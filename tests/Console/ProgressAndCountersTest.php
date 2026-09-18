<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Console;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\WorkflowsInspectCommand;
use Jul6Art\DevTools\Console\ConsoleProgressReporter;
use Jul6Art\DevTools\Console\SummaryRenderer;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\InspectionReport;
use Jul6Art\DevTools\Inspection\Progress\NullProgressReporter;
use Jul6Art\DevTools\Inspection\Progress\ProgressReporter;
use Jul6Art\DevTools\Tests\Inspection\Pipeline\FakeAdapter;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0042: the command says what it is doing, and ends on what it did and what it cost.
 */
#[CoversClass(ConsoleProgressReporter::class)]
#[CoversClass(SummaryRenderer::class)]
#[CoversClass(NullProgressReporter::class)]
#[CoversClass(InspectionReport::class)]
final class ProgressAndCountersTest extends TestCase
{
    use CopiesFixtureProjects;

    public function testThePipelineRunsWithoutAnyReporter(): void
    {
        $report = $this->pipeline()->run(new InspectionOptions(path: $this->copyFixtureProject('symfony-minimal')));

        self::assertSame(0, $report->exitCode());
    }

    /**
     * Every counter is incremented by the operation itself, so removing the operation moves the number —
     * a counter read off a cache survives its own removal and measures nothing.
     */
    public function testEveryCounterMeasuresARealOperation(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $report = new InspectionPipeline(clock: new FrozenClock(new \DateTimeImmutable('2026-09-16')))->run(new InspectionOptions(path: $project));

        self::assertGreaterThan(0, $report->filesParsed, 'The PHP files of the project were parsed.');
        self::assertGreaterThan(0, $report->filesHashed, 'Every tracked file was hashed for the first writing.');
        self::assertGreaterThan(0, $report->pagesWritten);
        self::assertGreaterThan(0, $report->bytesWritten);
        self::assertGreaterThan(0, $report->briefsWritten, 'One brief and one model per page to write.');
        self::assertGreaterThan(0, $report->briefBytes);
        self::assertGreaterThan(0, $report->peakMemoryBytes);
        self::assertSame((int) ceil($report->briefBytes / 4), $report->estimatedTokens());

        // The second run writes nothing: the counters follow what happened, not what exists.
        $again = new InspectionPipeline(clock: new FrozenClock(new \DateTimeImmutable('2026-09-16')))->run(new InspectionOptions(path: $project));

        self::assertSame(0, $again->pagesWritten);
        self::assertSame(0, $again->bytesWritten);
    }

    public function testTheConsoleCallsOfTheProjectAreCounted(): void
    {
        $report = $this->pipeline()->run(new InspectionOptions(path: $this->copyFixtureProject('symfony-minimal')));

        self::assertSame(0, $report->consoleCalls, 'The fake adapter launches no console.');
    }

    public function testTheReportRepeatsTheMeasurements(): void
    {
        $report = new InspectionReport(new \DateTimeImmutable('2026-09-16'));
        $report->filesParsed = 1247;
        $report->filesHashed = 412;
        $report->gitProcesses = 3;
        $report->consoleCalls = 4;
        $report->briefsWritten = 15;
        $report->briefBytes = 155648;
        $report->peakMemoryBytes = 224395264;

        $markdown = $report->toMarkdown();

        self::assertStringContainsString('1247 files parsed, 412 hashed', $markdown);
        self::assertStringContainsString('3 git processes, 4 console calls', $markdown);
        self::assertStringContainsString('~38912 tokens estimated', $markdown);
        self::assertStringContainsString('peak memory: 214 MB', $markdown);
    }

    public function testAtQuietVerbosityNothingIsWritten(): void
    {
        $tester = new CommandTester(new WorkflowsInspectCommand($this->pipeline()));
        $tester->execute(['path' => $this->copyFixtureProject('symfony-minimal')], ['verbosity' => OutputInterface::VERBOSITY_QUIET]);

        self::assertSame('', $tester->getDisplay());
    }

    /**
     * A bar is a stream of control sequences: never when the output is not a terminal (CI, redirection).
     */
    public function testAnUndecoratedOutputGetsNoBarAndNoEscapeSequence(): void
    {
        $tester = new CommandTester(new WorkflowsInspectCommand($this->pipeline()));
        $tester->execute(['path' => $this->copyFixtureProject('symfony-minimal')], ['decorated' => false]);
        $display = $tester->getDisplay();

        self::assertStringNotContainsString("\033[", $display);
        self::assertStringNotContainsString("\r", $display);
        self::assertStringContainsString('workflows', $display);
        self::assertStringContainsString('Durée', $display);
    }

