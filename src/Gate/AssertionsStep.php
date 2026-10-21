<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

use Jul6Art\DevTools\Assertion\Assertion;
use Jul6Art\DevTools\Assertion\AssertionChecker;
use Jul6Art\DevTools\Assertion\AssertionRepository;
use Jul6Art\DevTools\Assertion\ToolRunner;
use Jul6Art\DevTools\Assertion\Violation;
use Jul6Art\DevTools\Assertion\WorkingTree;
use Jul6Art\DevTools\Rule\RuleIndexEntry;
use Jul6Art\DevTools\Rule\RuleRepository;
use Jul6Art\DevTools\Rule\RuleStatus;
use Jul6Art\DevTools\Xml\InvalidXml;

/**
 * The mechanical assertions of the active rules (ADR-0027), on the files of the change they watch — those that
 * watch none of them are not run. One that fails stops the change, names its rule and where, and counts as
 * `blocked` for the rule.
 */
final readonly class AssertionsStep implements GateStepInterface
{
    /**
     * Past this, the violations of one assertion are counted rather than listed.
     */
    private const int LISTED = 5;

    public function __construct(private ?ToolRunner $tools = null)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'assertions';
    }

    #[\Override]
    public function run(GateContext $context): GateStep
    {
        $rules = new RuleRepository($context->directory);
        $enforced = array_values(array_filter($rules->index()->entries, static fn (RuleIndexEntry $rule): bool => RuleStatus::Active === $rule->status && null !== $rule->assertion));

        if ([] === $enforced) {
            return new GateStep($this->name(), notes: ['No active rule has an assertion.']);
        }

        $repository = new AssertionRepository($context->directory);
        $failures = [];
        $concerned = [];

        foreach ($enforced as $rule) {
            try {
                $assertion = $repository->get((string) $rule->assertion);
            } catch (InvalidXml $invalid) {
                $failures[] = \sprintf('%s: its assertion %s cannot be read — %s', $rule->id, $rule->assertion, $invalid->getMessage());

                continue;
            }

            if ([] !== $assertion->watched($context->changed)) {
                $concerned[] = $assertion;
            }
        }

        $notes = [\sprintf('%d of %d assertions watch a file of the change.', \count($concerned), \count($enforced))];

        if ([] === $concerned) {
            return new GateStep($this->name(), $failures, notes: $notes);
        }

        // The DOM of this very passage, if the scenarios step replayed any: never the captures of an older change.
        $run = $context->directory->runDirectory($context->at).'/result.xml';
        $checker = new AssertionChecker($context->directory->root, $this->tools ?? new ToolRunner(), is_file($run) ? $run : null);
        $warnings = [];
        $stats = $rules->stats();

        foreach ($checker->check($concerned, new WorkingTree($context->directory->root, $context->config->excludes()), $context->changed) as $result) {
            $assertion = $result->assertion;

            if ($result->failed()) {
                $failures = [...$failures, ...self::describe($assertion, $result->violations)];
                $stats = $stats->blocked($assertion->rule, $context->at);
            } elseif (null !== $result->unverifiable) {
                $warnings[] = \sprintf('%s (%s) cannot tell: %s', $assertion->rule, $assertion->id, $result->unverifiable);
            }
        }

        $rules->saveStats($stats);

        return new GateStep($this->name(), $failures, $warnings, $notes);
    }

    /**
     * One line per place it failed: the rule and its assertion, what is wrong, where, and what was found there.
     *
     * @param list<Violation> $violations
     *
     * @return list<string>
     */
    private static function describe(Assertion $assertion, array $violations): array
    {
        $lines = array_map(
            static fn (Violation $violation): string => \sprintf('%s (%s): %s%s', $assertion->rule, $assertion->id, $assertion->message, ' — '.ltrim($violation->where().' '.$violation->found)),
            \array_slice($violations, 0, self::LISTED),
        );

        if (\count($violations) > self::LISTED) {
            $lines[] = \sprintf('%s (%s): and %d more', $assertion->rule, $assertion->id, \count($violations) - self::LISTED);
        }

        return $lines;
    }
}
