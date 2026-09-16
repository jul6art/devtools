<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * The source files no workflow references: the "Non couvert" section of the menu, which makes visible
 * what the analysis did not understand (specs § 4.7).
 *
 * Code only — configuration files are not expected to belong to a workflow unless an adapter says so.
 */
final class CoverageCalculator
{
    public const array CODE_EXTENSIONS = ['go', 'java', 'js', 'jsx', 'kt', 'php', 'py', 'rb', 'rs', 'ts', 'tsx', 'twig', 'vue'];

    /**
     * @param list<Workflow> $workflows
     *
     * @return list<FileRef>
     */
    public static function uncovered(ProjectRoot $root, StackProfile $stack, array $workflows): array
    {
        $covered = [];

        foreach ($workflows as $workflow) {
            foreach ($workflow->files as $file) {
                $covered[$file->path] = true;
            }
        }

        $uncovered = [];

        foreach (SourceFiles::in($root, $stack, $stack->sourceDirs, self::CODE_EXTENSIONS) as $path) {
            $role = RoleGuesser::guess($path);

            // A PHP file under config/ (bundles.php, a generated reference) configures, it is not orphan code.
            if (!isset($covered[$path]) && FileRole::Config !== $role) {
                $uncovered[] = new FileRef($path, $role);
            }
        }

        return $uncovered;
    }
}