    /**
     * The bar is the one thing a decorated output has and an undecorated one must not; the summary — the
     * part a reader keeps — says exactly the same words on both, colours aside.
     */
    public function testTheSummarySaysTheSameThingDecoratedOrNot(): void
    {
        // Documented once first, so both runs below see the same, unchanged project.
        $project = $this->copyFixtureProject('symfony-minimal');
        $this->pipeline()->run(new InspectionOptions(path: $project));

        $plain = new CommandTester(new WorkflowsInspectCommand($this->pipeline()));
        $plain->execute(['path' => $project], ['decorated' => false]);

        $coloured = new CommandTester(new WorkflowsInspectCommand($this->pipeline()));
        $coloured->execute(['path' => $project], ['decorated' => true]);

        self::assertSame(self::summary($plain->getDisplay()), self::summary($coloured->getDisplay()), 'Colours go, the text stays.');
        self::assertStringContainsString('✔', self::summary($plain->getDisplay()), 'The symbols stay without colours.');
    }

    public function testTheSummaryIsGreenThenYellowThenRed(): void
    {
        $colour = static function (InspectionReport $report): string {
            $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
            new SummaryRenderer()->inspection(new SymfonyStyle(new ArrayInput([]), $output), $report, 'Symfony 7.4');

            return $output->fetch();
        };

        $green = new InspectionReport(new \DateTimeImmutable('2026-09-16'));

        $yellow = new InspectionReport(new \DateTimeImmutable('2026-09-16'));
        $yellow->warnings[] = 'something';

        $red = new InspectionReport(new \DateTimeImmutable('2026-09-16'));
        $red->errors[] = 'something';

        self::assertStringContainsString("\033[32m✔", $colour($green));
        self::assertStringContainsString("\033[33m⚠", $colour($yellow));
        self::assertStringContainsString("\033[31m✖", $colour($red));
    }

    public function testTheStagesAreAnnouncedInOrder(): void
    {
        $recorder = new class implements ProgressReporter {
            /**
             * @var list<string>
             */
            public array $stages = [];

            public int $steps = 0;

            #[\Override]
            public function stage(string $name, ?int $steps = null): void
            {
                $this->stages[] = $name;
            }

            #[\Override]
            public function advance(string $label): void
            {
                ++$this->steps;
            }

            #[\Override]
            public function finish(): void
            {
            }
        };

        $this->pipeline()->run(new InspectionOptions(path: $this->copyFixtureProject('symfony-minimal')), $recorder);

        self::assertSame(
            ['Configuration et stack', 'Extraction', 'Fraîcheur', 'Rendu des pages', 'Connaissances et briefs', 'Index, menu et graphe'],
            $recorder->stages,
        );
        self::assertGreaterThan(0, $recorder->steps, 'One step per stack extracted and per page rendered.');
    }

    /**
     * An undecorated reporter writes one line per stage at verbose, and nothing at all below it.
     */
    public function testTheReporterKeepsQuietUntilAskedForVerbosity(): void
    {
        $normal = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $normal))->stage('Rendu', 3);

        $verbose = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $reporter = new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $verbose));
        $reporter->stage('Rendu', 3);
        $reporter->advance('route.order.new');
        $reporter->finish();

        self::assertSame('', $normal->fetch());
        self::assertStringContainsString('Rendu (3)', $verbose->fetch());
        self::assertSame('', $reporter->current(), 'A closed stage is no longer current.');
    }

    private function pipeline(): InspectionPipeline
    {
        return new InspectionPipeline(new AdapterResolver([new FakeAdapter()]), new FrozenClock(new \DateTimeImmutable('2026-09-16')));
    }

    /**
     * The final block, without its colours and without what differs between two runs: the temporary path
     * of the project, the duration and the memory.
     */
    private static function summary(string $display): string
    {
        $plain = (string) preg_replace('/\033\[[0-9;]*m/', '', $display);
        $start = strrpos($plain, ' DevTools — ');

        return (string) preg_replace(
            ['#/private/var/\S+#', '#/var/folders/\S+#', '/Durée .*/'],
            'x',
            false === $start ? $plain : substr($plain, $start),
        );
    }
}
