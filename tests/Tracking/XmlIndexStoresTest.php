<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Tracking;

use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Tracking\FileLink;
use Jul6Art\DevTools\Tracking\FileRelation;
use Jul6Art\DevTools\Tracking\FilesToWorkflows;
use Jul6Art\DevTools\Tracking\Index;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\VcsState;
use Jul6Art\DevTools\Tracking\XmlFilesToWorkflowsStore;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Xml\InvalidXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(XmlIndexStore::class)]
#[CoversClass(Index::class)]
#[CoversClass(IndexEntry::class)]
#[CoversClass(XmlFilesToWorkflowsStore::class)]
#[CoversClass(FilesToWorkflows::class)]
#[CoversClass(FileLink::class)]
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

    private function filesToWorkflows(): FilesToWorkflows
    {
        return FilesToWorkflows::fromTracking([TrackingFixtures::orderNew(), TrackingFixtures::importCatalog()]);
    }
}
