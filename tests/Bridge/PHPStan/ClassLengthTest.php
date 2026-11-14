<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Bridge\PHPStan;

use Jul6Art\DevTools\Bridge\PHPStan\ClassLength;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassLength::class)]
final class ClassLengthTest extends TestCase
{
    public function testADeclarationLongerThanTheThresholdIsReportedWithItsCountInTheTipOnly(): void
    {
        $verdict = new ClassLength()->of('App\Service\ReportBuilder', '/app/src/Service/ReportBuilder.php', 10, 621);

        self::assertNotNull($verdict);
        self::assertSame('Class App\Service\ReportBuilder is longer than 500 lines.', $verdict['message']);
        self::assertStringStartsWith('612 lines from its docblock to its closing brace', $verdict['tip']);
    }

    /**
     * ⚠️ A project freezes its long classes in a baseline that compares messages: the same class, one line longer
     * or shorter, must read the same, or it leaves its list at every edit.
     */
    public function testTheMessageDoesNotChangeWithTheNumberOfLines(): void
    {
        $length = new ClassLength();

        self::assertSame(
            $length->of('App\Big', '/app/src/Big.php', 1, 600)['message'] ?? null,
            $length->of('App\Big', '/app/src/Big.php', 1, 900)['message'] ?? null,
        );
    }

    public function testADeclarationOfExactlyTheThresholdIsAccepted(): void
    {
        self::assertNull(new ClassLength()->of('App\Edge', '/app/src/Edge.php', 1, 500));
        self::assertNotNull(new ClassLength()->of('App\Edge', '/app/src/Edge.php', 1, 501));
    }

    public function testEntitiesAndFixturesAreExcludedByDefault(): void
    {
        $length = new ClassLength();

        self::assertNull($length->of('App\Entity\Order', '/app/src/Entity/Order.php', 1, 900));
        self::assertNull($length->of('App\DataFixtures\OrderFixtures', '/app/src/DataFixtures/OrderFixtures.php', 1, 900));
        self::assertNotNull($length->of('App\Service\Orders', '/app/src/Service/Orders.php', 1, 900));
    }

    public function testTheThresholdAndTheExclusionsAreTheProjects(): void
    {
        $length = new ClassLength(300, ['*/Legacy/*']);

        self::assertSame('Class App\Entity\Order is longer than 300 lines.', $length->of('App\Entity\Order', '/app/src/Entity/Order.php', 1, 301)['message'] ?? null);
        self::assertNull($length->of('App\Legacy\Kernel', '/app/src/Legacy/Kernel.php', 1, 900));
    }
}
