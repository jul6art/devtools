<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai;

use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Rendering\MarkdownWriter;
use Jul6Art\DevTools\Rendering\PageSection;

/**
 * The mechanical guards a draft goes through before it touches a page (ADR-0011).
 *
 * A draft is untrusted data: it may add no section, drop none, quote no file the model does not list, and
 * it must answer the revision of the code it was written for.
 */
final class PageDraftValidator
{
    /**
     * A relative path with an extension, in backticks. A leading slash makes it a URL (`/orders/new.php`): a
     * file of the model is always relative.
     */
    private const string PATH_IN_CODE = '/`([^`\s\/][^`\s]*\/[^`\s]*\.[A-Za-z0-9]{1,6})`/';

    /**
     * Five sentences: past that, the index says what the pages it links to are there to say.
     */
    private const int SUMMARY_MAX_SENTENCES = 5;

    /**
     * The summary of a group page (ADR-0045): one section, five sentences at most, no path, no diagram.
     *
     * @return list<string> every broken rule
     */
    public function validateGroup(PageDraft $draft, GroupBrief $brief): array
    {
        $errors = [];
        $expected = [PageSection::Summary->value];

        if (array_keys($draft->sections) !== $expected) {
            $errors[] = \sprintf('A group draft holds exactly one section, "%s": DevTools writes the others.', PageSection::Summary->value);
        }

        $summary = $draft->sections[PageSection::Summary->value] ?? null;

        if (null === $summary) {
            return $errors;
        }

        $content = trim($summary['content']);

        if ('' === $content || MarkdownWriter::EMPTY === $content) {
            $errors[] = \sprintf('The section "%s" is empty: a group page without a summary is written by --no-ai, not by a draft.', PageSection::Summary->value);
        }

        if (self::SUMMARY_MAX_SENTENCES < preg_match_all('/[.!?](\s|$)/u', $content)) {
            $errors[] = \sprintf('The summary is longer than %d sentences: an index that explains as much as a page stops being an index.', self::SUMMARY_MAX_SENTENCES);
        }

        if (str_contains($content, '```')) {
            $errors[] = 'A group summary holds no code block: the state machine is rendered by DevTools.';
        }

        if (1 === preg_match(self::PATH_IN_CODE, $content, $matches)) {
            $errors[] = \sprintf('"%s" is a file path: a group page names routes, and a path belongs to the page of a route.', $matches[1]);
        }

        $names = array_map(static fn (array $route): string => $route['route'], $brief->routes);

        foreach (self::quoted($content) as $quoted) {
            if (str_starts_with($quoted, $brief->directory) || \in_array($quoted, $names, true)) {
                continue;
            }

            $errors[] = \sprintf('"%s" is neither a route of this controller nor its directory: the brief lists what may be named.', $quoted);
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function quoted(string $content): array
    {
        preg_match_all('/`([^`]+)`/', $content, $matches);

        return array_values(array_unique($matches[1]));
    }

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

        $errors = [...$errors, ...self::decisions($draft, $workflow)];

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

    /**
     * "Decisions" draws one flowchart per decided field (ADR-0043). What is checked is mechanical: a
     * diagram is there, every field named exists in the model, and no decided field is left out.
     *
     * The **conditions** are deliberately not compared: rewriting them in business language is the work
     * asked for, and a literal comparison would refuse exactly the drafts that did it well.
     *
     * @return list<string>
     */
    private static function decisions(PageDraft $draft, Workflow $workflow): array
    {
        $section = $draft->sections[PageSection::Decisions->value] ?? null;

        if (null === $section || '' === $section['content'] || MarkdownWriter::EMPTY === $section['content']) {
            return [] === $workflow->decisions
                ? []
                : [\sprintf('"%s" is empty while the model records %d decided value(s): draw one flowchart per field.', PageSection::Decisions->value, \count($workflow->decisions))];
        }

        if ([] === $workflow->decisions) {
            return [\sprintf('"%s" must hold "%s": the model records no decided value for this workflow (line %d).', PageSection::Decisions->value, MarkdownWriter::EMPTY, $section['line'])];
        }

        $errors = [];

        if (1 > preg_match_all('/^```mermaid\n\s*flowchart\b.*?^```$/ms', $section['content'])) {
            $errors[] = \sprintf('"%s" must hold at least one Mermaid flowchart (line %d).', PageSection::Decisions->value, $section['line']);
        }

        $targets = array_values(array_unique(array_map(static fn (DecisionPoint $decision): string => $decision->target, $workflow->decisions)));
        preg_match_all('/`([^`\s]+::[^`\s]+)`/', $section['content'], $matches);
        $named = array_values(array_unique($matches[1]));

        foreach (array_diff($named, $targets) as $unknown) {
            $errors[] = \sprintf('`%s` is not a value this workflow decides (line %d): name only targets the model records.', $unknown, $section['line']);
        }

        foreach (array_diff($targets, $named) as $missing) {
            $errors[] = \sprintf('`%s` is decided by this workflow and is not drawn (line %d).', $missing, $section['line']);
        }

        return $errors;
    }
}
