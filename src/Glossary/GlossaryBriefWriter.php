<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Glossary;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Xml\DomBuilder;

/**
 * `.devtools/pending/glossary.brief.xml` (ADR-0037): the candidate terms, the workflows and files that name them —
 * one task for all of them, and none when there is nothing new to define.
 */
final readonly class GlossaryBriefWriter
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/glossary-brief/1';

    public const string BRIEF = 'pending/glossary.brief.xml';

    public const string DRAFT = 'pending/glossary.draft.xml';

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private DomBuilder $dom = new DomBuilder(self::NAMESPACE))
    {
    }

    /**
     * @param list<Term>                                        $candidates
     * @param array<string, array{title: string, page: string}> $workflows  identifier => its title and page
     * @param list<string>                                      $knowledge  the knowledge sheets of the stacks
     */
    public function write(string $path, string $draftPath, array $candidates, array $workflows, array $knowledge, string $language): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'glossary-brief', ['schema-version' => '1']);
        $this->dom->element($root, 'prompt', ['version' => 'glossary/1', 'path' => Resources::path('prompts/glossary/v1.md')]);
        $this->dom->element($root, 'language', text: $language);

        foreach ($knowledge as $sheet) {
            $this->dom->element($root, 'knowledge', ['path' => $sheet]);
        }

        foreach ($candidates as $term) {
            $element = $this->dom->element($root, 'term', ['name' => $term->name]);

            foreach ($term->workflows as $id) {
                $this->dom->element($element, 'workflow', array_filter(['id' => $id, 'title' => $workflows[$id]['title'] ?? $id, 'page' => $workflows[$id]['page'] ?? null], \is_string(...)));
            }

            foreach ($term->files as $file) {
                $this->dom->element($element, 'file', ['path' => $file]);
            }
        }

        $this->dom->element($root, 'draft', ['path' => $draftPath]);

        return $this->writer->write($path, $this->dom->toXml($xml));
    }
}
