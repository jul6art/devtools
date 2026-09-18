<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Review;

use Jul6Art\DevTools\Ai\XmlPageBriefStore;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\WorkflowsAcceptCommand;
use Jul6Art\DevTools\Command\WorkflowsRejectCommand;
use Jul6Art\DevTools\Command\WorkflowsReviewCommand;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\Diff\ChangeGroup;
use Jul6Art\DevTools\Inspection\Diff\ChangeNature;
use Jul6Art\DevTools\Inspection\Diff\ChangeSubject;
use Jul6Art\DevTools\Inspection\Diff\WorkflowChange;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\InspectionReport;
use Jul6Art\DevTools\Review\ChangeReview;
use Jul6Art\DevTools\Tests\Inspection\Pipeline\FakeAdapter;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0047: accepting writes the tracking files; refusing writes nothing and says what to undo.
 */
#[CoversClass(ChangeReview::class)]
#[CoversClass(WorkflowsAcceptCommand::class)]
#[CoversClass(WorkflowsRejectCommand::class)]
#[CoversClass(WorkflowsReviewCommand::class)]
final class ChangeReviewTest extends TestCase
{
    use CopiesFixtureProjects;

    public function testAcceptingRewritesThePagesThatCarryTheFactAndNothingElse(): void
    {
        $project = $this->inspected();
        self::rename($project, "'quoted'", "'estimated'");

        $untouched = (string) file_get_contents($project.'/docs/workflows/routes/health.md');
        $tester = new CommandTester(new WorkflowsAcceptCommand(self::review()));

        self::assertSame(0, $tester->execute(['target' => ['App\Entity\Order::status'], '--path' => $project]));
        self::assertStringContainsString('App\Entity\Order::status', $tester->getDisplay());
        self::assertSame($untouched, (string) file_get_contents($project.'/docs/workflows/routes/health.md'), 'A workflow that does not carry the fact is left alone.');
    }

    /**
     * ⚠️ The guarantee of `accept`, and the one a first version did not keep: a workflow whose own code
     * changed but whose fact nobody accepted is **not** rewritten. Found on a real project, where
     * accepting one route's security rewrote the four others of the same controller.
     */
    public function testAWorkflowWhoseFactWasNotAcceptedIsNotRewritten(): void
    {
        $project = $this->inspected();
        // Two facts in two different workflows: a decision only `order.new` reaches, and the priority of
        // a listener that every route carries.
        self::rename($project, "'quoted'", "'estimated'");
        $listener = $project.'/src/EventListener/LocaleListener.php';
        file_put_contents($listener, str_replace('priority: 20', 'priority: 5', (string) file_get_contents($listener)));

        $before = (string) file_get_contents($project.'/docs/workflows/routes/order.index.md');

        new CommandTester(new WorkflowsAcceptCommand(self::review()))->execute(['target' => ['App\Entity\Order::status'], '--path' => $project, '--no-ai' => true]);

        self::assertStringContainsString('modified decision App\Entity\Order::status', (string) file_get_contents($project.'/docs/workflows/routes/order.new.md'), 'The accepted fact is written.');
        self::assertSame($before, (string) file_get_contents($project.'/docs/workflows/routes/order.index.md'), 'The page of a workflow whose own fact changed, and that nobody accepted, does not move.');
    }

    /**
     * The same guarantee, stated on the pipeline itself: what `restrictTo` leaves out is not written,
     * even when the freshness has every reason to rewrite it.
     */
    public function testRestrictToLeavesEveryOtherWorkflowExactlyAsItIs(): void
    {
        $project = $this->inspected();
        $before = self::snapshotOf($project);

        self::pipeline()->run(new InspectionOptions($project, forceAll: true, noAi: true, restrictTo: ['route.order.new']));

        $after = self::snapshotOf($project);
        $changed = array_keys(array_diff_assoc($after, $before));

        self::assertSame(
            ['order.new.md', 'order.new.xml'],
            self::sorted(array_map(basename(...), array_filter($changed, static fn (string $file): bool => !str_contains($file, '/reports/') && !str_ends_with($file, 'index.xml') && !str_contains($file, '/graph/')))),
            'Everything was stale; only the page and the tracking of the named workflow moved.',
        );
    }

