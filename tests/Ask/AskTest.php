<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Ask;

use Jul6Art\DevTools\Ask\Answer;
use Jul6Art\DevTools\Ask\PageSelector;
use Jul6Art\DevTools\Ask\QuestionAnswerer;
use Jul6Art\DevTools\Ask\SearchIndex;
use Jul6Art\DevTools\Ask\SearchIndexBuilder;
use Jul6Art\DevTools\Ask\SearchIndexStore;
use Jul6Art\DevTools\Ask\SelectedPage;
use Jul6Art\DevTools\Ask\Tokenizer;
use Jul6Art\DevTools\Command\AskCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ADR-0039: the documentation asked a question — the pages selected without AI, and an answer written from
 * them alone, never trusted further than its sources.
 */
#[CoversClass(Tokenizer::class)]
#[CoversClass(SearchIndex::class)]
#[CoversClass(SearchIndexBuilder::class)]
#[CoversClass(SearchIndexStore::class)]
#[CoversClass(PageSelector::class)]
#[CoversClass(SelectedPage::class)]
#[CoversClass(QuestionAnswerer::class)]
#[CoversClass(Answer::class)]
#[CoversClass(AskCommand::class)]
final class AskTest extends TestCase
{
    use UsesTemporaryDirectory;

    /**
     * The questions of symfony-minimal, in the words of a reader rather than those of the code: the page that
     * answers each is among the first three.
     */
    #[DataProvider('questions')]
    public function testThePageThatAnswersAQuestionIsAmongTheFirstThree(string $question, string $expected): void
    {
        $pages = $this->select($this->documented('symfony-minimal'), $question);

        self::assertContains($expected, \array_slice(array_map(static fn (SelectedPage $page): string => $page->id, $pages), 0, 3));
        self::assertLessThanOrEqual(PageSelector::LIMIT, \count($pages));
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function questions(): iterable
    {
        yield ['Que se passe-t-il quand un opérateur valide une commande ?', 'route.order.validate'];
        yield ['Comment le catalogue est-il importé ?', 'command.app.import-catalog'];
        yield ['Qui traite le message de création d\'une commande ?', 'async.order-created'];
        yield ['Où modifie-t-on le site d\'un client ?', 'route.customer.edit'];
        yield ['Comment la supervision vérifie-t-elle l\'application ?', 'route.health'];
        yield ['Comment est calculé le prix d\'une commande ?', 'route.order.new'];
        yield ['Comment le nombre de commandes est-il recalculé sans recharger la page ?', 'ui.cart-summary'];
        yield ['What happens when an order is created?', 'async.order-created'];
    }

    /**
     * laravel-minimal: the daily schedule depends on the command it runs. Asked about the scheduler, the
     * command comes along, one hop away — though no word of the question is on its page.
     */
    public function testTheSelectionWidensToWhatTheBestPagesDependOn(): void
    {
        $pages = $this->select($this->documented('laravel-minimal'), 'Que lance le planificateur ?');

        self::assertSame('async.orders-import', $pages[0]->id ?? null);
        self::assertSame(['command.orders.import', 'async.orders-import'], [$pages[1]->id ?? null, $pages[1]->via ?? null]);
        self::assertSame([], $this->select($this->documented('laravel-minimal'), 'kubernetes'), 'No word in common: nothing.');
    }

    public function testThePagesAreListedWithTheirScoreAndAStaleOneIsSaid(): void
    {
        $project = $this->documented('symfony-minimal');
        $index = $project.'/.devtools/index.xml';
        file_put_contents($index, str_replace('id="route.order.validate" type="routes" title="POST /orders/{id}/validate" status="fresh"', 'id="route.order.validate" type="routes" title="POST /orders/{id}/validate" status="stale"', (string) file_get_contents($index)));
        $tester = new CommandTester(new Application()->find('ask'));

        self::assertSame(0, $tester->execute(['question' => 'Que se passe-t-il quand un opérateur valide une commande ?', '--path' => $project, '--pages' => true]));
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('route.order.validate', $display);
        self::assertStringContainsString('next to route.order.new', $display);
        self::assertStringContainsString('route.order.validate is stale: its page may describe the code as it was (revised 2026-09-16).', $display);

        self::assertSame(0, $tester->execute(['question' => 'Que se passe-t-il quand un opérateur valide une commande ?', '--path' => $project, '--pages' => true, '--format' => 'json', '--limit' => '2']));
        $json = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame(['route.order.new', 'route.order.validate'], array_column(\is_array($json['pages'] ?? null) ? $json['pages'] : [], 'id'));
        self::assertSame(['route.order.validate is stale: its page may describe the code as it was (revised 2026-09-16).'], $json['warnings'] ?? null);

        foreach ([['--limit' => '9'], ['--format' => 'xml'], ['question' => ' '], ['--path' => $project.'/nope']] as $refused) {
            self::assertSame(2, $tester->execute(['question' => 'commande', '--path' => $project, '--pages' => true, ...$refused]));
        }

        self::assertSame(1, $tester->execute(['question' => 'commande', '--path' => $this->temporaryDirectory(), '--pages' => true]), 'Never inspected.');
    }

    /**
     * The index is a cache: kept while nothing changed, built again when a page did — `workflows:apply` writes
     * pages without inspecting.
     */
    public function testTheIndexIsBuiltAgainWhenAPageChanged(): void
    {
        $project = $this->documented('symfony-minimal');
        $directory = DevToolsDirectory::documented(new ProjectRoot($project));
        $store = new SearchIndexStore();

        $first = $store->current($directory, new WorkflowTypeRegistry());
        self::assertFileExists($project.'/.devtools/search.idx');
        self::assertSame([], new PageSelector()->select($first, 'kubernetes'));

        $page = $project.'/docs/workflows/routes/health.md';
        file_put_contents($page, str_replace('Point de santé de l\'application', 'Point de santé de l\'application, déployée sur Kubernetes', (string) file_get_contents($page)));
        touch($page, time() + 5);
        self::assertSame('route.health', new PageSelector()->select($store->current($directory, new WorkflowTypeRegistry()), 'kubernetes')[0]->id ?? null);

        $unchanged = (string) file_get_contents($project.'/.devtools/search.idx');
        file_put_contents($page, str_replace('Kubernetes', 'Kubernetes et Nomad', (string) file_get_contents($page)));
        touch($page, time() + 10);
        self::assertSame('route.health', new PageSelector()->select($store->current($directory, new WorkflowTypeRegistry(), save: false), 'nomad')[0]->id ?? null);
        self::assertSame($unchanged, file_get_contents($project.'/.devtools/search.idx'), 'Built in memory, not written: the MCP server writes nothing.');

        file_put_contents($project.'/.devtools/search.idx', '{"version": 1, "fingerprint": 3}');
        self::assertSame('route.health', new PageSelector()->select($store->current($directory, new WorkflowTypeRegistry()), 'kubernetes')[0]->id ?? null, 'An index that cannot be read is built again.');
        self::assertNull(SearchIndex::fromJson('[]'));
    }

    public function testAnAnswerIsOffUntilTheProjectTurnsItOnAndNeedsTheKey(): void
    {
        $project = $this->documented('symfony-minimal');
        $client = $this->client('');
        $tester = new CommandTester(new AskCommand(new QuestionAnswerer($client)));

        self::assertSame(1, $tester->execute(['question' => 'Que se passe-t-il quand un opérateur valide une commande ?', '--path' => $project]));
        self::assertStringContainsString('ask --pages lists the pages that answer it, without AI', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));

        $this->enable($project);
        self::assertSame(1, $tester->execute(['question' => 'Que se passe-t-il quand un opérateur valide une commande ?', '--path' => $project]));
        self::assertStringContainsString('ANTHROPIC_API_KEY is not set', $tester->getDisplay());
        self::assertSame([], $client->requests, 'Nothing was sent.');
    }

