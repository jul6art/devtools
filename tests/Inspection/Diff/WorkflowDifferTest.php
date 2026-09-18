<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Diff;

use Jul6Art\DevTools\Inspection\Diff\ChangeNature;
use Jul6Art\DevTools\Inspection\Diff\ChangeSubject;
use Jul6Art\DevTools\Inspection\Diff\WorkflowChange;
use Jul6Art\DevTools\Inspection\Diff\WorkflowDiffer;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Tracking\Generation;
use Jul6Art\DevTools\Tracking\GenerationMode;
use Jul6Art\DevTools\Tracking\Revision;
use Jul6Art\DevTools\Tracking\TrackedFile;
use Jul6Art\DevTools\Tracking\TrackingDocument;
use Jul6Art\DevTools\Tracking\TrackingStatus;
use Jul6Art\DevTools\Tracking\VcsState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0046: what changed in a workflow, not merely that it changed.
 */
#[CoversClass(WorkflowDiffer::class)]
#[CoversClass(WorkflowChange::class)]
#[CoversClass(ChangeNature::class)]
#[CoversClass(ChangeSubject::class)]
final class WorkflowDifferTest extends TestCase
{
    private const string HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testAWorkflowThatDidNotChangeHasNoChange(): void
    {
        self::assertSame([], self::diff(self::tracking(), self::workflow()));
    }

    /**
     * The point of the whole ADR: the hash is what triggers the comparison, never what it reports.
     */
    public function testAFileWhoseContentChangedWithoutChangingAFactIsNotAChange(): void
    {
        $before = self::tracking(files: [new TrackedFile(new FileRef('src/Controller/OrderController.php', FileRole::Controller), self::HASH_A)]);
        $after = self::workflow(files: [new FileRef('src/Controller/OrderController.php', FileRole::Controller)]);

        self::assertSame([], self::diff($before, $after), 'Only the bytes moved: the facts are the same.');
    }

    public function testAnEntryPointAddedAndOneRemoved(): void
    {
        $before = self::tracking(satellites: [self::route('app_order_new_localized')]);
        $after = self::workflow(satellites: [self::route('app_order_new_modal')]);

        self::assertSame([
            'added entrypoint route:app_order_new_modal',
            'removed entrypoint route:app_order_new_localized',
        ], self::diff($before, $after));
    }

    public function testAnAttributeOfAnEntryPointAddedRemovedOrModified(): void
    {
        $before = self::tracking(main: self::route('app_order_new', ['path' => '/orders/new', 'security' => 'ROLE_USER']));
        $after = self::workflow(main: self::route('app_order_new', ['path' => '/orders/new', 'methods' => 'GET|POST', 'security' => 'ROLE_ADMIN']));

        self::assertSame([
            'added attribute app_order_new#methods: — → GET|POST',
            'modified attribute app_order_new#security: ROLE_USER → ROLE_ADMIN',
        ], self::diff($before, $after));

        self::assertSame(
            ['removed attribute app_order_new#security: ROLE_USER → —'],
            self::diff($before, self::workflow(main: self::route('app_order_new', ['path' => '/orders/new']))),
        );
    }

    public function testADecisionAddedAndOneRemoved(): void
    {
        $before = self::tracking(decisions: [self::decision('App\Entity\Order::status', "'draft'", null)]);
        $after = self::workflow(decisions: [self::decision('App\Entity\Order::total', '0', null)]);

        self::assertSame([
            'added decision App\\Entity\\Order::total: — → 0',
            "removed decision App\\Entity\\Order::status: 'draft' → —",
        ], self::diff($before, $after));
    }

    /**
     * A condition rewritten is one change, not a removal plus an addition: the reader wants the two
     * versions side by side.
     */
    public function testADecisionWhoseConditionChangedIsOneModification(): void
    {
        $before = self::tracking(decisions: [self::decision('App\Entity\User::email', 'strtolower($email)', 'null !== $email')]);
        $after = self::workflow(decisions: [self::decision('App\Entity\User::email', 'strtolower($email)', '\'\' !== trim($email)')]);

        self::assertSame(
            ["modified decision App\\Entity\\User::email: null !== \$email → '' !== trim(\$email)"],
            self::diff($before, $after),
        );
    }

    public function testADecisionWhoseValueChangedIsOneModification(): void
    {
        $before = self::tracking(decisions: [self::decision('App\Entity\Order::status', "'draft'", 'true === $new')]);
        $after = self::workflow(decisions: [self::decision('App\Entity\Order::status', "'pending'", 'true === $new')]);

        self::assertSame(
            ["modified decision App\\Entity\\Order::status: 'draft' → 'pending'"],
            self::diff($before, $after),
        );
    }

