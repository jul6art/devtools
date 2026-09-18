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
 * tests/Fixtures/demo/symfony-minimal/ is what a real Claude Code session produced on the fixture
 * application: inspect, one draft per brief following the page prompt, apply, inspect again with nothing
 * left to write. It stays valid and conform as formats evolve.
 *
 * The machinery lives in `.devtools/`, the pages in `docs/workflows/` — the two directories a project
 * commits.
 */
#[CoversNothing]
final class DemonstrationTest extends TestCase
{
    private const string DEMO = __DIR__.'/../Fixtures/demo/symfony-minimal';

    public function testEveryPageOfTheSessionIsWrittenValidAndConform(): void
    {
        $tracking = new XmlTrackingStore(new WorkflowTypeRegistry());
        $files = glob(self::DEMO.'/.devtools/workflows/*/*.xml') ?: [];

        self::assertCount(9, $files);

        foreach ($files as $file) {
            $document = $tracking->read($file);
            self::assertSame(GenerationMode::Ai, $document->generated->mode, $file);

            $page = new PageParser()->parse((string) file_get_contents(\sprintf('%s/docs/workflows/%s/%s.md', self::DEMO, $document->type->name, $document->id->pageName())));
            self::assertSame([], $page->conformityProblems(), $file);
            self::assertNotSame(MarkdownWriter::EMPTY, $page->section(PageSection::Summary), $file);
        }
    }

    /**
     * The point of the whole ADR-0043: the session drew the logic that decides a field's value, and the
     * page carries neither the file inventory nor the test inventory any more.
     */
    public function testTheSessionDrewTheDecisionsAndListedNoInventory(): void
    {
        $page = (string) file_get_contents(self::DEMO.'/docs/workflows/routes/order.new.md');

        self::assertStringContainsString('**`App\\Entity\\Order::status`**', $page);
        self::assertStringContainsString('flowchart TD', $page);
        self::assertStringNotContainsString('## Composants impliqués', $page);
        self::assertStringNotContainsString('## Tests existants', $page);
    }
}
