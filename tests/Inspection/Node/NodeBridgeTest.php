<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Node;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Graph\RoleGuesser;
use Jul6Art\DevTools\Inspection\Graph\TypeScriptDependencyResolver;
use Jul6Art\DevTools\Inspection\Graph\TypeScriptTestLocator;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Node\NodeBridge;
use Jul6Art\DevTools\Inspection\Node\NodeBridgeFailed;
use Jul6Art\DevTools\Inspection\Node\NodeFacts;
use Jul6Art\DevTools\Inspection\Node\NodeLock;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackDetector;
use Jul6Art\DevTools\Tests\Support\NodeDependencies;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NodeBridge::class)]
#[CoversClass(NodeFacts::class)]
#[CoversClass(NodeLock::class)]
#[CoversClass(TypeScriptDependencyResolver::class)]
#[CoversClass(TypeScriptTestLocator::class)]
final class NodeBridgeTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const string ANGULAR = __DIR__.'/../../Fixtures/projects/angular-minimal';

    public function testOneProcessReadsEveryFileAndResolvesTheTsconfigPaths(): void
    {
        NodeDependencies::install(self::ANGULAR);
        $root = new ProjectRoot(self::ANGULAR);
        $stack = new StackDetector()->detect($root, new Config())->stacks[0];
        $bridge = new NodeBridge();

        $facts = $bridge->analyze($root, $stack, ['src/app/app.routes.ts', 'src/app/orders/order.service.ts', 'src/app/home/home.component.ts'], NodeBridge::QUERIES, ['get', 'post']);

        self::assertSame(1, $bridge->calls(), 'ADR-0031: one Node process per scan, whatever the number of files.');
        self::assertMatchesRegularExpression('/^\d+\.\d+/', $facts->typescript);
        self::assertSame(['src/app/app.routes.ts', 'src/app/orders/order.service.ts', 'src/app/home/home.component.ts'], $facts->files());
        self::assertContains(['specifier' => '@app/auth/auth.guard', 'kind' => 'static', 'resolved' => 'src/app/auth/auth.guard.ts', 'package' => null, 'names' => ['authGuard' => 'authGuard']], $facts->imports('src/app/app.routes.ts'));
        self::assertContains(['specifier' => '@env', 'kind' => 'static', 'resolved' => 'src/environments/environment.ts', 'package' => null, 'names' => ['environment' => 'environment']], $facts->imports('src/app/orders/order.service.ts'));
        self::assertSame(['src/app/home/home.component.ts', 'HomeComponent'], $facts->origin('src/app/app.routes.ts', 'HomeComponent'));
        self::assertSame(['src/app/app.routes.ts', 'routes'], $facts->origin('src/app/app.routes.ts', 'routes'), 'Declared in the file itself.');
        self::assertNull($facts->origin('src/app/app.routes.ts', 'Routes'), 'A type of a package is no file.');
        self::assertSame([['name' => 'Injectable', 'target' => 'OrderService', 'member' => null, 'arguments' => [['providedIn' => 'root']]]], $facts->decorators('src/app/orders/order.service.ts'));
        self::assertSame('Component', $facts->decorators('src/app/home/home.component.ts')[0]['name']);
        self::assertSame(['this.http.get', 'this.http.get', 'this.http.post'], array_column($facts->calls('src/app/orders/order.service.ts'), 'callee'));
        self::assertSame(['HttpClient'], $facts->classes('src/app/orders/order.service.ts')[0]['injects']);
        self::assertSame('routes', $facts->variables('src/app/app.routes.ts')[1]['name']);
        self::assertSame('/api/orders/', NodeFacts::text(['$template' => ['/api/orders/']]));
        self::assertNull(NodeFacts::text(['$template' => [['$expression' => 'environment.apiUrl'], '/orders']]));
        self::assertSame('HomeComponent', NodeFacts::identifier(['$identifier' => 'HomeComponent']));
    }

    public function testTheGraphFollowsImportsDecoratorsUrlsAndPackagesWithTheirLockedVersion(): void
    {
        NodeDependencies::install(self::ANGULAR);
        $root = new ProjectRoot(self::ANGULAR);
        $stack = new StackDetector()->detect($root, new Config())->stacks[0];
        $files = ['src/app/home/home.component.ts', 'src/app/orders/order-list.component.ts', 'src/app/orders/order.service.ts', 'src/app/shared/order-status-badge.component.ts', 'src/environments/environment.ts'];
        $facts = new NodeBridge()->analyze($root, $stack, $files, NodeBridge::QUERIES);
        $resolver = new TypeScriptDependencyResolver($facts, NodeLock::in(self::ANGULAR), 3, RoleGuesser::guess(...));

        $list = $resolver->resolve(new FileRef('src/app/orders/order-list.component.ts', FileRole::Component));

        self::assertSame(['src/app/orders/order-list.component.ts', 'src/app/orders/order.service.ts', 'src/app/shared/order-status-badge.component.css', 'src/app/shared/order-status-badge.component.ts', 'src/environments/environment.ts'], array_map(static fn (FileRef $file): string => $file->path, $list->files));
        self::assertSame(['@angular/common', '@angular/core', '@angular/router'], array_map(static fn (mixed $package): string => $package->name, $list->packages));
        self::assertMatchesRegularExpression('/^22\./', $list->packages[1]->version, 'The version package-lock.json holds.');
        self::assertContains('src/app/home/home.component.html', array_map(static fn (FileRef $file): string => $file->path, $resolver->resolve(new FileRef('src/app/home/home.component.ts', FileRole::Component))->files), 'templateUrl is a file of the component.');
    }

    public function testATestImportingAFileOfTheFirstHopIsATestOfTheWorkflow(): void
    {
        $facts = new NodeFacts('6.0.3', [
            'src/a.spec.ts' => ['imports' => [['specifier' => './a', 'kind' => 'static', 'resolved' => 'src/a.ts', 'package' => null, 'names' => [['imported' => 'A', 'local' => 'A']]]]],
            'src/b.test.js' => ['imports' => [['specifier' => './b', 'kind' => 'static', 'resolved' => 'src/b.ts', 'package' => null]]],
        ]);
        $locator = new TypeScriptTestLocator($facts, ['src/b.test.js', 'src/a.spec.ts']);

        self::assertSame(['src/a.spec.ts'], array_map(static fn (FileRef $file): string => $file->path, $locator->testsOf([new FileRef('src/a.ts')])));
        self::assertTrue(TypeScriptTestLocator::isTest('e2e/orders.spec.mts'));
        self::assertFalse(TypeScriptTestLocator::isTest('src/specification.ts'));
    }

    public function testAProjectWithoutTypeScriptIsRefusedWithWhatToInstall(): void
    {
        $project = $this->temporaryDirectory();
        file_put_contents($project.'/package.json', '{"name": "plain", "dependencies": {"express": "^4.0.0"}, "main": "app.js"}');
        file_put_contents($project.'/app.js', "module.exports = 1;\n");
        $root = new ProjectRoot($project);

        try {
            new NodeBridge()->analyze($root, new StackDetector()->detect($root, new Config())->stacks[0], ['app.js'], ['imports']);
            self::fail('Refused.');
        } catch (NodeBridgeFailed $refused) {
            self::assertStringStartsWith('TypeScript is not installed in this project', $refused->getMessage());
            self::assertStringNotContainsString('at Module.', $refused->getMessage(), 'No raw Node trace.');
        }
    }

    public function testANodeThatCannotRunOrAnswersSomethingElseIsAFailureThatQuotesIt(): void
    {
        $root = new ProjectRoot(self::ANGULAR);
        $stack = new StackDetector()->detect($root, new Config())->stacks[0];

        foreach (['false' => '`false ', 'echo' => 'did not answer with JSON', 'devtools-no-such-node' => 'Node could not be started'] as $binary => $expected) {
            try {
                new NodeBridge($binary)->analyze($root, $stack, [], ['imports']);
                self::fail($binary.' refused.');
            } catch (NodeBridgeFailed $failure) {
                self::assertStringContainsString($expected, $failure->getMessage(), $binary);
            }
        }
    }

    public function testTheLockFilesOfNpmAndYarnGiveTheVersions(): void
    {
        $project = $this->temporaryDirectory();
        file_put_contents($project.'/package-lock.json', json_encode(['packages' => ['' => [], 'node_modules/express' => ['version' => '4.21.2'], 'node_modules/@scope/pkg' => ['version' => '1.0.0'], 'node_modules/express/node_modules/debug' => ['version' => '2.6.9']]]));
        file_put_contents($project.'/yarn.lock', "\"@angular/core@^22.1.0\":\n  version \"22.1.3\"\n  resolved \"https://…\"\n\nrxjs@~7.8.0:\n  version \"7.8.2\"\n");
        $lock = NodeLock::in($project);

        self::assertSame(['4.21.2', '1.0.0', null, '22.1.3', '7.8.2', null], [$lock->version('express'), $lock->version('@scope/pkg'), $lock->version('debug'), $lock->version('@angular/core'), $lock->version('rxjs'), $lock->version('left-pad')]);
    }
}