    /**
     * The history of a page says what changed in the workflow, not which file was touched: a file holds
     * twenty things, a fact holds one.
     */
    public function testTheHistoryOfTheRewrittenPageNamesTheFact(): void
    {
        $project = $this->inspected();
        self::rename($project, "'quoted'", "'estimated'");

        new CommandTester(new WorkflowsAcceptCommand(self::review()))->execute(['target' => ['App\Entity\Order::status'], '--path' => $project]);

        self::assertStringContainsString('modified decision App\Entity\Order::status', (string) file_get_contents($project.'/docs/workflows/routes/order.new.md'));
    }

    public function testTheBriefCarriesTheFactsSoClaudeKnowsWhereToLook(): void
    {
        $project = $this->inspected();
        self::rename($project, "'quoted'", "'estimated'");

        new CommandTester(new WorkflowsAcceptCommand(self::review()))->execute(['target' => ['App\Entity\Order::status'], '--path' => $project]);

        $brief = new XmlPageBriefStore()->read($project.'/.devtools/pending/page.route.order.new.brief.xml');

        self::assertNotSame([], $brief->facts);
        self::assertStringContainsString("modified decision App\\Entity\\Order::status: 'quoted' → 'estimated'", implode("\n", $brief->facts));
    }

    public function testAnUnknownTargetIsRefusedWithTheListOfWhatChanged(): void
    {
        $project = $this->inspected();
        self::rename($project, "'quoted'", "'estimated'");

        $tester = new CommandTester(new WorkflowsAcceptCommand(self::review()));

        self::assertSame(2, $tester->execute(['target' => ['App\Entity\Nothing::here'], '--path' => $project]));
        self::assertStringContainsString('App\Entity\Order::status', $tester->getDisplay(), 'It says what it could have accepted.');
    }

    public function testRefusingWritesNothingAndPrintsTheRestore(): void
    {
        $project = $this->repository();
        self::rename($project, "'quoted'", "'estimated'");

        $before = self::snapshotOf($project);
        $tester = new CommandTester(new WorkflowsRejectCommand(self::review()));

        self::assertSame(0, $tester->execute(['target' => ['App\Entity\Order::status'], '--path' => $project]));
        self::assertStringContainsString('git restore --source=', $tester->getDisplay());
        self::assertStringContainsString('src/Service/OrderPricing.php', $tester->getDisplay());
        self::assertSame($before, self::snapshotOf($project), 'Refusing touches neither the documentation nor the code.');
    }

    public function testRestoreBringsTheCodeBack(): void
    {
        $project = $this->repository();
        self::rename($project, "'quoted'", "'estimated'");

        $tester = new CommandTester(new WorkflowsRejectCommand(self::review()));

        self::assertSame(0, $tester->execute(['target' => ['App\Entity\Order::status'], '--path' => $project, '--restore' => true]));
        self::assertStringContainsString('ramené', $tester->getDisplay());
        self::assertStringContainsString("'quoted'", (string) file_get_contents($project.'/src/Service/OrderPricing.php'));
    }

    /**
     * ⚠️ Restoring a file would undo every change it carries: a fact nobody refused must not disappear
     * with the one that was.
     */
    public function testAFileCarryingAnotherChangedFactIsNotRestored(): void
    {
        $project = $this->repository();
        self::rename($project, "'quoted'", "'estimated'");
        // A second fact, on another target of the same file: Order::currency.
        self::rename($project, "'CHF'", "'CHF '");

        $tester = new CommandTester(new WorkflowsRejectCommand(self::review()));
        $tester->execute(['target' => ['App\Entity\Order::status'], '--path' => $project, '--restore' => true]);

        self::assertStringContainsString("'estimated'", (string) file_get_contents($project.'/src/Service/OrderPricing.php'), 'Nothing was undone.');
    }

    public function testReviewAcceptsFactByFact(): void
    {
        $project = $this->repository();
        self::rename($project, "'quoted'", "'estimated'");

        $tester = new CommandTester(new WorkflowsReviewCommand(self::review()));
        $tester->setInputs(['a']);

        self::assertSame(0, $tester->execute(['--path' => $project]));
        self::assertStringContainsString('accepté', $tester->getDisplay());
        self::assertStringContainsString('modified decision App\Entity\Order::status', (string) file_get_contents($project.'/docs/workflows/routes/order.new.md'));
    }

