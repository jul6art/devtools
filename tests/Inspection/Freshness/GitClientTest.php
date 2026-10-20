<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Freshness;

use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\Freshness\GitState;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Each question freshness asks git, answered by a real repository of the test's own (ADR-0010) — and the
 * answer when git cannot answer: nothing, never an error, since git only narrows what gets hashed.
 */
#[CoversClass(GitClient::class)]
#[CoversClass(GitState::class)]
final class GitClientTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testAProjectInASubdirectoryKnowsItsCommitItsBranchAndItsPrefix(): void
    {
        $repository = $this->repositoryWithAProjectIn('app');

        $state = new GitClient()->state(new ProjectRoot($repository->path.'/app'));

        self::assertEquals(new GitState($repository->head(), 'main', 'app/'), $state);
    }

    public function testADetachedHeadHasNoBranch(): void
    {
        $repository = $this->repositoryWithAProjectIn('app');
        $repository->git('checkout', '--quiet', '--detach');

        self::assertNull(new GitClient()->state(new ProjectRoot($repository->path))?->branch);
    }

    /**
     * Paths come back relative to the project, and what lies beside it in the repository is not its change.
     */
    public function testTheWorkingTreeIsReadFromTheProjectAndLeavesItsSurroundingsOut(): void
    {
        $repository = $this->repositoryWithAProjectIn('app');
        $root = new ProjectRoot($repository->path.'/app');
        file_put_contents($repository->path.'/app/src/Order.php', "<?php // changed\n");
        file_put_contents($repository->path.'/app/src/New.php', "<?php\n");
        file_put_contents($repository->path.'/beside.txt', "not the project's\n");
        mkdir($repository->path.'/app/.devtools');
        file_put_contents($repository->path.'/app/.devtools/index.xml', '<index/>');

        $git = new GitClient();

        self::assertSame(['src/New.php', 'src/Order.php'], self::sorted($git->workingTree($root, $git->state($root) ?? self::fail('No state.'))));
    }

    /**
     * A rename is two changes: the file that appears, and the one that is gone — the workflow that read the
     * old path is as concerned as the one that will read the new one.
     */
    public function testARenameReportsTheNewPathAndTheOldOne(): void
    {
        $repository = $this->repositoryWithAProjectIn('.');
        $repository->git('mv', 'src/Order.php', 'src/Purchase.php');
        $root = new ProjectRoot($repository->path);
        $git = new GitClient();

        self::assertSame(['src/Order.php', 'src/Purchase.php'], self::sorted($git->workingTree($root, $git->state($root) ?? self::fail('No state.'))));
    }

    /**
     * A commit, not any object: the hash of a file's content exists in the repository too, and taking it
     * for a commit would send freshness looking for history that is not there.
     */
    public function testACommitExistsOnlyAsACommit(): void
    {
        $repository = $this->repositoryWithAProjectIn('.');
        $root = new ProjectRoot($repository->path);
        $blob = trim($repository->git('rev-parse', 'HEAD:src/Order.php'));
        $git = new GitClient();

        self::assertTrue($git->commitExists($root, $repository->head()));
        self::assertFalse($git->commitExists($root, $blob));
        self::assertFalse($git->commitExists($root, 'f00df00df00df00df00df00df00df00df00df00d'));
    }

    /**
     * Between a commit and HEAD — what is committed, not what lies in the working tree: that one is
     * `workingTree()`'s question.
     */
    public function testWhatChangedSinceACommitIsReadUpToHead(): void
    {
        $repository = $this->repositoryWithAProjectIn('.');
        $root = new ProjectRoot($repository->path);
        $since = $repository->head();
        file_put_contents($repository->path.'/src/Order.php', "<?php // changed\n");
        file_put_contents($repository->path.'/src/Line.php', "<?php\n");
        $repository->commitAll('change');
        file_put_contents($repository->path.'/src/Other.php', "<?php // not committed\n");

        self::assertSame(['src/Line.php', 'src/Order.php'], new GitClient()->changedSince($root, $since));
        self::assertSame([], new GitClient()->changedSince($root, 'f00df00df00df00df00df00df00df00df00df00d'));
    }

    /**
     * An excluded directory is named however the caller wrote it: `/.devtools/` is `.devtools` to git,
     * which refuses a pathspec starting with a slash — and would then answer nothing at all.
     */
    public function testAnExcludedDirectoryIsLeftOutHoweverItIsWritten(): void
    {
        $repository = $this->repositoryWithAProjectIn('.');
        $root = new ProjectRoot($repository->path);
        file_put_contents($repository->path.'/src/Order.php', "<?php // changed\n");
        mkdir($repository->path.'/.devtools');
        file_put_contents($repository->path.'/.devtools/index.xml', '<index/>');
        mkdir($repository->path.'/docs/workflows', 0o777, true);
        file_put_contents($repository->path.'/docs/workflows/workflows.md', '# Workflows');
        $git = new GitClient();

        self::assertSame(['src/Order.php'], $git->workingTree($root, $git->state($root) ?? self::fail('No state.'), ['/.devtools/', 'docs/workflows/']));
    }

    /**
     * Each entry of `git status -z` is two status letters, a space and the path: a one-letter file name is
     * the shortest entry there is, and still one.
     */
    public function testAOneLetterFileIsAChangeLikeAnyOther(): void
    {
        $repository = $this->repositoryWithAProjectIn('.');
        file_put_contents($repository->path.'/x', "one\n");
        $repository->commitAll('a one-letter file');
        file_put_contents($repository->path.'/x', "two\n");
        $root = new ProjectRoot($repository->path);
        $git = new GitClient();

        self::assertSame(['x'], $git->workingTree($root, $git->state($root) ?? self::fail('No state.')));
    }

    /**
     * With copy detection on (`status.renames=copies`), a copy is printed like a rename: the new path, then
     * the one it was copied from.
     */
    public function testACopyReportsTheNewPathAndTheOneItCameFrom(): void
    {
        $repository = $this->repositoryWithAProjectIn('.');
        $repository->git('config', 'status.renames', 'copies');
        copy($repository->path.'/src/Order.php', $repository->path.'/src/Copy.php');
        file_put_contents($repository->path.'/src/Order.php', "\n// changed\n", \FILE_APPEND);
        $repository->git('add', '--all');
        self::assertStringContainsString('C  src/Order.php -> src/Copy.php', $repository->git('status', '--porcelain=v1'), 'git detected the copy, or the test proves nothing.');
        $root = new ProjectRoot($repository->path);
        $git = new GitClient();

        self::assertSame(['src/Copy.php', 'src/Order.php'], self::sorted($git->workingTree($root, $git->state($root) ?? self::fail('No state.'))));
    }

    /**
     * A file renamed away and a new file at its old path: git names the old path twice. It is listed once,
     * and the list stays a list.
     */
    public function testAPathGitNamesTwiceIsListedOnce(): void
    {
        $repository = $this->repositoryWithAProjectIn('.');
        $repository->git('mv', 'src/Order.php', 'src/Purchase.php');
        file_put_contents($repository->path.'/src/Order.php', "<?php // a new one\n");
        file_put_contents($repository->path.'/src/Zeta.php', "<?php\n");
        $root = new ProjectRoot($repository->path);
        $git = new GitClient();

        self::assertSame(['src/Purchase.php', 'src/Order.php', 'src/Zeta.php'], $git->workingTree($root, $git->state($root) ?? self::fail('No state.')));
    }

    /**
     * ADR-0016: the files of one commit — the root commit included — relative to the project, in one
     * process; a reference git does not know is no answer, not an empty one.
     */
    public function testTheFilesOfACommitAreReadInOneProcessRelativeToTheProject(): void
    {
        $repository = $this->repositoryWithAProjectIn('app');
        $root = new ProjectRoot($repository->path.'/app');
        $initial = $repository->head();
        file_put_contents($repository->path.'/app/src/Order.php', "<?php // changed\n");
        file_put_contents($repository->path.'/app/src/Line.php', "<?php\n");
        file_put_contents($repository->path.'/beside.txt', "not the project's\n");
        $repository->commitAll('change');
        $git = new GitClient();

        self::assertSame(['src/Line.php', 'src/Order.php'], $git->filesOfCommit($root, 'HEAD'));
        self::assertSame(1, $git->processes());
        self::assertSame(['src/Order.php', 'src/Other.php'], $git->filesOfCommit($root, $initial), 'The root commit has no parent to compare with, and still has files.');
        self::assertNull($git->filesOfCommit($root, 'f00df00df00df00df00df00df00df00df00df00d'));
    }

    public function testTheFilesOfARangeAreReadInOneProcessRelativeToTheProject(): void
    {
        $repository = $this->repositoryWithAProjectIn('app');
        $root = new ProjectRoot($repository->path.'/app');
        $from = $repository->head();
        file_put_contents($repository->path.'/app/src/Order.php', "<?php // changed\n");
        $repository->commitAll('one');
        file_put_contents($repository->path.'/app/src/Line.php', "<?php\n");
        file_put_contents($repository->path.'/beside.txt', "not the project's\n");
        $to = $repository->commitAll('two');
        $git = new GitClient();

        self::assertSame(['src/Line.php', 'src/Order.php'], $git->filesOfRange($root, $from.'..'.$to));
        self::assertSame(1, $git->processes());
        self::assertNull($git->filesOfRange($root, 'f00df00df00df00df00df00df00df00df00df00d..HEAD'));
    }

    /**
     * The working tree in one process: the project's place in the repository is found on the disk, from
     * the `.git` above it, instead of asking git a second question.
     */
    public function testTheWorkingTreeChangesAreReadInOneProcessFromAProjectInASubdirectory(): void
    {
        $repository = $this->repositoryWithAProjectIn('app');
        $root = new ProjectRoot($repository->path.'/app');
        file_put_contents($repository->path.'/app/src/Order.php', "<?php // changed\n");
        file_put_contents($repository->path.'/app/src/New.php', "<?php\n");
        $repository->git('mv', 'app/src/Other.php', 'app/src/Renamed.php');
        file_put_contents($repository->path.'/beside.txt', "not the project's\n");
        $git = new GitClient();

        self::assertSame(['src/New.php', 'src/Order.php', 'src/Other.php', 'src/Renamed.php'], self::sorted($git->workingTreeChanges($root) ?? self::fail('No answer.')));
        self::assertSame(1, $git->processes());
    }

    public function testAProjectAtTheRootOfItsRepositoryHasNoPrefix(): void
    {
        $repository = $this->repositoryWithAProjectIn('.');
        file_put_contents($repository->path.'/src/Order.php', "<?php // changed\n");

        self::assertSame(['src/Order.php'], new GitClient()->workingTreeChanges(new ProjectRoot($repository->path)));
    }

    public function testOutsideARepositoryTheWorkingTreeHasNoAnswer(): void
    {
        $directory = $this->temporaryDirectory().'/plain';
        mkdir($directory);
        $git = new GitClient();

        self::assertNull($git->workingTreeChanges(new ProjectRoot($directory)));
        self::assertSame(0, $git->processes(), 'No repository above: git is not even asked.');
    }

    public function testTheDiffShowsWhatChangedSinceACommitAndNothingWithoutPaths(): void
    {
        $repository = $this->repositoryWithAProjectIn('.');
        $commit = $repository->head();
        file_put_contents($repository->path.'/src/Order.php', "<?php\nfinal class Order { public int \$total = 0; }\n");
        file_put_contents($repository->path.'/src/Line.php', "<?php\nfinal class Line {}\n");
        $repository->git('add', 'src/Line.php');
        $root = new ProjectRoot($repository->path);
        $git = new GitClient();

        $diff = $git->diff($root, $commit, ['src/Order.php', 'src/Line.php']);
        self::assertStringContainsString('+final class Order { public int $total = 0; }', $diff);
        self::assertStringContainsString('+final class Line {}', $diff, 'Every path asked for, not the first one only.');
        self::assertSame('', $git->diff($root, $commit, []));
        self::assertSame(1, $git->processes(), 'No path, no process.');
        self::assertSame('', $git->diff($root, 'f00df00df00df00df00df00df00df00df00df00d', ['src/Order.php']), 'A commit git does not know gives no diff.');
    }

    /**
     * ADR-0047: the one call that writes into a project's own code says whether git did it.
     */
    public function testRestoreBringsAFileBackAndSaysWhetherGitDidIt(): void
    {
        $repository = $this->repositoryWithAProjectIn('.');
        $commit = $repository->head();
        $file = $repository->path.'/src/Order.php';
        $original = (string) file_get_contents($file);
        file_put_contents($file, "<?php // changed\n");
        $root = new ProjectRoot($repository->path);
        $git = new GitClient();

        self::assertFalse($git->restore($root, $commit, []), 'Nothing to restore is not a success.');
        self::assertSame(0, $git->processes());
        self::assertFalse($git->restore($root, 'f00df00df00df00df00df00df00df00df00df00d', ['src/Order.php']));
        self::assertStringEqualsFile($file, "<?php // changed\n", 'A refused restore leaves the file as it was.');
        file_put_contents($repository->path.'/src/Other.php', "<?php // changed too\n");
        self::assertTrue($git->restore($root, $commit, ['src/Order.php', 'src/Other.php']));
        self::assertStringEqualsFile($file, $original);
        self::assertStringEqualsFile($repository->path.'/src/Other.php', "<?php\nfinal class Other {}\n", 'Every path asked for, not the first one only.');
    }

    public function testOutsideARepositoryEveryAnswerIsEmpty(): void
    {
        $directory = $this->temporaryDirectory().'/plain';
        mkdir($directory);
        $root = new ProjectRoot($directory);
        $git = new GitClient();
        $previous = getenv('GIT_CEILING_DIRECTORIES');
        // The temporary directory must not be read as part of a repository that happens to contain it.
        putenv('GIT_CEILING_DIRECTORIES='.\dirname($directory));
        $_SERVER['GIT_CEILING_DIRECTORIES'] = \dirname($directory);

        try {
            self::assertNull($git->state($root));
            self::assertSame([], $git->workingTree($root, new GitState('a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', 'main', '')));
            self::assertSame([], $git->changedSince($root, 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0'));
            self::assertFalse($git->commitExists($root, 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0'));
        } finally {
            putenv(false === $previous ? 'GIT_CEILING_DIRECTORIES' : 'GIT_CEILING_DIRECTORIES='.$previous);
            if (false === $previous) {
                unset($_SERVER['GIT_CEILING_DIRECTORIES']);
            } else {
                $_SERVER['GIT_CEILING_DIRECTORIES'] = $previous;
            }
        }
    }

    /**
     * A git that cannot even start — here, the project vanished under the inspection — is no state, not a
     * crash: the inspection then hashes everything, which is slower and still right.
     */
    public function testAGitThatCannotStartIsNoState(): void
    {
        $directory = $this->temporaryDirectory().'/vanishing';
        mkdir($directory);
        $root = new ProjectRoot($directory);
        rmdir($directory);
        $git = new GitClient();

        self::assertNull($git->state($root));
        self::assertSame(1, $git->processes(), 'The attempt is counted like any other.');
        self::assertFalse($git->commitExists($root, 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0'), 'A git that did not run answered nothing.');
    }

    private function repositoryWithAProjectIn(string $directory): GitRepository
    {
        $repository = $this->temporaryDirectory().'/repository';
        $project = '.' === $directory ? $repository : $repository.'/'.$directory;
        mkdir($project.'/src', 0o777, true);
        file_put_contents($project.'/src/Order.php', "<?php\nfinal class Order {}\n");
        file_put_contents($project.'/src/Other.php', "<?php\nfinal class Other {}\n");

        return GitRepository::initialise($repository);
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function sorted(array $paths): array
    {
        sort($paths);

        return $paths;
    }
}
