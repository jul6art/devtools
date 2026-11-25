<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Bridge\Rector;

use Jul6Art\DevTools\Bridge\Rector\UseClockRector;
use PHPUnit\Framework\Attributes\CoversClass;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * A project with a clock of its own names it in the configuration.
 */
#[CoversClass(UseClockRector::class)]
final class UseClockRectorConfiguredTest extends AbstractRectorTestCase
{
    public function testTheConfiguredClockIsTheOneCalled(): void
    {
        $this->doTestFile(__DIR__.'/FixtureConfigured/own_clock.php.inc');
    }

    public function testAClockThatIsNotAClassNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new UseClockRector()->configure(['clock' => '']);
    }

    #[\Override]
    public function provideConfigFilePath(): string
    {
        return __DIR__.'/config/configured.php';
    }
}
