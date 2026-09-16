<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Project;

/**
 * One inspection at a time per project: two concurrent runs would interleave writes to the same files.
 * A native `flock`, released by the operating system if the process dies.
 */
final class ProjectLock
{
    /**
     * @param resource $handle
     */
    private function __construct(private $handle)
    {
    }

    public static function acquire(string $devtoolsDirectory): self
    {
        if (!is_dir($devtoolsDirectory.'/reports')) {
            mkdir($devtoolsDirectory.'/reports', 0o777, true);
        }

        $handle = fopen($devtoolsDirectory.'/reports/.inspect.lock', 'c');

        if (false === $handle || !flock($handle, \LOCK_EX | \LOCK_NB)) {
            throw new \RuntimeException('Another inspection is running on this project; wait for it to finish.');
        }

        return new self($handle);
    }

    public function release(): void
    {
        flock($this->handle, \LOCK_UN);
        fclose($this->handle);
    }
}
