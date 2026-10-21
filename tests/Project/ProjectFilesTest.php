<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Project;

use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Project\ProjectFiles;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ProjectFiles::class)]
#[CoversClass(GitClient::class)]
final class ProjectFilesTest extends TestCase
{
    use UsesTemporaryDirectory;

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->temporaryDirectory().'/project';

        foreach (['src/Kernel.php', 'src/Form/OrderType.php', 'vendor/acme/lib.php', 'var/cache/x.php', 'notes.tmp', 'build/app.js'] as $file) {
            new Filesystem()->dumpFile($this->project.'/'.$file, '<?php');
        }
    }

    public function testOutsideARepositoryTheDiskSaysWithoutTheExcludedDirectories(): void
    {
        self::assertSame(['notes.tmp', 'src/Form/OrderType.php', 'src/Kernel.php'], new ProjectFiles()->of(new ProjectRoot($this->project)));
        self::assertSame(['build/app.js', 'notes.tmp', 'src/Form/OrderType.php', 'src/Kernel.php'], new ProjectFiles()->of(new ProjectRoot($this->project), ['vendor', 'var']));
    }

    /**
     * In a repository, git says: what it ignores is not a file of the project, what is new is — and an excluded
     * directory stays excluded even when it is committed.
     */
    public function testInARepositoryGitSaysAndTheExcludedDirectoriesStayExcluded(): void
    {
        file_put_contents($this->project.'/.gitignore', "*.tmp\n");
        $repository = GitRepository::initialise($this->project);
        $repository->commitAll('initial');
        file_put_contents($this->project.'/src/New.php', '<?php');
        unlink($this->project.'/src/Kernel.php');

        self::assertSame(['.gitignore', 'src/Form/OrderType.php', 'src/New.php'], new ProjectFiles()->of(new ProjectRoot($this->project)));
    }
}
