<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Claude\Hook;

use Jul6Art\DevTools\Impact\ImpactedWorkflow;
use Jul6Art\DevTools\Impact\ImpactRelation;
use Jul6Art\DevTools\Impact\ImpactResolver;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\FileRelation;
use Jul6Art\DevTools\Tracking\XmlFilesToWorkflowsStore;

/**
 * Before Claude modifies a file, what the change will reach (ADR-0019): the workflows, their pages, their
 * tests — added to its context, never in its way.
 *
 * Silent on a tool that writes no file, on a file outside the project, and when nothing is reached; one
 * line on a file no workflow references.
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

    public function __construct(private ImpactResolver $resolver = new ImpactResolver())
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

        if ([] !== $impact->uncovered) {
            return self::answer(\sprintf('DevTools: no documented workflow references %s yet — the next workflows:inspect will tell where it belongs.', $file));
        }

        if ([] === $impact->workflows) {
            return null;
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

        return self::answer(implode("\n", $lines));
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
