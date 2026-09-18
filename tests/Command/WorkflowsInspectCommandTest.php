<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Command;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\WorkflowsInspectCommand;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Tests\Inspection\Pipeline\FakeAdapter;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(WorkflowsInspectCommand::class)]
final class WorkflowsInspectCommandTest extends TestCase
{
    use CopiesFixtureProjects;

    public function testItReportsWhatItDidAndExitsWithThePipelinesCode(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $tester = $this->tester(new FakeAdapter(fallbackCause: 'console missing'));

        self::assertSame(1, $tester->execute(['path' => $project], ['verbosity' => OutputInterface::VERBOSITY_NORMAL]));
        self::assertStringContainsString('console missing', $tester->getDisplay());
        self::assertStringContainsString('rapport ', $tester->getDisplay(), 'The summary names the report it wrote (ADR-0042).');
    }

    public function testTheFreshnessOptionsReachThePipeline(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $tester = $this->tester(new FakeAdapter());
        $tester->execute(['path' => $project]);

        $tester->execute(['path' => $project, '--dry-run' => true, '--force' => ['route.health']], ['verbosity' => OutputInterface::VERBOSITY_NORMAL]);
        self::assertMatchesRegularExpression('/route\.health\s+rewrite\s+forced/', $tester->getDisplay());
        self::assertStringNotContainsString('route.order.new', $tester->getDisplay());

        $tester->execute(['path' => $project, '--dry-run' => true, '--force' => [null]], ['verbosity' => OutputInterface::VERBOSITY_NORMAL]);
        self::assertStringContainsString('route.order.new', $tester->getDisplay(), 'A bare --force rewrites everything.');
    }

    public function testThePathMustBeADirectory(): void
    {
        self::assertSame(2, $this->tester(new FakeAdapter())->execute(['path' => '/definitely/not/here']));
    }

    public function testALocaleThatIsNotALanguageIsAConfigurationError(): void
    {
        $tester = $this->tester(new FakeAdapter());

        self::assertSame(2, $tester->execute(['path' => $this->copyFixtureProject('symfony-minimal'), '--locale' => 'french']));
        self::assertStringContainsString('is not a language', $tester->getDisplay());
    }

    public function testTheConfiguredLanguageIsUsedWhenTheProjectSaysNothing(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $command = new WorkflowsInspectCommand(new InspectionPipeline(new AdapterResolver([new FakeAdapter()]), new FrozenClock(new \DateTimeImmutable('2026-09-16'))), $project, 'fr');

        new CommandTester($command)->execute([]);

        self::assertStringContainsString('<language>fr</language>', (string) file_get_contents($project.'/.devtools/pending/page.route.order.new.brief.xml'));
    }

    public function testTheConfiguredDocumentationDirectoryIsUsedWhenTheOptionIsAbsent(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $command = new WorkflowsInspectCommand(new InspectionPipeline(new AdapterResolver([new FakeAdapter()]), new FrozenClock(new \DateTimeImmutable('2026-09-16'))), $project, null, 'documentation/flux');

        new CommandTester($command)->execute([]);

        self::assertFileExists($project.'/documentation/flux/workflows.md');
        self::assertFileDoesNotExist($project.'/docs/workflows/workflows.md');
    }

    public function testWithoutAPathItInspectsTheDefaultOne(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $command = new WorkflowsInspectCommand(new InspectionPipeline(new AdapterResolver([new FakeAdapter()]), new FrozenClock(new \DateTimeImmutable('2026-09-16'))), $project);

        self::assertSame(0, new CommandTester($command)->execute(['--dry-run' => true]));
    }

    private function tester(FakeAdapter $adapter): CommandTester
    {
        return new CommandTester(new WorkflowsInspectCommand(new InspectionPipeline(new AdapterResolver([$adapter]), new FrozenClock(new \DateTimeImmutable('2026-09-16')))));
    }
}
