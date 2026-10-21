<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Feedback\Categories;
use Jul6Art\DevTools\Feedback\Feedback;
use Jul6Art\DevTools\Feedback\FeedbackNotFound;
use Jul6Art\DevTools\Feedback\FeedbackRepository;
use Jul6Art\DevTools\Feedback\FeedbackStatus;
use Jul6Art\DevTools\Feedback\FeedbackTarget;
use Jul6Art\DevTools\Feedback\Occurrence;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\FileRelation;
use Jul6Art\DevTools\Tracking\XmlFilesToWorkflowsStore;

/**
 * `rules:promote` (ADR-0026): the brief Claude turns feedback into a rule from — what was said, about which files,
 * and what a rule may name. The rule itself comes back as a draft, checked by `workflows:apply`.
 */
final readonly class RulePromotion
{
    public const string PROMPT_VERSION = 'v1';

    private FeedbackRepository $feedback;

    public function __construct(
        private DevToolsDirectory $directory,
        private Clock $clock = new SystemClock(),
        private AtomicFileWriter $writer = new AtomicFileWriter(),
    ) {
        $this->feedback = new FeedbackRepository($directory, $writer);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> what makes the promotion impossible, empty when it is possible
     */
    public function refusals(array $ids): array
    {
        if ([] === $ids) {
            return ['Name the feedback to promote: feedback:list gives the identifiers.'];
        }

        $refusals = [];

        foreach (array_unique($ids) as $id) {
            try {
                $feedback = $this->feedback->get($id);
            } catch (FeedbackNotFound $missing) {
                $refusals[] = $missing->getMessage();

                continue;
            }

            $refusals[] = match ($feedback->status) {
                FeedbackStatus::Promoted => \sprintf('%s is already the rule %s.', $id, $feedback->promotedTo),
                FeedbackStatus::WontFix => \sprintf('%s was set aside as wont-fix: there is nothing to make a rule of.', $id),
                default => null,
            };
        }

        return array_values(array_filter($refusals, \is_string(...)));
    }

    /**
     * Writes the brief — {@see self::refusals()} first.
     *
     * @param non-empty-list<string> $ids
     *
     * @return string the brief, relative to the project
     */
    public function brief(array $ids): string
    {
        $ids = array_values(array_unique($ids));
        $config = new XmlConfigReader()->read($this->directory->configFile());
        $feedback = array_map($this->feedback->get(...), $ids);
        $brief = new RuleBrief(
            $this->clock->now()->format('Y-m-d'),
            self::PROMPT_VERSION,
            Resources::path('prompts/rule/'.self::PROMPT_VERSION.'.md'),
            Resources::path('schemas/rule.xsd'),
            array_map(fn (Feedback $one): array => [
                'id' => $one->id,
                'path' => $this->directory->root->relative($this->directory->feedbackDirectory().'/'.$one->path()),
                'category' => $one->category,
                'occurrences' => \count($one->occurrences),
                'summary' => $one->summary,
                'workflow' => $one->target->workflow,
                'selector' => $one->target->selector,
                'file' => $one->target->file,
            ], $feedback),
            $this->files($feedback),
            array_map(static fn (WorkflowType $type): string => $type->name, new WorkflowTypeRegistry($config->customTypes)->all()),
            Categories::of($config)->names(),
            array_map(fn (RuleIndexEntry $rule): array => ['id' => $rule->id, 'path' => $this->directory->root->relative($this->directory->rulesDirectory().'/'.$rule->path()), 'status' => $rule->status->value], new RuleRepository($this->directory, $this->writer)->index()->entries),
            DevToolsDirectory::NAME.'/pending/'.RuleBrief::fileName($ids[0], 'draft.xml'),
        );
        $file = $this->directory->path('pending/'.RuleBrief::fileName($ids[0], 'brief.xml'));
        new XmlRuleBriefStore($this->writer)->write($file, $brief);

        return $this->directory->root->relative($file);
    }

    /**
     * The files the feedback is about, wherever it was seen again, and the files of its workflows: where the
     * globs of the rule start from.
     *
     * @param list<Feedback> $feedback
     *
     * @return list<string>
     */
    private function files(array $feedback): array
    {
        $targets = array_merge(...array_map(static fn (Feedback $one): array => [$one->target, ...array_map(static fn (Occurrence $occurrence): FeedbackTarget => $occurrence->target, $one->occurrences)], $feedback));
        $files = array_filter(array_map(static fn (FeedbackTarget $target): ?string => $target->file, $targets), \is_string(...));
        $workflows = array_filter(array_map(static fn (FeedbackTarget $target): ?string => $target->workflow, $targets), \is_string(...));
        $graph = $this->directory->filesToWorkflowsFile();

        if ([] !== $workflows && is_file($graph)) {
            foreach (new XmlFilesToWorkflowsStore()->read($graph)->links as $path => $links) {
                foreach ($links as $link) {
                    if (FileRelation::File === $link->relation && \in_array($link->workflow->value, $workflows, true)) {
                        $files[] = $path;
                    }
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files, \SORT_STRING);

        return $files;
    }
}
