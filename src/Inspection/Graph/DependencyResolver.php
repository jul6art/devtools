<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * The files a PHP entry point traverses, breadth first, up to a depth (specs § 4.3 step 4, ADR-0006).
 *
 * Depth counts hops: controller (0) → service (1) → repository (2) → entity (3). Beyond the configured
 * depth a file is not followed — a shared logger would otherwise tie every workflow to every other — but
 * the structural files an adapter declares are always included, which is what guards against a false
 * "unchanged" when only configuration moved (§ 11).
 */
final readonly class DependencyResolver
{
    /**
     * @param list<string> $templateDirectories relative to the stack root, searched in order
     */
    public function __construct(
        private ProjectRoot $root,
        private StackProfile $stack,
        private ClassLocator $locator,
        private PhpReferenceExtractor $php,
        private TwigReferenceExtractor $twig,
        private int $maxDepth,
        private array $templateDirectories,
    ) {
    }

    /**
     * @param list<string>  $methods    the entry point's methods; empty for the whole file
     * @param list<FileRef> $structural always part of the workflow
     */
    public function resolve(FileRef $entryFile, array $methods = [], array $structural = []): ResolvedDependencies
    {
        // Breadth first, one level at a time: the level's depth is the loop variable, so nothing has to
        // remember the depth of each file. (A single queue whose length is re-read at each turn is what
        // Rector's ForRepeatedCountToOwnVariableRector silently breaks.)
        $files = [$entryFile->path => $entryFile];
        $direct = $files;
        $level = [$entryFile];
        $packages = [];
        $warnings = [];

        for ($depth = 0; $depth < $this->maxDepth && [] !== $level; ++$depth) {
            $nextLevel = [];

            foreach ($level as $file) {
                foreach ($this->neighbours($file, 0 === $depth ? $methods : [], $warnings) as $neighbour) {
                    if ($neighbour instanceof PackageRef) {
                        $packages[$neighbour->name] = $neighbour;
                    } elseif (!isset($files[$neighbour->path])) {
                        $files[$neighbour->path] = $neighbour;
                        $nextLevel[] = $neighbour;
                    }
                }
            }

            if (0 === $depth) {
                foreach ($nextLevel as $neighbour) {
                    $direct[$neighbour->path] = $neighbour;
                }
            }

            $level = $nextLevel;
        }

        foreach ($structural as $file) {
            $files[$file->path] ??= $file;
        }

        ksort($files, \SORT_STRING);
        ksort($direct, \SORT_STRING);
        ksort($packages, \SORT_STRING);

        return new ResolvedDependencies(
            array_values($files),
            array_values($direct),
            array_values($packages),
            array_values(array_unique($warnings)),
        );
    }

    /**
     * @param list<string> $methods
     * @param list<string> $warnings
     *
     * @return list<FileRef|PackageRef>
     */
    private function neighbours(FileRef $file, array $methods, array &$warnings): array
    {
        $absolute = $this->root->absolute($file);

        if (!is_file($absolute)) {
            return [];
        }

        if (FileRole::Template === $file->role && str_ends_with($file->path, '.twig')) {
            return array_values(array_filter(array_map($this->template(...), $this->twig->extract($absolute)->templates)));
        }

        if (!PhpReferenceExtractor::isPhpFile($absolute)) {
            return [];
        }

        $references = $this->php->extract($absolute, $methods);

        if (null !== $references->warning) {
            $warnings[] = str_replace($absolute, $file->path, $references->warning);
        }

        return [
            ...array_values(array_filter(array_map($this->locator->locate(...), $references->classes))),
            ...array_values(array_filter(array_map($this->template(...), $references->templates))),
            ...array_values(array_filter(array_map($this->included(...), $references->includes))),
        ];
    }

    private function included(string $absolute): ?FileRef
    {
        try {
            $path = $this->root->relative($absolute);
        } catch (PathOutsideProject) {
            return null;
        }

        return is_file($this->root->absolute($path)) ? new FileRef($path, RoleGuesser::guess($path)) : null;
    }

    private function template(string $name): ?FileRef
    {
        if (str_starts_with($name, '@')) {
            return null;
        }

        foreach ($this->templateDirectories as $directory) {
            $path = $this->stack->projectPath($directory).'/'.ltrim($name, '/');

            if (is_file($this->root->absolute($path))) {
                return new FileRef($path, FileRole::Template);
            }
        }

        return null;
    }
}
