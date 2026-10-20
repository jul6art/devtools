<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Scenario;

use Jul6Art\DevTools\Ai\DraftApplier;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\ScenariosGenerateCommand;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\InspectionReport;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rendering\MenuRenderer;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Scenario\ScenarioBrief;
use Jul6Art\DevTools\Scenario\ScenarioDraftApplier;
use Jul6Art\DevTools\Scenario\ScenarioGeneration;
use Jul6Art\DevTools\Scenario\ScenarioGenerator;
use Jul6Art\DevTools\Scenario\ScenarioOrigin;
use Jul6Art\DevTools\Scenario\ScenarioReview;
use Jul6Art\DevTools\Scenario\ScenariosUnavailable;
use Jul6Art\DevTools\Scenario\XmlScenarioBriefStore;
use Jul6Art\DevTools\Scenario\XmlScenarioIndexStore;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0020: scenarios generated for symfony-minimal inspected — skeletons from the facts, briefs for Claude,
 * drafts applied or refused — and what a rewrite of their workflow does to them.
 */
#[CoversClass(ScenarioGenerator::class)]
#[CoversClass(ScenarioGeneration::class)]
#[CoversClass(ScenariosUnavailable::class)]
#[CoversClass(ScenarioBrief::class)]
#[CoversClass(XmlScenarioBriefStore::class)]
#[CoversClass(ScenarioDraftApplier::class)]
#[CoversClass(ScenarioReview::class)]
#[CoversClass(ScenariosGenerateCommand::class)]
#[CoversClass(MenuRenderer::class)]
#[CoversClass(DraftApplier::class)]
final class ScenarioGenerationTest extends TestCase
{
    use AssertsSnapshots;
    use CopiesFixtureProjects;

    private const string DRAFT = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <scenarios xmlns="https://github.com/jul6art/devtools/schema/scenario/1" schema-version="1">
          <scenario id="route.order.show/by-id" workflow="route.order.show">
            <given>
              <login role="ROLE_USER"/>
              <fixture name="one-order"/>
            </given>
            <steps>
              <goto route="app_order_show"><param name="id" value="1"/></goto>
              <assert-route name="ROUTE"/>
              <assert-text text="Order #1"/>
            </steps>
          </scenario>
        </scenarios>
        XML;

    private string $project;

