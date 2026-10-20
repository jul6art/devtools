<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Tracking;

use Jul6Art\DevTools\Inspection\Model\WorkflowGroup;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Tracking\FileLink;
use Jul6Art\DevTools\Tracking\FileRelation;
use Jul6Art\DevTools\Tracking\FilesToWorkflows;
use Jul6Art\DevTools\Tracking\Index;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\IndexGroup;
use Jul6Art\DevTools\Tracking\TrackingDocument;
use Jul6Art\DevTools\Tracking\VcsState;
use Jul6Art\DevTools\Tracking\VcsXml;
use Jul6Art\DevTools\Tracking\XmlFilesToWorkflowsStore;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Xml\InvalidXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(XmlIndexStore::class)]
#[CoversClass(Index::class)]
#[CoversClass(IndexEntry::class)]
#[CoversClass(XmlFilesToWorkflowsStore::class)]
#[CoversClass(FilesToWorkflows::class)]
#[CoversClass(FileLink::class)]
#[CoversClass(IndexGroup::class)]
#[CoversClass(VcsXml::class)]
final class XmlIndexStoresTest extends TestCase
{
    use AssertsSnapshots;
    use UsesTemporaryDirectory;

    public function testTheIndexSurvivesARoundTripToTheByte(): void
    {
        $store = new XmlIndexStore(new WorkflowTypeRegistry());
        $path = $this->temporaryDirectory().'/index.xml';
        $index = $this->index();

        $store->write($path, $index);

        self::assertEquals($index, $store->read($path));
        self::assertFalse($store->write($path, $store->read($path)));
        self::assertMatchesSnapshot((string) file_get_contents($path), __DIR__.'/Fixtures/index.xml');
    }

    /**
     * ADR-0045: a group is listed once, whatever the number of its workflows, and gives back exactly the
     * entries it holds — the menu counts its routes from them.
     */
    public function testAGroupedIndexSurvivesARoundTripAndGivesBackTheEntriesOfEachGroup(): void
    {
        $store = new XmlIndexStore(new WorkflowTypeRegistry());
        $path = $this->temporaryDirectory().'/index.xml';
        $new = self::inGroup(TrackingFixtures::orderNew(), 'order', 'Orders');
        $show = self::inGroup(TrackingFixtures::orderNew(), 'order', 'Orders', 'route.order.show');
        $index = Index::fromTracking(new \DateTimeImmutable('2026-09-16T15:00:00+02:00'), VcsState::none(), [$new, $show, TrackingFixtures::importCatalog()]);

        self::assertEquals([new IndexGroup(WorkflowType::routes(), 'order', 'Orders')], $index->groups, 'One group for its two workflows.');
        self::assertSame(['route.order.new', 'route.order.show'], array_map(static fn (IndexEntry $entry): string => $entry->id->value, $index->of($index->groups[0])));
        self::assertSame([], $index->of(new IndexGroup(WorkflowType::commands(), 'order', 'Orders')), 'A group is a directory of ONE type.');
        self::assertSame([], $index->of(new IndexGroup(WorkflowType::routes(), 'invoice', 'Invoices')));
        self::assertSame('routes/order/README.md', $index->groups[0]->page());

        $store->write($path, $index);

        self::assertStringContainsString('<group type="routes" directory="order" title="Orders" page="routes/order/README.md"/>', (string) file_get_contents($path));
        self::assertEquals($index, $store->read($path));
        self::assertFalse($store->write($path, $store->read($path)));
    }

    /**
     * A group is a directory of ONE type: the same directory under two types is two groups, and two types
     * whose names run into each other (`routes` + `order`, `route` + `sorder`) are kept apart too.
     */
    public function testGroupsAreKeptApartByTypeAndDirectory(): void
    {
        $route = WorkflowType::custom('route', 'page');
        $groups = [
            new IndexGroup($route, 'sorder', 'Sorders'),
            new IndexGroup(WorkflowType::routes(), 'order', 'Orders'),
            new IndexGroup(WorkflowType::commands(), 'order', 'Order commands'),
            new IndexGroup(WorkflowType::routes(), 'invoice', 'Invoices'),
        ];

        $index = new Index(new \DateTimeImmutable('2026-09-16T15:00:00+02:00'), VcsState::none(), [], groups: $groups);

        self::assertSame(
            ['commands/order', 'route/sorder', 'routes/invoice', 'routes/order'],
            array_map(static fn (IndexGroup $group): string => $group->type->name.'/'.$group->directory, $index->groups),
        );

        $two = Index::fromTracking(new \DateTimeImmutable('2026-09-16T15:00:00+02:00'), VcsState::none(), [
            self::inGroup(TrackingFixtures::orderNew(), 'order', 'Orders'),
            self::inGroup(TrackingFixtures::orderNew(), 'invoice', 'Invoices', 'route.invoice.new'),
        ]);

        self::assertCount(2, $two->groups, 'Two directories of one type are two groups.');
    }

    /**
     * The schema says `xs:dateTime`, which accepts a date without its offset; DevTools writes and reads
     * ISO 8601 with one — a date read without it would move with the machine's time zone.
     */
    public function testADateWithoutItsOffsetIsRefused(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <index xmlns="https://github.com/jul6art/devtools/schema/index/1" schema-version="2" scanned-at="2026-09-16T12:00:00" docs="docs/workflows">
              <source vcs="none"/>
            </index>
            XML;

        $this->expectException(InvalidXml::class);
        $this->expectExceptionMessage('"2026-09-16T12:00:00" is not an ISO 8601 date.');

        new XmlIndexStore(new WorkflowTypeRegistry())->unserialize($xml, 'index.xml');
    }

