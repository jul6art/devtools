<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Scenario\Runner;

use Jul6Art\DevTools\Config\FixtureSettings;
use Jul6Art\DevTools\Config\LoginSettings;
use Jul6Art\DevTools\Config\ScenarioSettings;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Scenario\Action\AssertCountAction;
use Jul6Art\DevTools\Scenario\Action\AssertRouteAction;
use Jul6Art\DevTools\Scenario\Action\AssertTextAction;
use Jul6Art\DevTools\Scenario\Action\AssertVisibleAction;
use Jul6Art\DevTools\Scenario\Action\CheckAction;
use Jul6Art\DevTools\Scenario\Action\ClickAction;
use Jul6Art\DevTools\Scenario\Action\Elements;
use Jul6Art\DevTools\Scenario\Action\FillAction;
use Jul6Art\DevTools\Scenario\Action\GotoAction;
use Jul6Art\DevTools\Scenario\Action\ScreenshotAction;
use Jul6Art\DevTools\Scenario\Action\Select2Action;
use Jul6Art\DevTools\Scenario\Action\SelectAction;
use Jul6Art\DevTools\Scenario\Action\SubmitAction;
use Jul6Art\DevTools\Scenario\Action\WaitForAction;
use Jul6Art\DevTools\Scenario\Run\RunResult;
use Jul6Art\DevTools\Scenario\Run\ScenarioResult;
use Jul6Art\DevTools\Scenario\Run\StepResult;
use Jul6Art\DevTools\Scenario\Run\Verdict;
use Jul6Art\DevTools\Scenario\Run\XmlRunWriter;
use Jul6Art\DevTools\Scenario\Runner\ConfiguredFixtureProvider;
use Jul6Art\DevTools\Scenario\Runner\ConfiguredLoginProvider;
use Jul6Art\DevTools\Scenario\Runner\PantherRunner;
use Jul6Art\DevTools\Scenario\Runner\RouteMap;
use Jul6Art\DevTools\Scenario\Runner\Secrets;
use Jul6Art\DevTools\Scenario\Scenario;
use Jul6Art\DevTools\Scenario\Step;
use Jul6Art\DevTools\Tests\Support\Browser;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Panther\Client;
use Symfony\Component\Process\Process;

/**
 * ADR-0021: every action of the vocabulary replayed in Chrome against a site served by `php -S` — what passes,
 * what fails and how it says so, the logins, the fixtures, and the credentials nothing repeats.
 */
