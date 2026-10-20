<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Visual;

/**
 * Pixel by pixel, with GD (ADR-0022): the share of the pixels that differ, and an image of where — the
 * baseline faded, the differing pixels in red. Two captures of different sizes differ entirely.
 */
final class ImageComparator
{
    /**
     * @param string|null $diffImage where to draw the difference, when there is one
     *
     * @return array{float, string|null} the percentage of the pixels that differ, and why it is total when the sizes do
     */
    public function compare(string $actual, string $baseline, ?string $diffImage = null): array
    {
        if (hash_file('sha256', $actual) === hash_file('sha256', $baseline)) {
            return [0.0, null];
        }

        $after = self::load($actual);
        $before = self::load($baseline);
        [$width, $height] = [imagesx($after), imagesy($after)];

        if ($width !== imagesx($before) || $height !== imagesy($before)) {
            return [100.0, \sprintf('the capture is %d×%d, its baseline %d×%d', $width, $height, imagesx($before), imagesy($before))];
        }

        $differing = [];

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                if (imagecolorat($after, $x, $y) !== imagecolorat($before, $x, $y)) {
                    $differing[] = [$x, $y];
                }
            }
        }

        if ([] !== $differing && null !== $diffImage) {
            self::draw($before, $differing, $diffImage);
        }

        return [round(\count($differing) * 100 / ($width * $height), 4), null];
    }

    /**
     * @param list<array{int, int}> $differing
     */
    private static function draw(\GdImage $baseline, array $differing, string $path): void
    {
        imagefilter($baseline, \IMG_FILTER_GRAYSCALE);
        imagefilter($baseline, \IMG_FILTER_BRIGHTNESS, 120);
        $red = (int) imagecolorallocate($baseline, 230, 0, 0);

        foreach ($differing as [$x, $y]) {
            imagesetpixel($baseline, $x, $y, $red);
        }

        imagepng($baseline, $path);
    }

    private static function load(string $path): \GdImage
    {
        $image = @imagecreatefrompng($path);

        if (!$image instanceof \GdImage) {
            throw new \RuntimeException(\sprintf('%s is not a PNG image.', basename($path)));
        }

        imagepalettetotruecolor($image);

        return $image;
    }
}
