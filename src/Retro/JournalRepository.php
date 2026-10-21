<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Retro;

use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * The journal of the tasks delivered (ADR-0029): one file a month in `.devtools/journal/`, versioned.
 */
final readonly class JournalRepository
{
    public function __construct(private DevToolsDirectory $directory, private AtomicFileWriter $writer = new AtomicFileWriter())
    {
    }

    /**
     * Writes a task in the file of its month — once: delivered twice, it is the same task.
     */
    public function record(Task $task): bool
    {
        $file = $this->file($task->month());
        $store = new XmlJournalStore($this->writer);
        $tasks = $store->read($file);

        if (array_any($tasks, static fn (Task $known): bool => $known->id === $task->id)) {
            return false;
        }

        $tasks[] = $task;
        usort($tasks, static fn (Task $a, Task $b): int => [$a->deliveredAt, $a->id] <=> [$b->deliveredAt, $b->id]);

        return $store->write($file, $task->month(), $tasks);
    }

    /**
     * Every task delivered since a moment, in the order they were.
     *
     * @return list<Task>
     */
    public function since(\DateTimeImmutable $since): array
    {
        $files = glob($this->directory->journalDirectory().'/*.xml') ?: [];
        sort($files, \SORT_STRING);
        $store = new XmlJournalStore($this->writer);
        $tasks = [];

        foreach ($files as $file) {
            if (basename($file, '.xml') >= $since->format('Y-m')) {
                $tasks = [...$tasks, ...array_values(array_filter($store->read($file), static fn (Task $task): bool => $task->deliveredAt >= $since))];
            }
        }

        return $tasks;
    }

    private function file(string $month): string
    {
        return $this->directory->journalDirectory().'/'.$month.'.xml';
    }
}
