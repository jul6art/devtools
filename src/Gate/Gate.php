<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Impact\ImpactResolver;
use Jul6Art\DevTools\Impact\ImpactUnavailable;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Verification\VerificationPreparer;

/**
 * The gate (ADR-0023): Claude cannot call a task finished while it fails. Every step runs on what the change
 * reaches — never on the whole project's scenarios — and all of them run, whatever the first found, so that one
 * passage says everything there is to fix.
 */
final readonly class Gate
{
    /**
     * After this many identical failures in a row, the Stop hook lets Claude end, and asks it to tell the human.
     */
    public const int LIMIT = 3;

    /**
     * DevTools' own work areas, and the journal the Stop hook writes when the gate passes (ADR-0029): never a change
     * to judge — or a delivered task would start the next one.
     */
    private const string OWN = '#^\.devtools/(gate|runs|pending|reports|stats|journal)/#';

    /**
     * @var list<GateStepInterface>
     */
    private array $steps;

    /**
     * @param list<GateStepInterface>|null $steps null: freshness, scenarios, verification, assertions
     */
    public function __construct(?array $steps = null, private Clock $clock = new SystemClock(), private XmlGateStore $store = new XmlGateStore(), private GitClient $git = new GitClient())
    {
        $this->steps = $steps ?? [new FreshnessStep(), new ScenariosStep(), new VerificationStep(), new AssertionsStep()];
    }

    /**
     * The files a change is judged on: relative to the project, sorted, without DevTools' own work areas.
     *
     * @param list<string> $changed
     *
     * @return list<string>
     *
     * @throws PathOutsideProject when a file lies outside the project
     */
    public static function changes(DevToolsDirectory $directory, array $changed): array
    {
        $root = $directory->root;
        $changed = array_values(array_filter(array_unique(array_map(static fn (string $file): string => $root->relative($root->absolute($file)), $changed)), static fn (string $file): bool => 1 !== preg_match(self::OWN, $file)));
        sort($changed, \SORT_STRING);

        return $changed;
    }

    /**
     * @param list<string> $changed the files of the change
     *
     * @throws ImpactUnavailable  when the project was never inspected
     * @throws PathOutsideProject when a file lies outside the project
     */
    public function run(DevToolsDirectory $directory, array $changed, bool $strict = false, ?string $baseUrl = null, ?int $timeout = null): GateResult
    {
        $root = $directory->root;
        $changed = self::changes($directory, $changed);
        $config = new XmlConfigReader()->read($directory->configFile());
        $types = new WorkflowTypeRegistry($config->customTypes);
        $context = new GateContext($directory, $config, $types, $changed, new ImpactResolver($types)->resolve($directory, $changed), $this->clock->now(), $baseUrl ?? $config->scenarios->baseUrl, $timeout);
        $steps = [];

        foreach ($this->steps as $step) {
            $started = hrtime(true);

            try {
                $result = $step->run($context);
            } catch (\Throwable $error) {
                $result = new GateStep($step->name(), [\sprintf('The step could not run: %s', $error->getMessage())]);
            }

            $result = $result->timed((int) round((hrtime(true) - $started) / 1e6));
            $steps[] = $strict ? $result->strict() : $result;
        }

        $file = $directory->path('gate/last.xml');
        $previous = $this->store->read($file);
        $result = new GateResult($context->at, $changed, $steps, VerificationPreparer::fingerprint($root, $changed));
        $consecutive = match (true) {
            $result->passed() => 0,
            $previous instanceof GateResult && !$previous->passed() && $previous->failure() === $result->failure() => $previous->consecutive + 1,
            default => 1,
        };
        // A passage after a green one starts a task; the next green one delivers it (ADR-0029).
        $task = $previous instanceof GateResult && !$previous->passed() && $previous->task instanceof GateTask ? $previous->task : new GateTask($context->at, $this->git->state($root)?->commit);
        $result = new GateResult($result->at, $result->changed, $result->steps, $result->changes, $consecutive, $task->after($steps, $result->passed()));
        $this->store->write($file, $result);

        return $result;
    }
}
