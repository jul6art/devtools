<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Rendering;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Rendering\GraphRenderer;
use Jul6Art\DevTools\Rendering\MenuRenderer;
use Jul6Art\DevTools\Stack\StackDocument;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Tracking\TrackingFixtures;
use Jul6Art\DevTools\Tracking\GenerationMode;
use Jul6Art\DevTools\Tracking\Index;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\TrackingStatus;
use Jul6Art\DevTools\Tracking\VcsState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MenuRenderer::class)]
#[CoversClass(GraphRenderer::class)]
final class MenuRendererTest extends TestCase
{
    use AssertsSnapshots;

    public function testTheMenuMatchesItsSnapshot(): void
    {
        self::assertMatchesSnapshot($this->menu(), __DIR__.'/Fixtures/workflows.md');
    }

    public function testEverySubmenuIsPresentWithItsCountEvenAtZero(): void
    {
        $menu = $this->menu();

        foreach (['## Routes (1)', '## Commandes (1)', '## Asynchrone (0)', '## Événements (0)', '## Interface (0)', '## Intégrations (0)', '## Données (0)'] as $heading) {
            self::assertStringContainsString($heading, $menu);
        }

        self::assertStringContainsString('2 workflows', $menu);
    }

    public function testWhatNeedsAttentionAndWhatIsUncoveredAreListedInOrder(): void
    {
        $menu = $this->menu();

        self::assertStringContainsString("## À vérifier\n\n- ⚠ [`command.app.import-catalog`](commands/app.import-catalog.md) — rédaction en attente\n- ⚠ [`route.order.new`](routes/order.new.md) — périmé", $menu);
        self::assertStringContainsString("## Non couvert\n\n- `src/A.php` — aucun workflow ne référence ce fichier\n- `src/Util/StringHelper.php`", $menu);
    }

    public function testTheOverviewGroupsWorkflowsByTypeWithTheirDependencies(): void
    {
        $graph = new GraphRenderer()->render(array_values(RenderingFixtures::workflows()));

        self::assertStringStartsWith("flowchart LR\n", $graph);
        self::assertStringContainsString('subgraph routes["Routes"]', $graph);
        self::assertMatchesSnapshot($graph, __DIR__.'/Fixtures/workflows.mermaid');
        self::assertSame($graph, new GraphRenderer()->render(array_reverse(array_values(RenderingFixtures::workflows()))), 'Order-independent.');
    }

    /**
     * Fifty resources under one heading is a list nobody scrolls: past a dozen, the menu gains a third
     * level, one sub-heading per family (found on a 267-workflow project).
     */
    public function testPastADozenEntriesOfOneTypeTheMenuGainsAThirdLevel(): void
    {
        $entries = [];

        foreach (['admin', 'portal'] as $family) {
            for ($i = 0; $i < 7; ++$i) {
                $entries[] = new IndexEntry(
                    new WorkflowId(\sprintf('route.%s.resource%d', $family, $i)),
                    WorkflowType::routes(),
                    \sprintf('/%s/resource%d', $family, $i),
                    TrackingStatus::Fresh,
                    Confidence::High,
                    GenerationMode::NoAi,
                    new \DateTimeImmutable('2026-09-16T15:00:00+02:00'),
                );
            }
        }

        $menu = new MenuRenderer()->render(
            new StackDocument('acme/shop', [new StackProfile('.', 'php', 'symfony', '8.1', 'composer', ['src'], [], [], 'symfony', 'symfony-8')]),
            new Index(new \DateTimeImmutable('2026-09-16T15:00:00+02:00'), new VcsState(null), $entries),
            [],
        );

        self::assertStringContainsString('## Routes (14)', $menu);
        self::assertStringContainsString('### admin (7)', $menu);
        self::assertStringContainsString('### portal (7)', $menu);
        self::assertStringNotContainsString('### ', $this->menu(), 'Two entries stay a flat list.');
    }

    private function menu(): string
    {
        $stacks = new StackDocument('acme/shop', [
            new StackProfile('.', 'php', 'symfony', '8.1', 'composer', ['src'], [], [], 'symfony', 'symfony-8'),
        ]);
        $index = Index::fromTracking(
            new \DateTimeImmutable('2026-09-16T15:00:00+02:00'),
            new VcsState('a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', 'main'),
            [TrackingFixtures::orderNew(TrackingStatus::Stale), TrackingFixtures::importCatalog()],
        );

        return new MenuRenderer()->render($stacks, $index, [new FileRef('src/Util/StringHelper.php'), new FileRef('src/A.php')], [new WorkflowId('command.app.import-catalog')]);
    }
}
