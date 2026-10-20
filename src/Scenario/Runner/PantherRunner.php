<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Runner;

use Composer\InstalledVersions;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\StaleElementReferenceException;
use Facebook\WebDriver\Exception\TimeoutException;
use Jul6Art\DevTools\Config\ScenarioSettings;
use Jul6Art\DevTools\Scenario\Action\ActionContext;
use Jul6Art\DevTools\Scenario\Action\ActionInterface;
use Jul6Art\DevTools\Scenario\Action\AssertCountAction;
use Jul6Art\DevTools\Scenario\Action\AssertRouteAction;
use Jul6Art\DevTools\Scenario\Action\AssertTextAction;
use Jul6Art\DevTools\Scenario\Action\AssertVisibleAction;
use Jul6Art\DevTools\Scenario\Action\CheckAction;
use Jul6Art\DevTools\Scenario\Action\ClickAction;
use Jul6Art\DevTools\Scenario\Action\FillAction;
use Jul6Art\DevTools\Scenario\Action\GotoAction;
use Jul6Art\DevTools\Scenario\Action\ScreenshotAction;
use Jul6Art\DevTools\Scenario\Action\Select2Action;
use Jul6Art\DevTools\Scenario\Action\SelectAction;
use Jul6Art\DevTools\Scenario\Action\SubmitAction;
use Jul6Art\DevTools\Scenario\Action\WaitForAction;
use Jul6Art\DevTools\Scenario\Run\ScenarioResult;
use Jul6Art\DevTools\Scenario\Run\StepResult;
use Jul6Art\DevTools\Scenario\Run\Verdict;
use Jul6Art\DevTools\Scenario\Scenario;
use Jul6Art\DevTools\Scenario\Step;
use Jul6Art\DevTools\Scenario\Visual\VisualComparator;
use Jul6Art\DevTools\Scenario\Visual\VisualVerdict;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Panther\Client;

/**
 * Replays scenarios in Chrome through Panther (ADR-0021): one browser for the whole run, cleaned between
 * scenarios; the fixtures loaded and the role logged in before the first step; every step waited for, never
 * paused. The first step that fails stops its scenario, and the screen and the DOM it left are kept.
 */
final class PantherRunner implements RunnerInterface
{
    /**
     * What to install when Panther is missing.
     */
    public const string PACKAGE = 'symfony/panther:^2.4';

    /**
     * Hides the masked elements — their place kept, so that nothing else moves.
     */
    private const string HIDE = <<<'JS'
        var style = document.createElement('style');
        style.id = 'devtools-masks';
        style.textContent = arguments[0].map(function (selector) { return selector + ' { visibility: hidden !important; }'; }).join('\n');
        (document.head || document.documentElement).appendChild(style);
        JS;

    /**
     * The DOM, the masked elements emptied — in a copy: the page itself is left alone.
     */
    private const string DOM = <<<'JS'
        var copy = document.documentElement.cloneNode(true);
        arguments[0].forEach(function (selector) {
            try {
                copy.querySelectorAll(selector).forEach(function (element) { element.textContent = ''; element.setAttribute('data-devtools-masked', ''); });
            } catch (error) {}
        });
        return '<!DOCTYPE html>\n' + copy.outerHTML;
        JS;

    /**
     * @var array<string, ActionInterface>
     */
    private array $actions = [];

    private ?Client $client = null;

    /**
     * @param \Closure(): Client         $browser opens the browser, once, when the first scenario needs it
     * @param VisualComparator|null      $visual  compares each screenshot with its baseline (ADR-0022)
     * @param list<ActionInterface>|null $actions null: the whole vocabulary
     */
    public function __construct(
        private readonly \Closure $browser,
        private readonly RouteMap $routes,
        private readonly LoginProviderInterface $login,
        private readonly FixtureProviderInterface $fixtures,
        private readonly Secrets $secrets = new Secrets(),
        private readonly int $timeout = ScenarioSettings::DEFAULT_TIMEOUT,
        private readonly ?VisualComparator $visual = null,
        ?array $actions = null,
    ) {
        foreach ($actions ?? self::vocabulary() as $action) {
            $this->actions[$action->name()] = $action;
        }
    }

