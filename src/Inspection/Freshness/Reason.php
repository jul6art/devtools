<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Freshness;

/**
 * Why a workflow is (re)written: an object, not a sentence, so that a report, a CI annotation and the
 * page's history line all say the same thing.
 */
final readonly class Reason
{
    /**
     * @param list<string>          $paths
     * @param array<string, string> $details
     */
    private function __construct(public ReasonKind $kind, public array $paths = [], public array $details = [])
    {
    }

    public static function noPreviousTracking(): self
    {
        return new self(ReasonKind::NoPreviousTracking);
    }

    public static function forced(): self
    {
        return new self(ReasonKind::Forced);
    }

    /**
     * @param list<string> $paths
     */
    public static function filesChanged(array $paths): self
    {
        return new self(ReasonKind::FilesChanged, $paths);
    }

    /**
     * @param list<string> $paths
     */
    public static function filesRemoved(array $paths): self
    {
        return new self(ReasonKind::FilesRemoved, $paths);
    }

    /**
     * @param list<string> $paths
     */
    public static function filesAdded(array $paths): self
    {
        return new self(ReasonKind::FilesAdded, $paths);
    }

    public static function packageMajor(string $package, string $from, string $to): self
    {
        return new self(ReasonKind::PackageMajor, [], ['package' => $package, 'from' => $from, 'to' => $to]);
    }

    public static function entryPointGone(): self
    {
        return new self(ReasonKind::EntryPointGone);
    }

    public static function entryPointBack(): self
    {
        return new self(ReasonKind::EntryPointBack);
    }

    /**
     * The line of the page's history, e.g. "files changed: src/Service/OrderPricing.php".
     */
    public function describe(): string
    {
        return match ($this->kind) {
            ReasonKind::NoPreviousTracking => 'initial',
            ReasonKind::Forced => 'forced',
            ReasonKind::FilesChanged => 'files changed: '.implode(', ', $this->paths),
            ReasonKind::FilesRemoved => 'files removed: '.implode(', ', $this->paths),
            ReasonKind::FilesAdded => 'files added: '.implode(', ', $this->paths),
            ReasonKind::PackageMajor => \sprintf('%s %s → %s', $this->details['package'] ?? '', $this->details['from'] ?? '', $this->details['to'] ?? ''),
            ReasonKind::EntryPointGone => 'entry point gone',
            ReasonKind::EntryPointBack => 'entry point back',
        };
    }
}
