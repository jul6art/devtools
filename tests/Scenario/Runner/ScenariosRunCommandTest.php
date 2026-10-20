<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Scenario\Runner;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\ScenariosRunCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Scenario\Runner\PantherRunner;
use Jul6Art\DevTools\Tests\Support\Browser;
use Jul6Art\DevTools\Tests\Support\ServedSymfonyMinimal;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0021: `scenarios:run` — the URL mode on symfony-minimal served by `php -S`, its skeletons green; a step
 * that fails, kept with its screen; the options it refuses; and its absence without Panther.
 */
#[CoversClass(ScenariosRunCommand::class)]
#[CoversClass(Application::class)]
final class ScenariosRunCommandTest extends TestCase
{
    use UsesTemporaryDirectory;

    /**
     * The skeletons of ADR-0020, replayed against the application — the role of each logged in by HTTP basic —
     * then a scenario of a human that fails: the run says which step, keeps its screen and its DOM, exits 1, and
     * repeats no credential.
     */
    #[Group('browser')]
    public function testTheSkeletonsOfSymfonyMinimalPassAgainstTheServedApplication(): void
    {
        $missing = ServedSymfonyMinimal::missing();

        if (null !== $missing) {
            self::markTestSkipped($missing);
        }

        $project = ServedSymfonyMinimal::prepare($this->temporaryDirectory().'/symfony-minimal');
        [$server, $baseUrl] = Browser::serve($project.'/public', null, ['APP_ENV' => 'dev', 'APP_DEBUG' => '0']);
        ServedSymfonyMinimal::exportCredentials();

        try {
            $tester = new CommandTester(new ScenariosRunCommand(new FrozenClock(new \DateTimeImmutable('2026-10-20T12:00:00+00:00'))));
            $passed = $tester->execute(['--path' => $project, '--base-url' => $baseUrl]);
            $display = $tester->getDisplay();

            // Next to the skeleton of the same workflow.
            file_put_contents($project.'/.devtools/scenarios/route.order.new/refused.xml', '<scenario xmlns="https://github.com/jul6art/devtools/schema/scenario/1" schema-version="1" id="route.order.new/refused" workflow="route.order.new"><given><login role="ROLE_OPERATOR"/></given><steps><goto route="app_order_new"/><assert-text text="Order saved"/><screenshot name="never"/></steps></scenario>');
            $failing = new CommandTester(new ScenariosRunCommand(new FrozenClock(new \DateTimeImmutable('2026-10-20T12:05:00+00:00'))));
            $failed = $failing->execute(['--path' => $project, '--base-url' => $baseUrl, 'scenarios' => ['route.order.new']]);
        } finally {
            ServedSymfonyMinimal::exportCredentials(false);
            $server->stop();
        }

        self::assertSame(0, $passed, $display);
        self::assertStringContainsString('✓ route.health/smoke', $display);
        self::assertStringContainsString('✓ route.order.index/smoke', $display);
        self::assertStringContainsString('✓ route.order.new/smoke', $display);
        self::assertStringContainsString('3 passed, 0 failed, 0 errors — .devtools/runs/2026-10-20-120000/result.xml', $display);
        self::assertFileExists($project.'/.devtools/runs/2026-10-20-120000/route.order.new/smoke/order-new.png');
        self::assertStringContainsString('<form name="order"', (string) file_get_contents($project.'/.devtools/runs/2026-10-20-120000/route.order.new/smoke/order-new.html'), 'The page behind the login, not a 401.');

        $result = (string) file_get_contents($project.'/.devtools/runs/2026-10-20-120500/result.xml');
        self::assertSame(1, $failed);
        self::assertStringContainsString('✗ route.order.new/refused — step 2 (assert-text): "body" does not read "Order saved" after 5 s.', $failing->getDisplay());
        self::assertStringContainsString('<step index="2" action="assert-text" verdict="failed"', $result);
        self::assertStringContainsString('<url>'.$baseUrl.'/orders/new</url>', $result);
        self::assertStringContainsString('<screenshot path="route.order.new/refused/02-assert-text.png"/>', $result);
        self::assertStringContainsString('<dom path="route.order.new/refused/02-assert-text.html"/>', $result);
        self::assertStringContainsString('<step index="3" action="screenshot" verdict="skipped"', $result);
        self::assertFileExists($project.'/.devtools/runs/2026-10-20-120500/route.order.new/refused/02-assert-text.png');

        foreach ([$result, (string) file_get_contents($project.'/.devtools/runs/2026-10-20-120000/result.xml')] as $written) {
            self::assertStringNotContainsString(base64_encode('operator:operator'), $written);
            self::assertStringNotContainsString('operator@', $written);
        }
    }

    public function testWithoutABaseUrlTheCommandAsksWhereTheApplicationIs(): void
    {
        $tester = new CommandTester(new ScenariosRunCommand());

        self::assertSame(2, $tester->execute(['--path' => $this->temporaryDirectory()]));
        self::assertStringContainsString('Give --base-url', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $options
     */
    #[TestWith([['--base-url' => 'app.localhost'], 'is not a base URL'])]
    #[TestWith([['--base-url' => 'http://app.localhost', '--timeout' => '0'], '--timeout is a positive number'])]
    #[TestWith([['--base-url' => 'http://app.localhost', '--viewport' => 'large'], '--viewport is width x height'])]
    public function testAnOptionThatCannotWorkIsRefused(array $options, string $message): void
    {
        $tester = new CommandTester(new ScenariosRunCommand());

        self::assertSame(2, $tester->execute(['--path' => $this->temporaryDirectory(), ...$options]));
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertSame(2, $tester->execute(['--path' => $this->temporaryDirectory().'/nowhere']));
    }

    public function testWithoutScenarioThereIsNothingToRunAndAFilterThatMatchesNothingIsSaid(): void
    {
        $project = $this->temporaryDirectory();
        $tester = new CommandTester(new ScenariosRunCommand());

        self::assertSame(0, $tester->execute(['--path' => $project, '--base-url' => 'http://127.0.0.1:1']));
        self::assertStringContainsString('No scenario to run: scenarios:generate writes them.', $tester->getDisplay());

        mkdir($project.'/.devtools/scenarios/route.health', 0o777, true);
        file_put_contents($project.'/.devtools/scenarios/route.health/broken.xml', '<scenario');

        self::assertSame(2, $tester->execute(['--path' => $project, '--base-url' => 'http://127.0.0.1:1', 'scenarios' => ['route.order.new']]));
        self::assertStringContainsString('No scenario matches route.order.new.', $tester->getDisplay());
        self::assertStringContainsString('.devtools/scenarios/route.health/broken.xml', $tester->getDisplay());
    }

    public function testWithoutPantherTheCommandIsAbsentAndTheWayToInstallItIsGiven(): void
    {
        $application = new Application(panther: false);
        $application->setAutoExit(false);
        $list = new ApplicationTester($application);
        $list->run(['command' => 'list']);

        self::assertStringNotContainsString('scenarios:run', $list->getDisplay());
        self::assertStringContainsString('scenarios:generate', $list->getDisplay(), 'Generating needs no browser.');
        self::assertSame(PantherRunner::available(), new Application()->has('scenarios:run'));

        try {
            $application->find('scenarios:run');
            self::fail('scenarios:run exists only with Panther.');
        } catch (CommandNotFoundException $unknown) {
            self::assertStringContainsString('Replaying the scenarios needs Panther, which DevTools only suggests: composer require --dev symfony/panther:^2.4', $unknown->getMessage());
        }
    }
}
