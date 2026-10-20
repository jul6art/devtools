<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Freshness;

use Jul6Art\DevTools\Inspection\Freshness\DecisionKind;
use Jul6Art\DevTools\Inspection\Freshness\FileHasher;
use Jul6Art\DevTools\Inspection\Freshness\FreshnessDecision;
use Jul6Art\DevTools\Inspection\Freshness\FreshnessResolver;
use Jul6Art\DevTools\Inspection\Freshness\Reason;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Tracking\Generation;
use Jul6Art\DevTools\Tracking\GenerationMode;
use Jul6Art\DevTools\Tracking\Revision;
use Jul6Art\DevTools\Tracking\TrackedFile;
use Jul6Art\DevTools\Tracking\TrackingDocument;
use Jul6Art\DevTools\Tracking\TrackingStatus;
use Jul6Art\DevTools\Tracking\VcsState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The decision itself, without a pipeline around it: what the resolver says for each state of the files,
 * the tests and the packages, and exactly why. `FreshnessTest` proves the same rules hold end to end in a
 * real repository; this one makes each rule fail on its own, in milliseconds.
 */
#[CoversClass(FreshnessResolver::class)]
#[CoversClass(FreshnessDecision::class)]
#[CoversClass(Reason::class)]
#[CoversClass(FileHasher::class)]
final class FreshnessResolverTest extends TestCase
{
    use UsesTemporaryDirectory;

    private ProjectRoot $root;

    protected function setUp(): void
    {
        $project = $this->temporaryDirectory().'/project';
        mkdir($project.'/src', 0o777, true);

        foreach (['A', 'B', 'C'] as $name) {
            file_put_contents($project.'/src/'.$name.'.php', "<?php\nfinal class {$name} {}\n");
        }

        $this->root = new ProjectRoot($project);
    }

    public function testAWorkflowNothingTrackedYetIsCreated(): void
    {
        $decision = $this->resolve([self::workflow('command.a', ['src/A.php'])], [])['command.a'];

        self::assertSame(DecisionKind::Create, $decision->kind);
        self::assertEquals([Reason::noPreviousTracking()], $decision->reasons);
    }

    public function testAWorkflowTheCodeNoLongerHasIsOrphanedBecauseItsEntryPointIsGone(): void
    {
        $decision = $this->resolve([], [$this->tracked('command.a', ['src/A.php'])])['command.a'];

        self::assertSame(DecisionKind::Orphan, $decision->kind);
        self::assertEquals([Reason::entryPointGone()], $decision->reasons);
    }

    public function testDecisionsAreSortedByIdentifierOrphansIncluded(): void
    {
        $decisions = $this->resolve(
            [self::workflow('route.z', ['src/A.php']), self::workflow('route.a', ['src/A.php'])],
            [$this->tracked('command.gone', ['src/A.php'])],
        );

        self::assertSame(['command.gone', 'route.a', 'route.z'], array_keys($decisions));
    }

    /**
     * Without the two last arguments nothing is forced: the default must be the run that rewrites nothing.
     */
    public function testUnchangedFilesKeepThePageAndNothingIsForcedByDefault(): void
    {
        $hasher = new FileHasher();
        $decisions = new FreshnessResolver($hasher)->resolve($this->root, [self::workflow('command.a', ['src/A.php', 'src/B.php'])], ['command.a' => $this->tracked('command.a', ['src/A.php', 'src/B.php'])], null);

        self::assertSame(DecisionKind::Keep, $decisions['command.a']->kind);
        self::assertSame([], $decisions['command.a']->reasons);
        self::assertSame(2, $hasher->hashes(), 'Without git, every file is hashed.');
    }

    /**
     * A file the workflow no longer reaches is removed — not also changed, even though its bytes moved.
     */
    public function testAFileTheWorkflowLeftIsRemovedAndNotAlsoChanged(): void
    {
        $tracked = $this->tracked('command.a', ['src/A.php', 'src/B.php']);
        file_put_contents($this->root->absolute('src/B.php'), "<?php // changed\n");

        $decision = $this->resolve([self::workflow('command.a', ['src/A.php'])], [$tracked])['command.a'];

        self::assertEquals([Reason::filesRemoved(['src/B.php'])], $decision->reasons);
    }

    /**
     * ADR-0010: git designates, the hash decides — and only what git designates is hashed.
     */
    public function testOnlyTheFilesGitDesignatesAreHashed(): void
    {
        $tracked = $this->tracked('command.a', ['src/A.php', 'src/B.php']);
        file_put_contents($this->root->absolute('src/A.php'), "<?php // changed\n");
        file_put_contents($this->root->absolute('src/B.php'), "<?php // changed\n");
        $hasher = new FileHasher();

        $decision = new FreshnessResolver($hasher)->resolve($this->root, [self::workflow('command.a', ['src/A.php', 'src/B.php'])], ['command.a' => $tracked], ['src/B.php', 'src/C.php'])['command.a'];

        self::assertEquals([Reason::filesChanged(['src/B.php'])], $decision->reasons);
        self::assertSame(1, $hasher->hashes(), 'A file git does not name is not hashed; a file the workflow does not have is not either.');
    }

