<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Rule;

use Jul6Art\DevTools\Ai\DraftApplier;
use Jul6Art\DevTools\Claude\Hook\HookInput;
use Jul6Art\DevTools\Claude\Hook\PreToolUseHandler;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\RulesActivateCommand;
use Jul6Art\DevTools\Command\RulesListCommand;
use Jul6Art\DevTools\Command\RulesPromoteCommand;
use Jul6Art\DevTools\Feedback\FeedbackRecorder;
use Jul6Art\DevTools\Feedback\FeedbackStatus;
use Jul6Art\DevTools\Feedback\FeedbackTarget;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rule\AppliesTo;
use Jul6Art\DevTools\Rule\Rule;
use Jul6Art\DevTools\Rule\RuleBrief;
use Jul6Art\DevTools\Rule\RuleChecks;
use Jul6Art\DevTools\Rule\RuleDraftApplier;
use Jul6Art\DevTools\Rule\RuleIndex;
use Jul6Art\DevTools\Rule\RuleIndexEntry;
use Jul6Art\DevTools\Rule\RuleNotFound;
use Jul6Art\DevTools\Rule\RulePromotion;
use Jul6Art\DevTools\Rule\RuleRepository;
use Jul6Art\DevTools\Rule\RuleStat;
use Jul6Art\DevTools\Rule\RuleStats;
use Jul6Art\DevTools\Rule\RuleStatus;
use Jul6Art\DevTools\Rule\XmlRuleBriefStore;
use Jul6Art\DevTools\Rule\XmlRuleIndexStore;
use Jul6Art\DevTools\Rule\XmlRuleStatsStore;
use Jul6Art\DevTools\Rule\XmlRuleStore;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ADR-0026 on symfony-minimal inspected: feedback seen twice, promoted through a draft of Claude's, activated by a
 * human, and given to Claude before it edits a file the rule applies to — and to no other.
 */
