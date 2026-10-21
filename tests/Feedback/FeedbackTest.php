<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Feedback;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\FeedbackAddCommand;
use Jul6Art\DevTools\Command\FeedbackListCommand;
use Jul6Art\DevTools\Command\FeedbackResolveCommand;
use Jul6Art\DevTools\Feedback\Categories;
use Jul6Art\DevTools\Feedback\Feedback;
use Jul6Art\DevTools\Feedback\FeedbackIndex;
use Jul6Art\DevTools\Feedback\FeedbackIndexEntry;
use Jul6Art\DevTools\Feedback\FeedbackNotFound;
use Jul6Art\DevTools\Feedback\FeedbackRecorder;
use Jul6Art\DevTools\Feedback\FeedbackRepository;
use Jul6Art\DevTools\Feedback\FeedbackSource;
use Jul6Art\DevTools\Feedback\FeedbackStatus;
use Jul6Art\DevTools\Feedback\FeedbackTarget;
use Jul6Art\DevTools\Feedback\Occurrence;
use Jul6Art\DevTools\Feedback\RecurrenceDetector;
use Jul6Art\DevTools\Feedback\XmlFeedbackIndexStore;
use Jul6Art\DevTools\Feedback\XmlFeedbackStore;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0025: a feedback as data on symfony-minimal inspected — checked, written, listed, closed — and seen again as
 * an occurrence rather than a duplicate, until a rule is proposed.
 */
