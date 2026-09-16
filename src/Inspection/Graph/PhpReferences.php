<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

/**
 * What a PHP file refers to, as written: resolving a class to a file or a package is the locator's job.
 */
final readonly class PhpReferences
{
    /**
     * @param list<string> $classes   fully qualified, sorted
     * @param list<string> $templates literal template names, sorted
     * @param list<string> $routes    literal route names given to redirectToRoute() or generateUrl(), sorted
     * @param list<string> $includes  absolute paths of the files required or included literally, sorted
     */
    public function __construct(
        public array $classes = [],
        public array $templates = [],
        public ?string $warning = null,
        public array $routes = [],
        public array $includes = [],
    ) {
    }
}
