<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

/**
 * A workflow page read back into its sections.
 */
final readonly class ParsedPage
{
    /**
     * The French headings pages carried before v3, mapped to the ones they became.
     *
     * ⚠️ **Reading them is what keeps the prose.** A page is re-rendered from the model plus the sections
     * Claude wrote in the CURRENT page: a heading the reader does not recognise is a section that comes
     * back empty, so renaming the template without this map would have emptied every page of every project
     * at its next inspection — silently, since a rewrite reports a rewrite either way.
     *
     * Nothing writes these headings any more; they are only ever read.
     */
    private const array LEGACY_HEADINGS = [
        'Résumé' => 'Summary',
        'Déclencheur' => 'Trigger',
        'Parcours' => 'Journey',
        'Navigation / états' => 'Navigation / states',
        'Décisions' => 'Decisions',
        'Données' => 'Data',
        'Mécanismes transverses' => 'Cross-cutting mechanisms',
        "Points d'attention" => 'Points of attention',
        'Workflows liés' => 'Related workflows',
        'Historique' => 'History',
        'États' => 'States',
    ];

    /**
     * @param array<string, string> $sections title => content, in the order of the page
     */
    public function __construct(public string $title, public string $header, public array $sections)
    {
    }

    /**
     * Whether this page still carries the headings of the template before v3.
     *
     * What the inspection does with it: rewrite the FILE in the current template, and nothing else — same
     * facts, same prose, same tracking. A page nobody touches otherwise never migrates on its own, and a
     * documentation half in one template and half in the other is worse than either.
     */
    public function usesLegacyHeadings(): bool
    {
        return array_any(array_keys($this->sections), static fn (string $title): bool => isset(self::LEGACY_HEADINGS[$title]));
    }

    /**
     * The content of a section by its title, falling back to the French heading a page written before v3
     * carries for it.
     */
    public function sectionNamed(string $title): ?string
    {
        if (isset($this->sections[$title])) {
            return $this->sections[$title];
        }

        $legacy = array_search($title, self::LEGACY_HEADINGS, true);

        return \is_string($legacy) ? $this->sections[$legacy] ?? null : null;
    }

    /**
     * What makes this page deviate from the template: missing, unknown or misordered sections.
     *
     * @return list<string>
     */
    public function conformityProblems(): array
    {
        $expected = array_map(static fn (PageSection $section): string => $section->value, PageSection::cases());
        $actual = array_keys($this->sections);
        $problems = [];

        foreach (array_diff($expected, $actual) as $missing) {
            $problems[] = \sprintf('The section "%s" is missing.', $missing);
        }

        foreach (array_diff($actual, $expected) as $unknown) {
            $problems[] = \sprintf('The section "%s" is not part of the template.', $unknown);
        }

        if ([] === $problems && $expected !== $actual) {
            $problems[] = 'The sections are not in the order of the template.';
        }

        if ('' === $this->title) {
            $problems[] = 'The page has no title.';
        }

        return $problems;
    }

    public function section(PageSection $section): ?string
    {
        return $this->sectionNamed($section->value);
    }

    public function withSection(PageSection $section, string $content): self
    {
        return new self($this->title, $this->header, [...$this->sections, $section->value => $content]);
    }

    /**
     * The value of the "Preconditions" row of the trigger table — the one cell of a DevTools section Claude fills.
     */
    public function preconditions(): ?string
    {
        return 1 === preg_match('/^\| Pr(?:é|e)conditions \| (.*) \|$/m', $this->section(PageSection::Trigger) ?? '', $matches) ? $matches[1] : null;
    }

    /**
     * The sections DevTools owns whose content differs from another rendering of the page: a hand edit where
     * DevTools writes facts.
     *
     * @return list<PageSection>
     */
    public function devToolsSectionsDifferingFrom(self $other): array
    {
        $differing = [];

        foreach (PageSection::cases() as $section) {
            if (!$section->writtenByClaude() && self::normalised($this, $section) !== self::normalised($other, $section)) {
                $differing[] = $section;
            }
        }

        return $differing;
    }

    private static function normalised(self $page, PageSection $section): ?string
    {
        $content = $page->section($section);

        // The preconditions cell belongs to Claude even inside the trigger table.
        return null === $content ? null : (string) preg_replace('/^\| Pr(?:é|e)conditions \| .* \|$/m', '| Preconditions |', $content);
    }
}
