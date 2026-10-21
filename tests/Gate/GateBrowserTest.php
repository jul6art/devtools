<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Gate;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Gate\AssertionsStep;
use Jul6Art\DevTools\Gate\FreshnessStep;
use Jul6Art\DevTools\Gate\Gate;
use Jul6Art\DevTools\Gate\GateStatus;
use Jul6Art\DevTools\Gate\ScenariosStep;
use Jul6Art\DevTools\Gate\VerificationStep;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\Browser;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tests\Support\ServedSymfonyMinimal;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0023, against symfony-minimal served: the gate replays the scenarios of the route a change reaches — those
 * only — and a broken one makes it red, naming the scenario, the step and the capture.
 */
#[Group('browser')]
#[CoversClass(ScenariosStep::class)]
#[CoversClass(Gate::class)]
final class GateBrowserTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testABrokenScenarioMakesTheGateRedAndSaysWhereToLook(): void
    {
        $missing = ServedSymfonyMinimal::missing();

        if (null !== $missing) {
            self::markTestSkipped($missing);
        }

        $project = ServedSymfonyMinimal::prepare($this->temporaryDirectory().'/symfony-minimal');
        GitRepository::initialise($project)->commitAll('documented');
        file_put_contents($project.'/templates/order/new.html.twig', "\n{# reworked #}\n", \FILE_APPEND);
        file_put_contents($project.'/.devtools/scenarios/route.order.new/refused.xml', '<scenario xmlns="https://github.com/jul6art/devtools/schema/scenario/1" schema-version="1" id="route.order.new/refused" workflow="route.order.new"><given><login role="ROLE_OPERATOR"/></given><steps><goto route="app_order_new"/><assert-text text="Order saved"/></steps></scenario>');
        [$server, $baseUrl] = Browser::serve($project.'/public', null, ['APP_ENV' => 'dev', 'APP_DEBUG' => '0']);
        ServedSymfonyMinimal::exportCredentials();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-20T17:00:00+00:00'));

        try {
            $result = new Gate([
                new FreshnessStep(new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), $clock)),
                new ScenariosStep(),
                new VerificationStep(),
                new AssertionsStep(),
            ], $clock)->run(DevToolsDirectory::documented(new ProjectRoot($project)), ['templates/order/new.html.twig', '.devtools/scenarios/route.order.new/refused.xml'], baseUrl: $baseUrl);
        } finally {
            ServedSymfonyMinimal::exportCredentials(false);
            $server->stop();
        }

        $scenarios = $result->steps[1];

        self::assertSame(GateStatus::Failed, $scenarios->status());
        self::assertSame(['route.order.new/refused — step 2 (assert-text): "body" does not read "Order saved" after 5 s. — capture .devtools/runs/2026-10-20-170000/route.order.new/refused/02-assert-text.png'], $scenarios->failures);
        self::assertSame(['2 scenarios replayed — .devtools/runs/2026-10-20-170000/result.xml'], $scenarios->notes, 'The smoke and the broken one of route.order.new; no other route.');
        self::assertFileExists($project.'/.devtools/runs/2026-10-20-170000/route.order.new/refused/02-assert-text.png');
        self::assertSame(GateStatus::Failed, $result->steps[2]->status(), 'The verification ran all the same.');
    }
}
