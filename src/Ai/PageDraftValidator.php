<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Rendering\PageSection;

/**
 * The mechanical guards a draft goes through before it touches a page (ADR-0011).
 *
 * A draft is untrusted data: it may add no section, drop none, quote no file the model does not list, and
 * it must answer the revision of the code it was written for.
 */
final class PageDraftValidator
{
    private const string PATH_IN_CODE = '/`([^`\s]*\/[^`\s]*\.[A-Za-z0-9]{1,6})`/';

    /**
     * @return list<string> every broken rule, each naming the line when there is one
     */
    public function validate(PageDraft $draft, Workflow $workflow, \DateTimeImmutable $revision): array
    {
        $errors = [];
        $expected = PageDraft::expectedSections();

        foreach ($draft->sections as $title => $section) {
            if (!\in_array($title, $expected, true)) {
                $errors[] = \sprintf('The section "%s" is not one a draft may write (line %d).', $title, $section['line']);
            }
        }

        foreach ($expected as $title) {
            if (!isset($draft->sections[$title])) {
                $errors[] = \sprintf('The section "%s" is missing.', $title);
            }
        }

        if ([] === $errors && array_keys($draft->sections) !== $expected) {
            $errors[] = 'The sections are not in the order the prompt gives.';
        }

        $journey = $draft->sections[PageSection::Journey->value] ?? null;

        if (null !== $journey && 1 !== preg_match_all('/^```mermaid\n\s*(sequenceDiagram|flowchart)\b.*?^```$/ms', $journey['content'])) {
            $errors[] = \sprintf('"%s" must hold exactly one Mermaid sequenceDiagram or flowchart (line %d).', PageSection::Journey->value, $journey['line']);
        }

        foreach ([PageDraft::PRECONDITIONS, PageDraft::CHANGE] as $oneLine) {
            $section = $draft->sections[$oneLine] ?? null;

            if (null !== $section && ('' === $section['content'] || str_contains($section['content'], "\n"))) {
                $errors[] = \sprintf('"%s" must be one line (line %d).', $oneLine, $section['line']);
            }
        }

        $known = array_map(static fn (FileRef $file): string => $file->path, [...$workflow->files, ...$workflow->tests]);

        foreach ($draft->sections as $section) {
            foreach (explode("\n", $section['content']) as $offset => $line) {
                preg_match_all(self::PATH_IN_CODE, $line, $matches);

                foreach ($matches[1] as $path) {
                    if (!\in_array($path, $known, true)) {
                        $errors[] = \sprintf('`%s` is not a file of the workflow (line %d): quote only files the model lists.', $path, $section['line'] + 2 + $offset);
                    }
                }
            }
        }

        $answered = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $draft->metadata['revision'] ?? '');

        if (!$answered instanceof \DateTimeImmutable || $answered != $revision) {
            $errors[] = \sprintf('The draft is outdated: it answers revision %s, the workflow is at revision %s. Run the inspection again for a new brief.', $draft->metadata['revision'] ?? '(none)', $revision->format(\DATE_ATOM));
        }

        if ('' === ($draft->metadata['model'] ?? '')) {
            $errors[] = 'The front-matter must name the model that wrote the draft.';
        }

        return $errors;
    }
}
