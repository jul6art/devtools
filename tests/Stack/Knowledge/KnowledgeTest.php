<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Stack\Knowledge;

use Jul6Art\DevTools\Ai\DraftApplier;
use Jul6Art\DevTools\Ai\XmlPageBriefStore;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeBrief;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeCanvas;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeProvider;
use Jul6Art\DevTools\Stack\Knowledge\XmlKnowledgeBriefStore;
use Jul6Art\DevTools\Tests\Inspection\Pipeline\FakeAdapter;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(KnowledgeProvider::class)]
#[CoversClass(KnowledgeCanvas::class)]
#[CoversClass(KnowledgeBrief::class)]
#[CoversClass(XmlKnowledgeBriefStore::class)]
final class KnowledgeTest extends TestCase
{
    use CopiesFixtureProjects;

    #[DataProvider('embeddedKnowledge')]
    public function testEveryEmbeddedKnowledgeFollowsTheCanvas(string $file): void
    {
        self::assertSame([], new KnowledgeCanvas()->problems((string) file_get_contents($file)), basename($file));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function embeddedKnowledge(): iterable
    {
        foreach (glob(Resources::path('knowledge/*.md')) ?: [] as $file) {
            if ('_canvas.md' !== basename($file)) {
                yield basename($file) => [$file];
            }
        }
    }

    public function testThereIsEmbeddedKnowledgeForSymfonySevenAndEight(): void
    {
        self::assertFileExists(Resources::path('knowledge/symfony-7.md'));
        self::assertFileExists(Resources::path('knowledge/symfony-8.md'));
    }

    public function testTheProjectsKnowledgeWinsAndEmbeddedKnowledgeIsCopiedOnFirstUse(): void
    {
        $directory = new DevToolsDirectory(new ProjectRoot($this->temporaryDirectory()));
        $provider = new KnowledgeProvider();

        self::assertSame('.devtools/knowledge/symfony-8.md', $provider->provide($directory, 'symfony-8'));
        self::assertFileEquals(Resources::path('knowledge/symfony-8.md'), $directory->path('knowledge/symfony-8.md'));

        file_put_contents($directory->path('knowledge/symfony-8.md'), "# Symfony 8\n\nAdapted by the team.\n");
        self::assertSame('.devtools/knowledge/symfony-8.md', $provider->provide($directory, 'symfony-8'));
        self::assertStringContainsString('Adapted by the team', (string) file_get_contents($directory->path('knowledge/symfony-8.md')), 'Never overwritten.');

        self::assertNull($provider->provide($directory, 'laravel-12'));
    }

    /**
     * @param callable(string): string $mutation
     */
    #[DataProvider('invalidKnowledge')]
    public function testTheCanvasRefusesAnIncompleteFile(callable $mutation, string $problem): void
    {
        $problems = new KnowledgeCanvas()->problems($mutation((string) file_get_contents(Resources::path('knowledge/symfony-8.md'))));

        self::assertStringContainsString($problem, implode("\n", $problems));
    }

    /**
     * @return iterable<string, array{callable(string): string, string}>
     */
    public static function invalidKnowledge(): iterable
    {
        yield 'no title' => [static fn (string $file): string => (string) preg_replace('/^# Symfony 8\n/', '', $file), 'title'];
        yield 'missing section' => [static fn (string $file): string => (string) preg_replace('/## Tests\n.*?(?=## Pièges)/s', '', $file), '"Tests" is missing'];
        yield 'empty sources' => [static fn (string $file): string => (string) preg_replace('/## Sources\n.*$/s', "## Sources\n\n—\n", $file), 'Sources'];
        yield 'sources without a link' => [static fn (string $file): string => (string) preg_replace('/## Sources\n.*$/s', "## Sources\n\nThe official documentation, from memory.\n", $file), 'one URL per line'];
    }

    public function testAStackWithoutKnowledgeGetsAKnowledgeBriefAndNoPageBriefUntilItIsWritten(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        mkdir($project.'/.devtools');
        // A version DevTools ships no knowledge for, pinned the way a human would.
        $this->inspect($project, noAi: true);
        file_put_contents($project.'/.devtools/stack.xml', str_replace('<knowledge>symfony-8</knowledge>', '<knowledge locked="true">symfony-99</knowledge>', (string) file_get_contents($project.'/.devtools/stack.xml')));

        $this->inspect($project);

        $brief = new XmlKnowledgeBriefStore()->read($project.'/.devtools/pending/knowledge.symfony-99.brief.xml');
        self::assertSame('symfony-99', $brief->key);
        self::assertSame('symfony', $brief->framework);
        self::assertFileExists($brief->canvasPath);
        self::assertSame([], glob($project.'/.devtools/pending/page.*.brief.xml') ?: [], 'No page is written without the knowledge of its stack.');

        copy(Resources::path('knowledge/symfony-8.md'), $project.'/.devtools/pending/knowledge.symfony-99.draft.md');
        $result = new DraftApplier(new FrozenClock(new \DateTimeImmutable('2026-09-16T15:30:00+02:00')))->apply(new ProjectRoot($project));

        self::assertSame(['knowledge.symfony-99'], $result->accepted);
        self::assertFileExists($project.'/.devtools/knowledge/symfony-99.md');

        $this->inspect($project);

        self::assertCount(6, glob($project.'/.devtools/pending/page.*.brief.xml') ?: []);
        self::assertSame('.devtools/knowledge/symfony-99.md', new XmlPageBriefStore()->read($project.'/.devtools/pending/page.route.order.new.brief.xml')->knowledgePath);
        self::assertStringContainsString('[Connaissances symfony-99](../../.devtools/knowledge/symfony-99.md)', (string) file_get_contents($project.'/docs/workflows/workflows.md'));
    }

    public function testAKnowledgeDraftBreakingTheCanvasIsRefused(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $this->inspect($project, noAi: true);
        file_put_contents($project.'/.devtools/stack.xml', str_replace('<knowledge>symfony-8</knowledge>', '<knowledge locked="true">symfony-99</knowledge>', (string) file_get_contents($project.'/.devtools/stack.xml')));
        $this->inspect($project);
        file_put_contents($project.'/.devtools/pending/knowledge.symfony-99.draft.md', "# Symfony 99\n\n## Sources\n\n—\n");

        $result = new DraftApplier()->apply(new ProjectRoot($project));

        self::assertStringContainsString('"Cycle d\'entrée" is missing', implode("\n", $result->refused['knowledge.symfony-99'] ?? []));
        self::assertFileDoesNotExist($project.'/.devtools/knowledge/symfony-99.md');
    }

    private function inspect(string $project, bool $noAi = false): void
    {
        $report = new InspectionPipeline(new AdapterResolver([new FakeAdapter()]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($project, noAi: $noAi));
        self::assertSame([], $report->errors, implode("\n", $report->errors));
    }
}
