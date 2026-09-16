<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Xml;

/**
 * A document DevTools reads is malformed, refused, or does not match its schema.
 *
 * The message always names the document and, when libxml gives one, the line: the document is often
 * a draft Claude wrote, and the line is what it needs to correct it.
 */
final class InvalidXml extends \RuntimeException
{
    public static function atLine(string $source, int $line, string $reason): self
    {
        return new self(\sprintf('%s, line %d: %s', $source, $line, $reason));
    }

    public static function refused(string $source, string $reason): self
    {
        return new self(\sprintf('%s: %s', $source, $reason));
    }
}