    /**
     * ⚠️ Accepting two facts is one inspection, not two: a second pass would add a second revision to
     * the same page for the same review.
     */
    public function testAcceptingSeveralFactsRewritesEachPageOnce(): void
    {
        $project = $this->repository();
        self::rename($project, "'quoted'", "'estimated'");
        self::rename($project, "'CHF'", "'CHF '");

        $tester = new CommandTester(new WorkflowsReviewCommand(self::review()));
        $tester->setInputs(['a', 'a']);
        $tester->execute(['--path' => $project]);

        $history = substr_count((string) file_get_contents($project.'/docs/workflows/routes/order.new.md'), '| 2026-09-18 |');

        self::assertSame(2, $history, 'The initial revision, and one for this review.');
    }

    public function testReviewWithoutATerminalRefusesToGuess(): void
    {
        $project = $this->inspected();
        self::rename($project, "'quoted'", "'estimated'");

        $tester = new CommandTester(new WorkflowsReviewCommand(self::review()));

        self::assertSame(1, $tester->execute(['--path' => $project], ['interactive' => false]));
        self::assertStringContainsString('needs a terminal', $tester->getDisplay());
    }

    /**
     * A fact with no file of its own — a dependency, a package — has nothing to restore: it is skipped
     * rather than turned into a `git restore` with no path, which would restore the whole project.
     */
    public function testAFactWithoutAFileHasNothingToRestore(): void
    {
        $report = new InspectionReport(new \DateTimeImmutable('2026-09-18T10:00:00+02:00'));
        $report->writtenFrom = ['route.order.new' => 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0'];

        $group = new ChangeGroup(new WorkflowChange(ChangeNature::Added, ChangeSubject::Package, 'symfony/mailer', null, '7.4.0'), ['route.order.new']);

        self::assertSame([], self::review()->restoreCommands($report, [$group]));
    }

    public function testWithoutAChangeThereIsNothingToDecide(): void
    {
        $project = $this->inspected();

        self::assertSame(0, new CommandTester(new WorkflowsAcceptCommand(self::review()))->execute(['--path' => $project]));
        self::assertSame(0, new CommandTester(new WorkflowsRejectCommand(self::review()))->execute(['--path' => $project]));
    }

    public function testAMissingProjectIsRefused(): void
    {
        self::assertSame(2, new CommandTester(new WorkflowsAcceptCommand(self::review()))->execute(['--path' => '/definitely/not/here']));
        self::assertSame(2, new CommandTester(new WorkflowsRejectCommand(self::review()))->execute(['--path' => '/definitely/not/here']));
        self::assertSame(2, new CommandTester(new WorkflowsReviewCommand(self::review()))->execute(['--path' => '/definitely/not/here']));
    }

    private function inspected(): string
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        self::pipeline()->run(new InspectionOptions($project, prune: true, noAi: true));

        return $project;
    }

    private function repository(): string
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $repository = GitRepository::initialise($project);
        $repository->commitAll('initial');
        self::pipeline()->run(new InspectionOptions($project, prune: true, noAi: true));
        $repository->commitAll('documented');

        return $project;
    }

    private static function rename(string $project, string $from, string $to): void
    {
        $pricing = $project.'/src/Service/OrderPricing.php';
        file_put_contents($pricing, str_replace($from, $to, (string) file_get_contents($pricing)));
    }

    private static function pipeline(): InspectionPipeline
    {
        return new InspectionPipeline(new AdapterResolver([new FakeAdapter()]), new FrozenClock(new \DateTimeImmutable('2026-09-18T10:00:00+02:00')));
    }

    private static function review(): ChangeReview
    {
        return new ChangeReview(self::pipeline());
    }

    /**
     * @param array<int, string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, \SORT_STRING);

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private static function snapshotOf(string $project): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($project, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && !str_contains($file->getPathname(), '/.git/')) {
                $files[$file->getPathname()] = (string) md5_file($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
