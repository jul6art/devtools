<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Scenario\Runner;

use Jul6Art\DevTools\Config\FixtureSettings;
use Jul6Art\DevTools\Config\InvalidConfig;
use Jul6Art\DevTools\Config\LoginSettings;
use Jul6Art\DevTools\Config\ScenarioSettings;
use Jul6Art\DevTools\Config\VisualSettings;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Scenario\Run\RunResult;
use Jul6Art\DevTools\Scenario\Run\ScenarioResult;
use Jul6Art\DevTools\Scenario\Run\StepResult;
use Jul6Art\DevTools\Scenario\Run\Verdict;
use Jul6Art\DevTools\Scenario\Run\XmlRunWriter;
use Jul6Art\DevTools\Scenario\Runner\RouteMap;
use Jul6Art\DevTools\Scenario\Runner\Secrets;
use Jul6Art\DevTools\Scenario\Visual\VisualResult;
use Jul6Art\DevTools\Scenario\Visual\VisualVerdict;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0021, without a browser: the routes a scenario names, the credentials nothing repeats, the verdicts, the
 * result written, and the `<scenarios>` of `config.xml`.
 */
#[CoversClass(RouteMap::class)]
#[CoversClass(Secrets::class)]
#[CoversClass(Verdict::class)]
#[CoversClass(StepResult::class)]
#[CoversClass(ScenarioResult::class)]
#[CoversClass(RunResult::class)]
#[CoversClass(XmlRunWriter::class)]
#[CoversClass(ScenarioSettings::class)]
#[CoversClass(LoginSettings::class)]
#[CoversClass(FixtureSettings::class)]
#[CoversClass(XmlConfigReader::class)]
#[CoversClass(VisualSettings::class)]
final class RunModelTest extends TestCase
{
    use UsesTemporaryDirectory;

    private RouteMap $routes;

    protected function setUp(): void
    {
        $this->routes = new RouteMap(['show' => '/orders/{id}', 'typed' => '/orders/{id<\d+>}/lines', 'page' => '/list/{page?1}', 'bang' => '/posts/{!slug}', 'home' => '/']);
    }

    /**
     * @param array<string, string> $parameters
     */
    #[TestWith(['show', ['id' => '12'], '/orders/12'])]
    #[TestWith(['show', ['id' => 'a b/c'], '/orders/a%20b%2Fc'])]
    #[TestWith(['show', ['id' => '3', 'tab' => 'lines'], '/orders/3?tab=lines'])]
    #[TestWith(['typed', ['id' => '4'], '/orders/4/lines'])]
    #[TestWith(['page', [], '/list/1'])]
    #[TestWith(['page', ['page' => '2'], '/list/2'])]
    #[TestWith(['bang', ['slug' => 'hello'], '/posts/hello'])]
    #[TestWith(['home', [], '/'])]
    public function testARouteIsBuiltAsSymfonyBuildsIt(string $route, array $parameters, string $url): void
    {
        self::assertSame($url, $this->routes->url($route, $parameters));
    }

    #[TestWith(['show', 'http://app.localhost/orders/12', true])]
    #[TestWith(['show', 'http://app.localhost/orders/12/', true])]
    #[TestWith(['show', 'http://app.localhost/orders/12?tab=x', true])]
    #[TestWith(['show', 'http://app.localhost/orders', false])]
    #[TestWith(['show', 'http://app.localhost/orders/12/lines', false])]
    #[TestWith(['typed', 'http://app.localhost/orders/7/lines', true])]
    #[TestWith(['typed', 'http://app.localhost/orders/x/lines', false])]
    #[TestWith(['page', 'http://app.localhost/list/', true])]
    #[TestWith(['page', 'http://app.localhost/list/3', true])]
    #[TestWith(['home', 'http://app.localhost/', true])]
    #[TestWith(['home', 'http://app.localhost', false])]
    public function testAUrlIsOrIsNotAPageOfARoute(string $route, string $url, bool $matches): void
    {
        self::assertSame($matches, $this->routes->matches($route, $url));
    }

