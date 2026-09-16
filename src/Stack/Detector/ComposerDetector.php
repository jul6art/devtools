<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Detector;

use Jul6Art\DevTools\Stack\StackProfile;

/**
 * PHP projects, from `composer.json` and `composer.lock`.
 */
final class ComposerDetector implements StackDetectorInterface
{
    use ReadsManifests;

    private const array FRAMEWORKS = [
        'symfony/framework-bundle' => ['symfony', 'symfony', ['config', 'migrations', 'src', 'templates']],
        // No native adapter in the MVP: Laravel goes through the Claude path until ADR-0030.
        'laravel/framework' => ['laravel', 'claude', ['app', 'config', 'database', 'resources/views', 'routes']],
    ];

    #[\Override]
    public function detect(string $directory, string $root, array $excludes): ?StackProfile
    {
        $composer = self::json($directory.'/composer.json');

        if (null === $composer) {
            return null;
        }

        $require = self::stringMap($composer, 'require');
        [$framework, $adapter, $conventions, $package] = [null, 'generic-php', ['bin', 'migrations', 'public', 'src', 'web', 'www'], null];

        foreach (self::FRAMEWORKS as $candidate => [$name, $frameworkAdapter, $frameworkConventions]) {
            if (isset($require[$candidate])) {
                [$framework, $adapter, $conventions, $package] = [$name, $frameworkAdapter, $frameworkConventions, $candidate];

                break;
            }
        }

        $version = null === $package ? null : ($this->lockedVersion($directory, $package) ?? self::majorMinor($require[$package]));
        $knowledge = null === $framework
            ? 'php'.(null === self::major(self::majorMinor($require['php'] ?? '')) ? '' : '-'.self::major(self::majorMinor($require['php'] ?? '')))
            : $framework.'-'.self::major($version);

        $sources = self::existingDirectories($directory, [...$conventions, ...$this->autoloadDirectories($composer, 'autoload')]);
        $tests = self::existingDirectories($directory, ['tests', ...$this->autoloadDirectories($composer, 'autoload-dev')]);

        return new StackProfile($root, 'php', $framework, $version, 'composer', [] === $sources ? ['.'] : $sources, $tests, $excludes, $adapter, $knowledge);
    }

    #[\Override]
    public function projectName(string $directory): ?string
    {
        $name = self::json($directory.'/composer.json')['name'] ?? null;

        return \is_string($name) ? $name : null;
    }

    private function lockedVersion(string $directory, string $package): ?string
    {
        $lock = self::json($directory.'/composer.lock') ?? [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (\is_array($lock[$section] ?? null) ? $lock[$section] : [] as $locked) {
                if (\is_array($locked) && $package === ($locked['name'] ?? null) && \is_string($locked['version'] ?? null)) {
                    return self::majorMinor($locked['version']);
                }
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $composer
     *
     * @return list<string>
     */
    private function autoloadDirectories(array $composer, string $section): array
    {
        $psr4 = \is_array($composer[$section] ?? null) && \is_array($composer[$section]['psr-4'] ?? null) ? $composer[$section]['psr-4'] : [];
        $directories = [];

        foreach ($psr4 as $paths) {
            foreach ((array) $paths as $path) {
                if (\is_string($path)) {
                    $directories[] = trim($path, '/');
                }
            }
        }

        return $directories;
    }
}