    /**
     * ⚠️ The rule that keeps the tool usable: moving code produces no change. Without it, the first
     * refactoring would report one change per decision of the file.
     */
    public function testADecisionThatOnlyMovedIsNotAChange(): void
    {
        $before = self::tracking(decisions: [self::decision('App\Entity\Order::status', "'draft'", null, line: 42)]);
        $after = self::workflow(decisions: [self::decision('App\Entity\Order::status', "'draft'", null, line: 1203)]);

        self::assertSame([], self::diff($before, $after));
    }

    public function testAMechanismAddedAndAPriorityChanged(): void
    {
        $before = self::tracking(mechanisms: [self::mechanism('App\EventListener\LocaleListener', 'kernel.request', 7)]);
        $after = self::workflow(mechanisms: [
            self::mechanism('App\EventListener\LocaleListener', 'kernel.request', 12),
            self::mechanism('App\EventListener\AuditListener', 'kernel.response', 0),
        ]);

        self::assertSame([
            'added mechanism listener:App\EventListener\AuditListener@kernel.response',
            'modified mechanism listener:App\EventListener\LocaleListener@kernel.request: 7 → 12',
        ], self::diff($before, $after));
    }

    /**
     * What a listener writes is a fact of the workflows it runs in: the target names the mechanism so
     * that the reader knows the decision is not taken by the controller.
     */
    public function testADecisionOfAMechanismIsComparedUnderTheMechanismName(): void
    {
        $before = self::tracking(mechanisms: [self::mechanism('App\EventListener\LocaleListener', 'kernel.request', 7, [
            self::decision('Symfony\Component\HttpFoundation\Request::locale', '$user->getLocale()', 'null !== $user'),
        ])]);
        $after = self::workflow(mechanisms: [self::mechanism('App\EventListener\LocaleListener', 'kernel.request', 7, [
            self::decision('Symfony\Component\HttpFoundation\Request::locale', '$user->getLocale()', 'null !== $user && $user->getLocale()'),
        ])]);

        self::assertSame(
            ['modified decision LocaleListener → Symfony\Component\HttpFoundation\Request::locale: null !== $user → null !== $user && $user->getLocale()'],
            self::diff($before, $after),
        );
    }

    public function testDependenciesTestsAndPackages(): void
    {
        $before = self::tracking(
            tests: [new FileRef('tests/Controller/OrderControllerTest.php', FileRole::Test)],
            packages: [new PackageRef('symfony/form', '7.4.3')],
            dependsOn: [new WorkflowId('route.order.show')],
        );
        $after = self::workflow(
            tests: [new FileRef('tests/Controller/OrderCreationTest.php', FileRole::Test)],
            packages: [new PackageRef('symfony/form', '8.0.0')],
            dependsOn: [new WorkflowId('route.order.index')],
        );

        self::assertSame([
            'added dependency route.order.index',
            'removed dependency route.order.show',
            'added test tests/Controller/OrderCreationTest.php',
            'removed test tests/Controller/OrderControllerTest.php',
            'modified package symfony/form: 7.4.3 → 8.0.0',
        ], self::diff($before, $after));
    }

    /**
     * A target that keeps some of its branches and loses one: the branch that went away is reported,
     * the others are not.
     */
    public function testABranchRemovedFromATargetThatRemainsIsReported(): void
    {
        $before = self::tracking(decisions: [
            self::decision('App\Entity\Order::status', "'draft'", 'true === $new'),
            self::decision('App\Entity\Order::status', "'paid'", 'true === $paid'),
        ]);
        $after = self::workflow(decisions: [self::decision('App\Entity\Order::status', "'draft'", 'true === $new')]);

        self::assertSame(
            ["removed decision App\Entity\Order::status: 'paid' → —"],
            self::diff($before, $after),
        );
    }

    public function testAMechanismAndAPackageThatWentAway(): void
    {
        $before = self::tracking(
            packages: [new PackageRef('symfony/form', '7.4.3'), new PackageRef('symfony/mailer', '7.4.0')],
            mechanisms: [self::mechanism('App\EventListener\LocaleListener', 'kernel.request', 7)],
        );
        $after = self::workflow(packages: [new PackageRef('symfony/form', '7.4.3')]);

        self::assertSame([
            'removed mechanism listener:App\EventListener\LocaleListener@kernel.request',
            'removed package symfony/mailer: 7.4.0 → —',
        ], self::diff($before, $after), 'A package that did not move is not reported.');
    }

