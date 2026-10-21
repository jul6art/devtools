<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Link;

use Jul6Art\DevTools\Command\WorkflowsImpactCommand;
use Jul6Art\DevTools\Command\WorkflowsInspectCommand;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Link\Call;
use Jul6Art\DevTools\Link\EndpointMatcher;
use Jul6Art\DevTools\Link\LinkCalculator;
use Jul6Art\DevTools\Link\LinkCheck;
use Jul6Art\DevTools\Link\LinkedImpactResolver;
use Jul6Art\DevTools\Link\LinkedProject;
use Jul6Art\DevTools\Link\Links;
use Jul6Art\DevTools\Link\LinkSide;
use Jul6Art\DevTools\Link\ProjectLinker;
use Jul6Art\DevTools\Link\RelatedLinks;
use Jul6Art\DevTools\Link\XmlLinksStore;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tests\Support\NodeDependencies;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(EndpointMatcher::class)]
#[CoversClass(LinkCalculator::class)]
#[CoversClass(LinkSide::class)]
#[CoversClass(ProjectLinker::class)]
#[CoversClass(RelatedLinks::class)]
#[CoversClass(LinkCheck::class)]
#[CoversClass(LinkedImpactResolver::class)]
#[CoversClass(XmlLinksStore::class)]
#[CoversClass(Links::class)]
#[CoversClass(Call::class)]
#[CoversClass(LinkedProject::class)]
#[CoversClass(WorkflowsInspectCommand::class)]
#[CoversClass(WorkflowsImpactCommand::class)]
final class ProjectLinkTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string HOST = 'https://items.example.com/api';

    /**
     * @var array<string, GitRepository>
     */
    private array $repositories = [];

    /**
     * @param list<array{id: string, methods: list<string>, path: string}> $routes
     * @param list<string>                                                 $expected
     */
    #[DataProvider('endpoints')]
    public function testAnEndpointIsAnsweredByTheRoutesThatMatchItBest(string $endpoint, array $routes, array $expected, ?string $baseUrl = null): void
    {
        self::assertSame($expected, EndpointMatcher::answering($endpoint, $routes, $baseUrl));
    }

    /**
     * @return iterable<string, array{0: string, 1: list<array{id: string, methods: list<string>, path: string}>, 2: list<string>, 3?: string}>
     */
    public static function endpoints(): iterable
    {
        $symfony = [
            ['id' => 'index', 'methods' => ['GET'], 'path' => '/api/items'],
            ['id' => 'show', 'methods' => ['GET'], 'path' => '/api/items/{id<\d+>}'],
            ['id' => 'export', 'methods' => ['GET'], 'path' => '/api/items/export'],
            ['id' => 'update', 'methods' => ['PUT', 'PATCH'], 'path' => '/api/items/{id}'],
            ['id' => 'any', 'methods' => [], 'path' => '/api/ping'],
        ];

        yield 'a parameter for a parameter' => ['GET /api/items/:param', $symfony, ['show']];
        yield 'the host, the query and the fragment do not count' => ['GET https://api.example.com/api/items?page=2#top', $symfony, ['index']];
        yield 'a literal wins over a parameter' => ['GET /api/items/export', $symfony, ['export']];
        yield 'an expression the analysis could not read is a parameter' => ['PATCH /api/items/*', $symfony, ['update']];
        yield 'another method is another route' => ['DELETE /api/items/:param', $symfony, []];
        yield 'a route without methods answers them all' => ['POST /api/ping', $symfony, ['any']];
        yield 'an unread base is matched by the end' => ['GET */items/:param', $symfony, ['show']];
        yield 'an unread base is replaced by the declared one' => ['GET */items/:param', $symfony, [], '/v2'];
        yield 'the declared base, with its host' => ['GET */api/items', $symfony, ['index'], 'https://api.example.com'];
        yield 'an Express parameter' => ['GET /orders/42', [['id' => 'express', 'methods' => ['GET'], 'path' => '/orders/:id']], ['express']];
        yield 'two routes alike both answer' => ['GET */items', [['id' => 'b', 'methods' => ['GET'], 'path' => '/b/items'], ['id' => 'a', 'methods' => ['GET'], 'path' => '/a/items']], ['a', 'b']];
        yield 'a longer client path matches no shorter route' => ['GET /api/items/1/history', $symfony, []];
    }

    /**
     * ADR-0038 on monorepo-link: the integrations of the mobile application matched against the routes of
     * its API, in the links.xml of each, on the pages of both and in their menus — and no absolute path.
     */
    public function testTheCallsOfTheMobileAreLinkedToTheRoutesOfItsApiInBothProjects(): void
    {
        [$api, $mobile] = $this->linkedProjects();
        $tester = $this->inspect([$api, $mobile]);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertStringContainsString('api ↔ mobile: 5 calls linked', $display);
        self::assertStringContainsString('mobile ↔ api: 5 calls linked', $display);

        $expected = [
            ['integration.auth-service', 'POST '.self::HOST.'/login_check', 'route.api.login.check'],
            ['integration.item-service', 'DELETE '.self::HOST.'/items/:param', 'route.api.item.delete'],
            ['integration.item-service', 'GET '.self::HOST.'/items', 'route.api.item.index'],
            ['integration.item-service', 'GET '.self::HOST.'/items/:param', 'route.api.item.show'],
            ['integration.item-service', 'PUT '.self::HOST.'/items/*', 'route.api.item.update'],
        ];

        foreach ([[$api, 'mobile', '../mobile', 'client'], [$mobile, 'api', '../api', 'server']] as [$project, $name, $path, $role]) {
            $links = new XmlLinksStore()->read($project.'/.devtools/graph/links.xml');

            self::assertSame([$name], $links->names());
            self::assertSame([$path, $role], [$links->projects[0]->path, $links->projects[0]->role->value]);
            self::assertSame($expected, array_map(static fn (Call $call): array => [$call->client, $call->endpoint, $call->server], $links->projects[0]->calls));
            self::assertStringContainsString('[Linked: '.$name.'](../../.devtools/graph/links.xml)', (string) file_get_contents($project.'/docs/workflows/workflows.md'));
        }

        $show = $api.'/docs/workflows/routes/api.item.show.md';
        $line = '- mobile · [`integration.item-service`](../../../../mobile/docs/workflows/integrations/item-service.md) — calls GET '.self::HOST.'/items/:param';
        self::assertStringContainsString("## Related workflows\n\n".$line."\n\n## History", (string) file_get_contents($show));
        self::assertFileExists(\dirname($show).'/../../../../mobile/docs/workflows/integrations/item-service.md', 'The link opens: the two projects sit side by side.');
        self::assertStringContainsString('- api · [`route.api.item.update`](../../../../api/docs/workflows/routes/api.item.update.md) — answers PUT '.self::HOST.'/items/*', (string) file_get_contents($mobile.'/docs/workflows/integrations/item-service.md'));
        self::assertStringContainsString("## Related workflows\n\n—\n", (string) file_get_contents($api.'/docs/workflows/routes/api.health.md'), 'Nobody calls it.');

        foreach ([$api, $mobile] as $project) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($project, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && 1 === preg_match('#/(\.devtools|docs)/#', $file->getPathname()) && !str_contains($file->getPathname(), '/.devtools/reports/')) {
                    self::assertStringNotContainsString($this->temporaryDirectory(), (string) file_get_contents($file->getPathname()), $file->getPathname().' holds an absolute path.');
                }
            }
        }

        // Inspected again on its own, nothing moves: every rendering of the page gives the same links.
        $before = self::tree($api);
        self::assertSame(1, $this->inspect([$api])->getStatusCode(), 'The static fallback of a Symfony project without vendor/.');
        self::assertSame($before, self::tree($api));
        self::assertSame(0, $this->check($api)->getStatusCode());
    }

    public function testARouteRemovedFromTheApiOrphansTheCallInBothMenusAndFailsTheCheck(): void
    {
        [$api, $mobile] = $this->linkedProjects();
        $this->inspect([$api, $mobile]);
        $controller = $api.'/src/Controller/Api/ItemController.php';
        file_put_contents($controller, (string) preg_replace('/\n    #\[Route\(\'\/\{id\}\', name: \'api_item_delete\'.*?\n    }\n/s', "\n", (string) file_get_contents($controller)));
        $this->repositories[$api]->commitAll('The items are never deleted.');

        self::assertSame(0, $this->check($mobile)->getStatusCode(), 'The API was not inspected again: its index still has the route.');

        $display = (string) preg_replace('/\s+/', ' ', $this->inspect([$api, $mobile], ['--prune' => true])->getDisplay());
        self::assertStringContainsString('api ↔ mobile: 4 calls linked, 1 orphan — a call no route answers', $display);
        self::assertStringNotContainsString('is gone', $display, 'What the old links said is not worth a warning in a run that links again.');

        self::assertStringContainsString('- ⚠ mobile · `integration.item-service` calls DELETE '.self::HOST.'/items/:param: no route answers', (string) file_get_contents($api.'/docs/workflows/workflows.md'));
        self::assertStringContainsString('- ⚠ [`integration.item-service`](integrations/item-service.md) — calls DELETE '.self::HOST.'/items/:param: no route of api answers', (string) file_get_contents($mobile.'/docs/workflows/workflows.md'));
        self::assertStringContainsString('- api · no route answers DELETE '.self::HOST.'/items/:param', (string) file_get_contents($mobile.'/docs/workflows/integrations/item-service.md'));

        foreach ([$api => 'integration.item-service of mobile calls DELETE', $mobile => 'integration.item-service calls DELETE'] as $project => $cause) {
            $check = $this->check($project);
            self::assertSame(1, $check->getStatusCode());
            self::assertStringContainsString('link   '.$cause, $check->getDisplay());
        }

        // The other way round: a route the links name, gone from what an inspection without --link finds.
        $problems = new LinkCheck()->problems(DevToolsDirectory::documented(new ProjectRoot($api)), new XmlLinksStore()->read($api.'/.devtools/graph/links.xml'), ['route.api.item.show', 'route.api.item.update', 'route.api.login.check']);
        self::assertContains('route.api.item.index is linked to mobile and is gone: workflows:inspect --link', $problems);
    }

    /**
     * A controller of the API changes: the mobile integration that calls its routes, and the screens that go
     * through that integration.
     */
    public function testTheImpactOfAnApiControllerReachesTheScreensOfTheMobile(): void
    {
        [$api] = $this->linkedProjects();
        $this->inspect([$api, \dirname($api).'/mobile']);
        $tester = new CommandTester(new Application()->find('workflows:impact'));

        self::assertSame(0, $tester->execute(['files' => ['src/Controller/Api/ItemController.php'], '--path' => $api, '--linked' => true, '--format' => 'json']));
        $impact = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($impact);
        self::assertSame([
            ['project' => 'mobile', 'id' => 'integration.item-service', 'relation' => 'calls', 'via' => 'route.api.item.delete', 'endpoint' => 'DELETE '.self::HOST.'/items/:param', 'page' => 'docs/workflows/integrations/item-service.md'],
            ['project' => 'mobile', 'id' => 'route.items.id', 'relation' => 'uses', 'via' => 'integration.item-service', 'endpoint' => null, 'page' => 'docs/workflows/routes/items.id.md'],
            ['project' => 'mobile', 'id' => 'route.root', 'relation' => 'uses', 'via' => 'integration.item-service', 'endpoint' => null, 'page' => 'docs/workflows/routes/root.md'],
        ], $impact['linked'] ?? null, 'The login screen calls another service: it is not reached.');

        self::assertSame(0, $tester->execute(['files' => ['src/Controller/Api/ItemController.php'], '--path' => $api, '--format' => 'json']));
        self::assertArrayNotHasKey('linked', (array) json_decode($tester->getDisplay(), true), 'Only when asked.');

        self::assertSame(0, $tester->execute(['files' => ['src/Controller/Api/ItemController.php'], '--path' => $api, '--linked' => true, '--format' => 'xml']));
        self::assertStringContainsString('<linked project="mobile" id="route.root" relation="uses" via="integration.item-service" page="docs/workflows/routes/root.md"/>', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['files' => ['src/Controller/Api/ItemController.php'], '--path' => $api, '--linked' => true]));
        self::assertStringContainsString('3 workflows of linked projects reached', $tester->getDisplay());
    }

    public function testANeighbourNeverInspectedKeepsItsLinksAndAProjectWithoutNeighboursIsTold(): void
    {
        [$api, $mobile] = $this->linkedProjects();
        $this->inspect([$api, $mobile]);
        new Filesystem()->remove($mobile.'/.devtools/index.xml');
        $before = (string) file_get_contents($api.'/.devtools/graph/links.xml');

        $report = new ProjectLinker()->link(new ProjectRoot($api));
        self::assertSame(['mobile (../mobile) was never inspected: inspect both projects together with --link. Its links are kept as they were.'], $report->warnings);
        self::assertSame($before, file_get_contents($api.'/.devtools/graph/links.xml'));

        file_put_contents($mobile.'/.devtools/config.xml', '<devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1"/>');
        self::assertSame(['mobile declares no linked project: add <links> to its .devtools/config.xml.'], new ProjectLinker()->link(new ProjectRoot($mobile))->warnings);
        self::assertSame(2, $this->inspect([$mobile, $api], ['--dry-run' => true, '--link' => true])->getStatusCode(), '--link writes: no dry run.');
    }

    public function testALinkedProjectIsDeclaredRelativeToThisOne(): void
    {
        $config = $this->temporaryDirectory().'/config.xml';
        file_put_contents($config, '<devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1"><links><project name="mobile" path="/srv/mobile" role="client"/></links></devtools>');

        $this->expectException(InvalidXml::class);
        new XmlConfigReader()->read($config);
    }

    /**
     * monorepo-link copied side by side, each a repository of its own with the config.xml that declares the
     * other.
     *
     * @return array{string, string}
     */
    private function linkedProjects(): array
    {
        NodeDependencies::install(__DIR__.'/../Fixtures/projects/monorepo-link/mobile');
        $root = $this->copyFixtureProject('monorepo-link', withDependencies: true);

        foreach (['api', 'mobile'] as $project) {
            new Filesystem()->copy(__DIR__.'/../Fixtures/projects/monorepo-link/'.$project.'/.devtools/config.xml', $root.'/'.$project.'/.devtools/config.xml');
            file_put_contents($root.'/'.$project.'/.gitignore', "/node_modules/\n/.devtools/reports/\n");
            $this->repositories[$root.'/'.$project] = GitRepository::initialise($root.'/'.$project);
        }

        return [$root.'/api', $root.'/mobile'];
    }

    /**
     * @param list<string>         $paths
     * @param array<string, mixed> $options
     */
    private function inspect(array $paths, array $options = []): CommandTester
    {
        $tester = new CommandTester(new Application()->find('workflows:inspect'));
        $tester->execute(['path' => $paths, '--no-ai' => true, ...\array_key_exists('--dry-run', $options) ? [] : ['--link' => true], ...$options]);

        return $tester;
    }

    private function check(string $project): CommandTester
    {
        $tester = new CommandTester(new Application()->find('workflows:check'));
        $tester->execute(['path' => $project]);

        return $tester;
    }

    /**
     * @return array<string, string>
     */
    private static function tree(string $project): array
    {
        $tree = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($project, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && !str_contains($file->getPathname(), '/node_modules/') && !str_contains($file->getPathname(), '/.git/') && !str_contains($file->getPathname(), '/.devtools/reports/') && !str_ends_with($file->getPathname(), '/.devtools/search.idx')) {
                $tree[substr($file->getPathname(), \strlen($project))] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($tree);

        return $tree;
    }
}
