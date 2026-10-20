<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Runner;

use Jul6Art\DevTools\Config\ScenarioSettings;
use Jul6Art\DevTools\Project\ProjectRoot;
use Symfony\Component\Process\Process;

/**
 * The fixtures of `.devtools/config.xml` (ADR-0021): the project's own command, run from its root. A fixture a
 * scenario names and the configuration does not is an error, never silently skipped.
 */
final readonly class ConfiguredFixtureProvider implements FixtureProviderInterface
{
    public function __construct(private ScenarioSettings $settings, private ProjectRoot $root, private Secrets $secrets, private float $timeout = 120.0)
    {
    }

    #[\Override]
    public function load(string $name): void
    {
        $fixture = $this->settings->fixture($name) ?? throw new \RuntimeException(\sprintf('No fixture "%s" in the <scenarios> of .devtools/config.xml.', $name));
        $process = Process::fromShellCommandline($fixture->command, $this->root->path, null, null, $this->timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            $last = array_last(explode("\n", $output)) ?? '';

            throw new \RuntimeException($this->secrets->redact(\sprintf('The fixture "%s" failed (exit %d)%s', $name, $process->getExitCode() ?? -1, '' === $last ? '.' : ': '.$last)));
        }
    }
}