    public function testEveryReasonIsGivenInOrder(): void
    {
        $tracked = $this->tracked('command.a', ['src/A.php', 'src/B.php'], ['symfony/form' => '7.4.3', 'doctrine/orm' => '3.5.2'], ['spec/OldTest.php']);
        file_put_contents($this->root->absolute('src/A.php'), "<?php // changed\n");

        $decision = new FreshnessResolver(new FileHasher())->resolve(
            $this->root,
            [self::workflow('command.a', ['src/A.php', 'src/C.php'], ['symfony/form' => '8.0.1', 'doctrine/orm' => '4.0.0'], ['spec/NewTest.php'])],
            ['command.a' => $tracked],
            null,
            ['command.a'],
        )['command.a'];

        self::assertSame(DecisionKind::Rewrite, $decision->kind);
        self::assertEquals([
            Reason::forced(),
            Reason::filesChanged(['src/A.php']),
            // Files then tests, sorted together: `spec/` comes before `src/`.
            Reason::filesRemoved(['spec/OldTest.php', 'src/B.php']),
            Reason::filesAdded(['spec/NewTest.php', 'src/C.php']),
            Reason::packageMajor('doctrine/orm', '3.5.2', '4.0.0'),
            Reason::packageMajor('symfony/form', '7.4.3', '8.0.1'),
        ], $decision->reasons, 'Packages in the order the model keeps them: by name.');
    }

    /**
     * A path can be both a file the workflow reaches and a test that covers it (a plain-PHP project whose
     * tests are reached by the graph): leaving, or arriving, it is named once.
     */
    public function testAPathThatIsBothAFileAndATestIsNamedOnce(): void
    {
        file_put_contents($this->root->absolute('src/T.php'), "<?php\n");
        $tracked = $this->tracked('command.a', ['src/A.php', 'src/T.php'], tests: ['src/T.php']);

        $gone = $this->resolve([self::workflow('command.a', ['src/A.php'])], [$tracked])['command.a'];
        self::assertEquals([Reason::filesRemoved(['src/T.php'])], $gone->reasons);

        $back = $this->resolve([self::workflow('command.a', ['src/A.php', 'src/T.php'], tests: ['src/T.php'])], [$this->tracked('command.a', ['src/A.php'])])['command.a'];
        self::assertEquals([Reason::filesAdded(['src/T.php'])], $back->reasons);
    }

    /**
     * A minor or a patch release changes nothing a page says; a major one may. A version without a number
     * (`dev-main`) is compared as it is written.
     */
    public function testOnlyAMajorVersionOfAPackageIsAChange(): void
    {
        $tracked = $this->tracked('command.a', ['src/A.php'], ['symfony/form' => '7.4.3', 'acme/tools' => 'dev-main', 'acme/lib' => 'v2.1']);

        $minor = $this->resolve([self::workflow('command.a', ['src/A.php'], ['symfony/form' => '7.9.0', 'acme/tools' => 'dev-main', 'acme/lib' => 'v2.9'])], [$tracked])['command.a'];
        self::assertSame(DecisionKind::Keep, $minor->kind);

        $branch = $this->resolve([self::workflow('command.a', ['src/A.php'], ['acme/tools' => 'dev-next'])], [$tracked])['command.a'];
        self::assertEquals([Reason::packageMajor('acme/tools', 'dev-main', 'dev-next')], $branch->reasons);

        $added = $this->resolve([self::workflow('command.a', ['src/A.php'], ['new/package' => '1.0.0'])], [$tracked])['command.a'];
        self::assertSame(DecisionKind::Keep, $added->kind, 'A package the page never named is no change of it.');
    }

    public function testAManualPageIsNeverRewrittenButItsChangeIsSaid(): void
    {
        $tracked = $this->tracked('command.a', ['src/A.php'], status: TrackingStatus::Manual);

        self::assertSame(DecisionKind::Keep, $this->resolve([self::workflow('command.a', ['src/A.php'])], [$tracked])['command.a']->kind);

        file_put_contents($this->root->absolute('src/A.php'), "<?php // changed\n");
        $decision = $this->resolve([self::workflow('command.a', ['src/A.php'])], [$tracked])['command.a'];

        self::assertSame(DecisionKind::ManualStale, $decision->kind);
        self::assertSame('files changed: src/A.php', $decision->describe());
    }

