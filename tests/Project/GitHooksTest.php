<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Project;

use Jul6Art\DevTools\Command\GitInstallHooksCommand;
use Jul6Art\DevTools\Command\GitUninstallHooksCommand;
use Jul6Art\DevTools\Project\GitHooks;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * ADR-0017: hooks that chain instead of overwriting, and that never block a commit for a technical reason.
 */
#[CoversClass(GitHooks::class)]
#[CoversClass(GitInstallHooksCommand::class)]
#[CoversClass(GitUninstallHooksCommand::class)]
final class GitHooksTest extends TestCase
{
    use UsesTemporaryDirectory;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testItWritesTheThreeHooksExecutable(): void
    {
        $project = $this->repository();

        self::assertSame(['pre-commit', 'post-merge', 'post-checkout'], new GitHooks()->install(new ProjectRoot($project)));

        foreach (['pre-commit', 'post-merge', 'post-checkout'] as $hook) {
            $file = $project.'/.git/hooks/'.$hook;

            self::assertFileExists($file);
            self::assertTrue(is_executable($file), $hook.' must be executable, or git ignores it.');
            self::assertStringContainsString(GitHooks::BEGIN, (string) file_get_contents($file));
        }

        self::assertStringContainsString('workflows:check', (string) file_get_contents($project.'/.git/hooks/pre-commit'));
        self::assertStringContainsString('workflows:inspect --no-ai', (string) file_get_contents($project.'/.git/hooks/post-merge'));
    }

    /**
     * ⚠️ Someone else's hook is someone else's work: the block is appended, and removed at the byte.
     */
    public function testAnExistingHookIsChainedThenRestoredExactly(): void
    {
        $project = $this->repository();
        $file = $project.'/.git/hooks/pre-commit';
        $theirs = "#!/bin/sh\necho 'their own check'\nexit 0\n";
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o777, true);
        }

        file_put_contents($file, $theirs);

        $hooks = new GitHooks();
        $hooks->install(new ProjectRoot($project));

        self::assertStringContainsString('their own check', (string) file_get_contents($file));
        self::assertStringContainsString('workflows:check', (string) file_get_contents($file));

        $hooks->uninstall(new ProjectRoot($project));

        self::assertSame($theirs, (string) file_get_contents($file), 'Restored to the byte.');
    }

    public function testAHookItCreatedGoesAwayWithItsBlock(): void
    {
        $project = $this->repository();
        $hooks = new GitHooks();
        $hooks->install(new ProjectRoot($project));

        self::assertSame(['pre-commit', 'post-merge', 'post-checkout'], $hooks->uninstall(new ProjectRoot($project)));
        self::assertFileDoesNotExist($project.'/.git/hooks/pre-commit');
    }

    public function testInstallingTwiceReplacesTheBlockInsteadOfStackingIt(): void
    {
        $project = $this->repository();
        $hooks = new GitHooks();
        $hooks->install(new ProjectRoot($project));
        $hooks->install(new ProjectRoot($project));

        self::assertSame(1, substr_count((string) file_get_contents($project.'/.git/hooks/pre-commit'), GitHooks::BEGIN));
    }

    public function testStrictRefusesTheCommitWhereTheDefaultOnlyWarns(): void
    {
        $project = $this->repository();
        new GitHooks()->install(new ProjectRoot($project));

        self::assertStringContainsString('exit 0', (string) file_get_contents($project.'/.git/hooks/pre-commit'));

        new GitHooks()->install(new ProjectRoot($project), true);

        self::assertStringContainsString('|| exit 1', (string) file_get_contents($project.'/.git/hooks/pre-commit'));
    }

    /**
     * ⚠️ The hook must let the commit through when DevTools is not there: it is run by people who never
     * installed it.
     */
    public function testAHookWithoutDevToolsInstalledLetsTheCommitThrough(): void
    {
        $project = $this->repository();
        new GitHooks('/definitely/not/here/devtools')->install(new ProjectRoot($project));
        mkdir($project.'/.devtools');

        $process = new Process(['sh', '.git/hooks/pre-commit'], $project);
        $process->run();

        self::assertSame(0, $process->getExitCode(), 'No DevTools, no obstacle.');
    }

    public function testAProjectWithoutDevToolsDirectoryIsLeftAlone(): void
    {
        $project = $this->repository();
        new GitHooks('/bin/echo')->install(new ProjectRoot($project));

        $process = new Process(['sh', '.git/hooks/pre-commit'], $project);
        $process->run();

        self::assertSame(0, $process->getExitCode());
        self::assertSame('', trim($process->getOutput()), 'Nothing ran: there is nothing to check.');
    }

    public function testTheHooksPathConfiguredByTheProjectIsRespected(): void
    {
        $project = $this->repository();
        mkdir($project.'/.githooks');
        GitRepository::initialise($project)->git('config', 'core.hooksPath', '.githooks');

        new GitHooks()->install(new ProjectRoot($project));

        self::assertFileExists($project.'/.githooks/pre-commit');
        self::assertFileDoesNotExist($project.'/.git/hooks/pre-commit');
    }

    public function testTheCommandsReportWhatTheyDid(): void
    {
        $project = $this->repository();
        $tester = new CommandTester(new GitInstallHooksCommand());

        self::assertSame(0, $tester->execute(['path' => $project]));
        self::assertStringContainsString('pre-commit', $tester->getDisplay());

        $tester = new CommandTester(new GitUninstallHooksCommand());

        self::assertSame(0, $tester->execute(['path' => $project]));
        self::assertStringContainsString('cleaned', $tester->getDisplay());
    }

    public function testOutsideAGitRepositoryThereIsNothingToInstall(): void
    {
        $directory = $this->temporaryDirectory().'/plain';
        mkdir($directory, 0o777, true);

        self::assertSame(1, new CommandTester(new GitInstallHooksCommand())->execute(['path' => $directory]));
        self::assertSame(2, new CommandTester(new GitInstallHooksCommand())->execute(['path' => '/definitely/not/here']));
        self::assertSame(2, new CommandTester(new GitUninstallHooksCommand())->execute(['path' => '/definitely/not/here']));
    }

    private function repository(): string
    {
        $project = $this->temporaryDirectory().'/project';
        mkdir($project, 0o777, true);
        GitRepository::initialise($project);

        return $project;
    }
}
