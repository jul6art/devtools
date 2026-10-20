<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Stack\Knowledge;

use Jul6Art\DevTools\Ai\ApplyResult;
use Jul6Art\DevTools\Ai\DraftApplier;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\KnowledgeAmendCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\Knowledge\DepositOutcome;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeBrief;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeCanvas;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeProvider;
use Jul6Art\DevTools\Stack\Knowledge\LibraryEntry;
use Jul6Art\DevTools\Stack\Knowledge\XmlKnowledgeBriefStore;
use Jul6Art\DevTools\Tests\Inspection\Pipeline\FakeAdapter;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0044 § 1: a knowledge sheet that a project shows to be incomplete can be completed — on demand, never
 * by an inspection — and the completion reaches every other project as a revision, never as an overwrite.
 */
#[CoversClass(KnowledgeAmendCommand::class)]
#[CoversClass(KnowledgeBrief::class)]
#[CoversClass(XmlKnowledgeBriefStore::class)]
#[CoversClass(KnowledgeCanvas::class)]
#[CoversClass(KnowledgeLibrary::class)]
#[CoversClass(LibraryEntry::class)]
#[CoversClass(KnowledgeProvider::class)]
#[CoversClass(DraftApplier::class)]
final class KnowledgeAmendTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string BRIEF = '.devtools/pending/knowledge-amend.symfony-8.brief.xml';

    private const string DRAFT = '.devtools/pending/knowledge-amend.symfony-8.draft.md';

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        $this->inspect();
    }

    public function testTheBriefCarriesTheCurrentSheetAndWhatTheInspectionDidNotUnderstand(): void
    {
        // One workflow found by the static scan alone: its stack fell back at the last inspection.
        $tracking = $this->project.'/.devtools/workflows/routes/order.index.xml';
        file_put_contents($tracking, str_replace('<confidence>high</confidence>', '<confidence>medium</confidence>', (string) file_get_contents($tracking)));

        $tester = $this->amend();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString(self::BRIEF, $tester->getDisplay());

        $brief = new XmlKnowledgeBriefStore()->read($this->project.'/'.self::BRIEF);

        self::assertTrue($brief->amends());
        self::assertSame('symfony-8', $brief->key);
        self::assertSame('.devtools/knowledge/symfony-8.md', $brief->currentPath);
        self::assertSame(self::DRAFT, $brief->draftPath);
        self::assertFileExists($brief->promptPath);
        self::assertContains('src/Util/StringHelper.php', $brief->uncovered, 'The files no workflow references.');
        self::assertSame(['native:symfony answered with medium confidence for 1 workflow(s): its console could not be queried at the last inspection.'], $brief->fallbacks);
        self::assertSame([], $brief->withoutAdapter, 'The Symfony stack has its adapter.');
    }

    /**
     * An amendment is asked for, never triggered: the next inspection leaves its brief where it is.
     */
    public function testAnInspectionLeavesTheAmendmentBriefAlone(): void
    {
        $this->amend();
        $this->inspect();

        self::assertFileExists($this->project.'/'.self::BRIEF);
    }

    public function testASheetDevToolsDoesNotKnowCannotBeAmended(): void
    {
        $tester = $this->amend('fictional-9');

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No knowledge sheet "fictional-9" to amend', $tester->getDisplay());
    }

    /**
     * The whole sheet is rewritten, under the canvas — and an amendment adds: a section that shrinks is a
     * correction made by deleting, which the reader of the next project cannot see.
     */
    public function testASectionThatShrinksIsRefusedByName(): void
    {
        $this->amend();
        $current = (string) file_get_contents($this->project.'/.devtools/knowledge/symfony-8.md');
        $shrunk = (string) preg_replace('/(## Known traps\n\n).*?(\n## Sources)/s', "\$1- Short.\n\$2", $current);
        self::assertNotSame($current, $shrunk, 'The mutation was applied.');
        file_put_contents($this->project.'/'.self::DRAFT, $shrunk);

        $result = $this->apply();

        self::assertSame([], $result->accepted);
        self::assertStringContainsString('The section "Known traps" is shorter than in the current sheet', implode("\n", $result->refused['knowledge-amend.symfony-8'] ?? []));
        self::assertStringEqualsFile($this->project.'/.devtools/knowledge/symfony-8.md', $current, 'A refused amendment changes nothing.');
    }

    public function testADraftOutsideTheCanvasIsRefused(): void
    {
        $this->amend();
        file_put_contents($this->project.'/'.self::DRAFT, "# Symfony 8\n\n## Entry cycle\n\nonly that\n");

        self::assertStringContainsString('The section "Sources" is missing.', implode("\n", $this->apply()->refused['knowledge-amend.symfony-8'] ?? []));
    }

    /**
     * ADR-0041 never overwrites a sheet of the library: a revision does not overwrite, it succeeds. The
     * original stays, the index carries both, and the next project reads the revision.
     */
    public function testAnAcceptedAmendmentRewritesTheSheetAndIsDepositedAsARevision(): void
    {
        $library = KnowledgeLibrary::locate();
        $current = (string) file_get_contents($this->project.'/.devtools/knowledge/symfony-8.md');
        self::assertSame(DepositOutcome::Deposited, $library->deposit('symfony-8', $current, 'cereezer'), 'The library holds the original.');

        $this->amend();
        $amended = str_replace('## Known traps', "## Known traps\n\n- Voters are mechanisms: they decide access inside the routes asking for their attribute.\n", $current);
        file_put_contents($this->project.'/'.self::DRAFT, $amended);

        $result = $this->apply();

        self::assertSame(['knowledge-amend.symfony-8'], $result->accepted, implode("\n", array_merge(...array_values($result->refused)) ?: ['no refusal']));
        self::assertStringEqualsFile($this->project.'/.devtools/knowledge/symfony-8.md', $amended);
        self::assertSame([$library->path.'/symfony-8.r2.md'], array_values($result->deposited));
        self::assertStringEqualsFile($library->path.'/symfony-8.md', $current, 'The original is never overwritten.');
        self::assertStringEqualsFile($library->path.'/symfony-8.r2.md', $amended);
        self::assertSame(['symfony-8', 'symfony-8.r2'], array_keys($library->entries()));
        self::assertSame(2, $library->entries()['symfony-8.r2']->revision);
        self::assertSame(['symfony-8'], $library->keys(), 'A revision is not another stack.');
        self::assertSame($library->path.'/symfony-8.r2.md', $library->file('symfony-8'), 'The latest revision is the sheet.');
        self::assertFileDoesNotExist($this->project.'/'.self::BRIEF);
        self::assertFileDoesNotExist($this->project.'/'.self::DRAFT);

        $other = new DevToolsDirectory(new ProjectRoot($this->copyFixtureProject('plain-php')));
        new KnowledgeProvider(library: $library)->provide($other, 'symfony-8');
        self::assertStringEqualsFile($other->path('knowledge/symfony-8.md'), $amended, 'The next project reads the revision.');

        // The same amendment again is no new revision.
        $this->amend();
        file_put_contents($this->project.'/'.self::DRAFT, $amended);
        self::assertSame([], $this->apply()->deposited);
        self::assertSame(['symfony-8', 'symfony-8.r2'], array_keys($library->entries()));
    }

    private function amend(string $key = 'symfony-8'): CommandTester
    {
        $tester = new CommandTester(new Application()->find('knowledge:amend'));
        $tester->execute(['key' => $key, 'path' => $this->project]);

        return $tester;
    }

    private function apply(): ApplyResult
    {
        return new DraftApplier(new FrozenClock(new \DateTimeImmutable('2026-09-16T15:30:00+02:00')))->apply(new ProjectRoot($this->project));
    }

    private function inspect(): void
    {
        $report = new InspectionPipeline(new AdapterResolver([new FakeAdapter()]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($this->project));
        self::assertSame([], $report->errors, implode("\n", $report->errors));
    }
}