    /**
     * A simulated answer: it cites a page it was not given, and rests on a stale one — both said, with the
     * sources and what it cost.
     */
    public function testAnAnswerListsItsSourcesAndSaysWhatCannotBeTrusted(): void
    {
        $project = $this->documented('symfony-minimal');
        $this->enable($project);
        $index = $project.'/.devtools/index.xml';
        file_put_contents($index, str_replace('title="POST /orders/{id}/validate" status="fresh"', 'title="POST /orders/{id}/validate" status="stale"', (string) file_get_contents($index)));
        $client = $this->client('sk-ant-test');
        $client->answer = 'L\'opérateur valide la commande par `route.order.validate`, qui la fait passer de `draft` à `validated` ; `async.order-created` prévient ensuite le client.';
        $tester = new CommandTester(new AskCommand(new QuestionAnswerer($client)));

        self::assertSame(0, $tester->execute(['question' => 'Que se passe-t-il quand un opérateur valide une commande ?', '--path' => $project]));
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('qui la fait passer de `draft` à `validated`', $display);
        self::assertStringContainsString('route.order.validate — docs/workflows/routes/order.validate.md, revised 2026-09-16 (stale)', $display);
        self::assertStringContainsString('The answer cites async.order-created, which was not among its sources: nothing checks what it says of it.', $display);
        self::assertStringContainsString('route.order.validate is stale: its page may describe the code as it was (revised 2026-09-16).', $display);
        self::assertStringContainsString('claude-opus-5-5 · 900 input tokens · 120 output', $display);

        $request = $client->requests[0] ?? [];
        $system = \is_array($request['system'] ?? null) && \is_array($request['system'][0] ?? null) ? $request['system'][0] : [];
        $messages = \is_array($request['messages'] ?? null) && \is_array($request['messages'][0] ?? null) ? $request['messages'][0] : [];
        self::assertStringContainsString('"Not documented."', \is_string($system['text'] ?? null) ? $system['text'] : '');
        $content = \is_string($messages['content'] ?? null) ? $messages['content'] : '';
        self::assertStringStartsWith('<question>Que se passe-t-il quand un opérateur valide une commande ?</question>', $content);
        self::assertStringContainsString('<page id="route.order.validate" title="POST /orders/{id}/validate" updated="2026-09-16" status="stale">', $content);
        self::assertLessThanOrEqual(PageSelector::LIMIT, substr_count($content, '<page id='), 'Eight pages at most in the context.');

        self::assertSame(0, $tester->execute(['question' => 'Que se passe-t-il quand un opérateur valide une commande ?', '--path' => $project, '--format' => 'json']));
        $json = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame($client->answer, $json['answer'] ?? null);
        self::assertCount(2, \is_array($json['warnings'] ?? null) ? $json['warnings'] : []);

        $client->stop = 'refusal';
        self::assertSame(0, $tester->execute(['question' => 'commande', '--path' => $project]));
        self::assertStringContainsString('Claude declined to answer this question.', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['question' => 'kubernetes', '--path' => $project]));
        self::assertStringContainsString('Not documented: no page shares a word with the question.', $tester->getDisplay());
        self::assertCount(3, $client->requests, 'A question no page shares a word with is not sent.');
    }

    /**
     * ADR-0039: under 200 ms for 300 workflows.
     */
    public function testASelectionAmongThreeHundredWorkflowsTakesLessThanTwoHundredMilliseconds(): void
    {
        $words = Tokenizer::tokens((string) file_get_contents(__DIR__.'/../../docs/specs.md'));
        $documents = [];

        for ($workflow = 0; $workflow < 300; ++$workflow) {
            $terms = [];

            foreach (\array_slice($words, ($workflow * 37) % (\count($words) - 400), 400) as $word) {
                $terms[$word] = ($terms[$word] ?? 0.0) + 1.0;
            }

            $documents[\sprintf('route.page.p%03d', $workflow)] = ['title' => 'Page '.$workflow, 'type' => 'routes', 'page' => 'p.md', 'status' => 'fresh', 'updated' => '2026-10-21', 'related' => [\sprintf('route.page.p%03d', ($workflow + 1) % 300)], 'length' => array_sum($terms), 'terms' => $terms];
        }

        $index = SearchIndex::fromJson(new SearchIndex($documents)->toJson()) ?? self::fail('The index reads back.');
        $start = hrtime(true);
        $pages = new PageSelector()->select($index, 'Que se passe-t-il quand un opérateur valide une commande dans le back-office ?');
        $elapsed = (hrtime(true) - $start) / 1e6;

        self::assertNotSame([], $pages);
        self::assertLessThan(200, $elapsed, \sprintf('%.1f ms', $elapsed));
    }

    public function testWordsAreReducedToTheirStem(): void
    {
        self::assertSame(['valid', 'valid', 'valid', 'valid'], Tokenizer::tokens('valide validate validated validation'));
        self::assertSame(['order', 'order', 'command', 'catalog', 'catalog', 'site', 'statu'], Tokenizer::tokens('orders the Order commandes catalogue catalog sites status'));
        self::assertSame(['order', 'controll', 'rout', 'order', 'new', 'order', 'id'], Tokenizer::tokens('OrderController route.order.new order_id 42'));
        self::assertSame(['acce', 'class'], Tokenizer::tokens('accès class'), 'The accent goes; a double s stays.');
    }

    /**
     * What the matrix produced for a fixture, written by Claude: a real documentation to ask.
     */
    private function documented(string $fixture): string
    {
        $project = $this->temporaryDirectory().'/'.$fixture.'-'.bin2hex(random_bytes(3));
        new Filesystem()->mirror(__DIR__.'/../Fixtures/matrix/'.$fixture.'/written', $project);

        return $project;
    }

    /**
     * @return list<SelectedPage>
     */
    private function select(string $project, string $question): array
    {
        return new PageSelector()->select(new SearchIndexStore()->current(DevToolsDirectory::documented(new ProjectRoot($project)), new WorkflowTypeRegistry()), $question);
    }

    private function enable(string $project): void
    {
        file_put_contents($project.'/.devtools/config.xml', '<?xml version="1.0" encoding="UTF-8"?><devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1"><ai enabled="true"/></devtools>');
    }

    private function client(string $key): AnsweringClient
    {
        return new AnsweringClient($key);
    }
}
