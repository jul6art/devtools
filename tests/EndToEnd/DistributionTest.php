<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\EndToEnd;

use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RealConsoleTest;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tests\Support\NodeDependencies;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * ADR-0034: the phar and the Docker image document a project exactly as the Composer installation does.
 *
 * The phar is built with Box — `DEVTOOLS_BOX`, or `box` on the PATH — from a copy of this repository with its
 * production dependencies; the image from that phar. Without Box or Docker, the test says so and is skipped.
 */
#[CoversNothing]
#[Group('end-to-end')]
#[Group('distribution')]
final class DistributionTest extends TestCase
{
    use CopiesFixtureProjects;

    private const array REPRODUCIBLE = [
        'SOURCE_DATE_EPOCH' => '1789560000',
        'GIT_AUTHOR_DATE' => '2026-09-16T12:00:00+00:00',
        'GIT_COMMITTER_DATE' => '2026-09-16T12:00:00+00:00',
    ];

    private static ?string $phar = null;

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        if (null !== self::$phar) {
            new Filesystem()->remove(\dirname(self::$phar, 2));
            self::$phar = null;
        }

        parent::tearDownAfterClass();
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (array_keys(self::REPRODUCIBLE) as $name) {
            putenv($name);
            unset($_SERVER[$name]);
        }

        parent::tearDown();
    }

    public function testThePharInspectsASymfonyProjectAsComposerDoes(): void
    {
        $phar = $this->phar();
        RealConsoleTest::installDependencies(__DIR__.'/../Fixtures/projects/symfony-minimal');
        $composer = $this->project('symfony-minimal', 'composer');
        $packaged = $this->project('symfony-minimal', 'phar');

        $this->execute([\PHP_BINARY, \dirname(__DIR__, 2).'/bin/devtools', 'workflows:inspect', '--no-ai', $composer]);
        $this->execute([\PHP_BINARY, $phar, 'workflows:inspect', '--no-ai', $packaged]);

        self::assertSame(self::documented($composer), self::documented($packaged));
        self::assertLessThan(20 * 1024 * 1024, filesize($phar), 'ADR-0034: under 20 MB.');
    }

    public function testTheImageInspectsAMountedAngularProjectAsTheHostDoes(): void
    {
        $docker = new ExecutableFinder()->find('docker') ?? self::markTestSkipped('Docker is not installed.');
        $info = new Process([$docker, 'info']);

        if (0 !== $info->run()) {
            self::markTestSkipped('Docker is not running.');
        }

        $phar = $this->phar();
        NodeDependencies::install(__DIR__.'/../Fixtures/projects/angular-minimal');
        $context = \dirname($phar, 2);
        $this->execute([$docker, 'build', '--quiet', '--tag', 'devtools:test', '--file', \dirname(__DIR__, 2).'/Dockerfile', $context], 900);
        $size = $this->execute([$docker, 'image', 'inspect', '--format', '{{.Size}}', 'devtools:test']);

        $host = $this->project('angular-minimal', 'host');
        $mounted = $this->project('angular-minimal', 'mounted');
        $this->execute([\PHP_BINARY, \dirname(__DIR__, 2).'/bin/devtools', 'workflows:inspect', '--no-ai', $host], expected: 1);
        $this->execute([$docker, 'run', '--rm', '--env', 'SOURCE_DATE_EPOCH='.self::REPRODUCIBLE['SOURCE_DATE_EPOCH'], '--volume', $mounted.':/project', 'devtools:test', 'workflows:inspect', '--no-ai'], 300, 1);

        self::assertSame(self::documented($host), self::documented($mounted));
        self::assertLessThan(250 * 1000 * 1000, (int) trim($size), 'ADR-0034: under 250 MB.');
    }

    /**
     * The phar, built once for the whole run.
     */
    private function phar(): string
    {
        if (null !== self::$phar) {
            return self::$phar;
        }

        $box = getenv('DEVTOOLS_BOX');
        $box = \is_string($box) && is_file($box) ? $box : new ExecutableFinder()->find('box');

        if (null === $box) {
            self::markTestSkipped('Box is not installed: DEVTOOLS_BOX=/path/to/box.phar, or box on the PATH.');
        }

        $build = sys_get_temp_dir().'/devtools-phar-'.bin2hex(random_bytes(4));
        $repository = \dirname(__DIR__, 2);
        new Filesystem()->mirror($repository, $build, new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($repository, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !\in_array(substr($file->getPathname(), \strlen($repository) + 1), ['vendor', 'tests', '.git', 'drivers', 'build', 'var', '.devtools', 'docs'], true),
        ), \RecursiveIteratorIterator::SELF_FIRST));

        $this->execute(['composer', 'install', '--no-dev', '--no-interaction', '--no-progress', '--quiet'], 600, 0, $build, ['COMPOSER_ROOT_VERSION' => '3.4.0']);
        $this->execute([\PHP_BINARY, '-d', 'phar.readonly=0', $box, 'compile', '--no-interaction', '--quiet'], 600, 0, $build);

        return self::$phar = $build.'/build/devtools.phar';
    }

    private function project(string $fixture, string $copy): string
    {
        foreach (self::REPRODUCIBLE as $name => $value) {
            putenv($name.'='.$value);
            $_SERVER[$name] = $value;
        }

        $project = $this->copyFixtureProject($fixture, withDependencies: true);
        $target = \dirname($project).'/'.$copy;
        rename($project, $target);
        file_put_contents($target.'/.gitignore', "/vendor/\n/var/\n/node_modules/\n/config/reference.php\n");
        GitRepository::initialise($target)->commitAll('fixture');

        return $target;
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    private function execute(array $command, int $timeout = 300, int $expected = 0, ?string $directory = null, array $env = []): string
    {
        $process = new Process($command, $directory, [...self::REPRODUCIBLE, ...$env], timeout: $timeout);
        $process->run();

        self::assertSame($expected, $process->getExitCode(), implode(' ', $command)."\n".$process->getOutput().$process->getErrorOutput());

        return $process->getOutput();
    }

    /**
     * @return array<string, string>
     */
    private static function documented(string $project): array
    {
        return array_map(static fn (string $content): string => (string) preg_replace('/tool="devtools [^"]*"/', 'tool="devtools"', $content), self::documentedTree($project));
    }
}
