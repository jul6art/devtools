<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

/**
 * A workflow page read back into its sections.
 */
final readonly class ParsedPage
{
    /**
     * @param array<string, string> $sections title => content, in the order of the page
     */
    public function __construct(public string $title, public string $header, public array $sections)
    {
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
        return $this->sections[$section->value] ?? null;
    }

    public function withSection(PageSection $section, string $content): self
    {
        return new self($this->title, $this->header, [...$this->sections, $section->value => $content]);
    }

    /**
     * The value of the "Préconditions" row of the trigger table — the one cell of a DevTools section Claude fills.
     */
    public function preconditions(): ?string
    {
        return 1 === preg_match('/^\| Préconditions \| (.*) \|$/m', $this->section(PageSection::Trigger) ?? '', $matches) ? $matches[1] : null;
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
        return null === $content ? null : (string) preg_replace('/^\| Préconditions \| .* \|$/m', '| Préconditions |', $content);
    }
}