    public function testAnUnknownRouteOrAMissingParameterIsSaid(): void
    {
        try {
            $this->routes->url('nowhere');
            self::fail('An unknown route.');
        } catch (\InvalidArgumentException $unknown) {
            self::assertSame('The route "nowhere" is unknown to the last inspection: run workflows:inspect.', $unknown->getMessage());
        }

        $this->expectExceptionMessageIsOrContains('The route "show" needs the parameter "id": <param name="id" value="…"/>.');
        $this->routes->url('show');
    }

    public function testACredentialIsMaskedInEveryFormItCanTake(): void
    {
        $secrets = new Secrets();
        $secrets->add('');
        $secrets->add('p@ss word');
        $secrets->add('tester');

        self::assertSame('no secret', new Secrets()->redact('no secret'));
        self::assertSame('*** and ***', $secrets->redact('tester and p@ss word'));
        self::assertSame('http://app.localhost/?q=***&r=***', $secrets->redact('http://user:pw@app.localhost/?q=p%40ss%20word&r=p%40ss+word'));
        self::assertSame('Basic ***', $secrets->redact('Basic '.base64_encode('tester')));
        self::assertSame('<input value="***">', $secrets->redact('<input value="'.htmlspecialchars('p@ss word').'">'));
    }

    public function testTheVerdictOfAScenarioIsItsWorstStep(): void
    {
        self::assertSame(Verdict::Passed, Verdict::worst([]));
        self::assertSame(Verdict::Passed, Verdict::worst([Verdict::Passed, Verdict::Skipped]));
        self::assertSame(Verdict::Failed, Verdict::worst([Verdict::Passed, Verdict::Failed, Verdict::Skipped]));
        self::assertSame(Verdict::Error, Verdict::worst([Verdict::Failed, Verdict::Error]));
        self::assertSame(Verdict::Skipped, Verdict::worst([Verdict::Skipped]));

        $failed = new ScenarioResult('route.a/b', [new StepResult(1, 'goto', Verdict::Passed), new StepResult(2, 'click', Verdict::Failed, message: 'Nothing to click.'), new StepResult(3, 'screenshot', Verdict::Skipped)]);
        self::assertSame(Verdict::Failed, $failed->verdict);
        self::assertSame('step 2 (click): Nothing to click.', $failed->failure());
        self::assertSame('step 1 (goto): error', new ScenarioResult('route.a/b', [new StepResult(1, 'goto', Verdict::Error)])->failure());
        self::assertNull(new ScenarioResult('route.a/b', [new StepResult(1, 'goto', Verdict::Passed)])->failure());
        self::assertSame(Verdict::Error, new ScenarioResult('route.a/b', [], message: 'No login.')->verdict);
    }

    public function testTheResultFollowsItsSchema(): void
    {
        $run = new RunResult(new \DateTimeImmutable('2026-10-20T12:00:00+00:00'), 'http://app.localhost', [
            new ScenarioResult('route.a/passed', [
                new StepResult(1, 'screenshot', Verdict::Passed, 12, url: 'http://app.localhost/', screenshot: 'route.a/passed/home.png', dom: 'route.a/passed/home.html', visual: new VisualResult(VisualVerdict::NoBaseline)),
                new StepResult(2, 'screenshot', Verdict::Passed, 12, screenshot: 'route.a/passed/menu.png', dom: 'route.a/passed/menu.html', visual: new VisualResult(VisualVerdict::WithinThreshold, 0.05, 'route.a/passed/menu.diff.png', 'route.a/passed/menu.dom.diff', '0.05 % of the pixels differ.')),
            ], 40),
            new ScenarioResult('route.a/failed', [new StepResult(1, 'assert-text', Verdict::Failed, 1001, 'Not there.', 'http://app.localhost/x')], 1100),
            new ScenarioResult('route.a/error', [new StepResult(1, 'goto', Verdict::Skipped)], 5, 'Before the first step: no login.'),
        ]);
        $file = $this->temporaryDirectory().'/runs/now/result.xml';

        new XmlRunWriter()->write($file, $run);
        $xml = (string) file_get_contents($file);

        self::assertFalse($run->passed());
        self::assertSame(1, $run->count(Verdict::Failed));
        self::assertStringContainsString('passed="1" failed="1" errors="1"', $xml);
        self::assertStringContainsString('<screenshot path="route.a/passed/home.png"/>', $xml);
        self::assertStringContainsString('<message>Before the first step: no login.</message>', $xml);
        self::assertStringContainsString('<visual verdict="no-baseline"/>', $xml);
        self::assertStringContainsString('<visual verdict="within-threshold" difference="0.05" diff="route.a/passed/menu.diff.png" dom-diff="route.a/passed/menu.dom.diff">0.05 % of the pixels differ.</visual>', $xml);
        self::assertTrue(new RunResult(new \DateTimeImmutable(), 'http://x', [])->passed());
    }