#[Group('browser')]
#[CoversClass(PantherRunner::class)]
#[CoversClass(Elements::class)]
#[CoversClass(GotoAction::class)]
#[CoversClass(FillAction::class)]
#[CoversClass(SelectAction::class)]
#[CoversClass(CheckAction::class)]
#[CoversClass(ClickAction::class)]
#[CoversClass(SubmitAction::class)]
#[CoversClass(WaitForAction::class)]
#[CoversClass(AssertRouteAction::class)]
#[CoversClass(AssertVisibleAction::class)]
#[CoversClass(AssertTextAction::class)]
#[CoversClass(AssertCountAction::class)]
#[CoversClass(ScreenshotAction::class)]
#[CoversClass(Select2Action::class)]
#[CoversClass(ConfiguredLoginProvider::class)]
#[CoversClass(ConfiguredFixtureProvider::class)]
#[CoversClass(RouteMap::class)]
#[CoversClass(Secrets::class)]
#[CoversClass(ScenarioResult::class)]
#[CoversClass(StepResult::class)]
#[CoversClass(Verdict::class)]
#[CoversClass(XmlRunWriter::class)]
final class PantherRunnerTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const array ROUTES = ['home' => '/', 'form' => '/form', 'done' => '/done', 'item' => '/items/{id}', 'select2' => '/select2', 'login' => '/login', 'private' => '/private'];

    private static ?Process $server = null;

    private static ?Client $client = null;

    private static string $baseUrl = '';

    private string $artifacts;

    public static function setUpBeforeClass(): void
    {
        if (null === Browser::driver()) {
            return;
        }

        [self::$server, self::$baseUrl] = Browser::serve(__DIR__.'/../../Fixtures/browser', __DIR__.'/../../Fixtures/browser/router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$client?->quit();
        self::$client = null;
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        Browser::skipWithout();
        $this->artifacts = $this->temporaryDirectory();
    }

    public function testAPageIsReachedByItsRouteItsParametersAndItsQuery(): void
    {
        $result = $this->replay([
            new Step('goto', ['route' => 'item'], ['id' => '7', 'tab' => 'notes']),
            new Step('assert-route', ['name' => 'item']),
            new Step('assert-text', ['text' => 'Item #7', 'selector' => 'h1']),
            new Step('assert-text', ['text' => 'notes', 'selector' => '.query']),
        ]);

        $this->assertPassed($result);
    }

    /**
     * A form as a person fills it: by the field's name, its id or its label; an option by its text or its value;
     * a box checked; the button clicked by what it reads.
     */
    public function testAFormIsFilledAndSentByWhatAPersonReads(): void
    {
        $result = $this->replay([
            new Step('goto', ['route' => 'form']),
            new Step('fill', ['field' => 'Customer', 'value' => 'ACME']),
            new Step('select', ['field' => 'status', 'value' => 'Paid']),
            new Step('check', ['field' => 'Urgent']),
            new Step('check', ['field' => 'urgent']),
            new Step('click', ['text' => 'Save']),
            new Step('assert-route', ['name' => 'done']),
            new Step('assert-visible', ['selector' => '.flash-success']),
            new Step('assert-text', ['text' => 'ACME', 'selector' => '.customer']),
            new Step('assert-text', ['text' => 'paid', 'selector' => '.status']),
            new Step('assert-text', ['text' => 'yes', 'selector' => '.urgent']),
            new Step('assert-text', ['text' => 'none', 'selector' => '.product']),
        ]);

        $this->assertPassed($result);
    }

    public function testAnOptionIsChosenByItsValueAndAFormSubmittedAsIs(): void
    {
        $result = $this->replay([
            new Step('goto', ['route' => 'form']),
            new Step('select', ['field' => 'status', 'value' => 'new']),
            new Step('fill', ['field' => 'customer', 'value' => 'Initech']),
            new Step('submit', ['selector' => '#order']),
            new Step('assert-route', ['name' => 'done']),
            new Step('assert-text', ['text' => 'Initech']),
            new Step('assert-text', ['text' => 'no', 'selector' => '.urgent']),
        ]);

        $this->assertPassed($result);
    }

    public function testAClickFindsALinkARoleButtonOrASelector(): void
    {
        $this->assertPassed($this->replay([new Step('goto', ['route' => 'home']), new Step('click', ['text' => 'Item seven']), new Step('assert-route', ['name' => 'item'])]));
        $this->assertPassed($this->replay([new Step('goto', ['route' => 'home']), new Step('click', ['text' => 'Go']), new Step('assert-route', ['name' => 'form'])]));
        $this->assertPassed($this->replay([new Step('goto', ['route' => 'home']), new Step('click', ['selector' => 'nav a[href="/form"]']), new Step('assert-route', ['name' => 'form'])]));
    }

    /**
     * What a page shows late is waited for — never a fixed pause — and counted exactly.
     */
    public function testWhatArrivesLateIsWaitedForAndCounted(): void
    {
        $result = $this->replay([
            new Step('goto', ['route' => 'home']),
            new Step('wait-for', ['selector' => '.arrived', 'timeout' => '3000']),
            new Step('assert-text', ['text' => 'Arrived late', 'selector' => '#late']),
            new Step('assert-count', ['selector' => '.items li', 'count' => '3']),
            new Step('assert-count', ['selector' => '.nothing', 'count' => '0']),
        ]);

        $this->assertPassed($result);
    }

    /**
     * @param list<Step> $steps
     */
    #[DataProvider('failures')]
    public function testAnAssertionThatDoesNotHoldFailsSayingWhat(array $steps, string $message): void
    {
        $result = $this->replay([new Step('goto', ['route' => 'home']), ...$steps, new Step('screenshot', ['name' => 'never'])]);
        $failed = $result->steps[\count($steps)];

        self::assertSame(Verdict::Failed, $result->verdict);
        self::assertSame(Verdict::Failed, $failed->verdict);
        self::assertStringContainsString($message, (string) $failed->message);
        self::assertSame(Verdict::Skipped, array_last($result->steps)?->verdict, 'The first failure stops the scenario.');
        self::assertSame(self::$baseUrl.'/', $failed->url);
        self::assertFileExists($this->artifacts.'/'.$failed->screenshot);
        self::assertStringContainsString('<h1>Home</h1>', (string) file_get_contents($this->artifacts.'/'.$failed->dom));
        self::assertFileDoesNotExist($this->artifacts.'/route.test/case/never.png');
    }

    /**
     * @return iterable<string, array{list<Step>, string}>
     */
    public static function failures(): iterable
    {
        yield 'another route' => [[new Step('assert-route', ['name' => 'form'])], 'Expected the route "form" (/form), the browser is at /.'];
        yield 'a hidden element' => [[new Step('assert-visible', ['selector' => '.hidden'])], 'Nothing visible matches ".hidden" after 1 s.'];
        yield 'a text that is not there' => [[new Step('assert-text', ['text' => 'Goodbye'])], '"body" does not read "Goodbye"'];
        yield 'a count that differs' => [[new Step('assert-count', ['selector' => '.items li', 'count' => '4'])], '".items li" matches 3 elements, 4 expected.'];
        yield 'an element that never comes' => [[new Step('wait-for', ['selector' => '.never', 'timeout' => '500'])], 'Nothing matches ".never" after 1 s.'];
        yield 'nothing to click' => [[new Step('click', ['text' => 'Delete everything'])], 'Nothing to click reads "Delete everything"'];
        yield 'no such field' => [[new Step('fill', ['field' => 'email', 'value' => 'x'])], 'No field "email"'];
    }

    public function testARouteTheRunnerCannotBuildIsAnErrorNotAFailure(): void
    {
        $result = $this->replay([new Step('goto', ['route' => 'item'])]);

        self::assertSame(Verdict::Error, $result->verdict);
        self::assertStringContainsString('The route "item" needs the parameter "id"', (string) $result->steps[0]->message);
        self::assertSame('step 1 (goto): The route "item" needs the parameter "id": <param name="id" value="…"/>.', $result->failure());
    }

    public function testAnOptionThatDoesNotExistFails(): void
    {
        $result = $this->replay([new Step('goto', ['route' => 'form']), new Step('select', ['field' => 'status', 'value' => 'Refunded'])]);

        self::assertSame('No option "Refunded" in "status".', $result->steps[1]->message);
    }

    public function testAScreenshotKeepsTheScreenAndTheDom(): void
    {
        $result = $this->replay([new Step('goto', ['route' => 'home']), new Step('screenshot', ['name' => 'home'])]);

        $this->assertPassed($result);
        self::assertSame('route.test/case/home.png', $result->steps[1]->screenshot);
        self::assertSame('route.test/case/home.html', $result->steps[1]->dom);
        self::assertSame(self::$baseUrl.'/', $result->steps[1]->url);
        self::assertStringStartsWith("\x89PNG", (string) file_get_contents($this->artifacts.'/route.test/case/home.png'));
        self::assertNull($result->steps[0]->url, 'A step that passed without capturing anything carries nothing.');
    }

    /**
     * A real Select2, searched and picked as a person does — local options, then remote ones that arrive late.
     */
    public function testASelect2FieldIsSearchedAndPicked(): void
    {
        $result = $this->replay([
            new Step('goto', ['route' => 'select2']),
            new Step('select2', ['field' => 'order[product]', 'search' => 'Wid', 'pick' => 'Widget XL']),
            new Step('select2', ['field' => 'order[customer]', 'search' => 'acm', 'pick' => 'ACME Corporation']),
        ]);

        $this->assertPassed($result);
        self::assertSame('wxl', $this->client()->executeScript('return document.getElementById("product").value;'));
        self::assertSame('acme', $this->client()->executeScript('return document.getElementById("customer").value;'));
    }

    public function testASelect2ResultThatDoesNotExistFails(): void
    {
        $result = $this->replay([new Step('goto', ['route' => 'select2']), new Step('select2', ['field' => 'order[product]', 'search' => 'Wid', 'pick' => 'Widget M'])]);

        self::assertSame(Verdict::Failed, $result->verdict);
        self::assertStringContainsString('No result "Widget M" for "Wid"', (string) $result->steps[1]->message);
    }

    /**
     * A login by a form and by HTTP basic, from the environment; the next scenario starts logged out; and
     * neither the result, nor a message, nor a captured DOM repeats a credential.
     */
    public function testTheLoginsComeFromTheEnvironmentAndNothingRepeatsThem(): void
    {
        putenv('DEVTOOLS_TEST_USER=tester');
        putenv('DEVTOOLS_TEST_PASSWORD=s3cret-pass');

        try {
            $settings = new ScenarioSettings(timeout: 2, logins: [
                new LoginSettings('ROLE_FORM', LoginSettings::FORM, 'DEVTOOLS_TEST_USER', 'DEVTOOLS_TEST_PASSWORD', '/login', submit: 'Sign in'),
                new LoginSettings('ROLE_BASIC', LoginSettings::HTTP_BASIC, 'DEVTOOLS_TEST_USER', 'DEVTOOLS_TEST_PASSWORD'),
            ]);
            $secrets = new Secrets();
            $private = [new Step('goto', ['route' => 'private']), new Step('assert-text', ['text' => 'Welcome', 'selector' => 'h1']), new Step('assert-text', ['text' => 'Nothing of the sort'])];
            [$form, $basic, $anonymous] = $this->runner($settings, $secrets)->run([
                new Scenario(new WorkflowId('route.test'), 'form', $private, 'ROLE_FORM'),
                new Scenario(new WorkflowId('route.test'), 'basic', $private, 'ROLE_BASIC'),
                new Scenario(new WorkflowId('route.test'), 'anonymous', [new Step('goto', ['route' => 'private']), new Step('assert-text', ['text' => 'Denied', 'selector' => 'h1'])]),
            ], $this->artifacts);
        } finally {
            putenv('DEVTOOLS_TEST_USER');
            putenv('DEVTOOLS_TEST_PASSWORD');
        }

        foreach ([$form, $basic] as $logged) {
            self::assertSame([Verdict::Passed, Verdict::Passed, Verdict::Failed], array_map(static fn (StepResult $step): Verdict => $step->verdict, $logged->steps), (string) $logged->failure());
            $dom = (string) file_get_contents($this->artifacts.'/'.$logged->steps[2]->dom);
            self::assertStringContainsString('Welcome ***', $dom);
            self::assertStringNotContainsString('tester', $dom);
            self::assertStringNotContainsString('s3cret-pass', $dom);
        }

        $this->assertPassed($anonymous);

        $xml = new XmlRunWriter()->serialize(new RunResult(new \DateTimeImmutable('2026-10-20T12:00:00+00:00'), self::$baseUrl, [$form, $basic, $anonymous]));
        self::assertStringNotContainsString('tester', $xml);
        self::assertStringNotContainsString('s3cret', $xml);
    }

    public function testALoginThatCannotHappenStopsTheScenarioBeforeItsFirstStep(): void
    {
        putenv('DEVTOOLS_TEST_USER=tester');
        putenv('DEVTOOLS_TEST_PASSWORD=wrong');

        try {
            $settings = new ScenarioSettings(timeout: 1, logins: [new LoginSettings('ROLE_FORM', LoginSettings::FORM, 'DEVTOOLS_TEST_USER', 'DEVTOOLS_TEST_PASSWORD', '/login'), new LoginSettings('ROLE_UNSET', LoginSettings::HTTP_BASIC, 'DEVTOOLS_TEST_NOBODY', 'DEVTOOLS_TEST_NOTHING')]);
            $steps = [new Step('goto', ['route' => 'private'])];
            [$refused, $unset, $unknown] = $this->runner($settings, new Secrets())->run([
                new Scenario(new WorkflowId('route.test'), 'refused', $steps, 'ROLE_FORM'),
                new Scenario(new WorkflowId('route.test'), 'unset', $steps, 'ROLE_UNSET'),
                new Scenario(new WorkflowId('route.test'), 'unknown', $steps, 'ROLE_NOBODY'),
            ], $this->artifacts);
        } finally {
            putenv('DEVTOOLS_TEST_USER');
            putenv('DEVTOOLS_TEST_PASSWORD');
        }

        self::assertSame(Verdict::Error, $refused->verdict);
        self::assertSame('Before the first step: The login form of "ROLE_FORM" did not let it in: check DEVTOOLS_TEST_USER and DEVTOOLS_TEST_PASSWORD.', $refused->message);
        self::assertSame([Verdict::Skipped], array_map(static fn (StepResult $step): Verdict => $step->verdict, $refused->steps));
        self::assertSame('Before the first step: The environment variable DEVTOOLS_TEST_NOBODY is not set: the login reads its credential there.', $unset->message);
        self::assertSame('Before the first step: No login for the role "ROLE_NOBODY" in the <scenarios> of .devtools/config.xml.', $unknown->message);
    }

    public function testTheFixturesAreTheProjectsCommandsRunFromItsRoot(): void
    {
        $project = $this->temporaryDirectory();
        $settings = new ScenarioSettings(fixtures: [
            new FixtureSettings('marked', \PHP_BINARY.' -r "file_put_contents(\'marked\', \'yes\');"'),
            new FixtureSettings('broken', \PHP_BINARY.' -r "fwrite(STDERR, \'no database\'); exit(3);"'),
        ]);
        $runner = new PantherRunner(fn (): Client => $this->client(), new RouteMap(self::ROUTES), new ConfiguredLoginProvider($settings, new Secrets()), new ConfiguredFixtureProvider($settings, new ProjectRoot($project), new Secrets()), timeout: 1);
        $home = [new Step('goto', ['route' => 'home'])];

        [$marked, $broken, $unknown] = $runner->run([
            new Scenario(new WorkflowId('route.test'), 'marked', $home, fixtures: ['marked']),
            new Scenario(new WorkflowId('route.test'), 'broken', $home, fixtures: ['broken']),
            new Scenario(new WorkflowId('route.test'), 'unknown', $home, fixtures: ['catalog']),
        ], $this->artifacts);

        $this->assertPassed($marked);
        self::assertStringEqualsFile($project.'/marked', 'yes');
        self::assertSame('Before the first step: The fixture "broken" failed (exit 3): no database', $broken->message);
        self::assertSame('Before the first step: No fixture "catalog" in the <scenarios> of .devtools/config.xml.', $unknown->message);
    }

    /**
     * @param list<Step> $steps
     */
    private function replay(array $steps): ScenarioResult
    {
        return $this->runner(new ScenarioSettings(timeout: 1), new Secrets())->run([new Scenario(new WorkflowId('route.test'), 'case', $steps)], $this->artifacts)[0];
    }

    private function runner(ScenarioSettings $settings, Secrets $secrets): PantherRunner
    {
        return new PantherRunner(fn (): Client => $this->client(), new RouteMap(self::ROUTES), new ConfiguredLoginProvider($settings, $secrets, $settings->timeout), new ConfiguredFixtureProvider($settings, new ProjectRoot($this->artifacts), $secrets), $secrets, $settings->timeout);
    }

    /**
     * One browser for the class, as for a run.
     */
    private function client(): Client
    {
        return self::$client ??= Browser::client(self::$baseUrl);
    }

    private function assertPassed(ScenarioResult $result): void
    {
        self::assertSame(Verdict::Passed, $result->verdict, (string) $result->failure());
    }
}
