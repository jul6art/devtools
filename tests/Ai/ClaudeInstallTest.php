<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Ai;

use Jul6Art\DevTools\Command\ClaudeInstallCommand;
use Jul6Art\DevTools\Command\WorkflowsApplyCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ClaudeInstallCommand::class)]
#[CoversClass(WorkflowsApplyCommand::class)]
final class ClaudeInstallTest extends TestCase
{
    use UsesTemporaryDirectory;
    /**
     * The classes allowed to open a connection, each by the decision that named it.
     */
    private const array CONNECTING = [
        'Ai/Api/AnthropicClient.php',
        'Release/GitHubReleases.php',
    ];

    public function testItInstallsTheSkillAndRefusesToOverwriteAModifiedOne(): void
    {
        $tester = new CommandTester(new Application()->find('claude:install'));
        $skill = $this->temporaryDirectory().'/.claude/skills/devtools-inspect/SKILL.md';

        self::assertSame(0, $tester->execute(['path' => $this->temporaryDirectory()]));
        self::assertFileEquals(\dirname(__DIR__, 2).'/resources/claude/skills/devtools-inspect/SKILL.md', $skill);
        self::assertFileEquals(\dirname(__DIR__, 2).'/resources/claude/skills/devtools-ask/SKILL.md', $this->temporaryDirectory().'/.claude/skills/devtools-ask/SKILL.md', 'ADR-0039: the skill that answers from the pages.');
        self::assertSame(0, $tester->execute(['path' => $this->temporaryDirectory()]), 'Installing again the same skill is fine.');

        file_put_contents($skill, "# adapted to this project\n", \FILE_APPEND);
        self::assertSame(1, $tester->execute(['path' => $this->temporaryDirectory()]));
        self::assertStringEndsWith("# adapted to this project\n", (string) file_get_contents($skill));

        self::assertSame(0, $tester->execute(['path' => $this->temporaryDirectory(), '--force' => true]));
        self::assertFileEquals(\dirname(__DIR__, 2).'/resources/claude/skills/devtools-inspect/SKILL.md', $skill);
    }

    public function testTheApplyCommandIsAvailable(): void
    {
        self::assertSame(0, new CommandTester(new Application()->find('workflows:apply'))->execute(['path' => $this->temporaryDirectory()]));
    }

    /**
     * ADR-0002: DevTools never talks to Claude, nor to anything else over the network — but for the
     * connections a later decision named, each in one class: the releases `self-update` downloads
     * (ADR-0034), the Claude API `ai:run` writes through when a project turned it on (ADR-0035). The name of Angular's `HttpClient`, which the Angular adapter looks for in the code it
     * reads (ADR-0032), is no connection: what is searched is a client in use — Symfony's, Guzzle, cURL, a
     * socket, an HTTP stream context, a URL opened as a file.
     */
    public function testNoClassOfTheCoreOpensANetworkConnection(): void
    {
        $offending = [];

        $src = \dirname(__DIR__, 2).'/src';

        foreach ((new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS))) as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.php') && 1 === preg_match('/\b(curl_init|fsockopen|stream_socket_client|stream_context_create|file_get_contents\(\s*[\'"]https?:)|Symfony\\\\Component\\\\HttpClient\\\\|HttpClientInterface|GuzzleHttp\\\\/', (string) file_get_contents($file->getPathname()))) {
                $offending[] = substr($file->getPathname(), \strlen($src) + 1);
            }
        }

        sort($offending);

        self::assertSame(self::CONNECTING, $offending);
    }
}
