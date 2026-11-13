<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ProjectFiles;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\Graph\EntryPointCandidate;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Tests\Inspection\Graph\GraphFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ADR-0052: the controller of a package, routed by the project's own route file, is the project's workflow — its
 * entry point declared in that file, its package a dependency. A route a bundle declares (an imported resource,
 * the profiler's) stays out, as before.
 */
#[CoversClass(SymfonyAdapter::class)]
#[CoversClass(ProjectFiles::class)]
#[CoversClass(EntryPointCandidate::class)]
#[CoversClass(WorkflowBuilder::class)]
final class RoutedPackageControllerTest extends TestCase
{
    private const array ROUTES = [
        'app_home' => ['/', 'GET', 'App\Controller\HomeController'],
        'admin_role_permission_index' => ['/admin/role-permissions', 'GET', 'Jul6Art\AdminBundle\Controller\RolePermissionController::index'],
        'admin_role_permission_save' => ['/admin/role-permissions', 'POST', 'Jul6Art\AdminBundle\Controller\RolePermissionController::save'],
        'legacy_dashboard' => ['/dashboard', 'GET', 'Symfony\Bundle\FrameworkBundle\Controller\RedirectController'],
        'legacy_gallery' => ['/dashboard/gallery', 'GET', 'Symfony\Bundle\FrameworkBundle\Controller\RedirectController'],
        // Localized: Symfony names each variant `<key>.<locale>`.
        'site_account_two_factor.en' => ['/en/me/security', 'GET|POST', 'Jul6Art\AdminBundle\Controller\TwoFactorController::show'],
        'site_account_two_factor.fr' => ['/fr/moi/securite', 'GET|POST', 'Jul6Art\AdminBundle\Controller\TwoFactorController::show'],
        // Imported from the bundle (`resource:`): its name is no key of the project's files.
        'admin_appearance_edit' => ['/admin/appearance', 'GET|POST', 'Jul6Art\AdminBundle\Controller\AppearanceController::edit'],
        // The profiler's: a service, not a class of a package.
        '_wdt' => ['/_wdt/{token}', 'ANY', 'web_profiler.controller.profiler::toolbarAction'],
        // A key of the project's file, but with another controller than the one the console reports.
        'admin_decoy' => ['/admin/decoy', 'GET', 'Jul6Art\AdminBundle\Controller\DecoyController'],
    ];

    private string $project;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/jul6art-devtools-tests/routed-package-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();

        $filesystem->dumpFile($this->project.'/composer.json', (string) json_encode([
            'require' => ['symfony/framework-bundle' => '^8.0', 'jul6art/admin-bundle' => '^1.41'],
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
        ]));
        $filesystem->dumpFile($this->project.'/composer.lock', (string) json_encode(['packages' => [
            ['name' => 'jul6art/admin-bundle', 'version' => 'v1.41.0', 'autoload' => ['psr-4' => ['Jul6Art\AdminBundle\\' => '']]],
            ['name' => 'symfony/framework-bundle', 'version' => 'v8.1.7', 'autoload' => ['psr-4' => ['Symfony\Bundle\FrameworkBundle\\' => '']]],
        ], 'packages-dev' => []]));
        $filesystem->dumpFile($this->project.'/src/Controller/HomeController.php', <<<'PHP'
            <?php

            namespace App\Controller;

            use Symfony\Component\Routing\Attribute\Route;

            final class HomeController
            {
                #[Route('/', name: 'app_home', methods: ['GET'])]
                public function __invoke(): void
                {
                }
            }
            PHP);
        $filesystem->dumpFile($this->project.'/config/routes.yaml', <<<'YAML'
            controllers:
                resource:
                    path: ../src/Controller/
                    namespace: App\Controller
                type: attribute
            YAML);
        $filesystem->dumpFile($this->project.'/config/routes/role_permission.yaml', <<<'YAML'
            # The grid's screen comes from admin-bundle; the project names its routes.
            admin_role_permission_index:
                path: /admin/role-permissions
                controller: Jul6Art\AdminBundle\Controller\RolePermissionController::index
                methods: [GET]

            admin_role_permission_save:
                path: /admin/role-permissions
                controller: 'Jul6Art\\AdminBundle\\Controller\\RolePermissionController::save'
                methods: [POST]

            admin_appearance:
                resource: '@AdminBundle/Controller/AppearanceController.php'
                type: attribute

            admin_decoy:
                path: /admin/decoy
                controller: Jul6Art\AdminBundle\Controller\SomethingElseController
            YAML);
        $filesystem->dumpFile($this->project.'/config/routes/legacy.yaml', <<<'YAML'
            when@prod:
                legacy_dashboard:
                    path: /dashboard
                    controller: Symfony\Bundle\FrameworkBundle\Controller\RedirectController
                    defaults: { route: app_home, permanent: true }
                legacy_gallery:
                    path: /dashboard/gallery
                    controller: Symfony\Bundle\FrameworkBundle\Controller\RedirectController
                    defaults: { route: app_home, permanent: true }
            YAML);
        $filesystem->dumpFile($this->project.'/config/routes/two_factor.yaml', <<<'YAML'
            site_account_two_factor:
                path:
                    fr: /fr/moi/securite
                    en: /en/me/security
                controller: Jul6Art\AdminBundle\Controller\TwoFactorController::show
                methods: [GET, POST]
            YAML);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->project);
    }

    public function testAPackagesControllerTheProjectRoutesIsItsWorkflow(): void
    {
        $result = $this->inspect();

        self::assertSame(
            ['route.admin.role.permission.index', 'route.admin.role.permission.save', 'route.home', 'route.legacy.dashboard', 'route.site.account.two.factor.en'],
            array_map(static fn (Workflow $workflow): string => (string) $workflow->id, $result->workflows),
            'Neither the imported routes, nor the profiler\'s, nor a key whose controller is another.',
        );

        $grid = $this->workflow($result, 'route.admin.role.permission.save');
        self::assertSame('config/routes/role_permission.yaml', $grid->main->declaredIn->path, 'Declared where the project routes it.');
        self::assertSame(['controller' => 'Jul6Art\AdminBundle\Controller\RolePermissionController::save', 'methods' => 'POST', 'path' => '/admin/role-permissions'], $grid->main->attributes);
        self::assertSame(['jul6art/admin-bundle@1.41.0'], array_map(static fn (PackageRef $package): string => $package->name.'@'.$package->version, $grid->packages));
        self::assertContains('config/routes/role_permission.yaml', array_map(static fn (FileRef $file): string => $file->path, $grid->files));

        $redirect = $this->workflow($result, 'route.legacy.dashboard');
        self::assertSame('config/routes/legacy.yaml', $redirect->main->declaredIn->path, 'Under when@prod too.');
        self::assertSame(['symfony/framework-bundle'], array_map(static fn (PackageRef $package): string => $package->name, $redirect->packages));
        self::assertSame(['legacy_gallery'], array_map(static fn (EntryPoint $satellite): string => $satellite->name, $redirect->satellites), 'One action of one file: one workflow, like a method of the project\'s.');

        $twoFactor = $this->workflow($result, 'route.site.account.two.factor.en');
        self::assertSame('config/routes/two_factor.yaml', $twoFactor->main->declaredIn->path, 'Found by the key the locales share.');
        self::assertSame(['site_account_two_factor.fr'], array_map(static fn (EntryPoint $satellite): string => $satellite->name, $twoFactor->satellites));

        self::assertSame([], $this->workflow($result, 'route.home')->packages, 'The project\'s own controller is unchanged.');
    }

    public function testWithoutTheRouteFileThePackagesControllerStaysOut(): void
    {
        new Filesystem()->remove([$this->project.'/config/routes/role_permission.yaml', $this->project.'/config/routes/legacy.yaml', $this->project.'/config/routes/two_factor.yaml']);

        self::assertSame(['route.home'], array_map(static fn (Workflow $workflow): string => (string) $workflow->id, $this->inspect()->workflows));
    }

    private function inspect(): InspectionResult
    {
        $routes = [];

        foreach (self::ROUTES as $name => [$path, $methods, $controller]) {
            $routes[$name] = ['path' => $path, 'method' => $methods, 'defaults' => ['_controller' => $controller]];
        }

        $root = GraphFixture::root($this->project);
        $stack = GraphFixture::stack($this->project);
        $extractor = new PhpReferenceExtractor();
        // The other questions get symfony-minimal's answers: their classes are not this project's, so they add nothing.
        $adapter = new SymfonyAdapter(new RecordedConsoleRunner(['router' => (string) json_encode($routes)]))->extract($root, $stack, new Config(), $extractor);
        self::assertNull($adapter->fallbackCause, 'The console answered: no static stand-in.');

        return new WorkflowBuilder(new Config())->build($root, $stack, $adapter->candidates, $adapter->templateDirectories, $extractor, $adapter->mechanisms)->result;
    }

    private function workflow(InspectionResult $result, string $id): Workflow
    {
        foreach ($result->workflows as $workflow) {
            if ($id === (string) $workflow->id) {
                return $workflow;
            }
        }

        self::fail(\sprintf('No workflow "%s".', $id));
    }
}
