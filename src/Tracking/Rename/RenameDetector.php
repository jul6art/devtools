<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking\Rename;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Tracking\TrackedFile;
use Jul6Art\DevTools\Tracking\TrackingDocument;

/**
 * A workflow gone and one new, of the same type, that share at least 80 % of their files: probably one
 * workflow renamed — a route renamed in the code (ADR-0036). Proposed, never applied on this guess alone: a
 * false positive would merge two histories.
 */
final class RenameDetector
{
    public const float THRESHOLD = 0.8;

    /**
     * @param array<string, TrackingDocument> $previous  what the last inspection documented, by identifier
     * @param list<Workflow>                  $workflows what this one found
     *
     * @return list<array{string, string, float}> [old, new, the share of their files in common]
     */
    public function detect(array $previous, array $workflows): array
    {
        $current = [];

        foreach ($workflows as $workflow) {
            $current[$workflow->id->value] = $workflow;
        }

        $renames = [];
        $taken = [];

        foreach (array_diff_key($previous, $current) as $document) {
            $before = array_map(static fn (TrackedFile $file): string => $file->file->path, $document->files);
            $best = null;

            foreach (array_diff_key($current, $previous) as $workflow) {
                if (($taken[$workflow->id->value] ?? false) || $workflow->type->name !== $document->type->name) {
                    continue;
                }

                $after = array_map(static fn (FileRef $file): string => $file->path, $workflow->files);
                // Every workflow tracks its entry file: neither side is empty, and the 1 only guards a hand edit.
                $share = \count(array_intersect($before, $after)) / max(\count($before), \count($after), 1);

                if ($share >= self::THRESHOLD && (null === $best || $share > $best[1])) {
                    $best = [$workflow->id->value, $share];
                }
            }

            if (null !== $best) {
                $taken[$best[0]] = true;
                $renames[] = [$document->id->value, $best[0], $best[1]];
            }
        }

        return $renames;
    }
}
