<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Graph\ClassLocator;
use Jul6Art\DevTools\Inspection\Graph\DependencyResolver;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\ResolvedDependencies;
use Jul6Art\DevTools\Inspection\Graph\RoleGuesser;
use Jul6Art\DevTools\Inspection\Graph\TwigReferenceExtractor;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(DependencyResolver::class)]
#[CoversClass(ResolvedDependencies::class)]
#[CoversClass(ClassLocator::class)]
#[CoversClass(RoleGuesser::class)]
final class DependencyResolverTest extends TestCase
{
    use UsesTemporaryDirectory;

    /**
     * @param list<string> $expected
     */
    #[DataProvider('depths')]
    public function testTheCreationRouteTraversesExactlyTheExpectedFiles(int $depth, array $expected): void
    {
        $resolved = $this->resolver($depth)->resolve(new FileRef('src/Controller/OrderController.php', FileRole::Controller), ['new']);

        self::assertSame($expected, array_map(static fn (FileRef $file): string => $file->path.' ('.$file->role->value.')', $resolved->files));
    }

    /**
     * @return iterable<string, array{int, list<string>}>
     */
    public static function depths(): iterable
    {
        $depthOne = [
            'src/Controller/OrderController.php (controller)',
            'src/Entity/Order.php (entity)',
            'src/Form/OrderType.php (form)',
            'src/Repository/OrderRepository.php (repository)',
            'src/Service/OrderPricing.php (service)',
            'templates/order/new.html.twig (template)',
        ];
        $depthTwo = [...$depthOne, 'src/Repository/ProductRepository.php (repository)', 'src/ValueObject/Money.php (other)', 'templates/base.html.twig (template)', 'templates/order/_form.html.twig (template)'];
        $depthThree = [...$depthTwo, 'src/Entity/Product.php (entity)'];

        foreach ([1 => $depthOne, 2 => $depthTwo, 3 => $depthThree] as $depth => $files) {
            sort($files);

            yield 'depth '.$depth => [$depth, $files];
        }
    }

    public function testTheListRouteDoesNotInheritTheCreationForm(): void
    {
        $resolved = $this->resolver(3)->resolve(new FileRef('src/Controller/OrderController.php', FileRole::Controller), ['index']);

        self::assertSame(
            ['src/Controller/OrderController.php', 'src/Entity/Order.php', 'src/Repository/OrderRepository.php', 'src/ValueObject/Money.php', 'templates/base.html.twig', 'templates/order/index.html.twig'],
            array_map(static fn (FileRef $file): string => $file->path, $resolved->files),
        );
    }

    public function testVendorClassesArePackagesWithTheirLockedVersion(): void
    {
        $resolved = $this->resolver(3)->resolve(new FileRef('src/Controller/OrderController.php', FileRole::Controller), ['new']);

        self::assertEquals([
            new PackageRef('symfony/form', '8.1.7'),
            new PackageRef('symfony/framework-bundle', '8.1.7'),
            new PackageRef('symfony/http-foundation', '8.1.7'),
            new PackageRef('symfony/options-resolver', '8.1.0'),
            new PackageRef('symfony/routing', '8.1.6'),
            new PackageRef('symfony/security-http', '8.1.7'),
        ], $resolved->packages);

        foreach ($resolved->files as $file) {
            self::assertStringNotContainsString('vendor', $file->path);
        }
    }

    public function testStructuralFilesAreAlwaysIncludedWhateverTheDepth(): void
    {
        $resolved = $this->resolver(0)->resolve(new FileRef('src/Controller/OrderController.php', FileRole::Controller), ['new'], [new FileRef('config/services.yaml', FileRole::Config)]);

        self::assertSame(['config/services.yaml', 'src/Controller/OrderController.php'], array_map(static fn (FileRef $file): string => $file->path, $resolved->files));
    }

    public function testDirectFilesAreTheEntryPointAndItsFirstHop(): void
    {
        $resolved = $this->resolver(3)->resolve(new FileRef('src/Controller/OrderController.php', FileRole::Controller), ['index']);

        self::assertSame(
            ['src/Controller/OrderController.php', 'src/Repository/OrderRepository.php', 'templates/order/index.html.twig'],
            array_map(static fn (FileRef $file): string => $file->path, $resolved->direct),
        );
    }

    public function testACycleTerminatesAndAnUnparseableFileIsAWarning(): void
    {
        $project = $this->temporaryDirectory().'/cycle';
        new Filesystem()->dumpFile($project.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "src/"}}}');
        new Filesystem()->dumpFile($project.'/src/A.php', "<?php\nnamespace App;\nfinal class A { public function b(): B { return new B(); } }\n");
        new Filesystem()->dumpFile($project.'/src/B.php', "<?php\nnamespace App;\nfinal class B { public function a(): A { return new A(); } public function c(): C { return new C(); } }\n");
        new Filesystem()->dumpFile($project.'/src/C.php', "<?php\nnamespace App;\nfinal class C {\n");

        $resolved = $this->resolver(10, $project)->resolve(new FileRef('src/A.php'));

        self::assertSame(['src/A.php', 'src/B.php', 'src/C.php'], array_map(static fn (FileRef $file): string => $file->path, $resolved->files));
        self::assertCount(1, $resolved->warnings);
        self::assertStringContainsString('src/C.php', $resolved->warnings[0]);
    }

    public function testRequiredFilesAreFollowedWhenTheyExistInsideTheProject(): void
    {
        $project = $this->temporaryDirectory().'/includes';
        new Filesystem()->dumpFile($project.'/composer.json', '{}');
        new Filesystem()->dumpFile($project.'/bin/cleanup', "#!/usr/bin/env php\n<?php\nrequire __DIR__.'/../lib/db.php';\nrequire __DIR__.'/../lib/missing.php';\nrequire __DIR__.'/../../../outside.php';\n");
        new Filesystem()->dumpFile($project.'/lib/db.php', "<?php\nrequire 'config.php';\n");
        new Filesystem()->dumpFile($project.'/lib/config.php', "<?php\nreturn [];\n");

        $resolved = $this->resolver(3, $project)->resolve(new FileRef('bin/cleanup'));

        self::assertSame(['bin/cleanup', 'lib/config.php', 'lib/db.php'], array_map(static fn (FileRef $file): string => $file->path, $resolved->files), 'An extensionless PHP script is followed; a missing file or one outside the project is not.');
    }

    public function testAFileSharedByManyEntryPointsIsAnalysedOnce(): void
    {
        $extractor = new PhpReferenceExtractor();
        $resolver = $this->resolver(3, GraphFixture::PROJECT, $extractor);

        for ($i = 0; $i < 50; ++$i) {
            $resolver->resolve(new FileRef('src/MessageHandler/OrderCreatedHandler.php'));
        }

        self::assertSame(4, $extractor->parsedFiles(), 'Handler, message, repository and entity: parsed once each, whatever the number of resolutions.');
    }

    private function resolver(int $depth, string $project = GraphFixture::PROJECT, ?PhpReferenceExtractor $extractor = null): DependencyResolver
    {
        $root = GraphFixture::root($project);
        $stack = GraphFixture::stack($project);

        return new DependencyResolver($root, $stack, ClassLocator::for($root, $stack), $extractor ?? new PhpReferenceExtractor(), new TwigReferenceExtractor(), $depth, ['templates']);
    }
}
