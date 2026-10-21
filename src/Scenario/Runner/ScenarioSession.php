<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Runner;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Scenario\Run\RunResult;
use Jul6Art\DevTools\Scenario\Run\XmlRunWriter;
use Jul6Art\DevTools\Scenario\Scenario;
use Jul6Art\DevTools\Scenario\Visual\VisualComparator;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Panther\Client;

/**
 * One replay of scenarios against an application where it is served (ADR-0021): Chrome opened once, the logins
 * and the fixtures of `config.xml`, each screenshot compared with its baseline (ADR-0022), and the run written
 * in `.devtools/runs/<timestamp>/` — what `scenarios:run` and the gate (ADR-0023) both do.
 */
final readonly class ScenarioSession
{
    public const string VIEWPORT = '1280x800';

    /**
     * @param \Closure(string, string, bool): Client|null $browser opens Chrome on a base URL, a viewport, headed or not
     */
    public function __construct(private ?\Closure $browser = null)
    {
    }

    /**
     * @param list<Scenario> $scenarios
     *
     * @return array{RunResult, string, string|null} the run, its directory, and what the comparison could not do
     */
    public function run(DevToolsDirectory $directory, Config $config, array $scenarios, string $baseUrl, \DateTimeImmutable $startedAt, ?int $timeout = null, string $viewport = self::VIEWPORT, bool $headed = false): array
    {
        $settings = $config->scenarios;
        $timeout ??= $settings->timeout;
        $artifacts = $directory->runDirectory($startedAt);
        $secrets = new Secrets();
        $visual = VisualComparator::forBaselines($directory->baselinesDirectory(), $settings->visual);
        $browser = $this->browser ?? self::chrome($directory);
        $runner = new PantherRunner(
            static fn (): Client => $browser(rtrim($baseUrl, '/'), $viewport, $headed),
            RouteMap::fromTracking($directory, new WorkflowTypeRegistry($config->customTypes)),
            new ConfiguredLoginProvider($settings, $secrets, $timeout),
            new ConfiguredFixtureProvider($settings, $directory->root, $secrets),
            $secrets,
            $timeout,
            $visual,
        );

        try {
            $results = $runner->run($scenarios, $artifacts);
        } finally {
            $runner->quit();
        }

        $run = new RunResult($startedAt, $secrets->redact($baseUrl), $results);
        new XmlRunWriter()->write($artifacts.'/result.xml', $run);

        return [$run, $artifacts, $visual->warning()];
    }

    /**
     * Chrome through the ChromeDriver of the project — `vendor/bin/bdi detect drivers` puts it in `drivers/` —
     * else Panther's search.
     *
     * @return \Closure(string, string, bool): Client
     */
    private static function chrome(DevToolsDirectory $directory): \Closure
    {
        return static function (string $baseUrl, string $viewport, bool $headed) use ($directory): Client {
            $arguments = ['--window-size='.str_replace('x', ',', $viewport), '--disable-gpu', '--disable-dev-shm-usage', '--force-prefers-reduced-motion'];

            if (!$headed) {
                $arguments[] = '--headless=new';
            }

            if (filter_var(getenv('PANTHER_NO_SANDBOX'), \FILTER_VALIDATE_BOOLEAN)) {
                $arguments[] = '--no-sandbox';
            }

            $driver = $directory->root->path.'/drivers/chromedriver';

            return Client::createChromeClient(is_file($driver) ? $driver : null, $arguments, [], $baseUrl);
        };
    }
}
