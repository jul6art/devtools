<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Command;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\WorkflowsDiffCommand;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Tests\Inspection\Pipeline\FakeAdapter;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0046: `workflows:diff` says what changed, groups it by fact, and writes nothing.
 */
#[CoversClass(WorkflowsDiffCommand::class)]
final class WorkflowsDiffCommandTest extends TestCase
{
    use AssertsSnapshots;
    use CopiesFixtureProjects;

    public function testAProjectThatDidNotChangeHasNothingToShow(): void
    {
        $project = $this->inspected();
        $tester = self::tester();

        self::assertSame(0, $tester->execute(['path' => $project]));
        self::assertStringContainsString('Aucun fait n\'a changé', $tester->getDisplay());
    }

    public function testAChangedValueIsOneFactWithTheWorkflowsItTouches(): void
    {
        $project = $this->inspected();
        self::rename($project, "'quoted'", "'estimated'");

        $tester = self::tester();

        self::assertSame(0, $tester->execute(['path' => $project]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('App\Entity\Order::status', $display);
        self::assertStringContainsString("'quoted'", $display, 'What it said.');
        self::assertStringContainsString("'estimated'", $display, 'What it says now.');
        self::assertStringContainsString('src/Service/OrderPricing.php', $display, 'Where to look.');
        self::assertMatchesRegularExpression('/\d+ workflows? ·/', $display, 'How far it reaches.');
    }

    /**
     * ⚠️ The rule that makes the whole thing usable: bytes that move without changing a fact are not a
     * change. A comment added to a traversed file must leave the report empty.
     */
    public function testBytesThatChangeNoFactAreNotAChange(): void
    {
        $project = $this->inspected();
        $pricing = $project.'/src/Service/OrderPricing.php';
        file_put_contents($pricing, str_replace('<?php', "<?php\n\n// A comment nobody asked for.", (string) file_get_contents($pricing)));

        $tester = self::tester();
        $tester->execute(['path' => $project]);

        self::assertStringContainsString('Aucun fait n\'a changé', $tester->getDisplay());
        self::assertStringContainsString('fichier', $tester->getDisplay(), 'The files it did look at are still counted.');
    }

    public function testExitCodeTurnsAChangeIntoAFailure(): void
    {
        $project = $this->inspected();

        self::assertSame(0, self::tester()->execute(['path' => $project, '--exit-code' => true]), 'Nothing changed: success.');

        self::rename($project, "'quoted'", "'estimated'");

        self::assertSame(1, self::tester()->execute(['path' => $project, '--exit-code' => true]));
    }

    public function testItWritesNothing(): void
    {
        $project = $this->inspected();
        self::rename($project, "'quoted'", "'estimated'");

        $before = self::snapshotOf($project);
        self::tester()->execute(['path' => $project]);

        self::assertSame($before, self::snapshotOf($project), 'A diff is read-only, reports included.');
    }

    public function testTheCodeOptionShowsTheDiffSinceThePageWasWritten(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $repository = GitRepository::initialise($project);
        $repository->commitAll('initial');

        self::pipeline()->run(new InspectionOptions($project, noAi: true));
        $repository->commitAll('documented');
        self::rename($project, "'quoted'", "'estimated'");

        $tester = self::tester();
        $tester->execute(['path' => $project, '--code' => true]);

        $display = $tester->getDisplay();

        self::assertStringContainsString("-            \$order->status = 'quoted';", $display);
        self::assertStringContainsString("+            \$order->status = 'estimated';", $display);
    }

    /**
     * A workflow that did not exist, or that no longer does, is named — never unfolded into its facts:
     * a new route would otherwise print every decision its graph reaches.
     */
    public function testANewWorkflowAndADisappearedOneAreNamedNotUnfolded(): void
    {
        $project = $this->inspected();

        // The entry point is gone from the code, so its workflow is orphaned.
        $tester = self::tester(new FakeAdapter(['app_health']));
        $tester->execute(['path' => $project]);

        self::assertStringContainsString('disparu route.health', $tester->getDisplay());
        self::assertStringNotContainsString('décision', $tester->getDisplay(), 'Its facts are not listed one by one.');

        // And the other way round: a project documented without it sees it appear.
        $fresh = $this->copyFixtureProject('symfony-minimal');
        new InspectionPipeline(new AdapterResolver([new FakeAdapter(['app_health'])]), new FrozenClock(new \DateTimeImmutable('2026-09-18T10:00:00+02:00')))->run(new InspectionOptions($fresh, prune: true, noAi: true));

        $tester = self::tester();
        $tester->execute(['path' => $fresh]);

        self::assertStringContainsString('nouveau route.health', $tester->getDisplay());
    }

    /**
     * ADR-0046 § 7: the tracking files of a project share the same commit, so the code of every fact is
     * read in one git process — not one per fact, which on a real project means dozens.
     */
    public function testTheCodeOfEveryFactIsReadInOneGitProcessPerReference(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $repository = GitRepository::initialise($project);
        $repository->commitAll('initial');

        self::pipeline()->run(new InspectionOptions($project, noAi: true));
        $repository->commitAll('documented');
        self::rename($project, "'quoted'", "'estimated'");
        self::rename($project, "'draft'", "'new'");

        $git = new GitClient();
        new CommandTester(new WorkflowsDiffCommand(self::pipeline(), $git))->execute(['path' => $project, '--code' => true]);

        self::assertSame(1, $git->processes(), 'Two facts of the same file, one process.');
    }

    public function testTheOutputOfEachFormatIsFixed(): void
    {
        $project = $this->inspected();
        self::rename($project, "'quoted'", "'estimated'");

        foreach (['text' => 'txt', 'md' => 'md'] as $format => $extension) {
            $tester = self::tester();
            $tester->execute(['path' => $project, '--format' => $format], ['decorated' => false]);

            self::assertMatchesSnapshot($tester->getDisplay(), __DIR__.'/../Fixtures/snapshots/diff.'.$extension);
        }
    }

    public function testAnUnknownFormatIsRefused(): void
    {
        self::assertSame(2, self::tester()->execute(['path' => $this->inspected(), '--format' => 'html']));
    }

    public function testThePathMustBeADirectory(): void
    {
        self::assertSame(2, self::tester()->execute(['path' => '/definitely/not/here']));
    }

    private function inspected(): string
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        self::pipeline()->run(new InspectionOptions($project, noAi: true));

        return $project;
    }

    private static function rename(string $project, string $from, string $to): void
    {
        $pricing = $project.'/src/Service/OrderPricing.php';
        file_put_contents($pricing, str_replace($from, $to, (string) file_get_contents($pricing)));
    }

    private static function pipeline(?FakeAdapter $adapter = null): InspectionPipeline
    {
        return new InspectionPipeline(new AdapterResolver([$adapter ?? new FakeAdapter()]), new FrozenClock(new \DateTimeImmutable('2026-09-18T10:00:00+02:00')));
    }

    private static function tester(?FakeAdapter $adapter = null): CommandTester
    {
        return new CommandTester(new WorkflowsDiffCommand(self::pipeline($adapter)));
    }

    /**
     * @return array<string, string>
     */
    private static function snapshotOf(string $project): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($project, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[$file->getPathname()] = (string) md5_file($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
