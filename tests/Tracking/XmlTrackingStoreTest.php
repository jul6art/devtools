<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Tracking;

use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Tracking\Generation;
use Jul6Art\DevTools\Tracking\GenerationMode;
use Jul6Art\DevTools\Tracking\Revision;
use Jul6Art\DevTools\Tracking\TrackedFile;
use Jul6Art\DevTools\Tracking\TrackingDocument;
use Jul6Art\DevTools\Tracking\TrackingStatus;
use Jul6Art\DevTools\Tracking\VcsState;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use Jul6Art\DevTools\Xml\InvalidXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(XmlTrackingStore::class)]
#[CoversClass(TrackingDocument::class)]
#[CoversClass(Generation::class)]
#[CoversClass(VcsState::class)]
#[CoversClass(TrackedFile::class)]
#[CoversClass(Revision::class)]
final class XmlTrackingStoreTest extends TestCase
{
    use AssertsSnapshots;
    use UsesTemporaryDirectory;

    public function testWritingThenReadingThenWritingProducesTheSameBytes(): void
    {
        $store = new XmlTrackingStore(new WorkflowTypeRegistry());
        $path = $this->temporaryDirectory().'/workflows/routes/order.new.xml';

        foreach ([TrackingFixtures::orderNew(), TrackingFixtures::importCatalog()] as $document) {
            self::assertTrue($store->write($path, $document));
            $first = (string) file_get_contents($path);

            self::assertEquals($document, $store->read($path));
            self::assertFalse($store->write($path, $store->read($path)), 'Rewriting an unchanged document changes nothing.');
            self::assertStringEqualsFile($path, $first);
        }
    }

    public function testTheFormatMatchesTheSnapshot(): void
    {
        self::assertMatchesSnapshot(new XmlTrackingStore(new WorkflowTypeRegistry())->serialize(TrackingFixtures::orderNew()), __DIR__.'/Fixtures/order.new.xml');
    }

    #[DataProvider('invalidDocuments')]
    public function testTheSchemaRejectsAnInvalidDocument(string $search, string $replace): void
    {
        $original = new XmlTrackingStore(new WorkflowTypeRegistry())->serialize(TrackingFixtures::orderNew());
        $xml = (string) preg_replace($search, $replace, $original);
        self::assertNotSame($original, $xml, 'The mutation was applied.');

        $this->expectException(InvalidXml::class);
        $this->expectExceptionMessageMatches('/line \d+/');

        new XmlTrackingStore(new WorkflowTypeRegistry())->unserialize($xml, 'order.new.xml');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'missing status' => ['#\\s*<status>fresh</status>#', ''];
        yield 'unknown status' => ['#<status>fresh</status>#', '<status>outdated</status>'];
        yield 'absolute path' => ['#path="src/Controller/OrderController.php" role="controller" sha256#', 'path="/var/www/src/Controller/OrderController.php" role="controller" sha256'];
        yield 'short hash' => ['#sha256="a{64}"#', 'sha256="abc"'];
        yield 'empty history' => ['#<history>.*</history>#s', '<history/>'];
    }

    public function testAModelInvariantIsEnforcedOnRead(): void
    {
        $xml = str_replace('path="templates/order/new.html.twig"', 'path="templates/../../../etc/passwd"', new XmlTrackingStore(new WorkflowTypeRegistry())->serialize(TrackingFixtures::orderNew()));

        $this->expectException(InvalidModel::class);

        new XmlTrackingStore(new WorkflowTypeRegistry())->unserialize($xml, 'order.new.xml');
    }

    public function testADocumentFromANewerDevToolsAsksForAnUpdate(): void
    {
        $xml = str_replace('schema-version="1"', 'schema-version="2"', new XmlTrackingStore(new WorkflowTypeRegistry())->serialize(TrackingFixtures::orderNew()));

        $this->expectException(InvalidXml::class);
        $this->expectExceptionMessage('Update DevTools');

        new XmlTrackingStore(new WorkflowTypeRegistry())->unserialize($xml, 'order.new.xml');
    }

    public function testAnExternalEntityIsNeverResolved(): void
    {
        $secret = $this->temporaryDirectory().'/secret.txt';
        file_put_contents($secret, 'TOP-SECRET-CONTENT');
        $xml = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<!DOCTYPE workflow [<!ENTITY s SYSTEM "file://'.$secret.'">]>', new XmlTrackingStore(new WorkflowTypeRegistry())->serialize(TrackingFixtures::orderNew()));
        $xml = str_replace('initial', '&s;', $xml);

        try {
            new XmlTrackingStore(new WorkflowTypeRegistry())->unserialize($xml, 'order.new.xml');
            self::fail('A document declaring an entity must be refused.');
        } catch (InvalidXml $refused) {
            self::assertStringNotContainsString('TOP-SECRET-CONTENT', $refused->getMessage());
        }
    }

    public function testModelAndPromptOnlyExistInAiMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Generation(new \DateTimeImmutable(), 'devtools', GenerationMode::NoAi, 'claude-opus-5');
    }

    public function testAHashMustBeASha256(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TrackedFile(TrackingFixtures::orderNew()->files[0]->file, 'ABC');
    }

    public function testTheLastRevisionIsTheLatestOne(): void
    {
        self::assertSame('files changed: src/Service/OrderPricing.php', TrackingFixtures::orderNew()->lastRevision()->reason);
        self::assertTrue(TrackingFixtures::orderNew(TrackingStatus::Stale)->status->needsAttention());
        self::assertFalse(TrackingFixtures::orderNew()->status->needsAttention());
    }
}
