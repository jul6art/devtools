<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Review\Web;

use Facebook\WebDriver\WebDriverBy;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Review\Web\ReviewApp;
use Jul6Art\DevTools\Review\Web\ReviewServer;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\Browser;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Panther\Client;
use Symfony\Component\Process\Process;

/**
 * ADR-0028 in Chrome: the review page of a symfony-minimal run — the captured page shown and never run, an element
 * picked and named by a selector that is its alone, a feedback recorded, seen again, proposed as a rule.
 */
#[Group('browser')]
#[CoversClass(ReviewApp::class)]
final class ReviewBrowserTest extends TestCase
{
    use CopiesFixtureProjects;
    use RecordsARun;

    private const string TOKEN = 'feedfacefeedfacefeedfacefeedface';

    private string $project;

    private ?Process $server = null;

    private ?Client $client = null;

    protected function setUp(): void
    {
        Browser::skipWithout();
        $this->project = $this->copyFixtureProject('symfony-minimal');
        new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]))->run(new InspectionOptions($this->project, noAi: true));
        $this->recordRun($this->project);
    }

    protected function tearDown(): void
    {
        $this->client?->quit();
        $this->server?->stop();
    }

    /**
     * The captured page holds a script and a handler that would write in the page showing it: neither runs.
     */
    public function testTheCapturedPageIsShownAndNeverRun(): void
    {
        $client = $this->open();
        // Long enough for the image's error handler, asynchronous, to have run — if it could.
        usleep(300_000);

        self::assertSame([true, false, false], $client->executeScript(<<<'JS'
            var frame = document.getElementById('page');
            return [frame.contentDocument.querySelector('script') !== null, frame.sandbox.contains('allow-scripts'), document.body.hasAttribute('data-pwned')];
            JS));
    }

    #[DataProvider('elements')]
    public function testAPickedElementIsNamedBySelectorThatIsItsAlone(string $find, string $expected): void
    {
        $answer = $this->open()->executeScript(<<<'JS'
            var page = document.getElementById('page').contentDocument;
            var picked = (new Function('page', 'return ' + arguments[0]))(page);
            var selector = window.DevToolsReview.selectorOf(picked);
            return [selector, page.querySelectorAll(selector).length, page.querySelector(selector) === picked];
            JS, [$find]);

        self::assertSame([$expected, 1, true], $answer);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function elements(): iterable
    {
        yield 'its identifier first' => ["page.getElementById('order_product')", '#order_product'];
        yield 'its name' => ["page.querySelector('input[name=\"order[customer]\"]')", 'input[name="order[customer]"]'];
        yield 'a data attribute that is its alone' => ["page.querySelectorAll('button')[1]", 'button[data-role="primary"]'];
        yield 'a short path when its name is shared' => ["page.querySelectorAll('input[name=\"order[note]\"]')[1]", 'input:nth-of-type(3)'];
        yield 'a short path from its parent' => ["page.querySelectorAll('form p')[1]", 'form > p:nth-of-type(2)'];
        yield 'a short path from an ancestor with an identifier' => ["page.querySelector('main span')", '#main > div > span'];
    }

    /**
     * From the click on the element to the rule proposed: what a human does, in the page.
     */
    public function testAClickedElementBecomesAFeedbackSeenAgainThenProposedAsARule(): void
    {
        $client = $this->open();
        $client->executeScript("document.getElementById('page').contentDocument.getElementById('order_product').click();");
        self::assertSame('#order_product', $client->executeScript("return document.getElementById('selector').value;"));
        self::assertSame('route.order.new', $client->executeScript("return document.getElementById('workflow').value;"));

        $this->record($client, 'Le champ produit doit être un select2.');
        $client->waitForElementToContain('#outcome', 'Recorded fb-');

        $this->record($client, 'Toujours pas de select2.');
        $client->waitForElementToContain('#outcome', 'Already said in');
        $client->findElement(WebDriverBy::xpath("//div[@id='outcome']/button[1]"))->click();
        $client->waitForElementToContain('#outcome', 'Seen twice: devtools rules:promote');

        self::assertCount(1, glob($this->project.'/.devtools/feedback/*/*.xml') ?: []);

        $client->findElement(WebDriverBy::id('accept'))->click();
        $client->waitForElementToContain('#outcome', 'Baseline written');
        self::assertFileExists($this->project.'/.devtools/baselines/'.self::CAPTURE.'.dom.html');
    }

    private function record(Client $client, string $summary): void
    {
        $client->executeScript("document.getElementById('category').value = 'ui-component';");
        $client->findElement(WebDriverBy::id('summary'))->sendKeys($summary);
        $client->findElement(WebDriverBy::cssSelector('#feedback button[type="submit"]'))->click();
    }

    private function open(): Client
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $this->server = ReviewServer::process(DevToolsDirectory::documented(new ProjectRoot($this->project)), $port, self::TOKEN);
        self::assertNull(ReviewServer::start($this->server));

        $this->client = Browser::client(\sprintf('http://%s:%d', ReviewServer::HOST, $port));
        $this->client->request('GET', '/?token='.self::TOKEN);
        $this->client->waitFor('nav button');
        $this->client->findElement(WebDriverBy::cssSelector('button[data-capture="'.self::CAPTURE.'"]'))->click();

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            if (true === $this->client->executeScript("var page = document.getElementById('page').contentDocument; return !!(page && page.getElementById('order_product'));")) {
                return $this->client;
            }

            usleep(50_000);
        }

        self::fail('The captured page did not load in the review page.');
    }
}
