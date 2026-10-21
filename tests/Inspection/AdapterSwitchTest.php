<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\Claude\ClaudeDrivenAdapter;
use Jul6Art\DevTools\Inspection\Adapter\Laravel\LaravelAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\AdapterSwitch;
use Jul6Art\DevTools\Inspection\Freshness\DecisionKind;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackDetector;
use Jul6Art\DevTools\Stack\StackDocument;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Stack\XmlStackStore;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Laravel\RecordedArtisanRunner;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\NodeDependencies;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\Initializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0030: a project the Claude path documented, that this version reads natively, switches only when asked
 * — and is told beforehand which identifiers stay, go and come.
 */
#[CoversClass(AdapterSwitch::class)]
final class AdapterSwitchTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string DISCOVERY = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <inspection xmlns="https://github.com/jul6art/devtools/schema/inspection-model/1" schema-version="1" stack="laravel-13">
          <workflows>
            <workflow id="route.orders.index" type="routes" confidence="medium" source="claude">
              <title>GET /orders</title>
              <main kind="route" name="orders.index">
                <declared-in path="app/Http/Controllers/OrderController.php" role="controller"/>
                <attribute name="path" value="/orders"/>
              </main>
              <files>
                <file path="app/Http/Controllers/OrderController.php" role="controller"/>
              </files>
            </workflow>
            <workflow id="route.orders-list" type="routes" confidence="medium" source="claude">
              <title>GET /orders</title>
              <main kind="route" name="GET /orders">
                <declared-in path="routes/web.php" role="config"/>
              </main>
              <files>
                <file path="routes/web.php" role="config"/>
              </files>
            </workflow>
          </workflows>
        </inspection>

        XML;

    public function testTheSwitchIsListedThenMadeOnlyWhenAsked(): void
    {
        $project = $this->copyFixtureProject('laravel-minimal');
        $directory = new DevToolsDirectory(new ProjectRoot($project));
        new Initializer()->initialize($directory);
        $detected = new StackDetector()->detect($directory->root, new Config())->stacks[0];
        new XmlStackStore()->write($directory->stackFile(), new StackDocument('acme/laravel-minimal', [self::onTheClaudePath($detected)]));
        mkdir($directory->path('discovery'));
        file_put_contents($directory->path('discovery/project.xml'), self::DISCOVERY);
        $pipeline = new InspectionPipeline(new AdapterResolver([new LaravelAdapter(new RecordedArtisanRunner()), new ClaudeDrivenAdapter()]));

        $held = $pipeline->run(new InspectionOptions($project, noAi: true));

        self::assertSame([], $held->errors);
        self::assertStringContainsString('laravel (.) is documented through Claude, and this version reads it natively (adapter laravel): 1 identifiers stay, 1 go (route.orders-list), 11 come (async.orders-import, ', implode("\n", $held->warnings));
        self::assertSame(['route.orders-list', 'route.orders.index'], self::ids($held->decisions), 'Held: the Claude path documents the project, as before.');
        self::assertSame('claude', new XmlStackStore()->read($directory->stackFile())->stacks[0]->adapter, 'Held on every run until the switch is asked.');

        $switched = $pipeline->run(new InspectionOptions($project, noAi: true, switchAdapter: true));

        self::assertStringContainsString('laravel (.) switched from the Claude path to the native laravel adapter: 1 identifiers stay, 1 go (route.orders-list), 11 come', implode("\n", $switched->warnings));
        self::assertFileDoesNotExist($directory->path('discovery/project.xml'));
        self::assertSame('laravel', new XmlStackStore()->read($directory->stackFile())->stacks[0]->adapter);
        self::assertSame(DecisionKind::Orphan, $switched->decision('route.orders-list')?->kind, 'The page of an identifier that went is orphaned, not deleted.');
        self::assertCount(13, $switched->decisions);

        $after = $pipeline->run(new InspectionOptions($project, noAi: true));

        self::assertSame([], array_values(array_filter($after->warnings, static fn (string $warning): bool => str_contains($warning, 'Claude'))), 'Once switched, nothing is said any more.');
    }

    /**
     * ADR-0032: the same mechanism for Angular, whose identifiers the Claude path derived otherwise.
     */
    public function testAnAngularProjectClaudeDocumentedListsItsChangedIdentifiers(): void
    {
        NodeDependencies::install(__DIR__.'/../Fixtures/projects/angular-minimal');
        $project = $this->copyFixtureProject('angular-minimal', withDependencies: true);
        $directory = new DevToolsDirectory(new ProjectRoot($project));
        new Initializer()->initialize($directory);
        $detected = new StackDetector()->detect($directory->root, new Config())->stacks[0];
        new XmlStackStore()->write($directory->stackFile(), new StackDocument('acme-back-office', [self::onTheClaudePath($detected)]));
        mkdir($directory->path('discovery'));
        copy(__DIR__.'/../Fixtures/drafts/angular-minimal/discovery.project.draft.xml', $directory->path('discovery/project.xml'));

        $held = new InspectionPipeline()->run(new InspectionOptions($project, noAi: true));

        self::assertStringContainsString('angular (.) is documented through Claude, and this version reads it natively (adapter angular): 2 identifiers stay, 1 go (route.orders-id), 5 come (route.admin.users, route.orders.id, route.reports, route.root, ui.order-status-badge). Inspect with --switch-adapter to switch.', implode("\n", $held->warnings));
    }

    public function testANativeStackOrOneWithoutDiscoveryIsNoSwitch(): void
    {
        $project = $this->copyFixtureProject('laravel-minimal');
        $directory = new DevToolsDirectory(new ProjectRoot($project));
        $detected = new StackDetector()->detect($directory->root, new Config());
        $switch = new AdapterSwitch(new AdapterResolver());

        [$stacks, $notes] = $switch->apply($directory, $detected, $detected, new Config(), false, false);
        self::assertSame([[], 'laravel'], [$notes, $stacks->stacks[0]->adapter], 'Native before, native now.');

        [$stacks, $notes] = $switch->apply($directory, $detected, new StackDocument('acme/laravel-minimal', [self::onTheClaudePath($detected->stacks[0])]), new Config(), false, false);
        self::assertSame([[], 'laravel'], [$notes, $stacks->stacks[0]->adapter], 'No discovery: nothing Claude documented to keep.');
    }

    private static function onTheClaudePath(StackProfile $stack): StackProfile
    {
        return new StackProfile($stack->root, $stack->language, $stack->framework, $stack->version, $stack->packageManager, $stack->sourceDirs, $stack->testDirs, $stack->excludedDirs, 'claude', $stack->knowledgeKey);
    }

    /**
     * @param array<string, mixed> $decisions
     *
     * @return list<string>
     */
    private static function ids(array $decisions): array
    {
        $ids = array_map(strval(...), array_keys($decisions));
        sort($ids, \SORT_STRING);

        return $ids;
    }
}
