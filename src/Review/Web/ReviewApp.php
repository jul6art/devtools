<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Review\Web;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Feedback\FeedbackIndexEntry;
use Jul6Art\DevTools\Feedback\FeedbackNotFound;
use Jul6Art\DevTools\Feedback\FeedbackRecorder;
use Jul6Art\DevTools\Feedback\FeedbackStatus;
use Jul6Art\DevTools\Feedback\FeedbackTarget;
use Jul6Art\DevTools\Impact\ImpactedWorkflow;
use Jul6Art\DevTools\Impact\ImpactResolver;
use Jul6Art\DevTools\Impact\ImpactUnavailable;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Scenario\Run\XmlRunReader;
use Jul6Art\DevTools\Scenario\Visual\Baselines;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;

/**
 * The review page (ADR-0028): a run's captures next to their baselines and diffs, a captured page to pick an element
 * from, a feedback recorded in one line — and nothing else. Every request but the page's own files carries the
 * token `devtools review` printed; nothing is written outside `.devtools/feedback/` and `.devtools/baselines/`.
 */
final readonly class ReviewApp
{
    /**
     * The page and its two files, from `resources/review/`.
     */
    private const array ASSETS = [
        '/' => ['index.html', 'text/html; charset=utf-8'],
        '/assets/review.css' => ['review.css', 'text/css; charset=utf-8'],
        '/assets/review.js' => ['review.js', 'text/javascript; charset=utf-8'],
    ];

    /**
     * The page runs its own files, and nothing inline or from elsewhere.
     */
    private const string PAGE_POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; frame-src 'self'; base-uri 'none'; form-action 'none'";

    /**
     * A captured page is shown, never run: no script, no request out, its inline style only.
     */
    private const string CAPTURE_POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'";

    private const array TYPES = ['png' => 'image/png', 'html' => 'text/html; charset=utf-8', 'diff' => 'text/plain; charset=utf-8'];

    /**
     * @param string|null $run a run of `.devtools/runs/`; null: the last one, read again on every request
     */
    public function __construct(
        private DevToolsDirectory $directory,
        private string $token,
        private ?string $run = null,
        private Clock $clock = new SystemClock(),
        private GitClient $git = new GitClient(),
    ) {
    }

    /**
     * What `ReviewServer` passes to the router.
     */
    public static function fromEnvironment(): self
    {
        $run = (string) getenv(ReviewServer::RUN);

        return new self(DevToolsDirectory::documented(new ProjectRoot((string) getenv(ReviewServer::PROJECT))), (string) getenv(ReviewServer::TOKEN), '' === $run ? null : $run);
    }

    public function handle(ReviewRequest $request): ReviewResponse
    {
        if ('GET' === $request->method && str_starts_with($request->path, '/assets/')) {
            return $this->asset($request->path);
        }

        if ('' === $this->token || !hash_equals($this->token, $request->token())) {
            return ReviewResponse::json(403, ['error' => 'This review needs the token of the address devtools review printed.']);
        }

        return match (true) {
            'GET' === $request->method && '/' === $request->path => $this->asset('/'),
            'GET' === $request->method && '/api/run' === $request->path => ReviewResponse::json(200, $this->run()),
            'GET' === $request->method && '/api/categories' === $request->path => ReviewResponse::json(200, ['categories' => $this->categories()]),
            'GET' === $request->method && str_starts_with($request->path, '/files/run/') => $this->file($this->artifacts(), substr($request->path, \strlen('/files/run/'))),
            'GET' === $request->method && str_starts_with($request->path, '/files/baselines/') => $this->file($this->directory->baselinesDirectory(), substr($request->path, \strlen('/files/baselines/'))),
            'POST' === $request->method && '/api/feedback' === $request->path => $this->feedback($request->json()),
            'POST' === $request->method && '/api/baseline' === $request->path => $this->baseline($request->json()),
            default => ReviewResponse::json(404, ['error' => \sprintf('Nothing at %s %s.', $request->method, $request->path)]),
        };
    }

    private function asset(string $path): ReviewResponse
    {
        if (!isset(self::ASSETS[$path])) {
            return ReviewResponse::json(404, ['error' => 'No such file.']);
        }

        [$file, $type] = self::ASSETS[$path];

        return new ReviewResponse(200, ['Content-Type' => $type, 'Content-Security-Policy' => self::PAGE_POLICY, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'], (string) file_get_contents(Resources::path('review/'.$file)));
    }

    /**
     * The scenarios of the run, their captures with baseline and diff, where each failed — and the workflows the
     * current change of the working tree reaches, to filter on.
     *
     * @return array<string, mixed>
     */
    private function run(): array
    {
        $result = $this->result();
        $scenarios = [];

        try {
            $read = null === $result ? [] : new XmlRunReader()->read($result);
        } catch (InvalidXml $invalid) {
            return ['run' => null, 'error' => $invalid->getMessage(), 'scenarios' => [], 'impacted' => []];
        }

        foreach ($read as $id => $scenario) {
            $scenarios[] = [
                'id' => $id,
                'workflow' => explode('/', $id)[0],
                'verdict' => $scenario['verdict'],
                'captures' => array_map(fn (array $capture): array => [
                    'name' => $capture['name'],
                    'label' => basename($capture['name']),
                    'screenshot' => '/files/run/'.$capture['screenshot'],
                    'dom' => '/files/run/'.$capture['dom'],
                    'diff' => null === $capture['diff'] ? null : '/files/run/'.$capture['diff'],
                    'domDiff' => null === $capture['domDiff'] ? null : '/files/run/'.$capture['domDiff'],
                    'visual' => $capture['visual'],
                    'baseline' => is_file($this->directory->baselinesDirectory().'/'.$capture['name'].'.png') ? '/files/baselines/'.$capture['name'].'.png' : null,
                ], $scenario['captures']),
                // Where it stopped, as the run kept it: what a human wants to see first.
                'failure' => null === $scenario['failure'] ? null : [
                    'action' => $scenario['failure']['action'],
                    'message' => $scenario['failure']['message'],
                    'screenshot' => null === $scenario['failure']['screenshot'] ? null : '/files/run/'.$scenario['failure']['screenshot'],
                    'dom' => null === $scenario['failure']['dom'] ? null : '/files/run/'.$scenario['failure']['dom'],
                ],
            ];
        }

        return ['run' => null === $result ? null : basename(\dirname($result)), 'scenarios' => $scenarios, 'impacted' => $this->impacted()];
    }

    /**
     * @return list<array{name: string, covers: string}>
     */
    private function categories(): array
    {
        $categories = FeedbackRecorder::for($this->directory, $this->clock, $this->git)->categories()->all;

        return array_map(static fn (string $name, string $covers): array => ['name' => $name, 'covers' => $covers], array_keys($categories), array_values($categories));
    }

    /**
     * Recorded as `feedback:add` would: refused with its reasons, already said asked about — `decision` answers,
     * `auto` an occurrence, `new` apart — and seen twice, a rule proposed.
     *
     * @param array<string, mixed>|null $data
     */
    private function feedback(?array $data): ReviewResponse
    {
        if (null === $data) {
            return ReviewResponse::json(400, ['error' => 'A feedback is a JSON object.']);
        }

        $text = static fn (string $key): ?string => \is_string($data[$key] ?? null) && '' !== trim($data[$key]) ? trim($data[$key]) : null;
        $recorder = FeedbackRecorder::for($this->directory, $this->clock, $this->git);
        $target = new FeedbackTarget($text('workflow'), $text('selector'), $text('file'));
        $category = $text('category') ?? '';
        $summary = $text('summary') ?? '';
        $refusals = $recorder->refusals($target, $category, $summary);

        if ([] !== $refusals) {
            return ReviewResponse::json(422, ['errors' => $refusals]);
        }

        $decision = $text('decision');
        $known = 'new' === $decision ? null : $recorder->recurrenceOf($target, $category);

        if ($known instanceof FeedbackIndexEntry && 'auto' !== $decision) {
            return ReviewResponse::json(409, ['recurrence' => ['id' => $known->id, 'summary' => $known->summary, 'occurrences' => $known->occurrences]]);
        }

        try {
            $feedback = $known instanceof FeedbackIndexEntry ? $recorder->seenAgain($known->id, $target) : $recorder->create($target, $category, $summary);
        } catch (FeedbackNotFound|InvalidXml $unreadable) {
            return ReviewResponse::json(409, ['errors' => [$unreadable->getMessage()]]);
        }

        $promote = \count($feedback->occurrences) >= 2 && FeedbackStatus::Promoted !== $feedback->status;

        return ReviewResponse::json(201, [
            'id' => $feedback->id,
            'occurrences' => \count($feedback->occurrences),
            'path' => $this->directory->root->relative($this->directory->feedbackDirectory().'/'.$feedback->path()),
            'promote' => $promote ? 'devtools rules:promote '.$feedback->id : null,
        ]);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function baseline(?array $data): ReviewResponse
    {
        $result = $this->result();
        $name = \is_string($data['capture'] ?? null) ? $data['capture'] : '';

        try {
            $scenarios = null === $result ? [] : new XmlRunReader()->read($result);
        } catch (InvalidXml $invalid) {
            return ReviewResponse::json(409, ['errors' => [$invalid->getMessage()]]);
        }

        foreach ($scenarios as $scenario) {
            foreach ($scenario['captures'] as $capture) {
                if ($capture['name'] === $name && null !== $result) {
                    return ReviewResponse::json(201, ['baseline' => new Baselines($this->directory)->accept(\dirname($result), $capture['name'], $capture['screenshot'], $capture['dom'])]);
                }
            }
        }

        return ReviewResponse::json(404, ['errors' => [\sprintf('The run has no screenshot "%s".', $name)]]);
    }

    /**
     * A file of a directory DevTools wrote — never a path that leaves it, never a type it did not write.
     */
    private function file(?string $base, string $relative): ReviewResponse
    {
        $relative = rawurldecode($relative);
        $type = self::TYPES[pathinfo($relative, \PATHINFO_EXTENSION)] ?? null;
        $real = null === $base ? false : realpath($base.'/'.$relative);
        $baseReal = null === $base ? false : realpath($base);

        if (null === $type || str_contains($relative, "\0") || \in_array('..', explode('/', $relative), true) || false === $real || false === $baseReal || !str_starts_with($real, $baseReal.'/') || !is_file($real)) {
            return ReviewResponse::json(404, ['error' => 'No such file.']);
        }

        $headers = ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'];

        // A captured page may hold scripts: shown, never run, even opened on its own.
        if (str_starts_with($type, 'text/html')) {
            $headers['Content-Security-Policy'] = self::CAPTURE_POLICY;
        }

        return new ReviewResponse(200, $headers, (string) file_get_contents($real));
    }

    private function result(): ?string
    {
        if (null !== $this->run) {
            $file = $this->directory->path('runs/'.basename($this->run).'/result.xml');

            return is_file($file) ? $file : null;
        }

        $runs = glob($this->directory->path('runs/*/result.xml')) ?: [];
        sort($runs, \SORT_STRING);

        return array_last($runs);
    }

    private function artifacts(): ?string
    {
        $result = $this->result();

        return null === $result ? null : \dirname($result);
    }

    /**
     * @return list<string>
     */
    private function impacted(): array
    {
        $changed = $this->git->workingTreeChanges($this->directory->root) ?? [];

        try {
            return [] === $changed ? [] : array_map(static fn (ImpactedWorkflow $workflow): string => $workflow->id->value, new ImpactResolver()->resolve($this->directory, $changed)->workflows);
        } catch (ImpactUnavailable|PathOutsideProject) {
            return [];
        }
    }
}
