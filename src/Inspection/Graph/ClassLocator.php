<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * Resolves a class name to a file of the project or to a package, from `composer.json` and
 * `composer.lock` — never through an autoloader, which would execute the analysed project.
 */
final readonly class ClassLocator
{
    /**
     * @param array<string, list<string>>          $projectPrefixes namespace prefix => directories relative to the project
     * @param array<string, array{string, string}> $packagePrefixes namespace prefix => [package, version]
     */
    private function __construct(
        private ProjectRoot $root,
        private array $projectPrefixes,
        private array $packagePrefixes,
    ) {
    }

    public static function for(ProjectRoot $root, StackProfile $stack): self
    {
        $directory = $root->absolute($stack->root);
        $composer = self::json($directory.'/composer.json');
        $projectPrefixes = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (self::psr4($composer[$section] ?? null) as $prefix => $paths) {
                foreach ($paths as $path) {
                    $projectPrefixes[$prefix][] = $stack->projectPath('' === trim($path, '/') ? '.' : trim($path, '/'));
                }
            }
        }

        $packagePrefixes = [];
        $lock = self::json($directory.'/composer.lock');

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (\is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                if (!\is_array($package) || !\is_string($package['name'] ?? null) || !\is_string($package['version'] ?? null)) {
                    continue;
                }

                $autoload = \is_array($package['autoload'] ?? null) ? $package['autoload'] : [];

                foreach ([...array_keys(self::psr4($autoload)), ...array_keys(self::psr4($autoload, 'psr-0'))] as $prefix) {
                    $packagePrefixes[$prefix] = [$package['name'], ltrim($package['version'], 'v')];
                }
            }
        }

        // Longest prefix first: `App\Tests\` must win over `App\`.
        uksort($projectPrefixes, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        uksort($packagePrefixes, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return new self($root, $projectPrefixes, $packagePrefixes);
    }

    public function locate(string $class): FileRef|PackageRef|null
    {
        foreach ($this->projectPrefixes as $prefix => $directories) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            foreach ($directories as $directory) {
                $path = ltrim(('.' === $directory ? '' : $directory.'/').str_replace('\\', '/', substr($class, \strlen($prefix))).'.php', '/');

                if (is_file($this->root->absolute($path))) {
                    return new FileRef($path, RoleGuesser::guess($path));
                }
            }
        }

        foreach ($this->packagePrefixes as $prefix => [$name, $version]) {
            if ('' !== $prefix && str_starts_with($class, $prefix)) {
                return new PackageRef($name, $version);
            }
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $file): array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return \is_array($data) ? $data : [];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function psr4(mixed $autoload, string $standard = 'psr-4'): array
    {
        $map = \is_array($autoload) && \is_array($autoload[$standard] ?? null) ? $autoload[$standard] : [];
        $prefixes = [];

        foreach ($map as $prefix => $paths) {
            if (\is_string($prefix)) {
                $prefixes[$prefix] = array_values(array_filter((array) $paths, \is_string(...)));
            }
        }

        return $prefixes;
    }
}