#[CoversClass(Feedback::class)]
#[CoversClass(FeedbackTarget::class)]
#[CoversClass(Occurrence::class)]
#[CoversClass(FeedbackStatus::class)]
#[CoversClass(FeedbackSource::class)]
#[CoversClass(Categories::class)]
#[CoversClass(XmlFeedbackStore::class)]
#[CoversClass(FeedbackIndex::class)]
#[CoversClass(FeedbackIndexEntry::class)]
#[CoversClass(XmlFeedbackIndexStore::class)]
#[CoversClass(FeedbackRepository::class)]
#[CoversClass(FeedbackNotFound::class)]
#[CoversClass(FeedbackRecorder::class)]
#[CoversClass(RecurrenceDetector::class)]
#[CoversClass(FeedbackAddCommand::class)]
#[CoversClass(FeedbackListCommand::class)]
#[CoversClass(FeedbackResolveCommand::class)]
final class FeedbackTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string EXAMPLE = <<<'XML'
        <feedback xmlns="https://github.com/jul6art/devtools/schema/feedback/1" schema-version="1" id="fb-2026-09-20-order-product" status="open">
          <reported at="2026-09-20T10:12:00+02:00" by="human" commit="a1b2c3d"/>
          <target workflow="route.order.new" selector="#order_product" file="templates/order/_form.html.twig"/>
          <category>ui-component</category>
          <summary>Le champ produit doit être un select2, pas un select natif.</summary>
          <occurrences>
            <occurrence at="2026-09-20T10:12:00+02:00" by="human" workflow="route.order.new" selector="#order_product" commit="a1b2c3d"/>
          </occurrences>
        </feedback>
        XML;

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($this->project, noAi: true));
    }

    public function testTheExampleOfTheDecisionReadsAndWritesBackToItself(): void
    {
        $store = new XmlFeedbackStore();
        $file = $this->project.'/example.xml';
        file_put_contents($file, self::EXAMPLE);
        $feedback = $store->read($file);

        self::assertSame('fb-2026-09-20-order-product', $feedback->id);
        self::assertSame('#order_product', $feedback->target->selector);
        self::assertSame(FeedbackSource::Human, $feedback->reported()->by);
        self::assertSame('2026/fb-2026-09-20-order-product.xml', $feedback->path());
        self::assertEquals($feedback, $store->read($this->written($store->serialize($feedback))));
    }

    /**
     * @param non-empty-string $cause
     */
    #[DataProvider('invalid')]
    public function testWhatTheSchemaRefusesIsRefused(string $from, string $to, string $cause): void
    {
        $this->expectException(InvalidXml::class);
        $this->expectExceptionMessageMatches('/'.$cause.'/');

        new XmlFeedbackStore()->read($this->written(str_replace($from, $to, self::EXAMPLE)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'a summary of two lines' => ['pas un select natif.', "pas un select\nnatif.", 'summary'];
        yield 'a summary too long' => ['Le champ produit doit être un select2, pas un select natif.', str_repeat('x', 201), 'summary'];
        yield 'an unknown status' => ['status="open"', 'status="maybe"', 'status'];
        yield 'no occurrence' => ['<occurrence at="2026-09-20T10:12:00+02:00" by="human" workflow="route.order.new" selector="#order_product" commit="a1b2c3d"/>', '', 'occurrence'];
    }

    /**
     * What a schema cannot say, DevTools says before writing: a target, a category of the list, a workflow that
     * exists, a file of the project.
     *
     * @param list<string> $expected
     */
    #[DataProvider('refusals')]
    public function testWhatCannotBeRecordedIsSaidBeforeWriting(FeedbackTarget $target, string $category, string $summary, array $expected): void
    {
        $refusals = $this->recorder()->refusals($target, $category, $summary);

        self::assertCount(\count($expected), $refusals, implode("\n", $refusals));

        foreach ($expected as $index => $part) {
            self::assertStringContainsString($part, $refusals[$index]);
        }
    }

    /**
     * @return iterable<string, array{FeedbackTarget, string, string, list<string>}>
     */
    public static function refusals(): iterable
    {
        yield 'a valid one' => [new FeedbackTarget('route.order.new', '#order_product', 'templates/order/_form.html.twig'), 'ui-component', 'Le champ produit doit être un select2.', []];
        yield 'a summary too long' => [new FeedbackTarget('route.order.new'), 'ui-component', str_repeat('x', 201), ['201 characters long']];
        yield 'no target' => [new FeedbackTarget(), 'ui-component', 'Something.', ['--workflow, --selector or --file']];
        yield 'an unknown category' => [new FeedbackTarget('route.order.new'), 'aesthetics', 'Something.', ['"aesthetics" is not a category']];
        yield 'a workflow that does not exist' => [new FeedbackTarget('route.order.edit'), 'ui-component', 'Something.', ['"route.order.edit" is not a workflow']];
        yield 'a file that does not exist' => [new FeedbackTarget(file: 'templates/order/nope.html.twig'), 'layout', 'Something.', ['is not a file of this project']];
        yield 'a file outside the project' => [new FeedbackTarget(file: '../outside.twig'), 'layout', 'Something.', ['outside the project root']];
        yield 'an empty summary' => [new FeedbackTarget('route.order.new'), 'layout', ' ', ['one line']];
    }

    public function testAProjectAddsItsOwnCategories(): void
    {
        file_put_contents($this->project.'/.devtools/config.xml', '<devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1"><feedback><category name="accessibility"/><category name="layout"/></feedback></devtools>');

        self::assertSame([], $this->recorder()->refusals(new FeedbackTarget('route.order.new'), 'accessibility', 'Le bouton n\'a pas de libellé.'));
        self::assertCount(11, $this->recorder()->categories()->names(), 'One of DevTools\' own, declared again, is not counted twice.');
        self::assertStringStartsWith('Where things are', $this->recorder()->categories()->all['layout']);
    }

    public function testAFeedbackIsAddedThenListedThenResolved(): void
    {
        $repository = GitRepository::initialise($this->project);
        $repository->commitAll('documented');
        $add = $this->add(['summary' => 'Le champ produit doit être un select2.', '--workflow' => 'route.order.new', '--selector' => '#order_product', '--category' => 'ui-component']);

        self::assertSame(0, $add->getStatusCode(), $add->getDisplay());
        self::assertStringContainsString('Recorded fb-2026-10-20-order-product.', $add->getDisplay());
        $feedback = $this->recorder()->repository()->get('fb-2026-10-20-order-product');
        self::assertSame($repository->head(), $feedback->reported()->commit, 'Seen at this commit.');
        self::assertFileExists($this->project.'/.devtools/feedback/2026/fb-2026-10-20-order-product.xml');

        $list = new CommandTester(new FeedbackListCommand($this->project));
        self::assertSame(0, $list->execute([]));
        self::assertStringContainsString('fb-2026-10-20-order-product', $list->getDisplay());
        self::assertStringContainsString('route.order.new · #order_product', $list->getDisplay());

        $resolve = new CommandTester(new FeedbackResolveCommand($this->project));
        self::assertSame(0, $resolve->execute(['id' => 'fb-2026-10-20-order-product', '--status' => 'wont-fix']));
        self::assertSame(FeedbackStatus::WontFix, $this->recorder()->repository()->get('fb-2026-10-20-order-product')->status);

        self::assertSame(0, $list->execute([]));
        self::assertStringContainsString('No open feedback.', $list->getDisplay());
        self::assertSame(0, $list->execute(['--status' => 'wont-fix', '--category' => 'ui-component', '--workflow' => 'route.order.new']));
        self::assertStringContainsString('fb-2026-10-20-order-product', $list->getDisplay());
        self::assertSame(0, $list->execute(['--categories' => true]));
        self::assertStringContainsString('error-handling', $list->getDisplay());
    }

    /**
     * The same element in the same category: seen again — an occurrence, not a duplicate — and the second time a
     * rule is proposed. Another category: another feedback.
     */
    public function testSeenAgainIsAnOccurrenceAndASecondTimeProposesARule(): void
    {
        $this->add(['summary' => 'Le champ produit doit être un select2.', '--selector' => '#order_product', '--workflow' => 'route.order.new', '--category' => 'ui-component']);

        $again = $this->add(['summary' => 'Encore un select natif.', '--selector' => '#order_product', '--category' => 'ui-component', '--auto' => true]);
        self::assertStringContainsString('fb-2026-10-20-order-product seen again: 2 occurrences.', $again->getDisplay());
        self::assertStringContainsString('devtools rules:promote fb-2026-10-20-order-product', self::flat($again));

        $other = $this->add(['summary' => 'Le libellé est en anglais.', '--selector' => '#order_product', '--category' => 'translation']);
        self::assertStringContainsString('Recorded fb-2026-10-20-order-product-2.', $other->getDisplay(), 'Another category: another feedback, and another identifier.');

        $entries = $this->recorder()->repository()->index()->entries;
        self::assertSame([2, 1], array_map(static fn (FeedbackIndexEntry $entry): int => $entry->occurrences, $entries));
    }

    /**
     * Without anyone to ask, already said is not decided for the human: the command stops and says how.
     */
    public function testWithoutAnyoneToAskARecurrenceIsNotDecidedForTheHuman(): void
    {
        $this->add(['summary' => 'Le champ produit doit être un select2.', '--file' => 'templates/order/_form.html.twig', '--category' => 'ui-component']);

        $stopped = $this->add(['summary' => 'Toujours pas de select2.', '--file' => 'templates/order/_form.html.twig', '--category' => 'ui-component']);
        self::assertSame(1, $stopped->getStatusCode());
        self::assertStringContainsString('--auto adds an occurrence to it; --new records this one apart.', self::flat($stopped));

        $apart = $this->add(['summary' => 'Toujours pas de select2.', '--file' => 'templates/order/_form.html.twig', '--category' => 'ui-component', '--new' => true]);
        self::assertSame(0, $apart->getStatusCode());
        self::assertCount(2, $this->recorder()->repository()->index()->entries);
    }

    public function testAskedWhatTheCommandLineDidNotSay(): void
    {
        $tester = new CommandTester(new FeedbackAddCommand(new FrozenClock(new \DateTimeImmutable('2026-10-20T10:00:00+02:00')), defaultPath: $this->project));
        $tester->setInputs(['layout', 'Le bouton déborde de la carte.', 'route.order.show', '', '']);

        self::assertSame(0, $tester->execute([], ['interactive' => true]));
        self::assertSame('layout', $this->recorder()->repository()->index()->entries[0]->category);

        $tester->setInputs(['layout', 'Il déborde encore.', 'route.order.show', '', '', 'yes']);
        self::assertSame(0, $tester->execute([], ['interactive' => true]));
        self::assertSame(2, $this->recorder()->repository()->index()->entries[0]->occurrences, 'Confirmed: an occurrence.');
    }

    /**
     * A fixed feedback seen again came back: it is open again. One set aside on purpose is not seen again.
     */
    public function testAFixedFeedbackSeenAgainIsOpenAgainAndAWontFixOneIsLeftAlone(): void
    {
        $recorder = $this->recorder();
        $fixed = $recorder->create(new FeedbackTarget(selector: '.flash'), 'layout', 'Le message masque le titre.');
        $recorder->resolve($fixed->id, FeedbackStatus::Fixed);

        self::assertSame(FeedbackStatus::Open, $recorder->seenAgain($fixed->id, new FeedbackTarget(selector: '.flash'))->status);

        $recorder->resolve($fixed->id, FeedbackStatus::WontFix);
        self::assertNull($recorder->recurrenceOf(new FeedbackTarget(selector: '.flash'), 'layout'));

        $this->expectException(FeedbackNotFound::class);
        $recorder->seenAgain('fb-2026-01-01-nothing', new FeedbackTarget(selector: '.flash'));
    }

    /**
     * Two routes of the same kind sharing a component are close; a route and a command are not.
     */
    public function testWorkflowsOfAKindSharingAComponentAreClose(): void
    {
        $recorder = $this->recorder();
        $recorder->create(new FeedbackTarget('route.order.new'), 'layout', 'La base de page coupe le pied de page.');

        self::assertSame('fb-2026-10-20-route-order-new', $recorder->recurrenceOf(new FeedbackTarget('route.order.index'), 'layout')?->id, 'Both render templates/base.html.twig.');
        self::assertNull($recorder->recurrenceOf(new FeedbackTarget('command.app.import-catalog'), 'layout'));
        self::assertNull(new RecurrenceDetector()->find($recorder->repository()->index(), new FeedbackTarget('route.order.index'), 'layout'), 'Without the map, only what is equal.');
    }

    public function testTheIndexReadsBackToTheSameBytes(): void
    {
        $recorder = $this->recorder();
        $recorder->create(new FeedbackTarget('route.order.new', '#order_product'), 'ui-component', 'Un select2.');
        $recorder->create(new FeedbackTarget(file: 'templates/order/_form.html.twig'), 'layout', 'Le formulaire déborde.');
        $file = $this->project.'/.devtools/feedback/index.xml';
        $store = new XmlFeedbackIndexStore();

        self::assertFalse($store->write($file, $store->read($file)), 'Read and written again: not a byte moves.');
        self::assertEquals(new FeedbackIndex(), $store->read($this->project.'/none.xml'));
    }

    public function testTheCommandsRefuseWhatTheyCannotDo(): void
    {
        self::assertSame(2, $this->add(['summary' => 'x', '--by' => 'robot', '--workflow' => 'route.order.new', '--category' => 'layout'])->getStatusCode());
        self::assertSame(2, $this->add(['summary' => 'x', '--category' => 'layout'])->getStatusCode(), 'No target.');
        self::assertSame(2, $this->add(['--path' => $this->project.'/nowhere'])->getStatusCode());
        self::assertSame(2, new CommandTester(new FeedbackListCommand($this->project))->execute(['--status' => 'later']));
        self::assertSame(2, new CommandTester(new FeedbackListCommand())->execute(['--path' => $this->project.'/nowhere']));
        self::assertSame(2, new CommandTester(new FeedbackResolveCommand($this->project))->execute(['id' => 'fb-2026-01-01-x', '--status' => 'promoted']));
        self::assertSame(1, new CommandTester(new FeedbackResolveCommand($this->project))->execute(['id' => 'fb-2026-01-01-x']));
        self::assertSame(2, new CommandTester(new FeedbackResolveCommand())->execute(['id' => 'fb-2026-01-01-x', '--path' => $this->project.'/nowhere']));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function add(array $input): CommandTester
    {
        $tester = new CommandTester(new FeedbackAddCommand(new FrozenClock(new \DateTimeImmutable('2026-10-20T10:00:00+02:00')), defaultPath: $this->project));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    /**
     * The display on one line, without the margin SymfonyStyle gives a wrapped note.
     */
    private static function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/(\s+!)?\s+/', ' ', $tester->getDisplay());
    }

    private function recorder(): FeedbackRecorder
    {
        return FeedbackRecorder::for(DevToolsDirectory::documented(new ProjectRoot($this->project)), new FrozenClock(new \DateTimeImmutable('2026-10-20T10:00:00+02:00')));
    }

    private function written(string $xml): string
    {
        $file = $this->project.'/feedback-'.bin2hex(random_bytes(4)).'.xml';
        file_put_contents($file, $xml);

        return $file;
    }
}
