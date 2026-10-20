<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Mcp;

/**
 * A question the project cannot answer as asked: the MCP server turns it into a tool error Claude reads, never
 * into a crash of the server (ADR-0018).
 */
final class ToolRefused extends \RuntimeException
{
    public static function notInspected(string $file): self
    {
        return new self(\sprintf('%s does not exist: the project was never inspected. Run `devtools workflows:inspect` first.', $file));
    }

    public static function unknownWorkflow(string $id): self
    {
        return new self(\sprintf('No workflow "%s" in this project: list_workflows gives the identifiers.', $id));
    }

    public static function impactInput(): self
    {
        return new self('Give either "files" or "changed": true — not both, not neither.');
    }

    public static function noGit(): self
    {
        return new self('git could not list the changes: the project is not in a repository. Give the files instead.');
    }

    /**
     * @param list<string> $known
     */
    public static function unknownStack(?string $stack, array $known): self
    {
        return new self([] === $known
            ? 'No stack of this project has knowledge: its stack is unknown to DevTools.'
            : \sprintf('No stack "%s" with knowledge in this project; known: %s.', $stack, implode(', ', $known)));
    }
}
