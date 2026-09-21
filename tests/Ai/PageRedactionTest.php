<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Ai;

use Jul6Art\DevTools\Ai\ApplyResult;
use Jul6Art\DevTools\Ai\DraftApplier;
use Jul6Art\DevTools\Ai\PageBrief;
use Jul6Art\DevTools\Ai\PageDraft;
use Jul6Art\DevTools\Ai\PageDraftValidator;
use Jul6Art\DevTools\Ai\XmlGroupBriefStore;
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
        self::assertCount(5, $briefs);

        $brief = new XmlPageBriefStore()->read($this->project.'/.devtools/pending/page.route.order.new.brief.xml');
        self::assertSame('route.order.new', $brief->workflow->value);
        self::assertSame('page/3', $brief->promptVersion);
        self::assertFileExists($brief->promptPath);
        self::assertSame('en', $brief->language, 'English unless the project or the caller says otherwise.');
        self::assertSame('.devtools/pending/page.route.order.new.draft.md', $brief->draftPath);
        self::assertFileExists($this->project.'/'.$brief->modelPath);
        self::assertSame('2026-09-16T15:00:00+02:00', $brief->revision->format(\DATE_ATOM));
        self::assertStringContainsString('waiting for Claude', (string) file_get_contents($this->project.'/docs/workflows/workflows.md'));
        self::assertSame(['Summary', 'Preconditions', 'Journey', 'Decisions', 'Data', 'Cross-cutting mechanisms', 'Points of attention', 'Change'], $brief->sections, 'The brief carries the closed list of sections, in order.');
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

    public function testTheLanguageOfTheBriefsComesFromTheOptionThenTheProjectThenEnglish(): void
    {
        $this->inspect(language: 'de', fallbackLanguage: 'es');
        self::assertSame('de', $this->brief()->language, 'The option wins over everything.');

        $configuration = $this->project.'/.devtools/config.xml';
        file_put_contents($configuration, str_replace('<types/>', '<types/><language pages="fr"/>', (string) file_get_contents($configuration)));
        $this->inspect(fallbackLanguage: 'es');
        self::assertSame('fr', $this->brief()->language, 'The project wins over the configured default.');

        file_put_contents($configuration, str_replace('<language pages="fr"/>', '', (string) file_get_contents($configuration)));
        $this->inspect(fallbackLanguage: 'es');
        self::assertSame('es', $this->brief()->language, 'The bundle\'s configuration wins over English.');
    }

    public function testALanguageThatIsNotTwoLettersIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"french" is not a language');

        new InspectionOptions($this->project, language: 'french');
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
        self::assertSame('page/3', $tracking->generated->prompt);
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
        yield 'extra section' => [static fn (string $draft): string => $draft."\n## Composants impliqués\n\n| Rôle | Fichier |\n", 'section "Composants impliqués" is not one a draft may write (line 71)'];
        yield 'missing section' => [static fn (string $draft): string => str_replace("## Data\n\nÉcrit une `Order` en mémoire via `src/Repository/OrderRepository.php` ; lit le prix des produits.\n\n", '', $draft), 'section "Data" is missing'];
        yield 'journey without mermaid' => [static fn (string $draft): string => (string) preg_replace('/```mermaid.*?```/s', 'Le contrôleur appelle le service.', $draft), '"Journey" must hold exactly one Mermaid sequenceDiagram or flowchart (line 15)'];
        yield 'invented path' => [static fn (string $draft): string => str_replace('`src/Repository/OrderRepository.php`', '`src/Repository/InvoiceRepository.php`', $draft), '`src/Repository/InvoiceRepository.php` is not a file of the workflow (line 57)'];
        yield 'data file' => [static fn (string $draft): string => str_replace('lit le prix des produits.', 'lit le prix des produits et `var/app.sqlite`.', $draft), '`var/app.sqlite` is not a file of the workflow (line 57)'];
        yield 'outdated' => [static fn (string $draft): string => str_replace('revision: 2026-09-16T15:00:00+02:00', 'revision: 2026-09-01T10:00:00+02:00', $draft), 'outdated'];
        yield 'two-line preconditions' => [static fn (string $draft): string => str_replace('Le catalogue de produits est chargé.', "Le catalogue est chargé.\nEt l'opérateur connecté.", $draft), '"Preconditions" must be one line (line 11)'];

        // ADR-0043: the decisions are Claude's to draw, but only from what the model records.
        yield 'invented decision target' => [
            static fn (string $draft): string => str_replace('**`App\Entity\Order::currency`**', '**`App\Entity\Order::invented`**', $draft),
            '`App\Entity\Order::invented` is not a value this workflow decides',
        ];
        yield 'decided field left undrawn' => [
            static fn (string $draft): string => (string) preg_replace('/\*\*`App\\\\Entity\\\\Order::currency`\*\*.*?```\n/s', '', $draft),
            '`App\Entity\Order::currency` is decided by this workflow and is not drawn',
        ];
        yield 'decisions without a flowchart' => [
            static fn (string $draft): string => (string) preg_replace('/```mermaid\nflowchart TD.*?```/s', 'Selon le pays.', $draft),
            '"Decisions" must hold at least one Mermaid flowchart',
        ];
        yield 'decisions emptied while the model records some' => [
            static fn (string $draft): string => (string) preg_replace('/(## Decisions\n\n).*?(\n## Data)/s', '$1—$2', $draft),
            '"Decisions" is empty while the model records 6 decided value(s)',
        ];
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

    /**
     * ADR-0045: a group page gets a brief of its own — one section, three to five sentences — and applying it
     * replaces that section without touching the facts DevTools rendered.
     */
    public function testAGroupPageIsWrittenFromItsOwnBriefAndKeepsItsFacts(): void
    {
        self::groupByController($this->project);
        $this->inspect();

        $brief = new XmlGroupBriefStore()->read($this->project.'/.devtools/pending/group.routes.order.brief.xml');

        self::assertSame('group/2', $brief->promptVersion);
        self::assertFileExists($brief->promptPath);
        self::assertSame('docs/workflows/routes/order/README.md', $brief->pagePath);
        self::assertSame(['app_order_index', 'app_order_new', 'app_order_show'], array_map(static fn (array $route): string => $route['route'], $brief->routes));

        file_put_contents($this->project.'/'.$brief->draftPath, "---\nmodel: claude-opus-5\n---\n\n## Summary\n\nLes commandes, de leur création à leur validation.\n");

        $result = $this->apply();

        self::assertSame(['group.routes.order'], $result->accepted);
        self::assertSame([], $result->refused);

        $page = (string) file_get_contents($this->project.'/docs/workflows/routes/order/README.md');

        self::assertStringContainsString("## Summary\n\nLes commandes, de leur création à leur validation.", $page);
        self::assertStringContainsString('| [`app_order_new`](new.md) |', $page, 'The facts stay exactly as the inspection rendered them.');
        self::assertFileDoesNotExist($this->project.'/'.$brief->draftPath, 'An applied draft is removed.');
        self::assertFileDoesNotExist($this->project.'/.devtools/pending/group.routes.order.brief.xml');

        $this->inspect(at: '2026-09-16T16:00:00+02:00');

        self::assertStringContainsString('Les commandes, de leur création à leur validation.', (string) file_get_contents($this->project.'/docs/workflows/routes/order/README.md'), 'A later inspection keeps what Claude wrote.');
        self::assertFileDoesNotExist($this->project.'/.devtools/pending/group.routes.order.brief.xml', 'And asks for it again only when it is missing.');
    }

    /**
     * ADR-0045: the page of a route lives in the directory of its group, and `apply` writes it there.
     *
     * It wrote it at the flat path instead — `routes/order.new.md` — because the serialized model carries no
     * group and the path was recomputed from it: on three real projects, every written page landed beside the
     * page the reader opens, which stayed empty.
     */
    public function testAnAcceptedDraftIsWrittenInTheDirectoryOfItsGroup(): void
    {
        self::groupByController($this->project);
        $this->inspect();

        self::assertSame('docs/workflows/routes/order/new.md', new XmlPageBriefStore()->read($this->project.'/.devtools/pending/page.route.order.new.brief.xml')->pagePath);

        $factual = new PageParser()->parse((string) file_get_contents($this->project.'/docs/workflows/routes/order/new.md'));
        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');

        $result = $this->apply();

        self::assertSame(['route.order.new'], $result->accepted);
        self::assertSame([], $result->refused);

        $page = new PageParser()->parse((string) file_get_contents($this->project.'/docs/workflows/routes/order/new.md'));

        self::assertStringContainsString('Crée une commande', (string) $page->section(PageSection::Summary));
        self::assertFileDoesNotExist($this->project.'/docs/workflows/routes/order.new.md', 'Nothing is written beside the directory of the group.');
        self::assertSame([PageSection::History], $page->devToolsSectionsDifferingFrom($factual), 'The facts, links included, are rendered exactly as the inspection rendered them.');
    }

    public function testAGroupDraftThatSaysTooMuchIsRefused(): void
    {
        self::groupByController($this->project);
        $this->inspect();

        $sentences = str_repeat('Une phrase de plus. ', 6);
        file_put_contents($this->project.'/.devtools/pending/group.routes.order.draft.md', "---\nmodel: claude-opus-5\n---\n\n## Summary\n\n".$sentences."\n\n## Routes\n\nrien\n");

        $result = $this->apply();

        self::assertSame([], $result->accepted);
        self::assertArrayHasKey('group.routes.order', $result->refused);
        self::assertStringContainsString('exactly one section', implode("\n", $result->refused['group.routes.order']));
        self::assertStringContainsString('longer than 5 sentences', implode("\n", $result->refused['group.routes.order']));
    }

    private static function groupByController(string $project): void
    {
        if (!is_dir($project.'/.devtools')) {
            mkdir($project.'/.devtools', 0o777, true);
        }

        file_put_contents($project.'/.devtools/config.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1">
                <routes group="controller"/>
            </devtools>

            XML);
    }

    private function inspect(bool $noAi = false, string $at = '2026-09-16T15:00:00+02:00', ?string $language = null, ?string $fallbackLanguage = null): InspectionReport
    {
        $report = new InspectionPipeline(new AdapterResolver([new FakeAdapter()]), new FrozenClock(new \DateTimeImmutable($at)))->run(new InspectionOptions($this->project, noAi: $noAi, language: $language, fallbackLanguage: $fallbackLanguage));
        self::assertSame([], $report->errors, implode("\n", $report->errors));

        return $report;
    }

    private function brief(): PageBrief
    {
        return new XmlPageBriefStore()->read($this->project.'/.devtools/pending/page.route.order.new.brief.xml');
    }

    private function apply(): ApplyResult
    {
        return new DraftApplier(new FrozenClock(new \DateTimeImmutable('2026-09-16T15:30:00+02:00')))->apply(new ProjectRoot($this->project));
    }

    private function page(): string
    {
        return $this->project.'/docs/workflows/routes/order.new.md';
    }
}
