<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Freshness;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\Freshness\DecisionKind;
use Jul6Art\DevTools\Inspection\Freshness\FileHasher;
use Jul6Art\DevTools\Inspection\Freshness\FreshnessDecision;
use Jul6Art\DevTools\Inspection\Freshness\FreshnessResolver;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\Freshness\Reason;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\InspectionReport;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Tests\Inspection\Pipeline\FakeAdapter;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tracking\TrackingDocument;
use Jul6Art\DevTools\Tracking\TrackingStatus;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Every case of specs § 7.2 "Fraîcheur", each in a temporary git repository.
 */
#[CoversClass(FreshnessResolver::class)]
#[CoversClass(FreshnessDecision::class)]
#[CoversClass(Reason::class)]
#[CoversClass(GitClient::class)]
#[CoversClass(FileHasher::class)]
final class FreshnessTest extends TestCase
{
    use CopiesFixtureProjects;

    private string $project;

    private GitClient $git;

    private FileHasher $hasher;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        $this->git = new GitClient();
        $this->hasher = new FileHasher();
    }

    public function testASecondRunWithoutChangeModifiesNothing(): void
    {
        $repository = GitRepository::initialise($this->project);
        $this->inspect();
        $repository->commitAll('documentation');
        $before = $this->documentation();

        $report = $this->inspect();

        self::assertSame($before, $this->documentation(), 'Idempotence: zero file modified outside reports/.');
        self::assertSame(6, $report->count('unchanged'));
        self::assertSame(0, $report->count('updated'));
    }

    public function testChangingAFileRewritesExactlyTheWorkflowsTraversingIt(): void
    {
        $repository = GitRepository::initialise($this->project);
        $this->inspect();
        $repository->commitAll('documentation');

        $this->append('src/Repository/ProductRepository.php', "\n// a change\n");
        $repository->commitAll('change a shared repository');
        $report = $this->inspect();

        self::assertSame(['command.app.import-catalog', 'route.order.new'], $this->rewritten($report));
        self::assertEquals([Reason::filesChanged(['src/Repository/ProductRepository.php'])], $report->decision('route.order.new')?->reasons);
        self::assertStringContainsString('| files changed: src/Repository/ProductRepository.php |', (string) file_get_contents($this->project.'/.devtools/workflows/routes/order.new.md'), 'The history gains a line.');
    }

    public function testAChangeUndoneInALaterCommitIsNotAChange(): void
    {
        $repository = GitRepository::initialise($this->project);
        $this->inspect();
        $repository->commitAll('documentation');
        $original = (string) file_get_contents($this->project.'/src/Service/OrderPricing.php');

        $this->append('src/Service/OrderPricing.php', "\n// temporary\n");
        $repository->commitAll('change');
        file_put_contents($this->project.'/src/Service/OrderPricing.php', $original);
        $repository->commitAll('revert');

        self::assertSame([], $this->rewritten($this->inspect()), 'Git lists the file, the hash decides.');
    }

    public function testARemovedFileMakesTheWorkflowStaleAndARemovedEntryPointOrphansIt(): void
    {
        $repository = GitRepository::initialise($this->project);
        $this->inspect();
        $repository->commitAll('documentation');

        file_put_contents($this->project.'/templates/order/new.html.twig', "{% extends 'base.html.twig' %}\n");
        unlink($this->project.'/templates/order/_form.html.twig');
        $repository->commitAll('remove the form partial');
        $report = $this->inspect(dryRun: true);

        $decision = $report->decision('route.order.new');
        self::assertSame(DecisionKind::Rewrite, $decision?->kind);
        self::assertSame(TrackingStatus::Stale, $decision->status());
        self::assertContainsEquals(Reason::filesRemoved(['templates/order/_form.html.twig']), $decision->reasons);

        $orphaned = $this->inspect(adapter: new FakeAdapter(['app_order_show']));
        self::assertSame(DecisionKind::Orphan, $orphaned->decision('route.order.show')?->kind);
        self::assertFileExists($this->project.'/.devtools/workflows/routes/order.show.md');

        $this->inspect(prune: true, adapter: new FakeAdapter(['app_order_show']));
        self::assertFileDoesNotExist($this->project.'/.devtools/workflows/routes/order.show.md');
        self::assertFileDoesNotExist($this->project.'/.devtools/workflows/routes/order.show.xml');
        self::assertStringNotContainsString('route.order.show', (string) file_get_contents($this->project.'/.devtools/index.xml'));
    }

    public function testPruneOnlyDeletesThePagesOfTheTypeBeingWritten(): void
    {
        GitRepository::initialise($this->project);
        $this->inspect();

        $report = new InspectionPipeline(new AdapterResolver([new FakeAdapter(['app_order_show'])]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))
            ->run(new InspectionOptions($this->project, only: 'commands', prune: true));

        self::assertSame([], $report->errors, implode("\n", $report->errors));
        self::assertFileExists($this->project.'/.devtools/workflows/routes/order.show.md', '--only=commands must not delete a route.');
    }

    public function testAForceThatNamesNoWorkflowIsAWarning(): void
    {
        GitRepository::initialise($this->project);
        $this->inspect();

        self::assertSame(['--force=route.invoice.new names no workflow of this project; nothing was forced for it.'], $this->inspect(force: ['route.invoice.new'])->warnings);
    }

    public function testASinceThatIsNotACommitIsRefusedBeforeGitSeesIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--since takes a commit');

        new InspectionOptions($this->project, since: '--output=/tmp/devtools-audit');
    }

    public function testANewFileReachedByAWorkflowRewritesIt(): void
    {
        $repository = GitRepository::initialise($this->project);
        $this->inspect();
        $repository->commitAll('documentation');

        file_put_contents($this->project.'/src/Service/Discount.php', "<?php\nnamespace App\\Service;\nfinal class Discount {}\n");
        $this->replace('src/Service/OrderPricing.php', 'private ProductRepository $products', 'private ProductRepository $products, private Discount $discount');
        $repository->commitAll('discount');

        $report = $this->inspect();

        self::assertSame(['route.order.new'], $this->rewritten($report));
        self::assertNotNull($report->decision('route.order.new'));
        self::assertContainsEquals(Reason::filesAdded(['src/Service/Discount.php']), $report->decision('route.order.new')->reasons);
    }

    public function testATestAppearingOrDisappearingRewritesTheWorkflowItIsListedIn(): void
    {
        $repository = GitRepository::initialise($this->project);
        $this->inspect();
        $repository->commitAll('documentation');

        rename($this->project.'/tests/Service/OrderPricingTest.php', $this->project.'/OrderPricingTest.php.bak');
        $repository->commitAll('test removed');
        $removed = $this->inspect();

        self::assertSame(['command.app.import-catalog', 'route.order.new'], $this->rewritten($removed), 'Every workflow listing the test.');
        self::assertEquals([Reason::filesRemoved(['tests/Service/OrderPricingTest.php'])], $removed->decision('route.order.new')?->reasons);

        rename($this->project.'/OrderPricingTest.php.bak', $this->project.'/tests/Service/OrderPricingTest.php');
        $repository->commitAll('test back');
        $added = $this->inspect();

        self::assertSame(['command.app.import-catalog', 'route.order.new'], $this->rewritten($added));
        self::assertEquals([Reason::filesAdded(['tests/Service/OrderPricingTest.php'])], $added->decision('route.order.new')?->reasons);
    }

    public function testARewrittenHistoryFallsBackOnHashes(): void
    {
        $repository = GitRepository::initialise($this->project);
        $recorded = $repository->head();
        $this->inspect();

        // The very commit the documentation recorded is rewritten away, as a rebase then a gc would.
        $repository->rewriteHistory();
        self::assertStringContainsString('fatal', $this->gitFailure($repository, 'cat-file', '-e', $recorded.'^{commit}'), 'The recorded commit no longer exists.');

        self::assertSame([], $this->rewritten($this->inspect()), 'The recorded commit is gone: every file is hashed, nothing changed.');

        $this->append('src/Service/OrderPricing.php', "\n// after the rebase\n");
        $repository->rewriteHistory();
        self::assertSame('', $repository->git('status', '--porcelain', '--', 'src'), 'The change is committed: only hashing can find it.');

        self::assertSame(['route.order.new'], $this->rewritten($this->inspect()));
    }

    public function testUncommittedAndUntrackedChangesCount(): void
    {
        $repository = GitRepository::initialise($this->project);
        $this->inspect();
        $repository->commitAll('documentation');

        $this->append('src/Entity/Product.php', "\n// not committed\n");
        file_put_contents($this->project.'/src/Service/Discount.php', "<?php\nnamespace App\\Service;\nfinal class Discount {}\n");
        $this->replace('src/Form/OrderType.php', 'use App\Entity\Order;', "use App\\Entity\\Order;\nuse App\\Service\\Discount;");
        $this->replace('src/Form/OrderType.php', "'data_class' => Order::class", "'data_class' => Order::class, 'discount' => Discount::class");

        $report = $this->inspect();

        self::assertContains('route.order.new', $this->rewritten($report));
        self::assertContains('command.app.import-catalog', $this->rewritten($report), 'Product.php is not committed, it still counts.');
        self::assertTrue($this->tracking('route.order.new')->vcs->dirty);
    }

    public function testTheDocumentationDevToolsWroteDoesNotMakeTheProjectDirty(): void
    {
        $repository = GitRepository::initialise($this->project);
        $this->inspect();

        $this->append('src/Service/OrderPricing.php', "\n// committed change\n");
        // init added its work areas to .gitignore: that file is the project's, committed with the code.
        $repository->git('add', 'src', '.gitignore');
        $repository->git('commit', '--quiet', '-m', 'code only, .devtools/ left uncommitted');
        $this->inspect();

        self::assertFalse($this->tracking('route.order.new')->vcs->dirty);
    }

    public function testWithoutGitEveryFileIsHashed(): void
    {
        $this->inspect();
        self::assertNull($this->tracking('route.order.new')->vcs->commit);

        self::assertSame([], $this->rewritten($this->inspect()));

        $this->append('src/Entity/Order.php', "\n// changed\n");
        self::assertSame(['route.order.index', 'route.order.new', 'route.order.show'], $this->rewritten($this->inspect()));
    }

    public function testAMajorVersionOfAPackageRewritesTheWorkflowsUsingIt(): void
    {
        GitRepository::initialise($this->project);
        $this->inspect();

        $this->replace('composer.lock', "\"name\": \"symfony/form\",\n            \"version\": \"v8.1.7\"", "\"name\": \"symfony/form\",\n            \"version\": \"v9.0.0\"");
        $report = $this->inspect();

        self::assertSame(['route.order.new'], $this->rewritten($report));
        self::assertEquals([Reason::packageMajor('symfony/form', '8.1.7', '9.0.0')], $report->decision('route.order.new')?->reasons);
    }

    public function testAManualWorkflowIsNeverRewrittenButItsTrackingIsUpdated(): void
    {
        GitRepository::initialise($this->project);
        $this->inspect();
        $trackingFile = $this->project.'/.devtools/workflows/routes/order.new.xml';
        file_put_contents($trackingFile, str_replace('<status>fresh</status>', '<status>manual</status>', (string) file_get_contents($trackingFile)));
        $page = $this->project.'/.devtools/workflows/routes/order.new.md';
        file_put_contents($page, "# Written by hand\n");

        $this->append('src/Service/OrderPricing.php', "\n// changed\n");
        $report = $this->inspect();

        self::assertSame(DecisionKind::ManualStale, $report->decision('route.order.new')?->kind);
        self::assertStringEqualsFile($page, "# Written by hand\n");
        self::assertSame(TrackingStatus::Manual, $this->tracking('route.order.new')->status);
        self::assertStringContainsString('route.order.new', implode("\n", $report->warnings));
    }

    public function testForceSinceAndDryRun(): void
    {
        $repository = GitRepository::initialise($this->project);
        $this->inspect();
        $documented = $repository->commitAll('documentation');

        self::assertSame(['route.health'], $this->rewritten($this->inspect(force: ['route.health'])));
        self::assertEquals([Reason::forced()], $this->inspect(force: ['route.health'])->decision('route.health')?->reasons);
        self::assertCount(6, $this->rewritten($this->inspect(forceAll: true)));

        $this->append('src/Controller/HealthController.php', "\n// changed\n");
        $repository->commitAll('change');
        $before = $this->documentation();
        $dry = $this->inspect(dryRun: true, since: $documented);

        self::assertSame(['route.health'], $this->rewritten($dry));
        self::assertSame($before, $this->documentation());
    }

    public function testGitIsAskedAtMostFourTimesAndEachFileHashedOnce(): void
    {
        $repository = GitRepository::initialise($this->project);
        $this->inspect();
        $repository->commitAll('documentation');
        $this->append('src/Entity/Order.php', "\n// changed\n");
        $repository->commitAll('change');

        $git = new GitClient();
        $hasher = new FileHasher();
        $this->inspect(git: $git, hasher: $hasher);

        self::assertLessThanOrEqual(4, $git->processes());
        self::assertSame($hasher->distinctFiles(), $hasher->hashes(), 'No file is hashed twice.');
    }

    /**
     * @param list<string> $force
     */
    private function inspect(bool $dryRun = false, array $force = [], bool $forceAll = false, ?string $since = null, bool $prune = false, FakeAdapter $adapter = new FakeAdapter(), ?GitClient $git = null, ?FileHasher $hasher = null): InspectionReport
    {
        $pipeline = new InspectionPipeline(new AdapterResolver([$adapter]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')), git: $git ?? $this->git, hasher: $hasher ?? $this->hasher);
        $report = $pipeline->run(new InspectionOptions($this->project, dryRun: $dryRun, force: $force, forceAll: $forceAll, since: $since, prune: $prune));
        self::assertSame([], $report->errors, implode("\n", $report->errors));

        return $report;
    }

    /**
     * @return list<string>
     */
    private function rewritten(InspectionReport $report): array
    {
        $ids = array_keys(array_filter($report->decisions, static fn (FreshnessDecision $decision): bool => DecisionKind::Rewrite === $decision->kind));
        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    private function documentation(): array
    {
        return array_filter(self::tree($this->project.'/.devtools'), static fn (string $path): bool => !str_starts_with($path, 'reports/'), \ARRAY_FILTER_USE_KEY);
    }

    private function tracking(string $id): TrackingDocument
    {
        [$prefix, $name] = explode('.', $id, 2);
        $type = ['route' => 'routes', 'command' => 'commands', 'event' => 'events'][$prefix];

        return new XmlTrackingStore(new WorkflowTypeRegistry())->read(\sprintf('%s/.devtools/workflows/%s/%s.xml', $this->project, $type, $name));
    }

    private function gitFailure(GitRepository $repository, string ...$arguments): string
    {
        try {
            $repository->git(...$arguments);

            return '';
        } catch (ProcessFailedException $failure) {
            return 'fatal: '.$failure->getProcess()->getErrorOutput();
        }
    }

    private function append(string $file, string $content): void
    {
        file_put_contents($this->project.'/'.$file, $content, \FILE_APPEND);
    }

    private function replace(string $file, string $search, string $replace): void
    {
        $path = $this->project.'/'.$file;
        $original = (string) file_get_contents($path);
        self::assertStringContainsString($search, $original);
        file_put_contents($path, str_replace($search, $replace, $original));
    }
}
