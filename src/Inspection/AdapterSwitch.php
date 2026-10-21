<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterInterface;
use Jul6Art\DevTools\Inspection\Adapter\Claude\Discovery;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Stack\StackDocument;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * A stack Claude documented, that a native adapter of this version now reads (ADR-0030): its identifiers may
 * differ, so the switch is never silent. The differences are listed and the stack stays on the Claude path
 * until `--switch-adapter` asks for the switch.
 */
final readonly class AdapterSwitch
{
    public function __construct(private AdapterResolver $adapters)
    {
    }

    /**
     * The stacks to inspect — each one held on the Claude path unless the switch is asked — and what the
     * report says about each switch, held or made.
     *
     * @return array{StackDocument, list<string>}
     */
    public function apply(DevToolsDirectory $directory, StackDocument $detected, ?StackDocument $previous, Config $config, bool $switch, bool $dryRun): array
    {
        $stacks = [];
        $notes = [];

        foreach ($detected->stacks as $stack) {
            $before = $previous?->stackAt($stack->root);
            $discovery = Discovery::file($directory, Discovery::slug($stack));
            $native = $this->adapters->for($stack->adapter);

            if (!$before instanceof StackProfile || 'claude' !== $before->adapter || 'claude' === $stack->adapter || !$native instanceof AdapterInterface || !is_file($discovery)) {
                $stacks[] = $stack;

                continue;
            }

            [$kept, $gone, $new] = $this->differences($directory, $stack, $before, $native, $config);
            $label = $stack->framework ?? $stack->language;
            $summary = \sprintf('%d identifiers stay, %d go%s, %d come%s', \count($kept), \count($gone), self::listed($gone), \count($new), self::listed($new));

            if ($switch) {
                if (!$dryRun) {
                    unlink($discovery);
                }

                $notes[] = \sprintf('%s (%s) switched from the Claude path to the native %s adapter: %s. The pages of the identifiers that went are orphaned (--prune deletes them).', $label, $stack->root, $stack->adapter, $summary);
                $stacks[] = $stack;

                continue;
            }

            $notes[] = \sprintf('%s (%s) is documented through Claude, and this version reads it natively (adapter %s): %s. Inspect with --switch-adapter to switch.', $label, $stack->root, $stack->adapter, $summary);
            $stacks[] = new StackProfile($stack->root, $stack->language, $stack->framework, $stack->version, $stack->packageManager, $stack->sourceDirs, $stack->testDirs, $stack->excludedDirs, 'claude', $stack->knowledgeKey, $stack->locked);
        }

        return [new StackDocument($detected->projectName, $stacks, $detected->projectNameLocked), $notes];
    }

    /**
     * @return array{list<string>, list<string>, list<string>} identifiers kept, gone, new
     */
    private function differences(DevToolsDirectory $directory, StackProfile $stack, StackProfile $before, AdapterInterface $native, Config $config): array
    {
        $claude = $this->adapters->for('claude');
        $extractor = new PhpReferenceExtractor();
        $found = $native->extract($directory->root, $stack, $config, $extractor);
        $nativeIds = self::ids($found->workflows ?? new WorkflowBuilder($config)->build($directory->root, $stack, $found->candidates, $found->templateDirectories, $extractor, $found->mechanisms, $found->graph, $found->tests)->result->workflows);
        $claudeIds = $claude instanceof AdapterInterface ? self::ids($claude->extract($directory->root, $before, $config, $extractor)->workflows ?? []) : [];
        $extractor->release();

        return [
            array_values(array_intersect($claudeIds, $nativeIds)),
            array_values(array_diff($claudeIds, $nativeIds)),
            array_values(array_diff($nativeIds, $claudeIds)),
        ];
    }

    /**
     * @param list<Workflow> $workflows
     *
     * @return list<string>
     */
    private static function ids(array $workflows): array
    {
        $ids = array_map(static fn (Workflow $workflow): string => $workflow->id->value, $workflows);
        sort($ids, \SORT_STRING);

        return $ids;
    }

    /**
     * @param list<string> $ids
     */
    private static function listed(array $ids): string
    {
        return [] === $ids ? '' : ' ('.implode(', ', $ids).')';
    }
}