    #[DataProvider('versionControlStates')]
    public function testEveryVersionControlStateSurvivesARoundTrip(VcsState $vcs, string $written): void
    {
        $store = new XmlIndexStore(new WorkflowTypeRegistry());
        $index = Index::fromTracking(new \DateTimeImmutable('2026-09-16T15:00:00+02:00'), $vcs, [TrackingFixtures::importCatalog()]);
        $xml = $store->serialize($index);

        self::assertStringContainsString($written, $xml);
        self::assertEquals($vcs, $store->unserialize($xml, 'index.xml')->vcs);
    }

    /**
     * @return iterable<string, array{VcsState, string}>
     */
    public static function versionControlStates(): iterable
    {
        yield 'outside git' => [VcsState::none(), '<source vcs="none"/>'];
        yield 'a detached head, clean' => [new VcsState('a1b2c3d'), '<source vcs="git" commit="a1b2c3d" dirty="false"/>'];
        yield 'a branch, dirty' => [new VcsState('a1b2c3d', 'main', true), '<source vcs="git" commit="a1b2c3d" branch="main" dirty="true"/>'];
    }

    public function testTheIndexListsEntriesByIdentifierWithTheirPage(): void
    {
        self::assertSame(['command.app.import-catalog', 'route.order.new'], array_map(static fn (IndexEntry $entry): string => (string) $entry->id, $this->index()->entries));
        self::assertSame('routes/order.new.md', $this->index()->entries[1]->page(), 'Relative to the documentation directory the index names.');
    }

    public function testAnIndexWrittenBeforeTheDocumentationMovedIsStillRead(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <index xmlns="https://github.com/jul6art/devtools/schema/index/1" schema-version="1" scanned-at="2026-09-16T12:00:00+00:00">
              <source vcs="none"/>
            </index>
            XML;

        self::assertSame('docs/workflows', new XmlIndexStore(new WorkflowTypeRegistry())->unserialize($xml, 'index.xml')->docs);
    }

    /**
     * ADR-0045: an index written before groups existed is read as a project without any — never refused.
     */
    public function testAnIndexWrittenBeforeGroupsExistedIsStillRead(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <index xmlns="https://github.com/jul6art/devtools/schema/index/1" schema-version="2" scanned-at="2026-09-16T12:00:00+00:00" docs="docs/workflows">
              <source vcs="none"/>
              <workflow id="route.order.new" type="routes" title="Order creation" status="fresh" confidence="high" mode="ai" updated="2026-09-16T12:00:00+00:00" page="routes/order.new.md"/>
            </index>
            XML;

        $index = new XmlIndexStore(new WorkflowTypeRegistry())->unserialize($xml, 'index.xml');

        self::assertSame([], $index->groups);
        self::assertNull($index->entries[0]->group);
        self::assertSame('routes/order.new.md', $index->entries[0]->page(), 'Without a group the page stays where it was.');
    }

    public function testFilesToWorkflowsDistinguishesFilesFromTests(): void
    {
        $graph = $this->filesToWorkflows();

        self::assertEquals(
            [new FileLink(TrackingFixtures::importCatalog()->id, FileRelation::File), new FileLink(TrackingFixtures::orderNew()->id, FileRelation::File)],
            $graph->linksOf('src/Controller/OrderController.php'),
        );
        self::assertEquals(
            [new FileLink(TrackingFixtures::importCatalog()->id, FileRelation::Test), new FileLink(TrackingFixtures::orderNew()->id, FileRelation::Test)],
            $graph->linksOf('tests/Controller/OrderControllerTest.php'),
        );
        self::assertSame([], $graph->linksOf('src/Unknown.php'));
    }

    public function testFilesToWorkflowsSurvivesARoundTripToTheByte(): void
    {
        $store = new XmlFilesToWorkflowsStore();
        $path = $this->temporaryDirectory().'/graph/files-to-workflows.xml';

        $store->write($path, $this->filesToWorkflows());

        self::assertEquals($this->filesToWorkflows(), $store->read($path));
        self::assertFalse($store->write($path, $store->read($path)));
        self::assertMatchesSnapshot((string) file_get_contents($path), __DIR__.'/Fixtures/files-to-workflows.xml');
    }

    public function testTheSchemasRejectAnUnknownRelationAndAnUnknownStatus(): void
    {
        $graphXml = str_replace('relation="test"', 'relation="friend"', new XmlFilesToWorkflowsStore()->serialize($this->filesToWorkflows()));

        try {
            new XmlFilesToWorkflowsStore()->unserialize($graphXml, 'files-to-workflows.xml');
            self::fail('An unknown relation must be refused.');
        } catch (InvalidXml) {
        }

        $indexXml = str_replace('status="manual"', 'status="lost"', new XmlIndexStore(new WorkflowTypeRegistry())->serialize($this->index()));

        $this->expectException(InvalidXml::class);

        new XmlIndexStore(new WorkflowTypeRegistry())->unserialize($indexXml, 'index.xml');
    }

    private function index(): Index
    {
        return Index::fromTracking(
            new \DateTimeImmutable('2026-09-16T15:00:00+02:00'),
            new VcsState('a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', 'main', true),
            [TrackingFixtures::orderNew(), TrackingFixtures::importCatalog()],
        );
    }

    private static function inGroup(TrackingDocument $document, string $directory, string $title, ?string $id = null): TrackingDocument
    {
        return new TrackingDocument(
            null === $id ? $document->id : new WorkflowId($id),
            $document->type,
            $document->title,
            $document->generated,
            $document->vcs,
            $document->main,
            history: $document->history,
            group: new WorkflowGroup($directory, $title, $document->main->declaredIn),
        );
    }

    private function filesToWorkflows(): FilesToWorkflows
    {
        return FilesToWorkflows::fromTracking([TrackingFixtures::orderNew(), TrackingFixtures::importCatalog()]);
    }
}
