<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Bridge\PHPStan;

use Jul6Art\DevTools\Bridge\PHPStan\TimeReading;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimeReading::class)]
final class TimeReadingTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function dateStrings(): iterable
    {
        yield 'now' => ["'now'", true];
        yield 'an empty string' => ["''", true];
        yield 'today' => ["'today'", true];
        yield 'a time alone' => ["'10:00'", true];
        yield 'a relative delay' => ["'-14 days'", true];
        yield 'a full date' => ["'2026-01-01'", false];
        yield 'a full date and time' => ["'2026-01-01 10:00:00'", false];
        yield 'a European date' => ["'24/11/2026'", false];
        yield 'a timestamp' => ["'@1700000000'", false];
        yield 'a delay built from a word' => ["'-'.\$count.' days'", true];
        yield 'a delay built by sprintf' => ["sprintf('-%d days', \$count)", true];
        yield 'a delay interpolated' => ['"+{$count} days"', true];
        yield 'a delay named so' => ["'-'.\$this->retention", true];
        yield 'a delay from a getter' => ["'-'.\$purgeable->getInterval()", true];
        yield 'a timestamp built' => ["'@'.\$timestamp", false];
        yield 'a date built from its year' => ["sprintf('%d-01-01', \$year)", false];
        yield 'a full date moved by a delay' => ["'2026-01-01 +'.\$days.' days'", false];
        yield 'a value converted' => ["\$row['date']", false];
    }

    #[DataProvider('dateStrings')]
    public function testADateStringIsRelativeWhenItReadsTheClock(string $code, bool $relative): void
    {
        self::assertSame($relative, TimeReading::isRelative(self::expression($code)));
    }

    public function testNoStringIsAReading(): void
    {
        self::assertTrue(TimeReading::isRelative(null));
    }

    /**
     * PHPStan's constant strings win over the syntax: a constant that holds a relative date is a reading.
     */
    public function testTheConstantsPHPStanKnowsDecide(): void
    {
        self::assertTrue(TimeReading::isRelative(self::expression('self::RETENTION'), ['-30 days']));
        self::assertTrue(TimeReading::isRelative(self::expression('$when'), ['2026-01-01', 'tomorrow']));
        self::assertFalse(TimeReading::isRelative(self::expression('$when'), ['2026-01-01', '@0']));
    }

    public function testTheClockAndTheTestTraitAreAllowedByDefault(): void
    {
        $reading = new TimeReading();

        self::assertTrue($reading->allows('Jul6Art\CoreBundle\Util\Clock', null));
        self::assertTrue($reading->allows('App\Tests\SomeTest', 'Jul6Art\CoreBundle\Test\ClockTestTrait'));
        self::assertFalse($reading->allows('App\Service\Reader', null));
        self::assertFalse($reading->allows(null, null));
    }

    public function testAnotherClockIsNamedInTheTipsAndAllowed(): void
    {
        $reading = new TimeReading('App\Clock', ['App\Clock'], []);

        self::assertTrue($reading->allows('App\Clock', null));
        self::assertFalse($reading->allows('Jul6Art\CoreBundle\Util\Clock', null));
        self::assertSame(
            ['message' => 'time() reads the system clock.', 'tip' => 'Read it through App\Clock: now() and timestamp() give the same, from the clock a test can freeze.'],
            $reading->ofFunction('time', [], [], '/app/src/Reader.php'),
        );
    }

    public function testATestFileIsToldByItsPath(): void
    {
        $reading = new TimeReading(tests: ['*/spec/*']);

        self::assertNull($reading->ofFunction('time', [], [], '/app/spec/ReaderSpec.php'));
        self::assertNotNull($reading->ofFunction('time', [], [], '/app/tests/ReaderTest.php'), 'Its own patterns replace the defaults.');
        self::assertNotNull($reading->ofFunction('sleep', [], [], '/app/spec/ReaderSpec.php'), 'A test still does not wait.');
    }

    public function testAClassThatIsNotADateIsNotAReading(): void
    {
        self::assertNull(new TimeReading()->ofConstruction('App\Model\Order', [], [], '/app/src/Order.php'));
        self::assertNull(new TimeReading()->ofStaticCall('App\Model\Order', 'createFromFormat', [], '/app/src/Order.php'));
    }

    private static function expression(string $code): Expr
    {
        $statement = new ParserFactory()->createForHostVersion()->parse('<?php '.$code.';')[0] ?? null;
        self::assertInstanceOf(Expression::class, $statement);

        return $statement->expr;
    }
}
