<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Tracking;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every path of ADR-0004 is built here and nowhere else: a wrong one is a page written where no reader
 * looks, or a tracking file the next inspection does not find — and so a workflow it believes new.
 */
#[CoversClass(DevToolsDirectory::class)]
final class DevToolsDirectoryTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testTheMachineryLivesInDotDevtoolsAndTheDocumentationWhereTheProjectWantsIt(): void
    {
        $root = $this->temporaryDirectory();
        $directory = new DevToolsDirectory(new ProjectRoot($root), 'documentation/flux/');

        self::assertSame('documentation/flux', $directory->docs, 'Trailing slashes are not part of the name.');
        self::assertSame($root.'/.devtools', $directory->path());
        self::assertSame($root.'/.devtools/pending/x', $directory->path('pending/x'));
        self::assertSame($root.'/.devtools/config.xml', $directory->configFile());
        self::assertSame($root.'/.devtools/stack.xml', $directory->stackFile());
        self::assertSame($root.'/.devtools/index.xml', $directory->indexFile());
        self::assertSame($root.'/.devtools/graph/files-to-workflows.xml', $directory->filesToWorkflowsFile());
        self::assertSame($root.'/.devtools/graph/workflows.mermaid', $directory->overviewFile());
        self::assertSame($root.'/documentation/flux/workflows.md', $directory->menuFile());
    }

    public function testWithoutAnOptionThePagesGoInDocsWorkflows(): void
    {
        self::assertSame('docs/workflows', new DevToolsDirectory(new ProjectRoot($this->temporaryDirectory()))->docs);
    }

    /**
     * Every command but `inspect` finds the pages where the last inspection wrote them.
     */
    public function testAnInspectedProjectIsDocumentedWhereItsIndexSays(): void
    {
        $root = new ProjectRoot($this->temporaryDirectory());

        self::assertSame('docs/workflows', DevToolsDirectory::documented($root)->docs, 'Never inspected: the default.');

        mkdir($root->path.'/.devtools');
        file_put_contents($root->path.'/.devtools/index.xml', '<index xmlns="https://github.com/jul6art/devtools/schema/index/1" schema-version="2" scanned-at="2026-09-16T12:00:00+00:00" docs="documentation/flux"><source vcs="none"/></index>');

        self::assertSame('documentation/flux', DevToolsDirectory::documented($root)->docs);
    }

    public function testAPageAndItsTrackingFileAreNamedAfterTheIdentifier(): void
    {
        $root = $this->temporaryDirectory();
        $directory = new DevToolsDirectory(new ProjectRoot($root));
        $id = new WorkflowId('route.admin.user.edit');

        self::assertSame('docs/workflows/routes/admin.user.edit.md', $directory->pageRelativePath(WorkflowType::routes(), $id));
        self::assertSame($root.'/docs/workflows/routes/admin.user.edit.md', $directory->pageFile(WorkflowType::routes(), $id));
        self::assertSame($root.'/.devtools/workflows/routes/admin.user.edit.xml', $directory->trackingFile(WorkflowType::routes(), $id), 'The tracking file does not move with the group.');
    }

    public function testInAGroupThePageMovesUnderItsDirectoryAndKeepsWhatItsIdentifierSaysBeyondIt(): void
    {
        $root = $this->temporaryDirectory();
        $directory = new DevToolsDirectory(new ProjectRoot($root));
        $id = new WorkflowId('route.admin.user.edit');

        self::assertSame('routes/admin.user/edit.md', DevToolsDirectory::pageInDocs(WorkflowType::routes(), $id, 'admin.user'));
        self::assertSame('docs/workflows/routes/admin.user/edit.md', $directory->pageRelativePath(WorkflowType::routes(), $id, 'admin.user'));
        self::assertSame($root.'/docs/workflows/routes/admin.user/edit.md', $directory->pageFile(WorkflowType::routes(), $id, 'admin.user'));
        self::assertSame('routes/admin.user/README.md', DevToolsDirectory::groupPageInDocs(WorkflowType::routes(), 'admin.user'));
        self::assertSame($root.'/docs/workflows/routes/admin.user/README.md', $directory->groupPageFile(WorkflowType::routes(), 'admin.user'));
    }

    /**
     * ADR-0020 and ADR-0021: a scenario under its workflow's directory, their index beside them, and one
     * directory per run, named after the second it started.
     */
    public function testTheScenariosAndTheirRunsLiveInDotDevtools(): void
    {
        $directory = new DevToolsDirectory(new ProjectRoot($this->temporaryDirectory()));

        self::assertSame($this->temporaryDirectory().'/.devtools/scenarios/route.order.new/happy-path.xml', $directory->scenarioFile(new WorkflowId('route.order.new'), 'happy-path'));
        self::assertSame($this->temporaryDirectory().'/.devtools/scenarios/index.xml', $directory->scenarioIndexFile());
        self::assertSame($this->temporaryDirectory().'/.devtools/baselines', $directory->baselinesDirectory(), 'Versioned: a human accepted them (ADR-0022).');
        self::assertSame($this->temporaryDirectory().'/.devtools/feedback', $directory->feedbackDirectory(), 'Versioned: the feedback of the project (ADR-0025).');
        self::assertSame($this->temporaryDirectory().'/.devtools/runs/2026-10-20-090507', $directory->runDirectory(new \DateTimeImmutable('2026-10-20T09:05:07+02:00')));
        self::assertContains('runs', DevToolsDirectory::IGNORED, 'Screenshots of a machine, never committed.');
    }

    /**
     * The menu links to `.devtools/` from wherever the documentation lives: one `../` per level of it.
     */
    public function testALinkToTheMachineryClimbsOutOfTheDocumentationDirectory(): void
    {
        self::assertSame('../.devtools/stack.xml', DevToolsDirectory::machineryLink('docs', 'stack.xml'));
        self::assertSame('../../.devtools/stack.xml', DevToolsDirectory::machineryLink('docs/workflows', 'stack.xml'));
        self::assertSame('../../../.devtools/graph/workflows.mermaid', DevToolsDirectory::machineryLink('/a/b/c/', 'graph/workflows.mermaid'));
    }

    #[DataProvider('directoriesOutsideTheProject')]
    public function testADocumentationDirectoryOutsideTheProjectIsRefused(string $docs): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a directory of the project');

        new DevToolsDirectory(new ProjectRoot($this->temporaryDirectory()), $docs);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function directoriesOutsideTheProject(): iterable
    {
        yield 'empty' => [''];
        yield 'only slashes' => ['//'];
        yield 'parent' => ['..'];
        yield 'parent inside' => ['docs/../../elsewhere'];
    }
}
