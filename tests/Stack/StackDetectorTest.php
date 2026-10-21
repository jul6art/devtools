<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Stack;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackDetectionFailed;
use Jul6Art\DevTools\Stack\StackDetector;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Stack\XmlStackStore;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversNamespace('Jul6Art\DevTools\Stack')]
#[CoversClass(StackDetector::class)]
final class StackDetectorTest extends TestCase
{
    use AssertsSnapshots;
    use UsesTemporaryDirectory;

    private const string PROJECTS = __DIR__.'/../Fixtures/projects';

    #[DataProvider('fixtureProjects')]
    public function testEachFixtureProjectMatchesItsExpectedStackFile(string $project): void
    {
        $document = new StackDetector()->detect(new ProjectRoot(self::PROJECTS.'/'.$project), new Config());

        self::assertMatchesSnapshot(new XmlStackStore()->serialize($document), __DIR__.'/../Fixtures/expected/'.$project.'/.devtools/stack.xml');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtureProjects(): iterable
    {
        foreach (['symfony-minimal', 'laravel-minimal', 'plain-php', 'node-express', 'angular-minimal', 'monorepo', 'node-app-monorepo'] as $project) {
            yield $project => [$project];
        }
    }

    public function testTheSymfonyVersionComesFromTheLockWhenThereIsOne(): void
    {
        $stack = $this->onlyStack('symfony-minimal');

        self::assertSame('symfony', $stack->framework);
        self::assertSame('8.1', $stack->version);
        self::assertSame('symfony', $stack->adapter);
        self::assertSame('symfony-8', $stack->knowledgeKey);
        self::assertSame(['config', 'migrations', 'src', 'templates'], $stack->sourceDirs);
        self::assertSame(['tests'], $stack->testDirs);
    }

    /**
     * ADR-0030: Laravel has its native adapter, and its version comes from the lock.
     */
    public function testALaravelProjectIsReadByItsNativeAdapter(): void
    {
        $stack = $this->onlyStack('laravel-minimal');

        self::assertSame(['laravel', '13.38', 'laravel', 'laravel-13'], [$stack->framework, $stack->version, $stack->adapter, $stack->knowledgeKey]);
        self::assertSame(['app', 'config', 'database', 'database/seeders', 'resources/views', 'routes'], $stack->sourceDirs);
    }

    public function testWithoutALockTheVersionComesFromTheConstraint(): void
    {
        $project = $this->copyOf('symfony-minimal');
        unlink($project.'/composer.lock');
        file_put_contents($project.'/composer.json', str_replace('"symfony/framework-bundle": "^8.1"', '"symfony/framework-bundle": "^7.4"', (string) file_get_contents($project.'/composer.json')));

        $stack = new StackDetector()->detect(new ProjectRoot($project), new Config())->stacks[0];

        self::assertSame('7.4', $stack->version);
        self::assertSame('symfony-7', $stack->knowledgeKey);
    }

    public function testAMonorepoHasOneStackPerRoot(): void
    {
        $document = new StackDetector()->detect(new ProjectRoot(self::PROJECTS.'/monorepo'), new Config());

        self::assertSame(['api', 'front'], array_map(static fn (StackProfile $stack): string => $stack->root, $document->stacks));
        self::assertSame(['symfony', 'angular'], array_map(static fn (StackProfile $stack): ?string => $stack->framework, $document->stacks));
        self::assertSame('monorepo', $document->projectName);
    }

    public function testAFrameworklessPackageJsonNextToASymfonyProjectDoesNotBecomeAStack(): void
    {
        $project = $this->copyOf('symfony-minimal');
        file_put_contents($project.'/package.json', '{"name": "assets", "devDependencies": {"@symfony/webpack-encore": "^5.0"}}');

        self::assertCount(1, new StackDetector()->detect(new ProjectRoot($project), new Config())->stacks);
    }

    /** ADR-0050: an application Node package next to a back office is a stack; the Encore package.json is not. */
    public function testAFrameworklessNodeApplicationNextToASymfonyProjectIsAStack(): void
    {
        $document = new StackDetector()->detect(new ProjectRoot(self::PROJECTS.'/node-app-monorepo'), new Config());

        self::assertSame(['.', 'worker'], array_map(static fn (StackProfile $stack): string => $stack->root, $document->stacks));
        $worker = $document->stacks[1];
        self::assertSame('node', $worker->framework);
        self::assertSame('javascript', $worker->language, 'checkJs: JavaScript that tsc checks, not TypeScript.');
        self::assertSame('24.1', $worker->version);
        self::assertSame('node-24', $worker->knowledgeKey);
        self::assertSame('claude', $worker->adapter);
        self::assertSame(['bin', 'src'], $worker->sourceDirs, 'The directory of the entry point is a source.');
        self::assertSame(['test'], $worker->testDirs);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function toolingManifests(): iterable
    {
        yield 'an entry point without runtime dependencies' => [['name' => 'tool', 'main' => 'bin/worker.js', 'devDependencies' => ['eslint' => '^9.0']]];
        yield 'runtime dependencies without an entry point' => [['name' => 'lib', 'dependencies' => ['mysql2' => '^3.11']]];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    #[DataProvider('toolingManifests')]
    public function testAFrameworklessPackageNeedsAnEntryPointAndRuntimeDependenciesToBeAnApplication(array $manifest): void
    {
        $project = $this->copyOf('node-app-monorepo');
        file_put_contents($project.'/worker/package.json', json_encode($manifest, \JSON_THROW_ON_ERROR));

        self::assertSame(['.'], array_map(static fn (StackProfile $stack): string => $stack->root, new StackDetector()->detect(new ProjectRoot($project), new Config())->stacks));
    }

    public function testAStartScriptIsAnEntryPointAndABinMapNamesSources(): void
    {
        $project = $this->copyOf('node-app-monorepo');
        mkdir($project.'/worker/cli');
        file_put_contents($project.'/worker/package.json', json_encode(['name' => 'acme-worker', 'bin' => ['acme' => './cli/acme.js'], 'scripts' => ['start' => 'node cli/acme.js'], 'dependencies' => ['mysql2' => '^3.11']], \JSON_THROW_ON_ERROR));

        $worker = new StackDetector()->detect(new ProjectRoot($project), new Config())->stacks[1];

        self::assertSame(['cli', 'src'], $worker->sourceDirs);
    }

    public function testTheNodeVersionComesFromTheEnginesThenTheNvmrcThenNowhere(): void
    {
        $project = $this->copyOf('node-app-monorepo');
        $manifest = json_decode((string) file_get_contents($project.'/worker/package.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        unset($manifest['engines']);
        file_put_contents($project.'/worker/package.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
        file_put_contents($project.'/worker/.nvmrc', "v24.3.0\n");

        $worker = new StackDetector()->detect(new ProjectRoot($project), new Config())->stacks[1];
        self::assertSame('24.3', $worker->version);

        unlink($project.'/worker/.nvmrc');
        $worker = new StackDetector()->detect(new ProjectRoot($project), new Config())->stacks[1];
        self::assertNull($worker->version);
        self::assertSame('node', $worker->knowledgeKey);
    }

    public function testATsconfigWithoutCheckJsOrUnreadableMeansTypeScript(): void
    {
        $project = $this->copyOf('node-app-monorepo');

        file_put_contents($project.'/worker/tsconfig.json', '{"compilerOptions": {"strict": true}}');
        self::assertSame('typescript', new StackDetector()->detect(new ProjectRoot($project), new Config())->stacks[1]->language);

        // Comments are common in a tsconfig: the file keeps the verdict its presence gave, and nothing throws.
        file_put_contents($project.'/worker/tsconfig.json', "// generated\n{\"compilerOptions\": {\"checkJs\": true}}");
        self::assertSame('typescript', new StackDetector()->detect(new ProjectRoot($project), new Config())->stacks[1]->language);
    }

    public function testAProjectWithoutAKnownManifestIsLeftToClaude(): void
    {
        mkdir($this->temporaryDirectory().'/scripts');
        file_put_contents($this->temporaryDirectory().'/scripts/run.sh', "#!/bin/sh\n");

        $stack = new StackDetector()->detect(new ProjectRoot($this->temporaryDirectory()), new Config())->stacks[0];

        self::assertSame('unknown', $stack->language);
        self::assertSame('claude', $stack->adapter);
        self::assertSame(['.'], $stack->sourceDirs);
        self::assertNull($stack->knowledgeKey);
    }

    public function testALockedElementSurvivesADetectionThatContradictsIt(): void
    {
        $root = new ProjectRoot($this->copyOf('symfony-minimal'));
        $detected = new StackDetector()->detect($root, new Config());
        $store = new XmlStackStore();

        // A human forces the Claude path and adds a source directory, and locks both.
        $edited = str_replace(
            ['<adapter>symfony</adapter>', '<sources>'],
            ['<adapter locked="true">claude</adapter>', '<sources locked="true">'."\n".'      <dir>lib</dir>'],
            $store->serialize($detected),
        );

        $redetected = new StackDetector()->detect($root, new Config(), $store->unserialize($edited, 'stack.xml'));

        self::assertSame('claude', $redetected->stacks[0]->adapter);
        self::assertContains('lib', $redetected->stacks[0]->sourceDirs);
        self::assertSame('8.1', $redetected->stacks[0]->version, 'What is not locked is detected again.');
        self::assertSame($store->serialize($store->unserialize($edited, 'stack.xml')), $store->serialize($redetected), 'The locks themselves are kept.');
    }

    public function testConfiguredExclusionsAreListed(): void
    {
        $stack = new StackDetector()->detect(new ProjectRoot(self::PROJECTS.'/symfony-minimal'), new Config(excludes: ['public/build']))->stacks[0];

        self::assertContains('public/build', $stack->excludedDirs);
        self::assertContains('vendor', $stack->excludedDirs);
    }

    public function testAMalformedManifestNamesTheFile(): void
    {
        file_put_contents($this->temporaryDirectory().'/composer.json', '{"name": ');

        $this->expectException(StackDetectionFailed::class);
        $this->expectExceptionMessage('composer.json');

        new StackDetector()->detect(new ProjectRoot($this->temporaryDirectory()), new Config());
    }

    public function testTwoConsecutiveDetectionsWriteTheSameBytes(): void
    {
        $root = new ProjectRoot($this->copyOf('monorepo'));
        $store = new XmlStackStore();
        $path = $this->temporaryDirectory().'/stack.xml';

        $store->write($path, new StackDetector()->detect($root, new Config()));
        $first = (string) file_get_contents($path);

        self::assertFalse($store->write($path, new StackDetector()->detect($root, new Config(), $store->read($path))));
        self::assertStringEqualsFile($path, $first);
    }

    public function testTheDocumentSurvivesARoundTrip(): void
    {
        $document = new StackDetector()->detect(new ProjectRoot(self::PROJECTS.'/monorepo'), new Config());
        $store = new XmlStackStore();

        self::assertEquals($document, $store->unserialize($store->serialize($document), 'stack.xml'));
    }

    private function onlyStack(string $project): StackProfile
    {
        $stacks = new StackDetector()->detect(new ProjectRoot(self::PROJECTS.'/'.$project), new Config())->stacks;
        self::assertCount(1, $stacks);

        return $stacks[0];
    }

    private function copyOf(string $project): string
    {
        $copy = $this->temporaryDirectory().'/'.$project;
        new Filesystem()->mirror(self::PROJECTS.'/'.$project, $copy);

        return $copy;
    }
}