    #[DataProvider('statuses')]
    public function testADecisionSaysTheStateOfThePageBeforeItIsApplied(DecisionKind $kind, TrackingStatus $status): void
    {
        self::assertSame($status, new FreshnessDecision(new WorkflowId('command.a'), $kind, [])->status());
    }

    /**
     * @return iterable<string, array{DecisionKind, TrackingStatus}>
     */
    public static function statuses(): iterable
    {
        yield 'create' => [DecisionKind::Create, TrackingStatus::Fresh];
        yield 'keep' => [DecisionKind::Keep, TrackingStatus::Fresh];
        yield 'rewrite' => [DecisionKind::Rewrite, TrackingStatus::Stale];
        yield 'manual stale' => [DecisionKind::ManualStale, TrackingStatus::Stale];
        yield 'orphan' => [DecisionKind::Orphan, TrackingStatus::Orphaned];
    }

    /**
     * The words of the page's history line, one per kind of reason.
     */
    #[DataProvider('descriptions')]
    public function testEachReasonSaysItselfInTheWordsOfTheHistory(Reason $reason, string $line): void
    {
        self::assertSame($line, $reason->describe());
    }

    /**
     * @return iterable<string, array{Reason, string}>
     */
    public static function descriptions(): iterable
    {
        yield 'initial' => [Reason::noPreviousTracking(), 'initial'];
        yield 'forced' => [Reason::forced(), 'forced'];
        yield 'changed' => [Reason::filesChanged(['src/A.php', 'src/B.php']), 'files changed: src/A.php, src/B.php'];
        yield 'removed' => [Reason::filesRemoved(['src/A.php', 'src/B.php']), 'files removed: src/A.php, src/B.php'];
        yield 'added' => [Reason::filesAdded(['src/A.php', 'src/B.php']), 'files added: src/A.php, src/B.php'];
        yield 'major' => [Reason::packageMajor('symfony/form', '7.4.3', '8.0.1'), 'symfony/form 7.4.3 → 8.0.1'];
        yield 'gone' => [Reason::entryPointGone(), 'entry point gone'];
        yield 'back' => [Reason::entryPointBack(), 'entry point back'];
    }

    public function testAFileThatDoesNotExistHasNoHash(): void
    {
        self::assertNull(new FileHasher()->hash($this->root->absolute('src/Missing.php')));
    }

    /**
     * @param list<Workflow>         $current
     * @param list<TrackingDocument> $previous
     *
     * @return array<string, FreshnessDecision>
     */
    private function resolve(array $current, array $previous): array
    {
        $byId = [];

        foreach ($previous as $document) {
            $byId[$document->id->value] = $document;
        }

        return new FreshnessResolver(new FileHasher())->resolve($this->root, $current, $byId, null, [], false);
    }

    /**
     * @param list<string>          $files
     * @param array<string, string> $packages
     * @param list<string>          $tests
     */
    private static function workflow(string $id, array $files, array $packages = [], array $tests = []): Workflow
    {
        $id = new WorkflowId($id);
        $type = 'route' === $id->prefix() ? WorkflowType::routes() : WorkflowType::commands();

        return new Workflow(
            id: $id,
            type: $type,
            title: $id->value,
            main: new EntryPoint($id->prefix(), $id->value, new FileRef('src/A.php')),
            files: array_map(static fn (string $path): FileRef => new FileRef($path), $files),
            packages: array_map(static fn (string $name, string $version): PackageRef => new PackageRef($name, $version), array_keys($packages), $packages),
            tests: array_map(static fn (string $path): FileRef => new FileRef($path), $tests),
        );
    }

    /**
     * What the last inspection recorded: the hashes of the files as they are on disk NOW.
     *
     * @param list<string>          $files
     * @param array<string, string> $packages
     * @param list<string>          $tests
     */
    private function tracked(string $id, array $files, array $packages = [], array $tests = [], TrackingStatus $status = TrackingStatus::Fresh): TrackingDocument
    {
        $workflow = self::workflow($id, $files, $packages, $tests);

        return new TrackingDocument(
            $workflow->id,
            $workflow->type,
            $workflow->title,
            new Generation(new \DateTimeImmutable('2026-09-16T15:00:00+02:00'), 'devtools', GenerationMode::NoAi),
            VcsState::none(),
            $workflow->main,
            files: array_map(fn (string $path): TrackedFile => new TrackedFile(new FileRef($path), (string) hash_file('sha256', $this->root->absolute($path))), $files),
            tests: $workflow->tests,
            packages: $workflow->packages,
            status: $status,
            history: [new Revision(new \DateTimeImmutable('2026-09-16T15:00:00+02:00'), null, 'initial')],
        );
    }
}
