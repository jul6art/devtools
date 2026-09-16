<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Project;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The last guard before a file is read or written on disk: whatever the model, a draft or a tracking
 * file says, nothing resolves outside the analysed project.
 */
#[CoversClass(ProjectRoot::class)]
#[CoversClass(PathOutsideProject::class)]
final class ProjectRootTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testItResolvesARelativePathInsideTheRoot(): void
    {
        $root = new ProjectRoot($this->temporaryDirectory());

        self::assertSame($this->temporaryDirectory().'/src/Kernel.php', $root->absolute('src/Kernel.php'));
        self::assertSame($this->temporaryDirectory().'/src/Kernel.php', $root->absolute(new FileRef('./src/Kernel.php')));
        self::assertSame('src/Kernel.php', $root->relative($this->temporaryDirectory().'/src/Kernel.php'));
    }

    public function testItRefusesAParentSegmentThatLeavesTheRoot(): void
    {
        $this->expectException(PathOutsideProject::class);

        new ProjectRoot($this->temporaryDirectory())->absolute('src/../../secrets.env');
    }

    public function testAParentSegmentThatStaysInsideIsAccepted(): void
    {
        self::assertSame($this->temporaryDirectory().'/config/routes.yaml', new ProjectRoot($this->temporaryDirectory())->absolute('src/../config/routes.yaml'));
    }

    public function testItRefusesAnAbsolutePathOutsideTheRoot(): void
    {
        $this->expectException(PathOutsideProject::class);

        new ProjectRoot($this->temporaryDirectory())->absolute('/etc/passwd');
    }

    public function testItRefusesASymbolicLinkThatLeavesTheRoot(): void
    {
        $outside = $this->temporaryDirectory().'/outside';
        $project = $this->temporaryDirectory().'/project';
        mkdir($outside);
        mkdir($project);
        symlink($outside, $project.'/linked');

        $root = new ProjectRoot($project);

        try {
            $root->absolute('linked/secret.txt');
            self::fail('A symbolic link leaving the project must be refused.');
        } catch (PathOutsideProject $refused) {
            self::assertStringContainsString('linked/secret.txt', $refused->getMessage());
        }

        $this->expectException(PathOutsideProject::class);

        $root->relative($outside.'/secret.txt');
    }

    public function testTheRootMustBeAnExistingDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProjectRoot($this->temporaryDirectory().'/missing');
    }
}
