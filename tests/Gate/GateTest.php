<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Gate;

use Jul6Art\DevTools\Claude\Hook\HookInput;
use Jul6Art\DevTools\Claude\Hook\StopHandler;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\ClaudeHookCommand;
use Jul6Art\DevTools\Command\GateCommand;
use Jul6Art\DevTools\Gate\AssertionsStep;
use Jul6Art\DevTools\Gate\FreshnessStep;
use Jul6Art\DevTools\Gate\Gate;
use Jul6Art\DevTools\Gate\GateContext;
use Jul6Art\DevTools\Gate\GateRenderer;
use Jul6Art\DevTools\Gate\GateResult;
use Jul6Art\DevTools\Gate\GateStatus;
use Jul6Art\DevTools\Gate\GateStep;
use Jul6Art\DevTools\Gate\GateStepInterface;
use Jul6Art\DevTools\Gate\ScenariosStep;
use Jul6Art\DevTools\Gate\VerificationStep;
use Jul6Art\DevTools\Gate\XmlGateStore;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Scenario\ScenarioGenerator;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Verification\VerificationCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0023: the gate on a change of symfony-minimal under git — the steps it runs, on what, all of them — and
 * the Stop hook that keeps Claude from ending a task while it fails.
 */
#[CoversClass(Gate::class)]
#[CoversClass(GateContext::class)]
#[CoversClass(GateResult::class)]
#[CoversClass(GateStep::class)]
#[CoversClass(GateStatus::class)]
#[CoversClass(GateRenderer::class)]
#[CoversClass(XmlGateStore::class)]
#[CoversClass(FreshnessStep::class)]
#[CoversClass(ScenariosStep::class)]
#[CoversClass(VerificationStep::class)]
#[CoversClass(AssertionsStep::class)]
#[CoversClass(GateCommand::class)]
#[CoversClass(StopHandler::class)]
final class GateTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string TEMPLATE = 'templates/order/new.html.twig';

    private string $project;

    private GitRepository $repository;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($this->project, noAi: true));
        new ScenarioGenerator()->generate($this->directory(), new WorkflowTypeRegistry(), noAi: true);
        $this->repository = GitRepository::initialise($this->project);
        $this->repository->commitAll('documented');
        file_put_contents($this->project.'/'.self::TEMPLATE, "\n{# a field reworked #}\n", \FILE_APPEND);
    }

    /**
     * A template changed: every step judges the route it reaches, and that route only — its page is still true,
     * its scenario cannot be replayed here, and the verifier has not judged it yet.
     */
    public function testATemplateChangeIsJudgedOnTheRouteItReaches(): void
    {
        $result = $this->gate()->run($this->directory(), [self::TEMPLATE]);

        self::assertFalse($result->passed());
        self::assertSame(['freshness', 'scenarios', 'verification', 'assertions'], array_map(static fn (GateStep $step): string => $step->name, $result->steps));
        [$freshness, $scenarios, $verification, $assertions] = $result->steps;

        self::assertSame(GateStatus::Warning, $freshness->status());
        self::assertSame(['route.order.new: its code changed without a fact moving — its page may say something no longer true.'], $freshness->warnings);
        self::assertSame(['1 scenarios not replayed: Panther is not installed — composer require --dev symfony/panther:^2.4.'], $scenarios->warnings, 'Its smoke scenario only: no other route is reached.');
        self::assertSame(GateStatus::Failed, $verification->status());
        self::assertStringStartsWith('Not verified yet: have the devtools-verifier subagent judge .devtools/pending/verification.2026-10-20-150000, write its answer as it is to .devtools/pending/verification.2026-10-20-150000/verdict.xml', $verification->failures[0]);
        self::assertSame(['route.order.new'], $this->manifestWorkflows());
        self::assertSame(GateStatus::Passed, $assertions->status());
        self::assertSame(1, $result->consecutive);
        self::assertSame([self::TEMPLATE], $result->changed);
    }

    /**
     * The verifier's verdict, once collected, decides the step: rejected is red, approved is green — on the same
     * folder, as long as the change does not move.
     */
    public function testTheVerificationStepFollowsTheCollectedVerdict(): void
    {
        $this->gate()->run($this->directory(), [self::TEMPLATE]);
        $folder = $this->project.'/.devtools/pending/verification.2026-10-20-150000';

        $this->verdict($folder, 'rejected', '<finding workflow="route.order.new" severity="blocking" category="ui-component">The product field is a native select.</finding>');
        $rejected = $this->gate('2026-10-20T15:05:00+00:00')->run($this->directory(), [self::TEMPLATE])->steps[2];
        self::assertSame(['route.order.new · ui-component — The product field is a native select.'], $rejected->failures);

        $this->verdict($folder, 'approved');
        $approved = $this->gate('2026-10-20T15:10:00+00:00')->run($this->directory(), [self::TEMPLATE])->steps[2];
        self::assertSame(GateStatus::Passed, $approved->status());
        self::assertSame(['Approved — .devtools/pending/verification.2026-10-20-150000'], $approved->notes);

        file_put_contents($this->project.'/'.self::TEMPLATE, "\n{# and again #}\n", \FILE_APPEND);
        $moved = $this->gate('2026-10-20T15:20:00+00:00')->run($this->directory(), [self::TEMPLATE])->steps[2];
        self::assertStringContainsString('verification.2026-10-20-152000', $moved->failures[0], 'The change moved: a new folder to judge.');

        $this->verdict($this->project.'/.devtools/pending/verification.2026-10-20-152000', 'rejected');
        self::assertStringStartsWith('Inconclusive — Rejected without a blocking finding', $this->gate('2026-10-20T15:25:00+00:00')->run($this->directory(), [self::TEMPLATE])->steps[2]->failures[0]);
    }

    public function testWhatChangesNoTemplateAndNoFormNeedsNoVerifier(): void
    {
        $result = $this->gate()->run($this->directory(), ['src/Service/OrderPricing.php']);

        self::assertSame(['No template and no form changed.'], $result->steps[2]->notes);
        self::assertSame(GateStatus::Passed, $result->steps[2]->status());
    }

    /**
     * A reached workflow without a scenario is a warning: a project that starts must not be red everywhere.
     * --strict makes it a failure.
     */
    public function testAWorkflowWithoutScenarioIsAWarningUnlessStrict(): void
    {
        unlink($this->project.'/.devtools/scenarios/route.order.new/smoke.xml');

        $warning = $this->gate()->run($this->directory(), ['src/Service/OrderPricing.php']);
        self::assertSame(['route.order.new has no scenario: devtools scenarios:generate route.order.new writes one.'], $warning->steps[1]->warnings);
        self::assertTrue($warning->passed(), 'Warnings only.');

        $strict = $this->gate()->run($this->directory(), ['src/Service/OrderPricing.php'], strict: true);
        self::assertSame(['route.order.new has no scenario: devtools scenarios:generate route.order.new writes one.'], $strict->steps[1]->failures);
        self::assertFalse($strict->passed());
    }

    /**
     * Every step runs, whatever the first found — even one that cannot run at all.
     */
    public function testEveryStepRunsWhateverTheFirstFound(): void
    {
        $ran = new \ArrayObject();
        $result = new Gate([$this->step('broken', $ran, throw: true), $this->step('red', $ran, ['A failure.']), $this->step('green', $ran)], new FrozenClock(new \DateTimeImmutable('2026-10-20T15:00:00+00:00')))->run($this->directory(), [self::TEMPLATE]);

        self::assertSame(['broken', 'red', 'green'], $ran->getArrayCopy());
        self::assertSame(['The step could not run: the browser is gone'], $result->steps[0]->failures);
        self::assertSame([GateStatus::Failed, GateStatus::Failed, GateStatus::Passed], array_map(static fn (GateStep $step): GateStatus => $step->status(), $result->steps));
    }

    /**
     * The same failure counts up; another failure starts again; a success resets — and DevTools' own work areas
     * are never part of a change.
     */
    public function testIdenticalFailuresAreCountedInARow(): void
    {
        $gate = $this->gate();

        self::assertSame(1, $gate->run($this->directory(), [self::TEMPLATE])->consecutive);
        self::assertSame(2, $gate->run($this->directory(), [self::TEMPLATE, '.devtools/gate/last.xml', '.devtools/runs/x/result.xml'])->consecutive);
        self::assertSame(1, $this->gate('2026-10-20T15:30:00+00:00')->run($this->directory(), ['src/Form/OrderType.php'])->consecutive, 'Another failure.');

        $green = new Gate([new AssertionsStep()], new FrozenClock(new \DateTimeImmutable('2026-10-20T16:00:00+00:00')))->run($this->directory(), [self::TEMPLATE]);
        self::assertSame(0, $green->consecutive);
        self::assertEquals($green, new XmlGateStore()->read($this->project.'/.devtools/gate/last.xml'));
    }

    public function testTheTextNamesWhatToFixAndTheJsonCarriesIt(): void
    {
        $result = $this->gate()->run($this->directory(), [self::TEMPLATE]);
        $text = new GateRenderer()->text($result);

        self::assertStringStartsWith("DevTools gate — failed: verification.\n! freshness (", $text);
        self::assertStringContainsString("\n    ✗ Not verified yet: have the devtools-verifier subagent judge", $text);
        self::assertStringContainsString("\n✓ assertions (", $text);
        self::assertStringContainsString(') — No active rule has an assertion.', $text);
        self::assertSame('failed', new GateRenderer()->toArray($result)['verdict']);
        self::assertSame("DevTools gate — passed (1 files changed).\n✓ assertions (0 ms) — No active rule has an assertion.\n", new GateRenderer()->text(new GateResult(new \DateTimeImmutable(), ['a'], [new GateStep('assertions', notes: ['No active rule has an assertion.'])], str_repeat('0', 64))));
    }

    /**
     * The working tree by default: what a task in progress changed.
     */
    public function testTheCommandJudgesTheWorkingTreeByDefault(): void
    {
        $tester = new CommandTester(new GateCommand($this->gate(), defaultPath: $this->project));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('DevTools gate — failed: verification.', $tester->getDisplay());

        self::assertSame(1, $tester->execute(['--format' => 'json']));
        $json = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame([self::TEMPLATE], $json['changed']);

        self::assertSame(2, $tester->execute(['--format' => 'xml']));
        self::assertSame(2, $tester->execute(['--timeout' => '0']));
        self::assertSame(2, $tester->execute(['--changed' => true, '--commit' => 'HEAD']));
        self::assertSame(2, $tester->execute(['files' => ['../outside.twig']]));
        self::assertSame(2, $tester->execute(['--path' => $this->project.'/nowhere']));
        self::assertSame(1, new CommandTester(new GateCommand($this->gate()))->execute(['--path' => $this->copyFixtureProject('plain-php'), 'files' => ['index.php']]), 'Never inspected.');
    }

    /**
     * The Stop hook blocks while the gate fails, with its text as the reason; three identical failures ask Claude
     * to stop trying and report; a fourth lets it end.
     */
    public function testTheStopHookBlocksWhileTheGateFailsThenLetsItReport(): void
    {
        $answers = [];

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $answers[] = $this->stop();
        }

        self::assertSame('block', self::field($answers[0], 'decision'));
        self::assertStringStartsWith("The DevTools gate fails: the task is not finished. Fix what follows, then end again.\n\nDevTools gate — failed: verification.", self::field($answers[0], 'reason'));
        self::assertSame('block', self::field($answers[2], 'decision'));
        self::assertStringStartsWith('The DevTools gate failed 3 times the same way. Stop trying to fix it: end with a message that tells the human what still fails, and why.', self::field($answers[2], 'reason'));
        self::assertSame('', self::field($answers[3], 'decision'), 'A fourth time: it may end.');
        self::assertStringStartsWith('The DevTools gate still fails, the same way, after 3 attempts: it lets the task end.', self::field($answers[3], 'systemMessage'));
    }

    /**
     * Passed on this very state of the change: the gate does not run again — and says nothing.
     */
    public function testAfterASuccessNothingRunsAgainUntilTheChangeMoves(): void
    {
        $ran = new \ArrayObject();
        $gate = new Gate([$this->step('green', $ran)], new FrozenClock(new \DateTimeImmutable('2026-10-20T15:00:00+00:00')));

        self::assertNull($this->stop($gate));
        self::assertNull($this->stop($gate));
        self::assertSame(['green'], $ran->getArrayCopy(), 'Run once.');

        file_put_contents($this->project.'/'.self::TEMPLATE, "\n{# moved #}\n", \FILE_APPEND);
        self::assertNull($this->stop($gate));
        self::assertSame(['green', 'green'], $ran->getArrayCopy());
    }

    public function testTheStopHookIsSilentOutsideADocumentedProjectOrWithoutAChange(): void
    {
        $this->repository->commitAll('nothing left');
        self::assertNull($this->stop(), 'Nothing changed.');

        $bare = $this->copyFixtureProject('plain-php');
        self::assertNull(new StopHandler($this->gate())->handle(new HookInput('Stop'), new DevToolsDirectory(new ProjectRoot($bare))));
    }

    public function testTheStopHookIsTheOneClaudeCodeRuns(): void
    {
        $file = $this->project.'/../stop.json';
        file_put_contents($file, str_replace('{PROJECT}', $this->project, (string) file_get_contents(__DIR__.'/../Fixtures/claude/hooks/stop.json')));
        $tester = new CommandTester(new ClaudeHookCommand($file, $this->project, [new StopHandler($this->gate())]));

        self::assertSame(0, $tester->execute(['event' => 'Stop']));
        $answer = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertSame('block', $answer['decision']);
    }

    /**
     * @param array<string, mixed>|null $answer
     */
    private static function field(?array $answer, string $key): string
    {
        $value = $answer[$key] ?? '';
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stop(?Gate $gate = null): ?array
    {
        return new StopHandler($gate ?? $this->gate())->handle(new HookInput('Stop', cwd: $this->project), $this->directory());
    }

    /**
     * The gate of symfony-minimal: the real steps, the console's answers replayed, no browser.
     */
    private function gate(string $at = '2026-10-20T15:00:00+00:00'): Gate
    {
        $clock = new FrozenClock(new \DateTimeImmutable($at));

        return new Gate([
            new FreshnessStep(new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), $clock)),
            new ScenariosStep(panther: false),
            new VerificationStep(),
            new AssertionsStep(),
        ], $clock);
    }

    /**
     * @param \ArrayObject<int, string> $ran
     * @param list<string>              $failures
     */
    private function step(string $name, \ArrayObject $ran, array $failures = [], bool $throw = false): GateStepInterface
    {
        return new readonly class($name, $ran, $failures, $throw) implements GateStepInterface {
            /**
             * @param \ArrayObject<int, string> $ran
             * @param list<string>              $failures
             */
            public function __construct(private string $name, private \ArrayObject $ran, private array $failures, private bool $throw)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function run(GateContext $context): GateStep
            {
                $this->ran[] = $this->name;

                if ($this->throw) {
                    throw new \RuntimeException('the browser is gone');
                }

                return new GateStep($this->name, $this->failures);
            }
        };
    }

    private function verdict(string $folder, string $verdict, string $findings = ''): void
    {
        file_put_contents($folder.'/verdict.xml', '<verification xmlns="https://github.com/jul6art/devtools/schema/verification/1" schema-version="1" verdict="'.$verdict.'">'.$findings.'</verification>');
        new VerificationCollector()->collect($folder, new \DateTimeImmutable('2026-10-20T15:01:00+00:00'));
    }

    /**
     * @return list<string>
     */
    private function manifestWorkflows(): array
    {
        preg_match_all('/<workflow id="([^"]+)"/', (string) file_get_contents($this->project.'/.devtools/pending/verification.2026-10-20-150000/manifest.xml'), $ids);

        return $ids[1];
    }

    private function directory(): DevToolsDirectory
    {
        return DevToolsDirectory::documented(new ProjectRoot($this->project));
    }
}
