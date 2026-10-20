<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Impact;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\WorkflowsImpactCommand;
use Jul6Art\DevTools\Console\ImpactRenderer;
use Jul6Art\DevTools\Impact\Impact;
use Jul6Art\DevTools\Impact\ImpactedWorkflow;
use Jul6Art\DevTools\Impact\ImpactRelation;
use Jul6Art\DevTools\Impact\ImpactResolver;
use Jul6Art\DevTools\Impact\ImpactUnavailable;
use Jul6Art\DevTools\Impact\XmlImpactWriter;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0016: what a change reaches, read from the map the last inspection of symfony-minimal wrote — through
 * the real Symfony adapter, only the console's answers replayed.
 */
#[CoversClass(ImpactResolver::class)]
#[CoversClass(Impact::class)]
#[CoversClass(ImpactedWorkflow::class)]
#[CoversClass(ImpactRelation::class)]
#[CoversClass(ImpactUnavailable::class)]
#[CoversClass(XmlImpactWriter::class)]
#[CoversClass(ImpactRenderer::class)]
#[CoversClass(WorkflowsImpactCommand::class)]
final class ImpactTest extends TestCase
{
    use AssertsSnapshots;
    use CopiesFixtureProjects;

    private const string ROUTES = '/^route\./';

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        $report = new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($this->project, noAi: true));
        self::assertSame([], $report->errors, implode("\n", $report->errors));
        // The copy and the inspection happen in the same second: the files are older than the map.
        $this->age($this->project.'/src', '-1 hour');
        $this->age($this->project.'/tests', '-1 hour');
    }

    public function testASharedRepositoryReachesEveryWorkflowTraversingItDirectly(): void
    {
        $impact = $this->resolve(['src/Repository/OrderRepository.php']);

        self::assertSame(
            ['async.order-created' => 'direct', 'route.order.index' => 'direct', 'route.order.new' => 'direct', 'route.order.show' => 'direct', 'route.order.validate' => 'direct', 'ui.cart-summary' => 'direct'],
            self::relations($impact),
            'Its four routes, the handler of OrderCreated and the cart component.',
        );
        self::assertSame('docs/workflows/routes/order.new.md', $impact->workflows[2]->page);
        self::assertSame('fresh', $impact->workflows[2]->status->value);
        self::assertSame([], $impact->stale);
    }

    /**
     * A kernel listener is a mechanism of every route (ADR-0043): changing it reaches them all, as a
     * dependency — and none of them at depth 0.
     */
    public function testAKernelListenerReachesEveryRouteAsADependency(): void
    {
        $impact = $this->resolve(['src/EventListener/LocaleListener.php']);
        $routes = array_filter(self::relations($impact), static fn (string $id): bool => 1 === preg_match(self::ROUTES, $id), \ARRAY_FILTER_USE_KEY);

        self::assertSame(['route.customer.edit', 'route.health', 'route.order.index', 'route.order.new', 'route.order.show', 'route.order.validate'], array_keys($routes));
        self::assertSame(['dependency'], array_values(array_unique($routes)));
        self::assertSame([], $this->resolve(['src/EventListener/LocaleListener.php'], 0)->workflows);
        self::assertSame([], $this->resolve(['src/EventListener/LocaleListener.php'], 0)->uncovered, 'Known to the map, only farther than depth 0.');
    }

    public function testATestReachesTheWorkflowItCovers(): void
    {
        self::assertSame(['route.order.index' => 'test'], self::relations($this->resolve(['tests/Controller/OrderControllerTest.php'])));
    }

    /**
     * A workflow reached by several files is listed once, through the strongest link, with every file.
     */
    public function testAWorkflowReachedSeveralWaysIsListedOnceThroughTheStrongestLink(): void
    {
        $impact = $this->resolve(['tests/Controller/OrderControllerTest.php', 'src/Controller/OrderController.php', 'src/EventListener/LocaleListener.php']);
        $index = array_first(array_filter($impact->workflows, static fn (ImpactedWorkflow $workflow): bool => 'route.order.index' === $workflow->id->value)) ?? self::fail('route.order.index is not reached.');

        self::assertSame(ImpactRelation::Direct, $index->relation);
        self::assertSame(['src/Controller/OrderController.php', 'src/EventListener/LocaleListener.php', 'tests/Controller/OrderControllerTest.php'], $index->files);
    }

    public function testAFileNoWorkflowReferencesIsUncoveredAndNotAnError(): void
    {
        file_put_contents($this->project.'/src/Brand.php', "<?php\n");
        $tester = $this->command(['files' => ['src/Brand.php']]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Not covered src/Brand.php', $tester->getDisplay());
    }

    public function testAPathOutsideTheProjectIsRefused(): void
    {
        $tester = $this->command(['files' => ['../../../etc/passwd']]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('resolves outside the project root', $tester->getDisplay());
    }

    /**
     * An absolute path inside the project is the same file — the form a Claude Code hook passes.
     */
    public function testAnAbsolutePathInsideTheProjectIsThatFile(): void
    {
        self::assertSame(self::relations($this->resolve(['src/Repository/OrderRepository.php'])), self::relations($this->resolve([$this->project.'/src/Repository/OrderRepository.php'])));
    }

    /**
     * The map may predate a file changed since the last inspection: the warning comes first, the answer
     * after it.
     */
    public function testAFileChangedAfterTheLastInspectionMakesTheMapSuspect(): void
    {
        touch($this->project.'/src/Service/OrderPricing.php', time() + 60);

        $impact = $this->resolve(['src/Service/OrderPricing.php', 'src/Repository/OrderRepository.php']);

        self::assertSame(['src/Service/OrderPricing.php'], $impact->stale);
        self::assertStringStartsWith(" DevTools — acme/symfony-minimal   workflow impact\n\n ⚠ The map predates a change to src/Service/OrderPricing.php", $this->command(['files' => ['src/Service/OrderPricing.php']])->getDisplay());
    }

    public function testAFileDeletedSinceTheInspectionMakesTheMapSuspectToo(): void
    {
        unlink($this->project.'/src/Service/OrderPricing.php');

        self::assertSame(['src/Service/OrderPricing.php'], $this->resolve(['src/Service/OrderPricing.php'])->stale);
        self::assertSame([], $this->resolve(['src/Never/Existed.php'])->stale, 'A file nobody mapped and nobody has is just uncovered.');
    }

    public function testWithoutAnInspectionThereIsNoMapToRead(): void
    {
        $empty = $this->temporaryDirectory().'/empty';
        mkdir($empty);
        $tester = new CommandTester(new WorkflowsImpactCommand());
        $tester->execute(['files' => ['src/A.php'], '--path' => $empty]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No impact map', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('refusals')]
    public function testAnInputThatCannotBeAnsweredIsRefusedBeforeAnythingIsRead(array $input, string $message): void
    {
        $tester = $this->command($input);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusals(): iterable
    {
        yield 'nothing asked' => [[], 'Give files, or one of --commit, --range, --changed.'];
        yield 'two sources' => [['files' => ['src/A.php'], '--changed' => true], 'Give files, or one of --commit, --range, --changed.'];
        yield 'unknown format' => [['files' => ['src/A.php'], '--format' => 'yaml'], '"yaml" is not a format'];
        yield 'depth' => [['files' => ['src/A.php'], '--depth' => '2'], '--depth is 0 or 1.'];
        yield 'an option for a commit' => [['--commit' => '--output=/tmp/x'], 'is not a commit reference'];
        yield 'a range without its dots' => [['--range' => 'main'], 'is not a range'];
    }

    /**
     * ADR-0016: `--commit`, `--range` and `--changed` each cost ONE git process.
     */
    public function testTheFilesOfACommitCostOneGitProcess(): void
    {
        $this->commitAPricingReview();
        $git = new GitClient();

        self::assertSame(['async.order-created', 'route.order.new'], self::reached($this->command(['--commit' => 'HEAD', '--format' => 'json'], $git)->getDisplay()));
        self::assertSame(1, $git->processes());
    }

    public function testTheFilesOfARangeCostOneGitProcess(): void
    {
        $before = $this->commitAPricingReview();
        $git = new GitClient();

        self::assertSame(['async.order-created', 'route.order.new'], self::reached($this->command(['--range' => $before.'..HEAD', '--format' => 'json'], $git)->getDisplay()));
        self::assertSame(1, $git->processes());
    }

    public function testTheWorkingTreeCostsOneGitProcess(): void
    {
        $this->commitAPricingReview();
        file_put_contents($this->project.'/src/Repository/ProductRepository.php', "\n// reviewed\n", \FILE_APPEND);
        $git = new GitClient();

        self::assertSame(['async.order-created', 'command.app.import-catalog', 'route.order.new'], self::reached($this->command(['--changed' => true, '--format' => 'json'], $git)->getDisplay()));
        self::assertSame(1, $git->processes());
    }

    public function testAnUnknownReferenceIsAFailureNamedAsSuch(): void
    {
        GitRepository::initialise($this->project);

        $tester = $this->command(['--commit' => 'f00df00df00df00df00df00df00df00df00df00d']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('git could not answer', $tester->getDisplay());
    }

    /**
     * The json format is read by tools — hooks, CI — and the xml one follows impact.xsd: both are fixed.
     */
    #[DataProvider('formats')]
    public function testEachFormatIsFixed(string $format, string $snapshot): void
    {
        $tester = $this->command(['files' => ['src/Service/OrderPricing.php', 'src/Brand/New.php'], '--format' => $format]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertMatchesSnapshot($tester->getDisplay(), __DIR__.'/../Fixtures/snapshots/'.$snapshot);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formats(): iterable
    {
        yield 'text' => ['text', 'impact.txt'];
        yield 'json' => ['json', 'impact.json'];
        yield 'xml' => ['xml', 'impact.xml'];
    }

    /**
     * @param list<string> $files
     */
    private function resolve(array $files, int $depth = 1): Impact
    {
        return new ImpactResolver()->resolve(new DevToolsDirectory(new ProjectRoot($this->project)), $files, $depth);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function command(array $input, GitClient $git = new GitClient()): CommandTester
    {
        $tester = new CommandTester(new WorkflowsImpactCommand($git));
        $tester->execute([...$input, '--path' => $this->project], ['decorated' => false]);

        return $tester;
    }

    /**
     * @return array<string, string> workflow => relation
     */
    private static function relations(Impact $impact): array
    {
        $relations = [];

        foreach ($impact->workflows as $workflow) {
            $relations[$workflow->id->value] = $workflow->relation->value;
        }

        return $relations;
    }

    /**
     * The project under git, its pricing service changed in a second commit.
     *
     * @return string the first commit
     */
    private function commitAPricingReview(): string
    {
        $repository = GitRepository::initialise($this->project);
        $before = $repository->head();
        file_put_contents($this->project.'/src/Service/OrderPricing.php', "\n// reviewed\n", \FILE_APPEND);
        $repository->commitAll('pricing reviewed');

        return $before;
    }

    /**
     * @return list<string> the identifiers of the json output
     */
    private static function reached(string $json): array
    {
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['workflows'] ?? null);

        return array_values(array_map(static fn (mixed $workflow): string => \is_array($workflow) && \is_string($workflow['id'] ?? null) ? $workflow['id'] : '', $decoded['workflows']));
    }

    private function age(string $directory, string $modifier): void
    {
        $time = (int) strtotime($modifier);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                touch($file->getPathname(), $time);
            }
        }
    }
}