    /**
     * Read from the packages Composer installed, without loading Panther: its client pulls BrowserKit in, and
     * every command would pay for it to learn whether one of them exists.
     */
    public static function available(): bool
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('symfony/panther')) {
            return true;
        }

        return class_exists(Client::class);
    }

    /**
     * One class per action of `scenario.xsd`, in its order.
     *
     * @return list<ActionInterface>
     */
    public static function vocabulary(): array
    {
        return [
            new GotoAction(), new FillAction(), new SelectAction(), new CheckAction(), new ClickAction(), new SubmitAction(), new WaitForAction(),
            new AssertRouteAction(), new AssertVisibleAction(), new AssertTextAction(), new AssertCountAction(), new ScreenshotAction(), new Select2Action(),
        ];
    }

    #[\Override]
    public function run(array $scenarios, string $artifacts): array
    {
        return array_map(fn (Scenario $scenario): ScenarioResult => $this->replay($scenario, $artifacts), $scenarios);
    }

    public function quit(): void
    {
        $this->client?->quit();
        $this->client = null;
    }

    private function replay(Scenario $scenario, string $artifacts): ScenarioResult
    {
        $started = hrtime(true);
        $client = $this->client ??= ($this->browser)();

        try {
            ConfiguredLoginProvider::reset($client);

            foreach ($scenario->fixtures as $fixture) {
                $this->fixtures->load($fixture);
            }

            if (null !== $scenario->login) {
                $this->login->login($client, $scenario->login);
            }
        } catch (\Throwable $failure) {
            return new ScenarioResult($scenario->id, array_map(static fn (int $index, Step $step): StepResult => new StepResult($index + 1, $step->action, Verdict::Skipped), array_keys($scenario->steps), $scenario->steps), self::since($started), $this->secrets->redact(\sprintf('Before the first step: %s', $failure->getMessage())));
        }

        $results = [];
        $stopped = false;

        foreach ($scenario->steps as $offset => $step) {
            $index = $offset + 1;

            if ($stopped) {
                $results[] = new StepResult($index, $step->action, Verdict::Skipped);

                continue;
            }

            $results[] = $result = $this->step($client, $scenario, $index, $step, $artifacts);
            $stopped = Verdict::Passed !== $result->verdict;
        }

        return new ScenarioResult($scenario->id, $results, self::since($started));
    }

    private function step(Client $client, Scenario $scenario, int $index, Step $step, string $artifacts): StepResult
    {
        $started = hrtime(true);
        $captured = [];
        $visual = null;
        $capture = function (string $name) use ($client, $scenario, $artifacts, &$captured, &$visual): void {
            $captured = $this->capture($client, $artifacts, $scenario->id.'/'.$name, $this->visual?->masks() ?? []);
            $visual = $this->visual?->compare($artifacts, $scenario->id.'/'.$name);
        };
        $action = $this->actions[$step->action] ?? null;

        try {
            if (!$action instanceof ActionInterface) {
                throw new \LogicException(\sprintf('The runner has no implementation of "%s".', $step->action));
            }

            $action->perform($client, $step, new ActionContext($this->routes, $this->timeout, $capture));
            [$verdict, $message] = VisualVerdict::Different === $visual?->verdict ? [Verdict::Failed, $visual->summary] : [Verdict::Passed, null];
        } catch (StepFailed|NoSuchElementException|TimeoutException|StaleElementReferenceException $failed) {
            [$verdict, $message] = [Verdict::Failed, $failed->getMessage()];
        } catch (\Throwable $error) {
            [$verdict, $message] = [Verdict::Error, $error->getMessage()];
        }

        if (Verdict::Passed !== $verdict && [] === $captured) {
            try {
                $captured = $this->capture($client, $artifacts, \sprintf('%s/%02d-%s', $scenario->id, $index, $step->action));
            } catch (\Throwable) {
                // The browser that failed the step may not answer any more: the verdict stands without its picture.
            }
        }

        return new StepResult(
            $index,
            $step->action,
            $verdict,
            self::since($started),
            null === $message ? null : $this->secrets->redact(trim(strtok($message, "\n") ?: $message)),
            Verdict::Passed === $verdict && [] === $captured ? null : $this->url($client),
            $captured['screenshot'] ?? null,
            $captured['dom'] ?? null,
            $visual,
        );
    }

    /**
     * The screen and the DOM. With masks — a `screenshot` compared with its baseline — what changes on every run
     * is hidden from the image and emptied in the DOM, and the page is given back as it was.
     *
     * @param list<string>|null $masks CSS selectors; null for the capture of a failure, taken as it is
     *
     * @return array{screenshot: string, dom: string} relative to the run's directory
     */
    private function capture(Client $client, string $artifacts, string $name, ?array $masks = null): array
    {
        new Filesystem()->mkdir(\dirname($artifacts.'/'.$name));

        if (null === $masks) {
            $client->takeScreenshot($artifacts.'/'.$name.'.png');
            $dom = $client->getPageSource();
        } else {
            $client->executeScript(self::HIDE, [$masks]);

            try {
                $client->takeScreenshot($artifacts.'/'.$name.'.png');
            } finally {
                $client->executeScript('var style = document.getElementById("devtools-masks"); if (style) { style.remove(); }');
            }

            $dom = $client->executeScript(self::DOM, [$masks]);
            $dom = \is_string($dom) ? $dom : $client->getPageSource();
        }

        file_put_contents($artifacts.'/'.$name.'.html', $this->secrets->redact($dom));

        return ['screenshot' => $name.'.png', 'dom' => $name.'.html'];
    }

    private function url(Client $client): ?string
    {
        try {
            return $this->secrets->redact($client->getCurrentURL());
        } catch (\Throwable) {
            return null;
        }
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
