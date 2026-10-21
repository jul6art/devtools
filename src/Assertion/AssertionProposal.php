<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Feedback\Feedback;
use Jul6Art\DevTools\Feedback\FeedbackNotFound;
use Jul6Art\DevTools\Feedback\FeedbackRepository;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Rule\Glob;
use Jul6Art\DevTools\Rule\Rule;
use Jul6Art\DevTools\Rule\RuleNotFound;
use Jul6Art\DevTools\Rule\RuleRepository;
use Jul6Art\DevTools\Rule\RuleStatus;
use Jul6Art\DevTools\Scenario\Run\XmlRunReader;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;

/**
 * `assertions:propose` (ADR-0027): the brief Claude writes the assertion of a rule from — the rule, its feedback
 * and where it was reported, the files, the DOM of the last run, the tools of the project.
 */
final readonly class AssertionProposal
{
    public const string PROMPT_VERSION = 'v1';

    /**
     * The files of the rule given to Claude: enough to see what the defect looks like.
     */
    private const int FILES = 40;

    /**
     * The tools an assertion may call, where a project installs them.
     */
    private const array TOOLS = ['phpstan' => 'vendor/bin/phpstan', 'eslint' => 'node_modules/.bin/eslint', 'symfony-console' => 'bin/console'];

    public function __construct(private DevToolsDirectory $directory, private AtomicFileWriter $writer = new AtomicFileWriter(), private GitClient $git = new GitClient())
    {
    }

    /**
     * @return list<string> what makes the proposal impossible, empty when it is possible
     */
    public function refusals(string $id): array
    {
        try {
            $rule = new RuleRepository($this->directory, $this->writer)->get($id);
        } catch (RuleNotFound|InvalidXml $missing) {
            return [$missing->getMessage()];
        }

        return array_values(array_filter([
            RuleStatus::Archived === $rule->status ? \sprintf('%s is archived: rules:activate it first.', $rule->id) : null,
            null === $rule->assertion ? null : \sprintf('%s is already checked by %s.', $rule->id, $rule->assertion),
        ]));
    }

    /**
     * Writes the brief — {@see self::refusals()} first.
     *
     * @param list<string> $before the commits where the assertion must fail; empty: those its feedback was reported at
     *
     * @return string the brief, relative to the project
     */
    public function brief(string $id, array $before = []): string
    {
        $root = $this->directory->root;
        $rule = new RuleRepository($this->directory, $this->writer)->get($id);
        $feedback = $this->feedback($rule);
        $config = new XmlConfigReader()->read($this->directory->configFile());
        $files = array_values(array_filter(new WorkingTree($root, $config->excludes())->files(), static fn (string $file): bool => array_any($rule->appliesTo->globs, static fn (string $glob): bool => new Glob($glob)->matches($file))));
        $reported = array_values(array_unique(array_filter(array_map(static fn (Feedback $one): ?string => $one->reported()->commit, $feedback), \is_string(...))));
        $brief = new AssertionBrief(
            self::PROMPT_VERSION,
            Resources::path('prompts/assertion/'.self::PROMPT_VERSION.'.md'),
            Resources::path('schemas/assertion.xsd'),
            ['id' => $rule->id, 'path' => $root->relative($this->directory->rulesDirectory().'/'.$rule->path()), 'instruction' => $rule->instruction, 'globs' => $rule->appliesTo->globs, 'workflowTypes' => $rule->appliesTo->workflowTypes],
            array_map(fn (Feedback $one): array => [
                'id' => $one->id,
                'path' => $root->relative($this->directory->feedbackDirectory().'/'.$one->path()),
                'summary' => $one->summary,
                'commit' => $one->reported()->commit,
                'workflow' => $one->target->workflow,
                'selector' => $one->target->selector,
                'file' => $one->target->file,
            ], $feedback),
            array_values(array_filter([] === $before ? $reported : $before, fn (string $commit): bool => $this->git->commitExists($root, $commit))),
            \array_slice($files, 0, self::FILES),
            $this->captures($feedback),
            array_values(array_map(static fn (string $name, string $path): array => ['name' => $name, 'path' => $path], array_keys($tools = array_filter(self::TOOLS, static fn (string $path): bool => is_file($root->absolute($path)))), $tools)),
            DevToolsDirectory::NAME.'/pending/'.AssertionBrief::fileName($rule->id, 'draft.xml'),
        );
        $file = $this->directory->path('pending/'.AssertionBrief::fileName($rule->id, 'brief.xml'));
        new XmlAssertionBriefStore($this->writer)->write($file, $brief);

        return $root->relative($file);
    }

    /**
     * @return non-empty-list<Feedback>
     */
    private function feedback(Rule $rule): array
    {
        $repository = new FeedbackRepository($this->directory, $this->writer);
        $feedback = [];

        foreach ($rule->origin as $id) {
            try {
                $feedback[] = $repository->get($id);
            } catch (FeedbackNotFound) {
                // A feedback deleted by hand: the rule still says what it was about.
            }
        }

        return [] === $feedback ? throw new \RuntimeException(\sprintf('%s comes from no feedback this project still has.', $rule->id)) : $feedback;
    }

    /**
     * The DOM the last run captured for the scenarios of the workflows the feedback is about.
     *
     * @param list<Feedback> $feedback
     *
     * @return list<array{scenario: string, name: string, dom: string}>
     */
    private function captures(array $feedback): array
    {
        $runs = glob($this->directory->path('runs/*/result.xml')) ?: [];
        sort($runs, \SORT_STRING);
        $last = array_last($runs);
        $workflows = array_filter(array_map(static fn (Feedback $one): ?string => $one->target->workflow, $feedback), \is_string(...));

        if (null === $last || [] === $workflows) {
            return [];
        }

        $captures = [];

        try {
            $scenarios = new XmlRunReader()->read($last);
        } catch (InvalidXml) {
            return [];
        }

        foreach ($scenarios as $scenario => $result) {
            if (!array_any($workflows, static fn (string $workflow): bool => str_starts_with($scenario, $workflow.'/'))) {
                continue;
            }

            foreach ($result['captures'] as $capture) {
                $captures[] = ['scenario' => $scenario, 'name' => basename($capture['name']), 'dom' => $this->directory->root->relative(\dirname($last).'/'.$capture['dom'])];
            }
        }

        return $captures;
    }
}
