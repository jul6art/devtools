<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Rendering\Site;

use Jul6Art\DevTools\Command\ExportSiteCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rendering\Site\SiteExporter;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(SiteExporter::class)]
#[CoversClass(ExportSiteCommand::class)]
final class SiteExporterTest extends TestCase
{
    use UsesTemporaryDirectory;

    /**
     * What the matrix produced for laravel-minimal, written by Claude: a real documentation to export.
     */
    private const string DOCUMENTED = __DIR__.'/../../Fixtures/matrix/laravel-minimal/written';

    public function testEveryPageTheMenuAndTheMachineryBecomeASiteThatLinksAsTheDocumentationDoes(): void
    {
        $project = $this->documentedProject();
        $tester = new CommandTester(new Application()->find('export:site'));

        self::assertSame(0, $tester->execute(['path' => $project]));
        self::assertStringContainsString('18 pages written to', $tester->getDisplay());
        self::assertStringContainsString('open index.html, no server needed', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));

        $site = $project.'/build/devtools-site';
        $index = (string) file_get_contents($site.'/index.html');
        $page = (string) file_get_contents($site.'/routes/orders.store.html');

        self::assertFileEquals($site.'/index.html', $site.'/workflows.html');
        self::assertStringContainsString('<title>Workflows — acme/laravel-minimal</title>', $index, 'The menu names the project in its heading: the title does not name it twice.');
        self::assertStringContainsString('<title>POST /orders — acme/laravel-minimal</title>', $page);
        self::assertStringContainsString('<a href="routes/orders.store.html">POST /orders</a>', $index);
        self::assertStringContainsString('<a href="_devtools/knowledge/laravel-13.html">', $index, 'The machinery a page links to is copied beside it.');
        self::assertFileExists($site.'/_devtools/stack.xml.html');
        self::assertStringContainsString('<a href="glossary.html">Glossary</a>', $index, 'The glossary is a page of the site like the others.');
        self::assertStringContainsString('<pre class="mermaid">', (string) file_get_contents($site.'/_devtools/graph/workflows.mermaid.html'));
        self::assertStringContainsString('<a href="orders.show.html"><code>route.orders.show</code></a>', $page);
        self::assertStringContainsString('<script src="../assets/mermaid/mermaid.min.js"></script>', $page, 'Mermaid is in the site, never fetched from a CDN.');
        self::assertStringNotContainsString('http://', str_replace(['http://www.w3.org'], '', $page).file_get_contents($site.'/assets/search.js'));
        self::assertStringContainsString('"title":"POST /orders","url":"routes/orders.store.html","text":"Crée une commande', (string) file_get_contents($site.'/assets/search-index.js'));
        self::assertFileExists($site.'/assets/mermaid/LICENSE');
    }

    public function testADirectoryDevToolsDidNotWriteIsNeverEmptied(): void
    {
        $project = $this->documentedProject();
        new Filesystem()->dumpFile($project.'/public/keep.txt', 'mine');
        $tester = new CommandTester(new Application()->find('export:site'));

        self::assertSame(1, $tester->execute(['path' => $project, '--output' => 'public']));
        self::assertStringContainsString('is not empty and DevTools did not write it', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertFileExists($project.'/public/keep.txt');

        self::assertSame(0, $tester->execute(['path' => $project, '--output' => $this->temporaryDirectory().'/site']));
        file_put_contents($this->temporaryDirectory().'/site/stale.html', 'from a previous export');
        self::assertSame(0, $tester->execute(['path' => $project, '--output' => $this->temporaryDirectory().'/site']));
        self::assertFileDoesNotExist($this->temporaryDirectory().'/site/stale.html', 'Its own directory is replaced whole.');
    }

    public function testAProjectNeverInspectedIsToldWhatToRunFirst(): void
    {
        $tester = new CommandTester(new Application()->find('export:site'));

        self::assertSame(1, $tester->execute(['path' => $this->temporaryDirectory()]));
        self::assertStringContainsString('run workflows:inspect first', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    /**
     * ADR-0034: under 5 s for 300 workflows.
     */
    public function testThreeHundredPagesExportInUnderFiveSeconds(): void
    {
        $project = $this->temporaryDirectory().'/large';
        $page = (string) file_get_contents(self::DOCUMENTED.'/docs/workflows/routes/orders.store.md');
        $menu = "# Workflows\n\n";

        for ($i = 0; $i < 300; ++$i) {
            new Filesystem()->dumpFile($project.'/docs/workflows/routes/order'.$i.'.md', $page);
            $menu .= '- [Order '.$i.'](routes/order'.$i.".md)\n";
        }

        new Filesystem()->dumpFile($project.'/docs/workflows/workflows.md', $menu);
        mkdir($project.'/.devtools');
        $started = microtime(true);
        $written = new SiteExporter()->export(new DevToolsDirectory(new ProjectRoot($project)), $project.'/site', 'large');

        self::assertSame(302, $written['pages']);
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    private function documentedProject(): string
    {
        $project = $this->temporaryDirectory().'/project';
        new Filesystem()->mirror(self::DOCUMENTED, $project);

        return $project;
    }
}
