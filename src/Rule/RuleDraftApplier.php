<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Feedback\Categories;
use Jul6Art\DevTools\Feedback\FeedbackNotFound;
use Jul6Art\DevTools\Feedback\FeedbackRepository;
use Jul6Art\DevTools\Feedback\FeedbackStatus;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectFiles;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The rules Claude wrote (ADR-0026), applied by `workflows:apply`: a draft passes the schema, proposes — never
 * activates — a new rule promoted from exactly the feedback of its brief, and passes {@see RuleChecks}. Written,
 * the rule is `proposed`, and its feedback `promoted` to it.
 */
final readonly class RuleDraftApplier
{
    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private ProjectFiles $files = new ProjectFiles())
    {
    }

    /**
     * @return array{accepted: list<string>, refused: array<string, list<string>>} the rules written, the drafts refused
     */
    public function apply(DevToolsDirectory $directory, Config $config): array
    {
        $drafts = glob($directory->path('pending/rule.*.draft.xml')) ?: [];
        $outcome = ['accepted' => [], 'refused' => []];

        if ([] === $drafts) {
            return $outcome;
        }

        $checks = new RuleChecks($this->files->of($directory->root, $config->excludes()), new WorkflowTypeRegistry($config->customTypes)->all(), Categories::of($config));

        foreach ($drafts as $draft) {
            $key = 'rule.'.substr(basename($draft), \strlen('rule.'), -\strlen('.draft.xml'));
            $brief = $directory->path('pending/'.substr(basename($draft), 0, -\strlen('draft.xml')).'brief.xml');

            try {
                [$errors, $id] = $this->applyOne($directory, $draft, $brief, $checks);
            } catch (InvalidXml|FeedbackNotFound|\InvalidArgumentException $invalid) {
                [$errors, $id] = [[$invalid->getMessage()], null];
            }

            if ([] === $errors) {
                new Filesystem()->remove([$draft, $brief]);
                $outcome['accepted'][] = (string) $id;
            } else {
                $outcome['refused'][$key] = $errors;
            }
        }

        return $outcome;
    }

    /**
     * @return array{list<string>, ?string} the broken rules, and the identifier of the rule written
     */
    private function applyOne(DevToolsDirectory $directory, string $draft, string $briefFile, RuleChecks $checks): array
    {
        if (!is_file($briefFile)) {
            return [['The draft has no brief: rules:promote writes one.'], null];
        }

        $brief = new XmlRuleBriefStore()->read($briefFile);
        $rule = new XmlRuleStore()->read($draft);
        $rules = new RuleRepository($directory, $this->writer);
        $feedback = new FeedbackRepository($directory, $this->writer);
        $origin = $brief->feedbackIds();
        $errors = [];

        if (RuleStatus::Proposed !== $rule->status) {
            $errors[] = 'A draft proposes a rule: status="proposed" — a human activates it, with rules:activate.';
        }

        if ([] !== array_diff($rule->origin, $origin) || [] !== array_diff($origin, $rule->origin)) {
            $errors[] = \sprintf('The rule is promoted from the feedback of its brief, all of it and nothing else: %s.', implode(' ', $origin));
        }

        if ($rules->index()->entry($rule->id) instanceof RuleIndexEntry) {
            $errors[] = \sprintf('"%s" exists already: give the rule another identifier.', $rule->id);
        }

        if (null !== $rule->assertion) {
            $errors[] = 'A rule is proposed without its assertion: assertions:propose writes it once the rule is active.';
        }

        foreach ($origin as $id) {
            if (\in_array($feedback->get($id)->status, [FeedbackStatus::Promoted, FeedbackStatus::WontFix], true)) {
                $errors[] = \sprintf('%s was promoted or set aside since its brief: rules:promote again what is left.', $id);
            }
        }

        $errors = [...$errors, ...$checks->refusals($rule)];

        if ([] !== $errors) {
            return [$errors, null];
        }

        // The day of the brief, whatever the draft says: the rule was asked for then.
        $rules->save(new Rule($rule->id, RuleStatus::Proposed, $brief->date, $origin, $rule->appliesTo, $rule->instruction));

        foreach ($origin as $id) {
            $feedback->save($feedback->get($id)->withStatus(FeedbackStatus::Promoted, $rule->id));
        }

        return [[], $rule->id];
    }
}