    /**
     * Two changes are the same fact when they say the same thing, whichever workflow carries them: that
     * is what lets 233 workflows sharing one entity be reviewed once.
     */
    public function testTwoWorkflowsCarryingTheSameFactProduceTheSameKey(): void
    {
        $first = new WorkflowChange(ChangeNature::Modified, ChangeSubject::Decision, 'App\Entity\User::email', 'a', 'b', new FileRef('src/Entity/User.php'), 250);
        $second = new WorkflowChange(ChangeNature::Modified, ChangeSubject::Decision, 'App\Entity\User::email', 'a', 'b', new FileRef('src/Entity/User.php'), 1024);
        $third = new WorkflowChange(ChangeNature::Modified, ChangeSubject::Decision, 'App\Entity\User::email', 'a', 'c', new FileRef('src/Entity/User.php'), 250);

        self::assertSame($first->key(), $second->key(), 'The line is not part of the identity of a fact.');
        self::assertNotSame($first->key(), $third->key());
    }

    /**
     * @return list<string>
     */
    private static function diff(TrackingDocument $before, Workflow $after): array
    {
        return array_map(
            static fn (WorkflowChange $change): string => trim(\sprintf(
                '%s %s %s%s',
                $change->nature->value,
                $change->subject->value,
                $change->target,
                null === $change->before && null === $change->after ? '' : \sprintf(': %s → %s', $change->before ?? '—', $change->after ?? '—'),
            )),
            new WorkflowDiffer()->between($before, $after),
        );
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function route(string $name = 'app_order_new', array $attributes = ['path' => '/orders/new']): EntryPoint
    {
        return new EntryPoint('route', $name, new FileRef('src/Controller/OrderController.php', FileRole::Controller), $attributes);
    }

    private static function decision(string $target, string $value, ?string $condition, int $line = 42): DecisionPoint
    {
        return new DecisionPoint($target, $value, $condition, new FileRef('src/Entity/Order.php', FileRole::Entity), $line, Confidence::High);
    }

    /**
     * @param list<DecisionPoint> $decisions
     */
    private static function mechanism(string $name, string $event, ?int $priority, array $decisions = []): Mechanism
    {
        return new Mechanism('listener', $name, $event, new FileRef('src/EventListener/Listener.php', FileRole::Listener), $priority, $decisions);
    }

    /**
     * @param list<EntryPoint>    $satellites
     * @param list<TrackedFile>   $files
     * @param list<FileRef>       $tests
     * @param list<PackageRef>    $packages
     * @param list<WorkflowId>    $dependsOn
     * @param list<DecisionPoint> $decisions
     * @param list<Mechanism>     $mechanisms
     */
    private static function tracking(
        ?EntryPoint $main = null,
        array $satellites = [],
        array $files = [],
        array $tests = [],
        array $packages = [],
        array $dependsOn = [],
        array $decisions = [],
        array $mechanisms = [],
    ): TrackingDocument {
        return new TrackingDocument(
            id: new WorkflowId('route.order.new'),
            type: WorkflowType::routes(),
            title: 'Order creation',
            generated: new Generation(new \DateTimeImmutable('2026-09-16T14:22:31+02:00'), 'devtools 0.1.0', GenerationMode::Ai, 'claude-opus-5', 'page/2'),
            vcs: new VcsState('a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', 'main', false),
            main: $main ?? self::route(),
            satellites: $satellites,
            files: [] === $files ? [new TrackedFile(new FileRef('src/Controller/OrderController.php', FileRole::Controller), self::HASH_A)] : $files,
            tests: $tests,
            packages: $packages,
            dependsOn: $dependsOn,
            confidence: Confidence::High,
            producer: new WorkflowSource('native:symfony'),
            status: TrackingStatus::Fresh,
            history: [new Revision(new \DateTimeImmutable('2026-09-16T14:22:31+02:00'), 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', 'initial')],
            decisions: $decisions,
            mechanisms: $mechanisms,
        );
    }

    /**
     * @param list<EntryPoint>    $satellites
     * @param list<FileRef>       $files
     * @param list<FileRef>       $tests
     * @param list<PackageRef>    $packages
     * @param list<WorkflowId>    $dependsOn
     * @param list<DecisionPoint> $decisions
     * @param list<Mechanism>     $mechanisms
     */
    private static function workflow(
        ?EntryPoint $main = null,
        array $satellites = [],
        array $files = [],
        array $tests = [],
        array $packages = [],
        array $dependsOn = [],
        array $decisions = [],
        array $mechanisms = [],
    ): Workflow {
        return new Workflow(
            id: new WorkflowId('route.order.new'),
            type: WorkflowType::routes(),
            title: 'Order creation',
            main: $main ?? self::route(),
            satellites: $satellites,
            files: [] === $files ? [new FileRef('src/Controller/OrderController.php', FileRole::Controller)] : $files,
            packages: $packages,
            dependsOn: $dependsOn,
            tests: $tests,
            decisions: $decisions,
            mechanisms: $mechanisms,
            confidence: Confidence::High,
            source: new WorkflowSource('native:symfony'),
        );
    }
}
