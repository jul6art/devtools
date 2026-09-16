<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Xml;

/**
 * Loads and validates the XML documents DevTools reads, treating every one of them as untrusted.
 *
 * They come from an analysed repository — where a pull request can change them — or from a draft
 * Claude wrote. So a DOCTYPE is refused before parsing (no entity can ever be resolved), the network
 * is disabled, and libxml errors are turned into an exception that names the line instead of warnings.
 */
final class SafeXmlLoader
{
    public function load(string $xml, string $source): \DOMDocument
    {
        if (1 === preg_match('/<!DOCTYPE/i', $xml)) {
            throw InvalidXml::refused($source, 'a DOCTYPE is not allowed in a DevTools document.');
        }

        $document = new \DOMDocument();

        $this->withInternalErrors($source, static fn (): bool => '' !== $xml && $document->loadXML($xml, \LIBXML_NONET | \LIBXML_NOBLANKS));

        return $document;
    }

    /**
     * Loads a DevTools document, refuses one written by a newer DevTools, then validates it.
     *
     * The version is checked before the schema on purpose: a document from a newer release fails its
     * schema too, and "update DevTools" is the only useful thing to say about it.
     */
    public function loadValidated(string $xml, string $source, string $schemaPath, int $supportedVersion): \DOMDocument
    {
        $document = $this->load($xml, $source);
        $version = $document->documentElement?->getAttribute('schema-version') ?? '';

        if (ctype_digit($version) && (int) $version > $supportedVersion) {
            throw InvalidXml::refused($source, \sprintf('it was written by a newer version of DevTools (schema-version %s; this version reads up to %d). Update DevTools to read it.', $version, $supportedVersion));
        }

        $this->validate($document, $schemaPath, $source);

        return $document;
    }

    public function validate(\DOMDocument $document, string $schemaPath, string $source): void
    {
        $this->withInternalErrors($source, static fn (): bool => $document->schemaValidate($schemaPath, \LIBXML_NONET));
    }

    /**
     * @param callable(): bool $operation
     */
    private function withInternalErrors(string $source, callable $operation): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $succeeded = $operation();
            $error = libxml_get_errors()[0] ?? null;

            if ($error instanceof \LibXMLError) {
                throw InvalidXml::atLine($source, $error->line, trim($error->message));
            }

            if (!$succeeded) {
                throw InvalidXml::refused($source, 'the document could not be read.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
