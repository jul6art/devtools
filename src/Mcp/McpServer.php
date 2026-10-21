<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Mcp;

use Jul6Art\DevTools\Ask\PageSelector;
use Jul6Art\DevTools\Version;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

/**
 * The MCP server of a project (ADR-0018): five read-only tools on stdio, launched by Claude Code inside the
 * project — the page of one workflow instead of a directory to read.
 *
 * The only place that knows the SDK. It is pre-1.0 and only suggested: when it is missing, `mcp:serve` is not
 * registered and nothing else in DevTools notices.
 */
final readonly class McpServer
{
    public const string NAME = 'devtools';

    /**
     * What to install when the SDK is missing.
     */
    public const string PACKAGE = 'mcp/sdk:^0.9';

    /**
     * What Claude reads once, at the start of a session, to know when the tools are worth a call.
     */
    public const string INSTRUCTIONS = 'DevTools documents the workflows of this project — routes, commands, messages, components — and maps each source file to the workflows it takes part in. '
        .'Before modifying a file, call `impact` with it: the workflows it reaches are the behaviour to keep, and their pages say what that behaviour is. '
        .'Call `search_workflows` with a question about how the project behaves to find the pages that answer it, `get_workflow` to read one page instead of opening the documentation directory, `list_workflows` to find an identifier, `get_knowledge` for the conventions of the stack. '
        .'Nothing here writes: the documentation is as fresh as the last `devtools workflows:inspect`.';

    public function __construct(private ProjectQueries $queries)
    {
    }

    public static function available(): bool
    {
        return class_exists(Server::class);
    }

    /**
     * Serves until the client closes the input.
     *
     * @param resource $input
     * @param resource $output
     */
    public function serve($input, $output): void
    {
        $this->build()->run(new StdioTransport($input, $output));
    }

    public function build(): Server
    {
        return Server::builder()
            ->setServerInfo(self::NAME, Version::current(), 'The workflows of this project, as DevTools documents them')
            ->setInstructions(self::INSTRUCTIONS)
            ->addTool(
                $this->listWorkflows(...),
                'list_workflows',
                'List the workflows',
                'Lists the documented workflows of the project: identifier, type, title, freshness, confidence and page. Call it to find the identifier get_workflow needs, or to see which pages are stale.',
                self::readOnly('List the workflows'),
                self::schema([
                    'type' => ['type' => 'string', 'enum' => $this->queries->typeNames(), 'description' => 'Only the workflows of this type'],
                    'status' => ['type' => 'string', 'enum' => ProjectQueries::statuses(), 'description' => 'Only the workflows of this freshness'],
                ]),
            )
            ->addTool(
                $this->getWorkflow(...),
                'get_workflow',
                'Read a workflow',
                'Returns the page of one workflow — what it does, what it decides, what to watch — then its files, tests, dependencies, mechanisms and history. Call it before changing a behaviour, instead of opening the documentation directory.',
                self::readOnly('Read a workflow'),
                self::schema(['id' => ['type' => 'string', 'description' => 'The identifier of the workflow, e.g. route.order.create']], ['id']),
            )
            ->addTool(
                $this->searchWorkflows(...),
                'search_workflows',
                'Search the workflows',
                'Returns the pages that answer a question about how the project behaves — "what happens when an operator validates an order?" — ranked on their words, then those they lead to; eight at most, the stale ones said. Read them with get_workflow and answer from them alone, citing their identifiers.',
                self::readOnly('Search the workflows'),
                self::schema([
                    'question' => ['type' => 'string', 'description' => 'The question, in plain words, in any language the pages are written in'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => PageSelector::LIMIT, 'description' => 'At most this many pages'],
                ], ['question']),
            )
            ->addTool(
                $this->impact(...),
                'impact',
                'Impact of a change',
                'Lists the workflows a change reaches — directly, through a test, or through a dependency — and the files no workflow covers. Call it before modifying a file, and before a commit with "changed": true.',
                self::readOnly('Impact of a change'),
                self::schema([
                    'files' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Files of the project, relative to it or absolute'],
                    'changed' => ['type' => 'boolean', 'description' => 'The files modified, staged or untracked in the working tree, instead of files'],
                ]),
            )
            ->addTool(
                $this->getKnowledge(...),
                'get_knowledge',
                'Knowledge of the stack',
                'Returns the knowledge sheet of the project\'s stacks: where the framework puts its routes, rules and side effects, and the conventions the pages follow. Call it once, before reading code of a framework you are unsure of.',
                self::readOnly('Knowledge of the stack'),
                self::schema(['stack' => ['type' => 'string', 'description' => 'A stack root (".", "front") or a knowledge key (symfony-7); every stack when omitted']]),
            )
            ->build();
    }

    public function listWorkflows(?string $type = null, ?string $status = null): CallToolResult
    {
        return self::answer(fn (): array => $this->queries->listWorkflows($type, $status));
    }

    public function getWorkflow(string $id): CallToolResult
    {
        return self::answer(fn (): array => $this->queries->getWorkflow($id));
    }

    public function searchWorkflows(string $question, int $limit = PageSelector::LIMIT): CallToolResult
    {
        return self::answer(fn (): array => $this->queries->searchWorkflows($question, $limit));
    }

    /**
     * @param list<string> $files
     */
    public function impact(array $files = [], bool $changed = false): CallToolResult
    {
        return self::answer(fn (): array => $this->queries->impact(array_values(array_filter($files, \is_string(...))), $changed));
    }

    public function getKnowledge(?string $stack = null): CallToolResult
    {
        return self::answer(fn (): array => $this->queries->knowledge($stack));
    }

    /**
     * A refusal, a project never inspected, a path outside the project: an error the client shows Claude,
     * never a crash of the server.
     *
     * @param \Closure(): list<string> $query
     */
    private static function answer(\Closure $query): CallToolResult
    {
        try {
            return new CallToolResult(array_map(static fn (string $text): TextContent => new TextContent($text), $query()));
        } catch (\Exception $failure) {
            throw new ToolCallException($failure->getMessage(), $failure->getCode(), previous: $failure);
        }
    }

    private static function readOnly(string $title): ToolAnnotations
    {
        return new ToolAnnotations($title, readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false);
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     *
     * @return array<string, mixed>
     */
    private static function schema(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false, ...([] === $required ? [] : ['required' => $required])];
    }
}
