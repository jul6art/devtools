<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Bridge\PHPStan;

use Jul6Art\DevTools\Bridge\PHPStan\TimeReading;
use Jul6Art\DevTools\Bridge\PHPStan\TimeReadingRule;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Process\Process;

/**
 * @extends RuleTestCase<TimeReadingRule>
 */
#[CoversClass(TimeReadingRule::class)]
#[CoversClass(TimeReading::class)]
final class TimeReadingRuleTest extends RuleTestCase
{
    use UsesTemporaryDirectory;

    private const string CLOCK_TIP = 'Jul6Art\CoreBundle\Util\Clock::now() takes the same arguments and gives the same date, from the clock a test can freeze.';

    private const string FORMAT_TIP = 'Start the format with "!" or end it with "|": the missing fields are then zero, whatever the clock.';

    private const string FUNCTION_TIP = 'Read it through Jul6Art\CoreBundle\Util\Clock: now() and timestamp() give the same, from the clock a test can freeze.';

    private const string SLEEP_TIP = 'Jul6Art\CoreBundle\Util\Clock::sleep() waits the same, and a frozen clock moves instead.';

    private const string SYMFONY_TIP = 'Read the time through Jul6Art\CoreBundle\Util\Clock; a service injects Psr\Clock\ClockInterface.';

    public function testEveryReadingIsReportedAtItsLine(): void
    {
        $file = $this->write('src/Service/Readings.php', <<<'PHP'
            <?php

            namespace App\Service;

            use DateTimeImmutable;
            use Symfony\Component\Clock\Clock;
            use Symfony\Component\Clock\DatePoint;

            use function Symfony\Component\Clock\now;

            final class Readings
            {
                private const string RETENTION = '-30 days';

                public function read(string $interval, int $days): void
                {
                    new \DateTime();
                    new DateTimeImmutable('now');
                    new \DateTimeImmutable('today', new \DateTimeZone('Europe/Brussels'));
                    new \DateTimeImmutable(self::RETENTION);
                    new \DateTimeImmutable('-'.$interval);
                    new \DateTimeImmutable(sprintf('-%d days', $days));
                    new \DateTimeImmutable("+{$days} days");
                    time();
                    date('Y');
                    gmdate('Y-m-d');
                    mktime(0, 0, 0);
                    strtotime('+1 day');
                    date_create();
                    sleep(1);
                    usleep(10);
                    now();
                    Clock::get();
                    new DatePoint();
                    \DateTimeImmutable::createFromFormat('Y-m-d', '2026-11-24');
                }
            }
            PHP);

        $this->analyse([$file], [
            ['new \DateTime() reads the system clock.', 17, self::CLOCK_TIP],
            ['new \DateTimeImmutable() reads the system clock.', 18, self::CLOCK_TIP],
            ['new \DateTimeImmutable() reads the system clock.', 19, self::CLOCK_TIP],
            ['new \DateTimeImmutable() reads the system clock.', 20, self::CLOCK_TIP],
            ['new \DateTimeImmutable() reads the system clock.', 21, self::CLOCK_TIP],
            ['new \DateTimeImmutable() reads the system clock.', 22, self::CLOCK_TIP],
            ['new \DateTimeImmutable() reads the system clock.', 23, self::CLOCK_TIP],
            ['time() reads the system clock.', 24, self::FUNCTION_TIP],
            ['date() reads the system clock.', 25, self::FUNCTION_TIP],
            ['gmdate() reads the system clock.', 26, self::FUNCTION_TIP],
            ['mktime() reads the system clock.', 27, self::FUNCTION_TIP],
            ['strtotime() reads the system clock.', 28, self::FUNCTION_TIP],
            ['date_create() reads the system clock.', 29, self::FUNCTION_TIP],
            ['sleep() waits on the system clock.', 30, self::SLEEP_TIP],
            ['usleep() waits on the system clock.', 31, self::SLEEP_TIP],
            ['Symfony\Component\Clock\now() is Symfony Clock, which only the project\'s clock wraps.', 32, self::SYMFONY_TIP],
            ['Symfony\Component\Clock\Clock::get() is Symfony Clock, which only the project\'s clock wraps.', 33, self::SYMFONY_TIP],
            ['new Symfony\Component\Clock\DatePoint is Symfony Clock, which only the project\'s clock wraps.', 34, self::SYMFONY_TIP],
            ['createFromFormat() fills the fields its format leaves out with the current time.', 35, self::FORMAT_TIP],
        ]);
    }

