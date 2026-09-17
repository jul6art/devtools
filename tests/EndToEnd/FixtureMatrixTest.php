<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\EndToEnd;

use Jul6Art\DevTools\Rendering\PageParser;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RealConsoleTest;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * The MVP as a whole (ADR-0015): every fixture project, through the binary in a separate process, in a
 * temporary git repository — documented without AI, re-inspected without a single change, written from
 * recorded Claude drafts, then changed and re-inspected with exactly the expected decisions.
 */
#[CoversNothing]
#[Group('end-to-end')]
final class FixtureMatrixTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string FIXTURES = __DIR__.'/../Fixtures';

    /**
     * A fixed clock, fixed commit dates and no enclosing repository: two runs give the same bytes.
     */
    private const array REPRODUCIBLE = [
        'SOURCE_DATE_EPOCH' => '1789560000',
        'SHELL_VERBOSITY' => '0',
        'GIT_AUTHOR_DATE' => '2026-09-16T12:00:00+00:00',
        'GIT_COMMITTER_DATE' => '2026-09-16T12:00:00+00:00',
    ];

    /**
     * 1 when a stack falls back to reading attributes, which the report says — the monorepo's `api` has no
     * `vendor/`.
     */
    private int $inspectExitCode = 0;

    #[\Override]
    protected function tearDown(): void
    {
        foreach (array_keys(self::REPRODUCIBLE) as $name) {
            putenv($name);
            unset($_SERVER[$name]);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, string|null> $change   path => new content appended (string) or deletion (null); a path that does not exist is added
     * @param array<string, string>      $expected workflow => "decision: why" of the inspection after the change
     */
    #[DataProvider('fixtures')]
    public function testAFixtureIsDocumentedWrittenAndFollowedThroughChanges(string $fixture, string $drafts, array $change, array $expected, int $inspectExitCode = 0): void
    {
        $this->inspectExitCode = $inspectExitCode;

        if (is_dir(self::FIXTURES.'/projects/'.$fixture.'/vendor') || is_file(self::FIXTURES.'/projects/'.$fixture.'/composer.lock')) {
            RealConsoleTest::installDependencies(self::FIXTURES.'/projects/'.$fixture);
        }

        $project = $this->copyFixtureProject($fixture, withDependencies: true);

        // Process only passes on the variables $_SERVER also holds: git commits get fixed dates, hence fixed hashes.
        foreach (self::REPRODUCIBLE as $name => $value) {
            putenv($name.'='.$value);
            $_SERVER[$name] = $value;
        }

        $repository = GitRepository::initialise($project);
        $repository->commitAll('fixture');

        // 1. Documented without AI.
        $this->devtools($project, 'init');
        $this->devtools($project, 'workflows:inspect', '--no-ai');
        $this->assertSnapshot($project, $fixture.'/no-ai');

        // 2. Nothing changed, nothing rewritten.
        $before = self::devtoolsTree($project);
        $this->devtools($project, 'workflows:inspect', '--no-ai');
        self::assertSame($before, self::devtoolsTree($project), 'A second inspection without change modifies no file.');

        // 3. Written by Claude, from recorded drafts, round after round.
        do {
            $this->devtools($project, 'workflows:inspect');
            $copied = $this->copyRecordedDrafts($project, $drafts);

            if ($copied > 0) {
                $this->devtools($project, 'workflows:apply');
            }
        } while ($copied > 0);

        self::assertSame([], glob($project.'/.devtools/pending/*.brief.xml') ?: [], 'Every brief has a recorded draft.');
        $this->assertSnapshot($project, $fixture.'/written');

        foreach (glob($project.'/.devtools/workflows/*/*.md') ?: [] as $page) {
            self::assertSame([], new PageParser()->parse((string) file_get_contents($page))->conformityProblems(), $page);
        }

        $written = self::devtoolsTree($project);
        $this->devtools($project, 'workflows:inspect', '--no-ai');
        self::assertSame($written, self::devtoolsTree($project), 'Written pages are not rewritten by an inspection without change.');

        // 4. A file changed, one deleted, one added: exactly the expected decisions.
        $repository->commitAll('documented');
        $filesystem = new Filesystem();

        foreach ($change as $path => $content) {
            match (true) {
                null === $content => $filesystem->remove($project.'/'.$path),
                is_file($project.'/'.$path) => $filesystem->appendToFile($project.'/'.$path, $content),
                default => $filesystem->dumpFile($project.'/'.$path, $content),
            };
        }

        $repository->commitAll('change');

        $this->devtools($project, 'workflows:inspect', '--no-ai');

        self::assertSame($expected, $this->decisions($project));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: array<string, string|null>, 3: array<string, string>, 4?: int}>
     */
    public static function fixtures(): iterable
    {
        $symfony = [
            ['src/Service/OrderPricing.php' => "\n// pricing reviewed\n", 'src/Twig/Components/CartSummary.php' => null, 'src/Util/Slugger.php' => "<?php\n\nnamespace App\\Util;\n\nfinal class Slugger\n{\n}\n"],
            // The new class is used by nothing: it is uncovered, not a decision.
            [
                'route.order.new' => 'rewrite: files changed: src/Service/OrderPricing.php',
                'ui.cart-summary' => 'orphan: entry point gone',
            ],
        ];

        yield 'symfony-minimal' => ['symfony-minimal', 'symfony-minimal', ...$symfony];
        yield 'symfony-legacy-yaml' => ['symfony-legacy-yaml', 'symfony-minimal', ...$symfony];
        yield 'plain-php' => ['plain-php', 'plain-php', ['lib/db.php' => "\n// connection reviewed\n", 'migrations/001_create_orders.sql' => null, 'public/about.php' => "<?php\n\necho 'Acme';\n"], [
            'command.cleanup' => 'rewrite: files changed: lib/db.php',
            'command.import-orders' => 'rewrite: files changed: lib/db.php',
            'data.migrations' => 'orphan: entry point gone',
            'route.about' => 'create: initial',
            'route.index' => 'rewrite: files changed: lib/db.php',
            'route.orders.new' => 'rewrite: files changed: lib/db.php',
        ]];
        yield 'node-express' => ['node-express', 'node-express', ['src/services/orderService.js' => "\n// reviewed\n", 'test/orders.test.js' => null, 'src/routes/invoices.js' => "module.exports = require('express').Router();\n"], [
            // Without AI, the new router waits for a limited discovery: no decision yet.
            'route.get-orders' => 'rewrite: files changed: src/services/orderService.js; files removed: test/orders.test.js',
            'route.post-orders' => 'rewrite: files changed: src/services/orderService.js',
        ]];
        yield 'angular-minimal' => ['angular-minimal', 'angular-minimal', ['src/app/orders/order.service.ts' => "\n// reviewed\n", 'src/app/orders/order-detail.component.ts' => null, 'src/app/orders/order.model.ts' => "export interface OrderModel {}\n"], [
            'integration.orderservice' => 'rewrite: files changed: src/app/orders/order.service.ts',
            'route.orders' => 'rewrite: files changed: src/app/orders/order.service.ts',
            'route.orders-id' => 'rewrite: files changed: src/app/orders/order.service.ts; files removed: src/app/orders/order-detail.component.ts',
        ]];
        yield 'monorepo' => ['monorepo', 'monorepo', ['api/src/Controller/ItemController.php' => "\n// reviewed\n", 'front/src/app/item-list.component.ts' => null, 'api/src/Controller/HealthController.php' => "<?php\n\nnamespace Api\\Controller;\n\nuse Symfony\\Component\\HttpFoundation\\JsonResponse;\nuse Symfony\\Component\\Routing\\Attribute\\Route;\n\nfinal class HealthController\n{\n    #[Route('/api/health', name: 'api_health')]\n    public function __invoke(): JsonResponse\n    {\n        return new JsonResponse(['status' => 'ok']);\n    }\n}\n"], [
            'route.api.health' => 'create: initial',
            'route.api.item.list' => 'rewrite: files changed: api/src/Controller/ItemController.php',
            'route.items' => 'rewrite: files removed: front/src/app/item-list.component.ts',
        ], 1];
    }

    private function devtools(string $project, string ...$arguments): string
    {
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/bin/devtools', ...$arguments, $project], $project, [...self::REPRODUCIBLE, 'GIT_CEILING_DIRECTORIES' => \dirname($project)], timeout: 300);
        $process->run();

        self::assertSame('workflows:inspect' === $arguments[0] ? $this->inspectExitCode : 0, $process->getExitCode(), implode(' ', $arguments)."\n".$process->getOutput().$process->getErrorOutput());

        return $process->getOutput();
    }

    /**
     * Copies the recorded draft of every pending brief that has one, with the revision the brief asks for.
     */
    private function copyRecordedDrafts(string $project, string $drafts): int
    {
        $copied = 0;

        foreach (glob($project.'/.devtools/pending/*.brief.xml') ?: [] as $brief) {
            $xml = (string) file_get_contents($brief);

            if (1 !== preg_match('/<draft path="([^"]+)"/', $xml, $draft)) {
                continue;
            }

            $recorded = self::FIXTURES.'/drafts/'.$drafts.'/'.basename($draft[1]);

            if (!is_file($recorded) || is_file($project.'/'.$draft[1])) {
                continue;
            }

            $content = (string) file_get_contents($recorded);

            if (1 === preg_match('/\brevision="([^"]+)"/', $xml, $revision)) {
                $content = (string) preg_replace('/^revision: .*$/m', 'revision: '.$revision[1], $content, 1);
            }

            file_put_contents($project.'/'.$draft[1], $content);
            ++$copied;
        }

        return $copied;
    }

    /**
     * @return array<string, string> workflow => "decision: why", from the "Decisions" table of the last report
     */
    private function decisions(string $project): array
    {
        $reports = glob($project.'/.devtools/reports/inspect-*.md') ?: [];
        usort($reports, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        preg_match_all('/^\| `([^`]+)` \| ([a-z-]+) \| (.*) \|$/m', (string) file_get_contents($reports[0] ?? '/dev/null'), $rows, \PREG_SET_ORDER);
        $decisions = [];

        foreach ($rows as [, $workflow, $decision, $why]) {
            $decisions[$workflow] = $decision.': '.$why;
        }

        ksort($decisions);

        return $decisions;
    }

    /**
     * @return array<string, string>
     */
    private static function devtoolsTree(string $project): array
    {
        return array_filter(self::tree($project.'/.devtools'), static fn (string $path): bool => !str_starts_with($path, 'reports/'), \ARRAY_FILTER_USE_KEY);
    }

    private function assertSnapshot(string $project, string $name): void
    {
        $expected = self::FIXTURES.'/matrix/'.$name;
        $actual = [];

        foreach (self::devtoolsTree($project) as $path => $content) {
            if (!str_starts_with($path, 'pending/') && !str_starts_with($path, 'schemas/')) {
                $actual[$path] = (string) preg_replace('/tool="devtools [^"]*"/', 'tool="devtools"', $content);
            }
        }

        if ('1' === getenv('DEVTOOLS_UPDATE_SNAPSHOTS')) {
            new Filesystem()->remove($expected);

            foreach ($actual as $path => $content) {
                new Filesystem()->dumpFile($expected.'/'.$path, $content);
            }
        }

        self::assertSame(is_dir($expected) ? self::tree($expected) : [], $actual);
    }
}
