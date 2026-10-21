<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Impact\Impact;
use Jul6Art\DevTools\Impact\ImpactedWorkflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * What every step of the gate judges: the change, what it reaches, the project's configuration.
 */
final readonly class GateContext
{
    /**
     * @param list<string> $changed the files of the change, relative to the project
     */
    public function __construct(
        public DevToolsDirectory $directory,
        public Config $config,
        public WorkflowTypeRegistry $types,
        public array $changed,
        public Impact $impact,
        public \DateTimeImmutable $at,
        public ?string $baseUrl = null,
        public ?int $timeout = null,
    ) {
    }

    /**
     * The workflows the change reaches, by identifier.
     *
     * @return list<string>
     */
    public function reached(): array
    {
        return array_map(static fn (ImpactedWorkflow $workflow): string => $workflow->id->value, $this->impact->workflows);
    }
}
