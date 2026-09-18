<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Rendering;

use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowGroup;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Rendering\MarkdownWriter;
use Jul6Art\DevTools\Rendering\MermaidWriter;
use Jul6Art\DevTools\Rendering\PageParser;
use Jul6Art\DevTools\Rendering\PageRenderer;
use Jul6Art\DevTools\Rendering\PageSection;
use Jul6Art\DevTools\Rendering\ParsedPage;
use Jul6Art\DevTools\Rendering\RenderingContext;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageRenderer::class)]
#[CoversClass(PageParser::class)]
#[CoversClass(ParsedPage::class)]
#[CoversClass(PageSection::class)]
#[CoversClass(MarkdownWriter::class)]
#[CoversClass(MermaidWriter::class)]
#[CoversClass(RenderingContext::class)]
final class PageRendererTest extends TestCase
{
    use AssertsSnapshots;

    #[DataProvider('types')]
    public function testTheFactualPageOfEachTypeMatchesItsSnapshot(string $type): void
    {
        $page = new PageRenderer()->render(RenderingFixtures::workflows()[$type], RenderingFixtures::history(), RenderingFixtures::context());

        self::assertMatchesSnapshot($page, __DIR__.'/Fixtures/pages/'.$type.'.md');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function types(): iterable
    {
        foreach (array_keys(RenderingFixtures::workflows()) as $type) {
            yield $type => [$type];
        }
    }

    /**
     * The page is no longer an inventory: the files and the tests of a workflow live in its XML tracking
     * file, which is what links it to the code (ADR-0043). 250 of a real page's 344 lines were those two
     * tables.
     */
    public function testThePageListsNeitherTheFilesNorTheTestsOfTheWorkflow(): void
    {
        $workflow = RenderingFixtures::withFiles(RenderingFixtures::workflows()['routes'], [new FileRef('src/Entity/Order.php', FileRole::Entity)]);
        $page = new PageRenderer()->render($workflow, RenderingFixtures::history(), RenderingFixtures::context());

        self::assertStringNotContainsString('Composants impliqués', $page);
        self::assertStringNotContainsString('Tests existants', $page);
        self::assertStringNotContainsString('src/Entity/Order.php', $page);
        self::assertStringNotContainsString('tests/Controller/OrderControllerTest.php', $page);
        self::assertStringNotContainsString('Paquets :', $page);
    }

    /**
     * A listener is not a workflow: what a reader wants is what it can do inside this one (ADR-0043).
     */
    public function testTheMechanismsOfTheWorkflowAreListedWithWhatTheyWrite(): void
    {
        $workflow = new Workflow(
            id: new WorkflowId('route.order.new'),
            type: WorkflowType::routes(),
            title: 'Order creation',
            main: new EntryPoint('route', 'app_order_new', new FileRef('src/Controller/OrderController.php', FileRole::Controller)),
            mechanisms: [new Mechanism(
                'listener',
                'App\\EventListener\\LocaleListener',
                'kernel.request',
                new FileRef('src/EventListener/LocaleListener.php', FileRole::Listener),
                16,
                [new DecisionPoint('App\\Entity\\User::locale', "'fr'", null, new FileRef('src/EventListener/LocaleListener.php', FileRole::Listener), 22)],
            )],
        );

        $mechanisms = (string) new PageParser()->parse(new PageRenderer()->render($workflow, RenderingFixtures::history(), RenderingContext::of([$workflow])))->section(PageSection::CrossCutting);

        self::assertStringContainsString('| `App\\EventListener\\LocaleListener` | `kernel.request` | 16 | `App\\Entity\\User::locale` |', $mechanisms);
    }

    /**
     * Titles and order fixed: a page is parsable and diffable (specs § 4.5).
     */
    #[DataProvider('types')]
    public function testEveryPageHasTheTemplateSectionsInOrderAndNothingElse(string $type): void
    {
        $page = new PageRenderer()->render(RenderingFixtures::workflows()[$type], RenderingFixtures::history(), RenderingFixtures::context());
        $parsed = new PageParser()->parse($page);

        self::assertSame([], $parsed->conformityProblems());
        self::assertSame(array_map(static fn (PageSection $section): string => $section->value, PageSection::cases()), array_keys($parsed->sections));
        self::assertStringStartsWith('# ', $page);
        self::assertMatchesRegularExpression('/^`[a-z0-9.-]+` · type : [a-z]+ · dernière mise à jour : \d{4}-\d{2}-\d{2} · commit : [0-9a-f]{7}$/m', $page);

        foreach ($parsed->sections as $name => $content) {
            self::assertNotSame('', trim($content), \sprintf('The section "%s" is never empty: it holds "—".', $name));
        }
    }

    public function testSectionsOutOfOrderAreAConformityProblem(): void
    {
        $page = new PageRenderer()->render(RenderingFixtures::workflows()['commands'], RenderingFixtures::history(), RenderingFixtures::context());
        $swapped = str_replace(["## Données\n", "## Résumé\n"], ["## __TMP__\n", "## Données\n"], $page);
        $swapped = str_replace('## __TMP__', '## Résumé', $swapped);

        self::assertSame(['The sections are not in the order of the template.'], new PageParser()->parse($swapped)->conformityProblems());
        self::assertSame(['The section "Historique" is missing.'], new PageParser()->parse(substr($page, 0, (int) strpos($page, '## Historique')))->conformityProblems());
    }

    /**
     * A sequence diagram or a code sample Claude writes may contain a line starting with "## ".
     */
    public function testAHeadingInsideACodeBlockIsContentNotASection(): void
    {
        $page = str_replace("## Données\n\n—", "## Données\n\n```php\n## not a section\n```", new PageRenderer()->render(RenderingFixtures::workflows()['commands'], RenderingFixtures::history(), RenderingFixtures::context()));
        $parsed = new PageParser()->parse($page);

        self::assertSame([], $parsed->conformityProblems());
        self::assertSame("```php\n## not a section\n```", $parsed->sections['Données']);
    }

    public function testParsingARenderedPageGivesBackEverySection(): void
    {
        $page = new PageRenderer()->render(RenderingFixtures::workflows()['routes'], RenderingFixtures::history(), RenderingFixtures::context());
        $parsed = new PageParser()->parse($page);

        self::assertSame('—', $parsed->sections['Résumé']);
        self::assertStringContainsString('| Point d\'entrée | `GET\|POST /orders/new` (`app_order_new`) |', $parsed->sections['Déclencheur']);
        self::assertSame('`route.order.new` · type : routes · dernière mise à jour : 2026-09-16 · commit : a1b2c3d', $parsed->header);
    }

    public function testAHandEditInADevToolsSectionIsDetected(): void
    {
        $renderer = new PageRenderer();
        $page = $renderer->render(RenderingFixtures::workflows()['routes'], RenderingFixtures::history(), RenderingFixtures::context());
        $edited = str_replace('n3["app_order_show"]', 'n3["app_order_elsewhere"]', $page);
        $edited = str_replace("## Résumé\n\n—", "## Résumé\n\nWritten by Claude.", $edited);

        self::assertSame([PageSection::Navigation], new PageParser()->parse($edited)->devToolsSectionsDifferingFrom(new PageParser()->parse($page)));
    }

    public function testAFactualRewriteKeepsWhatClaudeWrote(): void
    {
        $renderer = new PageRenderer();
        $written = new PageParser()->parse(strtr($renderer->render(RenderingFixtures::workflows()['routes'], RenderingFixtures::history(), RenderingFixtures::context()), [
            "## Résumé\n\n—" => "## Résumé\n\nCrée une commande.",
            "## Données\n\n—" => "## Données\n\nÉcrit `Order`.",
            "## Points d'attention\n\n—" => "## Points d'attention\n\nLe prix est recalculé.",
            '| Préconditions | — |' => '| Préconditions | catalogue chargé |',
        ]));
        $written = $written->withSection(PageSection::Journey, "```mermaid\nsequenceDiagram\n  U->>C: POST /orders/new\n```");
        $written = $written->withSection(PageSection::Decisions, "**`App\\Entity\\Order::status`**\n\n```mermaid\nflowchart TD\n  d1{\"sans référence\"}\n```");
        $written = $written->withSection(PageSection::CrossCutting, 'La locale est fixée par `LocaleListener`.');

        $rewritten = new PageParser()->parse($renderer->render(RenderingFixtures::workflows()['routes'], RenderingFixtures::history(), RenderingFixtures::context(), $written));

        foreach ([PageSection::Summary, PageSection::Journey, PageSection::Decisions, PageSection::Data, PageSection::CrossCutting, PageSection::Attention] as $section) {
            self::assertSame($written->sections[$section->value], $rewritten->sections[$section->value], $section->value);
        }

        self::assertStringContainsString('| Préconditions | catalogue chargé |', $rewritten->sections['Déclencheur']);
    }

    /**
     * Without Claude, "Parcours" and "Décisions" hold "—": a flowchart of the files reached restated the
     * table this ADR removed, and a decision diagram is Claude's to draw (ADR-0043).
     */
    public function testWithoutAWrittenPageTheSectionsClaudeOwnsAreEmpty(): void
    {
        $parsed = new PageParser()->parse(new PageRenderer()->render(RenderingFixtures::workflows()['routes'], RenderingFixtures::history(), RenderingFixtures::context()));

        self::assertSame('—', $parsed->sections['Parcours']);
        self::assertSame('—', $parsed->sections['Décisions']);
        self::assertStringContainsString('stateDiagram-v2', $parsed->sections['Navigation / états'], 'The state machine stays factual.');
    }

    /**
     * @param list<string> $mustNotAppear
     */
    #[DataProvider('trickyLabels')]
    public function testMermaidLabelsAreEscaped(string $label, array $mustNotAppear): void
    {
        $diagram = MermaidWriter::flowchart('TD', [$label, 'b'], [[0, 1, null]]);

        self::assertStringContainsString('n1["', $diagram);

        foreach ($mustNotAppear as $raw) {
            self::assertStringNotContainsString($raw, $diagram);
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function trickyLabels(): iterable
    {
        yield 'namespace and method' => ['App\Controller\X::new', []];
        yield 'route parameter' => ['/orders/{id}', []];
        yield 'bracket' => ['a[0]', []];
        yield 'double quote' => ['say "hi"', ['"hi"']];
        yield 'newline' => ["two\nlines", ["two\nlines"]];
    }

    public function testATableCellCannotBreakTheTable(): void
    {
        self::assertSame('| `GET\|POST /orders` | a\|b |', MarkdownWriter::row(['`GET|POST /orders`', 'a|b']));
        self::assertSame('``a`b``', MarkdownWriter::code('a`b'), 'A backtick inside code is fenced by a double backtick.');
    }

    public function testRenderingTwiceGivesTheSameBytes(): void
    {
        $renderer = new PageRenderer();

        foreach (RenderingFixtures::workflows() as $workflow) {
            self::assertSame(
                $renderer->render($workflow, RenderingFixtures::history(), RenderingFixtures::context()),
                $renderer->render($workflow, RenderingFixtures::history(), RenderingFixtures::context()),
            );
        }
    }

    public function testLinksToOtherWorkflowsAreRelative(): void
    {
        $page = new PageRenderer()->render(RenderingFixtures::workflows()['routes'], RenderingFixtures::history(), RenderingFixtures::context());

        self::assertStringContainsString('[`route.order.show`](order.show.md)', $page);
        self::assertStringContainsString('`route.order.index`', $page, 'A dependency without a page is named, not linked.');
        self::assertStringNotContainsString('](../events/', $page, 'There is no events type any more (ADR-0043).');
    }

    /**
     * ADR-0045: a page under `routes/<controller>/` links from where it lives — the page of another
     * controller is one directory up, the page of another type two.
     *
     * The link was written as if every page sat directly under its type: from `routes/item.qr/`, the page
     * of `route.item.show` was linked as `item.show.md`, where nothing is.
     */
    public function testLinksOfAGroupedPageAreRelativeToTheDirectoryItLivesIn(): void
    {
        $context = RenderingContext::of([
            self::inGroup('route.order.show', 'order'),
            self::inGroup('route.health.index', 'health'),
            RenderingFixtures::workflows()['commands'],
        ]);

        self::assertSame('show.md', $context->relativePage(WorkflowType::routes(), 'order', new WorkflowId('route.order.show')), 'The same controller: a neighbour.');
        self::assertSame('../health/index.md', $context->relativePage(WorkflowType::routes(), 'order', new WorkflowId('route.health.index')), 'Another controller: one level up.');
        self::assertSame('../../commands/app.import-catalog.md', $context->relativePage(WorkflowType::routes(), 'order', new WorkflowId('command.app.import-catalog')), 'Another type: two.');
        self::assertNull($context->relativePage(WorkflowType::routes(), 'order', new WorkflowId('route.order.absent')), 'An undocumented workflow is not linked.');

        $page = new PageRenderer()->render(
            self::inGroup('route.order.new', 'order', [new WorkflowId('route.health.index'), new WorkflowId('command.app.import-catalog')]),
            RenderingFixtures::history(),
            $context,
        );

        self::assertStringContainsString('](../health/index.md)', $page, 'The page itself writes the link from where it lives.');
        self::assertStringContainsString('](../../commands/app.import-catalog.md)', $page);
    }

    /**
     * @param list<WorkflowId> $dependsOn
     */
    private static function inGroup(string $id, string $directory, array $dependsOn = []): Workflow
    {
        $file = new FileRef('src/Controller/'.ucfirst($directory).'Controller.php', FileRole::Controller);

        return new Workflow(
            id: new WorkflowId($id),
            type: WorkflowType::routes(),
            title: $id,
            main: new EntryPoint('route', str_replace('.', '_', $id), $file),
            dependsOn: $dependsOn,
            group: new WorkflowGroup($directory, '/'.$directory, $file),
        );
    }

    public function testAWorkflowWithoutStatesOrNavigationSaysSo(): void
    {
        $workflow = new Workflow(new WorkflowId('command.app.purge'), WorkflowType::commands(), 'app:purge', new EntryPoint('command', 'app:purge', new FileRef('src/Command/PurgeCommand.php')));
        $parsed = new PageParser()->parse(new PageRenderer()->render($workflow, RenderingFixtures::history(), RenderingContext::of([$workflow])));

        self::assertSame('—', $parsed->sections['Navigation / états']);
        self::assertSame('—', $parsed->sections['Mécanismes transverses']);
    }
}
