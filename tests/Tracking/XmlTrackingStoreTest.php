<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Tracking;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\WorkflowGroup;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
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
use Jul6Art\DevTools\Tracking\VcsXml;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use Jul6Art\DevTools\Xml\DomBuilder;
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
#[CoversClass(TrackingStatus::class)]
#[CoversClass(VcsXml::class)]
#[CoversClass(DomBuilder::class)]
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

    /**
     * The group (ADR-0045), the mechanisms and the decisions (ADR-0043) are optional: the fixtures above
     * carry none, so without this document none of the three would ever be written, nor read back.
     */
    public function testAGroupMechanismsAndDecisionsSurviveARoundTripToTheByte(): void
    {
        $store = new XmlTrackingStore(new WorkflowTypeRegistry());
        $path = $this->temporaryDirectory().'/workflows/routes/order.new.xml';
        $document = self::groupedWithMechanismsAndDecisions();

        self::assertTrue($store->write($path, $document));
        $xml = (string) file_get_contents($path);

        self::assertEquals($document, $store->read($path));
        self::assertFalse($store->write($path, $store->read($path)));
        self::assertStringContainsString('<group directory="order" title="Orders"/>', $xml);
        self::assertStringContainsString('<mechanism kind="listener" name="App\EventListener\LocaleListener" event="kernel.request" priority="20">', $xml);
        self::assertStringContainsString('<mechanism kind="middleware" name="App\Middleware\Audit" event="messenger.middleware">', $xml, 'No priority, no attribute.');
        self::assertStringContainsString('<decision target="App\Entity\Order::currency" value="EUR" condition="$country === \'FR\'" file="src/Service/OrderPricing.php" line="12" confidence="high"/>', $xml);
        self::assertStringContainsString('<decision target="App\Entity\Order::currency" value="USD" file="src/Service/OrderPricing.php" line="14" confidence="medium"/>', $xml, 'No condition, no attribute.');
        self::assertStringContainsString('<decision target="Request::locale" value="fr" file="src/EventListener/LocaleListener.php" line="9" confidence="high"/>', $xml, 'A mechanism carries its own decisions.');
    }

    /**
     * ADR-0036: a renamed workflow keeps everything it knew, its history one revision longer.
     */
    public function testARevisedDocumentKeepsAllAndAddsOneRevision(): void
    {
        $document = self::groupedWithMechanismsAndDecisions();
        $revision = new Revision(new \DateTimeImmutable('2026-10-21T10:00:00+00:00'), 'abc1234', 'renamed from route.order.create');
        $revised = $document->revised($revision);

        self::assertSame([...$document->history, $revision], $revised->history);
        self::assertSame($revision, $revised->lastRevision());

        $everythingElse = static fn (TrackingDocument $tracked): array => array_diff_key(get_object_vars($tracked), ['history' => true]);
        self::assertEquals($everythingElse($document), $everythingElse($revised));
        self::assertNotNull($document->group, 'A document that carries every optional part, so that none is dropped unseen.');
    }

    /**
     * The schema cannot count attribute values: "exactly one main entry point" is checked on read.
     */
    public function testADocumentDeclaringTwoMainEntryPointsIsRefused(): void
    {
        $xml = new XmlTrackingStore(new WorkflowTypeRegistry())->serialize(TrackingFixtures::orderNew());
        $mutated = (string) preg_replace('/role="satellite"/', 'role="main"', $xml, 1);
        self::assertNotSame($xml, $mutated, 'The mutation was applied.');

        $this->expectException(InvalidXml::class);
        $this->expectExceptionMessageIsOrContains('exactly one main entry point');

        new XmlTrackingStore(new WorkflowTypeRegistry())->unserialize($mutated, 'order.new.xml');
    }

    public function testADocumentWithoutAMainEntryPointIsRefused(): void
    {
        $xml = new XmlTrackingStore(new WorkflowTypeRegistry())->serialize(TrackingFixtures::orderNew());
        $mutated = str_replace('role="main"', 'role="satellite"', $xml);
        self::assertNotSame($xml, $mutated, 'The mutation was applied.');

        $this->expectException(InvalidXml::class);
        $this->expectExceptionMessageIsOrContains('this document declares none');

        new XmlTrackingStore(new WorkflowTypeRegistry())->unserialize($mutated, 'order.new.xml');
    }

    /**
     * The order of the satellites is part of the bytes, and the bytes are what freshness compares: kind,
     * then name, then file — each kept apart, so that no two satellites can ever be taken for one.
     */
    public function testSatellitesAreKeptAllAndSortedByKindThenNameThenFile(): void
    {
        $at = static fn (string $kind, string $name, string $file): EntryPoint => new EntryPoint($kind, $name, new FileRef($file));
        $satellites = [
            $at('routex', 'a', 'src/A.php'),
            $at('route', 'z', 'src/B.php'),
            $at('route', 'x-y', 'src/A.php'),
            $at('route', 'x', 'src/C.php'),
            $at('command', 'x', 'src/B.php'),
            $at('route', 'x', 'src/B.php'),
        ];
        $fixture = TrackingFixtures::orderNew();

        $document = new TrackingDocument($fixture->id, $fixture->type, $fixture->title, $fixture->generated, $fixture->vcs, $fixture->main, $satellites, history: $fixture->history);

        self::assertSame(
            ['command x src/B.php', 'route x src/B.php', 'route x src/C.php', 'route x-y src/A.php', 'route z src/B.php', 'routex a src/A.php'],
            array_map(static fn (EntryPoint $satellite): string => $satellite->kind.' '.$satellite->name.' '.$satellite->declaredIn->path, $document->satellites),
        );
    }

    public function testAMissingFileIsRefusedByName(): void
    {
        $this->expectException(InvalidXml::class);
        $this->expectExceptionMessageIsOrContains('the file does not exist or cannot be read');

        new XmlTrackingStore(new WorkflowTypeRegistry())->read($this->temporaryDirectory().'/missing.xml');
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
        $this->expectExceptionMessageIsOrContains('Update DevTools');

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

    public function testTheGeneratingToolMustBeNamed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The generating tool must be named.');

        new Generation(new \DateTimeImmutable(), '  ', GenerationMode::NoAi);
    }

    /**
     * @param callable(): mixed        $build
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('invalidValues')]
    public function testAValueThatCannotBeTrueIsRefusedWhenBuilt(callable $build, string $class, string $message): void
    {
        $this->expectException($class);
        $this->expectExceptionMessageIsOrContains($message);

        $build();
    }

    /**
     * @return iterable<string, array{callable(): mixed, class-string<\Throwable>, string}>
     */
    public static function invalidValues(): iterable
    {
        $at = new \DateTimeImmutable('2026-09-16T15:00:00+02:00');

        yield 'a commit that is not a hash' => [static fn (): VcsState => new VcsState('HEAD'), \InvalidArgumentException::class, '"HEAD" is not a commit hash.'];
        yield 'a commit after something else' => [static fn (): VcsState => new VcsState('v1-a1b2c3d'), \InvalidArgumentException::class, '"v1-a1b2c3d" is not a commit hash.'];
        yield 'a commit before something else' => [static fn (): VcsState => new VcsState('a1b2c3d-dirty'), \InvalidArgumentException::class, '"a1b2c3d-dirty" is not a commit hash.'];
        yield 'a revision on a commit after something else' => [static fn (): Revision => new Revision($at, 'v1-a1b2c3d', 'initial'), \InvalidArgumentException::class, 'is not a commit hash.'];
        yield 'a revision on a commit before something else' => [static fn (): Revision => new Revision($at, 'a1b2c3d-dirty', 'initial'), \InvalidArgumentException::class, 'is not a commit hash.'];
        yield 'a hash after something else' => [static fn (): TrackedFile => new TrackedFile(new FileRef('src/A.php'), 'sha256:'.TrackingFixtures::HASH_A), \InvalidArgumentException::class, 'is not a lowercase SHA-256.'];
        yield 'a hash before something else' => [static fn (): TrackedFile => new TrackedFile(new FileRef('src/A.php'), TrackingFixtures::HASH_A.'0'), \InvalidArgumentException::class, 'is not a lowercase SHA-256.'];
        yield 'a branch without a commit' => [static fn (): VcsState => new VcsState(null, 'main'), \InvalidArgumentException::class, 'Without a commit there is no version control'];
        yield 'a dirty tree without a commit' => [static fn (): VcsState => new VcsState(null, null, true), \InvalidArgumentException::class, 'Without a commit there is no version control'];
        yield 'a revision on a commit that is not a hash' => [static fn (): Revision => new Revision($at, 'main', 'initial'), \InvalidArgumentException::class, '"main" is not a commit hash.'];
        yield 'a revision that says nothing' => [static fn (): Revision => new Revision($at, null, '   '), \InvalidArgumentException::class, 'A revision must say why the page was rewritten.'];
        yield 'an identifier of another type' => [static fn (): TrackingDocument => self::document(id: 'command.order.new'), InvalidModel::class, 'its identifier must start with "route"'];
        yield 'no history' => [static fn (): TrackingDocument => self::document(history: []), InvalidModel::class, 'needs at least its initial revision'];
        yield 'no title' => [static fn (): TrackingDocument => self::document(title: ' '), InvalidModel::class, 'needs a title'];
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
        self::assertTrue(TrackingFixtures::orderNew(TrackingStatus::Orphaned)->status->needsAttention());
        self::assertFalse(TrackingFixtures::orderNew()->status->needsAttention());
        self::assertFalse(TrackingFixtures::orderNew(TrackingStatus::Manual)->status->needsAttention(), 'A manual page is the team\'s: nothing to check.');
    }

    /**
     * @param list<Revision> $history
     */
    private static function document(string $id = 'route.order.new', string $title = 'Order creation', ?array $history = null): TrackingDocument
    {
        $fixture = TrackingFixtures::orderNew();

        return new TrackingDocument(new WorkflowId($id), WorkflowType::routes(), $title, $fixture->generated, $fixture->vcs, $fixture->main, history: $history ?? $fixture->history);
    }

    private static function groupedWithMechanismsAndDecisions(): TrackingDocument
    {
        $fixture = TrackingFixtures::orderNew();
        $pricing = new FileRef('src/Service/OrderPricing.php');

        return new TrackingDocument(
            $fixture->id,
            $fixture->type,
            $fixture->title,
            $fixture->generated,
            $fixture->vcs,
            $fixture->main,
            $fixture->satellites,
            $fixture->files,
            $fixture->tests,
            $fixture->packages,
            $fixture->dependsOn,
            $fixture->confidence,
            $fixture->producer,
            $fixture->status,
            $fixture->history,
            decisions: [
                new DecisionPoint('App\Entity\Order::currency', 'EUR', '$country === \'FR\'', $pricing, 12),
                new DecisionPoint('App\Entity\Order::currency', 'USD', null, $pricing, 14, Confidence::Medium),
            ],
            mechanisms: [
                new Mechanism('listener', 'App\EventListener\LocaleListener', 'kernel.request', new FileRef('src/EventListener/LocaleListener.php'), 20, [
                    new DecisionPoint('Request::locale', 'fr', null, new FileRef('src/EventListener/LocaleListener.php'), 9),
                ]),
                new Mechanism('middleware', 'App\Middleware\Audit', 'messenger.middleware', new FileRef('src/Middleware/Audit.php')),
            ],
            // The group's file is the main entry point's: the format stores it once (see the reader).
            group: new WorkflowGroup('order', 'Orders', $fixture->main->declaredIn),
        );
    }
}
