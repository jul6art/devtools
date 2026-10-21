<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Verification;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\ClaudeInstallCommand;
use Jul6Art\DevTools\Command\ClaudeUninstallCommand;
use Jul6Art\DevTools\Command\VerifyCollectCommand;
use Jul6Art\DevTools\Command\VerifyPrepareCommand;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Scenario\Run\RunResult;
use Jul6Art\DevTools\Scenario\Run\ScenarioResult;
use Jul6Art\DevTools\Scenario\Run\StepResult;
use Jul6Art\DevTools\Scenario\Run\Verdict;
use Jul6Art\DevTools\Scenario\Run\XmlRunReader;
use Jul6Art\DevTools\Scenario\Run\XmlRunWriter;
use Jul6Art\DevTools\Scenario\ScenarioGenerator;
use Jul6Art\DevTools\Scenario\Visual\VisualResult;
use Jul6Art\DevTools\Scenario\Visual\VisualVerdict;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Verification\Finding;
use Jul6Art\DevTools\Verification\Outcome;
use Jul6Art\DevTools\Verification\VerificationCollector;
use Jul6Art\DevTools\Verification\VerificationPreparer;
use Jul6Art\DevTools\Verification\VerificationVerdict;
use Jul6Art\DevTools\Verification\XmlVerificationStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0024: the folder a verifier judges a change on — what is in it, and what never is — the verdict it gives,
 * checked, and the agent itself, which can read and nothing else.
 */
#[CoversClass(VerificationPreparer::class)]
#[CoversClass(VerificationCollector::class)]
#[CoversClass(XmlVerificationStore::class)]
#[CoversClass(Finding::class)]
#[CoversClass(Outcome::class)]
#[CoversClass(VerificationVerdict::class)]
#[CoversClass(XmlRunReader::class)]
#[CoversClass(VerifyPrepareCommand::class)]
#[CoversClass(VerifyCollectCommand::class)]
#[CoversClass(ClaudeInstallCommand::class)]
#[CoversClass(ClaudeUninstallCommand::class)]
final class VerificationTest extends TestCase
{
    use AssertsSnapshots;
    use CopiesFixtureProjects;

    private const string TEMPLATE = 'templates/order/new.html.twig';

    private const string FOLDER = '.devtools/pending/verification.2026-10-20-130000';

