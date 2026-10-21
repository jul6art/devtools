<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Assertion;

use Jul6Art\DevTools\Assertion\Assertion;
use Jul6Art\DevTools\Assertion\AssertionChecker;
use Jul6Art\DevTools\Assertion\AssertionRepository;
use Jul6Art\DevTools\Assertion\AssertionResult;
use Jul6Art\DevTools\Assertion\CommitTree;
use Jul6Art\DevTools\Assertion\DomCheck;
use Jul6Art\DevTools\Assertion\Expect;
use Jul6Art\DevTools\Assertion\PatternCheck;
use Jul6Art\DevTools\Assertion\ToolCheck;
use Jul6Art\DevTools\Assertion\ToolFindings;
use Jul6Art\DevTools\Assertion\ToolFormat;
use Jul6Art\DevTools\Assertion\ToolOutput;
use Jul6Art\DevTools\Assertion\ToolRunner;
use Jul6Art\DevTools\Assertion\Violation;
use Jul6Art\DevTools\Assertion\WorkingTree;
use Jul6Art\DevTools\Assertion\XmlAssertionStore;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Gate\AssertionsStep;
use Jul6Art\DevTools\Gate\Gate;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rule\AppliesTo;
use Jul6Art\DevTools\Rule\Rule;
use Jul6Art\DevTools\Rule\RuleRepository;
use Jul6Art\DevTools\Rule\RuleStatus;
use Jul6Art\DevTools\Scenario\Run\RunResult;
use Jul6Art\DevTools\Scenario\Run\ScenarioResult;
use Jul6Art\DevTools\Scenario\Run\StepResult;
use Jul6Art\DevTools\Scenario\Run\Verdict;
use Jul6Art\DevTools\Scenario\Run\XmlRunWriter;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ADR-0027 on symfony-minimal inspected: one assertion of each family passes, then fails once the defect is in —
 * and the gate runs only those that watch a file of the change, a tool once for all.
 */
