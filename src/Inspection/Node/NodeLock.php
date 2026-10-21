<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Node;

/**
 * The versions a JavaScript project locked: `package-lock.json`, or `yarn.lock` — what a package reference
 * records, so that a major version moving rewrites the workflows using it (ADR-0010).
 */
final readonly class NodeLock
{
    /**
     * @param array<string, string> $versions package => version
     */
    private function __construct(private array $versions)
    {
    }

    public static function in(string $directory): self
    {
        $versions = [];
        $npm = is_file($directory.'/package-lock.json') ? json_decode((string) file_get_contents($directory.'/package-lock.json'), true) : null;

        if (\is_array($npm) && \is_array($npm['packages'] ?? null)) {
            foreach ($npm['packages'] as $path => $package) {
                // Only the top-level install: `node_modules/a/node_modules/b` is a's own copy of b.
                if (\is_string($path) && 1 === preg_match('#^node_modules/((?:@[^/]+/)?[^/]+)$#', $path, $name) && \is_array($package) && \is_string($package['version'] ?? null)) {
                    $versions[$name[1]] = $package['version'];
                }
            }
        }

        if (is_file($directory.'/yarn.lock')) {
            // `"@angular/core@^22.1.0":` then `  version "22.1.3"`, in yarn 1 and in berry alike.
            preg_match_all('/^"?((?:@[^@\s"\/]+\/)?[^@\s"\/]+)@[^\n]*\n(?:\s+[^\n]*\n)*?\s+version:? "?([^"\s]+)"?/m', (string) file_get_contents($directory.'/yarn.lock'), $entries, \PREG_SET_ORDER);

            foreach ($entries as [, $name, $version]) {
                $versions[$name] ??= $version;
            }
        }

        return new self($versions);
    }

    public function version(string $package): ?string
    {
        return $this->versions[$package] ?? null;
    }
}
