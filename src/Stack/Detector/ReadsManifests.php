<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Detector;

use Jul6Art\DevTools\Stack\StackDetectionFailed;

/**
 * @internal
 */
trait ReadsManifests
{
    /**
     * @return array<mixed>|null null when the file does not exist
     */
    private static function json(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $invalid) {
            throw StackDetectionFailed::malformedManifest($file, $invalid->getMessage());
        }

        if (!\is_array($data)) {
            throw StackDetectionFailed::malformedManifest($file, 'a JSON object was expected.');
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private static function stringMap(array $data, string $key): array
    {
        $map = [];

        foreach (\is_array($data[$key] ?? null) ? $data[$key] : [] as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $map[$name] = $value;
            }
        }

        return $map;
    }

    /**
     * `v7.4.3`, `^7.4 || ^8.0`, `~22.0.1`, `^8` → `7.4`, `7.4`, `22.0`, `8.0`.
     */
    private static function majorMinor(string $versionOrConstraint): ?string
    {
        if (1 !== preg_match('/(\d+)(?:\.(\d+))?/', $versionOrConstraint, $matches)) {
            return null;
        }

        return $matches[1].'.'.($matches[2] ?? '0');
    }

    private static function major(?string $majorMinor): ?string
    {
        return null === $majorMinor ? null : explode('.', $majorMinor)[0];
    }

    /**
     * @param list<string> $candidates relative to $directory
     *
     * @return list<string>
     */
    private static function existingDirectories(string $directory, array $candidates): array
    {
        return array_values(array_filter($candidates, static fn (string $candidate): bool => '' !== $candidate && is_dir($directory.'/'.$candidate)));
    }

    /**
     * A line of a TOML file such as `name = "acme"`, without a TOML parser.
     */
    private static function tomlString(string $file, string $key): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        return 1 === preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*"([^"]+)"/m', (string) file_get_contents($file), $matches) ? $matches[1] : null;
    }
}
