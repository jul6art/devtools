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
use Jul6Art\DevTools\Tracking\IndexGroup;
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

        foreach (['## Routes (1)', '## Commands (1)'] as $heading) {
            self::assertStringContainsString($heading, $menu);
        }

        // A type with no workflow does not take an empty section any more (ADR-0043); it is still named,
        // so "none found" stays distinguishable from "not looked for".
        self::assertStringNotContainsString('## Async', $menu);
        self::assertStringContainsString('No workflow found for: Async, UI, Integrations, Data.', $menu);

        self::assertStringContainsString('2 workflows', $menu);
    }

    public function testWhatNeedsAttentionAndWhatIsUncoveredAreListedInOrder(): void
    {
        $menu = $this->menu();

        self::assertStringContainsString("## To check\n\n- ⚠ [`command.app.import-catalog`](commands/app.import-catalog.md) — waiting for Claude\n- ⚠ [`route.order.new`](routes/order.new.md) — stale", $menu);
        self::assertStringContainsString("## Not covered\n\n- `src/A.php` — no workflow references this file\n- `src/Util/StringHelper.php`", $menu);
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

    /**
     * ADR-0045: when routes are grouped by controller, the menu lists the controllers — one line each, with
     * how many routes they hold — and the routes are listed on the group's own page.
     */
    public function testGroupedRoutesAreListedAsControllers(): void
    {
        $entries = [
            self::entry('route.admin.user.index', '/admin/users', 'admin.user'),
            self::entry('route.admin.user.edit', '/admin/users/{id}/edit', 'admin.user'),
            self::entry('route.health', '/health', null),
        ];

        $menu = new MenuRenderer()->render(
            new StackDocument('acme/shop', [new StackProfile('.', 'php', 'symfony', '8.1', 'composer', ['src'], [], [], 'symfony', 'symfony-8')]),
            new Index(new \DateTimeImmutable('2026-09-16T15:00:00+02:00'), new VcsState(null), $entries, 'docs/workflows', [new IndexGroup(WorkflowType::routes(), 'admin.user', '/admin/users')]),
            [],
        );

        self::assertStringContainsString('## Routes (3)', $menu, 'The count is of workflows, never of groups.');
        self::assertStringContainsString('- [/admin/users](routes/admin.user/README.md) — `admin.user` · 2 routes · updated 2026-09-16', $menu);
        self::assertStringNotContainsString('routes/admin.user/edit.md', $menu, 'A grouped route is listed on the page of its group.');
        self::assertStringContainsString('- [/health](routes/health.md)', $menu, 'A workflow outside any group keeps its line.');
    }

    private static function entry(string $id, string $title, ?string $group): IndexEntry
    {
        return new IndexEntry(
            new WorkflowId($id),
            WorkflowType::routes(),
            $title,
            TrackingStatus::Fresh,
            Confidence::High,
            GenerationMode::NoAi,
            new \DateTimeImmutable('2026-09-16T15:00:00+02:00'),
            $group,
        );
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
