<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\EndToEnd;

use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Rendering\PageParser;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RealConsoleTest;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * `workflows:inspect` as a user runs it: in a separate process, on a real application, standalone and
 * through the bundle.
 */
#[CoversNothing]
#[Group('end-to-end')]
final class InspectCommandEndToEndTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string EXPECTED = __DIR__.'/../Fixtures/expected/symfony-minimal/.devtools';

    /**
     * A fixed clock (SOURCE_DATE_EPOCH) and no enclosing git repository make two runs comparable.
     */
    private const array REPRODUCIBLE = ['SOURCE_DATE_EPOCH' => '1789516800', 'SHELL_VERBOSITY' => '0'];

    public function testTheStandaloneBinaryProducesTheExpectedDevtoolsFolder(): void
    {
        RealConsoleTest::installDependencies(__DIR__.'/../Fixtures/projects/symfony-minimal');
        $project = $this->copyFixtureProject('symfony-minimal', withDependencies: true);

        $run = $this->runInProject([\PHP_BINARY, \dirname(__DIR__, 2).'/bin/devtools', 'workflows:inspect', $project, '--no-ai'], $project);

        self::assertSame(0, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        $this->assertTreeMatchesExpected($project.'/.devtools');

        $tracking = new XmlTrackingStore(new WorkflowTypeRegistry());

        foreach (glob($project.'/.devtools/workflows/*/*.xml') ?: [] as $file) {
            $tracking->read($file);
            self::assertSame([], new PageParser()->parse((string) file_get_contents(substr($file, 0, -4).'.md'))->conformityProblems(), $file);
        }
    }

    public function testTheBundleInBinConsoleProducesTheSameFolder(): void
    {
        RealConsoleTest::installDependencies(__DIR__.'/../Fixtures/projects/symfony-minimal');
        $project = $this->copyFixtureProject('symfony-minimal', withDependencies: true);

        $composer = json_decode((string) file_get_contents($project.'/composer.json'), true);
        self::assertIsArray($composer);
        $composer['repositories'] = [['type' => 'path', 'url' => \dirname(__DIR__, 2), 'options' => ['symlink' => true, 'versions' => ['jul6art/devtools' => '1.0.0']]]];
        file_put_contents($project.'/composer.json', json_encode($composer, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $require = new Process(['composer', 'require', '--dev', 'jul6art/devtools:1.0.0', '--no-interaction', '--no-progress', '--quiet'], $project, timeout: 600);
        $require->run();

        if (!$require->isSuccessful()) {
            self::markTestSkipped('DevTools could not be installed into the fixture: '.$require->getErrorOutput());
        }

        file_put_contents($project.'/config/bundles.php', str_replace('];', "    Jul6Art\\DevTools\\Bridge\\Symfony\\DevToolsBundle::class => ['dev' => true, 'test' => true],\n];", (string) file_get_contents($project.'/config/bundles.php')));
        new Filesystem()->remove($project.'/var');

        $run = $this->runInProject([\PHP_BINARY, 'bin/console', 'devtools:workflows:inspect', '--no-ai'], $project);

        self::assertSame(0, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        $this->assertTreeMatchesExpected($project.'/.devtools');
    }

    /**
     * @param list<string> $command
     */
    private function runInProject(array $command, string $project): Process
    {
        $process = new Process($command, $project, [...self::REPRODUCIBLE, 'GIT_CEILING_DIRECTORIES' => \dirname($project)], timeout: 300);
        $process->run();

        return $process;
    }

    private function assertTreeMatchesExpected(string $devtools): void
    {
        $actual = self::normalised(self::tree($devtools));

        if ('1' === getenv('DEVTOOLS_UPDATE_SNAPSHOTS')) {
            new Filesystem()->remove(array_map(static fn (string $path): string => self::EXPECTED.'/'.$path, array_keys(self::normalised(self::tree(self::EXPECTED)))));

            foreach ($actual as $path => $content) {
                new Filesystem()->dumpFile(self::EXPECTED.'/'.$path, $content);
            }
        }

        self::assertSame(self::normalised(self::tree(self::EXPECTED)), $actual);
    }

    /**
     * Reports are never compared (they carry a duration); the tool version depends on the checkout.
     *
     * @param array<string, string> $tree
     *
     * @return array<string, string>
     */
    private static function normalised(array $tree): array
    {
        $kept = [];

        foreach ($tree as $path => $content) {
            if (!str_starts_with($path, 'reports/') && !str_starts_with($path, 'schemas/')) {
                $kept[$path] = (string) preg_replace('/tool="devtools [^"]*"/', 'tool="devtools"', $content);
            }
        }

        return $kept;
    }
}
