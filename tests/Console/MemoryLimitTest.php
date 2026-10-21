<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Console;

use Jul6Art\DevTools\Console\MemoryLimit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoryLimit::class)]
final class MemoryLimitTest extends TestCase
{
    private string $limit;

    protected function setUp(): void
    {
        $this->limit = \ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->limit);
    }

    #[DataProvider('limits')]
    public function testAShorthandLimitIsReadInBytes(string $limit, int $bytes): void
    {
        self::assertSame($bytes, MemoryLimit::bytes($limit));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function limits(): iterable
    {
        yield 'megabytes' => ['512M', 536870912];
        yield 'lowercase' => ['128m', 134217728];
        yield 'gigabytes' => ['2G', 2147483648];
        yield 'kilobytes' => ['1024K', 1048576];
        yield 'bytes' => ['1048576', 1048576];
        yield 'spaced' => [' 256M ', 268435456];
        yield 'unlimited' => ['-1', -1];
        yield 'nonsense reads as unlimited' => ['plenty', -1];
    }

    public function testALowerLimitIsRaised(): void
    {
        // Under 1G, yet above what the process already holds: run late — Infection shuffles the suite — it
        // holds more than 128M, and PHP refuses a limit below its usage.
        $lower = max(128 * 1024 * 1024, memory_get_usage(true) + 64 * 1024 * 1024);
        self::assertLessThan(MemoryLimit::bytes(MemoryLimit::MINIMUM), $lower);
        ini_set('memory_limit', (string) $lower);

        self::assertSame('1G', MemoryLimit::raiseTo());
        self::assertSame('1G', \ini_get('memory_limit'));
    }

    public function testAHigherLimitIsLeftAlone(): void
    {
        ini_set('memory_limit', '1G');

        self::assertNull(MemoryLimit::raiseTo('512M'), 'Nothing to raise.');
        self::assertSame('1G', \ini_get('memory_limit'));
    }

    public function testAnUnlimitedLimitIsNeverLowered(): void
    {
        ini_set('memory_limit', '-1');

        self::assertNull(MemoryLimit::raiseTo('512M'));
        self::assertSame('-1', \ini_get('memory_limit'));
    }
}
