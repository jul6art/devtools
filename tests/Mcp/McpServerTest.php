<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Mcp;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\McpServeCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Mcp\McpServer;
use Jul6Art\DevTools\Mcp\ProjectQueries;
use Jul6Art\DevTools\Mcp\ToolRefused;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeProvider;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Version;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0018: what Claude Code reads from the MCP server, through the real SDK on stdio, on symfony-minimal
 * inspected — and the errors it gets instead of a crash.
 */
#[CoversClass(McpServer::class)]
#[CoversClass(ProjectQueries::class)]
#[CoversClass(ToolRefused::class)]
#[CoversClass(McpServeCommand::class)]
#[CoversClass(Application::class)]
#[CoversClass(KnowledgeProvider::class)]
final class McpServerTest extends TestCase
{
    use AssertsSnapshots;
    use CopiesFixtureProjects;

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        $report = new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($this->project, noAi: true));
        self::assertSame([], $report->errors, implode("\n", $report->errors));

        // The copy and the inspection happen in the same second: the files are older than the map.
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                touch($file->getPathname(), time() - 3600);
            }
        }
    }

    /**
     * The handshake, the list of the tools and one call of each, as Claude Code sends them: the answers are a
     * contract with the client, fixed.
     */
    public function testTheStdioDialogueIsAnsweredAsFixed(): void
    {
        $lines = $this->dialogue((string) file_get_contents(__DIR__.'/../Fixtures/mcp/dialogue.jsonl'));
        $responses = array_map(static fn (string $line): mixed => json_decode($line, flags: \JSON_THROW_ON_ERROR), $lines);

        self::assertSame([1, 2, 3, 4, 5, 6, 7], array_map(static fn (mixed $response): mixed => $response instanceof \stdClass ? $response->id : null, $responses), 'One answer per request, none to the notification.');
        self::assertStringContainsString('"version":"'.Version::current().'"', $lines[0]);

        // Objects, not arrays: `{}` stays `{}`, as on the wire. The version and the copy's path vary.
        $snapshot = str_replace(
            ['"version": "'.Version::current().'"', (string) realpath($this->project), $this->project],
            ['"version": "VERSION"', 'PROJECT', 'PROJECT'],
            json_encode($responses, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        );

        self::assertMatchesSnapshot($snapshot."\n", __DIR__.'/../Fixtures/snapshots/mcp/dialogue.json');
    }

    /**
     * Every tool reads, none writes: Claude Code may call them without asking.
     */
    public function testEveryToolIsDeclaredReadOnly(): void
    {
        $tools = self::decode($this->dialogue((string) file_get_contents(__DIR__.'/../Fixtures/mcp/dialogue.jsonl'))[1], 'result', 'tools');

        self::assertSame(['list_workflows', 'get_workflow', 'impact', 'get_knowledge'], array_column($tools, 'name'));

        foreach ($tools as $tool) {
            self::assertIsArray($tool);
            self::assertIsArray($tool['annotations'] ?? null);
            self::assertSame(['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false], array_diff_key($tool['annotations'], ['title' => true]));
        }
    }

    public function testAnUnknownIdentifierIsAToolErrorNotAnException(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No workflow "route.order.nope" in this project: list_workflows gives the identifiers.');

        $this->server()->getWorkflow('route.order.nope');
    }

    /**
     * A path out of the project is refused before anything is read — relative or absolute.
     */
    #[DataProvider('outside')]
    public function testAPathOutsideTheProjectIsRefused(string $path): void
    {
        $this->expectException(PathOutsideProject::class);

        $this->queries()->impact([$path]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outside(): iterable
    {
        yield 'climbing out' => ['../outside.php'];
        yield 'absolute' => ['/etc/passwd'];
    }

    /**
     * @param list<string> $files
     */
    #[DataProvider('impactInputs')]
    public function testImpactTakesFilesOrTheWorkingTreeNeitherBothNorNone(array $files, bool $changed): void
    {
        $this->expectException(ToolRefused::class);
        $this->expectExceptionMessage('Give either "files" or "changed": true — not both, not neither.');

        $this->queries()->impact($files, $changed);
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function impactInputs(): iterable
    {
        yield 'neither' => [[], false];
        yield 'both' => [['src/Repository/OrderRepository.php'], true];
    }

    public function testTheWorkingTreeIsReadFromGit(): void
    {
        $repository = GitRepository::initialise($this->project);
        $repository->commitAll('inspected');
        file_put_contents($this->project.'/src/Service/OrderPricing.php', "\n// reviewed\n", \FILE_APPEND);

        self::assertSame(['async.order-created', 'route.order.new'], array_column(self::decode($this->queries()->impact(changed: true)[0], 'workflows'), 'id'));
    }

    public function testWithoutGitTheWorkingTreeIsRefusedAndNamedAsSuch(): void
    {
        $this->expectException(ToolRefused::class);
        $this->expectExceptionMessage('git could not list the changes');

        $this->queries()->impact(changed: true);
    }

    public function testTheWorkflowsAreFilteredByTypeAndFreshness(): void
    {
        $index = $this->project.'/.devtools/index.xml';
        file_put_contents($index, (string) preg_replace('/(id="route\.order\.show"[^>]*status=")fresh/', '$1stale', (string) file_get_contents($index)));

        self::assertSame(['route.order.show'], $this->listed(status: 'stale'));
        self::assertSame(['command.app.import-catalog'], $this->listed(type: 'commands'));
        self::assertSame([], $this->listed(type: 'commands', status: 'stale'));
        self::assertCount(10, $this->listed());
    }

    /**
     * The page as a reader opens it, then what only the tracking file knows.
     */
    public function testAWorkflowIsItsPageThenItsTrackingFile(): void
    {
        [$page, $metadata] = $this->queries()->getWorkflow('route.order.new');
        $metadata = self::decode($metadata);

        self::assertStringStartsWith('# ', $page);
        self::assertStringEqualsFile($this->project.'/docs/workflows/routes/order.new.md', $page);
        self::assertSame('docs/workflows/routes/order.new.md', $metadata['page']);
        self::assertSame('.devtools/workflows/routes/order.new.xml', $metadata['tracking']);
        self::assertContains(['path' => 'src/Repository/OrderRepository.php', 'role' => 'repository'], self::decode($metadata, 'files'));
        self::assertSame(['2026-09-16'], array_column(self::decode($metadata, 'history'), 'date'));
    }

    public function testAWorkflowWhosePageWasRemovedSaysSo(): void
    {
        unlink($this->project.'/docs/workflows/routes/health.md');

        self::assertSame('No page yet for route.health: run `devtools workflows:inspect`.', $this->queries()->getWorkflow('route.health')[0]);
    }

    /**
     * A project never inspected gets the command to run, whatever it asks.
     */
    public function testANeverInspectedProjectIsToldWhatToRun(): void
    {
        $queries = new ProjectQueries(new ProjectRoot($this->copyFixtureProject('plain-php')));

        foreach ([static fn (): array => $queries->listWorkflows(), static fn (): array => $queries->getWorkflow('route.health'), static fn (): array => $queries->impact(['index.php']), static fn (): array => $queries->knowledge()] as $call) {
            try {
                $call();
                self::fail('A project never inspected cannot answer.');
            } catch (\RuntimeException $refusal) {
                self::assertMatchesRegularExpression('/(never inspected|run workflows:inspect first)/', $refusal->getMessage());
            }
        }
    }

    /**
     * The project's sheet first — by default, by the root of its stack or by its key — then, once it is
     * gone, the shared library's, then DevTools' own.
     */
    public function testTheKnowledgeComesFromTheProjectThenTheLibraryThenDevTools(): void
    {
        $sheet = (string) file_get_contents($this->project.'/.devtools/knowledge/symfony-8.md');
        $header = "<!-- knowledge symfony-8 · stack . · .devtools/knowledge/symfony-8.md -->\n\n";

        self::assertSame([$header.$sheet], $this->queries()->knowledge());
        self::assertSame([$header.$sheet], $this->queries()->knowledge('.'));
        self::assertSame([$header.$sheet], $this->queries()->knowledge('symfony-8'));

        unlink($this->project.'/.devtools/knowledge/symfony-8.md');
        $library = $this->copyFixtureProject('plain-php').'/library';
        mkdir($library);
        file_put_contents($library.'/symfony-8.md', '# Symfony 8, as this machine knows it');
        putenv(KnowledgeLibrary::HOME_ENV.'='.$library);

        try {
            self::assertSame(['<!-- knowledge symfony-8 · stack . · shared library: '.realpath($library)."/symfony-8.md -->\n\n# Symfony 8, as this machine knows it"], $this->queries()->knowledge());
        } finally {
            putenv(KnowledgeLibrary::HOME_ENV);
        }

        self::assertStringContainsString('symfony-8 · stack . · shipped with DevTools -->', $this->queries()->knowledge()[0]);
        self::assertFileDoesNotExist($this->project.'/.devtools/knowledge/symfony-8.md', 'Read, never copied: the server writes nothing.');
    }

    public function testAStackWithoutAnySheetIsSaidSo(): void
    {
        $stack = $this->project.'/.devtools/stack.xml';
        file_put_contents($stack, str_replace('>symfony-8<', '>cobol-85<', (string) file_get_contents($stack)));

        self::assertSame(['No knowledge for cobol-85 (stack .) yet: `devtools workflows:inspect` asks Claude to write it.'], $this->queries()->knowledge());
    }

    public function testAnUnknownStackNamesTheKnownOnes(): void
    {
        $this->expectException(ToolRefused::class);
        $this->expectExceptionMessage('No stack "front" with knowledge in this project; known: symfony-8.');

        $this->queries()->knowledge('front');
    }

    public function testAProjectWhoseStackIsUnknownHasNoKnowledge(): void
    {
        $stack = $this->project.'/.devtools/stack.xml';
        file_put_contents($stack, (string) preg_replace('/\s*<knowledge[^>]*>symfony-8<\/knowledge>/', '', (string) file_get_contents($stack)));

        $this->expectException(ToolRefused::class);
        $this->expectExceptionMessage('No stack of this project has knowledge: its stack is unknown to DevTools.');

        $this->queries()->knowledge();
    }

    /**
     * Standard output is the protocol's: the command serves on it and writes nothing else there.
     */
    public function testTheCommandServesTheProjectOnTheStreamsItIsGiven(): void
    {
        [$input, $output, $responses] = $this->streams('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"claude-code","version":"2.0.0"}}}'."\n");
        $tester = new CommandTester(new McpServeCommand(input: $input, output: $output));

        self::assertSame(0, $tester->execute(['--path' => $this->project]));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('"serverInfo":{"name":"devtools"', (string) file_get_contents($responses));
    }

    public function testTheProjectComesFromClaudeProjectDirWhenNoPathIsGiven(): void
    {
        $handshake = \array_slice(file(__DIR__.'/../Fixtures/mcp/dialogue.jsonl') ?: [], 0, 2);
        [$input, $output, $responses] = $this->streams(implode('', $handshake).'{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"list_workflows","arguments":{}}}'."\n");
        putenv(McpServeCommand::PROJECT_ENV.'='.$this->project);

        try {
            new CommandTester(new McpServeCommand(input: $input, output: $output))->execute([]);
        } finally {
            putenv(McpServeCommand::PROJECT_ENV);
        }

        self::assertStringContainsString('\"count\":10', (string) file_get_contents($responses));
    }

    public function testAProjectThatIsNotADirectoryIsAnErrorOnStandardError(): void
    {
        $tester = new CommandTester(new McpServeCommand());

        self::assertSame(2, $tester->execute(['--path' => $this->project.'/nowhere'], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('is not a directory', $tester->getErrorOutput());
    }

    /**
     * Without the SDK the command does not exist, and asking for it says how to get it.
     */
    public function testWithoutTheSdkTheCommandIsAbsentAndTheWayToInstallItIsGiven(): void
    {
        $application = new Application(false);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'list']);

        self::assertStringNotContainsString('mcp:serve', $tester->getDisplay());
        self::assertTrue(new Application(true)->has('mcp:serve'));
        self::assertSame(McpServer::available(), new Application()->has('mcp:serve'));

        try {
            $application->find('mcp:serve');
            self::fail('mcp:serve exists only with the SDK.');
        } catch (CommandNotFoundException $unknown) {
            self::assertStringContainsString('"mcp"', $unknown->getMessage());
            self::assertStringContainsString('composer require --dev mcp/sdk:^0.9', $unknown->getMessage());
        }

        try {
            $application->find('mcp:nothing');
            self::fail('An unknown command stays unknown.');
        } catch (CommandNotFoundException $unknown) {
            self::assertStringNotContainsString('mcp/sdk', $unknown->getMessage());
        }
    }

    /**
     * @return list<string> the identifiers listed
     */
    private function listed(?string $type = null, ?string $status = null): array
    {
        $listed = self::decode($this->queries()->listWorkflows($type, $status)[0]);
        $workflows = self::decode($listed, 'workflows');
        self::assertSame(\count($workflows), $listed['count']);

        return array_values(array_filter(array_column($workflows, 'id'), \is_string(...)));
    }

    /**
     * The JSON of an answer, or the array found down the given keys.
     *
     * @param array<mixed>|string $json
     *
     * @return array<mixed>
     */
    private static function decode(array|string $json, string ...$keys): array
    {
        $value = \is_string($json) ? json_decode($json, true, flags: \JSON_THROW_ON_ERROR) : $json;

        foreach ($keys as $key) {
            self::assertIsArray($value);
            $value = $value[$key] ?? null;
        }

        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return list<string> the answers, one JSON-RPC message per line
     */
    private function dialogue(string $requests): array
    {
        [$input, $output, $responses] = $this->streams($requests);
        $this->server()->serve($input, $output);

        return array_values(array_filter(explode("\n", (string) file_get_contents($responses))));
    }

    /**
     * Files, not `php://memory`: the SDK waits on its input with `stream_select()`, which a memory stream
     * cannot answer — and it closes both streams when the client leaves, so the answers are read back from
     * the file.
     *
     * @return array{resource, resource, string} the input, the output, and the file the output is written to
     */
    private function streams(string $requests): array
    {
        $directory = $this->temporaryDirectory();
        file_put_contents($directory.'/requests.jsonl', $requests);
        $input = fopen($directory.'/requests.jsonl', 'r');
        $output = fopen($directory.'/responses.jsonl', 'w');
        self::assertIsResource($input);
        self::assertIsResource($output);

        return [$input, $output, $directory.'/responses.jsonl'];
    }

    private function server(): McpServer
    {
        return new McpServer($this->queries());
    }

    private function queries(): ProjectQueries
    {
        return new ProjectQueries(new ProjectRoot($this->project));
    }
}
