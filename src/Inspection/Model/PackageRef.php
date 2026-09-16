<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * A dependency the workflow relies on, referenced by package rather than by file (specs § 4.3 step 4):
 * a change of major version is a reason to rewrite, a change inside the package is not tracked.
 */
final readonly class PackageRef
{
    public string $name;

    public string $version;

    public function __construct(string $name, string $version)
    {
        $this->name = NonEmpty::string($name, 'package name');
        $this->version = NonEmpty::string($version, \sprintf('version of package "%s"', $this->name));
    }
}
