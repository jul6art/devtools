<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Diff;

use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Tracking\TrackingDocument;

/**
 * Compares the tracking file of a workflow with the model the inspection just rebuilt, and says what
 * changed — one typed object per fact (ADR-0046).
 *
 * The freshness of ADR-0010 answers with a verb (`rewrite`, `keep`); this answers with facts. It reads
 * nothing from disk: both sides are already in memory when the pipeline decides what to rewrite.
 */
final readonly class WorkflowDiffer
{
    /**
     * @return list<WorkflowChange> in a stable order: entry points, attributes, decisions, mechanisms,
     *                              dependencies, tests, packages — additions before removals within each
     */
    public function between(TrackingDocument $before, Workflow $after): array
    {
        return [
            ...self::entryPoints($before, $after),
            ...self::attributes($before, $after),
            ...self::decisions($before->decisions, $after->decisions),
            ...self::mechanisms($before->mechanisms, $after->mechanisms),
            ...self::identifiers(ChangeSubject::Dependency, array_map(static fn (WorkflowId $id): string => $id->value, $before->dependsOn), array_map(static fn (WorkflowId $id): string => $id->value, $after->dependsOn)),
            ...self::identifiers(ChangeSubject::Test, array_map(static fn (FileRef $file): string => $file->path, $before->tests), array_map(static fn (FileRef $file): string => $file->path, $after->tests)),
            ...self::packages($before->packages, $after->packages),
        ];
    }

    /**
     * @return list<WorkflowChange>
     */
    private static function entryPoints(TrackingDocument $before, Workflow $after): array
    {
        $old = self::byKey([$before->main, ...$before->satellites]);
        $new = self::byKey([$after->main, ...$after->satellites]);
        $changes = [];

        foreach ($new as $key => $entryPoint) {
            if (!isset($old[$key])) {
                $changes[] = new WorkflowChange(ChangeNature::Added, ChangeSubject::EntryPoint, $key, declaredIn: $entryPoint->declaredIn);
            }
        }

        foreach ($old as $key => $entryPoint) {
            if (!isset($new[$key])) {
                $changes[] = new WorkflowChange(ChangeNature::Removed, ChangeSubject::EntryPoint, $key, declaredIn: $entryPoint->declaredIn);
            }
        }

        return $changes;
    }

    /**
     * The attributes of the entry points both sides share: a route that loses its `security` is the
     * change this whole module exists for.
     *
     * @return list<WorkflowChange>
     */
    private static function attributes(TrackingDocument $before, Workflow $after): array
    {
        $old = self::byKey([$before->main, ...$before->satellites]);
        $changes = [];

        foreach (self::byKey([$after->main, ...$after->satellites]) as $key => $entryPoint) {
            $previous = $old[$key] ?? null;

            if (!$previous instanceof EntryPoint) {
                continue;
            }

            foreach ($entryPoint->attributes as $name => $value) {
                $was = $previous->attributes[$name] ?? null;

                if ($was === $value) {
                    continue;
                }

                $changes[] = new WorkflowChange(
                    null === $was ? ChangeNature::Added : ChangeNature::Modified,
                    ChangeSubject::Attribute,
                    $entryPoint->name.'#'.$name,
                    $was,
                    $value,
                    $entryPoint->declaredIn,
                );
            }

            foreach ($previous->attributes as $name => $value) {
                if (!isset($entryPoint->attributes[$name])) {
                    $changes[] = new WorkflowChange(ChangeNature::Removed, ChangeSubject::Attribute, $entryPoint->name.'#'.$name, $value, null, $entryPoint->declaredIn);
                }
            }
        }

        return $changes;
    }

    /**
     * Decisions are compared per target, so that a rewritten condition reads as one modification rather
     * than a removal and an addition — the reader wants the two versions side by side.
     *
     * @param list<DecisionPoint> $before
     * @param list<DecisionPoint> $after
     *
     * @return list<WorkflowChange>
     */
    private static function decisions(array $before, array $after, string $prefix = ''): array
    {
        $old = self::byTarget($before);
        $new = self::byTarget($after);
        $changes = [];

        foreach ($new as $target => $decisions) {
            $changes = [...$changes, ...self::decisionsOf($prefix.$target, $old[$target] ?? [], $decisions)];
        }

        foreach ($old as $target => $decisions) {
            if (isset($new[$target])) {
                continue;
            }

            foreach ($decisions as $decision) {
                $changes[] = new WorkflowChange(ChangeNature::Removed, ChangeSubject::Decision, $prefix.$target, $decision->value, null, $decision->declaredIn, $decision->line);
            }
        }

        return $changes;
    }

    /**
     * @param list<DecisionPoint> $before
     * @param list<DecisionPoint> $after
     *
     * @return list<WorkflowChange>
     */
    private static function decisionsOf(string $target, array $before, array $after): array
    {
        // What both sides say identically is not a change — and the line is not part of "identically".
        foreach ($after as $index => $decision) {
            foreach ($before as $position => $previous) {
                if ($previous->value === $decision->value && $previous->condition === $decision->condition) {
                    unset($after[$index], $before[$position]);

                    break;
                }
            }
        }

        $changes = [];

        foreach ($after as $decision) {
            // A branch that kept its value changed its condition, and the other way round: both are one
            // modification. Anything else is a branch that appeared.
            foreach ([['value', 'condition'], ['condition', 'value']] as [$same, $changed]) {
                foreach ($before as $position => $previous) {
                    if ($previous->{$same} !== $decision->{$same}) {
                        continue;
                    }

                    $changes[] = new WorkflowChange(ChangeNature::Modified, ChangeSubject::Decision, $target, $previous->{$changed}, $decision->{$changed}, $decision->declaredIn, $decision->line, $changed);
                    unset($before[$position]);

                    continue 3;
                }
            }

            $changes[] = new WorkflowChange(ChangeNature::Added, ChangeSubject::Decision, $target, null, $decision->value, $decision->declaredIn, $decision->line);
        }

        foreach ($before as $previous) {
            $changes[] = new WorkflowChange(ChangeNature::Removed, ChangeSubject::Decision, $target, $previous->value, null, $previous->declaredIn, $previous->line);
        }

        return $changes;
    }

    /**
     * @param list<Mechanism> $before
     * @param list<Mechanism> $after
     *
     * @return list<WorkflowChange>
     */
    private static function mechanisms(array $before, array $after): array
    {
        $old = [];

        foreach ($before as $mechanism) {
            $old[self::mechanismKey($mechanism)] = $mechanism;
        }

        $changes = [];
        $seen = [];

        foreach ($after as $mechanism) {
            $key = self::mechanismKey($mechanism);
            $seen[$key] = true;
            $previous = $old[$key] ?? null;

            if (!$previous instanceof Mechanism) {
                $changes[] = new WorkflowChange(ChangeNature::Added, ChangeSubject::Mechanism, $key, declaredIn: $mechanism->declaredIn);

                continue;
            }

            if ($previous->priority !== $mechanism->priority) {
                $changes[] = new WorkflowChange(
                    ChangeNature::Modified,
                    ChangeSubject::Mechanism,
                    $key,
                    null === $previous->priority ? null : (string) $previous->priority,
                    null === $mechanism->priority ? null : (string) $mechanism->priority,
                    $mechanism->declaredIn,
                    aspect: 'priority',
                );
            }

            // What a listener writes belongs to the workflows it runs in, under its own name.
            $changes = [...$changes, ...self::decisions($previous->decisions, $mechanism->decisions, self::shortName($mechanism->name).' → ')];
        }

        foreach ($old as $key => $mechanism) {
            if (!isset($seen[$key])) {
                $changes[] = new WorkflowChange(ChangeNature::Removed, ChangeSubject::Mechanism, $key, declaredIn: $mechanism->declaredIn);
            }
        }

        return $changes;
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     *
     * @return list<WorkflowChange>
     */
    private static function identifiers(ChangeSubject $subject, array $before, array $after): array
    {
        $changes = [];

        foreach (array_diff($after, $before) as $added) {
            $changes[] = new WorkflowChange(ChangeNature::Added, $subject, $added);
        }

        foreach (array_diff($before, $after) as $removed) {
            $changes[] = new WorkflowChange(ChangeNature::Removed, $subject, $removed);
        }

        return $changes;
    }

    /**
     * @param list<PackageRef> $before
     * @param list<PackageRef> $after
     *
     * @return list<WorkflowChange>
     */
    private static function packages(array $before, array $after): array
    {
        $old = [];

        foreach ($before as $package) {
            $old[$package->name] = $package->version;
        }

        $changes = [];
        $seen = [];

        foreach ($after as $package) {
            $seen[$package->name] = true;
            $was = $old[$package->name] ?? null;

            if ($was === $package->version) {
                continue;
            }

            $changes[] = new WorkflowChange(
                null === $was ? ChangeNature::Added : ChangeNature::Modified,
                ChangeSubject::Package,
                $package->name,
                $was,
                $package->version,
            );
        }

        foreach ($old as $name => $version) {
            if (!isset($seen[$name])) {
                $changes[] = new WorkflowChange(ChangeNature::Removed, ChangeSubject::Package, $name, $version);
            }
        }

        return $changes;
    }

    /**
     * @param list<EntryPoint> $entryPoints
     *
     * @return array<string, EntryPoint>
     */
    private static function byKey(array $entryPoints): array
    {
        $byKey = [];

        foreach ($entryPoints as $entryPoint) {
            $byKey[$entryPoint->kind.':'.$entryPoint->name] = $entryPoint;
        }

        return $byKey;
    }

    /**
     * @param list<DecisionPoint> $decisions
     *
     * @return array<string, list<DecisionPoint>>
     */
    private static function byTarget(array $decisions): array
    {
        $byTarget = [];

        foreach ($decisions as $decision) {
            $byTarget[$decision->target][] = $decision;
        }

        return $byTarget;
    }

    private static function mechanismKey(Mechanism $mechanism): string
    {
        return \sprintf('%s:%s@%s', $mechanism->kind, $mechanism->name, $mechanism->event);
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }
}
