<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Stack\Knowledge;

use Jul6Art\DevTools\Ai\ApplyResult;
use Jul6Art\DevTools\Ai\DraftApplier;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Stack\Knowledge\DepositOutcome;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeProvider;
use Jul6Art\DevTools\Stack\Knowledge\LibraryEntry;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0041: what Claude writes once for a stack is deposited in a library every other project reads,
 * instead of being asked again per project.
 */
#[CoversClass(KnowledgeLibrary::class)]
#[CoversClass(KnowledgeProvider::class)]
#[CoversClass(LibraryEntry::class)]
#[CoversClass(DepositOutcome::class)]
final class KnowledgeLibraryTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string SHEET = "# Fictional 9\n\n## Cycle d'entrée\n\nx\n\n## Mécanismes d'extension\n\nx\n\n## Injection de dépendances et conventions de nommage\n\nx\n\n## Points d'entrée par type\n\nx\n\n## Tests\n\nx\n\n## Pièges connus\n\nx\n\n## Sources\n\n- https://example.test/docs\n";

    public function testTheEnvironmentVariableWinsOverEverythingElse(): void
    {
        self::assertSame($this->temporaryDirectory().'/knowledge-library', KnowledgeLibrary::locate()->path, 'The suite points every test at its own library.');
        self::assertSame($this->temporaryDirectory().'/knowledge-library', KnowledgeLibrary::locate('/somewhere/else')->path);
    }

    public function testTheConfiguredLibraryWinsOverThePackageAndIsRelativeToTheProject(): void
    {
        $this->withoutTheEnvironmentVariable(function (): void {
            $project = $this->copyFixtureProject('symfony-minimal');
            $root = new ProjectRoot($project);

            self::assertSame($project.'/var/kb', KnowledgeLibrary::forProject($root, new Config(knowledgeLibrary: 'var/kb'))->path);
            self::assertSame('/absolute/kb', KnowledgeLibrary::forProject($root, new Config(knowledgeLibrary: '/absolute/kb'))->path);
        });
    }

    /**
     * The suite pins the library through the environment (ADR-0041), which wins over everything: a test
     * about the configured library has to step out of it.
     */
    private function withoutTheEnvironmentVariable(callable $test): void
    {
        $home = $this->temporaryDirectory().'/knowledge-library';
        putenv(KnowledgeLibrary::HOME_ENV);
        unset($_SERVER[KnowledgeLibrary::HOME_ENV], $_ENV[KnowledgeLibrary::HOME_ENV]);

        try {
            $test();
        } finally {
            putenv(KnowledgeLibrary::HOME_ENV.'='.$home);
            $_SERVER[KnowledgeLibrary::HOME_ENV] = $_ENV[KnowledgeLibrary::HOME_ENV] = $home;
        }
    }

    /**
     * Without a variable and without configuration, a source checkout deposits into the knowledge it ships
     * — the folder grows — and an installation under `vendor/` falls back to the user's own directory.
     */
    public function testWithoutAnythingElseTheSourceCheckoutIsTheLibraryAndVendorIsNot(): void
    {
        self::assertTrue(KnowledgeLibrary::embeddedIsWritable(), 'This test suite runs from a source checkout.');
        self::assertTrue(new KnowledgeLibrary(Resources::path('knowledge'))->isEmbedded());
        self::assertFalse(new KnowledgeLibrary(KnowledgeLibrary::userHome())->isEmbedded());
        self::assertStringEndsWith('devtools/knowledge', KnowledgeLibrary::userHome());
    }

    public function testTheProjectFileWinsOverTheLibraryAndTheLibraryOverTheEmbeddedOne(): void
    {
        $directory = new DevToolsDirectory(new ProjectRoot($this->copyFixtureProject('symfony-minimal')));
        $library = KnowledgeLibrary::locate();
        $library->deposit('symfony-8', "# Library's own\n", 'other-project');

        // Layer 2: the library answers a key the project does not have, and is copied into the project.
        self::assertSame('.devtools/knowledge/symfony-8.md', new KnowledgeProvider(library: $library)->provide($directory, 'symfony-8'));
        self::assertStringContainsString("Library's own", (string) file_get_contents($directory->path('knowledge/symfony-8.md')));

        // Layer 1: once the project has its own file, nothing overwrites it.
        file_put_contents($directory->path('knowledge/symfony-8.md'), "# The team's\n");
        self::assertSame('.devtools/knowledge/symfony-8.md', new KnowledgeProvider(library: $library)->provide($directory, 'symfony-8'));
        self::assertStringContainsString("The team's", (string) file_get_contents($directory->path('knowledge/symfony-8.md')));

        // Layer 3: a key the library does not hold still comes from what DevTools ships.
        $other = new DevToolsDirectory(new ProjectRoot($this->copyFixtureProject('symfony-legacy-yaml')));
        self::assertSame('.devtools/knowledge/symfony-7.md', new KnowledgeProvider(library: $library)->provide($other, 'symfony-7'));

        // Layer 4: nobody has it.
        self::assertNull(new KnowledgeProvider(library: $library)->provide($other, 'fictional-9'));
    }

    public function testADraftAcceptedByTheCanvasIsDepositedAndRecorded(): void
    {
        [$project, $result] = $this->writeAndApply(self::SHEET);
        $library = KnowledgeLibrary::locate();

        self::assertSame(['knowledge.fictional-9'], $result->accepted, implode("\n", array_merge(...array_values($result->refused)) ?: ['no refusal recorded']));
        self::assertSame([$library->path.'/fictional-9.md'], array_values($result->deposited));
        self::assertFileExists($library->path.'/fictional-9.md');

        $entry = $library->entries()['fictional-9'] ?? null;

        self::assertInstanceOf(LibraryEntry::class, $entry);
        self::assertSame(basename($project), $entry->project);
        self::assertSame(hash('sha256', self::SHEET), $entry->sha256);
        self::assertSame(['fictional-9'], $library->keys());
    }

    public function testASheetAlreadyInTheLibraryIsNeverOverwritten(): void
    {
        $library = KnowledgeLibrary::locate();

        self::assertSame(DepositOutcome::Deposited, $library->deposit('fictional-9', "# First\n", 'a'));
        self::assertSame(DepositOutcome::AlreadyPresent, $library->deposit('fictional-9', "# Second\n", 'b'));
        self::assertSame("# First\n", (string) file_get_contents($library->path.'/fictional-9.md'));
        self::assertSame('a', $library->entries()['fictional-9']->project);
    }

    public function testADraftRefusedByTheCanvasIsDepositedNowhere(): void
    {
        [, $result] = $this->writeAndApply("# Fictional 9\n\nNothing else.\n");

        self::assertSame([], $result->accepted);
        self::assertNotSame([], $result->refused['knowledge.fictional-9']);
        self::assertSame([], $result->deposited);
        self::assertSame([], KnowledgeLibrary::locate()->keys());
    }

    public function testNoShareKeepsTheSheetInTheProjectOnly(): void
    {
        [$project, $result] = $this->writeAndApply(self::SHEET, share: false);

        self::assertSame(['knowledge.fictional-9'], $result->accepted);
        self::assertSame([], $result->deposited);
        self::assertFileExists($project.'/.devtools/knowledge/fictional-9.md');
        self::assertSame([], KnowledgeLibrary::locate()->keys());
    }

    public function testTheConfigurationCanTurnSharingOff(): void
    {
        [, $result] = $this->writeAndApply(self::SHEET, configuration: '<knowledge share="false"/>');

        self::assertSame([], $result->deposited);
    }

    /**
     * A library nobody can write to is an inconvenience, not a failure: the sheet stays in the project and
     * the command says so.
     */
    public function testALibraryThatCannotBeWrittenIsAWarningNotAnError(): void
    {
        $readOnly = $this->temporaryDirectory().'/read-only';
        mkdir($readOnly, 0o555, true);

        try {
            $this->withoutTheEnvironmentVariable(function () use ($readOnly): void {
                [, $result] = $this->writeAndApply(self::SHEET, configuration: \sprintf('<knowledge library="%s/kb"/>', $readOnly));

                self::assertSame(['knowledge.fictional-9'], $result->accepted);
                self::assertSame([], $result->deposited);
                self::assertStringContainsString('cannot be written', implode("\n", $result->warnings));
            });
        } finally {
            chmod($readOnly, 0o755);
        }
    }

    public function testTheInspectionReportNamesTheLibraryItUsed(): void
    {
        $report = new InspectionPipeline(clock: new FrozenClock(new \DateTimeImmutable('2026-09-16')))->run(new InspectionOptions(path: $this->copyFixtureProject('symfony-minimal')));

        self::assertSame(KnowledgeLibrary::locate()->path, $report->knowledgeLibrary);
        self::assertStringContainsString('knowledge library: '.KnowledgeLibrary::locate()->path, $report->toMarkdown());
    }

    public function testKnowledgeListShowsThePathAndWhatTheLibraryHolds(): void
    {
        KnowledgeLibrary::locate()->deposit('fictional-9', self::SHEET, 'cereezer');

        $tester = new CommandTester(new Application()->find('knowledge:list'));
        $tester->execute(['path' => $this->copyFixtureProject('symfony-minimal')]);
        $display = $tester->getDisplay();

        self::assertStringContainsString(KnowledgeLibrary::locate()->path, $display);
        self::assertStringContainsString('fictional-9', $display);
        self::assertStringContainsString('cereezer', $display);
        self::assertStringContainsString('symfony-7', $display, 'What DevTools ships and the library does not hold is named too.');
    }

    public function testKnowledgePromoteRefusesASheetDevToolsAlreadyShips(): void
    {
        $tester = new CommandTester(new Application()->find('knowledge:promote'));

        self::assertSame(1, $tester->execute(['key' => 'symfony-7']));
        self::assertStringContainsString('already ships', $tester->getDisplay());
    }

    public function testKnowledgePromoteRefusesWhenThereIsNothingToPromote(): void
    {
        $tester = new CommandTester(new Application()->find('knowledge:promote'));

        self::assertSame(1, $tester->execute(['key' => 'fictional-9']));
        self::assertStringContainsString('No knowledge file', $tester->getDisplay());
    }

    /**
     * @return array{string, ApplyResult}
     */
    private function writeAndApply(string $draft, bool $share = true, string $configuration = ''): array
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $pending = $project.'/.devtools/pending';
        mkdir($pending, 0o777, true);

        file_put_contents($project.'/.devtools/config.xml', \sprintf(
            '<devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1">%s</devtools>',
            $configuration,
        ));
        file_put_contents($pending.'/knowledge.fictional-9.brief.xml', \sprintf(
            '<knowledge-brief xmlns="https://github.com/jul6art/devtools/schema/knowledge-brief/1" schema-version="1" key="fictional-9">'
            .'<stack language="php" framework="fictional" version="9.0"/><prompt version="knowledge/1" path="%s"/><canvas path="%s"/><draft path=".devtools/pending/knowledge.fictional-9.draft.md"/></knowledge-brief>',
            Resources::path('prompts/knowledge/v1.md'),
            Resources::path('knowledge/_canvas.md'),
        ));
        file_put_contents($pending.'/knowledge.fictional-9.draft.md', $draft);

        return [$project, new DraftApplier()->apply(new ProjectRoot($project), $share)];
    }
}
