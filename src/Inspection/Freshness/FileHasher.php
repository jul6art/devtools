<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Freshness;

/**
 * SHA-256 of the project's files, each computed once per run however many workflows share the file.
 */
final class FileHasher
{
    /**
     * @var array<string, string|null> null for a file that cannot be read
     */
    private array $cache = [];

    private int $hashes = 0;

    public function hash(string $absolutePath): ?string
    {
        if (\array_key_exists($absolutePath, $this->cache)) {
            return $this->cache[$absolutePath];
        }

        ++$this->hashes;
        $hash = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : false;

        return $this->cache[$absolutePath] = false === $hash ? null : $hash;
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    public function hashes(): int
    {
        return $this->hashes;
    }

    public function distinctFiles(): int
    {
        return \count($this->cache);
    }
}
