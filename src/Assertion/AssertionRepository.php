<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;

/**
 * The assertions of a project (ADR-0027): `.devtools/assertions/<id>.xml`, found from the rules that reference
 * them — no index of their own.
 */
final readonly class AssertionRepository
{
    public function __construct(private DevToolsDirectory $directory, private AtomicFileWriter $writer = new AtomicFileWriter())
    {
    }

    public function has(string $id): bool
    {
        return is_file($this->file($id));
    }

    /**
     * @throws InvalidXml when it is missing or unreadable
     */
    public function get(string $id): Assertion
    {
        return new XmlAssertionStore($this->writer)->read($this->file($id));
    }

    public function save(Assertion $assertion): void
    {
        new XmlAssertionStore($this->writer)->write($this->file($assertion->id), $assertion);
    }

    private function file(string $id): string
    {
        return $this->directory->assertionsDirectory().'/'.$id.'.xml';
    }
}
