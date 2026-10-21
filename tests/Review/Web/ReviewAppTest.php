<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Review\Web;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Feedback\XmlFeedbackStore;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Review\Web\ReviewApp;
use Jul6Art\DevTools\Review\Web\ReviewRequest;
use Jul6Art\DevTools\Review\Web\ReviewResponse;
use Jul6Art\DevTools\Review\Web\ReviewServer;
use Jul6Art\DevTools\Scenario\Visual\Baselines;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0028, the review page without a server: what it serves, to whom, and what it writes — feedback and baselines,
 * nothing else.
 */
#[CoversClass(ReviewApp::class)]
#[CoversClass(ReviewRequest::class)]
#[CoversClass(ReviewResponse::class)]
#[CoversClass(Baselines::class)]
final class ReviewAppTest extends TestCase
{
    use CopiesFixtureProjects;
    use RecordsARun;

    private const string TOKEN = '0123456789abcdef0123456789abcdef';

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($this->project, noAi: true));
        $this->recordRun($this->project);
    }

    /**
     * Only the page's own files go without the token; everything else, the page included, needs it.
     */
    #[DataProvider('guarded')]
    public function testWithoutTheTokenNothingIsServed(string $method, string $path): void
    {
        foreach (['', 'wrong'] as $token) {
            $response = $this->app()->handle(new ReviewRequest($method, $path, '' === $token ? [] : ['token' => $token]));

            self::assertSame(403, $response->status, $path);
            self::assertStringContainsString('needs the token', $response->body);
        }

        self::assertSame(403, new ReviewApp($this->directory(), '')->handle(new ReviewRequest($method, $path, ['token' => '']))->status, 'No token at all is no access at all.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function guarded(): iterable
    {
        yield 'the page' => ['GET', '/'];
        yield 'the run' => ['GET', '/api/run'];
        yield 'the categories' => ['GET', '/api/categories'];
        yield 'a capture' => ['GET', '/files/run/'.self::CAPTURE.'.png'];
        yield 'a baseline' => ['GET', '/files/baselines/'.self::CAPTURE.'.png'];
        yield 'a feedback' => ['POST', '/api/feedback'];
        yield 'a baseline accepted' => ['POST', '/api/baseline'];
    }

    public function testThePageAndItsFilesComeWithAPolicyThatRunsNothingFromElsewhere(): void
    {
        $page = $this->get('/');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('<iframe id="page" title="The captured page, never run" sandbox="allow-same-origin">', $page->body);
        self::assertStringContainsString("script-src 'self'", $page->headers['Content-Security-Policy']);

        foreach (['/assets/review.css' => 'text/css; charset=utf-8', '/assets/review.js' => 'text/javascript; charset=utf-8'] as $asset => $type) {
            $response = $this->app()->handle(new ReviewRequest('GET', $asset));
            self::assertSame(200, $response->status, 'The page\'s own files, without the token.');
            self::assertSame($type, $response->headers['Content-Type']);
        }

        self::assertSame(404, $this->app()->handle(new ReviewRequest('GET', '/assets/../src/Version.php'))->status);
        self::assertSame(404, $this->get('/nowhere')->status);
    }

    public function testTheRunListsItsCapturesWithTheirBaselineAndDiff(): void
    {
        $run = $this->json($this->get('/api/run'));

        self::assertSame(self::RUN, $run['run']);
        self::assertSame([
            [
                'id' => 'route.order.new/happy-path',
                'workflow' => 'route.order.new',
                'verdict' => 'passed',
                'captures' => [[
                    'name' => self::CAPTURE,
                    'label' => 'after-load',
                    'screenshot' => '/files/run/'.self::CAPTURE.'.png',
                    'dom' => '/files/run/'.self::CAPTURE.'.html',
                    'diff' => '/files/run/'.self::CAPTURE.'.diff.png',
                    'domDiff' => null,
                    'visual' => 'within-threshold',
                    'baseline' => '/files/baselines/'.self::CAPTURE.'.png',
                ]],
                'failure' => null,
            ],
            [
                'id' => 'route.order.index/smoke',
                'workflow' => 'route.order.index',
                'verdict' => 'failed',
                'captures' => [],
                'failure' => ['action' => 'goto', 'message' => 'HTTP 500', 'screenshot' => '/files/run/route.order.index/smoke/01-goto.png', 'dom' => '/files/run/route.order.index/smoke/01-goto.html'],
            ],
        ], $run['scenarios']);
        self::assertSame([], $run['impacted'], 'Outside a repository, no change to filter on.');
    }

    /**
     * The filter: the workflows the change of the working tree reaches.
     */
    public function testTheWorkflowsTheCurrentChangeReachesAreGiven(): void
    {
        GitRepository::initialise($this->project)->commitAll('documented');
        file_put_contents($this->project.'/templates/order/_form.html.twig', "{# changed #}\n", \FILE_APPEND);

        $impacted = $this->json($this->get('/api/run'))['impacted'];
        self::assertIsArray($impacted);
        self::assertContains('route.order.new', $impacted);
    }

    public function testWithoutARunOrWithABrokenOneThePageSaysSo(): void
    {
        self::assertSame(['run' => null, 'scenarios' => [], 'impacted' => []], $this->json(new ReviewApp($this->directory(), self::TOKEN, 'nope')->handle(new ReviewRequest('GET', '/api/run', ['token' => self::TOKEN]))));

        file_put_contents($this->project.'/.devtools/runs/'.self::RUN.'/result.xml', '<broken');
        $run = $this->json($this->get('/api/run'));
        self::assertNull($run['run']);
        self::assertIsString($run['error']);
    }

    public function testTheCategoriesAreTheClosedList(): void
    {
        $categories = $this->json($this->get('/api/categories'))['categories'];

        self::assertIsArray($categories);
        self::assertCount(10, $categories);
        self::assertSame(['name' => 'ui-component', 'covers' => 'A component of the interface: a native select where the others use Select2, a missing date picker.'], $categories[0]);
    }

    /**
     * A captured page is served under a policy that runs none of its scripts, even opened on its own.
     */
    public function testFilesOfTheRunAndTheBaselinesAreServedAndNothingElse(): void
    {
        $page = $this->get('/files/run/'.self::CAPTURE.'.html');
        self::assertSame(200, $page->status);
        self::assertStringContainsString("default-src 'none'", $page->headers['Content-Security-Policy']);
        self::assertStringNotContainsString('script-src', $page->headers['Content-Security-Policy'], 'default-src none: no script at all.');
        self::assertStringContainsString('data-pwned', $page->body, 'Served as captured — it is the browser that runs nothing.');

        self::assertSame('image/png', $this->get('/files/run/'.self::CAPTURE.'.png')->headers['Content-Type']);
        self::assertSame('baseline', $this->get('/files/baselines/'.self::CAPTURE.'.png')->body);
        self::assertSame(200, $this->get('/files/run/route.order.new/happy-path/after%2Dload.png')->status, 'Encoded, the same file.');

        foreach (['/files/run/result.xml', '/files/run/../../config.xml', '/files/run/..%2F..%2Fconfig.xml', '/files/run/'.self::CAPTURE.'.gone.png', '/files/baselines/../runs/'.self::RUN.'/'.self::CAPTURE.'.png'] as $refused) {
            self::assertSame(404, $this->get($refused)->status, $refused);
        }

        self::assertSame(404, new ReviewApp($this->directory(), self::TOKEN, 'nope')->handle(new ReviewRequest('GET', '/files/run/'.self::CAPTURE.'.png', ['token' => self::TOKEN]))->status);
    }

    /**
     * Recorded as feedback:add would: checked, already said asked about, seen twice proposed as a rule.
     */
    public function testAFeedbackIsRecordedAndSeenAgainAndThenProposedAsARule(): void
    {
        $feedback = ['summary' => 'Le champ produit doit être un select2.', 'category' => 'ui-component', 'workflow' => 'route.order.new', 'selector' => '#order_product'];

        $created = $this->post('/api/feedback', $feedback);
        self::assertSame(201, $created->status);
        $answer = $this->json($created);
        self::assertSame(['id' => 'fb-2026-10-21-order-product', 'occurrences' => 1, 'path' => '.devtools/feedback/2026/fb-2026-10-21-order-product.xml', 'promote' => null], $answer);
        self::assertSame('#order_product', new XmlFeedbackStore()->read($this->project.'/'.$answer['path'])->target->selector, 'A valid feedback file.');

        $again = $this->post('/api/feedback', ['summary' => 'Toujours pas de select2.'] + $feedback);
        self::assertSame(409, $again->status);
        self::assertSame(['recurrence' => ['id' => 'fb-2026-10-21-order-product', 'summary' => 'Le champ produit doit être un select2.', 'occurrences' => 1]], $this->json($again));

        $occurrence = $this->json($this->post('/api/feedback', ['decision' => 'auto'] + $feedback));
        self::assertSame(2, $occurrence['occurrences']);
        self::assertSame('devtools rules:promote fb-2026-10-21-order-product', $occurrence['promote']);

        self::assertSame('fb-2026-10-21-order-product-2', $this->json($this->post('/api/feedback', ['decision' => 'new'] + $feedback))['id']);
    }

    public function testAFeedbackThatCannotBeRecordedSaysWhy(): void
    {
        self::assertSame(400, $this->app()->handle(new ReviewRequest('POST', '/api/feedback', ['token' => self::TOKEN], body: '[1, 2]'))->status);
        self::assertSame(400, $this->app()->handle(new ReviewRequest('POST', '/api/feedback', ['token' => self::TOKEN], body: 'not json'))->status);

        $refused = $this->post('/api/feedback', ['summary' => str_repeat('x', 201), 'category' => 'aesthetics']);
        self::assertSame(422, $refused->status);
        $errors = $this->json($refused)['errors'];
        self::assertIsArray($errors);
        self::assertCount(3, $errors, 'Too long, no target, no such category.');
    }

    /**
     * Already said in a feedback whose file is gone: said, not a crash of the server.
     */
    public function testAnOccurrenceOfAFeedbackThatCannotBeReadIsRefused(): void
    {
        $feedback = ['summary' => 'Un select2.', 'category' => 'ui-component', 'selector' => '#order_product'];
        $path = $this->json($this->post('/api/feedback', $feedback))['path'];
        self::assertIsString($path);
        unlink($this->project.'/'.$path);

        $refused = $this->post('/api/feedback', ['decision' => 'auto'] + $feedback);
        self::assertSame(409, $refused->status);
        self::assertStringContainsString('cannot be read', $refused->body);
    }

    /**
     * A project whose map is gone gives no workflow to filter on — and still its run.
     */
    public function testWithoutAMapNothingIsImpactedAndTheRunIsStillGiven(): void
    {
        GitRepository::initialise($this->project)->commitAll('documented');
        file_put_contents($this->project.'/templates/order/_form.html.twig', "{# changed #}\n", \FILE_APPEND);
        unlink($this->project.'/.devtools/graph/files-to-workflows.xml');

        $run = $this->json($this->get('/api/run'));
        self::assertSame([], $run['impacted']);
        self::assertSame(self::RUN, $run['run']);
    }

    /**
     * What the router builds from PHP's globals, and what it sends back — in a process of its own, where no output
     * came before the headers.
     */
    #[RunInSeparateProcess]
    public function testTheRouterReadsTheGlobalsAndSendsTheAnswer(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/feedback?token=abc&tags[]=x';
        $_SERVER['HTTP_X_DEVTOOLS_TOKEN'] = 'def';
        $_GET = ['token' => 'abc', 'tags' => ['x']];
        $request = ReviewRequest::fromGlobals();

        self::assertSame('POST', $request->method);
        self::assertSame('/api/feedback', $request->path);
        self::assertSame(['token' => 'abc'], $request->query, 'Strings only.');
        self::assertSame('abc', $request->token(), 'The query first.');
        self::assertSame('def', $request->headers['x-devtools-token']);
        self::assertSame('', $request->body);
        self::assertSame(['a' => 1], new ReviewRequest('POST', '/', body: '{"a": 1}')->json());
        self::assertSame([], new ReviewRequest('POST', '/', body: '{}')->json(), 'An empty object is an object.');

        ob_start();
        ReviewResponse::json(422, ['errors' => ['No.']])->send();
        self::assertSame('{"errors":["No."]}', ob_get_clean());
        self::assertSame(422, http_response_code());
    }

    public function testACaptureIsAcceptedAsTheBaseline(): void
    {
        $accepted = $this->post('/api/baseline', ['capture' => self::CAPTURE]);

        self::assertSame(201, $accepted->status);
        self::assertSame(['baseline' => '.devtools/baselines/'.self::CAPTURE.'.png'], $this->json($accepted));
        self::assertFileEquals($this->project.'/.devtools/runs/'.self::RUN.'/'.self::CAPTURE.'.png', $this->project.'/.devtools/baselines/'.self::CAPTURE.'.png');
        self::assertFileEquals($this->project.'/.devtools/runs/'.self::RUN.'/'.self::CAPTURE.'.html', $this->project.'/.devtools/baselines/'.self::CAPTURE.'.dom.html');

        self::assertSame(404, $this->post('/api/baseline', ['capture' => 'route.order.index/smoke/01-goto'])->status, 'A failure is no baseline.');
        file_put_contents($this->project.'/.devtools/runs/'.self::RUN.'/result.xml', '<broken');
        self::assertSame(409, $this->post('/api/baseline', ['capture' => self::CAPTURE])->status);
    }

    /**
     * Read-only, but for feedback and baselines: every other file of the project, before and after, the same.
     */
    public function testNothingIsWrittenOutsideFeedbackAndBaselines(): void
    {
        GitRepository::initialise($this->project)->commitAll('documented');
        $before = $this->outsideFeedbackAndBaselines();

        foreach (['/', '/api/run', '/api/categories', '/files/run/'.self::CAPTURE.'.html', '/files/baselines/'.self::CAPTURE.'.png', '/assets/review.js'] as $path) {
            $this->get($path);
        }

        $this->post('/api/feedback', ['summary' => 'Un select2.', 'category' => 'ui-component', 'selector' => '#order_product']);
        $this->post('/api/feedback', ['summary' => 'Encore.', 'category' => 'ui-component', 'selector' => '#order_product', 'decision' => 'auto']);
        $this->post('/api/baseline', ['capture' => self::CAPTURE]);

        self::assertSame($before, $this->outsideFeedbackAndBaselines());
        self::assertFileExists($this->project.'/.devtools/feedback/index.xml');
        self::assertFileExists($this->project.'/.devtools/baselines/'.self::CAPTURE.'.dom.html');
    }

    public function testTheRouterBuildsItFromItsEnvironment(): void
    {
        putenv(ReviewServer::PROJECT.'='.$this->project);
        putenv(ReviewServer::TOKEN.'='.self::TOKEN);
        putenv(ReviewServer::RUN.'='.self::RUN);

        try {
            self::assertSame(self::RUN, $this->json(ReviewApp::fromEnvironment()->handle(new ReviewRequest('GET', '/api/run', ['token' => self::TOKEN])))['run']);
            putenv(ReviewServer::RUN.'=');
            self::assertSame(self::RUN, $this->json(ReviewApp::fromEnvironment()->handle(new ReviewRequest('GET', '/api/run', [], ['x-devtools-token' => self::TOKEN])))['run'], 'The last run, the token in a header.');
        } finally {
            putenv(ReviewServer::PROJECT);
            putenv(ReviewServer::TOKEN);
            putenv(ReviewServer::RUN);
        }
    }

    /**
     * Every file of the project but feedback and baselines, with its hash.
     *
     * @return array<string, string>
     */
    private function outsideFeedbackAndBaselines(): array
    {
        $tree = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $path = substr($file->getPathname(), \strlen($this->project) + 1);

                if (!str_starts_with($path, '.devtools/feedback/') && !str_starts_with($path, '.devtools/baselines/')) {
                    $tree[$path] = (string) hash_file('sha256', $file->getPathname());
                }
            }
        }

        ksort($tree);

        return $tree;
    }

    private function app(): ReviewApp
    {
        return new ReviewApp($this->directory(), self::TOKEN, clock: new FrozenClock(new \DateTimeImmutable('2026-10-21T10:00:00+02:00')));
    }

    private function get(string $path): ReviewResponse
    {
        return $this->app()->handle(new ReviewRequest('GET', $path, ['token' => self::TOKEN]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): ReviewResponse
    {
        return $this->app()->handle(new ReviewRequest('POST', $path, [], ['x-devtools-token' => self::TOKEN], (string) json_encode($body)));
    }

    /**
     * @return array<mixed>
     */
    private function json(ReviewResponse $response): array
    {
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded, $response->body);

        return $decoded;
    }

    private function directory(): DevToolsDirectory
    {
        return DevToolsDirectory::documented(new ProjectRoot($this->project));
    }
}
