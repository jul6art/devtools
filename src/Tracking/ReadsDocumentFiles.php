<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Xml\InvalidXml;

/**
 * @internal
 */
trait ReadsDocumentFiles
{
    private static function contentOf(string $path): string
    {
        $content = is_file($path) ? file_get_contents($path) : false;

        if (false === $content) {
            throw InvalidXml::refused($path, 'the file does not exist or cannot be read.');
        }

        return $content;
    }

    private static function date(\DateTimeImmutable $date): string
    {
        return $date->format(\DATE_ATOM);
    }

    private static function parseDate(string $value, string $source): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value) ?: throw InvalidXml::refused($source, \sprintf('"%s" is not an ISO 8601 date.', $value));
    }
}