    private DevToolsDirectory $directory;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        $this->inspect('2026-09-16T15:00:00+02:00');
        $this->directory = DevToolsDirectory::documented(new ProjectRoot($this->project));
    }

    /**
     * Without AI: one skeleton per route that answers GET without a parameter, as the role its security asks
     * for — and the others named, never silently left out.
     */
    public function testWithoutAiEachGetRouteWithoutParameterGetsItsSkeleton(): void
    {
        $result = $this->generate(noAi: true);

        self::assertSame(['route.health/smoke', 'route.order.index/smoke', 'route.order.new/smoke'], $result->written);
        self::assertSame(['route.customer.edit', 'route.order.show', 'route.order.validate', 'ui.cart-summary'], $result->skipped);
        self::assertSame([], $result->briefs);
        self::assertSame([], glob($this->project.'/.devtools/pending/scenario.*') ?: []);

        $files = '';

        foreach (['route.health/smoke', 'route.order.index/smoke', 'route.order.new/smoke', 'index'] as $file) {
            $files .= '<!-- .devtools/scenarios/'.$file.".xml -->\n".file_get_contents($this->project.'/.devtools/scenarios/'.$file.'.xml');
        }

        self::assertMatchesSnapshot($files, __DIR__.'/../Fixtures/snapshots/scenarios/skeletons.xml');
    }

    public function testGeneratingTwiceWritesNothingTheSecondTime(): void
    {
        $this->generate(noAi: true);
        $index = (string) file_get_contents($this->directory->scenarioIndexFile());

        self::assertSame([], $this->generate(noAi: true)->written);
        self::assertStringEqualsFile($this->directory->scenarioIndexFile(), $index);
    }

    /**
     * With AI, a brief for every route and component without a scenario of its own — the skeleton does not
     * count — that says what Claude reads and where it writes.
     */
    public function testWithAiEveryRouteAndComponentGetsABrief(): void
    {
        $result = $this->generate();

        self::assertSame(['route.health/smoke', 'route.order.index/smoke', 'route.order.new/smoke'], $result->written, 'The skeletons too: replayable at once.');
        self::assertSame(['route.customer.edit', 'route.health', 'route.order.index', 'route.order.new', 'route.order.show', 'route.order.validate', 'ui.cart-summary'], $result->briefs);

        $brief = new XmlScenarioBriefStore()->read($this->project.'/.devtools/pending/scenario.route.order.new.brief.xml');

        self::assertSame('route.order.new', $brief->workflow->value);
        self::assertSame('2026-09-16T15:00:00+02:00', $brief->revision->format(\DATE_ATOM));
        self::assertSame(Resources::path('prompts/scenario/v1.md'), $brief->promptPath);
        self::assertFileExists($brief->promptPath);
        self::assertSame(Resources::path('schemas/scenario.xsd'), $brief->vocabularyPath);
        self::assertStringStartsWith('The workflow has only the skeleton', $brief->reason);
        self::assertSame('docs/workflows/routes/order.new.md', $brief->pagePath);
        self::assertSame('.devtools/workflows/routes/order.new.xml', $brief->trackingPath);
        self::assertSame('.devtools/knowledge/symfony-8.md', $brief->knowledgePath);
        self::assertSame([['name' => 'app_order_new', 'path' => '/orders/new', 'methods' => 'GET|POST']], $brief->routes);
        self::assertSame('ROLE_OPERATOR', $brief->login);
        self::assertSame([['path' => '.devtools/scenarios/route.order.new/smoke.xml', 'origin' => 'skeleton']], $brief->scenarios);
        self::assertSame('.devtools/pending/scenario.route.order.new.draft.xml', $brief->draftPath);
        self::assertSame('The workflow has no scenario yet.', new XmlScenarioBriefStore()->read($this->project.'/.devtools/pending/scenario.route.order.show.brief.xml')->reason);

        self::assertSame(['route.order.new'], $this->generate(only: 'route.order.new')->briefs, 'One workflow when asked.');
    }

    public function testADraftIsAppliedAndTheBriefAnsweredGoesWithIt(): void
    {
        $this->generate(only: 'route.order.show');
        $this->draft('route.order.show', str_replace('ROUTE', 'app_order_show', self::DRAFT));

        $result = new DraftApplier()->apply(new ProjectRoot($this->project));

        self::assertSame(['scenario.route.order.show'], $result->accepted);
        self::assertSame([], $result->refused);
        self::assertFileExists($this->project.'/.devtools/scenarios/route.order.show/by-id.xml');
        self::assertSame([], glob($this->project.'/.devtools/pending/scenario.*') ?: []);
        self::assertSame(ScenarioOrigin::Claude, new XmlScenarioIndexStore()->read($this->directory->scenarioIndexFile())->entry('route.order.show/by-id')?->origin);
        self::assertSame([], $this->generate(only: 'route.order.show')->briefs, 'Answered: no brief any more.');
    }

    /**
     * A draft is refused whole, with every rule it breaks, and nothing of it is written.
     */
    public function testADraftNamingARouteTheProjectDoesNotHaveIsRefused(): void
    {
        $this->generate(only: 'route.order.show');
        $this->draft('route.order.show', str_replace('ROUTE', 'app_order_shown', self::DRAFT));

        $result = new DraftApplier()->apply(new ProjectRoot($this->project));

        self::assertSame(['scenario.route.order.show' => ['"route.order.show/by-id" names the route "app_order_shown", which this project does not have.']], $result->refused);
        self::assertFileDoesNotExist($this->project.'/.devtools/scenarios/route.order.show/by-id.xml');
        self::assertFileExists($this->project.'/.devtools/pending/scenario.route.order.show.draft.xml', 'Left to be corrected.');
    }

    public function testADraftForAnotherWorkflowOrWithoutBriefIsRefused(): void
    {
        $this->generate(only: 'route.order.new');
        $this->draft('route.order.new', str_replace('ROUTE', 'app_order_show', self::DRAFT));
        $this->draft('route.health', str_replace('ROUTE', 'app_order_show', self::DRAFT));

        $refused = new DraftApplier()->apply(new ProjectRoot($this->project))->refused;

        self::assertSame(['"route.order.show/by-id" belongs to another workflow: this draft writes the scenarios of route.order.new only.'], $refused['scenario.route.order.new']);
        self::assertSame(['The draft has no brief: run scenarios:generate for one.'], $refused['scenario.route.health']);
    }

    public function testADraftThatIsNotAScenarioIsRefusedWithTheLine(): void
    {
        $this->generate(only: 'route.order.show');
        $this->draft('route.order.show', str_replace('<assert-text', '<assert-smell', str_replace('ROUTE', 'app_order_show', self::DRAFT)));

        self::assertStringContainsString('assert-smell', implode('', new DraftApplier()->apply(new ProjectRoot($this->project))->refused['scenario.route.order.show']));
    }

    /**
     * A scenario a human wrote or modified is theirs: never regenerated, never overwritten by a draft.
     */
    public function testAScenarioModifiedByHandIsNeverRegenerated(): void
    {
        $this->generate();
        $smoke = $this->project.'/.devtools/scenarios/route.health/smoke.xml';
        file_put_contents($smoke, str_replace('<screenshot name="health"/>', "<assert-text text=\"OK\"/>\n    <screenshot name=\"health\"/>", (string) file_get_contents($smoke)));
        $mine = (string) file_get_contents($smoke);

        // Modified since DevTools recorded it, before any generate saw it: a draft still cannot overwrite it.
        $this->draft('route.health', '<scenarios xmlns="https://github.com/jul6art/devtools/schema/scenario/1" schema-version="1"><scenario id="route.health/smoke" workflow="route.health"><steps><goto route="app_health"/></steps></scenario></scenarios>');

        self::assertSame(['"route.health/smoke" was written or modified by hand: DevTools never rewrites it. Give the scenario another name.'], new DraftApplier()->apply(new ProjectRoot($this->project))->refused['scenario.route.health']);
        self::assertStringEqualsFile($smoke, $mine);

        $this->rewriteHealth();
        $result = $this->generate();

        self::assertStringEqualsFile($smoke, $mine);
        self::assertNotContains('route.health/smoke', $result->written);
        self::assertSame('modified by hand, never regenerated: review it, then scenarios:generate --reviewed=route.health/smoke', $result->kept['route.health/smoke']);
        self::assertSame(ScenarioOrigin::Human, new XmlScenarioIndexStore()->read($this->directory->scenarioIndexFile())->entry('route.health/smoke')?->origin);
        self::assertNotContains('route.health', $result->briefs, 'A scenario of its own: the human reviews it, not Claude.');
        self::assertFileDoesNotExist($this->project.'/.devtools/pending/scenario.route.health.brief.xml', 'A brief nobody needs any more is removed.');
    }

    /**
     * A rewritten workflow puts its scenarios to review in the menu — listed, not deleted. A skeleton is
     * written again from the new facts; a scenario reviewed by hand leaves the list.
     */
    public function testARewrittenWorkflowPutsItsScenariosToReview(): void
    {
        $this->generate(noAi: true);
        $this->rewriteHealth();

        $menu = (string) file_get_contents($this->directory->menuFile());
        self::assertStringContainsString('- ⚠ scenario [`route.health/smoke`](../../.devtools/scenarios/route.health/smoke.xml) — to review: route.health was rewritten', $menu);
        self::assertFileExists($this->project.'/.devtools/scenarios/route.health/smoke.xml');

        self::assertSame(['route.health/smoke'], $this->generate(noAi: true)->written, 'The skeleton follows its facts.');
        self::assertSame([], $this->attention());

        $this->rewriteHealth('2026-09-18T09:00:00+02:00');
        self::assertSame(['route.health/smoke' => 'to review: route.health was rewritten'], $this->attention());

        $reviewed = $this->generate(review: ['route.health/smoke']);

        self::assertSame(['route.health/smoke'], $reviewed->reviewed);
        self::assertSame([], $reviewed->written, 'Reviewing writes nothing but the index.');
        self::assertSame([], $this->attention());
        self::assertSame(ScenarioOrigin::Human, new XmlScenarioIndexStore()->read($this->directory->scenarioIndexFile())->entry('route.health/smoke')?->origin, 'Reviewed: yours from now on.');
        self::assertSame(['route.gone/smoke' => 'no such scenario in .devtools/scenarios/.'], $this->generate(review: ['route.gone/smoke'])->errors);
    }

    /**
     * A scenario of a workflow the project no longer has is signalled by the inspection, and in the menu.
     */
    public function testAScenarioWhoseWorkflowIsGoneIsSignalled(): void
    {
        mkdir($this->project.'/.devtools/scenarios/route.gone', 0o777, true);
        file_put_contents($this->project.'/.devtools/scenarios/route.gone/smoke.xml', '<scenario xmlns="https://github.com/jul6art/devtools/schema/scenario/1" schema-version="1" id="route.gone/smoke" workflow="route.gone"><steps><goto route="app_gone"/></steps></scenario>');

        $this->generate(noAi: true);
        self::assertSame(ScenarioOrigin::Human, new XmlScenarioIndexStore()->read($this->directory->scenarioIndexFile())->entry('route.gone/smoke')?->origin, 'Written by hand: recorded as such.');

        $report = $this->inspect('2026-09-17T09:00:00+02:00');

        self::assertContains('The scenario route.gone/smoke is orphaned: route.gone is no longer documented.', $report->warnings);
        self::assertStringContainsString('route.gone/smoke`](../../.devtools/scenarios/route.gone/smoke.xml) — orphaned: route.gone is no longer documented', (string) file_get_contents($this->directory->menuFile()));
    }

    public function testAnInvalidOrMisplacedScenarioIsAnErrorTheOthersStillGenerate(): void
    {
        mkdir($this->project.'/.devtools/scenarios/route.health', 0o777, true);
        file_put_contents($this->project.'/.devtools/scenarios/route.health/broken.xml', '<scenario');
        file_put_contents($this->project.'/.devtools/scenarios/route.health/elsewhere.xml', '<scenario xmlns="https://github.com/jul6art/devtools/schema/scenario/1" schema-version="1" id="route.health/smoke" workflow="route.health"><steps><goto route="app_health"/></steps></scenario>');

        $result = $this->generate(noAi: true);

        self::assertSame(['.devtools/scenarios/route.health/broken.xml', '.devtools/scenarios/route.health/elsewhere.xml'], array_keys($result->errors));
        self::assertStringContainsString('belongs in .devtools/scenarios/route.health/smoke.xml', $result->errors['.devtools/scenarios/route.health/elsewhere.xml']);
        self::assertContains('route.order.index/smoke', $result->written);
    }

    public function testAScenarioDeletedLeavesTheIndex(): void
    {
        $this->generate(noAi: true);
        unlink($this->project.'/.devtools/scenarios/route.order.index/smoke.xml');

        $this->generate(only: 'route.health', noAi: true);

        self::assertNull(new XmlScenarioIndexStore()->read($this->directory->scenarioIndexFile())->entry('route.order.index/smoke'));
    }

    public function testOnlyRoutesAndComponentsHaveScenarios(): void
    {
        $this->expectException(ScenariosUnavailable::class);
        $this->expectExceptionMessage('"command.app.import-catalog" is not a route or a component documented in this project');

        $this->generate(only: 'command.app.import-catalog');
    }

    public function testTheCommandSaysWhatItWroteAndWhatItLeftToClaude(): void
    {
        $tester = new CommandTester(new ScenariosGenerateCommand());

        self::assertSame(0, $tester->execute(['--path' => $this->project]));
        self::assertStringContainsString('✓ route.health/smoke', $tester->getDisplay());
        self::assertStringContainsString('→ route.order.show: brief for Claude in .devtools/pending/', $tester->getDisplay());
        self::assertStringContainsString('then workflows:apply', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['--path' => $this->project, '--no-ai' => true]));
        self::assertStringContainsString('No skeleton for route.customer.edit, route.order.show', $tester->getDisplay());

        self::assertSame(2, $tester->execute(['workflow' => 'Not An Id', '--path' => $this->project]));
        self::assertSame(1, $tester->execute(['workflow' => 'command.app.import-catalog', '--path' => $this->project]));
        self::assertSame(2, $tester->execute(['--path' => $this->project.'/nowhere']));

        $empty = $this->copyFixtureProject('plain-php');
        self::assertSame(1, $tester->execute(['--path' => $empty]));
        self::assertStringContainsString('never inspected', $tester->getDisplay());
    }

    private function inspect(string $at): InspectionReport
    {
        $report = new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), new FrozenClock(new \DateTimeImmutable($at)))->run(new InspectionOptions($this->project, noAi: true));
        self::assertSame([], $report->errors, implode("\n", $report->errors));

        return $report;
    }

    /**
     * The health controller changed, and the inspection that follows rewrites its workflow.
     */
    private function rewriteHealth(string $at = '2026-09-17T09:00:00+02:00'): void
    {
        file_put_contents($this->project.'/src/Controller/HealthController.php', "\n// ".$at."\n", \FILE_APPEND);
        $this->inspect($at);
    }

    /**
     * @param list<string> $review
     */
    private function generate(?string $only = null, bool $noAi = false, array $review = []): ScenarioGeneration
    {
        return new ScenarioGenerator()->generate($this->directory, new WorkflowTypeRegistry(), null === $only ? null : new WorkflowId($only), $noAi, $review);
    }

    private function draft(string $workflow, string $xml): void
    {
        file_put_contents($this->project.'/.devtools/pending/scenario.'.$workflow.'.draft.xml', $xml);
    }

    /**
     * @return array<string, string>
     */
    private function attention(): array
    {
        return ScenarioReview::attention(new XmlScenarioIndexStore()->read($this->directory->scenarioIndexFile()), new XmlIndexStore(new WorkflowTypeRegistry())->read($this->directory->indexFile()));
    }
}
