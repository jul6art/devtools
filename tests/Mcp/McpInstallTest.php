<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Mcp;

use Jul6Art\DevTools\Claude\ClaudeSettings;
use Jul6Art\DevTools\Command\ClaudeInstallCommand;
use Jul6Art\DevTools\Command\ClaudeUninstallCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0018: `claude:install --mcp` declares the server in `.mcp.json` — merged, never overwritten — and
 * `claude:uninstall --mcp` gives the file back as it was.
 */
#[CoversClass(ClaudeSettings::class)]
#[CoversClass(ClaudeInstallCommand::class)]
#[CoversClass(ClaudeUninstallCommand::class)]
final class McpInstallTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const string TEAM = <<<'JSON'
        {
            "mcpServers": {
                "github": {"type": "http", "url": "https://api.githubcopilot.com/mcp/"}
            },
            "other": {}
        }
        JSON;

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->temporaryDirectory();
    }

    public function testTheServerIsMergedIntoTheTeamsFileAndUninstallingGivesItBack(): void
    {
        file_put_contents($this->project.'/.mcp.json', self::TEAM);

        self::assertSame(0, $this->install(['--mcp' => true])->getStatusCode());
        self::assertSame(0, $this->install(['--mcp' => true])->getStatusCode(), 'Twice is once.');
        self::assertFileDoesNotExist($this->project.'/.claude/skills/devtools-inspect/SKILL.md', '--mcp installs the server only.');

        $installed = json_decode((string) file_get_contents($this->project.'/.mcp.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($installed);
        self::assertSame(
            [
                'github' => ['type' => 'http', 'url' => 'https://api.githubcopilot.com/mcp/'],
                'devtools' => ['type' => 'stdio', 'command' => 'devtools', 'args' => ['mcp:serve']],
            ],
            $installed['mcpServers'],
        );
        $objects = json_decode((string) file_get_contents($this->project.'/.mcp.json'));
        self::assertInstanceOf(\stdClass::class, $objects);
        self::assertSame('{}', json_encode($objects->other), 'An empty object stays an object.');

        $uninstall = $this->uninstall(['--mcp' => true]);
        self::assertSame(0, $uninstall->getStatusCode());
        self::assertStringContainsString('Removed the DevTools MCP server', $uninstall->getDisplay());
        self::assertEquals(json_decode(self::TEAM), json_decode((string) file_get_contents($this->project.'/.mcp.json')), 'The file of the team, as it was.');
        self::assertStringContainsString('No DevTools MCP server', $this->uninstall(['--mcp' => true])->getDisplay());
    }

    /**
     * Wherever the project is cloned: Claude Code expands the variable, with a default since it only knows
     * its own environment.
     */
    public function testTheDevToolsOfTheProjectIsTheOneLaunchedAndTheFileGoesWithIt(): void
    {
        mkdir($this->project.'/vendor/bin', 0o777, true);
        touch($this->project.'/vendor/bin/devtools');

        $this->install(['--mcp' => true]);

        self::assertStringContainsString('"command": "${CLAUDE_PROJECT_DIR:-.}/vendor/bin/devtools"', (string) file_get_contents($this->project.'/.mcp.json'));

        $this->uninstall(['--mcp' => true]);

        self::assertFileDoesNotExist($this->project.'/.mcp.json');
    }

    /**
     * A server named "devtools" that DevTools did not write is the team's: kept, unless --force — and never
     * uninstalled.
     */
    public function testAServerNamedDevToolsThatIsNotDevToolsIsKeptUnlessForced(): void
    {
        $foreign = '{"mcpServers": {"devtools": {"command": "node", "args": ["tools/devtools.js"]}}}';
        file_put_contents($this->project.'/.mcp.json', $foreign);

        $refused = $this->install(['--mcp' => true]);
        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString('DevTools did not write', self::flat($refused));
        self::assertStringEqualsFile($this->project.'/.mcp.json', $foreign);

        self::assertSame(0, $this->uninstall(['--mcp' => true])->getStatusCode());
        self::assertStringEqualsFile($this->project.'/.mcp.json', $foreign, 'Not ours to remove.');

        self::assertSame(0, $this->install(['--mcp' => true, '--force' => true])->getStatusCode());
        self::assertStringContainsString('"command": "devtools"', (string) file_get_contents($this->project.'/.mcp.json'));
    }

    /**
     * DevTools' own entry follows the installation: a global DevTools replaced by the project's.
     */
    public function testDevToolsOwnEntryIsUpdatedWithoutForce(): void
    {
        $this->install(['--mcp' => true]);
        mkdir($this->project.'/vendor/bin', 0o777, true);
        touch($this->project.'/vendor/bin/devtools');

        self::assertSame(0, $this->install(['--mcp' => true])->getStatusCode());
        self::assertStringContainsString('vendor/bin/devtools', (string) file_get_contents($this->project.'/.mcp.json'));
    }

    #[TestWith(['{"mcpServers": ', 'is not valid JSON'])]
    #[TestWith(['[]', 'is not a JSON object'])]
    #[TestWith(['{"mcpServers": []}', '"mcpServers" is not an object'])]
    public function testAFileDevToolsCannotMergeIntoIsLeftAlone(string $content, string $cause): void
    {
        file_put_contents($this->project.'/.mcp.json', $content);

        $install = $this->install(['--mcp' => true]);
        self::assertSame(1, $install->getStatusCode());
        self::assertStringContainsString($cause, self::flat($install));
        self::assertStringEqualsFile($this->project.'/.mcp.json', $content);

        $uninstall = $this->uninstall(['--mcp' => true]);
        self::assertSame('{"mcpServers": []}' === $content ? 0 : 1, $uninstall->getStatusCode(), 'A file that is not a JSON object is refused; one without a server of DevTools has nothing to give back.');
        self::assertStringEqualsFile($this->project.'/.mcp.json', $content);
    }

    public function testTheSkillTheHooksAndTheServerInstallTogether(): void
    {
        self::assertSame(0, $this->install(['--skill' => true, '--hooks' => true, '--mcp' => true])->getStatusCode());

        self::assertFileExists($this->project.'/.claude/skills/devtools-inspect/SKILL.md');
        self::assertFileExists($this->project.'/.claude/settings.json');
        self::assertFileExists($this->project.'/.mcp.json');

        self::assertSame(0, $this->uninstall(['--hooks' => true, '--mcp' => true])->getStatusCode());
        self::assertFileDoesNotExist($this->project.'/.claude/settings.json');
        self::assertFileDoesNotExist($this->project.'/.mcp.json');
    }

    /**
     * The display on one line: the console wraps a long message wherever it likes.
     */
    private static function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function install(array $options): CommandTester
    {
        $tester = new CommandTester(new Application()->find('claude:install'));
        $tester->execute(['path' => $this->project, ...$options]);

        return $tester;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function uninstall(array $options): CommandTester
    {
        $tester = new CommandTester(new Application()->find('claude:uninstall'));
        $tester->execute(['path' => $this->project, ...$options]);

        return $tester;
    }
}
