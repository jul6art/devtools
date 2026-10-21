<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Rule\Glob;
use Jul6Art\DevTools\Rule\RuleNotFound;
use Jul6Art\DevTools\Rule\RuleRepository;
use Jul6Art\DevTools\Rule\RuleStatus;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The assertions Claude wrote (ADR-0027), applied by `workflows:apply` — and validated by **running them**: on the
 * project as it is, an assertion passes; where its feedback was reported, it fails. One that cannot tell the defect
 * from its fix is refused.
 */
final readonly class AssertionDraftApplier
{
    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private GitClient $git = new GitClient(), private ?ToolRunner $tools = null)
    {
    }

    /**
     * @return array{accepted: list<string>, refused: array<string, list<string>>, warnings: list<string>}
     */
    public function apply(DevToolsDirectory $directory, Config $config): array
    {
        $drafts = glob($directory->path('pending/assertion.*.draft.xml')) ?: [];
        $outcome = ['accepted' => [], 'refused' => [], 'warnings' => []];

        foreach ($drafts as $draft) {
            $key = 'assertion.'.substr(basename($draft), \strlen('assertion.'), -\strlen('.draft.xml'));
            $brief = $directory->path('pending/'.substr(basename($draft), 0, -\strlen('draft.xml')).'brief.xml');

            try {
                [$errors, $id, $warnings] = $this->applyOne($directory, $config, $draft, $brief);
            } catch (InvalidXml|RuleNotFound|\InvalidArgumentException $invalid) {
                [$errors, $id, $warnings] = [[$invalid->getMessage()], null, []];
            }

            if ([] === $errors) {
                new Filesystem()->remove([$draft, $brief]);
                $outcome['accepted'][] = (string) $id;
                $outcome['warnings'] = [...$outcome['warnings'], ...$warnings];
            } else {
                $outcome['refused'][$key] = $errors;
            }
        }

        return $outcome;
    }

    /**
     * @return array{list<string>, ?string, list<string>} the broken rules, the assertion written, what could not be checked
     */
    private function applyOne(DevToolsDirectory $directory, Config $config, string $draft, string $briefFile): array
    {
        if (!is_file($briefFile)) {
            return [['The draft has no brief: assertions:propose writes one.'], null, []];
        }

        $brief = new XmlAssertionBriefStore()->read($briefFile);
        $assertion = new XmlAssertionStore()->read($draft);
        $rules = new RuleRepository($directory, $this->writer);
        $assertions = new AssertionRepository($directory, $this->writer);
        $now = new WorkingTree($directory->root, $config->excludes());
        $errors = [];

        if ($assertion->rule !== $brief->rule['id']) {
            return [[\sprintf('The assertion enforces the rule of its brief: rule="%s".', $brief->rule['id'])], null, []];
        }

        $rule = $rules->get($assertion->rule);

        if (RuleStatus::Archived === $rule->status || null !== $rule->assertion) {
            $errors[] = \sprintf('%s is archived, or checked by %s already, since its brief.', $rule->id, $rule->assertion ?? 'another assertion');
        }

        if ($assertions->has($assertion->id)) {
            $errors[] = \sprintf('"%s" exists already: give the assertion another identifier.', $assertion->id);
        }

        foreach ($assertion->watch as $glob) {
            if (\in_array('..', explode('/', $glob), true)) {
                $errors[] = \sprintf('"%s" leaves the project: a glob is relative to its root.', $glob);
            } elseif ([] === new Glob($glob)->filter($now->files())) {
                $errors[] = \sprintf('"%s" matches no file of this project: an assertion watches what exists.', $glob);
            }
        }

        if ($assertion->check instanceof PatternCheck && null !== $assertion->check->error()) {
            $errors[] = $assertion->check->error();
        }

        if ([] !== $errors) {
            return [$errors, null, []];
        }

        $checker = new AssertionChecker($directory->root, $this->tools ?? new ToolRunner(), $this->lastRun($directory));
        [$current] = $checker->check([$assertion], $now);

        if (null !== $current->unverifiable) {
            return [[\sprintf('It cannot be checked on the project as it is: %s', $current->unverifiable)], null, []];
        }

        if ($current->failed()) {
            return [[\sprintf('It fails on the project as it is, where the rule is followed: %s', self::first($current))], null, []];
        }

        $warnings = [];

        if (!$assertion->check instanceof PatternCheck) {
            $warnings[] = \sprintf('%s passes on the project as it is; a %s assertion is not checked at a commit — make sure it fails where the defect is.', $assertion->id, $assertion->family());
        } elseif ([] === $brief->before) {
            $warnings[] = \sprintf('%s passes on the project as it is; no commit says where its feedback was reported, so nothing showed it fails there.', $assertion->id);
        }

        foreach ($assertion->check instanceof PatternCheck ? $brief->before : [] as $commit) {
            [$before] = $checker->check([$assertion], new CommitTree($directory->root, $commit, $this->git, $config->excludes()));

            if (!$before->failed()) {
                return [[\sprintf('It passes at %s too, where its feedback was reported: it does not tell the defect from its fix. If the defect was not committed then, assertions:propose --before=<commit> names one that has it.', $commit)], null, []];
            }
        }

        $assertions->save($assertion);
        $rules->save($rule->withAssertion($assertion->id));

        return [[], $assertion->id, $warnings];
    }

    private function lastRun(DevToolsDirectory $directory): ?string
    {
        $runs = glob($directory->path('runs/*/result.xml')) ?: [];
        sort($runs, \SORT_STRING);

        return array_last($runs);
    }

    private static function first(AssertionResult $result): string
    {
        $violation = $result->violations[0];

        return ltrim($violation->where().' '.$violation->found).(\count($result->violations) > 1 ? \sprintf(' (+%d)', \count($result->violations) - 1) : '');
    }
}
