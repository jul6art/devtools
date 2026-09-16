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
