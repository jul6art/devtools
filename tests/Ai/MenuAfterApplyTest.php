<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Ai;

use Jul6Art\DevTools\Ai\ApplyResult;
use Jul6Art\DevTools\Ai\DraftApplier;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rendering\MenuRenderer;
use Jul6Art\DevTools\Tests\Inspection\Pipeline\FakeAdapter;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ADR-0049: `workflows:apply` is what makes the briefs disappear, so it rewrites the menu that lists them —
 * from what is already on disk, without analysing anything again.
 *
 * Measured before, on a real project: 951 pages written and applied, `workflows:check` green, and a menu
 * still asking for the 951 to be written until the next inspection.
 */
#[CoversClass(DraftApplier::class)]
#[CoversClass(MenuRenderer::class)]
final class MenuAfterApplyTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string DRAFT = __DIR__.'/../Fixtures/drafts/symfony-minimal/page.route.order.new.draft.md';

    private const string WAITING_ROUTE = '[`route.order.new`](routes/order.new.md) — waiting for Claude';

    private const string WAITING_COMMAND = '[`command.app.import-catalog`](commands/app.import-catalog.md) — waiting for Claude';

    /**
     * Two workflows are enough: the route whose draft exists, and a command nobody writes.
     */
    private const array LEFT_OUT = ['app_order_index', 'app_order_show', 'app_status', 'app_health'];

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
    }

    public function testAnAppliedDraftLeavesTheListOfWhatIsWaitingAndTheOtherStays(): void
    {
        $this->inspect();
        $before = $this->menu();
        self::assertStringContainsString(self::WAITING_ROUTE, $before);
        self::assertStringContainsString(self::WAITING_COMMAND, $before);

        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');
        self::assertSame(['route.order.new'], $this->apply()->accepted);

        $after = $this->menu();
        self::assertStringNotContainsString(self::WAITING_ROUTE, $after);
        self::assertStringContainsString(self::WAITING_COMMAND, $after, 'apply does not clear what has not been written.');
        self::assertStringContainsString('[/orders/new](routes/order.new.md) — `route.order.new`', $after, 'The written workflow is still listed under its type.');
    }

    public function testTheNotCoveredSectionIsCopiedByteForByte(): void
    {
        $this->inspect();
        $before = self::notCovered($this->menu());
        self::assertStringContainsString('no workflow references this file', $before, 'The fixture leaves files uncovered, or the copy would prove nothing.');

        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');
        self::assertSame(['route.order.new'], $this->apply()->accepted);

        self::assertSame($before, self::notCovered($this->menu()));
    }

    /**
     * The menu is edited by hand first: rendering it again would give it back as the inspection wrote it, so
     * a rewrite shows even when there is nothing new to say — and an apply that refuses everything must not
     * have written.
     */
    public function testAnApplyThatAcceptsNothingChangesNoFile(): void
    {
        $this->inspect();
        file_put_contents($this->project.'/docs/workflows/workflows.md', "\n<!-- edited by hand -->\n", \FILE_APPEND);
        $tree = self::documentedTree($this->project);

        self::assertSame([], $this->apply()->accepted, 'No draft at all.');
        self::assertSame($tree, self::documentedTree($this->project));

        file_put_contents($this->project.'/.devtools/pending/page.route.order.new.draft.md', str_replace('`src/Repository/OrderRepository.php`', '`src/Repository/InvoiceRepository.php`', (string) file_get_contents(self::DRAFT)));
        $result = $this->apply();

        self::assertSame([], $result->accepted, 'A refused draft only.');
        self::assertArrayHasKey('route.order.new', $result->refused);
        self::assertSame($tree, self::documentedTree($this->project));
    }

    public function testAProjectWithoutAMenuGetsNone(): void
    {
        $this->inspect();
        unlink($this->project.'/docs/workflows/workflows.md');
        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');

        self::assertSame(['route.order.new'], $this->apply()->accepted);
        self::assertFileDoesNotExist($this->project.'/docs/workflows/workflows.md', 'There is nothing to correct before the first inspection.');
    }

    /**
     * The applier has neither a git client nor a hasher whose calls a test could count: the proof that it
     * walks no file and launches no process is that the code can be gone. A rebuild that analysed anything
     * would fail, or render another menu — no workflow, other files uncovered.
     */
    public function testTheMenuIsRebuiltFromDiskWithoutAnalysingAnything(): void
    {
        $this->inspect();
        $before = $this->menu();
        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');
        new Filesystem()->remove([$this->project.'/src', $this->project.'/config', $this->project.'/templates', $this->project.'/tests']);

        self::assertSame(['route.order.new'], $this->apply()->accepted);

        $after = $this->menu();
        self::assertSame(str_replace('- ⚠ '.self::WAITING_ROUTE."\n", '', $before), $after, 'The one line of the written workflow is gone; every other byte stays.');
    }

    /**
     * Two code paths render the menu now. The day the rendering changes, both must move together — this is
     * the test that says so.
     */
    public function testAnInspectionRightAfterApplyRendersTheSameMenu(): void
    {
        $this->inspect();
        copy(self::DRAFT, $this->project.'/.devtools/pending/page.route.order.new.draft.md');
        self::assertSame(['route.order.new'], $this->apply()->accepted);
        $applied = $this->menu();

        $this->inspect();

        self::assertSame($applied, $this->menu());
    }

    private function inspect(): void
    {
        $report = new InspectionPipeline(new AdapterResolver([new FakeAdapter(self::LEFT_OUT)]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($this->project));
        self::assertSame([], $report->errors, implode("\n", $report->errors));
    }

    private function apply(): ApplyResult
    {
        return new DraftApplier(new FrozenClock(new \DateTimeImmutable('2026-09-16T15:30:00+02:00')))->apply(new ProjectRoot($this->project));
    }

    private function menu(): string
    {
        return (string) file_get_contents($this->project.'/docs/workflows/workflows.md');
    }

    private static function notCovered(string $menu): string
    {
        $section = strstr($menu, "## Not covered\n");
        self::assertIsString($section, 'The menu always carries the section, at zero too.');

        return $section;
    }
}
