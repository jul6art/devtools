<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Detector;

use Jul6Art\DevTools\Stack\StackProfile;

/**
 * JavaScript and TypeScript projects, from `package.json` and its lock file. No native adapter in the
 * MVP: they go through the Claude path (ADR-0013).
 *
 * A package without a framework is an application — framework `node` — when it has an entry point
 * AND runtime dependencies (ADR-0050); otherwise it is tooling, such as the `package.json` of Webpack
 * Encore next to a Symfony application.
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
        $entryPoints = [];

        if (null === $framework && self::isApplication($package, $entryPoints)) {
            [$framework, $version] = ['node', $this->nodeVersion($directory, $package)];
        }

        $language = 'angular' === $framework || $this->writesTypeScript($directory) ? 'typescript' : 'javascript';
        $sources = self::existingDirectories($directory, 'angular' === $framework ? ['src/app'] : ['src']);

        // An application's entry points may live outside `src/` — `bin/runtime.js`: their directories are sources too.
        foreach ($entryPoints as $entryPoint) {
            $entryDirectory = \dirname($entryPoint);

            if ('.' !== $entryDirectory && !\in_array($entryDirectory, $sources, true) && [] !== self::existingDirectories($directory, [$entryDirectory])) {
                $sources[] = $entryDirectory;
            }
        }

        return new StackProfile(
            $root,
            $language,
            $framework,
            $version,
            $this->packageManager($directory),
            [] === $sources ? ['.'] : $sources,
            self::existingDirectories($directory, ['e2e', 'test', 'tests']),
            $excludes,
            // ADR-0032 and 0033: Angular and Express are read natively, on the Node bridge; the others go to Claude.
            \in_array($framework, ['angular', 'express'], true) ? $framework : 'claude',
            match (true) {
                null === $framework => $language,
                null === $version => $framework,
                default => $framework.'-'.self::major($version),
            },
        );
    }

    #[\Override]
    public function projectName(string $directory): ?string
    {
        $name = self::json($directory.'/package.json')['name'] ?? null;

        return \is_string($name) ? $name : null;
    }

    /**
     * An entry point (`bin`, `main` or a `start` script) and runtime dependencies (ADR-0050).
     *
     * @param array<mixed> $package
     * @param list<string> $entryPoints the files `main` and `bin` name, relative to the package
     *
     * @param-out list<string> $entryPoints
     */
    private static function isApplication(array $package, array &$entryPoints): bool
    {
        $bin = $package['bin'] ?? null;
        $entryPoints = array_values(array_filter(
            [...(\is_string($bin) ? [$bin] : array_values(self::stringMap($package, 'bin'))), ...(\is_string($package['main'] ?? null) ? [$package['main']] : [])],
            static fn (string $file): bool => '' !== $file,
        ));
        $entryPoints = array_values(array_unique(array_map(static fn (string $file): string => preg_replace('#^\./#', '', $file) ?? $file, $entryPoints)));
        $start = isset(self::stringMap($package, 'scripts')['start']);

        return ([] !== $entryPoints || $start) && [] !== self::stringMap($package, 'dependencies');
    }

    /**
     * `engines.node`, else `.nvmrc`, else `.node-version` — major.minor, or null.
     *
     * @param array<mixed> $package
     */
    private function nodeVersion(string $directory, array $package): ?string
    {
        $engine = self::stringMap($package, 'engines')['node'] ?? null;

        if (null !== $engine) {
            return self::majorMinor($engine);
        }

        foreach (['.nvmrc', '.node-version'] as $file) {
            if (is_file($directory.'/'.$file)) {
                return self::majorMinor((string) file_get_contents($directory.'/'.$file));
            }
        }

        return null;
    }

    /**
     * A `tsconfig.json` makes a TypeScript project — unless it only checks JavaScript (`checkJs`, ADR-0050).
     *
     * ⚠️ Not read through `json()`: a tsconfig commonly carries comments, which `json_decode` refuses. One
     * that does not decode keeps the verdict the file's presence always gave, TypeScript.
     */
    private function writesTypeScript(string $directory): bool
    {
        if (!is_file($directory.'/tsconfig.json')) {
            return false;
        }

        $tsconfig = json_decode((string) file_get_contents($directory.'/tsconfig.json'), true);
        $options = \is_array($tsconfig) && \is_array($tsconfig['compilerOptions'] ?? null) ? $tsconfig['compilerOptions'] : [];

        return true !== ($options['checkJs'] ?? null);
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
