<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Diff;

use Jul6Art\DevTools\Inspection\Diff\ChangeGroup;
use Jul6Art\DevTools\Inspection\Diff\ChangeNature;
use Jul6Art\DevTools\Inspection\Diff\ChangeSubject;
use Jul6Art\DevTools\Inspection\Diff\WorkflowChange;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0046 § 5: the review is grouped by fact, never by workflow — on a real project, 233 of 259
 * workflows share one entity, and one changed line in it would otherwise fill 233 screens.
 */
#[CoversClass(ChangeGroup::class)]
final class ChangeGroupTest extends TestCase
{
    public function testAFactSharedByTwoWorkflowsIsOneGroupThatCountsThem(): void
    {
        $groups = ChangeGroup::of([
            'route.admin.user.index' => [self::email(), self::security('admin_user_index')],
            'route.admin.customer.index' => [self::email()],
        ]);

        self::assertCount(2, $groups);
        self::assertSame('App\Entity\User::email', $groups[0]->change->target);
        self::assertSame(['route.admin.customer.index', 'route.admin.user.index'], $groups[0]->workflows, 'Sorted, so that two runs print the same thing.');
        self::assertSame(2, $groups[0]->count());
        self::assertSame(['route.admin.user.index'], $groups[1]->workflows);
    }

    public function testTheWidestFactComesFirst(): void
    {
        $groups = ChangeGroup::of([
            'route.a' => [self::security('a'), self::email()],
            'route.b' => [self::email()],
            'route.c' => [self::email()],
        ]);

        self::assertSame([3, 1], array_map(static fn (ChangeGroup $group): int => $group->count(), $groups));
    }

    public function testNoChangeAtAllIsNoGroup(): void
    {
        self::assertSame([], ChangeGroup::of(['route.a' => []]));
    }

    private static function email(): WorkflowChange
    {
        return new WorkflowChange(ChangeNature::Modified, ChangeSubject::Decision, 'App\Entity\User::email', 'a', 'b', new FileRef('src/Entity/User.php'), 250, 'condition');
    }

    private static function security(string $route): WorkflowChange
    {
        return new WorkflowChange(ChangeNature::Modified, ChangeSubject::Attribute, $route.'#security', 'ROLE_USER', 'ROLE_ADMIN', new FileRef('src/Controller/UserController.php'));
    }
}
