<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Visual;

use Jul6Art\DevTools\Config\VisualSettings;

/**
 * A capture of a run against the baseline a human accepted (ADR-0022): the image says where it changed, the
 * DOM says what. Without GD, the images are not compared and the DOM alone decides — said once, as a
 * warning.
 */
final readonly class VisualComparator
{
    public function __construct(
        private string $baselines,
        private VisualSettings $settings = new VisualSettings(),
        private bool $images = true,
        private ImageComparator $comparator = new ImageComparator(),
        private DomNormalizer $normalizer = new DomNormalizer(),
        private DomDiff $differ = new DomDiff(),
    ) {
    }

    /**
     * Images are compared when GD is there.
     */
    public static function forBaselines(string $baselines, VisualSettings $settings = new VisualSettings()): self
    {
        return new self($baselines, $settings, \extension_loaded('gd'));
    }

    /**
     * @return list<string> the CSS selectors hidden before a capture
     */
    public function masks(): array
    {
        return $this->settings->masks;
    }

    public function warning(): ?string
    {
        return $this->images ? null : 'ext-gd is not loaded: the captures were not compared with their baselines pixel by pixel, the DOM alone decided.';
    }

    /**
     * @param string $artifacts the run's directory
     * @param string $capture   `<scenario-id>/<name>`, relative to it, without extension
     */
    public function compare(string $artifacts, string $capture): VisualResult
    {
        $baseline = $this->baselines.'/'.$capture;

        if (!is_file($baseline.'.png') || !is_file($baseline.'.dom.html')) {
            return new VisualResult(VisualVerdict::NoBaseline);
        }

        $diff = $this->differ->diff($this->normalizer->normalize((string) file_get_contents($baseline.'.dom.html')), $this->normalizer->normalize((string) file_get_contents($artifacts.'/'.$capture.'.html')));
        $domDiff = null;

        if ([] !== $diff) {
            $domDiff = $capture.'.dom.diff';
            file_put_contents($artifacts.'/'.$domDiff, implode("\n", $diff)."\n");
        }

        $dom = $this->differ->summary($diff);

        if (!$this->images) {
            return new VisualResult([] === $diff ? VisualVerdict::Identical : VisualVerdict::Different, null, null, $domDiff, null === $dom ? null : 'The DOM changed: '.$dom);
        }

        [$difference, $why] = $this->comparator->compare($artifacts.'/'.$capture.'.png', $baseline.'.png', $artifacts.'/'.$capture.'.diff.png');
        $verdict = match (true) {
            0.0 === $difference => VisualVerdict::Identical,
            null === $why && $difference <= $this->settings->threshold => VisualVerdict::WithinThreshold,
            default => VisualVerdict::Different,
        };
        $pixels = match (true) {
            null !== $why => ucfirst($why),
            0.0 === $difference => null,
            default => \sprintf('%s %% of the pixels differ from the baseline (threshold %s %%)', self::percent($difference), self::percent($this->settings->threshold)),
        };
        $summary = implode('; ', array_filter([$pixels, null === $dom ? null : 'the DOM changed: '.$dom]));

        return new VisualResult($verdict, $difference, is_file($artifacts.'/'.$capture.'.diff.png') ? $capture.'.diff.png' : null, $domDiff, '' === $summary ? null : $summary.'.');
    }

    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
