<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Claude\Hook;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Impact\Impact;
use Jul6Art\DevTools\Impact\ImpactedWorkflow;
use Jul6Art\DevTools\Impact\ImpactRelation;
use Jul6Art\DevTools\Impact\ImpactResolver;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Rule\RuleIndexEntry;
use Jul6Art\DevTools\Rule\RuleRepository;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\FileRelation;
use Jul6Art\DevTools\Tracking\XmlFilesToWorkflowsStore;
use Jul6Art\DevTools\Xml\InvalidXml;

/**
 * Before Claude modifies a file, what the change will reach (ADR-0019): the workflows, their pages, their
 * tests — and the active rules that apply to it (ADR-0026) — added to its context, never in its way.
 *
 * Silent on a tool that writes no file, on a file outside the project, and when nothing is reached and no rule
 * applies; one line on a file no workflow references.
 */
final readonly class PreToolUseHandler implements HookHandlerInterface
{
    /**
     * The tools that write a file: their `tool_input.file_path` is the file about to change.
     */
    public const array TOOLS = ['Edit', 'Write', 'MultiEdit'];

    /**
     * Past this, the list is cut and the full answer is one command away.
     */
    private const int LISTED = 20;

    /**
     * The tests named per workflow: enough to know where to look, not an inventory.
     */
    private const int TESTS = 3;

    public function __construct(private ImpactResolver $resolver = new ImpactResolver(), private Clock $clock = new SystemClock())
    {
    }

    public function event(): string
    {
        return 'PreToolUse';
    }

    public function handle(HookInput $input, DevToolsDirectory $directory): ?array
    {
        if (!\in_array($input->toolName, self::TOOLS, true) || null === $input->filePath) {
            return null;
        }

        try {
            $file = $directory->root->relative($directory->root->absolute($input->filePath));
        } catch (PathOutsideProject) {
            return null;
        }

        $impact = $this->resolver->resolve($directory, [$file]);
        $lines = [...$this->impact($directory, $file, $impact), ...$this->rules($directory, $file, $impact)];

        return [] === $lines ? null : self::answer(implode("\n", $lines));
    }

    /**
     * @return list<string>
     */
    private function impact(DevToolsDirectory $directory, string $file, Impact $impact): array
    {
        if ([] !== $impact->uncovered) {
            return [\sprintf('DevTools: no documented workflow references %s yet — the next workflows:inspect will tell where it belongs.', $file)];
        }

        if ([] === $impact->workflows) {
            return [];
        }

        $tests = self::tests($directory, $impact->workflows);
        $lines = [\sprintf('DevTools: %s is part of %d documented workflow%s — read the page before changing what it does:', $file, \count($impact->workflows), 1 === \count($impact->workflows) ? '' : 's')];

        foreach (\array_slice($impact->workflows, 0, self::LISTED) as $workflow) {
            $lines[] = \sprintf(
                '- %s (%s): %s%s%s',
                $workflow->id->value,
                self::how($workflow->relation),
                $workflow->page,
                'fresh' === $workflow->status->value ? '' : ' · '.$workflow->status->value,
                self::tested($tests[$workflow->id->value] ?? []),
            );
        }

        if (\count($impact->workflows) > self::LISTED) {
            $lines[] = \sprintf('- and %d more: devtools workflows:impact %s', \count($impact->workflows) - self::LISTED, $file);
        }

        if (null !== $impact->warning()) {
            $lines[] = '⚠ '.$impact->warning();
        }

        return $lines;
    }

    /**
     * The active rules that apply to the file or to the kind of a workflow it is part of (ADR-0026): their
     * identifier and their instruction, nothing else — and each counted as given.
     *
     * @return list<string>
     */
    private function rules(DevToolsDirectory $directory, string $file, Impact $impact): array
    {
        $repository = new RuleRepository($directory);

        try {
            $rules = $repository->index()->applicableTo($file, array_values(array_unique(array_map(static fn (ImpactedWorkflow $workflow): string => $workflow->type->name, $impact->workflows))));
        } catch (InvalidXml $invalid) {
            return ['⚠ DevTools cannot read the rules: '.$invalid->getMessage()];
        }

        if ([] === $rules) {
            return [];
        }

        $ids = array_map(static fn (RuleIndexEntry $rule): string => $rule->id, $rules);
        $repository->saveStats($repository->stats()->applied($ids, $this->clock->now()));

        return [
            \sprintf('DevTools: the rules of this project for %s — follow them:', $file),
            ...array_map(static fn (RuleIndexEntry $rule): string => \sprintf('- %s: %s', $rule->id, $rule->instruction), $rules),
        ];
    }

    /**
     * @param list<string> $tests
     */
    private static function tested(array $tests): string
    {
        if ([] === $tests) {
            return '';
        }

        $more = \count($tests) - self::TESTS;

        return ' · tests: '.implode(', ', \array_slice($tests, 0, self::TESTS)).($more > 0 ? \sprintf(' (+%d)', $more) : '');
    }

    private static function how(ImpactRelation $relation): string
    {
        return match ($relation) {
            ImpactRelation::Direct => 'traverses it',
            ImpactRelation::Test => 'tested by it',
            ImpactRelation::Dependency => 'runs it inside',
        };
    }

    /**
     * The tests of each workflow, from the same map: no reading of the tracking files.
     *
     * @param list<ImpactedWorkflow> $workflows
     *
     * @return array<string, list<string>>
     */
    private static function tests(DevToolsDirectory $directory, array $workflows): array
    {
        $wanted = array_flip(array_map(static fn (ImpactedWorkflow $workflow): string => $workflow->id->value, $workflows));
        $tests = [];

        foreach (new XmlFilesToWorkflowsStore()->read($directory->filesToWorkflowsFile())->links as $path => $links) {
            foreach ($links as $link) {
                if (FileRelation::Test === $link->relation && isset($wanted[$link->workflow->value])) {
                    $tests[$link->workflow->value][] = $path;
                }
            }
        }

        return $tests;
    }

    /**
     * @return array{hookSpecificOutput: array{hookEventName: string, additionalContext: string}}
     */
    private static function answer(string $context): array
    {
        return ['hookSpecificOutput' => ['hookEventName' => 'PreToolUse', 'additionalContext' => $context]];
    }
}
