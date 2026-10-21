<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Glossary;

use Jul6Art\DevTools\Rendering\MarkdownWriter;

/**
 * `glossary.md` beside the menu (ADR-0037): the defined terms, sorted, what they mean, what else they are
 * called, and where they appear.
 */
final readonly class GlossaryRenderer
{
    public function render(Glossary $glossary, string $projectName): string
    {
        $defined = $glossary->withStatus(TermStatus::Defined);
        usort($defined, static fn (Term $a, Term $b): int => strcasecmp($a->name, $b->name));

        return implode("\n", [
            '# Glossary — '.$projectName,
            '',
            'The words of this project, as its pages use them. Back to the '.MarkdownWriter::link('workflows', 'workflows.md').'.',
            '',
            MarkdownWriter::table(['Term', 'Definition', 'Also called', 'Workflows'], array_map(static fn (Term $term): array => [
                '**'.$term->name.'**'.(null === $term->label ? '' : ' ('.$term->label.')'),
                (string) $term->definition,
                [] === $term->synonyms ? MarkdownWriter::EMPTY : implode(', ', $term->synonyms),
                [] === $term->workflows ? MarkdownWriter::EMPTY : implode(', ', array_map(MarkdownWriter::code(...), $term->workflows)),
            ], $defined)),
        ])."\n";
    }
}
