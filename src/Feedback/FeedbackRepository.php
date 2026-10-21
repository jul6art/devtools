<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * The feedback of a project (ADR-0025): one file per feedback, and the index every reader goes through.
 */
final readonly class FeedbackRepository
{
    public function __construct(private DevToolsDirectory $directory, private AtomicFileWriter $writer = new AtomicFileWriter())
    {
    }

    public function index(): FeedbackIndex
    {
        return new XmlFeedbackIndexStore($this->writer)->read($this->indexFile());
    }

    /**
     * @throws FeedbackNotFound
     */
    public function get(string $id): Feedback
    {
        $entry = $this->index()->entry($id) ?? throw FeedbackNotFound::id($id);

        return new XmlFeedbackStore($this->writer)->read($this->directory->feedbackDirectory().'/'.$entry->path);
    }

    /**
     * Writes the feedback and its line of the index.
     */
    public function save(Feedback $feedback): void
    {
        new XmlFeedbackStore($this->writer)->write($this->directory->feedbackDirectory().'/'.$feedback->path(), $feedback);
        new XmlFeedbackIndexStore($this->writer)->write($this->indexFile(), $this->index()->with(FeedbackIndexEntry::of($feedback)));
    }

    private function indexFile(): string
    {
        return $this->directory->feedbackDirectory().'/index.xml';
    }
}
