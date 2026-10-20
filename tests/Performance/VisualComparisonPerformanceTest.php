<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Performance;

use Jul6Art\DevTools\Config\VisualSettings;
use Jul6Art\DevTools\Scenario\Visual\VisualComparator;
use Jul6Art\DevTools\Scenario\Visual\VisualVerdict;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0022: under 200 ms to compare a 1280×800 capture with its baseline — the worst case, where a change
 * makes every pixel be read, a page of text drawn on both and a block that moved.
 */
#[CoversNothing]
#[Group('performance')]
#[RequiresPhpExtension('gd')]
final class VisualComparisonPerformanceTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testA1280By800CaptureIsComparedInUnder200Milliseconds(): void
    {
        $directory = $this->temporaryDirectory();
        mkdir($directory.'/baselines/route.a/s', 0o777, true);
        mkdir($directory.'/run/route.a/s', 0o777, true);
        $this->page($directory.'/baselines/route.a/s/home', 40);
        $this->page($directory.'/run/route.a/s/home', 60);

        $started = microtime(true);
        $result = new VisualComparator($directory.'/baselines', new VisualSettings(0.1))->compare($directory.'/run', 'route.a/s/home');
        $milliseconds = 1000 * (microtime(true) - $started);

        self::assertSame(VisualVerdict::Different, $result->verdict);
        self::assertLessThan(200.0, $milliseconds, \sprintf('The comparison took %.0f ms.', $milliseconds));
        fwrite(\STDERR, \sprintf("\n[performance] a 1280×800 capture against its baseline: %.0f ms\n", $milliseconds));
    }

    private function page(string $path, int $block): void
    {
        $image = imagecreatetruecolor(1280, 800);
        imagefilledrectangle($image, 0, 0, 1279, 799, (int) imagecolorallocate($image, 248, 248, 248));

        for ($line = 0; $line < 45; ++$line) {
            imagestring($image, 4, 20, 10 + 17 * $line, str_repeat('Order 42 — Widget XL — 3 × 19.90 EUR  ', 4), (int) imagecolorallocate($image, 30, 30, 30));
        }

        imagefilledrectangle($image, 900, $block, 1200, $block + 120, (int) imagecolorallocate($image, 14, 165, 233));
        imagepng($image, $path.'.png');
        file_put_contents($path.'.dom.html', '<p>Order 42</p>');
        file_put_contents($path.'.html', '<p>Order 42</p>');
    }
}
