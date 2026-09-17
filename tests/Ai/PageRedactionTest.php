<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Ai;

use Jul6Art\DevTools\Ai\ApplyResult;
use Jul6Art\DevTools\Ai\DraftApplier;
use Jul6Art\DevTools\Ai\PageBrief;
use Jul6Art\DevTools\Ai\PageDraft;
use Jul6Art\DevTools\Ai\PageDraftValidator;
use Jul6Art\DevTools\Ai\XmlPageBriefStore;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\InspectionReport;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rendering\PageParser;
use Jul6Art\DevTools\Rendering\PageSection;
use Jul6Art\DevTools\Tests\Inspection\Pipeline\FakeAdapter;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tracking\GenerationMode;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DraftApplier::class)]
#[CoversClass(PageBrief::class)]
#[CoversClass(PageDraft::class)]
#[CoversClass(PageDraftValidator::class)]
#[CoversClass(XmlPageBriefStore::class)]
final class PageRedactionTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string DRAFT = __DIR__.'/../Fixtures/drafts/symfony-minimal/page.route.order.new.draft.md';

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
    }

    public function testAnInspectionWritesAValidBriefPerPageToWrite(): void
    {
        $this->inspect();

        $briefs = glob($this->project.'/.devtools/pending/page.*.brief.xml') ?: [];
        self::assertCount(6, $briefs);

        $brief = new XmlPageBriefStore()->read($this->project.'/.devtools/pending/page.route.order.new.brief.xml');
        self::assertSame('route.order.new', $brief->workflow->value);
        self::assertSame('page/1', $brief->promptVersion);
        self::assertFileExists($brief->promptPath);
        self::assertSame('fr', $brief->language);
        self::assertSame('.devtools/pending/page.route.order.new.draft.md', $brief->draftPath);
        self::assertFileExists($this->project.'/'.$brief->modelPath);
        self::assertSame('2026-09-16T15:00:00+02:00', $brief->revision->format(\DATE_ATOM));
        self::assertStringContainsString('rédaction en attente', (string) file_get_contents($this->project.'/.devtools/workflows.md'));
        self::assertSame(['Résumé', 'Préconditions', 'Parcours', 'Données', 'Mécanismes transverses', "Points d'attention", 'Changement'], $brief->sections, 'The brief carries the closed list of sections, in order.');
        self::assertSame([], $brief->changes, 'Nothing moved: the page has never been written.');
    }

    public function testABriefNamesTheFilesThatMovedSinceTheLastRevision(): void
    {
        $this->inspect();
        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');
        $this->apply();

        file_put_contents($this->project.'/src/Service/OrderPricing.php', "\n// changed\n", \FILE_APPEND);
        file_put_contents($this->project.'/src/Service/Discount.php', "<?php\nnamespace App\\Service;\nfinal class Discount {}\n");
        $pricing = $this->project.'/src/Service/OrderPricing.php';
        file_put_contents($pricing, str_replace('private ProductRepository $products', 'private ProductRepository $products, private Discount $discount', (string) file_get_contents($pricing)));
        $this->inspect(at: '2026-09-16T16:00:00+02:00');

        self::assertSame(
            [['path' => 'src/Service/OrderPricing.php', 'change' => 'changed'], ['path' => 'src/Service/Discount.php', 'change' => 'added']],
            new XmlPageBriefStore()->read($this->project.'/.devtools/pending/page.route.order.new.brief.xml')->changes,
        );
    }

    public function testWithoutAiNoBriefIsWritten(): void
    {
        $this->inspect(noAi: true);

        self::assertSame([], glob($this->project.'/.devtools/pending/page.*') ?: []);
    }

    public function testAnAcceptedDraftCompletesThePageAndItsTracking(): void
    {
        $this->inspect();
        $factual = new PageParser()->parse((string) file_get_contents($this->page()));
        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');

        $result = $this->apply();

        self::assertSame(['route.order.new'], $result->accepted);
        self::assertSame([], $result->refused);

        $page = new PageParser()->parse((string) file_get_contents($this->page()));
        self::assertSame([], $page->conformityProblems());
        self::assertStringStartsWith('Crée une commande', (string) $page->section(PageSection::Summary));
        self::assertStringContainsString('sequenceDiagram', (string) $page->section(PageSection::Journey));
        self::assertSame('Le catalogue de produits est chargé.', $page->preconditions());
        self::assertSame([PageSection::History], $page->devToolsSectionsDifferingFrom($factual), 'Facts are rendered identically; only the history line gains the words of the writing.');
        self::assertStringContainsString('| rédaction initiale |', (string) $page->section(PageSection::History));

        $tracking = new XmlTrackingStore(new WorkflowTypeRegistry())->read($this->project.'/.devtools/workflows/routes/order.new.xml');
        self::assertSame(GenerationMode::Ai, $tracking->generated->mode);
        self::assertSame('claude-opus-5', $tracking->generated->model);
        self::assertSame('page/1', $tracking->generated->prompt);
        self::assertCount(1, $tracking->history, 'The first writing amends the initial revision instead of adding one.');

        self::assertSame([], glob($this->project.'/.devtools/pending/page.route.order.new.*') ?: [], 'Brief, model and draft are removed.');
    }

    /**
     * Running the inspection again before Claude writes must not change the brief — nor, once the draft is
     * applied, add a second line to the history.
     */
    public function testInspectingTwiceBeforeWritingChangesNeitherTheBriefNorTheHistory(): void
    {
        $this->inspect();
        $brief = (string) file_get_contents($this->project.'/.devtools/pending/page.route.order.new.brief.xml');
        $this->inspect();

        self::assertStringEqualsFile($this->project.'/.devtools/pending/page.route.order.new.brief.xml', $brief);

        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');
        self::assertSame(['route.order.new'], $this->apply()->accepted);
        self::assertCount(1, new XmlTrackingStore(new WorkflowTypeRegistry())->read($this->project.'/.devtools/workflows/routes/order.new.xml')->history);
    }

    public function testAWrittenPageNeedsNoNewBriefAndSurvivesAFactualRewrite(): void
    {
        $this->inspect();
        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');
        $this->apply();

        $this->inspect();
        self::assertFileDoesNotExist($this->project.'/.devtools/pending/page.route.order.new.brief.xml');

        file_put_contents($this->project.'/src/Service/OrderPricing.php', "\n// changed\n", \FILE_APPEND);
        $this->inspect(noAi: true);

        $page = new PageParser()->parse((string) file_get_contents($this->page()));
        self::assertStringStartsWith('Crée une commande', (string) $page->section(PageSection::Summary));
        self::assertSame('Le catalogue de produits est chargé.', $page->preconditions());
    }

    /**
     * @param callable(string): string $mutation
     */
    #[DataProvider('refusals')]
    public function testADraftBreakingARuleIsRefusedWithTheRuleAndTheLine(callable $mutation, string $expected): void
    {
        $this->inspect();
        $before = (string) file_get_contents($this->page());
        file_put_contents($this->project.'/.devtools/pending/page.route.order.new.draft.md', $mutation((string) file_get_contents(self::DRAFT)));

        $result = $this->apply();

        self::assertSame([], $result->accepted);
        self::assertStringContainsString($expected, implode("\n", $result->refused['route.order.new'] ?? []));
        self::assertStringEqualsFile($this->page(), $before, 'A refused draft changes nothing.');
        self::assertFileExists($this->project.'/.devtools/pending/page.route.order.new.draft.md', 'A refused draft is kept for correction.');
    }

    /**
     * @return iterable<string, array{callable(string): string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'extra section' => [static fn (string $draft): string => $draft."\n## Composants impliqués\n\n| Rôle | Fichier |\n", 'section "Composants impliqués" is not one a draft may write (line 48)'];
        yield 'missing section' => [static fn (string $draft): string => str_replace("## Données\n\nÉcrit une `Order` en mémoire via `src/Repository/OrderRepository.php` ; lit le prix des produits.\n\n", '', $draft), 'section "Données" is missing'];
        yield 'journey without mermaid' => [static fn (string $draft): string => (string) preg_replace('/```mermaid.*?```/s', 'Le contrôleur appelle le service.', $draft), '"Parcours" must hold exactly one Mermaid sequenceDiagram or flowchart (line 15)'];
        yield 'invented path' => [static fn (string $draft): string => str_replace('`src/Repository/OrderRepository.php`', '`src/Repository/InvoiceRepository.php`', $draft), '`src/Repository/InvoiceRepository.php` is not a file of the workflow (line 34)'];
        yield 'data file' => [static fn (string $draft): string => str_replace('lit le prix des produits.', 'lit le prix des produits et `var/app.sqlite`.', $draft), '`var/app.sqlite` is not a file of the workflow (line 34)'];
        yield 'outdated' => [static fn (string $draft): string => str_replace('revision: 2026-09-16T15:00:00+02:00', 'revision: 2026-09-01T10:00:00+02:00', $draft), 'outdated'];
        yield 'two-line preconditions' => [static fn (string $draft): string => str_replace('Le catalogue de produits est chargé.', "Le catalogue est chargé.\nEt l'opérateur connecté.", $draft), '"Préconditions" must be one line (line 11)'];
    }

    public function testAModelEmptiedByHandIsRefusedByName(): void
    {
        $this->inspect();
        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');
        file_put_contents(
            $this->project.'/.devtools/pending/page.route.order.new.model.xml',
            '<?xml version="1.0" encoding="UTF-8"?><inspection xmlns="https://github.com/jul6art/devtools/schema/inspection-model/1" schema-version="1" stack="symfony-8"><workflows/></inspection>',
        );

        self::assertStringContainsString('holds no workflow', implode("\n", $this->apply()->refused['route.order.new'] ?? []));
    }

    public function testAUrlInBackticksIsNotAFilePath(): void
    {
        $this->inspect();
        file_put_contents($this->project.'/.devtools/pending/page.route.order.new.draft.md', str_replace('lit le prix des produits.', 'lit le prix des produits, comme `/orders/new.php` le ferait.', (string) file_get_contents(self::DRAFT)));

        self::assertSame(['route.order.new'], $this->apply()->accepted);
    }

    public function testAnOutdatedDraftIsRefusedWhenTheCodeChangedAfterTheBrief(): void
    {
        $this->inspect();
        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');
        file_put_contents($this->project.'/src/Service/OrderPricing.php', "\n// changed after the brief\n", \FILE_APPEND);
        $this->inspect(at: '2026-09-16T16:00:00+02:00');

        self::assertStringContainsString('outdated', implode("\n", $this->apply()->refused['route.order.new'] ?? []));
    }

    /**
     * A factual run (--no-ai, as in CI) moves the workflow to a new revision without touching the briefs: a
     * draft answering the old brief must not land on the new code.
     */
    public function testADraftForABriefTheTrackingHasMovedPastIsRefused(): void
    {
        $this->inspect();
        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');
        file_put_contents($this->project.'/src/Service/OrderPricing.php', "\n// changed\n", \FILE_APPEND);
        $this->inspect(noAi: true, at: '2026-09-16T16:00:00+02:00');

        self::assertStringContainsString('the brief was written for revision 2026-09-16T15:00:00+02:00, the workflow is now at revision 2026-09-16T16:00:00+02:00', implode("\n", $this->apply()->refused['route.order.new'] ?? []));
    }

    private function inspect(bool $noAi = false, string $at = '2026-09-16T15:00:00+02:00'): InspectionReport
    {
        $report = new InspectionPipeline(new AdapterResolver([new FakeAdapter()]), new FrozenClock(new \DateTimeImmutable($at)))->run(new InspectionOptions($this->project, noAi: $noAi));
        self::assertSame([], $report->errors, implode("\n", $report->errors));

        return $report;
    }

    private function apply(): ApplyResult
    {
        return new DraftApplier(new FrozenClock(new \DateTimeImmutable('2026-09-16T15:30:00+02:00')))->apply(new ProjectRoot($this->project));
    }

    private function page(): string
    {
        return $this->project.'/.devtools/workflows/routes/order.new.md';
    }
}