    /**
     * A value converted, a full date, a timestamp, a complete format and a duration read no clock.
     */
    public function testWhatReadsNoClockIsNotReported(): void
    {
        $file = $this->write('src/Service/Conversions.php', <<<'PHP'
            <?php

            namespace App\Service;

            final class Conversions
            {
                private const string FIRST_DAY = '2026-01-01';

                public function convert(string $value, int $timestamp, int $year): void
                {
                    new \DateTimeImmutable('2026-01-01');
                    new \DateTimeImmutable('@1700000000');
                    new \DateTimeImmutable(self::FIRST_DAY);
                    new \DateTimeImmutable($value);
                    new \DateTimeImmutable('@'.$timestamp);
                    new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year));
                    date('Y', $timestamp);
                    mktime(0, 0, 0, 1, 1, $year);
                    strtotime($value);
                    strtotime('+1 day', $timestamp);
                    \DateTimeImmutable::createFromFormat('!Y-m-d', '2026-11-24');
                    \DateTimeImmutable::createFromFormat('Y-m-d|', '2026-11-24');
                    \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', '2026-11-24 10:00:00');
                    \DateTimeImmutable::createFromFormat('U', '1700000000');
                    \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value);
                    microtime(true);
                    hrtime(true);
                    $read = time(...);
                }
            }
            PHP);

        $this->analyse([$file], []);
    }

    /**
     * A test builds its dates as it likes; it does not wait.
     */
    public function testATestIsReportedForItsWaitsOnly(): void
    {
        $file = $this->write('tests/Service/WaitingTest.php', <<<'PHP'
            <?php

            namespace App\Tests\Service;

            final class WaitingTest
            {
                public function testSomething(): void
                {
                    new \DateTimeImmutable('-1 day');
                    time();
                    sleep(2);
                }
            }
            PHP);

        $this->analyse([$file], [
            ['sleep() waits on the system clock.', 11, 'A test does not wait: freeze the clock and move it with ClockTestTrait::advanceClock().'],
        ]);
    }

    public function testTheClockAndTheTestTraitReadTheTimeThemselves(): void
    {
        $file = $this->write('src/Util/Clock.php', <<<'PHP'
            <?php

            namespace Jul6Art\CoreBundle\Util;

            final class Clock
            {
                public static function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable();
                }
            }
            PHP);

        $this->analyse([$file], []);
    }

    /**
     * The rule as a project takes it: the neon included alone, its settings applied, and a real PHPStan that reports
     * the reading under the rule's identifier.
     *
     * ⚠️ Its own `tmpDir`: two PHPStan runs sharing the system's result cache can corrupt it.
     */
    public function testTheNeonIsIncludedAloneAndItsSettingsApply(): void
    {
        $this->write('src/Service/Reader.php', "<?php\n\nnamespace App\\Service;\n\nfinal class Reader\n{\n    public function read(): int\n    {\n        return time();\n    }\n}\n");
        $this->write('src/Legacy/Clock.php', "<?php\n\nnamespace App\\Legacy;\n\nfinal class Clock\n{\n    public function read(): int\n    {\n        return time();\n    }\n}\n");
        $config = $this->write('phpstan.neon', "includes:\n    - ".\dirname(__DIR__, 3)."/resources/phpstan/clock.neon\n\nparameters:\n    level: 0\n    tmpDir: ".$this->temporaryDirectory()."/phpstan\n    paths:\n        - src\n    devtoolsClock:\n        clock: App\\Legacy\\Clock\n        allowed!:\n            - App\\Legacy\\Clock\n");

        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 3).'/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '-c', $config, '--autoload-file', \dirname(__DIR__, 3).'/vendor/autoload.php'], $this->temporaryDirectory());
        $process->run();

        $report = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($report, $process->getErrorOutput());
        $totals = $report['totals'] ?? null;
        self::assertIsArray($totals);
        self::assertSame(1, $totals['file_errors'] ?? null, $process->getOutput());

        $files = $report['files'] ?? null;
        self::assertIsArray($files);
        $reader = $files[$this->temporaryDirectory().'/src/Service/Reader.php'] ?? null;
        self::assertIsArray($reader, 'The reader is reported; the clock it is told about is allowed.');
        $messages = $reader['messages'] ?? null;
        self::assertIsArray($messages);
        $first = $messages[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('time() reads the system clock.', $first['message'] ?? null);
        self::assertSame('devtools.clock', $first['identifier'] ?? null);
        self::assertSame('Read it through App\Legacy\Clock: now() and timestamp() give the same, from the clock a test can freeze.', $first['tip'] ?? null);
    }

    #[\Override]
    protected function getRule(): Rule
    {
        return TimeReadingRule::create();
    }

    private function write(string $path, string $contents): string
    {
        $file = $this->temporaryDirectory().'/'.$path;

        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o777, true);
        }

        file_put_contents($file, $contents);

        return $file;
    }
}
