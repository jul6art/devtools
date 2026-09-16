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

    public function testItInstallsTheSkillAndRefusesToOverwriteAModifiedOne(): void
    {
        $tester = new CommandTester(new Application()->find('claude:install'));
        $skill = $this->temporaryDirectory().'/.claude/skills/devtools-inspect/SKILL.md';

        self::assertSame(0, $tester->execute(['path' => $this->temporaryDirectory()]));
        self::assertFileEquals(\dirname(__DIR__, 2).'/resources/claude/skills/devtools-inspect/SKILL.md', $skill);
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
     * ADR-0002: DevTools never talks to Claude, nor to anything else over the network.
     */
    public function testNoClassOfTheCoreOpensANetworkConnection(): void
    {
        $offending = [];

        foreach ((new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS))) as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.php') && 1 === preg_match('/\b(curl_init|fsockopen|stream_socket_client|HttpClient|file_get_contents\(\s*[\'"]https?:)/', (string) file_get_contents($file->getPathname()))) {
                $offending[] = $file->getFilename();
            }
        }

        self::assertSame([], $offending);
    }
}
