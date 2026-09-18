<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Model;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\WorkflowGroup;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkflowGroup::class)]
final class WorkflowGroupTest extends TestCase
{
    public function testAWorkflowOfTheGroupIsWrittenUnderWhatItsIdentifierSaysBeyondIt(): void
    {
        $group = self::group('admin.user');

        self::assertSame('edit', $group->leafOf(new WorkflowId('route.admin.user.edit')));
        self::assertSame('bulk-delete', $group->leafOf(new WorkflowId('route.admin.user.bulk-delete')));
    }

    /**
     * The collision this naming exists to avoid: a list route is `…index`, and the group's own page is
     * `README.md`.
     */
    public function testAnIndexRouteAndTheGroupPageDoNotCollide(): void
    {
        $group = self::group('admin.user');

        self::assertSame('index', $group->leafOf(new WorkflowId('route.admin.user.index')));
    }

    public function testAControllerWithASingleRouteWritesItAsTheIndexOfItsDirectory(): void
    {
        $group = self::group('home');

        self::assertSame('index', $group->leafOf(new WorkflowId('route.home')));
    }

    public function testAnIdentifierThatIsNotItsOwnKeepsItsWholePageName(): void
    {
        $group = self::group('admin.user');

        self::assertSame('other.route', $group->leafOf(new WorkflowId('route.other.route')));
        self::assertFalse($group->holds(new WorkflowId('route.other.route')));
        self::assertTrue($group->holds(new WorkflowId('route.admin.user.edit')));
        self::assertTrue($group->holds(new WorkflowId('route.admin.user')));
    }

    #[DataProvider('malformedDirectories')]
    public function testItRejectsADirectoryThatWouldNotBeSafeAsAPath(string $directory): void
    {
        $this->expectException(InvalidModel::class);

        self::group($directory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedDirectories(): iterable
    {
        yield 'separator' => ['admin/user'];
        yield 'parent' => ['..'];
        yield 'uppercase' => ['Admin.User'];
        yield 'trailing dot' => ['admin.'];
    }

    public function testItRefusesAnEmptyTitle(): void
    {
        $this->expectException(InvalidModel::class);

        new WorkflowGroup('admin.user', '  ', new FileRef('src/Controller/Admin/UserController.php', FileRole::Controller));
    }

    private static function group(string $directory): WorkflowGroup
    {
        return new WorkflowGroup($directory, '/admin/users', new FileRef('src/Controller/Admin/UserController.php', FileRole::Controller));
    }
}
