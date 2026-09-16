<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Ai;

use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Rendering\MarkdownWriter;
use Jul6Art\DevTools\Rendering\PageParser;
use Jul6Art\DevTools\Rendering\PageSection;
use Jul6Art\DevTools\Tracking\GenerationMode;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * tests/Fixtures/demo/symfony-minimal/.devtools/ is what a real Claude Code session produced on the fixture
 * application (2026-09-17): inspect, one draft per brief following prompt page/1, apply, inspect again with
 * nothing left to write. It stays valid and conform as formats evolve.
 */
#[CoversNothing]
final class DemonstrationTest extends TestCase
{
    public function testEveryPageOfTheSessionIsWrittenValidAndConform(): void
    {
        $tracking = new XmlTrackingStore(new WorkflowTypeRegistry());
        $files = glob(__DIR__.'/../Fixtures/demo/symfony-minimal/.devtools/workflows/*/*.xml') ?: [];

        self::assertCount(10, $files);

        foreach ($files as $file) {
            self::assertSame(GenerationMode::Ai, $tracking->read($file)->generated->mode, $file);

            $page = new PageParser()->parse((string) file_get_contents(substr($file, 0, -4).'.md'));
            self::assertSame([], $page->conformityProblems(), $file);
            self::assertNotSame(MarkdownWriter::EMPTY, $page->section(PageSection::Summary), $file);
        }
    }
}
