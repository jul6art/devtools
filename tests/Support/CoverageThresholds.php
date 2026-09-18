<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Support;

/**
 * The coverage floors of ADR-0015, checked against a Clover report: more than 90 % of the statements of
 * `src/` outside the bridge, all of them in the directories that **decide** — `Inspection/Freshness/`
 * and `Tracking/` (ADR-0010), `Inspection/Diff/` and `Review/` (ADR-0046, ADR-0047).
 *
 * ⚠️ A wrong « nothing changed » is worse than a needless rewrite, and a wrong « no drift » is worse
 * than a needless review: both are silences, and a silence is what nobody comes back to check.
 */
final readonly class CoverageThresholds
{
    /**
     * @var array<string, float> directory relative to the project => minimum percentage of covered statements
     */
    public const array FLOORS = [
        'src/' => 90.0,
        'src/Inspection/Freshness/' => 100.0,
        'src/Inspection/Diff/' => 100.0,
        'src/Review/' => 100.0,
        'src/Tracking/' => 100.0,
    ];

    private const string EXCLUDED = 'src/Bridge/';

    public function __construct(private string $projectDirectory)
    {
    }

    /**
     * @return list<string> one line per floor not reached; empty when all are
     */
    public function failures(string $cloverXml): array
    {
        $document = new \DOMDocument();

        if ('' === trim($cloverXml) || !@$document->loadXML($cloverXml, \LIBXML_NONET)) {
            return ['The coverage report is not valid XML.'];
        }

        $totals = array_fill_keys(array_keys(self::FLOORS), ['statements' => 0, 'covered' => 0]);

        foreach ($document->getElementsByTagName('file') as $file) {
            $path = ltrim(substr($file->getAttribute('name'), \strlen(rtrim($this->projectDirectory, '/'))), '/');
            $metrics = $file->getElementsByTagName('metrics')->item(0);

            if (!$metrics instanceof \DOMElement || str_starts_with($path, self::EXCLUDED)) {
                continue;
            }

            foreach (array_keys(self::FLOORS) as $directory) {
                if (str_starts_with($path, $directory)) {
                    $totals[$directory]['statements'] += (int) $metrics->getAttribute('statements');
                    $totals[$directory]['covered'] += (int) $metrics->getAttribute('coveredstatements');
                }
            }
        }

        $failures = [];

        foreach (self::FLOORS as $directory => $floor) {
            ['statements' => $statements, 'covered' => $covered] = $totals[$directory];
            // A directory absent from the report is a report that measured the wrong thing, not a pass.
            if (0 === $statements) {
                $failures[] = \sprintf('%s: no statement in the report.', $directory);

                continue;
            }

            $percentage = 100 * $covered / $statements;

            if ($percentage < $floor) {
                $failures[] = \sprintf('%s: %.2f %% of %d statements covered, %.0f %% required.', $directory, $percentage, $statements, $floor);
            }
        }

        return $failures;
    }
}
