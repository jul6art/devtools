<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Rendering;

use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\StateMachine;
use Jul6Art\DevTools\Inspection\Model\Transition;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowGroup;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Rendering\GroupPageRenderer;
use Jul6Art\DevTools\Rendering\GroupPageSection;
use Jul6Art\DevTools\Rendering\PageParser;
use Jul6Art\DevTools\Rendering\ParsedPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GroupPageRenderer::class)]
#[CoversClass(GroupPageSection::class)]
final class GroupPageRendererTest extends TestCase
{
    public function testThePageHoldsItsThreeSectionsInOrderAndNothingElse(): void
    {
        $page = new GroupPageRenderer()->render(self::group(), self::workflows());

        self::assertSame(['Résumé', 'Routes', 'États'], array_keys(new PageParser()->parse($page)->sections));
        self::assertStringStartsWith("# /admin/users\n", $page);
        self::assertStringContainsString('`admin.user` · type : routes · 2 routes · `src/Controller/Admin/UserController.php`', $page);
    }

    public function testEveryRouteIsOneLineOfTheTableAndLinksToItsOwnPage(): void
    {
        $page = new GroupPageRenderer()->render(self::group(), self::workflows());

        self::assertStringContainsString('| [`admin_user_index`](index.md) | `/admin/users` | `GET` | `ROLE_ADMIN` |', $page);
        self::assertStringContainsString('| [`admin_user_edit`](edit.md) | `/admin/users/{id}/edit` | `GET\\|POST` | — |', $page);
    }

    /**
     * Without Claude the summary holds "—": the facts are the table and the state machine, and a group page
     * never invents the sentence that would explain them.
     */
    public function testWithoutASummaryTheSectionHoldsADash(): void
    {
        self::assertStringContainsString("## Résumé\n\n—\n", new GroupPageRenderer()->render(self::group(), self::workflows()));
    }

    public function testASummaryWrittenByClaudeSurvivesAFactualRewrite(): void
    {
        $written = new PageParser()->parse(new GroupPageRenderer()->render(self::group(), self::workflows()));
        $written = new ParsedPage($written->title, $written->header, [...$written->sections, 'Résumé' => 'Le CRUD des comptes.']);

        $page = new GroupPageRenderer()->render(self::group(), self::workflows(), $written);

        self::assertStringContainsString("## Résumé\n\nLe CRUD des comptes.\n", $page);
        self::assertStringContainsString('| [`admin_user_index`](index.md) |', $page, 'The facts are rewritten from the model.');
    }

    public function testTheStateMachineOfTheResourceIsRenderedOnTheGroupPage(): void
    {
        $machine = new StateMachine('status', ['draft', 'sent'], [new Transition('send', ['draft'], ['sent'])]);
        $page = new GroupPageRenderer()->render(self::group($machine), self::workflows());

        self::assertStringContainsString('stateDiagram-v2', $page);
        self::assertStringContainsString(': send', $page, 'The transition is named on the edge.');
    }

    public function testWithoutAStateMachineTheSectionHoldsADash(): void
    {
        self::assertStringContainsString("## États\n\n—\n", new GroupPageRenderer()->render(self::group(), self::workflows()));
    }

    public function testTwoRenderingsOfAnUnchangedGroupProduceTheSameBytes(): void
    {
        self::assertSame(
            new GroupPageRenderer()->render(self::group(), self::workflows()),
            new GroupPageRenderer()->render(self::group(), self::workflows()),
        );
    }

    private static function group(?StateMachine $states = null): WorkflowGroup
    {
        return new WorkflowGroup('admin.user', '/admin/users', new FileRef('src/Controller/Admin/UserController.php', FileRole::Controller), $states);
    }

    /**
     * @return non-empty-list<Workflow>
     */
    private static function workflows(): array
    {
        return [
            self::route('route.admin.user.index', 'admin_user_index', ['path' => '/admin/users', 'methods' => 'GET', 'security' => 'ROLE_ADMIN']),
            self::route('route.admin.user.edit', 'admin_user_edit', ['path' => '/admin/users/{id}/edit', 'methods' => 'GET|POST']),
        ];
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function route(string $id, string $name, array $attributes): Workflow
    {
        $file = new FileRef('src/Controller/Admin/UserController.php', FileRole::Controller);

        return new Workflow(
            id: new WorkflowId($id),
            type: WorkflowType::routes(),
            title: $attributes['path'],
            main: new EntryPoint('route', $name, $file, $attributes),
            files: [$file],
            group: new WorkflowGroup('admin.user', '/admin/users', $file),
        );
    }
}
