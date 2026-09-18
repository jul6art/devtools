<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Support;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CoverageThresholdsTest extends TestCase
{
    public function testEveryFloorReachedGivesNoFailure(): void
    {
        self::assertSame([], new CoverageThresholds('/project')->failures(self::clover([
            'src/Rendering/PageRenderer.php' => [100, 91],
            'src/Inspection/Freshness/FreshnessResolver.php' => [40, 40],
            'src/Inspection/Diff/WorkflowDiffer.php' => [30, 30],
            'src/Review/ChangeReview.php' => [20, 20],
            'src/Tracking/Index.php' => [10, 10],
            'src/Bridge/Symfony/DevToolsBundle.php' => [50, 0],
        ])));
    }

    public function testAFloorMissedIsNamedWithItsFigures(): void
    {
        self::assertSame(
            [
                'src/: 89.00 % of 200 statements covered, 90 % required.',
                'src/Inspection/Freshness/: no statement in the report.',
                'src/Inspection/Diff/: no statement in the report.',
                'src/Review/: no statement in the report.',
                'src/Tracking/: 99.00 % of 100 statements covered, 100 % required.',
            ],
            new CoverageThresholds('/project/')->failures(self::clover([
                'src/Rendering/PageRenderer.php' => [100, 79],
                'src/Tracking/Index.php' => [100, 99],
                'src/Inspection/Freshness/Reason.php' => [0, 0],
            ])),
        );
    }

    public function testAnInvalidReportFails(): void
    {
        self::assertSame(['The coverage report is not valid XML.'], new CoverageThresholds('/project')->failures(''));
        self::assertSame(['The coverage report is not valid XML.'], new CoverageThresholds('/project')->failures('<coverage>'));
    }

    /**
     * @param array<string, array{int, int}> $files path => [statements, covered]
     */
    private static function clover(array $files): string
    {
        $xml = '<?xml version="1.0"?><coverage><project>';

        foreach ($files as $path => [$statements, $covered]) {
            $xml .= \sprintf('<file name="/project/%s"><metrics statements="%d" coveredstatements="%d"/></file>', $path, $statements, $covered);
        }

        return $xml.'</project></coverage>';
    }
}
