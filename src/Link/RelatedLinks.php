<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

use Jul6Art\DevTools\Rendering\MarkdownWriter;
use Jul6Art\DevTools\Rendering\PageSection;

/**
 * The workflows of the linked projects in a page's "Related workflows" (ADR-0038), after its own:
 *
 *     - mobile · [`integration.item-service`](../../../../mobile/docs/workflows/integrations/item-service.md) — calls GET /api/items/:param
 *     - api · [`route.api.item.show`](…) — answers GET /api/items/:param
 *     - api · no route answers DELETE /api/items/:param
 *
 * The link is relative, followed from the page through the neighbour's declared path: it opens when the two
 * repositories sit side by side on disk, as they do where `--link` ran. A line of a linked project starts
 * with its name — no line of the page's own does — which is how the section is rewritten in place when
 * only the links changed.
 */
final class RelatedLinks
{
    private const string LINE = '/^- [a-z][a-z0-9-]* · /';

    /**
     * @param string $pageDirectory the directory of the page, relative to the project root
     *
     * @return list<string>
     */
    public static function lines(Links $links, string $workflow, string $pageDirectory): array
    {
        $lines = [];

        foreach ($links->of($workflow) as [$project, $call]) {
            $remote = $call->workflowOn($project->role);

            if (null === $remote) {
                $lines[] = \sprintf('- %s · no route answers %s', $project->name, $call->endpoint);

                continue;
            }

            $page = $call->pageOn($project->role);
            $target = null === $page ? MarkdownWriter::code($remote) : MarkdownWriter::link(MarkdownWriter::code($remote), str_repeat('../', \count(explode('/', trim($pageDirectory, '/')))).$project->path.'/'.$page);
            $lines[] = \sprintf('- %s · %s — %s %s', $project->name, $target, LinkRole::Client === $project->role ? 'calls' : 'answers', $call->endpoint);
        }

        return array_values(array_unique($lines));
    }

    /**
     * The section's own lines, then those of the linked projects; `—` when there is neither.
     *
     * @param list<string> $own
     * @param list<string> $linked
     */
    public static function section(array $own, array $linked): string
    {
        $lines = [...$own, ...$linked];

        return [] === $lines ? MarkdownWriter::EMPTY : implode("\n", $lines);
    }

    /**
     * A page with its "Related workflows" given these linked lines, its own lines kept as they are.
     *
     * @param list<string> $linked
     */
    public static function rewrite(string $markdown, array $linked): string
    {
        $heading = '## '.PageSection::Related->value."\n\n";

        return (string) preg_replace_callback(
            '/^'.preg_quote($heading, '/').'(.*?)(?=\n\n## |\n*\z)/ms',
            static fn (array $matches): string => $heading.self::section(
                array_values(array_filter(explode("\n", $matches[1]), static fn (string $line): bool => '' !== trim($line) && MarkdownWriter::EMPTY !== trim($line) && 1 !== preg_match(self::LINE, $line))),
                $linked,
            ),
            $markdown,
            1,
        );
    }
}
