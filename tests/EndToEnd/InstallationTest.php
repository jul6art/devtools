<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\EndToEnd;

use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The two ways the README installs DevTools (specs § 12 question 5), from this checkout as a Composer path
 * repository: globally, and in a project's require-dev.
 */
#[CoversNothing]
#[Group('end-to-end')]
final class InstallationTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string VERSION = '1.0.0';

    public function testAGlobalInstallationDocumentsAnyProject(): void
    {
        $home = $this->temporaryDirectory().'/composer-home';
        mkdir($home);
        $environment = ['COMPOSER_HOME' => $home, 'COMPOSER_CACHE_DIR' => $this->cacheDirectory()];

        $this->composer(['global', 'config', 'repositories.devtools', $this->repository()], $home, $environment);
        $this->composer(['global', 'require', 'jul6art/devtools:'.self::VERSION], $home, $environment);

        $project = $this->copyFixtureProject('plain-php');
        $run = $this->devtools([$home.'/vendor/bin/devtools', 'workflows:inspect', '--no-ai'], $project);

        self::assertSame(0, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        self::assertFileExists($project.'/.devtools/workflows/routes/orders.new.md');
    }

    public function testARequireDevInstallationDocumentsItsProject(): void
    {
        $project = $this->copyFixtureProject('plain-php');
        $environment = ['COMPOSER_CACHE_DIR' => $this->cacheDirectory()];

        $this->composer(['config', 'repositories.devtools', $this->repository()], $project, $environment);
        $this->composer(['require', '--dev', 'jul6art/devtools:'.self::VERSION], $project, $environment);

        $run = $this->devtools([$project.'/vendor/bin/devtools', 'workflows:inspect', '--no-ai'], $project);

        self::assertSame(0, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        self::assertFileExists($project.'/.devtools/workflows/routes/orders.new.md');
        self::assertStringNotContainsString('vendor/', (string) file_get_contents($project.'/.devtools/graph/files-to-workflows.xml'), 'Its own vendor/ is not documented.');
    }

    private function repository(): string
    {
        return (string) json_encode(['type' => 'path', 'url' => \dirname(__DIR__, 2), 'options' => ['symlink' => true, 'versions' => ['jul6art/devtools' => self::VERSION]]], \JSON_UNESCAPED_SLASHES);
    }

    /**
     * The machine's Composer cache, so that installing does not download everything again.
     */
    private function cacheDirectory(): string
    {
        $process = new Process(['composer', 'config', '--global', 'cache-dir']);
        $process->run();

        return trim($process->getOutput()) ?: sys_get_temp_dir().'/composer-cache';
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private function composer(array $arguments, string $directory, array $environment): void
    {
        $downloads = \in_array('require', $arguments, true);
        $process = new Process(['composer', ...$arguments, '--no-interaction', '--quiet', ...$downloads ? ['--no-progress'] : []], $directory, $environment, timeout: 600);
        $process->run();

        // Only a download may be unavailable; a wrong configuration command is a failure.
        self::assertTrue($downloads || $process->isSuccessful(), $process->getErrorOutput());

        if (!$process->isSuccessful()) {
            self::markTestSkipped('DevTools could not be installed (Composer or network unavailable): '.$process->getErrorOutput());
        }
    }

    /**
     * The binary is given by its absolute path: Symfony Process may start a relative command a second time
     * through its resolved path, and two inspections then compete for the project's lock.
     *
     * @param list<string> $command
     */
    private function devtools(array $command, string $project): Process
    {
        $process = new Process($command, $project, ['SOURCE_DATE_EPOCH' => '1789560000', 'SHELL_VERBOSITY' => '0', 'GIT_CEILING_DIRECTORIES' => \dirname($project)], timeout: 300);
        $process->run();

        return $process;
    }
}
