<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Claude;

use Jul6Art\DevTools\Claude\ClaudeSettings;
use Jul6Art\DevTools\Claude\Hook\HookInput;
use Jul6Art\DevTools\Claude\Hook\PreToolUseHandler;
use Jul6Art\DevTools\Claude\Hook\SessionStartHandler;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\ClaudeHookCommand;
use Jul6Art\DevTools\Command\ClaudeInstallCommand;
use Jul6Art\DevTools\Command\ClaudeUninstallCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0019: what Claude Code is told, from the real JSON of each event, on symfony-minimal inspected — and
 * the silence when DevTools has nothing to say or cannot say it.
 */
#[CoversClass(ClaudeHookCommand::class)]
#[CoversClass(HookInput::class)]
#[CoversClass(PreToolUseHandler::class)]
#[CoversClass(SessionStartHandler::class)]
#[CoversClass(ClaudeSettings::class)]
#[CoversClass(ClaudeInstallCommand::class)]
#[CoversClass(ClaudeUninstallCommand::class)]
final class ClaudeHookTest extends TestCase
{
    use AssertsSnapshots;
    use CopiesFixtureProjects;

    private const string FIXTURES = __DIR__.'/../Fixtures/claude/hooks/';

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        $report = new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($this->project, noAi: true));
        self::assertSame([], $report->errors, implode("\n", $report->errors));

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                touch($file->getPathname(), time() - 3600);
            }
        }
    }

    /**
     * The answers are fixed: the format is a contract with Claude Code, read once in its documentation.
     */
    #[DataProvider('answers')]
    public function testEachEventIsAnsweredAsFixed(string $input, string $event, string $snapshot): void
    {
        [$status, $output, $errors] = $this->hook($event, $input);

        self::assertSame(0, $status);
        self::assertSame('', $errors);
        self::assertMatchesSnapshot($output, __DIR__.'/../Fixtures/snapshots/hooks/'.$snapshot);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function answers(): iterable
    {
        yield 'an edit of a shared repository' => ['pre-tool-use.edit.json', 'PreToolUse', 'pre-tool-use.edit.json'];
        yield 'a new file nothing references' => ['pre-tool-use.write-new-file.json', 'PreToolUse', 'pre-tool-use.write-new-file.json'];
        yield 'the start of a session' => ['session-start.json', 'SessionStart', 'session-start.json'];
    }

    public function testAnEditOfASharedServiceListsItsWorkflowsTheirPagesAndTheirTests(): void
    {
        $context = $this->context('pre-tool-use.edit.json');

        self::assertStringStartsWith('DevTools: src/Repository/OrderRepository.php is part of 6 documented workflows', $context);
        self::assertStringContainsString('- route.order.new (traverses it): docs/workflows/routes/order.new.md', $context);
        self::assertStringContainsString('- route.order.index (traverses it): docs/workflows/routes/order.index.md · tests: tests/Controller/OrderControllerTest.php', $context);
    }

    public function testAFileNoWorkflowReferencesIsOneLine(): void
    {
        self::assertSame('DevTools: no documented workflow references src/Service/Invoicing.php yet — the next workflows:inspect will tell where it belongs.', $this->context('pre-tool-use.write-new-file.json'));
    }

    /**
     * Nothing to say is said with nothing: a tool that writes no file, a file outside the project, a file the
     * map knows but whose workflows are gone from the index.
     */
    public function testWithNothingToSayTheHookSaysNothing(): void
    {
        self::assertSame(['0', '', ''], array_map(strval(...), $this->hook('PreToolUse', 'pre-tool-use.bash.json')));
        self::assertSame(['0', '', ''], array_map(strval(...), $this->hookWith('PreToolUse', (string) json_encode(['hook_event_name' => 'PreToolUse', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => '/etc/hosts']]))));

        $graph = $this->project.'/.devtools/graph/files-to-workflows.xml';
        file_put_contents($graph, str_replace('</files-to-workflows>', '<file path="src/Legacy.php"><workflow ref="route.gone" relation="file"/></file></files-to-workflows>', (string) file_get_contents($graph)));

        self::assertSame(['0', '', ''], array_map(strval(...), $this->hookWith('PreToolUse', (string) json_encode(['hook_event_name' => 'PreToolUse', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => $this->project.'/src/Legacy.php']]))));
    }

    /**
     * ⚠️ A DevTools hook never keeps Claude from working because of itself: exit 0, nothing injected, the
     * cause on the standard error — which Claude never sees.
     *
     * @param callable(string): void $breakage
     */
    #[DataProvider('breakages')]
    public function testABrokenProjectOrInputIsExitZeroNothingInjectedTheCauseOnStderr(callable $breakage, string $input, string $cause): void
    {
        $breakage($this->project);

        [$status, $output, $errors] = '' === $input ? $this->hookWith('PreToolUse', 'not json at all') : $this->hook('PreToolUse', $input);

        self::assertSame(0, $status);
        self::assertSame('', $output);
        self::assertStringContainsString($cause, $errors);
    }

    /**
     * @return iterable<string, array{callable(string): mixed, string, string}>
     */
    public static function breakages(): iterable
    {
        yield 'no index' => [static fn (string $project): bool => unlink($project.'/.devtools/index.xml'), 'pre-tool-use.edit.json', 'No impact map'];
        yield 'a corrupted map' => [static fn (string $project): int|false => file_put_contents($project.'/.devtools/graph/files-to-workflows.xml', '<files-to-workflows'), 'pre-tool-use.edit.json', 'files-to-workflows.xml'];
        yield 'an input that is not JSON' => [static fn (string $project): null => null, '', 'The hook input is not JSON'];
    }

    public function testAnEventDevToolsDoesNotAnswerIsSilentToo(): void
    {
        [$status, $output, $errors] = $this->hook('Notification', 'session-start.json');

        self::assertSame([0, ''], [$status, $output]);
        self::assertStringContainsString('DevTools answers no "Notification" hook.', $errors);
    }

    /**
     * ADR-0019: the start of a session names the workflows to check, and the MCP server once it is installed.
     */
    public function testTheStartOfASessionNamesWhatToCheckAndTheServerWhenItIsThere(): void
    {
        $tracking = $this->project.'/.devtools/index.xml';
        file_put_contents($tracking, (string) preg_replace('/(id="route\.order\.show"[^>]*status=")fresh/', '$1stale', (string) file_get_contents($tracking)));
        file_put_contents($this->project.'/.mcp.json', '{"mcpServers": {"devtools": {"command": "vendor/bin/devtools", "args": ["mcp:serve"]}}}');

        $context = $this->context('session-start.json', 'SessionStart');

        self::assertStringContainsString('⚠ 1 to check: route.order.show (stale)', $context);
        self::assertStringContainsString('The devtools MCP server answers list_workflows, get_workflow, impact and get_knowledge', $context);
    }

    public function testTheProjectComesFromClaudeProjectDirWhenNoPathIsGiven(): void
    {
        putenv('CLAUDE_PROJECT_DIR='.$this->project);

        try {
            $tester = new CommandTester(new ClaudeHookCommand($this->input('session-start.json')));
            $tester->execute(['event' => 'SessionStart'], ['capture_stderr_separately' => true]);
        } finally {
            putenv('CLAUDE_PROJECT_DIR');
        }

        self::assertStringContainsString('10 workflows', $tester->getDisplay());
    }

    /**
     * The install merges, never overwrites: what the team configured stays, and uninstalling gives the file
     * back as it was.
     */
    public function testTheHooksAreMergedIntoTheSettingsAndUninstallingGivesThemBack(): void
    {
        $settings = $this->project.'/.claude/settings.json';
        mkdir(\dirname($settings));
        $team = <<<'JSON'
            {
                "permissions": {"allow": ["Bash(composer test)"]},
                "env": {},
                "hooks": {
                    "PreToolUse": [{"matcher": "Bash", "hooks": [{"type": "command", "command": "./bin/guard"}]}]
                }
            }
            JSON;
        file_put_contents($settings, $team);

        $install = new CommandTester(new Application()->find('claude:install'));
        self::assertSame(0, $install->execute(['path' => $this->project, '--hooks' => true]));
        self::assertSame(0, $install->execute(['path' => $this->project, '--hooks' => true]), 'Twice is once.');
        self::assertFileDoesNotExist($this->project.'/.claude/skills/devtools-inspect/SKILL.md', '--hooks installs the hooks only.');

        $installed = json_decode((string) file_get_contents($settings), true);
        self::assertIsArray($installed);
        self::assertSame(['allow' => ['Bash(composer test)']], $installed['permissions']);
        $objects = json_decode((string) file_get_contents($settings));
        self::assertInstanceOf(\stdClass::class, $objects);
        self::assertSame('{}', json_encode($objects->env), 'An empty object stays an object.');
        self::assertIsArray($installed['hooks'] ?? null);
        self::assertSame(
            [
                ['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => './bin/guard']]],
                ['matcher' => 'Edit|Write|MultiEdit', 'hooks' => [['type' => 'command', 'command' => 'devtools claude:hook PreToolUse', 'timeout' => 10]]],
            ],
            $installed['hooks']['PreToolUse'],
        );
        self::assertSame([['hooks' => [['type' => 'command', 'command' => 'devtools claude:hook SessionStart', 'timeout' => 10]]]], $installed['hooks']['SessionStart']);

        $uninstall = new CommandTester(new Application()->find('claude:uninstall'));
        self::assertSame(0, $uninstall->execute(['path' => $this->project, '--hooks' => true]));
        self::assertEquals(json_decode($team), json_decode((string) file_get_contents($settings)), 'The file of the team, as it was.');
        self::assertSame(0, $uninstall->execute(['path' => $this->project, '--hooks' => true]));
        self::assertStringContainsString('No DevTools hook', $uninstall->getDisplay());
    }

    public function testASettingsFileHoldingOnlyTheHooksIsRemovedWithThem(): void
    {
        mkdir($this->project.'/vendor/bin', 0o777, true);
        touch($this->project.'/vendor/bin/devtools');

        new CommandTester(new Application()->find('claude:install'))->execute(['path' => $this->project, '--hooks' => true]);
        $settings = (string) file_get_contents($this->project.'/.claude/settings.json');

        self::assertStringContainsString('"command": "\"${CLAUDE_PROJECT_DIR}/vendor/bin/devtools\" claude:hook PreToolUse"', $settings, 'The DevTools of the project, wherever it is cloned.');

        new CommandTester(new Application()->find('claude:uninstall'))->execute(['path' => $this->project, '--hooks' => true]);

        self::assertFileDoesNotExist($this->project.'/.claude/settings.json');
    }

    public function testASettingsFileThatIsNotJsonIsLeftAlone(): void
    {
        mkdir($this->project.'/.claude');
        file_put_contents($this->project.'/.claude/settings.json', '{"hooks": ');
        $tester = new CommandTester(new Application()->find('claude:install'));

        self::assertSame(1, $tester->execute(['path' => $this->project, '--hooks' => true]));
        self::assertStringContainsString('is not valid JSON', $tester->getDisplay());
        self::assertStringEqualsFile($this->project.'/.claude/settings.json', '{"hooks": ');
    }

    public function testUninstallingAsksWhatToRemove(): void
    {
        $tester = new CommandTester(new Application()->find('claude:uninstall'));

        self::assertSame(2, $tester->execute(['path' => $this->project]));
        self::assertStringContainsString('Say what to remove', $tester->getDisplay());
    }

    private function context(string $input, string $event = 'PreToolUse'): string
    {
        [, $output] = $this->hook($event, $input);
        $answer = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        $specific = $answer['hookSpecificOutput'] ?? null;
        self::assertIsArray($specific);
        self::assertSame($event, $specific['hookEventName'] ?? null);
        self::assertIsString($specific['additionalContext'] ?? null);

        return $specific['additionalContext'];
    }

    /**
     * @return array{int, string, string} status, standard output, standard error
     */
    private function hook(string $event, string $fixture): array
    {
        return $this->runHook($event, $this->input($fixture));
    }

    /**
     * @return array{int, string, string}
     */
    private function hookWith(string $event, string $json): array
    {
        $file = $this->temporaryDirectory().'/input-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($file, $json);

        return $this->runHook($event, $file);
    }

    /**
     * @return array{int, string, string}
     */
    private function runHook(string $event, string $stdin): array
    {
        $tester = new CommandTester(new ClaudeHookCommand($stdin, $this->project));
        $status = $tester->execute(['event' => $event], ['capture_stderr_separately' => true]);

        return [$status, $tester->getDisplay(), $tester->getErrorOutput()];
    }

    private function input(string $fixture): string
    {
        $file = $this->temporaryDirectory().'/'.$fixture;
        file_put_contents($file, str_replace('{PROJECT}', $this->project, (string) file_get_contents(self::FIXTURES.$fixture)));

        return $file;
    }
}
