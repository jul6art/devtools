<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Detector;

use Jul6Art\DevTools\Stack\StackProfile;

/**
 * JavaScript and TypeScript projects, from `package.json` and its lock file. No native adapter in the
 * MVP: they go through the Claude path (ADR-0013).
 */
final class NodeDetector implements StackDetectorInterface
{
    use ReadsManifests;

    /**
     * In priority order: an Angular application may also depend on express for its SSR server.
     */
    private const array FRAMEWORKS = ['@angular/core' => 'angular', 'next' => 'next', 'express' => 'express'];

    #[\Override]
    public function detect(string $directory, string $root, array $excludes): ?StackProfile
    {
        $package = self::json($directory.'/package.json');

        if (null === $package) {
            return null;
        }

        $dependencies = [...self::stringMap($package, 'devDependencies'), ...self::stringMap($package, 'dependencies')];
        [$framework, $dependency] = [null, null];

        foreach (self::FRAMEWORKS as $candidate => $name) {
            if (isset($dependencies[$candidate])) {
                [$framework, $dependency] = [$name, $candidate];

                break;
            }
        }

        $version = null === $dependency ? null : ($this->lockedVersion($directory, $dependency) ?? self::majorMinor($dependencies[$dependency]));
        $language = is_file($directory.'/tsconfig.json') || 'angular' === $framework ? 'typescript' : 'javascript';
        $sources = self::existingDirectories($directory, 'angular' === $framework ? ['src/app'] : ['src']);

        return new StackProfile(
            $root,
            $language,
            $framework,
            $version,
            $this->packageManager($directory),
            [] === $sources ? ['.'] : $sources,
            self::existingDirectories($directory, ['e2e', 'test', 'tests']),
            $excludes,
            'claude',
            null === $framework ? $language : $framework.'-'.self::major($version),
        );
    }

    #[\Override]
    public function projectName(string $directory): ?string
    {
        $name = self::json($directory.'/package.json')['name'] ?? null;

        return \is_string($name) ? $name : null;
    }

    private function lockedVersion(string $directory, string $dependency): ?string
    {
        $lock = self::json($directory.'/package-lock.json') ?? [];
        $packages = \is_array($lock['packages'] ?? null) ? $lock['packages'] : [];
        $entry = $packages['node_modules/'.$dependency] ?? null;

        return \is_array($entry) && \is_string($entry['version'] ?? null) ? self::majorMinor($entry['version']) : null;
    }

    private function packageManager(string $directory): string
    {
        return match (true) {
            is_file($directory.'/pnpm-lock.yaml') => 'pnpm',
            is_file($directory.'/yarn.lock') => 'yarn',
            default => 'npm',
        };
    }
}
