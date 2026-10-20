<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
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
use Jul6Art\DevTools\Scenario\Visual\VisualComparator;
use Jul6Art\DevTools\Scenario\Visual\VisualVerdict;
use Jul6Art\DevTools\Scenario\XmlScenarioStore;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Panther\Client;

/**
 * Replays the scenarios of a project against the application where it is served (ADR-0021), in Chrome, and
 * writes their verdicts in `.devtools/runs/<timestamp>/result.xml`.
 *
 * Registered only when Panther is installed.
 */
#[AsCommand(name: self::NAME, description: 'Replay the scenarios in Chrome against a running application, and write their verdicts')]
final class ScenariosRunCommand extends Command
{
    public const string NAME = 'scenarios:run';

    public const string VIEWPORT = '1280x800';

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('scenarios', InputArgument::IS_ARRAY, 'Scenarios or workflows: route.order.new/happy-path, route.order.new (default: all)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Where the application is served (default: <scenarios base-url> of .devtools/config.xml)')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds a step waits for what it looks for (default: <scenarios timeout>, else 10)')
            ->addOption('viewport', null, InputOption::VALUE_REQUIRED, 'The size of the window: width x height', self::VIEWPORT)
            ->addOption('headed', null, InputOption::VALUE_NONE, 'Show the browser instead of running it headless');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());

        if (!is_dir($path)) {
            $io->error(\sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        $root = new ProjectRoot($path);
        $directory = DevToolsDirectory::documented($root);
        $config = new XmlConfigReader()->read($directory->configFile());
        $settings = $config->scenarios;
        $baseUrl = $input->getOption('base-url');
        $baseUrl = \is_string($baseUrl) ? $baseUrl : $settings->baseUrl;
        $timeout = $input->getOption('timeout');
        $timeout = \is_string($timeout) ? (int) $timeout : $settings->timeout;
        $viewport = $input->getOption('viewport');
        $viewport = \is_string($viewport) ? $viewport : self::VIEWPORT;
        $asked = $input->getArgument('scenarios');
        $asked = \is_array($asked) ? array_values(array_filter($asked, \is_string(...))) : [];

        if (null === $baseUrl) {
            $io->error('Where is the application served? Give --base-url, or <scenarios base-url="…"/> in .devtools/config.xml.');

            return Command::INVALID;
        }

        $refused = match (true) {
            1 !== preg_match('#^https?://[^/\s]+#', $baseUrl) => \sprintf('"%s" is not a base URL: http://… or https://….', $baseUrl),
            $timeout <= 0 => '--timeout is a positive number of seconds.',
            1 !== preg_match('/^\d+x\d+$/', $viewport) => '--viewport is width x height: 1280x800.',
            default => null,
        };

        if (null !== $refused) {
            $io->error($refused);

            return Command::INVALID;
        }

        [$scenarios, $invalid] = self::scenarios($directory, $asked);

        foreach ($invalid as $file => $error) {
            $io->error(\sprintf('%s: %s', $file, $error));
        }

        if ([] === $scenarios) {
            $io->text([] === $asked ? 'No scenario to run: scenarios:generate writes them.' : \sprintf('No scenario matches %s.', implode(', ', $asked)));

            return [] === $asked && [] === $invalid ? Command::SUCCESS : Command::INVALID;
        }

        $startedAt = $this->clock->now();
        $artifacts = $directory->runDirectory($startedAt);
        $secrets = new Secrets();
        $visual = VisualComparator::forBaselines($directory->baselinesDirectory(), $settings->visual);
        $runner = new PantherRunner(
            self::browser($root, rtrim($baseUrl, '/'), $viewport, true === $input->getOption('headed')),
            RouteMap::fromTracking($directory, new WorkflowTypeRegistry($config->customTypes)),
            new ConfiguredLoginProvider($settings, $secrets, $timeout),
            new ConfiguredFixtureProvider($settings, $root, $secrets),
            $secrets,
            $timeout,
            $visual,
        );

        if (null !== $visual->warning()) {
            $io->warning($visual->warning());
        }

        try {
            $results = $runner->run($scenarios, $artifacts);
        } finally {
            $runner->quit();
        }

        $run = new RunResult($startedAt, $secrets->redact($baseUrl), $results);
        new XmlRunWriter()->write($artifacts.'/result.xml', $run);

        foreach ($results as $result) {
            $io->writeln(self::line($result));
        }

        $io->newLine();
        $io->text(\sprintf('%d passed, %d failed, %d errors — %s', $run->count(Verdict::Passed), $run->count(Verdict::Failed), $run->count(Verdict::Error), $root->relative($artifacts.'/result.xml')));
        $unaccepted = \count(array_filter(array_merge(...array_map(static fn (ScenarioResult $result): array => $result->steps, $results)), static fn (StepResult $step): bool => VisualVerdict::NoBaseline === $step->visual?->verdict));

        if ($unaccepted > 0) {
            $io->text(\sprintf('%d captures have no baseline: look at them, then baselines:accept %s.', $unaccepted, basename($artifacts)));
        }

        return $run->passed() && [] === $invalid ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<string> $asked scenarios or workflows; all when empty
     *
     * @return array{list<Scenario>, array<string, string>} the scenarios, and the files that could not be read
     */
    private static function scenarios(DevToolsDirectory $directory, array $asked): array
    {
        $store = new XmlScenarioStore();
        $scenarios = [];
        $invalid = [];

        foreach (glob($directory->path('scenarios/*/*.xml')) ?: [] as $file) {
            try {
                $scenario = $store->read($file);
            } catch (InvalidXml $error) {
                $invalid[$directory->root->relative($file)] = $error->getMessage();

                continue;
            }

            if ([] === $asked || \in_array($scenario->id, $asked, true) || \in_array($scenario->workflow->value, $asked, true)) {
                $scenarios[] = $scenario;
            }
        }

        return [$scenarios, $invalid];
    }

    /**
     * @return \Closure(): Client
     */
    private static function browser(ProjectRoot $root, string $baseUrl, string $viewport, bool $headed): \Closure
    {
        return static function () use ($root, $baseUrl, $viewport, $headed): Client {
            $arguments = ['--window-size='.str_replace('x', ',', $viewport), '--disable-gpu', '--disable-dev-shm-usage', '--force-prefers-reduced-motion'];

            if (!$headed) {
                $arguments[] = '--headless=new';
            }

            if (filter_var(getenv('PANTHER_NO_SANDBOX'), \FILTER_VALIDATE_BOOLEAN)) {
                $arguments[] = '--no-sandbox';
            }

            // The ChromeDriver of the project — `vendor/bin/bdi detect drivers` puts it there — else Panther's search.
            $driver = $root->path.'/drivers/chromedriver';

            return Client::createChromeClient(is_file($driver) ? $driver : null, $arguments, [], $baseUrl);
        };
    }

    private static function line(ScenarioResult $result): string
    {
        return match ($result->verdict) {
            Verdict::Passed => \sprintf('<info>✓</info> %s', $result->id),
            Verdict::Skipped => \sprintf('<comment>-</comment> %s', $result->id),
            default => \sprintf('<error>✗</error> %s — %s', $result->id, $result->failure() ?? $result->verdict->value),
        };
    }
}
