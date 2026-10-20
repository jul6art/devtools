<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Scenario\Visual;

use Jul6Art\DevTools\Config\InvalidConfig;
use Jul6Art\DevTools\Config\VisualSettings;
use Jul6Art\DevTools\Scenario\Visual\DomDiff;
use Jul6Art\DevTools\Scenario\Visual\DomNormalizer;
use Jul6Art\DevTools\Scenario\Visual\ImageComparator;
use Jul6Art\DevTools\Scenario\Visual\VisualComparator;
use Jul6Art\DevTools\Scenario\Visual\VisualResult;
use Jul6Art\DevTools\Scenario\Visual\VisualVerdict;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0022: a capture against its baseline — the image says where, the DOM says what — and the verdicts that
 * follow, with GD and without.
 */
#[CoversClass(ImageComparator::class)]
#[CoversClass(DomNormalizer::class)]
#[CoversClass(DomDiff::class)]
#[CoversClass(VisualComparator::class)]
#[CoversClass(VisualResult::class)]
#[CoversClass(VisualVerdict::class)]
#[CoversClass(VisualSettings::class)]
#[RequiresPhpExtension('gd')]
final class VisualComparisonTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const string PAGE = '<!DOCTYPE html><html><head><title>Order</title><script>var t = 1;</script></head><body><form><label for="product">Product</label><select id="product" name="order[product]"><option>Widget</option></select><input type="hidden" name="order[_token]" value="TOKEN"></form></body></html>';

    public function testTwoImagesWithTheSamePixelsAreIdenticalWhateverTheirBytes(): void
    {
        $a = $this->png('a', 200, 100);
        $b = $this->png('b', 200, 100, compression: 0);

        self::assertNotSame(hash_file('sha256', $a), hash_file('sha256', $b), 'Different bytes.');
        self::assertSame([0.0, null], new ImageComparator()->compare($a, $b));
        self::assertSame([0.0, null], new ImageComparator()->compare($a, $a));
    }

    public function testTheShareOfThePixelsThatDifferIsMeasuredAndDrawn(): void
    {
        $before = $this->png('before', 200, 100);
        $after = $this->png('after', 200, 100, [[10, 10, 19, 19]]);
        $diff = $this->temporaryDirectory().'/diff.png';

        self::assertSame([0.5, null], new ImageComparator()->compare($after, $before, $diff), '100 pixels of 20,000.');

        $drawn = imagecreatefrompng($diff);
        self::assertInstanceOf(\GdImage::class, $drawn);
        self::assertSame([230, 0, 0], self::rgb($drawn, 15, 15), 'A differing pixel in red.');
        self::assertNotSame([230, 0, 0], self::rgb($drawn, 100, 50), 'The others faded.');
    }

    public function testTwoSizesDifferEntirely(): void
    {
        self::assertSame([100.0, 'the capture is 200×100, its baseline 200×120'], new ImageComparator()->compare($this->png('small', 200, 100), $this->png('tall', 200, 120)));
    }

    public function testWhatIsNotAPngIsSaid(): void
    {
        file_put_contents($this->temporaryDirectory().'/broken.png', 'not an image');

        $this->expectExceptionMessage('broken.png is not a PNG image.');

        new ImageComparator()->compare($this->temporaryDirectory().'/broken.png', $this->png('a', 10, 10));
    }

    /**
     * The verdicts: no baseline, identical, under the threshold, over it.
     */
    public function testEachVerdictFollowsTheThreshold(): void
    {
        $comparator = new VisualComparator($this->temporaryDirectory().'/baselines', new VisualSettings(1.0));

        $this->capture('run', 'route.a/s/home', [], self::PAGE);
        self::assertSame(VisualVerdict::NoBaseline, $comparator->compare($this->temporaryDirectory().'/run', 'route.a/s/home')->verdict);

        $this->capture('baselines', 'route.a/s/home', [], self::PAGE, '.dom.html');
        $identical = $comparator->compare($this->temporaryDirectory().'/run', 'route.a/s/home');
        self::assertEquals(new VisualResult(VisualVerdict::Identical, 0.0), $identical);

        $this->capture('run', 'route.a/s/home', [[0, 0, 9, 9]], self::PAGE);
        $within = $comparator->compare($this->temporaryDirectory().'/run', 'route.a/s/home');
        self::assertSame(VisualVerdict::WithinThreshold, $within->verdict);
        self::assertSame(0.5, $within->difference);
        self::assertSame('route.a/s/home.diff.png', $within->diffImage);

        $this->capture('run', 'route.a/s/home', [[0, 0, 99, 9]], self::PAGE);
        $different = $comparator->compare($this->temporaryDirectory().'/run', 'route.a/s/home');
        self::assertSame(VisualVerdict::Different, $different->verdict);
        self::assertSame('5 % of the pixels differ from the baseline (threshold 1 %).', $different->summary);
        self::assertNull($different->domDiff, 'The same DOM: no DOM diff.');
    }

    /**
     * What the image cannot say: a select became an input.
     */
    public function testTheDomDiffNamesASelectThatBecameAnInput(): void
    {
        $comparator = new VisualComparator($this->temporaryDirectory().'/baselines', new VisualSettings(0.0));
        $this->capture('baselines', 'route.a/s/form', [], self::PAGE, '.dom.html');
        $this->capture('run', 'route.a/s/form', [[0, 0, 99, 49]], str_replace('<select id="product" name="order[product]"><option>Widget</option></select>', '<input id="product" name="order[product]">', self::PAGE));

        $result = $comparator->compare($this->temporaryDirectory().'/run', 'route.a/s/form');

        self::assertSame(VisualVerdict::Different, $result->verdict);
        self::assertSame('25 % of the pixels differ from the baseline (threshold 0 %); the DOM changed: <select id="product" name="order[product]"> became <input id="product" name="order[product]">.', $result->summary);
        self::assertSame('route.a/s/form.dom.diff', $result->domDiff);
        self::assertMatchesRegularExpression('/^- +<select id="product" name="order\[product\]">$/m', (string) file_get_contents($this->temporaryDirectory().'/run/route.a/s/form.dom.diff'));
    }

    /**
     * Without GD, the DOM alone decides — and the run says so.
     */
    public function testWithoutGdTheDomAloneDecides(): void
    {
        $comparator = new VisualComparator($this->temporaryDirectory().'/baselines', images: false);
        $this->capture('baselines', 'route.a/s/home', [], self::PAGE, '.dom.html');
        $this->capture('run', 'route.a/s/home', [[0, 0, 99, 49]], self::PAGE);

        self::assertSame(VisualVerdict::Identical, $comparator->compare($this->temporaryDirectory().'/run', 'route.a/s/home')->verdict, 'Pixels differ, nobody looks at them.');
        self::assertStringContainsString('ext-gd is not loaded', (string) $comparator->warning());
        self::assertNull(VisualComparator::forBaselines('/nowhere')->warning());

        $this->capture('run', 'route.a/s/home', [], str_replace('Product', 'Item', self::PAGE));
        $changed = $comparator->compare($this->temporaryDirectory().'/run', 'route.a/s/home');

        self::assertSame(VisualVerdict::Different, $changed->verdict);
        self::assertSame('The DOM changed: "Product" became "Item"', $changed->summary);
        self::assertNull($changed->difference);
    }

    /**
     * Structure only: attributes in order, no script, whitespace collapsed, and what a framework draws anew on
     * each request — a token, a generated identifier — masked.
     */
    public function testTheDomIsNormalisedToItsStructure(): void
    {
        $normalizer = new DomNormalizer();
        $lines = $normalizer->normalize('<html><body><div   class="a"  id="select2-product-result-a3f9c1">  Hello
            world </div><script>alert(1)</script><input value="abc" type="hidden" name="_csrf_token"><meta name="csrf-token" content="x"></body></html>');

        self::assertSame([
            '<html>',
            '  <head>',
            '  <body>',
            '    <div class="a" id="select2-product-result-#">',
            '      "Hello world"',
            '    <input name="_csrf_token" type="hidden" value="…">',
            '    <meta content="x" name="csrf-token">',
        ], $lines);
        self::assertSame($normalizer->normalize('<p id="item12">x</p>'), $normalizer->normalize('<p id="item98">x</p>'));
    }

    public function testADiffLeavesWhatBothShareOut(): void
    {
        $differ = new DomDiff();

        self::assertSame([], $differ->diff(['a', 'b'], ['a', 'b']));
        self::assertSame(['- b', '+ c'], $differ->diff(['a', 'b', 'd'], ['a', 'c', 'd']));
        self::assertSame(['+ x'], $differ->diff(['a'], ['a', 'x']));
        self::assertSame(['- a'], $differ->diff(['a', 'b'], ['b']));
        self::assertSame(['- b', '@@', '+ e'], $differ->diff(['a', 'b', 'c', 'd'], ['a', 'c', 'd', 'e']), 'Two blocks that do not touch.');
        self::assertNull($differ->summary([]));
        self::assertSame('added x', $differ->summary(['+ x']));
        self::assertSame('removed b; added e', $differ->summary(['- b', '@@', '+ e']));
        self::assertSame('removed a; removed b; removed c; and more in the DOM diff', $differ->summary(['- a', '@@', '- b', '@@', '- c', '@@', '- d']));
    }

    public function testAThresholdIsAPercentage(): void
    {
        $this->expectException(InvalidConfig::class);

        new VisualSettings(101.0);
    }

    /**
     * @param int<1, max>                     $width
     * @param int<1, max>                     $height
     * @param list<array{int, int, int, int}> $marks  rectangles drawn in black
     */
    private function png(string $name, int $width, int $height, array $marks = [], int $compression = 6): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 250, 250, 250));

        foreach ($marks as [$x1, $y1, $x2, $y2]) {
            imagefilledrectangle($image, $x1, $y1, $x2, $y2, (int) imagecolorallocate($image, 0, 0, 0));
        }

        $path = $this->temporaryDirectory().'/'.$name.'.png';
        imagepng($image, $path, $compression);

        return $path;
    }

    /**
     * A capture of 200×100 and its DOM, under `<directory>/<capture>`.
     *
     * @param list<array{int, int, int, int}> $marks
     */
    private function capture(string $directory, string $capture, array $marks, string $html, string $domExtension = '.html'): void
    {
        $path = $this->temporaryDirectory().'/'.$directory.'/'.$capture;

        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }

        copy($this->png(bin2hex(random_bytes(4)), 200, 100, $marks), $path.'.png');
        file_put_contents($path.$domExtension, $html);
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(\GdImage $image, int $x, int $y): array
    {
        $color = imagecolorat($image, $x, $y);

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }
}
