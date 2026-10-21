<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Verification;

use Dom\Element;
use Dom\HTMLDocument;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * `verify:collect` (ADR-0024): the verdict the verifier gave, checked against the folder it was given — a
 * workflow it was shown, a category of the closed list, a selector the captured page has. What cannot be
 * checked counts as `inconclusive`, never as approved. The outcome is written beside it, for the gate.
 */
final readonly class VerificationCollector
{
    public const string VERDICT = 'verdict.xml';

    public const string OUTCOME = 'outcome.xml';

    public function __construct(private XmlVerificationStore $store = new XmlVerificationStore())
    {
    }

    public function collect(string $folder, \DateTimeImmutable $at): Outcome
    {
        $outcome = $this->judge($folder, $at);
        $this->store->writeOutcome($folder.'/'.self::OUTCOME, $outcome);

        return $outcome;
    }

    private function judge(string $folder, \DateTimeImmutable $at): Outcome
    {
        if (!is_file($folder.'/'.self::VERDICT)) {
            return new Outcome(VerificationVerdict::Inconclusive, $at, ['No verdict: the answer of the verifier goes in '.self::VERDICT.' of the folder.']);
        }

        try {
            [$verdict, $findings] = $this->store->readVerdict($folder.'/'.self::VERDICT);
        } catch (InvalidXml $invalid) {
            return new Outcome(VerificationVerdict::Inconclusive, $at, [$invalid->getMessage()]);
        }

        [$pages, $categories] = self::manifest($folder);
        $reasons = [];

        foreach ($findings as $finding) {
            $reasons = [...$reasons, ...self::check($finding, $pages, $categories, $folder)];
        }

        $blocking = array_any($findings, static fn (Finding $finding): bool => $finding->isBlocking());

        if (VerificationVerdict::Approved === $verdict && $blocking) {
            $reasons[] = 'Approved with a blocking finding: one of the two is wrong.';
        }

        if (VerificationVerdict::Rejected === $verdict && !$blocking) {
            $reasons[] = 'Rejected without a blocking finding: nothing says what to fix.';
        }

        return new Outcome([] === $reasons ? $verdict : VerificationVerdict::Inconclusive, $at, $reasons, $findings);
    }

    /**
     * @param array<string, list<string>> $pages      workflow => the DOMs captured for it
     * @param list<string>                $categories the categories the folder was prepared with
     *
     * @return list<string> what is wrong with the finding
     */
    private static function check(Finding $finding, array $pages, array $categories, string $folder): array
    {
        if (!isset($pages[$finding->workflow])) {
            return [\sprintf('"%s" was not in the folder: the verifier judged a workflow it was not shown.', $finding->workflow)];
        }

        $reasons = \in_array($finding->category, $categories, true) ? [] : [\sprintf('"%s" is not a category of feedback: %s.', $finding->category, implode(', ', $categories))];

        if (null === $finding->selector) {
            return $reasons;
        }

        if ([] === $pages[$finding->workflow]) {
            return [...$reasons, \sprintf('"%s" names %s, but no page of %s was captured: run its scenarios first.', $finding->workflow, $finding->selector, $finding->workflow)];
        }

        foreach ($pages[$finding->workflow] as $page) {
            try {
                if (HTMLDocument::createFromString((string) file_get_contents($folder.'/'.$page), \LIBXML_NOERROR)->querySelector($finding->selector) instanceof Element) {
                    return $reasons;
                }
            } catch (\DOMException) {
                return [...$reasons, \sprintf('"%s" is not a CSS selector.', $finding->selector)];
            }
        }

        return [...$reasons, \sprintf('%s is in no page captured for %s.', $finding->selector, $finding->workflow)];
    }

    /**
     * @return array{array<string, list<string>>, list<string>} workflow => the DOMs captured for it, relative to the
     *                                                          folder; and the categories a finding may have
     */
    private static function manifest(string $folder): array
    {
        $dom = new DomBuilder(VerificationPreparer::NAMESPACE);
        $manifest = $folder.'/manifest.xml';
        $root = DomBuilder::root(new SafeXmlLoader()->loadValidated((string) file_get_contents($manifest), $manifest, Resources::path('schemas/verification-brief.xsd'), 1));
        $pages = [];

        foreach ($dom->children($root, 'workflow') as $workflow) {
            $pages[$workflow->getAttribute('id')] = array_map(static fn (\DOMElement $capture): string => $capture->getAttribute('dom'), $dom->children($workflow, 'capture'));
        }

        return [$pages, array_map(static fn (\DOMElement $category): string => trim($category->textContent), $dom->children($root, 'category'))];
    }
}
