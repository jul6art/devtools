<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\Detector\ComposerDetector;
use Jul6Art\DevTools\Stack\Detector\LanguageOnlyDetector;
use Jul6Art\DevTools\Stack\Detector\NodeDetector;
use Jul6Art\DevTools\Stack\Detector\StackDetectorInterface;

/**
 * Answers "what is this project?" from its manifests (specs § 4.3 step 1, ADR-0005).
 *
 * The project root and its immediate subdirectories are read — one directory listing, never a walk of
 * the tree — which is what a monorepo (`api/`, `front/`) needs.
 */
final readonly class StackDetector
{
    /**
     * @var list<StackDetectorInterface>
     */
    private array $detectors;

    /**
     * @param list<StackDetectorInterface>|null $detectors in priority order
     */
    public function __construct(?array $detectors = null)
    {
        $this->detectors = $detectors ?? [new ComposerDetector(), new NodeDetector(), ...LanguageOnlyDetector::all()];
    }

    public function detect(ProjectRoot $project, Config $config, ?StackDocument $previous = null): StackDocument
    {
        $excludes = $config->excludes();
        $stacks = $this->stacksIn($project->path, '.', $excludes);
        $rootHasStack = [] !== $stacks;

        foreach ($this->subdirectories($project->path, $excludes) as $subdirectory) {
            foreach ($this->stacksIn($project->path.'/'.$subdirectory, $subdirectory, $excludes) as $stack) {
                // Next to a stack at the root, a subdirectory only counts when it holds a framework of its own.
                if (!$rootHasStack || null !== $stack->framework) {
                    $stacks[] = $stack;
                }
            }
        }

        if ([] === $stacks) {
            $stacks[] = new StackProfile('.', 'unknown', null, null, 'none', ['.'], [], $excludes, 'claude', null);
        }

        if ($previous instanceof StackDocument) {
            $stacks = array_map(
                static fn (StackProfile $stack): StackProfile => ($locked = $previous->stackAt($stack->root)) instanceof StackProfile ? $stack->withLocksFrom($locked) : $stack,
                $stacks,
            );
        }

        if ($previous instanceof StackDocument && $previous->projectNameLocked) {
            return new StackDocument($previous->projectName, $stacks, true);
        }

        return new StackDocument($this->projectName($project->path), $stacks);
    }

    /**
     * @param list<string> $excludes
     *
     * @return list<StackProfile>
     */
    private function stacksIn(string $directory, string $root, array $excludes): array
    {
        $found = array_values(array_filter(array_map(
            static fn (StackDetectorInterface $detector): ?StackProfile => $detector->detect($directory, $root, $excludes),
            $this->detectors,
        )));

        // A framework says what the directory is; a frameworkless manifest next to it is tooling
        // (the package.json of Webpack Encore beside a Symfony application).
        $withFramework = array_values(array_filter($found, static fn (StackProfile $stack): bool => null !== $stack->framework));

        return [] !== $withFramework ? $withFramework : \array_slice($found, 0, 1);
    }

    /**
     * @param list<string> $excludes
     *
     * @return list<string>
     */
    private function subdirectories(string $directory, array $excludes): array
    {
        $subdirectories = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if (!str_starts_with($entry, '.') && !\in_array($entry, $excludes, true) && is_dir($directory.'/'.$entry)) {
                $subdirectories[] = $entry;
            }
        }

        return $subdirectories;
    }

    private function projectName(string $directory): string
    {
        foreach ($this->detectors as $detector) {
            $name = $detector->projectName($directory);

            if (null !== $name) {
                return $name;
            }
        }

        return basename($directory);
    }
}
