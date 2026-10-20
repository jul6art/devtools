<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Scenario\Visual;

use Jul6Art\DevTools\Command\BaselinesAcceptCommand;
use Jul6Art\DevTools\Scenario\Run\RunResult;
use Jul6Art\DevTools\Scenario\Run\ScenarioResult;
use Jul6Art\DevTools\Scenario\Run\StepResult;
use Jul6Art\DevTools\Scenario\Run\Verdict;
use Jul6Art\DevTools\Scenario\Run\XmlRunWriter;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0022: a human makes the screenshots of a run the baselines — exactly those files, those of the
 * `screenshot` steps only, of the scenarios named.
 */
#[CoversClass(BaselinesAcceptCommand::class)]
final class BaselinesAcceptCommandTest extends TestCase
{
    use UsesTemporaryDirectory;

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->temporaryDirectory();
        $this->recordRun('2026-10-20-090000', 'old');
        $this->recordRun('2026-10-20-100000', 'new');
    }

    public function testTheScreenshotsOfARunBecomeTheBaselinesByteForByte(): void
    {
        $tester = $this->accept(['run' => '2026-10-20-100000']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('✓ route.health/smoke/health', $tester->getDisplay());
        self::assertStringContainsString('3 baselines in .devtools/baselines/', $tester->getDisplay());

        foreach (['route.health/smoke/health', 'route.order.new/happy-path/form', 'route.order.new/happy-path/saved'] as $capture) {
            self::assertFileEquals($this->project.'/.devtools/runs/2026-10-20-100000/'.$capture.'.png', $this->project.'/.devtools/baselines/'.$capture.'.png');
            self::assertFileEquals($this->project.'/.devtools/runs/2026-10-20-100000/'.$capture.'.html', $this->project.'/.devtools/baselines/'.$capture.'.dom.html');
        }

        self::assertFileDoesNotExist($this->project.'/.devtools/baselines/route.order.new/happy-path/03-click.png', 'A failure is never a baseline.');
    }

    public function testLastIsTheLatestRunAndAScenarioOrAWorkflowNarrowsIt(): void
    {
        self::assertSame(0, $this->accept(['run' => 'last', 'scenarios' => ['route.order.new']])->getStatusCode());

        self::assertStringEqualsFile($this->project.'/.devtools/baselines/route.order.new/happy-path/form.png', 'new route.order.new/happy-path/form');
        self::assertFileDoesNotExist($this->project.'/.devtools/baselines/route.health/smoke/health.png');

        $this->accept(['run' => '2026-10-20-090000', 'scenarios' => ['route.health/smoke']]);
        self::assertStringEqualsFile($this->project.'/.devtools/baselines/route.health/smoke/health.png', 'old route.health/smoke/health');
    }

    public function testWhatCannotBeAcceptedIsSaid(): void
    {
        $unknown = $this->accept(['run' => '2026-01-01-000000']);
        self::assertSame(2, $unknown->getStatusCode());
        self::assertStringContainsString('No run "2026-01-01-000000" in .devtools/runs/.', $unknown->getDisplay());

        $nothing = $this->accept(['run' => 'last', 'scenarios' => ['route.gone']]);
        self::assertSame(2, $nothing->getStatusCode());
        self::assertStringContainsString('No screenshot of route.gone in this run.', $nothing->getDisplay());

        file_put_contents($this->project.'/.devtools/runs/2026-10-20-100000/result.xml', '<run');
        self::assertSame(1, $this->accept(['run' => 'last'])->getStatusCode());

        $empty = $this->temporaryDirectory().'/empty';
        mkdir($empty);
        self::assertSame(2, new CommandTester(new BaselinesAcceptCommand($empty))->execute(['run' => 'last']));
        self::assertSame(2, new CommandTester(new BaselinesAcceptCommand())->execute(['run' => 'last', '--path' => $empty.'/nowhere']));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function accept(array $input): CommandTester
    {
        $tester = new CommandTester(new BaselinesAcceptCommand($this->project));
        $tester->execute($input);

        return $tester;
    }

    /**
     * A run of two scenarios: three screenshots, and a step that failed with its own capture.
     */
    private function recordRun(string $name, string $content): void
    {
        $directory = $this->project.'/.devtools/runs/'.$name;

        foreach (['route.health/smoke/health', 'route.order.new/happy-path/form', 'route.order.new/happy-path/saved', 'route.order.new/happy-path/03-click'] as $capture) {
            if (!is_dir(\dirname($directory.'/'.$capture))) {
                mkdir(\dirname($directory.'/'.$capture), 0o777, true);
            }

            file_put_contents($directory.'/'.$capture.'.png', $content.' '.$capture);
            file_put_contents($directory.'/'.$capture.'.html', '<p>'.$content.'</p>');
        }

        $shot = static fn (int $index, string $capture): StepResult => new StepResult($index, 'screenshot', Verdict::Passed, screenshot: $capture.'.png', dom: $capture.'.html');
        new XmlRunWriter()->write($directory.'/result.xml', new RunResult(new \DateTimeImmutable('2026-10-20T10:00:00+00:00'), 'http://app.localhost', [
            new ScenarioResult('route.health/smoke', [new StepResult(1, 'goto', Verdict::Passed), $shot(2, 'route.health/smoke/health')]),
            new ScenarioResult('route.order.new/happy-path', [
                $shot(1, 'route.order.new/happy-path/form'),
                $shot(2, 'route.order.new/happy-path/saved'),
                new StepResult(3, 'click', Verdict::Failed, message: 'Nothing to click.', screenshot: 'route.order.new/happy-path/03-click.png', dom: 'route.order.new/happy-path/03-click.html'),
            ]),
        ]));
    }
}
