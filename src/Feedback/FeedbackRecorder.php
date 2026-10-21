<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\XmlFilesToWorkflowsStore;
use Jul6Art\DevTools\Tracking\XmlIndexStore;

/**
 * Records a feedback (ADR-0025) — from the command line or from the review page (ADR-0028): checked, given an
 * identifier, tied to the commit it was seen at, and compared with what was already said, so that seeing it again
 * adds an occurrence rather than a duplicate.
 */
final readonly class FeedbackRecorder
{
    private FeedbackRepository $repository;

    public function __construct(
        private DevToolsDirectory $directory,
        private Categories $categories,
        private Clock $clock = new SystemClock(),
        private GitClient $git = new GitClient(),
    ) {
        $this->repository = new FeedbackRepository($directory);
    }

    public static function for(DevToolsDirectory $directory, Clock $clock = new SystemClock(), GitClient $git = new GitClient()): self
    {
        return new self($directory, Categories::of(new XmlConfigReader()->read($directory->configFile())), $clock, $git);
    }

    public function categories(): Categories
    {
        return $this->categories;
    }

    public function repository(): FeedbackRepository
    {
        return $this->repository;
    }

    /**
     * @return list<string> what makes it unrecordable, empty when it can be recorded
     */
    public function refusals(FeedbackTarget $target, string $category, string $summary): array
    {
        $refusals = [];
        $summary = trim($summary);

        if ('' === $summary || str_contains($summary, "\n")) {
            $refusals[] = 'The summary is one line: what is wrong, and what it should be.';
        }

        if (mb_strlen($summary) > Feedback::SUMMARY_MAX) {
            $refusals[] = \sprintf('The summary is %d characters long: %d at most — a feedback that needs a paragraph is two.', mb_strlen($summary), Feedback::SUMMARY_MAX);
        }

        if ($target->isEmpty()) {
            $refusals[] = 'Say what it is about: --workflow, --selector or --file.';
        }

        if (!$this->categories->has($category)) {
            $refusals[] = \sprintf('"%s" is not a category of feedback: %s.', $category, implode(', ', $this->categories->names()));
        }

        if (null !== $target->workflow && !\in_array($target->workflow, $this->workflows(), true)) {
            $refusals[] = \sprintf('"%s" is not a workflow of this project: workflows:inspect documents them.', $target->workflow);
        }

        if (null !== $target->file) {
            try {
                if (!is_file($this->directory->root->absolute($target->file))) {
                    $refusals[] = \sprintf('"%s" is not a file of this project.', $target->file);
                }
            } catch (PathOutsideProject $outside) {
                $refusals[] = $outside->getMessage();
            }
        }

        return $refusals;
    }

    /**
     * What was already said about it, if anything.
     */
    public function recurrenceOf(FeedbackTarget $target, string $category): ?FeedbackIndexEntry
    {
        $graph = $this->directory->filesToWorkflowsFile();

        return new RecurrenceDetector(is_file($graph) ? new XmlFilesToWorkflowsStore()->read($graph) : null)->find($this->repository->index(), self::normalised($target, $this->directory), $category);
    }

    public function create(FeedbackTarget $target, string $category, string $summary, FeedbackSource $by = FeedbackSource::Human): Feedback
    {
        $at = $this->clock->now();
        $target = self::normalised($target, $this->directory);
        $feedback = new Feedback($this->identifier($at, $target), FeedbackStatus::Open, $target, $category, trim($summary), [new Occurrence($at, $by, $target, $this->commit())]);
        $this->repository->save($feedback);

        return $feedback;
    }

    /**
     * @throws FeedbackNotFound
     */
    public function seenAgain(string $id, FeedbackTarget $target, FeedbackSource $by = FeedbackSource::Human): Feedback
    {
        $feedback = $this->repository->get($id)->seenAgain(new Occurrence($this->clock->now(), $by, self::normalised($target, $this->directory), $this->commit()));
        $this->repository->save($feedback);

        return $feedback;
    }

    /**
     * @throws FeedbackNotFound
     */
    public function resolve(string $id, FeedbackStatus $status, ?string $rule = null): Feedback
    {
        $feedback = $this->repository->get($id)->withStatus($status, $rule);
        $this->repository->save($feedback);

        return $feedback;
    }

    /**
     * `fb-<date>-<what it is about>`, unique.
     */
    private function identifier(\DateTimeImmutable $at, FeedbackTarget $target): string
    {
        $words = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', self::ascii($target->label())), '-'));
        $base = \sprintf('fb-%s-%s', $at->format('Y-m-d'), '' === $words ? 'feedback' : substr($words, 0, 40));
        $base = rtrim($base, '-');
        $index = $this->repository->index();
        $id = $base;

        for ($suffix = 2; $index->entry($id) instanceof FeedbackIndexEntry; ++$suffix) {
            $id = $base.'-'.$suffix;
        }

        return $id;
    }

    private function commit(): ?string
    {
        return $this->git->state($this->directory->root)?->commit;
    }

    /**
     * @return list<string>
     */
    private function workflows(): array
    {
        if (!is_file($this->directory->indexFile())) {
            return [];
        }

        $types = new WorkflowTypeRegistry(new XmlConfigReader()->read($this->directory->configFile())->customTypes);

        return array_map(static fn (IndexEntry $entry): string => $entry->id->value, new XmlIndexStore($types)->read($this->directory->indexFile())->entries);
    }

    private static function normalised(FeedbackTarget $target, DevToolsDirectory $directory): FeedbackTarget
    {
        return null === $target->file ? $target : new FeedbackTarget($target->workflow, $target->selector, $directory->root->relative($directory->root->absolute($target->file)));
    }

    private static function ascii(string $text): string
    {
        $decomposed = class_exists(\Normalizer::class) ? \Normalizer::normalize($text, \Normalizer::FORM_D) : $text;
        $decomposed = \is_string($decomposed) ? $decomposed : $text;

        return (string) preg_replace('/[^\x20-\x7E]/', '', $decomposed);
    }
}
