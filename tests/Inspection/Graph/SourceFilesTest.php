<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Adapter\Symfony\ProjectFiles;
use Jul6Art\DevTools\Inspection\Graph\SourceFiles;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which files of a stack exist for the analysis: the ones under its directories, never under an excluded
 * one. A file listed here becomes "not covered" when no workflow reaches it — so a `vendor/` that slipped
 * through would flood the menu, and a source directory skipped would hide real code.
 */
#[CoversClass(SourceFiles::class)]
#[CoversClass(ProjectFiles::class)]
final class SourceFilesTest extends TestCase
{
    use UsesTemporaryDirectory;

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->temporaryDirectory().'/project';

        foreach ([
            'api/src/Controller/OrderController.php' => "<?php\n",
            'api/src/Service/Pricing.php' => "<?php\n",
            'api/src/Resources/views/order.html.twig' => "{{ order }}\n",
            'api/src/vendor/Leaked.php' => "<?php\n",
            'api/config/packages/framework.yaml' => "framework:\n    secret: '%env(APP_SECRET)%'\n",
            'api/config/packages/messenger.yaml' => "framework:\n    messenger:\n        transports: ~\n",
            'api/config/packages/twig.yml' => "twig:\n    default_path: '%kernel.project_dir%/templates'\n",
            'api/config/packages/notes.txt' => "twig:\n",
            'api/tests/OrderTest.php' => "<?php \$client->request('GET', '/orders');\n",
            'api/tests/ShowTest.php' => "<?php \$client->request(\"GET\", \"/orders/1\");\n",
            'api/tests/fixtures.json' => "{\"path\": \"/orders\"}\n",
            'front/src/main.ts' => "export {};\n",
        ] as $path => $content) {
            if (!is_dir(\dirname($this->project.'/'.$path))) {
                mkdir(\dirname($this->project.'/'.$path), 0o777, true);
            }

            file_put_contents($this->project.'/'.$path, $content);
        }
    }

    public function testTheFilesOfAStackAreListedFromTheProjectRootSortedAndWithoutTheExcludedDirectories(): void
    {
        self::assertSame(
            ['api/src/Controller/OrderController.php', 'api/src/Service/Pricing.php', 'api/tests/OrderTest.php', 'api/tests/ShowTest.php'],
            SourceFiles::in(new ProjectRoot($this->project), $this->stack(), ['tests', 'src', 'src/Controller', 'missing'], ['php']),
            'Relative to the project, not the stack; sorted; listed once though reached twice; a missing directory is no error.',
        );
    }

    public function testWithoutExtensionsEveryFileCounts(): void
    {
        self::assertSame(
            ['api/tests/OrderTest.php', 'api/tests/ShowTest.php', 'api/tests/fixtures.json'],
            SourceFiles::in(new ProjectRoot($this->project), $this->stack(), ['tests'], []),
        );
    }

    public function testAStackAtTheRootOfTheProjectListsFromThere(): void
    {
        self::assertSame(['front/src/main.ts'], SourceFiles::in(new ProjectRoot($this->project), $this->stack('.'), ['front'], ['ts']));
    }

    public function testOnlyTheConventionalFilesThatExistAreKept(): void
    {
        self::assertEquals(
            [new FileRef('api/config/packages/framework.yaml', FileRole::Config)],
            $this->files()->existing(['config/packages/framework.yaml', 'config/services.yaml'], FileRole::Config),
        );
    }

    public function testADirectoryIsReadWithTheExtensionsOfConfiguration(): void
    {
        self::assertSame(
            ['api/config/packages/framework.yaml', 'api/config/packages/messenger.yaml', 'api/config/packages/twig.yml'],
            self::paths($this->files()->in('config/packages', FileRole::Config)),
        );
    }

    /**
     * A key is declared at the top level or one level down (`framework.messenger`), not anywhere it is
     * merely mentioned — and only in YAML: a text file that happens to start with it does not configure it.
     */
    public function testTheConfigurationDeclaringAKeyIsFoundAtTheTopOrOneLevelDown(): void
    {
        self::assertSame(['api/config/packages/twig.yml'], self::paths($this->files()->declaring('twig')));
        self::assertSame(['api/config/packages/messenger.yaml'], self::paths($this->files()->declaring('messenger')));
        self::assertSame(['api/config/packages/framework.yaml', 'api/config/packages/messenger.yaml'], self::paths($this->files()->declaring('framework')));
        self::assertSame([], self::paths($this->files()->declaring('transports')), 'Eight spaces deep is not a declaration.');
    }

    public function testATestRequestingTheLiteralPathOfARouteIsItsTest(): void
    {
        $files = $this->files();

        self::assertEquals([new FileRef('api/tests/OrderTest.php', FileRole::Test)], $files->testsRequesting('/orders'));
        self::assertEquals([new FileRef('api/tests/ShowTest.php', FileRole::Test)], $files->testsRequesting('/orders/1'), 'Double quotes count too.');
        self::assertSame([], $files->testsRequesting('/orders/{id}'), 'The literal form of a parameter is unknowable.');
        self::assertSame([], $files->testsRequesting('/order'), 'A prefix of a path is not the path.');
    }

    /**
     * @param list<FileRef> $files
     *
     * @return list<string>
     */
    private static function paths(array $files): array
    {
        return array_map(static fn (FileRef $file): string => $file->path, $files);
    }

    private function files(): ProjectFiles
    {
        return new ProjectFiles(new ProjectRoot($this->project), $this->stack());
    }

    private function stack(string $root = 'api'): StackProfile
    {
        return new StackProfile($root, 'php', 'symfony', '8.1', 'composer', ['src'], ['tests'], ['vendor', 'var'], 'symfony', 'symfony-8');
    }
}
