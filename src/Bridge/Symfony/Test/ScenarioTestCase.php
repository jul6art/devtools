<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Bridge\Symfony\Test;

use Jul6Art\DevTools\Config\ScenarioSettings;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Scenario\Run\RunResult;
use Jul6Art\DevTools\Scenario\Run\ScenarioResult;
use Jul6Art\DevTools\Scenario\Run\Verdict;
use Jul6Art\DevTools\Scenario\Run\XmlRunWriter;
use Jul6Art\DevTools\Scenario\Runner\ConfiguredFixtureProvider;
use Jul6Art\DevTools\Scenario\Runner\ConfiguredLoginProvider;
use Jul6Art\DevTools\Scenario\Runner\FixtureProviderInterface;
use Jul6Art\DevTools\Scenario\Runner\LoginProviderInterface;
use Jul6Art\DevTools\Scenario\Runner\PantherRunner;
use Jul6Art\DevTools\Scenario\Runner\RouteMap;
use Jul6Art\DevTools\Scenario\Runner\Secrets;
use Jul6Art\DevTools\Scenario\Visual\VisualComparator;
use Jul6Art\DevTools\Scenario\XmlScenarioStore;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

/**
 * The scenarios of the application, one PHPUnit test each (ADR-0021): extend it, and `.devtools/scenarios/` is
 * replayed by the same runner as `scenarios:run`, against the application Panther serves from `public/`.
 *
 * ```php
 * final class ScenariosTest extends ScenarioTestCase
 * {
 * }
 * ```
 *
 * The logins and the fixtures are the services of the application that implement `LoginProviderInterface`
 * and `FixtureProviderInterface`, and without them the `<scenarios>` of `.devtools/config.xml`. The application
 * runs in the web server Panther starts, another process: a transaction of the test does not isolate its
 * data — a fixture provider that resets what a scenario needs does.
 *
 * The kernel is booted only to fetch those services: a project without `KERNEL_CLASS` replays its scenarios
 * all the same.
 */
abstract class ScenarioTestCase extends PantherTestCase
{
    private static ?Client $browser = null;

    /**
     * The run all the cases of the class make together: one directory, one `result.xml`, which
     * `baselines:accept` reads as it reads one of `scenarios:run`.
     */
    private static ?\DateTimeImmutable $startedAt = null;

    /**
     * @var list<ScenarioResult>
     */
    private static array $results = [];

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$browser?->quit();
        self::$browser = null;

        if (self::$startedAt instanceof \DateTimeImmutable && [] !== self::$results) {
            new XmlRunWriter()->write(DevToolsDirectory::documented(new ProjectRoot(static::projectDir()))->runDirectory(self::$startedAt).'/result.xml', new RunResult(self::$startedAt, (string) self::$baseUri, self::$results));
        }

        self::$startedAt = null;
        self::$results = [];

        parent::tearDownAfterClass();
    }

    /**
     * One case per scenario of the project, named after it — a single skipped one when there is none.
     *
     * @return iterable<string, array{string|null}>
     */
    public static function scenarios(): iterable
    {
        $files = glob(static::projectDir().'/'.DevToolsDirectory::NAME.'/scenarios/*/*.xml') ?: [];

        if ([] === $files) {
            yield 'no scenario' => [null];
        }

        foreach ($files as $file) {
            yield basename(\dirname($file)).'/'.basename($file, '.xml') => [$file];
        }
    }

    #[DataProvider('scenarios')]
    public function testScenario(?string $file): void
    {
        if (null === $file) {
            self::markTestSkipped('No scenario in .devtools/scenarios/: scenarios:generate writes them.');
        }

        $root = new ProjectRoot(static::projectDir());
        $directory = DevToolsDirectory::documented($root);
        $config = new XmlConfigReader()->read($directory->configFile());
        $secrets = new Secrets();
        $scenario = new XmlScenarioStore()->read($file);

        static::startWebServer(static::webServerOptions());
        $browser = self::$browser ??= Client::createChromeClient(null, static::browserArguments(), [], (string) self::$baseUri);

        $result = new PantherRunner(
            static fn (): Client => $browser,
            RouteMap::fromTracking($directory, new WorkflowTypeRegistry($config->customTypes)),
            $this->loginProvider($config->scenarios, $secrets),
            $this->fixtureProvider($config->scenarios, $root, $secrets),
            $secrets,
            $config->scenarios->timeout,
            VisualComparator::forBaselines($directory->baselinesDirectory(), $config->scenarios->visual),
        )->run([$scenario], $directory->runDirectory(self::$startedAt ??= new \DateTimeImmutable()))[0];
        self::$results[] = $result;

        self::assertSame(Verdict::Passed, $result->verdict, (string) $result->failure());
    }

    /**
     * The project whose scenarios are replayed: the directory PHPUnit runs from, as in a Symfony project.
     */
    protected static function projectDir(): string
    {
        return (string) getcwd();
    }

    /**
     * What Panther serves: `public/` of the project, on its port.
     *
     * @return array<string, mixed>
     */
    protected static function webServerOptions(): array
    {
        return ['webServerDir' => static::projectDir().'/public'];
    }

    /**
     * @return list<string>
     */
    protected static function browserArguments(): array
    {
        return ['--headless=new', '--window-size=1280,800', '--disable-gpu', '--disable-dev-shm-usage', '--force-prefers-reduced-motion', ...(filter_var(getenv('PANTHER_NO_SANDBOX'), \FILTER_VALIDATE_BOOLEAN) ? ['--no-sandbox'] : [])];
    }

    protected function loginProvider(ScenarioSettings $settings, Secrets $secrets): LoginProviderInterface
    {
        $service = $this->service(LoginProviderInterface::class);

        return $service instanceof LoginProviderInterface ? $service : new ConfiguredLoginProvider($settings, $secrets, $settings->timeout);
    }

    protected function fixtureProvider(ScenarioSettings $settings, ProjectRoot $root, Secrets $secrets): FixtureProviderInterface
    {
        $service = $this->service(FixtureProviderInterface::class);

        return $service instanceof FixtureProviderInterface ? $service : new ConfiguredFixtureProvider($settings, $root, $secrets);
    }

    private function service(string $id): ?object
    {
        if (!isset($_SERVER['KERNEL_CLASS']) && !isset($_ENV['KERNEL_CLASS'])) {
            return null;
        }

        $container = static::getContainer();

        return $container->has($id) ? $container->get($id) : null;
    }
}
