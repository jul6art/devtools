<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Claude\Hook;

use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\XmlIndexStore;

/**
 * At the start of a session, one paragraph (ADR-0019): how many workflows are documented and where, which
 * ones need attention, and how to ask what a change touches.
 */
final readonly class SessionStartHandler implements HookHandlerInterface
{
    public function event(): string
    {
        return 'SessionStart';
    }

    public function handle(HookInput $input, DevToolsDirectory $directory): ?array
    {
        if (!is_file($directory->indexFile())) {
            return null;
        }

        $index = new XmlIndexStore(new WorkflowTypeRegistry())->read($directory->indexFile());
        $attention = array_values(array_filter($index->entries, static fn (IndexEntry $entry): bool => $entry->status->needsAttention()));
        $lines = [\sprintf('DevTools documents the workflows of this project: %d workflow%s, menu in %s/workflows.md.', \count($index->entries), 1 === \count($index->entries) ? '' : 's', $index->docs)];

        if ([] !== $attention) {
            $lines[] = \sprintf('⚠ %d to check: %s — devtools workflows:check says why.', \count($attention), implode(', ', array_map(static fn (IndexEntry $entry): string => $entry->id->value.' ('.$entry->status->value.')', $attention)));
        }

        $lines[] = 'Before changing a file, devtools workflows:impact <file> names the workflows it reaches; a hook adds that to your context on every edit.';

        if (self::serverInstalled($directory)) {
            $lines[] = 'The devtools MCP server answers list_workflows, get_workflow, impact and get_knowledge: ask it instead of reading .devtools/.';
        }

        return ['hookSpecificOutput' => ['hookEventName' => 'SessionStart', 'additionalContext' => implode("\n", $lines)]];
    }

    private static function serverInstalled(DevToolsDirectory $directory): bool
    {
        $file = $directory->root->path.'/.mcp.json';
        $content = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return \is_array($content) && \is_array($content['mcpServers'] ?? null) && isset($content['mcpServers']['devtools']);
    }
}