#[CoversClass(Rule::class)]
#[CoversClass(RuleStatus::class)]
#[CoversClass(AppliesTo::class)]
#[CoversClass(RuleIndex::class)]
#[CoversClass(RuleIndexEntry::class)]
#[CoversClass(RuleStats::class)]
#[CoversClass(RuleStat::class)]
#[CoversClass(RuleRepository::class)]
#[CoversClass(RuleNotFound::class)]
#[CoversClass(RuleBrief::class)]
#[CoversClass(RuleChecks::class)]
#[CoversClass(RulePromotion::class)]
#[CoversClass(RuleDraftApplier::class)]
#[CoversClass(XmlRuleStore::class)]
#[CoversClass(XmlRuleIndexStore::class)]
#[CoversClass(XmlRuleStatsStore::class)]
#[CoversClass(XmlRuleBriefStore::class)]
#[CoversClass(RulesPromoteCommand::class)]
#[CoversClass(RulesActivateCommand::class)]
#[CoversClass(RulesListCommand::class)]
#[CoversClass(PreToolUseHandler::class)]
final class RuleTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string EXAMPLE = <<<'XML'
        <rule xmlns="https://github.com/jul6art/devtools/schema/rule/1" schema-version="1" id="rule-product-fields-use-select2" status="proposed" created="2026-01-01">
          <origin feedback="fb-2026-10-20-order-product"/>
          <applies-to>
            <files glob="templates/**/_form.html.twig"/>
            <workflow-type>routes</workflow-type>
            <category>ui-component</category>
          </applies-to>
          <instruction>Un champ de sélection d'entité utilise Select2Type,
            jamais un select natif.</instruction>
        </rule>
        XML;

    private const string FEEDBACK = 'fb-2026-10-20-order-product';

    private const string RULE = 'rule-product-fields-use-select2';

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($this->project, noAi: true));
    }

    public function testTheExampleReadsAndWritesBackToItself(): void
    {
        $store = new XmlRuleStore();
        $rule = $store->read($this->written(self::EXAMPLE));

        self::assertSame('Un champ de sélection d\'entité utilise Select2Type, jamais un select natif.', $rule->instruction, 'Written on two lines, one text.');
        self::assertSame(['templates/**/_form.html.twig'], $rule->appliesTo->globs);
        self::assertSame('ui-component', $rule->appliesTo->category);
        self::assertEquals($rule, $store->read($this->written($store->serialize($rule))));
        self::assertEquals($rule->withAssertion('assert-select2'), $store->read($this->written($store->serialize($rule->withAssertion('assert-select2')))));
    }

    #[DataProvider('invalid')]
    public function testWhatTheSchemaRefusesIsRefused(string $from, string $to): void
    {
        $this->expectException(InvalidXml::class);

        new XmlRuleStore()->read($this->written(str_replace($from, $to, self::EXAMPLE)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no files and no kind of workflow' => ['<files glob="templates/**/_form.html.twig"/>
    <workflow-type>routes</workflow-type>', ''];
        yield 'no applies-to at all' => ['<category>ui-component</category>', '<category>ui-component</category></applies-to><applies-to>'];
        yield 'no feedback of origin' => ['feedback="fb-2026-10-20-order-product"', 'feedback=" "'];
        yield 'an empty instruction' => ["Un champ de sélection d'entité utilise Select2Type,\n    jamais un select natif.", ' '];
        yield 'an unknown status' => ['status="proposed"', 'status="enforced"'];
        yield 'an identifier that is not a rule\'s' => ['id="rule-product-fields-use-select2"', 'id="product-fields"'];
        yield 'an absolute glob' => ['glob="templates/**/_form.html.twig"', 'glob="/templates/_form.html.twig"'];
    }

    /**
     * @param int<0, max> $expected
     */
    #[DataProvider('sentences')]
    public function testSentencesAreCounted(string $instruction, int $expected): void
    {
        self::assertSame($expected, Rule::sentences($instruction));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function sentences(): iterable
    {
        yield 'nothing' => [' ', 0];
        yield 'one, ended' => ['Use Select2Type.', 1];
        yield 'one, not ended' => ['Use Select2Type', 1];
        yield 'an abbreviation is not an end' => ['Use a widget, e.g. select2, for long lists.', 1];
        yield 'two, the last not ended' => ['Use Select2Type. Never a native select', 2];
        yield 'three' => ['Use Select2Type. Never a native select! Why? ', 3];
        yield 'four' => ['One. Two. Three. Four.', 4];
        yield 'an ellipsis ends one' => ['Wait… Then go.', 2];
    }

    public function testFeedbackSeenTwiceBecomesAProposedRuleThroughADraft(): void
    {
        $this->feedbackSeenTwice();
        $promote = new CommandTester(new RulesPromoteCommand($this->clock(), $this->project));

        self::assertSame(0, $promote->execute(['feedback' => [self::FEEDBACK]]), self::flat($promote));
        self::assertStringContainsString('.devtools/pending/rule.'.self::FEEDBACK.'.brief.xml', self::flat($promote));
        $brief = new XmlRuleBriefStore()->read($this->project.'/.devtools/pending/rule.'.self::FEEDBACK.'.brief.xml');
        self::assertSame('2026-10-20', $brief->date);
        self::assertSame([self::FEEDBACK], $brief->feedbackIds());
        self::assertSame(2, $brief->feedback[0]['occurrences']);
        self::assertContains('templates/order/_form.html.twig', $brief->files, 'The file the feedback is about.');
        self::assertContains('src/Form/OrderType.php', $brief->files, 'And the files of its workflow.');
        self::assertContains('routes', $brief->workflowTypes);
        self::assertContains('ui-component', $brief->categories);
        self::assertStringEndsWith('prompts/rule/v1.md', $brief->promptPath);
        self::assertFileExists($brief->promptPath);
        self::assertSame('.devtools/pending/rule.'.self::FEEDBACK.'.draft.xml', $brief->draftPath);

        file_put_contents($this->project.'/'.$brief->draftPath, self::EXAMPLE);
        $applied = new DraftApplier($this->clock())->apply(new ProjectRoot($this->project));

        self::assertSame([], $applied->refused);
        self::assertSame([self::RULE], $applied->accepted);
        $rule = $this->rules()->get(self::RULE);
        self::assertSame(RuleStatus::Proposed, $rule->status);
        self::assertSame('2026-10-20', $rule->created, 'The day of the brief, whatever the draft said.');
        $feedback = FeedbackRecorder::for($this->directory())->repository()->get(self::FEEDBACK);
        self::assertSame(FeedbackStatus::Promoted, $feedback->status);
        self::assertSame(self::RULE, $feedback->promotedTo);
        self::assertFileDoesNotExist($this->project.'/'.$brief->draftPath);
        self::assertFileDoesNotExist($this->project.'/.devtools/pending/rule.'.self::FEEDBACK.'.brief.xml');

        self::assertSame(1, $promote->execute(['feedback' => [self::FEEDBACK]]));
        self::assertStringContainsString('is already the rule '.self::RULE, self::flat($promote));
    }

    /**
     * @param list<array{string, string}> $edits
     */
    #[DataProvider('refusedDrafts')]
    public function testADraftThatBreaksARuleIsRefusedAndNothingIsWritten(array $edits, string $cause): void
    {
        $this->feedbackSeenTwice();
        new RulePromotion($this->directory(), $this->clock())->brief([self::FEEDBACK]);
        $this->rules()->save(new Rule('rule-taken', RuleStatus::Active, '2026-10-01', ['fb-2026-10-01-other'], new AppliesTo(['src/**']), 'Something.'));
        $draft = self::EXAMPLE;

        foreach ($edits as [$from, $to]) {
            $draft = str_replace($from, $to, $draft);
        }

        file_put_contents($this->project.'/.devtools/pending/rule.'.self::FEEDBACK.'.draft.xml', $draft);
        $applied = new DraftApplier($this->clock())->apply(new ProjectRoot($this->project));

        self::assertSame([], $applied->accepted);
        self::assertStringContainsString($cause, implode("\n", $applied->refused['rule.'.self::FEEDBACK] ?? []));
        self::assertNull($this->rules()->index()->entry(self::RULE));
        self::assertSame(FeedbackStatus::Open, FeedbackRecorder::for($this->directory())->repository()->get(self::FEEDBACK)->status);
        self::assertFileExists($this->project.'/.devtools/pending/rule.'.self::FEEDBACK.'.draft.xml', 'Kept, to be corrected.');
    }

    /**
     * @return iterable<string, array{list<array{string, string}>, string}>
     */
    public static function refusedDrafts(): iterable
    {
        yield 'a glob that matches nothing' => [[['templates/**/_form.html.twig', 'templates/invoice/*.twig']], '"templates/invoice/*.twig" matches no file'];
        yield 'a glob that leaves the project' => [[['templates/**/_form.html.twig', 'templates/../../x/*.twig']], 'leaves the project'];
        yield 'four sentences' => [[['jamais un select natif.', 'jamais un select natif. Deux. Trois. Quatre.']], '4 sentences long: 3 at most'];
        yield 'an active rule' => [[['status="proposed"', 'status="active"']], 'a human activates it'];
        yield 'another feedback' => [[['feedback="fb-2026-10-20-order-product"', 'feedback="fb-2026-10-20-order-product fb-2026-10-20-other"']], 'all of it and nothing else'];
        yield 'a rule that exists' => [[['id="rule-product-fields-use-select2"', 'id="rule-taken"']], '"rule-taken" exists already'];
        yield 'an assertion' => [[['</rule>', '<assertion ref="assert-select2"/></rule>']], 'assertions:propose writes it'];
        yield 'an unknown kind of workflow' => [[['<workflow-type>routes</workflow-type>', '<workflow-type>pages</workflow-type>']], '"pages" is not a kind of workflow'];
        yield 'an unknown category' => [[['<category>ui-component</category>', '<category>aesthetics</category>']], '"aesthetics" is not a category'];
        yield 'not a rule' => [[['<instruction>', '<instructions>'], ['</instruction>', '</instructions>']], 'instructions'];
    }

    public function testADraftWithoutItsBriefOrWhoseFeedbackMovedIsRefused(): void
    {
        $this->feedbackSeenTwice();
        file_put_contents($this->project.'/.devtools/pending/rule.'.self::FEEDBACK.'.draft.xml', self::EXAMPLE);

        $withoutBrief = new DraftApplier($this->clock())->apply(new ProjectRoot($this->project));
        self::assertSame(['rule.'.self::FEEDBACK => ['The draft has no brief: rules:promote writes one.']], $withoutBrief->refused);

        new RulePromotion($this->directory(), $this->clock())->brief([self::FEEDBACK]);
        FeedbackRecorder::for($this->directory(), $this->clock())->resolve(self::FEEDBACK, FeedbackStatus::WontFix);

        $setAside = new DraftApplier($this->clock())->apply(new ProjectRoot($this->project));
        self::assertStringContainsString('promoted or set aside since its brief', implode("\n", $setAside->refused['rule.'.self::FEEDBACK] ?? []));
    }

    public function testWhatCannotBePromotedIsSaid(): void
    {
        $recorder = FeedbackRecorder::for($this->directory(), $this->clock());
        $set = $recorder->create(new FeedbackTarget(selector: '.flash'), 'layout', 'Le message masque le titre.');
        $recorder->resolve($set->id, FeedbackStatus::WontFix);
        $promote = new CommandTester(new RulesPromoteCommand($this->clock(), $this->project));

        self::assertSame(1, $promote->execute(['feedback' => ['fb-2026-01-01-nothing', $set->id]]));
        self::assertStringContainsString('No feedback "fb-2026-01-01-nothing"', self::flat($promote));
        self::assertStringContainsString('set aside as wont-fix', self::flat($promote));
        self::assertSame(['Name the feedback to promote: feedback:list gives the identifiers.'], new RulePromotion($this->directory())->refusals([]));
        self::assertSame(2, $promote->execute(['feedback' => [$set->id], '--path' => $this->project.'/nowhere']));
    }

    /**
     * A proposed rule is never given to Claude; activated, it is — on the files it applies to, and on no other.
     */
    public function testOnlyAnActiveRuleIsGivenAndOnlyWhereItApplies(): void
    {
        $this->rules()->save(new Rule(self::RULE, RuleStatus::Proposed, '2026-10-20', [self::FEEDBACK], new AppliesTo(['templates/**/_form.html.twig'], ['routes'], 'ui-component'), 'Un champ de sélection d\'entité utilise Select2Type.'));

        self::assertStringNotContainsString(self::RULE, $this->context('templates/order/_form.html.twig'));

        $activate = new CommandTester(new RulesActivateCommand($this->project));
        self::assertSame(0, $activate->execute(['rules' => [self::RULE]]));
        self::assertStringContainsString(self::RULE.' is active', self::flat($activate));

        self::assertStringContainsString("DevTools: the rules of this project for templates/order/_form.html.twig — follow them:\n- ".self::RULE.': Un champ de sélection d\'entité utilise Select2Type.', $this->context('templates/order/_form.html.twig'));
        self::assertStringNotContainsString(self::RULE, $this->context('src/Form/OrderType.php'), 'Another file.');
        self::assertSame(1, $this->rules()->stats()->of(self::RULE)->applied);
        self::assertEquals(new \DateTimeImmutable('2026-10-20T10:00:00+02:00'), $this->rules()->stats()->of(self::RULE)->lastApplied);

        self::assertSame(0, $activate->execute(['rules' => [self::RULE]]));
        self::assertStringContainsString('already active', self::flat($activate));
    }

    /**
     * Of all those that apply, five at most, the most specific first: the longest glob written out, then the rule
     * that asks for both a file and a kind of workflow, then the one that names the fewest kinds.
     */
    public function testAtMostFiveRulesTheMostSpecificFirst(): void
    {
        $rules = [
            'rule-any-twig' => new AppliesTo(['**/*.twig']),
            'rule-all-templates' => new AppliesTo(['templates/**']),
            'rule-routes-and-commands' => new AppliesTo(workflowTypes: ['routes', 'commands']),
            'rule-routes' => new AppliesTo(workflowTypes: ['routes']),
            'rule-order-templates' => new AppliesTo(['templates/order/*.html.twig']),
            'rule-form-templates-of-routes' => new AppliesTo(['templates/**/_form.html.twig', 'src/**'], ['routes']),
            'rule-exact-form' => new AppliesTo(['templates/order/_form.html.twig']),
            'rule-sources' => new AppliesTo(['src/**']),
            'rule-commands' => new AppliesTo(['templates/**'], ['commands']),
        ];

        foreach ($rules as $id => $appliesTo) {
            $this->rules()->save(new Rule($id, RuleStatus::Active, '2026-10-20', [self::FEEDBACK], $appliesTo, 'Do it.'));
        }

        $this->rules()->save(new Rule('rule-proposed-exact', RuleStatus::Proposed, '2026-10-20', [self::FEEDBACK], new AppliesTo(['templates/order/_form.html.twig']), 'Do it.'));

        preg_match_all('/^- (rule-[a-z-]+):/m', $this->context('templates/order/_form.html.twig'), $given);
        self::assertSame(['rule-exact-form', 'rule-form-templates-of-routes', 'rule-order-templates', 'rule-all-templates', 'rule-any-twig'], $given[1]);
        self::assertSame(['rule-routes', 'rule-routes-and-commands'], array_map(static fn (RuleIndexEntry $rule): string => $rule->id, \array_slice($this->rules()->index()->applicableTo('templates/order/_form.html.twig', ['routes'], 7), 5)), 'Then the kinds of workflow, the fewest first.');
        self::assertSame(0, $this->rules()->stats()->of('rule-routes')->applied, 'Not given, not counted.');

        preg_match_all('/^- (rule-[a-z-]+):/m', $this->context('src/Command/ImportCatalogCommand.php'), $given);
        self::assertSame(['rule-sources', 'rule-routes-and-commands'], $given[1], 'A command: what applies to its file, and to its kind.');
    }

    public function testRulesAreListedWithWhatTheyDid(): void
    {
        $list = new CommandTester(new RulesListCommand($this->project));
        self::assertSame(0, $list->execute([]));
        self::assertStringContainsString('No proposed or active rule.', self::flat($list));

        $this->rules()->save(new Rule(self::RULE, RuleStatus::Active, '2026-10-20', [self::FEEDBACK], new AppliesTo(['templates/**/_form.html.twig'], ['routes']), 'Use Select2Type.'));
        $this->rules()->save(new Rule('rule-old', RuleStatus::Archived, '2026-09-01', [self::FEEDBACK], new AppliesTo(['src/**']), 'Old.'));
        $this->rules()->saveStats(new RuleStats()->applied([self::RULE], new \DateTimeImmutable('2026-10-20T10:00:00+02:00'))->blocked(self::RULE, new \DateTimeImmutable('2026-10-20T11:00:00+02:00')));

        self::assertSame(0, $list->execute([]));
        self::assertMatchesRegularExpression('/'.self::RULE.'\s+active\s+templates\/\*\*\/_form\.html\.twig · routes\s+1\s+1\s+Use Select2Type\./', self::flat($list));
        self::assertStringNotContainsString('rule-old', self::flat($list));
        self::assertSame(0, $list->execute(['--status' => 'archived']));
        self::assertStringContainsString('rule-old', self::flat($list));
        self::assertSame(2, $list->execute(['--status' => 'enforced']));
        self::assertSame(2, new CommandTester(new RulesListCommand())->execute(['--path' => $this->project.'/nowhere']));
    }

    /**
     * Checked again before it is given: a rule whose files are gone is not activated.
     */
    public function testARuleWhoseFilesAreGoneIsNotActivated(): void
    {
        $this->rules()->save(new Rule(self::RULE, RuleStatus::Proposed, '2026-10-20', [self::FEEDBACK], new AppliesTo(['templates/order/_form.html.twig']), 'Use Select2Type.'));
        unlink($this->project.'/templates/order/_form.html.twig');
        $activate = new CommandTester(new RulesActivateCommand($this->project));

        self::assertSame(1, $activate->execute(['rules' => [self::RULE, 'rule-nowhere']]));
        self::assertStringContainsString('cannot be activated', self::flat($activate));
        self::assertStringContainsString('No rule "rule-nowhere"', self::flat($activate));
        self::assertSame(RuleStatus::Proposed, $this->rules()->get(self::RULE)->status);
        self::assertSame(2, new CommandTester(new RulesActivateCommand())->execute(['rules' => [self::RULE], '--path' => $this->project.'/nowhere']));
    }

    /**
     * A count is not worth stopping for: a stats file that cannot be read counts from zero. An index that cannot be
     * read is said, and the impact is still given.
     */
    public function testBrokenFilesDoNotKeepClaudeFromWorking(): void
    {
        $this->rules()->save(new Rule(self::RULE, RuleStatus::Active, '2026-10-20', [self::FEEDBACK], new AppliesTo(['templates/**']), 'Use Select2Type.'));
        new Filesystem()->dumpFile($this->project.'/.devtools/stats/rules.xml', '<broken');

        self::assertEquals(new RuleStats(), $this->rules()->stats());
        self::assertStringContainsString('- '.self::RULE, $this->context('templates/order/_form.html.twig'));
        self::assertSame(1, $this->rules()->stats()->of(self::RULE)->applied);

        file_put_contents($this->project.'/.devtools/rules/index.xml', '<broken');
        $context = $this->context('templates/order/_form.html.twig');
        self::assertStringContainsString('documented workflow', $context);
        self::assertStringContainsString('⚠ DevTools cannot read the rules', $context);
    }

    public function testRuleIdentifiersAreChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Rule('select2', RuleStatus::Proposed, '2026-10-20', [self::FEEDBACK], new AppliesTo(['src/**']), 'Use it.');
    }

    /**
     * The display on one line: SymfonyStyle wraps what it says.
     */
    private static function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function feedbackSeenTwice(): void
    {
        $recorder = FeedbackRecorder::for($this->directory(), $this->clock());
        $feedback = $recorder->create(new FeedbackTarget('route.order.new', '#order_product', 'templates/order/_form.html.twig'), 'ui-component', 'Le champ produit doit être un select2.');
        $recorder->seenAgain($feedback->id, new FeedbackTarget(selector: '#order_product'));
    }

    private function context(string $file): string
    {
        $answer = new PreToolUseHandler(clock: $this->clock())->handle(HookInput::parse((string) json_encode(['hook_event_name' => 'PreToolUse', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => $this->project.'/'.$file]])), $this->directory());

        $output = $answer['hookSpecificOutput'] ?? null;

        return \is_array($output) && \is_string($output['additionalContext'] ?? null) ? $output['additionalContext'] : '';
    }

    private function rules(): RuleRepository
    {
        return new RuleRepository($this->directory());
    }

    private function directory(): DevToolsDirectory
    {
        return DevToolsDirectory::documented(new ProjectRoot($this->project));
    }

    private function clock(): FrozenClock
    {
        return new FrozenClock(new \DateTimeImmutable('2026-10-20T10:00:00+02:00'));
    }

    private function written(string $xml): string
    {
        $file = $this->project.'/rule-'.bin2hex(random_bytes(4)).'.xml';
        file_put_contents($file, $xml);

        return $file;
    }
}
