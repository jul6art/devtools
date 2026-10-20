<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Tracking;

use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AtomicFileWriter::class)]
final class AtomicFileWriterTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testItCreatesMissingDirectoriesAndReportsTheChange(): void
    {
        $path = $this->temporaryDirectory().'/a/b/file.xml';

        self::assertTrue(new AtomicFileWriter()->write($path, 'content'));
        self::assertStringEqualsFile($path, 'content');
    }

    public function testAnExistingFileWithOtherContentIsReplaced(): void
    {
        $path = $this->temporaryDirectory().'/index.xml';
        file_put_contents($path, 'previous');

        self::assertTrue(new AtomicFileWriter()->write($path, 'next'));
        self::assertSame(['index.xml' => 'next'], self::tree($this->temporaryDirectory()), 'Replaced in place, no temporary file left.');
    }

    public function testIdenticalContentIsNotRewritten(): void
    {
        $path = $this->temporaryDirectory().'/file.xml';
        file_put_contents($path, 'content');
        touch($path, 1_000_000);
        clearstatcache();

        self::assertFalse(new AtomicFileWriter()->write($path, 'content'));
        clearstatcache();
        self::assertSame(1_000_000, filemtime($path));
    }

    /**
     * A directory the writer cannot write to: the failure is named, never a silent `false` that callers
     * read as "unchanged".
     */
    public function testATemporaryFileThatCannotBeWrittenIsAnError(): void
    {
        $directory = $this->temporaryDirectory().'/locked';
        mkdir($directory);
        file_put_contents($directory.'/index.xml', 'previous');
        chmod($directory, 0o555);
        // The warning PHP raises before file_put_contents() returns false is the cause, not the outcome.
        set_error_handler(static fn (): bool => true, \E_WARNING);

        try {
            new AtomicFileWriter()->write($directory.'/index.xml', 'next');
            self::fail('A write that did not happen must not pass for one.');
        } catch (\RuntimeException $failure) {
            self::assertStringStartsWith(\sprintf('Could not write "%s/.index.xml.', $directory), $failure->getMessage());
        } finally {
            restore_error_handler();
            chmod($directory, 0o755);
        }

        self::assertStringEqualsFile($directory.'/index.xml', 'previous');
    }

    public function testAnInterruptedWriteLeavesThePreviousDocumentIntact(): void
    {
        $path = $this->temporaryDirectory().'/index.xml';
        file_put_contents($path, 'previous');

        $writer = new AtomicFileWriter(afterTemporaryWrite: static function (): never {
            throw new \RuntimeException('Simulated crash between the temporary write and the rename.');
        });

        try {
            $writer->write($path, 'next');
            self::fail('The simulated crash must propagate.');
        } catch (\RuntimeException) {
        }

        self::assertStringEqualsFile($path, 'previous');
        self::assertSame(['index.xml' => 'previous'], self::tree($this->temporaryDirectory()), 'No temporary file is left behind.');
    }
}
