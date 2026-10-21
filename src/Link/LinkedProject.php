<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

use Jul6Art\DevTools\Config\InvalidConfig;

/**
 * A neighbour `.devtools/config.xml` declares (ADR-0038): `<project name="mobile" path="../mobile" role="client"/>`.
 *
 * The path is relative to the project, so that two clones side by side link wherever they are; the base URL
 * is what a client's calls start with when the code does not say — an `environment.apiUrl` read from a
 * file the analysis cannot resolve.
 */
final readonly class LinkedProject
{
    public function __construct(
        public string $name,
        public string $path,
        public LinkRole $role,
        public ?string $baseUrl = null,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            throw new InvalidConfig(\sprintf('"%s" is not a project name: lowercase letters, digits and dashes.', $name));
        }

        if ('' === $path || str_starts_with($path, '/') || 1 === preg_match('/^[A-Za-z]:/', $path)) {
            throw new InvalidConfig(\sprintf('The path of the linked project "%s" must be relative to this one, such as "../%s".', $name, $name));
        }
    }
}
