<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Rendering\Site;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rendering\Site\SiteExporter;
use Jul6Art\DevTools\Tests\Support\Browser;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ADR-0034: the exported site works offline — opened from the disk, it loads nothing that is not in it, draws
 * its diagrams and searches its pages.
 */
#[CoversNothing]
#[Group('browser')]
final class SiteBrowserTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testTheSiteOpenedFromTheDiskDrawsItsDiagramsAndSearchesWithoutTheNetwork(): void
    {
        Browser::skipWithout();
        $project = $this->temporaryDirectory().'/project';
        new Filesystem()->mirror(__DIR__.'/../../Fixtures/matrix/laravel-minimal/written', $project);
        new SiteExporter()->export(new DevToolsDirectory(new ProjectRoot($project)), $project.'/site', 'acme/laravel-minimal');

        $client = Browser::client('file://'.$project.'/site/');

        try {
            // Panther would prefix a base URI: the driver itself opens the file.
            $client->getWebDriver()->navigate()->to('file://'.$project.'/site/routes/orders.store.html');
            $client->waitFor('pre.mermaid svg', 10);

            // What the page refers to, and what the browser fetched — Chrome times no resource of a file:// page.
            /** @var list<string> $resources */
            $resources = $client->executeScript("return Array.from(document.querySelectorAll('script[src], link[href], img[src], iframe[src]')).map(function (e) { return e.src || e.href; }).concat(performance.getEntriesByType('resource').map(function (entry) { return entry.name; }));");

            self::assertCount(4, array_unique($resources), 'The stylesheet, Mermaid, the search index and the search.');

            foreach ($resources as $resource) {
                self::assertStringStartsWith('file://', $resource, 'Nothing is fetched from the network.');
            }

            $client->findElement(WebDriverBy::id('search'))->sendKeys('commande');
            $client->waitForVisibility('#results', 5);
            $titles = array_map(static fn (WebDriverElement $link): string => trim(explode("\n", $link->getText())[0]), $client->findElements(WebDriverBy::cssSelector('#results a')));

            self::assertContains('POST /orders', $titles);
            $client->findElement(WebDriverBy::cssSelector('#results a'))->click();
            self::assertStringEndsWith('.html', $client->getCurrentURL());
        } finally {
            $client->quit();
        }
    }
}
