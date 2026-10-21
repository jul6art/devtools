<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

use Jul6Art\DevTools\Feedback\Categories;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;

/**
 * What a schema cannot say about a rule (ADR-0026): one to three sentences, globs that stay in the project and
 * each match a file of it, kinds of workflow and a category the project has.
 */
final readonly class RuleChecks
{
    /**
     * @param list<string>       $files the files of the project, {@see \Jul6Art\DevTools\Project\ProjectFiles}
     * @param list<WorkflowType> $types the kinds of workflow the project knows
     */
    public function __construct(
        private array $files,
        private array $types,
        private Categories $categories,
    ) {
    }

    /**
     * @return list<string> what makes it unacceptable, empty when it is acceptable
     */
    public function refusals(Rule $rule): array
    {
        $refusals = [];
        $sentences = Rule::sentences($rule->instruction);

        if ($sentences > Rule::SENTENCES_MAX) {
            $refusals[] = \sprintf('The instruction is %d sentences long: %d at most — a rule that needs a paragraph is two rules.', $sentences, Rule::SENTENCES_MAX);
        }

        foreach ($rule->appliesTo->globs as $glob) {
            if (\in_array('..', explode('/', $glob), true)) {
                $refusals[] = \sprintf('"%s" leaves the project: a glob is relative to its root.', $glob);
            } elseif ([] === new Glob($glob)->filter($this->files)) {
                $refusals[] = \sprintf('"%s" matches no file of this project: a rule applies where something exists.', $glob);
            }
        }

        $known = array_map(static fn (WorkflowType $type): string => $type->name, $this->types);

        foreach (array_diff($rule->appliesTo->workflowTypes, $known) as $unknown) {
            $refusals[] = \sprintf('"%s" is not a kind of workflow of this project: %s.', $unknown, implode(', ', $known));
        }

        if (null !== $rule->appliesTo->category && !$this->categories->has($rule->appliesTo->category)) {
            $refusals[] = \sprintf('"%s" is not a category of feedback: %s.', $rule->appliesTo->category, implode(', ', $this->categories->names()));
        }

        return $refusals;
    }
}