#[CoversClass(Assertion::class)]
#[CoversClass(PatternCheck::class)]
#[CoversClass(DomCheck::class)]
#[CoversClass(ToolCheck::class)]
#[CoversClass(Expect::class)]
#[CoversClass(ToolFormat::class)]
#[CoversClass(XmlAssertionStore::class)]
#[CoversClass(AssertionRepository::class)]
#[CoversClass(AssertionChecker::class)]
#[CoversClass(AssertionResult::class)]
#[CoversClass(Violation::class)]
#[CoversClass(WorkingTree::class)]
#[CoversClass(CommitTree::class)]
#[CoversClass(ToolRunner::class)]
#[CoversClass(ToolOutput::class)]
#[CoversClass(ToolFindings::class)]
#[CoversClass(AssertionsStep::class)]
final class AssertionTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string EXAMPLE = <<<'XML'
        <assertion xmlns="https://github.com/jul6art/devtools/schema/assertion/1" schema-version="1" id="assert-no-native-select" rule="rule-product-fields-use-select2">
          <watch glob="templates/**/*.twig"/>
          <pattern regex="/&lt;select\b(?![^&gt;]*data-controller=&quot;select2&quot;)/i" expect="must-not-match"/>
          <message>A select in a template uses Select2.</message>
        </assertion>
        XML;

    private const string FORM = 'templates/order/_form.html.twig';

    private const string DEFECT = "    <select name=\"product\"></select>\n";

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($this->project, noAi: true));
    }

    public function testTheExampleReadsAndWritesBackToItselfInEachFamily(): void
    {
        $store = new XmlAssertionStore();
        $pattern = $store->read($this->written(self::EXAMPLE));

        self::assertSame('/<select\b(?![^>]*data-controller="select2")/i', $pattern->check instanceof PatternCheck ? $pattern->check->regex : null);
        self::assertSame('pattern', $pattern->family());

        foreach ([$pattern, $this->dom(Expect::MustExist), $this->phpstan('assert-no-undefined-function', 'function.notFound')] as $assertion) {
            self::assertEquals($assertion, $store->read($this->written($store->serialize($assertion))));
        }
    }

    #[DataProvider('invalid')]
    public function testWhatTheSchemaRefusesIsRefused(string $from, string $to): void
    {
        $this->expectException(InvalidXml::class);

        new XmlAssertionStore()->read($this->written(str_replace($from, $to, self::EXAMPLE)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'nothing watched' => ['<watch glob="templates/**/*.twig"/>', ''];
        yield 'two families' => ['<message>', '<dom selector="select" expect="must-exist" scenario="route.order.new"/><message>'];
        yield 'an unknown family' => ['<pattern regex', '<grep regex'];
        yield 'a pattern that must exist' => ['expect="must-not-match"', 'expect="must-exist"'];
        yield 'not an assertion identifier' => ['id="assert-no-native-select"', 'id="no-native-select"'];
        yield 'a message on two lines' => ['uses Select2.', "uses\nSelect2."];
    }

    public function testAPatternPassesThenFailsOnceTheDefectIsIn(): void
    {
        $assertion = new XmlAssertionStore()->read($this->written(self::EXAMPLE));
        $required = new Assertion('assert-select2-controller', 'rule-product-fields-use-select2', [self::FORM], new PatternCheck('/data-controller="select2"/', Expect::MustMatch), 'A form says it uses Select2.');

        [$clean, $missing] = $this->check([$assertion, $required]);
        self::assertTrue($clean->passed());
        self::assertContains(self::FORM, $clean->files);
        self::assertSame([self::FORM.' nothing matches /data-controller="select2"/'], self::where($missing));

        $this->introduceTheDefect();
        [$broken] = $this->check([$assertion]);
        self::assertSame([self::FORM.':3 <select name="product"></select>'], self::where($broken));
        self::assertSame([], $this->check([$assertion], [self::FORM.'.gone'])[0]->violations, 'A file the change deleted is not read.');
        self::assertStringContainsString('watches no file here', (string) $this->check([$assertion], ['src/Kernel.php'])[0]->unverifiable);
    }

    public function testADomAssertionReadsTheCapturesOfARunWithoutABrowser(): void
    {
        $run = $this->recordRun('<select id="order_product" class="select2-hidden-accessible"></select>');
        $exists = $this->dom(Expect::MustExist);
        $absent = $this->dom(Expect::MustNotExist);

        [$found, $notFound] = $this->check([$exists, $absent], null, $run);
        self::assertTrue($found->passed());
        self::assertSame(['.devtools/runs/2026-10-20-100000/route.order.new/happy-path/after-load.html #order_product.select2-hidden-accessible is there'], self::where($notFound));

        $run = $this->recordRun('<select id="order_product"></select>');
        self::assertSame(['.devtools/runs/2026-10-20-100000/route.order.new/happy-path/after-load.html no element matches #order_product.select2-hidden-accessible'], self::where($this->check([$exists], null, $run)[0]));
        self::assertTrue($this->check([$absent], null, $run)[0]->passed());

        $elsewhere = new Assertion('assert-elsewhere', 'rule-x', [self::FORM], new DomCheck('select', Expect::MustExist, 'route.order.new/happy-path', 'after-save'), 'Elsewhere.');
        $broken = new Assertion('assert-broken', 'rule-x', [self::FORM], new DomCheck('select[', Expect::MustExist, 'route.order.new'), 'Broken.');

        self::assertStringContainsString('no capture of route.order.new/happy-path named after-save', (string) $this->check([$elsewhere], null, $run)[0]->unverifiable);
        self::assertStringContainsString('"select[" is not a selector the DOM reads', (string) $this->check([$broken], null, $run)[0]->unverifiable);
        self::assertStringContainsString('no scenario was replayed', (string) $this->check([$exists])[0]->unverifiable);
        self::assertStringContainsString('not a commit', (string) new AssertionChecker(new ProjectRoot($this->project), run: $run)->check([$exists], new CommitTree(new ProjectRoot($this->project), 'HEAD'))[0]->unverifiable);
    }

    /**
     * The project's own PHPStan — here DevTools' — called on the watched files, its report read, filtered on one
     * identifier: what the fixture lacks (its vendor/) is reported too, and does not count.
     */
    public function testAToolAssertionReadsWhatPhpstanReports(): void
    {
        $assertion = $this->phpstan('assert-no-undefined-function', 'function.notFound');
        $tools = new ToolRunner();

        self::assertTrue($this->check([$assertion], tools: $tools)[0]->passed());

        file_put_contents($this->project.'/src/Service/OrderPricing.php', str_replace('        $order->total = ', "        undefined_helper();\n        \$order->total = ", (string) file_get_contents($this->project.'/src/Service/OrderPricing.php')));
        [$broken] = $this->check([$assertion], tools: $tools);

        self::assertSame(['src/Service/OrderPricing.php:19 Function undefined_helper not found.'], self::where($broken));
    }

    public function testTwoPhpstanAssertionsRunPhpstanOnce(): void
    {
        $tools = new ToolRunner();
        $functions = $this->phpstan('assert-no-undefined-function', 'function.notFound');
        $classes = new Assertion('assert-no-unknown-form', 'rule-forms', ['src/Form/*.php'], new ToolCheck($functions->check instanceof ToolCheck ? $functions->check->command : '', ToolFormat::Phpstan, 'class.notFound'), 'A form type extends what exists.');

        [$first, $second] = $this->check([$functions, $classes], tools: $tools);

        self::assertSame(1, $tools->executions());
        self::assertTrue($first->passed());
        self::assertNotSame([], $second->violations, 'The fixture is copied without its vendor/: AbstractType is unknown.');
        self::assertSame(['src/Form/CustomerType.php', 'src/Form/OrderType.php'], array_values(array_unique(array_map(static fn (Violation $violation): ?string => $violation->file, $second->violations))));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('reports')]
    public function testWhatAToolSaysIsReadInItsFormat(ToolFormat $format, ?string $identifier, ToolOutput $output, array|string $expected): void
    {
        $findings = ToolFindings::read(new ToolCheck('lint', $format, $identifier), $output, new ProjectRoot($this->project));

        self::assertSame($expected, \is_string($findings) ? $findings : array_map(static fn (Violation $violation): string => ltrim($violation->where().' '.$violation->found), $findings));
    }

    /**
     * @return iterable<string, array{ToolFormat, ?string, ToolOutput, list<string>|string}>
     */
    public static function reports(): iterable
    {
        $eslint = (string) json_encode([['filePath' => 'assets/app.js', 'messages' => [['ruleId' => 'no-alert', 'message' => 'Unexpected alert.', 'line' => 3], ['ruleId' => 'semi', 'message' => 'Missing semicolon.', 'line' => 4]]], ['filePath' => 'assets/clean.js', 'messages' => []], 'garbage']);

        yield 'eslint, one rule' => [ToolFormat::Eslint, 'no-alert', new ToolOutput(1, $eslint), ['assets/app.js:3 Unexpected alert.']];
        yield 'eslint, every rule' => [ToolFormat::Eslint, null, new ToolOutput(1, $eslint), ['assets/app.js:3 Unexpected alert.', 'assets/app.js:4 Missing semicolon.']];
        yield 'eslint, not JSON' => [ToolFormat::Eslint, null, new ToolOutput(2, "Oops! Something went wrong.\n"), '"lint" did not answer as eslint --format json: Oops! Something went wrong.'];
        yield 'phpstan, not its JSON' => [ToolFormat::Phpstan, null, new ToolOutput(1, '{"totals": {}}'), '"lint" did not answer as phpstan --format json: {"totals": {}}'];
        yield 'an exit code of 0' => [ToolFormat::ExitCode, null, new ToolOutput(0, 'All good.'), []];
        yield 'an exit code of 3' => [ToolFormat::ExitCode, null, new ToolOutput(3, '', "\n  [ERROR] 2 templates are invalid.\n"), ['[ERROR] 2 templates are invalid.']];
        yield 'an exit code and nothing said' => [ToolFormat::ExitCode, null, new ToolOutput(3, ''), ['exit code 3']];
        yield 'a tool that never finished' => [ToolFormat::ExitCode, null, new ToolOutput(null, '', 'stopped after 1 s'), '"lint" did not finish: stopped after 1 s.'];
    }

    public function testAToolThatHangsIsStopped(): void
    {
        $output = new ToolRunner(0.3)->run(new ProjectRoot($this->project), escapeshellarg(\PHP_BINARY).' -r "sleep(3);"', []);

        self::assertNull($output->exitCode);
        self::assertSame('stopped after 0 s', $output->firstLine());
    }

    /**
     * The gate runs the assertions of the active rules that watch a file of the change — the tool of the other one
     * is not even started — names the rule and where, and counts the change as blocked by the rule.
     */
    public function testTheGateRunsOnlyTheAssertionsOfTheChangeAndCountsTheBlock(): void
    {
        $this->enforced('rule-product-fields-use-select2', new XmlAssertionStore()->read($this->written(self::EXAMPLE)));
        $this->enforced('rule-no-undefined-function', $this->phpstan('assert-no-undefined-function', 'function.notFound'));
        $this->enforced('rule-select2-on-order-new', new Assertion('assert-select2-on-order-new', 'rule-select2-on-order-new', ['templates/**'], new DomCheck('#order_product', Expect::MustExist, 'route.order.new'), 'The product field is there.'));
        new RuleRepository($this->directory())->save(new Rule('rule-proposed', RuleStatus::Proposed, '2026-10-20', ['fb-2026-10-20-x'], new AppliesTo(['templates/**']), 'Not yet.', 'assert-no-native-select'));
        $this->introduceTheDefect();
        $tools = new ToolRunner();

        $result = new Gate([new AssertionsStep($tools)], new FrozenClock(new \DateTimeImmutable('2026-10-20T16:00:00+02:00')))->run($this->directory(), [self::FORM]);
        $step = $result->steps[0];

        self::assertFalse($result->passed());
        self::assertSame(['rule-product-fields-use-select2 (assert-no-native-select): A select in a template uses Select2. — '.self::FORM.':3 <select name="product"></select>'], $step->failures);
        self::assertStringContainsString('rule-select2-on-order-new (assert-select2-on-order-new) cannot tell: no scenario was replayed', implode("\n", $step->warnings), 'The DOM of this passage only — none here.');
        self::assertSame(['2 of 3 assertions watch a file of the change.'], $step->notes);
        self::assertSame(0, $tools->executions(), 'PHPStan watches src/: not started.');

        $stats = new RuleRepository($this->directory())->stats();
        self::assertSame(1, $stats->of('rule-product-fields-use-select2')->blocked);
        self::assertEquals(new \DateTimeImmutable('2026-10-20T16:00:00+02:00'), $stats->of('rule-product-fields-use-select2')->lastBlocked);
        self::assertSame(0, $stats->of('rule-select2-on-order-new')->blocked, 'Could not tell: not a block.');
    }

    public function testTheGateSaysWhenNoRuleIsEnforcedOrAnAssertionIsUnreadable(): void
    {
        $gate = new Gate([new AssertionsStep(new ToolRunner())], new FrozenClock(new \DateTimeImmutable('2026-10-20T16:00:00+02:00')));

        self::assertSame(['No active rule has an assertion.'], $gate->run($this->directory(), [self::FORM])->steps[0]->notes);

        new RuleRepository($this->directory())->save(new Rule('rule-lost', RuleStatus::Active, '2026-10-20', ['fb-2026-10-20-x'], new AppliesTo(['templates/**']), 'Lost.', 'assert-lost'));
        $step = $gate->run($this->directory(), [self::FORM])->steps[0];

        self::assertStringStartsWith('rule-lost: its assertion assert-lost cannot be read', $step->failures[0]);
        self::assertSame(['0 of 1 assertions watch a file of the change.'], $step->notes);
    }

    /**
     * Past five places, the rest is counted.
     */
    public function testManyViolationsAreCountedPastFive(): void
    {
        $this->enforced('rule-product-fields-use-select2', new XmlAssertionStore()->read($this->written(self::EXAMPLE)));
        file_put_contents($this->project.'/'.self::FORM, str_repeat(self::DEFECT, 7));

        $failures = new Gate([new AssertionsStep(new ToolRunner())], new FrozenClock(new \DateTimeImmutable('2026-10-20T16:00:00+02:00')))->run($this->directory(), [self::FORM])->steps[0]->failures;

        self::assertCount(6, $failures);
        self::assertSame('rule-product-fields-use-select2 (assert-no-native-select): and 2 more', $failures[5]);
    }

    public function testACommitIsReadFromGitWithoutTouchingTheWorkingTree(): void
    {
        $repository = GitRepository::initialise($this->project);
        file_put_contents($this->project.'/'.self::FORM, self::DEFECT, \FILE_APPEND);
        $defect = $repository->commitAll('the defect');
        file_put_contents($this->project.'/'.self::FORM, str_replace(self::DEFECT, '', (string) file_get_contents($this->project.'/'.self::FORM)));
        $assertion = new XmlAssertionStore()->read($this->written(self::EXAMPLE));
        $commit = new CommitTree(new ProjectRoot($this->project), $defect);

        self::assertContains(self::FORM, $commit->files());
        self::assertNotContains('vendor/autoload.php', $commit->files());
        self::assertTrue(new AssertionChecker(new ProjectRoot($this->project))->check([$assertion], new WorkingTree(new ProjectRoot($this->project)))[0]->passed());
        self::assertTrue(new AssertionChecker(new ProjectRoot($this->project))->check([$assertion], $commit)[0]->failed());
        self::assertNull($commit->read('templates/order/nope.html.twig'));
        self::assertFalse($commit->isCurrent());
        self::assertNull(new WorkingTree(new ProjectRoot($this->project))->read('../outside.twig'));
    }

    public function testIdentifiersAndExpectationsAreChecked(): void
    {
        foreach ([
            static fn (): Assertion => new Assertion('select2', 'rule-x', ['src/**'], new PatternCheck('/x/', Expect::MustMatch), 'x'),
            static fn (): PatternCheck => new PatternCheck('/x/', Expect::MustExist),
            static fn (): DomCheck => new DomCheck('x', Expect::MustMatch, 'route.x'),
        ] as $build) {
            try {
                $build();
                self::fail('Refused.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertTrue(Expect::MustExist->present());
        self::assertFalse(Expect::MustNotMatch->present());
        self::assertStringContainsString('is not a regular expression PHP reads', (string) new PatternCheck('/(/', Expect::MustMatch)->error());
    }

    private function introduceTheDefect(): void
    {
        $form = (string) file_get_contents($this->project.'/'.self::FORM);
        file_put_contents($this->project.'/'.self::FORM, str_replace('    <button', self::DEFECT.'    <button', $form));
    }

    private function enforced(string $rule, Assertion $assertion): void
    {
        new AssertionRepository($this->directory())->save($assertion);
        new RuleRepository($this->directory())->save(new Rule($rule, RuleStatus::Active, '2026-10-20', ['fb-2026-10-20-x'], new AppliesTo($assertion->watch), 'Follow it.', $assertion->id));
    }

    private function phpstan(string $id, string $identifier): Assertion
    {
        $command = \sprintf('%s %s analyse --error-format=json --no-progress --level=0 --memory-limit=1G %s', escapeshellarg(\PHP_BINARY), escapeshellarg(\dirname(__DIR__, 2).'/vendor/bin/phpstan'), ToolCheck::FILES);

        return new Assertion($id, 'rule-no-undefined-function', ['src/**/*.php'], new ToolCheck($command, ToolFormat::Phpstan, $identifier), 'A function called exists.');
    }

    private function dom(Expect $expect): Assertion
    {
        return new Assertion(Expect::MustExist === $expect ? 'assert-select2-on-order-new' : 'assert-no-select2-on-order-new', 'rule-product-fields-use-select2', [self::FORM], new DomCheck('#order_product.select2-hidden-accessible', $expect, 'route.order.new/happy-path', 'after-load'), 'The product field is a Select2.');
    }

    /**
     * @param list<Assertion>   $assertions
     * @param list<string>|null $scope
     *
     * @return list<AssertionResult>
     */
    private function check(array $assertions, ?array $scope = null, ?string $run = null, ToolRunner $tools = new ToolRunner()): array
    {
        $root = new ProjectRoot($this->project);

        return new AssertionChecker($root, $tools, $run)->check($assertions, new WorkingTree($root), $scope);
    }

    /**
     * @return list<string>
     */
    private static function where(AssertionResult $result): array
    {
        return array_map(static fn (Violation $violation): string => ltrim($violation->where().' '.$violation->found), $result->violations);
    }

    private function recordRun(string $field): string
    {
        $artifacts = $this->project.'/.devtools/runs/2026-10-20-100000';
        new Filesystem()->dumpFile($artifacts.'/route.order.new/happy-path/after-load.html', '<!DOCTYPE html><html><body><form>'.$field.'</form></body></html>');
        new XmlRunWriter()->write($artifacts.'/result.xml', new RunResult(new \DateTimeImmutable('2026-10-20T10:00:00+02:00'), 'http://app.localhost', [
            new ScenarioResult('route.order.new/happy-path', [new StepResult(1, 'goto', Verdict::Passed), new StepResult(2, 'screenshot', Verdict::Passed, screenshot: 'route.order.new/happy-path/after-load.png', dom: 'route.order.new/happy-path/after-load.html')]),
            new ScenarioResult('route.order.index/smoke', [new StepResult(1, 'screenshot', Verdict::Passed, screenshot: 'route.order.index/smoke/list.png', dom: 'route.order.index/smoke/list.html')]),
        ]));

        return $artifacts.'/result.xml';
    }

    private function directory(): DevToolsDirectory
    {
        return DevToolsDirectory::documented(new ProjectRoot($this->project));
    }

    private function written(string $xml): string
    {
        $file = $this->project.'/assertion-'.bin2hex(random_bytes(4)).'.xml';
        file_put_contents($file, $xml);

        return $file;
    }
}
