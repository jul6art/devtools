<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Retro;

use Jul6Art\DevTools\Claude\Hook\HookInput;
use Jul6Art\DevTools\Claude\Hook\StopHandler;
use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Feedback\FeedbackSource;
use Jul6Art\DevTools\Feedback\FeedbackTarget;
use Jul6Art\DevTools\Feedback\Occurrence;
use Jul6Art\DevTools\Gate\Gate;
use Jul6Art\DevTools\Gate\GateContext;
use Jul6Art\DevTools\Gate\GateStep;
use Jul6Art\DevTools\Gate\GateStepInterface;
use Jul6Art\DevTools\Gate\GateTask;
use Jul6Art\DevTools\Gate\XmlGateStore;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Retro\Attribution;
use Jul6Art\DevTools\Retro\JournalRepository;
use Jul6Art\DevTools\Retro\Task;
use Jul6Art\DevTools\Retro\XmlJournalStore;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0029: a task is delivered when the Stop hook sees the gate pass — written in the journal with what failed on
 * the way — and a remark is placed in the task whose commits hold the one it was made at.
 */
#[CoversClass(Task::class)]
#[CoversClass(XmlJournalStore::class)]
#[CoversClass(JournalRepository::class)]
#[CoversClass(Attribution::class)]
#[CoversClass(GateTask::class)]
#[CoversClass(StopHandler::class)]
#[CoversClass(Gate::class)]
#[CoversClass(XmlGateStore::class)]
final class JournalTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string TEMPLATE = 'templates/order/new.html.twig';

    private string $project;

    private GitRepository $repository;

    private string $base;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]))->run(new InspectionOptions($this->project, noAi: true));
        $this->repository = GitRepository::initialise($this->project);
        $this->base = $this->repository->commitAll('documented');
        file_put_contents($this->project.'/'.self::TEMPLATE, "\n{# a field reworked #}\n", \FILE_APPEND);
    }

    /**
     * Failed twice — the second time stopped by a rule — then green: one task, three passages, what failed on the
     * way. Seen green again, the same task; a new change, a new task.
     */
    public function testATaskIsWrittenWhenTheGatePassesAtTheEndWithWhatFailedBefore(): void
    {
        $verdicts = self::verdicts([['scenarios'], ['assertions'], []]);
        $clock = self::steppingClock('2026-10-21T10:00:00+02:00');
        $gate = new Gate([self::scripted($verdicts)], $clock);

        self::assertSame('block', $this->stop($gate)['decision'] ?? null);
        file_put_contents($this->project.'/'.self::TEMPLATE, "{# again #}\n", \FILE_APPEND);
        self::assertSame('block', $this->stop($gate)['decision'] ?? null);
        self::assertNull($this->stop($gate));

        $tasks = new JournalRepository($this->directory())->since(new \DateTimeImmutable('2026-10-01T00:00:00+02:00'));
        self::assertCount(1, $tasks);
        [$task] = $tasks;
        self::assertSame('2026-10-21-100200', $task->id);
        self::assertEquals(new \DateTimeImmutable('2026-10-21T10:00:00+02:00'), $task->startedAt);
        self::assertSame(3, $task->attempts);
        self::assertSame(['scripted'], $task->steps);
        self::assertSame(['rule-select2'], $task->blocked);
        self::assertSame($this->base, $task->base);
        self::assertSame($this->base, $task->head, 'Nothing committed on the way.');
        self::assertContains('route.order.new', $task->workflows);
        self::assertFileExists($this->project.'/.devtools/journal/2026-10.xml');

        self::assertNull($this->stop($gate), 'Green, the same state: nothing runs, nothing more is written.');
        self::assertCount(1, new JournalRepository($this->directory())->since(new \DateTimeImmutable('2026-10-01')));

        $verdicts->append([]);
        file_put_contents($this->project.'/'.self::TEMPLATE, "{# the next task #}\n", \FILE_APPEND);
        self::assertNull($this->stop($gate));
        $tasks = new JournalRepository($this->directory())->since(new \DateTimeImmutable('2026-10-01'));
        self::assertCount(2, $tasks);
        self::assertSame(1, $tasks[1]->attempts, 'A new task, green at its first passage.');
        self::assertSame([], $tasks[1]->steps);
    }

    /**
     * Green from `devtools gate` itself, then the hook: the task is delivered all the same, once.
     */
    public function testAGreenPassageOfTheCommandIsDeliveredByTheHook(): void
    {
        $gate = new Gate([self::scripted(self::verdicts([[]]))], self::steppingClock('2026-10-21T11:00:00+02:00'));
        $gate->run($this->directory(), [self::TEMPLATE]);

        self::assertNull($this->stop($gate));
        self::assertNull($this->stop($gate));
        self::assertCount(1, new JournalRepository($this->directory())->since(new \DateTimeImmutable('2026-10-01')));
    }

    /**
     * A remark goes to the last task delivered before it whose commits hold the one it was made at — else to the
     * last that reached its workflow; made before any, to none.
     */
    public function testARemarkIsAttributedToTheTaskWhoseCommitsHoldIt(): void
    {
        file_put_contents($this->project.'/src/Service/OrderPricing.php', "\n// one\n", \FILE_APPEND);
        $middle = $this->repository->commitAll('one');
        file_put_contents($this->project.'/src/Service/OrderPricing.php', "\n// two\n", \FILE_APPEND);
        $head = $this->repository->commitAll('two');
        $root = new ProjectRoot($this->project);
        $tasks = [
            new Task('2026-10-01-100000', new \DateTimeImmutable('2026-10-01T09:00:00+02:00'), new \DateTimeImmutable('2026-10-01T10:00:00+02:00'), $this->base, $this->base, workflows: ['route.order.index']),
            new Task('2026-10-02-100000', new \DateTimeImmutable('2026-10-02T09:00:00+02:00'), new \DateTimeImmutable('2026-10-02T10:00:00+02:00'), $this->base, $head, workflows: ['route.order.new']),
        ];
        $attribution = new Attribution($root, $tasks);
        $at = static fn (string $commit, ?string $workflow = null, string $when = '2026-10-03T10:00:00+02:00'): Occurrence => new Occurrence(new \DateTimeImmutable($when), FeedbackSource::Human, new FeedbackTarget($workflow), $commit);

        self::assertSame('2026-10-02-100000', $attribution->taskOf($at($middle))?->id, 'Within base..head.');
        self::assertSame('2026-10-02-100000', $attribution->taskOf($at(substr($head, 0, 7)))?->id, 'Its head, abbreviated.');
        self::assertSame('2026-10-01-100000', $attribution->taskOf($at($this->base))?->id, 'The head of the first.');
        self::assertSame('2026-10-01-100000', $attribution->taskOf($at('0000000', 'route.order.index'))?->id, 'No commit of theirs: the workflow.');
        self::assertSame('2026-10-01-100000', $attribution->taskOf($at('0000000'), 'route.order.index')?->id, 'The workflow of the feedback.');
        self::assertNull($attribution->taskOf($at('0000000', 'route.health')));
        self::assertNull($attribution->taskOf($at($middle, null, '2026-10-01T08:00:00+02:00')), 'Before any task.');
        self::assertNull(new Attribution($root, [new Task('2026-10-01-100000', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-01'))])->taskOf($at($middle)), 'A task without commits holds none.');
    }

    public function testAJournalReadsBackAndRefusesWhatIsNotOne(): void
    {
        $repository = new JournalRepository($this->directory());
        $task = new Task('2026-10-21-100000', new \DateTimeImmutable('2026-10-21T09:00:00+02:00'), new \DateTimeImmutable('2026-10-21T10:00:00+02:00'), $this->base, $this->base, 2, ['route.order.new'], ['assertions'], ['rule-select2']);

        self::assertTrue($repository->record($task));
        self::assertFalse($repository->record($task), 'Once.');
        self::assertEquals([$task], $repository->since(new \DateTimeImmutable('2026-10-01')));
        self::assertSame([], $repository->since(new \DateTimeImmutable('2026-11-01')));

        file_put_contents($this->project.'/.devtools/journal/2026-10.xml', '<journal/>');
        $this->expectException(InvalidXml::class);
        $repository->since(new \DateTimeImmutable('2026-10-01'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stop(Gate $gate): ?array
    {
        return new StopHandler($gate)->handle(new HookInput('Stop', cwd: $this->project), $this->directory());
    }

    /**
     * @param list<list<string>> $verdicts
     *
     * @return \ArrayObject<int, list<string>>
     */
    private static function verdicts(array $verdicts): \ArrayObject
    {
        return new \ArrayObject($verdicts);
    }

    /**
     * A step that fails as told, passage after passage — blocked by a rule when it fails at `assertions`.
     *
     * @param \ArrayObject<int, list<string>> $verdicts what each passage fails at; empty: it passes
     */
    private static function scripted(\ArrayObject $verdicts): GateStepInterface
    {
        return new readonly class($verdicts) implements GateStepInterface {
            /**
             * @param \ArrayObject<int, list<string>> $verdicts
             */
            public function __construct(private \ArrayObject $verdicts)
            {
            }

            public function name(): string
            {
                return 'scripted';
            }

            public function run(GateContext $context): GateStep
            {
                $failures = [];

                foreach ($this->verdicts as $index => $verdict) {
                    unset($this->verdicts[$index]);
                    $failures = $verdict;

                    break;
                }

                return new GateStep('scripted', array_map(static fn (string $step): string => $step.' fails.', $failures), blocked: \in_array('assertions', $failures, true) ? ['rule-select2'] : []);
            }
        };
    }

    /**
     * A clock one minute later at every reading.
     */
    private static function steppingClock(string $start): Clock
    {
        return new class($start) implements Clock {
            private \DateTimeImmutable $now;

            public function __construct(string $start)
            {
                $this->now = new \DateTimeImmutable($start)->modify('-1 minute');
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now = $this->now->modify('+1 minute');
            }
        };
    }

    private function directory(): DevToolsDirectory
    {
        return DevToolsDirectory::documented(new ProjectRoot($this->project));
    }
}
