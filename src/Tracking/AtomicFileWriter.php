<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes a file so that a reader sees the old content or the new one, never half of either.
 *
 * The content goes to a temporary file in the same directory — same filesystem, so the rename is
 * atomic — then replaces the target. Identical content is not rewritten at all: an unchanged
 * `.devtools/` must not even change its modification times (idempotence, ADR-0010).
 */
final readonly class AtomicFileWriter
{
    /**
     * @param (\Closure(string): void)|null $afterTemporaryWrite test seam: runs between the temporary
     *                                                           write and the rename, to simulate a crash
     */
    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
        private ?\Closure $afterTemporaryWrite = null,
    ) {
    }

    /**
     * @return bool whether the file changed
     */
    public function write(string $path, string $content): bool
    {
        if (is_file($path) && file_get_contents($path) === $content) {
            return false;
        }

        $this->filesystem->mkdir(\dirname($path));
        $temporary = \sprintf('%s/.%s.%s.tmp', \dirname($path), basename($path), bin2hex(random_bytes(4)));

        try {
            if (false === file_put_contents($temporary, $content)) {
                throw new \RuntimeException(\sprintf('Could not write "%s".', $temporary));
            }

            if ($this->afterTemporaryWrite instanceof \Closure) {
                ($this->afterTemporaryWrite)($temporary);
            }

            $this->filesystem->rename($temporary, $path, true);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return true;
    }
}
