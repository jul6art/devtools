<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking\Rename;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What a rename writes and removes — computed whole before anything is touched, which is what `--dry-run`
 * shows (ADR-0036).
 */
final class RenamePlan
{
    /**
     * @var array<string, string> absolute path => content
     */
    public array $writes = [];

    /**
     * @var array<string, string> absolute path => the path it moves to
     */
    public array $moves = [];

    /**
     * @var list<string> absolute paths of the directories removed whole once their files have moved
     */
    public array $removals = [];

    /**
     * @var list<string>
     */
    public array $notes = [];

    public function __construct(public readonly WorkflowId $old, public readonly WorkflowId $new)
    {
    }

    public function write(string $path, string $content): void
    {
        $this->writes[$path] = $content;
    }

    public function copy(string $from, string $to): void
    {
        $this->writes[$to] = (string) file_get_contents($from);
    }

    public function move(string $from, string $to): void
    {
        if ($from !== $to) {
            $this->moves[$from] = $to;
        }
    }

    /**
     * A directory of the workflow's own — a scenario's, a baseline's — whose every file was moved.
     */
    public function removeDirectory(string $directory): void
    {
        $this->removals[] = $directory;
    }

    public function apply(AtomicFileWriter $writer, Filesystem $filesystem): void
    {
        foreach ($this->writes as $path => $content) {
            $writer->write($path, $content);
        }

        $filesystem->remove([...array_keys($this->moves), ...$this->removals]);
    }

    /**
     * @return list<string> the files written, relative to `$root`
     */
    public function changed(string $root): array
    {
        $files = array_map(static fn (string $path): string => substr($path, \strlen($root) + 1), array_keys($this->writes));
        sort($files, \SORT_STRING);

        return $files;
    }
}
