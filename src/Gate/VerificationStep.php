<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

use Jul6Art\DevTools\Verification\Finding;
use Jul6Art\DevTools\Verification\VerificationCollector;
use Jul6Art\DevTools\Verification\VerificationPreparer;
use Jul6Art\DevTools\Verification\VerificationVerdict;
use Jul6Art\DevTools\Verification\XmlVerificationStore;

/**
 * `verification` (ADR-0023, ADR-0024): a change to a template or a form is judged by the verifier, in a fresh
 * context. The folder is prepared once per state of the change; until its verdict is collected and approved, the
 * step fails and says what to do.
 */
final readonly class VerificationStep implements GateStepInterface
{
    /**
     * What a person sees: a template, a form.
     */
    private const string INTERFACE = '#(^|/)templates/|\.twig$|(^|/)Form/#';

    public function __construct(private VerificationPreparer $preparer = new VerificationPreparer())
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'verification';
    }

    #[\Override]
    public function run(GateContext $context): GateStep
    {
        if ([] === preg_grep(self::INTERFACE, $context->changed)) {
            return new GateStep($this->name(), notes: ['No template and no form changed.']);
        }

        $fingerprint = VerificationPreparer::fingerprint($context->directory->root, $context->changed);
        $folder = VerificationPreparer::folderOf($context->directory, $fingerprint) ?? $this->preparer->prepare($context->directory, $context->types, $context->changed, $context->at);

        if (null === $folder) {
            return new GateStep($this->name(), notes: ['The change reaches no route and no component.']);
        }

        $relative = $context->directory->root->relative($folder);

        if (!is_file($folder.'/'.VerificationCollector::OUTCOME)) {
            return new GateStep($this->name(), [\sprintf('Not verified yet: have the devtools-verifier subagent judge %s, write its answer as it is to %s/%s, then run devtools verify:collect.', $relative, $relative, VerificationCollector::VERDICT)]);
        }

        $outcome = new XmlVerificationStore()->readOutcome($folder.'/'.VerificationCollector::OUTCOME);

        return match ($outcome->verdict) {
            VerificationVerdict::Approved => new GateStep($this->name(), notes: [\sprintf('Approved — %s', $relative)]),
            VerificationVerdict::Rejected => new GateStep($this->name(), array_map(
                static fn (Finding $finding): string => \sprintf('%s · %s%s — %s', $finding->workflow, $finding->category, null === $finding->selector ? '' : ' · '.$finding->selector, $finding->text),
                array_values(array_filter($outcome->findings, static fn (Finding $finding): bool => $finding->isBlocking())),
            )),
            VerificationVerdict::Inconclusive => new GateStep($this->name(), array_map(static fn (string $reason): string => \sprintf('Inconclusive — %s (%s)', $reason, $relative), [] === $outcome->reasons ? ['the verifier could not judge'] : $outcome->reasons)),
        };
    }
}