    public function testTheScenariosOfTheConfigurationAreRead(): void
    {
        $file = $this->temporaryDirectory().'/config.xml';
        file_put_contents($file, <<<'XML'
            <devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1">
                <scenarios base-url="https://app.localhost" timeout="20">
                    <login role="ROLE_USER" type="form" url="/login" username-env="APP_USER" password-env="APP_PASSWORD" username-field="email" password-field="pw" submit="Log in"/>
                    <login role="ROLE_ADMIN" type="http-basic" username-env="ADMIN_USER" password-env="ADMIN_PASSWORD"/>
                    <fixture name="catalog" command="bin/console app:catalog"/>
                    <visual threshold="0.5%"><mask selector=".date"/><mask selector="#avatar"/></visual>
                </scenarios>
            </devtools>
            XML);

        $settings = new XmlConfigReader()->read($file)->scenarios;

        self::assertSame('https://app.localhost', $settings->baseUrl);
        self::assertSame(20, $settings->timeout);
        self::assertEquals(new LoginSettings('ROLE_USER', 'form', 'APP_USER', 'APP_PASSWORD', '/login', 'email', 'pw', 'Log in'), $settings->login('ROLE_USER'));
        self::assertEquals(new LoginSettings('ROLE_ADMIN', 'http-basic', 'ADMIN_USER', 'ADMIN_PASSWORD'), $settings->login('ROLE_ADMIN'));
        self::assertNull($settings->login('ROLE_NOBODY'));
        self::assertSame('bin/console app:catalog', $settings->fixture('catalog')?->command);
        self::assertNull($settings->fixture('none'));
        self::assertEquals(new VisualSettings(0.5, ['.date', '#avatar']), $settings->visual);
        self::assertEquals(new ScenarioSettings(), new XmlConfigReader()->read($this->temporaryDirectory().'/none.xml')->scenarios);

        file_put_contents($file, str_replace('username-env="ADMIN_USER"', 'username-env="ADMIN USER"', (string) file_get_contents($file)));
        $this->expectException(InvalidXml::class);
        new XmlConfigReader()->read($file);
    }

    #[TestWith([0, 'The timeout of a scenario step must be a positive number of seconds.'])]
    #[TestWith([1, 'The role "ROLE_USER" logs in twice'])]
    #[TestWith([2, 'The fixture "catalog" is declared twice'])]
    #[TestWith([3, '"oauth" is not a way to log in'])]
    #[TestWith([4, 'The login of "ROLE_USER" is a form: give its url.'])]
    public function testAConfigurationThatCannotWorkIsRefused(int $case, string $message): void
    {
        $login = static fn (): LoginSettings => new LoginSettings('ROLE_USER', 'http-basic', 'U', 'P');
        $fixture = new FixtureSettings('catalog', 'true');

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessageIsOrContains($message);

        match ($case) {
            0 => new ScenarioSettings(timeout: 0),
            1 => new ScenarioSettings(logins: [$login(), $login()]),
            2 => new ScenarioSettings(fixtures: [$fixture, $fixture]),
            3 => new LoginSettings('ROLE_USER', 'oauth', 'U', 'P'),
            default => new LoginSettings('ROLE_USER', 'form', 'U', 'P'),
        };
    }
}
