<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Glossary;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Claude's definitions, checked before they reach the glossary (ADR-0037): every term one of the glossary's, not
 * one a human set aside, defined in two sentences at most. A draft breaking a rule is refused whole and kept.
 */
final readonly class GlossaryDraftApplier
{
    public const int SENTENCES = 2;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter())
    {
    }

    /**
     * @return array{accepted: list<string>, refused: array<string, list<string>>}
     */
    public function apply(DevToolsDirectory $directory, string $projectName): array
    {
        $draftFile = $directory->path(GlossaryBriefWriter::DRAFT);

        if (!is_file($draftFile)) {
            return ['accepted' => [], 'refused' => []];
        }

        $store = new XmlGlossaryStore($this->writer);
        $glossary = $store->read($directory->path('glossary.xml'));
        $dom = new DomBuilder('https://github.com/jul6art/devtools/schema/glossary-draft/1');

        try {
            $root = DomBuilder::root(new SafeXmlLoader()->loadValidated((string) file_get_contents($draftFile), $draftFile, Resources::path('schemas/glossary-draft.xsd'), 1));
        } catch (InvalidXml $invalid) {
            return ['accepted' => [], 'refused' => ['glossary' => [$invalid->getMessage()]]];
        }

        $errors = [];

        foreach ($dom->children($root, 'term') as $element) {
            $name = $element->getAttribute('name');
            $term = $glossary->get($name);
            $definition = trim($dom->single($element, 'definition')->textContent);

            if (!$term instanceof Term) {
                $errors[] = \sprintf('"%s" is not a term of the glossary.', $name);

                continue;
            }

            if (TermStatus::Ignored === $term->status) {
                $errors[] = \sprintf('"%s" was set aside (status="ignored"): it is not defined again.', $name);

                continue;
            }

            $sentences = \count(preg_split('/(?<=[.!?…])\s+(?=\S)/u', $definition) ?: []);

            if ($sentences > self::SENTENCES) {
                $errors[] = \sprintf('The definition of "%s" has %d sentences; two at most.', $name, $sentences);

                continue;
            }

            $glossary = $glossary->with($term->defined(
                $definition,
                $dom->optional($element, 'label')?->textContent,
                array_map(static fn (\DOMElement $synonym): string => trim($synonym->textContent), $dom->children($element, 'synonym')),
            ));
        }

        if ([] !== $errors) {
            return ['accepted' => [], 'refused' => ['glossary' => $errors]];
        }

        $store->write($directory->path('glossary.xml'), $glossary);
        $this->writer->write($directory->root->absolute($directory->docs.'/glossary.md'), new GlossaryRenderer()->render($glossary, $projectName));
        new Filesystem()->remove([$draftFile, $directory->path(GlossaryBriefWriter::BRIEF)]);

        return ['accepted' => ['glossary'], 'refused' => []];
    }
}