    private const string PAGE = '<!DOCTYPE html><html><body><form name="order"><label for="order_product">Product</label><select id="order_product" name="order[product]"></select><button>Save</button></form></body></html>';

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        $report = new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($this->project, noAi: true));
        self::assertSame([], $report->errors, implode("\n", $report->errors));
        new ScenarioGenerator()->generate($this->directory(), new WorkflowTypeRegistry(), noAi: true);

        // An earlier run, and the next one: the folder takes the last.
        $this->recordRun('2026-10-20-110000', '<p>earlier</p>');
        $this->recordRun('2026-10-20-120000', self::PAGE);
        mkdir($this->project.'/.devtools/baselines/route.order.new/smoke', 0o777, true);
        file_put_contents($this->project.'/.devtools/baselines/route.order.new/smoke/order-new.png', 'baseline png');
        file_put_contents($this->project.'/.devtools/baselines/route.order.new/smoke/order-new.dom.html', self::PAGE);
    }

    /**
     * A template changed: the folder holds the route it reaches, its page, its scenario, the captures of the
     * last run, the baseline and the diffs — and nothing else.
     */
    public function testTheFolderOfAChangedTemplateHoldsWhatTheVerifierJudgesOn(): void
    {
        $folder = $this->prepare([self::TEMPLATE]);

        self::assertSame($this->project.'/'.self::FOLDER, $folder);
        self::assertMatchesSnapshot(self::describe($folder), __DIR__.'/../Fixtures/snapshots/verification/folder.txt');
        self::assertFileEquals($this->project.'/docs/workflows/routes/order.new.md', $folder.'/pages/route.order.new.md');
        self::assertFileEquals($this->project.'/.devtools/runs/2026-10-20-120000/route.order.new/smoke/order-new.png', $folder.'/run/route.order.new/smoke/order-new.png');
    }

    /**
     * What the author said — a commit message — never reaches the verifier.
     */
    public function testNothingOfTheAuthorReachesTheFolder(): void
    {
        $repository = GitRepository::initialise($this->project);
        file_put_contents($this->project.'/'.self::TEMPLATE, "\n{# reviewed #}\n", \FILE_APPEND);
        $repository->commitAll('I am sure the select2 works, I tested it myself');

        $tester = new CommandTester(new VerifyPrepareCommand(new FrozenClock(new \DateTimeImmutable('2026-10-20T13:00:00+00:00'))));
        self::assertSame(0, $tester->execute(['--path' => $this->project, '--commit' => 'HEAD']));

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project.'/'.self::FOLDER, \FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            self::assertStringNotContainsString('I tested it myself', (string) file_get_contents($file->getPathname()));
        }

        self::assertStringContainsString('devtools-verifier', $tester->getDisplay());
        self::assertStringContainsString(self::FOLDER.'/verdict.xml', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testAChangeThatReachesNoRouteAndNoComponentHasNothingToVerify(): void
    {
        self::assertNull($this->prepare(['src/Command/ImportCatalogCommand.php']));

        $tester = new CommandTester(new VerifyPrepareCommand());
        self::assertSame(0, $tester->execute(['--path' => $this->project, 'files' => ['src/Command/ImportCatalogCommand.php']]));
        self::assertStringContainsString('Nothing to verify', $tester->getDisplay());
        self::assertSame(2, $tester->execute(['--path' => $this->project]));
        self::assertSame(2, $tester->execute(['--path' => $this->project, 'files' => ['../outside.twig']]));
        self::assertSame(1, $tester->execute(['--path' => $this->project, '--changed' => true]), 'Not a repository: git cannot answer.');
        self::assertSame(2, $tester->execute(['--path' => $this->project.'/nowhere', 'files' => ['x']]));
    }

    /**
     * @param list<string> $reasons
     */
    #[DataProvider('verdicts')]
    public function testAVerdictCountsOnlyOnceChecked(?string $verdict, VerificationVerdict $expected, array $reasons): void
    {
        $folder = (string) $this->prepare([self::TEMPLATE]);

        if (null !== $verdict) {
            file_put_contents($folder.'/verdict.xml', $verdict);
        }

        $outcome = new VerificationCollector()->collect($folder, new \DateTimeImmutable('2026-10-20T14:00:00+00:00'));

        self::assertSame($expected, $outcome->verdict);
        self::assertCount(\count($reasons), $outcome->reasons, implode("\n", $outcome->reasons));

        foreach ($reasons as $index => $reason) {
            self::assertStringContainsString($reason, $outcome->reasons[$index]);
        }

        self::assertEquals($outcome, new XmlVerificationStore()->readOutcome($folder.'/outcome.xml'), 'Written for the gate.');
    }

    /**
     * @return iterable<string, array{string|null, VerificationVerdict, list<string>}>
     */
    public static function verdicts(): iterable
    {
        $verdict = static fn (string $verdict, string $findings = ''): string => '<verification xmlns="https://github.com/jul6art/devtools/schema/verification/1" schema-version="1" verdict="'.$verdict.'">'.$findings.'</verification>';
        $finding = static fn (string $attributes, string $text = 'The product field is a native select; the other forms use Select2.'): string => '<finding '.$attributes.'>'.$text.'</finding>';

        yield 'approved' => [$verdict('approved', $finding('workflow="route.order.new" severity="minor" category="layout"')), VerificationVerdict::Approved, []];
        yield 'rejected on an element of the page' => [$verdict('rejected', $finding('workflow="route.order.new" severity="blocking" category="ui-component" selector="#order_product"')), VerificationVerdict::Rejected, []];
        yield 'a selector the page does not have' => [$verdict('rejected', $finding('workflow="route.order.new" severity="blocking" category="ui-component" selector="#order_customer"')), VerificationVerdict::Inconclusive, ['#order_customer is in no page captured for route.order.new.']];
        yield 'not a selector' => [$verdict('rejected', $finding('workflow="route.order.new" severity="blocking" category="ui-component" selector="#[nope"')), VerificationVerdict::Inconclusive, ['"#[nope" is not a CSS selector.']];
        yield 'a workflow it was not shown' => [$verdict('rejected', $finding('workflow="route.order.show" severity="blocking" category="ui-component"')), VerificationVerdict::Inconclusive, ['"route.order.show" was not in the folder']];
        yield 'a category of its own' => [$verdict('rejected', $finding('workflow="route.order.new" severity="blocking" category="aesthetics"')), VerificationVerdict::Inconclusive, ['"aesthetics" is not a category of feedback']];
        yield 'approved with a blocking finding' => [$verdict('approved', $finding('workflow="route.order.new" severity="blocking" category="layout"')), VerificationVerdict::Inconclusive, ['Approved with a blocking finding']];
        yield 'rejected without a blocking one' => [$verdict('rejected', $finding('workflow="route.order.new" severity="minor" category="layout"')), VerificationVerdict::Inconclusive, ['Rejected without a blocking finding']];
        yield 'not XML' => ['The change looks fine to me.', VerificationVerdict::Inconclusive, ['verdict.xml']];
        yield 'a verdict of another kind' => [$verdict('maybe'), VerificationVerdict::Inconclusive, ['verdict.xml']];
        yield 'no verdict' => [null, VerificationVerdict::Inconclusive, ['No verdict']];
    }

    public function testASelectorOnAWorkflowWithoutCapturesCannotBeChecked(): void
    {
        $this->recordRun('2026-10-20-125900', null);
        $folder = (string) $this->prepare([self::TEMPLATE]);
        file_put_contents($folder.'/verdict.xml', '<verification xmlns="https://github.com/jul6art/devtools/schema/verification/1" schema-version="1" verdict="rejected"><finding workflow="route.order.new" severity="blocking" category="ui-component" selector="#order_product">Native select.</finding></verification>');

        $outcome = new VerificationCollector()->collect($folder, new \DateTimeImmutable('2026-10-20T14:00:00+00:00'));

        self::assertSame(['"route.order.new" names #order_product, but no page of route.order.new was captured: run its scenarios first.'], $outcome->reasons);
    }

    public function testTheCollectCommandSaysTheVerdictAndExitsOnIt(): void
    {
        $folder = (string) $this->prepare([self::TEMPLATE]);
        $tester = new CommandTester(new VerifyCollectCommand(new FrozenClock(new \DateTimeImmutable('2026-10-20T14:00:00+00:00'))));

        self::assertSame(1, $tester->execute(['--path' => $this->project]), 'The latest folder, without a verdict yet.');
        self::assertStringContainsString('Verdict: inconclusive', $tester->getDisplay());

        file_put_contents($folder.'/verdict.xml', '<verification xmlns="https://github.com/jul6art/devtools/schema/verification/1" schema-version="1" verdict="rejected"><finding workflow="route.order.new" severity="blocking" category="ui-component" selector="#order_product">Native select.</finding></verification>');
        self::assertSame(1, $tester->execute(['--path' => $this->project, 'folder' => self::FOLDER]));
        self::assertStringContainsString('✗ route.order.new · ui-component · #order_product — Native select.', $tester->getDisplay());
        self::assertStringContainsString('1 blocking findings', $tester->getDisplay());

        file_put_contents($folder.'/verdict.xml', '<verification xmlns="https://github.com/jul6art/devtools/schema/verification/1" schema-version="1" verdict="approved"/>');
        self::assertSame(0, $tester->execute(['--path' => $this->project]));
        self::assertStringContainsString('Verdict: approved', $tester->getDisplay());

        self::assertSame(2, $tester->execute(['--path' => $this->project, 'folder' => '.devtools/pending']));
        self::assertSame(2, $tester->execute(['--path' => $this->copyFixtureProject('plain-php')]));
        self::assertSame(2, $tester->execute(['--path' => $this->project.'/nowhere']));
    }

    /**
     * The agent can read, search and list: nothing that writes a file or runs a command.
     */
    public function testTheVerifierIsInstalledReadOnlyAndUninstalledUnlessAdapted(): void
    {
        $install = new CommandTester(new ClaudeInstallCommand());
        self::assertSame(0, $install->execute(['path' => $this->project, '--agents' => true]));

        $agent = (string) file_get_contents($this->project.'/.claude/agents/devtools-verifier.md');
        self::assertSame(1, preg_match('/^---\n(.*?)\n---\n/s', $agent, $frontmatter));
        self::assertStringContainsString('name: devtools-verifier', $frontmatter[1]);
        self::assertSame(1, preg_match('/^tools: (.+)$/m', $frontmatter[1], $tools));
        self::assertSame(['Read', 'Grep', 'Glob'], array_map(trim(...), explode(',', $tools[1])));
        self::assertFileDoesNotExist($this->project.'/.claude/skills/devtools-inspect/SKILL.md', '--agents installs the agent only.');

        $uninstall = new CommandTester(new ClaudeUninstallCommand());
        file_put_contents($this->project.'/.claude/agents/devtools-verifier.md', $agent."\nOur own rule.\n");
        self::assertSame(1, $install->execute(['path' => $this->project, '--agents' => true]), 'Adapted: kept.');
        self::assertSame(0, $uninstall->execute(['path' => $this->project, '--agents' => true]));
        self::assertStringContainsString('was modified in this project: it stays', (string) preg_replace('/\s+/', ' ', $uninstall->getDisplay()));

        self::assertSame(0, $install->execute(['path' => $this->project, '--agents' => true, '--force' => true]));
        self::assertSame(0, $uninstall->execute(['path' => $this->project, '--agents' => true]));
        self::assertFileDoesNotExist($this->project.'/.claude/agents/devtools-verifier.md');
        self::assertSame(0, $uninstall->execute(['path' => $this->project, '--agents' => true]));
        self::assertStringContainsString('No DevTools verifier', $uninstall->getDisplay());
    }

    /**
     * @param list<string> $changed
     */
    private function prepare(array $changed): ?string
    {
        return new VerificationPreparer()->prepare($this->directory(), new WorkflowTypeRegistry(), $changed, new \DateTimeImmutable('2026-10-20T13:00:00+00:00'));
    }

    private function directory(): DevToolsDirectory
    {
        return DevToolsDirectory::documented(new ProjectRoot($this->project));
    }

    /**
     * A run of the skeletons: the screenshot of the order form — its DOM, its diff with the baseline — or none.
     */
    private function recordRun(string $name, ?string $page): void
    {
        $artifacts = $this->project.'/.devtools/runs/'.$name;
        mkdir($artifacts.'/route.order.new/smoke', 0o777, true);
        $steps = [new StepResult(1, 'goto', Verdict::Passed)];

        if (null !== $page) {
            foreach (['order-new.png' => 'png of '.$name, 'order-new.html' => $page, 'order-new.diff.png' => 'diff', 'order-new.dom.diff' => '- <select>'] as $file => $content) {
                file_put_contents($artifacts.'/route.order.new/smoke/'.$file, $content);
            }

            $steps[] = new StepResult(2, 'screenshot', Verdict::Passed, screenshot: 'route.order.new/smoke/order-new.png', dom: 'route.order.new/smoke/order-new.html', visual: new VisualResult(VisualVerdict::WithinThreshold, 0.05, 'route.order.new/smoke/order-new.diff.png', 'route.order.new/smoke/order-new.dom.diff'));
        }

        new XmlRunWriter()->write($artifacts.'/result.xml', new RunResult(new \DateTimeImmutable('2026-10-20T12:00:00+00:00'), 'http://app.localhost', [new ScenarioResult('route.order.new/smoke', $steps), new ScenarioResult('route.health/smoke', [new StepResult(1, 'goto', Verdict::Failed)])]));
    }

    /**
     * The files of the folder, then its manifest.
     */
    private static function describe(string $folder): string
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo) {
                $files[] = substr($file->getPathname(), \strlen($folder) + 1);
            }
        }

        sort($files, \SORT_STRING);

        return implode("\n", $files)."\n\n".file_get_contents($folder.'/manifest.xml');
    }
}
