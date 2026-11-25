<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Bridge\Rector;

use Jul6Art\DevTools\Bridge\Rector\UseClockRector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * Each fixture holds the code before, then after the rule — or the code alone, when the rule leaves it.
 */
#[CoversClass(UseClockRector::class)]
final class UseClockRectorTest extends AbstractRectorTestCase
{
    /** @return iterable<array{string}> */
    public static function fixtures(): iterable
    {
        return self::yieldFilesFromDirectory(__DIR__.'/Fixture');
    }

    #[DataProvider('fixtures')]
    public function testTheReadingsAreReplaced(string $fixture): void
    {
        $this->doTestFile($fixture);
    }

    #[\Override]
    public function provideConfigFilePath(): string
    {
        return __DIR__.'/config/clock.php';
    }
}
