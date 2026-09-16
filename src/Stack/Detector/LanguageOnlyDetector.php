<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Detector;

use Jul6Art\DevTools\Stack\StackProfile;

/**
 * Go, Python, Rust and Java: language and package manager only, left to the Claude path (ADR-0005).
 */
final readonly class LanguageOnlyDetector implements StackDetectorInterface
{
    use ReadsManifests;

    private function __construct(
        private string $manifest,
        private string $language,
        private string $packageManager,
        private string $nameKey,
    ) {
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            new self('go.mod', 'go', 'go', 'module'),
            new self('pyproject.toml', 'python', 'pip', 'name'),
            new self('Cargo.toml', 'rust', 'cargo', 'name'),
            new self('pom.xml', 'java', 'maven', 'artifactId'),
        ];
    }

    #[\Override]
    public function detect(string $directory, string $root, array $excludes): ?StackProfile
    {
        if (!is_file($directory.'/'.$this->manifest)) {
            return null;
        }

        return new StackProfile($root, $this->language, null, null, $this->packageManagerIn($directory), ['.'], [], $excludes, 'claude', $this->language);
    }

    #[\Override]
    public function projectName(string $directory): ?string
    {
        $file = $directory.'/'.$this->manifest;

        return match ($this->manifest) {
            'go.mod' => is_file($file) && 1 === preg_match('/^module\s+(\S+)/m', (string) file_get_contents($file), $matches) ? $matches[1] : null,
            'pom.xml' => is_file($file) && 1 === preg_match('#<artifactId>([^<]+)</artifactId>#', (string) file_get_contents($file), $matches) ? $matches[1] : null,
            default => self::tomlString($file, $this->nameKey),
        };
    }

    private function packageManagerIn(string $directory): string
    {
        if ('python' !== $this->language) {
            return $this->packageManager;
        }

        return match (true) {
            is_file($directory.'/uv.lock') => 'uv',
            str_contains((string) @file_get_contents($directory.'/pyproject.toml'), '[tool.poetry]') => 'poetry',
            default => 'pip',
        };
    }
}
