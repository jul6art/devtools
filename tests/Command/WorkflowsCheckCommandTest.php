<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Command;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\WorkflowsCheckCommand;
use Jul6Art\DevTools\Console\CheckRenderer;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Tests\Inspection\Pipeline\FakeAdapter;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0017: the gate fails on facts, never on bytes.
 */
#[CoversClass(WorkflowsCheckCommand::class)]
#[CoversClass(CheckRenderer::class)]
final class WorkflowsCheckCommandTest extends TestCase
{
    use AssertsSnapshots;
    use CopiesFixtureProjects;

    public function testADocumentedProjectPasses(): void
    {
        $tester = self::tester();

        self::assertSame(0, $tester->execute(['path' => $this->inspected()]));
        self::assertStringContainsString('rien à signaler', $tester->getDisplay());
    }

    /**
     * ⚠️ The difference with the freshness, and the reason this command exists: a comment added to a
     * traversed file rewrites the page, but it is not a reason to stop anyone.
     */
    public function testAFileChangedWithoutAFactChangedPasses(): void
    {
        $project = $this->inspected();
        $pricing = $project.'/src/Service/OrderPricing.php';
        file_put_contents($pricing, str_replace('<?php', "<?php\n\n// A comment nobody asked for.", (string) file_get_contents($pricing)));

        $tester = self::tester();

        self::assertSame(0, $tester->execute(['path' => $project]));
        self::assertStringContainsString('rien à signaler', $tester->getDisplay());
    }

    public function testAChangedFactFailsAndIsNamed(): void
    {
        $project = $this->inspected();
        self::rename($project, "'quoted'", "'estimated'");

        $tester = self::tester();

        self::assertSame(1, $tester->execute(['path' => $project]));
        self::assertStringContainsString('App\Entity\Order::status', $tester->getDisplay());
        self::assertStringContainsString("'quoted' → 'estimated'", $tester->getDisplay());
    }

    public function testAWorkflowWithoutAPageAndAPageWithoutAWorkflowBothFail(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        self::pipeline(new FakeAdapter(['app_health']))->run(new InspectionOptions($project, prune: true, noAi: true));

        $tester = self::tester();

        self::assertSame(1, $tester->execute(['path' => $project]));
        self::assertStringContainsString('route.health', $tester->getDisplay());
        self::assertStringContainsString('non documenté', $tester->getDisplay());

        $documented = $this->inspected();
        $tester = self::tester(new FakeAdapter(['app_health']));

        self::assertSame(1, $tester->execute(['path' => $documented]));
        self::assertStringContainsString('orphelin', $tester->getDisplay());
    }

    public function testRequireAiFailsOnAPageThatWasNeverWritten(): void
    {
        $project = $this->inspected();

        self::assertSame(0, self::tester()->execute(['path' => $project]), 'Facts alone are enough by default.');

        $tester = self::tester();

        self::assertSame(1, $tester->execute(['path' => $project, '--require-ai' => true]));
        self::assertStringContainsString('jamais rédigé', $tester->getDisplay());
    }

    /**
     * ⚠️ The safety net of ADR-0017: the model only holds assignments, so a condition rewritten inside
     * a method, a constant renamed or a default value flipped changes no fact. The gate says how many
     * workflows are in that state — and fails on them only with `--strict`.
     */
    public function testCodeThatChangedWithoutChangingAFactIsReportedButDoesNotFail(): void
    {
        $project = $this->inspected();
        $pricing = $project.'/src/Service/OrderPricing.php';
        file_put_contents($pricing, str_replace('public function discount(', "public function unused(): void\n    {\n    }\n\n    public function discount(", (string) file_get_contents($pricing)));

        $tester = self::tester();

        self::assertSame(0, $tester->execute(['path' => $project]));
        self::assertStringContainsString('sans qu\'aucun fait ne bouge', $tester->getDisplay());

        $strict = self::tester();

        self::assertSame(1, $strict->execute(['path' => $project, '--strict' => true]));
        self::assertStringContainsString('code changé, aucun fait', $strict->getDisplay());
    }

    public function testTheGithubFormatAnnotatesTheFileAndTheLine(): void
    {
        $project = $this->inspected();
        self::rename($project, "'quoted'", "'estimated'");

        $tester = self::tester();
        $tester->execute(['path' => $project, '--format' => 'github'], ['decorated' => false]);

        self::assertMatchesSnapshot($tester->getDisplay(), __DIR__.'/../Fixtures/snapshots/check-github.txt');
    }

    public function testItWritesNothing(): void
    {
        $project = $this->inspected();
        self::rename($project, "'quoted'", "'estimated'");

        $before = self::snapshotOf($project);
        self::tester()->execute(['path' => $project]);

        self::assertSame($before, self::snapshotOf($project), 'A gate reads, it does not write.');
    }

    public function testAnUnknownFormatAndAMissingPathAreRefused(): void
    {
        self::assertSame(2, self::tester()->execute(['path' => $this->inspected(), '--format' => 'json']));
        self::assertSame(2, self::tester()->execute(['path' => '/definitely/not/here']));
    }

    private function inspected(): string
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        self::pipeline()->run(new InspectionOptions($project, prune: true, noAi: true));

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
        return new CommandTester(new WorkflowsCheckCommand(self::pipeline($adapter)));
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
