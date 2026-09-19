<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Bridge\PHPStan;

use Jul6Art\DevTools\Inspection\Diff\ChangeGroup;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * The drift of a project, as PHPStan will print it (ADR-0048): one entry per changed fact.
 *
 * ⚠️ It calls the inspection rather than reading the syntax tree PHPStan already has. A second extractor
 * would be faster and would diverge from the first one at the first detail, silently.
 */
final readonly class WorkflowDrift
{
    public function __construct(private InspectionPipeline $pipeline = new InspectionPipeline())
    {
    }

    /**
     * @return list<array{message: string, tip: string, file: string|null, line: int|null}>
     */
    public function of(string $path): array
    {
        // A project that does not use DevTools never hears about it.
        if (!is_dir($path.'/'.DevToolsDirectory::NAME)) {
            return [];
        }

        $report = $this->pipeline->run(new InspectionOptions($path, dryRun: true, noAi: true));

        if ([] !== $report->errors) {
            return [[
                'message' => 'DevTools could not inspect this project: '.$report->errors[0],
                'tip' => 'Run devtools workflows:check to see it in full.',
                'file' => null,
                'line' => null,
            ]];
        }

        $entries = [];

        foreach ($report->changeGroups() as $group) {
            $entries[] = [
                'message' => self::describe($group),
                'tip' => \sprintf(
                    '%d workflow%s documented this. Accept it with devtools workflows:accept %s, or refuse it.',
                    $group->count(),
                    1 === $group->count() ? '' : 's',
                    escapeshellarg($group->change->target),
                ),
                'file' => $group->change->declaredIn->path ?? self::entryFileOf($report->entryFiles, $group),
                // ⚠️ Line 1 when the fact has none — an attribute of a route, a package. PHPStan prints
                // `file.php:-1` for an error attached to a file without a line, which reads as a bug of
                // the tool rather than as « somewhere in this file ».
                'line' => $group->change->line ?? 1,
            ];
        }

        return $entries;
    }

    private static function describe(ChangeGroup $group): string
    {
        $change = $group->change;

        return trim(\sprintf(
            '%s %s %s%s',
            $change->nature->value,
            $change->subject->value,
            $change->target,
            null === $change->before && null === $change->after ? '' : \sprintf(': %s -> %s', $change->before ?? '—', $change->after ?? '—'),
        ));
    }

    /**
     * A fact with no file of its own — a dependency, a package — is reported on the entry point of the
     * first workflow that carries it: an error PHPStan cannot place is an error nobody reads.
     *
     * @param array<string, string> $entryFiles
     */
    private static function entryFileOf(array $entryFiles, ChangeGroup $group): ?string
    {
        foreach ($group->workflows as $id) {
            if (isset($entryFiles[$id])) {
                return $entryFiles[$id];
            }
        }

        return null;
    }
}
