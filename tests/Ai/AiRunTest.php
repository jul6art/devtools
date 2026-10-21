<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Ai;

use Jul6Art\DevTools\Ai\Api\AiRunner;
use Jul6Art\DevTools\Ai\Api\AiRunReport;
use Jul6Art\DevTools\Ai\Api\AnthropicClient;
use Jul6Art\DevTools\Ai\Api\ApiFailed;
use Jul6Art\DevTools\Ai\Api\Pricing;
use Jul6Art\DevTools\Ai\Api\SecretScanner;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\AiRunCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Inspection\Adapter\Laravel\LaravelAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Laravel\RecordedArtisanRunner;
use Jul6Art\DevTools\Tests\Support\Browser;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * ADR-0035: `ai:run` writes the briefs through the Claude API — off unless a project turns it on, never sending
 * a secret, within its limits, its drafts judged as any other — against a fake API served on 127.0.0.1.
 */
#[CoversClass(AiRunner::class)]
#[CoversClass(AiRunReport::class)]
#[CoversClass(AnthropicClient::class)]
#[CoversClass(SecretScanner::class)]
#[CoversClass(Pricing::class)]
#[CoversClass(AiRunCommand::class)]
final class AiRunTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string KEY = 'sk-ant-test-key-that-must-never-be-written-0123456789';

    private ?Process $server = null;

    private string $api = '';

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
        parent::tearDown();
    }

    public function testItIsOffUntilTheProjectTurnsItOn(): void
    {
        $project = $this->briefed();
        $tester = new CommandTester(new Application()->find('ai:run'));

        self::assertSame(1, $tester->execute(['path' => $project]));
        self::assertStringContainsString('ai:run is off: it sends briefs to the Claude API and spends tokens', $this->display($tester));
        self::assertNotSame([], glob($project.'/.devtools/pending/page.*.brief.xml') ?: []);
    }

    public function testWithoutAKeyNothingIsSent(): void
    {
        $project = $this->briefed(enabled: true);
        $tester = $this->command('', 'drafts');

        self::assertSame(1, $tester->execute(['path' => $project]));
        self::assertStringContainsString('ANTHROPIC_API_KEY is not set: ai:run reads the key from that variable only', $this->display($tester));
        self::assertSame([], $this->requests());
    }

    public function testBriefsAreWrittenThroughTheApiThenAppliedAndTheKeyIsWrittenNowhere(): void
    {
        $project = $this->briefed(enabled: true);
        $tester = $this->command(self::KEY, 'drafts');

        self::assertSame(0, $tester->execute(['path' => $project, '--max-tasks' => '3']));
        $requests = $this->requests();

        self::assertCount(3, $requests, '--max-tasks: three briefs sent.');
        self::assertSame(self::KEY, self::at($requests[0], 'headers', 'x-api-key'), 'In the header, and only there.');
        self::assertSame(['2023-06-01', 'server-side-fallback-2026-07-01'], [self::at($requests[0], 'headers', 'anthropic-version'), self::at($requests[0], 'headers', 'anthropic-beta')]);

        $body = self::at($requests[0], 'body');
        self::assertIsArray($body);
        self::assertSame(['model' => 'claude-opus-5-5', 'max_tokens' => 16000, 'output_config' => ['effort' => 'medium'], 'fallbacks' => 'default'], array_intersect_key($body, array_flip(['model', 'max_tokens', 'output_config', 'fallbacks'])));
        self::assertArrayNotHasKey('temperature', $body, 'Sampling is removed on the current models: a 400.');
        self::assertArrayNotHasKey('thinking', $body, 'Adaptive thinking is the default.');
        self::assertSame(['type' => 'ephemeral'], self::at($body, 'system', 2, 'cache_control'), 'The prefix every page shares — preamble, prompt, knowledge — is cached.');
        self::assertStringStartsWith('# Laravel 13', (string) preg_replace('/^The knowledge of the stack:\s+/', '', self::text($body, 'system', 2, 'text')));
        self::assertStringContainsString('<file path="routes/console.php">', self::text($body, 'messages', 0, 'content'), 'The files of the workflow are sent with its brief.');
        self::assertStringContainsString('<file path=".devtools/pending/page.async.orders-import.model.xml">', self::text($body, 'messages', 0, 'content'));
        self::assertStringEndsWith('Write the draft .devtools/pending/page.async.orders-import.draft.md.', self::text($body, 'messages', 0, 'content'));

        $display = $this->display($tester);
        self::assertStringContainsString('Model claude-opus-5-5 · 3 requests · 3600 input tokens · 1200 output · 2400 read from the cache · 300 written to it · about $0.04', $display);
        self::assertStringContainsString('## Accepted - `async.orders-import` - `async.send-order-confirmation` - `command.orders.import`', $display);
        self::assertStringContainsString('## Kept for the next run - `page.data.migrations`', $display);
        self::assertStringContainsString('Stopped: --max-tasks=3 is reached.', $display);
        self::assertFileExists($project.'/.devtools/pending/page.data.migrations.brief.xml', 'Kept for the next run.');
        self::assertFileExists($project.'/docs/workflows/async/orders-import.md');

        $written = new Process(['grep', '-rl', self::KEY, $project]);
        $written->run();
        self::assertSame('', $written->getOutput(), 'The key is in no file DevTools wrote.');
    }

    public function testATaskWhoseFilesHoldASecretIsRefusedAndNothingOfItIsSent(): void
    {
        $project = $this->briefed(enabled: true);
        file_put_contents($project.'/routes/console.php', "\n// \$password = 'correct-horse-battery';\n", \FILE_APPEND);
        $tester = $this->command(self::KEY, 'drafts');

        $tester->execute(['path' => $project, '--max-tasks' => '2']);

        self::assertStringContainsString('`page.async.orders-import` — routes/console.php holds what looks like a secret (password assignment): nothing was sent', $this->display($tester));
        self::assertCount(2, $this->requests(), 'The refused task counts for nothing: two others were sent.');

        foreach ($this->requests() as $request) {
            self::assertStringNotContainsString('correct-horse-battery', (string) json_encode($request));
        }
    }

    public function testTheTokenBudgetStopsTheRunAndKeepsTheRest(): void
    {
        $project = $this->briefed(enabled: true, budget: 4000);
        $tester = $this->command(self::KEY, 'drafts');

        $tester->execute(['path' => $project]);

        self::assertCount(2, $this->requests(), 'Each answer spends 2500 tokens: the third would exceed 4000.');
        self::assertStringContainsString('Stopped: the budget of 4000 tokens is spent.', $this->display($tester));
        self::assertCount(10, glob($project.'/.devtools/pending/page.*.brief.xml') ?: []);
    }

    public function testADraftNoValidatorAcceptsIsRefusedLikeAClaudeCodeDraft(): void
    {
        $project = $this->briefed(enabled: true);
        $tester = $this->command(self::KEY, 'invalid');

        self::assertSame(1, $tester->execute(['path' => $project, '--tasks' => 'page', '--max-tasks' => '1']));
        self::assertStringContainsString('## Refused by the validators - `async.orders-import` — The section "Summary" is missing.', $this->display($tester));
        self::assertFileExists($project.'/.devtools/pending/page.async.orders-import.draft.md', 'Kept, as a Claude Code draft would be, for a look.');
    }

    public function testARefusalOrADraftCutShortWritesNothing(): void
    {
        $project = $this->briefed(enabled: true);

        $refused = $this->command(self::KEY, 'refusal');
        self::assertSame(1, $refused->execute(['path' => $project, '--max-tasks' => '1']));
        self::assertStringContainsString('`page.async.orders-import` — Claude declined to write it (cyber)', $this->display($refused));
        $this->server?->stop();

        $cut = $this->command(self::KEY, 'max_tokens');
        self::assertSame(1, $cut->execute(['path' => $project, '--max-tasks' => '1']));
        self::assertStringContainsString('the draft did not fit in 16000 output tokens (<ai max-tokens>): nothing was written', $this->display($cut));
        self::assertFileDoesNotExist($project.'/.devtools/pending/page.async.orders-import.draft.md');
    }

    public function testAKnowledgeSheetIsNeverWrittenFromMemory(): void
    {
        $project = $this->briefed(enabled: true);
        file_put_contents($project.'/.devtools/pending/knowledge.express-4.brief.xml', '<knowledge-brief><draft path=".devtools/pending/knowledge.express-4.draft.md"/></knowledge-brief>');
        $tester = $this->command(self::KEY, 'drafts');

        $tester->execute(['path' => $project, '--tasks' => 'knowledge']);

        self::assertStringContainsString('`knowledge.express-4` — a knowledge sheet is researched on the web: <ai web-search="true"/> allows it', $this->display($tester));
        self::assertSame([], $this->requests());
    }

    public function testOptionsAndAddressesAreChecked(): void
    {
        $project = $this->briefed(enabled: true);

        self::assertSame(2, $this->command(self::KEY, 'drafts')->execute(['path' => $project, '--tasks' => 'novel']));
        self::assertNull(Pricing::estimate('claude-unknown', ['input' => 1, 'output' => 1, 'cacheRead' => 0, 'cacheWrite' => 0]));

        $this->expectException(ApiFailed::class);
        $this->expectExceptionMessage('http://example.com/v1/messages is not an HTTPS address: the key is never sent in clear.');

        new AnthropicClient(self::KEY, 'http://example.com/v1/messages')->messages(['model' => 'claude-opus-5-5']);
    }

    /**
     * laravel-minimal, inspected with briefs for its twelve pages.
     */
    private function briefed(bool $enabled = false, int $budget = 200000): string
    {
        $project = $this->copyFixtureProject('laravel-minimal');
        $report = new InspectionPipeline(new AdapterResolver([new LaravelAdapter(new RecordedArtisanRunner())]))->run(new InspectionOptions($project));
        self::assertSame([], $report->errors);

        if ($enabled) {
            file_put_contents($project.'/.devtools/config.xml', \sprintf('<?xml version="1.0" encoding="UTF-8"?><devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1"><ai enabled="true" budget-tokens="%d"/></devtools>', $budget));
        }

        return $project;
    }

    private function command(string $key, string $mode): CommandTester
    {
        $directory = $this->temporaryDirectory().'/api-'.$mode;
        @mkdir($directory);
        [$this->server, $base] = Browser::serve($directory, __DIR__.'/../Fixtures/anthropic/router.php', ['DEVTOOLS_FAKE_DIRECTORY' => $directory, 'DEVTOOLS_FAKE_MODE' => $mode, 'DEVTOOLS_FAKE_DRAFTS' => __DIR__.'/../Fixtures/drafts/laravel-minimal']);
        $this->api = $directory;

        return new CommandTester(new AiRunCommand(new AiRunner(new AnthropicClient($key, $base.'/v1/messages')), new FrozenClock(new \DateTimeImmutable('2026-10-21T10:00:00+00:00'))));
    }

    /**
     * The requests the fake API recorded, decoded: `headers` and `body`, read with at() and text().
     *
     * @return list<mixed>
     */
    private function requests(): array
    {
        $file = $this->api.'/requests.jsonl';

        if ('' === $this->api || !is_file($file)) {
            return [];
        }

        return array_map(static fn (string $line): mixed => json_decode($line, true), array_values(array_filter(explode("\n", (string) file_get_contents($file)))));
    }

    private static function at(mixed $value, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = \is_array($value) ? $value[$key] ?? null : null;
        }

        return $value;
    }

    private static function text(mixed $value, int|string ...$keys): string
    {
        $text = self::at($value, ...$keys);

        return \is_string($text) ? $text : '';
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
