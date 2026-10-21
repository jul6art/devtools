<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Assertion;

use Jul6Art\DevTools\Ai\DraftApplier;
use Jul6Art\DevTools\Assertion\Assertion;
use Jul6Art\DevTools\Assertion\AssertionBrief;
use Jul6Art\DevTools\Assertion\AssertionDraftApplier;
use Jul6Art\DevTools\Assertion\AssertionProposal;
use Jul6Art\DevTools\Assertion\AssertionRepository;
use Jul6Art\DevTools\Assertion\Expect;
use Jul6Art\DevTools\Assertion\PatternCheck;
use Jul6Art\DevTools\Assertion\XmlAssertionBriefStore;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\AssertionsProposeCommand;
use Jul6Art\DevTools\Feedback\FeedbackRecorder;
use Jul6Art\DevTools\Feedback\FeedbackTarget;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ADR-0027, `assertions:propose`: the draft is run before it is written — it passes on the project as it is, and
 * fails at the commit its feedback was reported at, where the defect was. One that cannot tell them apart is refused.
 */
#[CoversClass(AssertionProposal::class)]
#[CoversClass(AssertionBrief::class)]
#[CoversClass(XmlAssertionBriefStore::class)]
#[CoversClass(AssertionDraftApplier::class)]
#[CoversClass(AssertionsProposeCommand::class)]
final class AssertionProposalTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string RULE = 'rule-product-fields-use-select2';

    private const string FORM = 'templates/order/_form.html.twig';

    private const string DEFECT = "    <select name=\"product\"></select>\n";

    private const string DRAFT = <<<'XML'
        <assertion xmlns="https://github.com/jul6art/devtools/schema/assertion/1" schema-version="1" id="assert-no-native-select" rule="rule-product-fields-use-select2">
          <watch glob="templates/**/*.twig"/>
          <pattern regex="/&lt;select\b(?![^&gt;]*data-controller=&quot;select2&quot;)/i" expect="must-not-match"/>
          <message>A select in a template uses Select2.</message>
        </assertion>
        XML;

    private string $project;

    private string $initial;

    private string $defect;

    /**
     * The defect committed, reported at that commit, then fixed in the working tree.
     */
    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($this->project, noAi: true));
        $repository = GitRepository::initialise($this->project);
        $this->initial = $repository->commitAll('documented');
        $form = (string) file_get_contents($this->project.'/'.self::FORM);
        file_put_contents($this->project.'/'.self::FORM, str_replace('    <button', self::DEFECT.'    <button', $form));
        $this->defect = $repository->commitAll('the defect');

        $feedback = FeedbackRecorder::for($this->directory(), new FrozenClock(new \DateTimeImmutable('2026-10-20T10:00:00+02:00')))->create(new FeedbackTarget('route.order.new', '#order_product', self::FORM), 'ui-component', 'Le champ produit doit être un select2.');
        new RuleRepository($this->directory())->save(new Rule(self::RULE, RuleStatus::Active, '2026-10-20', [$feedback->id], new AppliesTo([self::FORM], ['routes']), 'A select in a form uses Select2.'));
        file_put_contents($this->project.'/'.self::FORM, $form);
    }

    public function testAnAssertionThatTellsTheDefectFromItsFixIsAccepted(): void
    {
        $propose = new CommandTester(new AssertionsProposeCommand($this->project));

        self::assertSame(0, $propose->execute(['rule' => self::RULE]), $propose->getDisplay());
        self::assertStringContainsString('.devtools/pending/assertion.'.self::RULE.'.brief.xml', $propose->getDisplay());
        $brief = new XmlAssertionBriefStore()->read($this->project.'/.devtools/pending/assertion.'.self::RULE.'.brief.xml');
        self::assertSame([$this->defect], $brief->before, 'Where the feedback was reported.');
        self::assertSame([self::FORM], $brief->files);
        self::assertSame([self::FORM], $brief->rule['globs']);
        self::assertSame(['routes'], $brief->rule['workflowTypes']);
        self::assertSame($this->defect, $brief->feedback[0]['commit']);
        self::assertSame([['name' => 'symfony-console', 'path' => 'bin/console']], $brief->tools);
        self::assertFileExists($brief->promptPath);

        file_put_contents($this->project.'/'.$brief->draftPath, self::DRAFT);
        $applied = new DraftApplier()->apply(new ProjectRoot($this->project));

        self::assertSame([], $applied->refused);
        self::assertSame(['assert-no-native-select'], $applied->accepted);
        self::assertSame([], $applied->warnings);
        self::assertSame('assert-no-native-select', new RuleRepository($this->directory())->get(self::RULE)->assertion);
        self::assertTrue(new AssertionRepository($this->directory())->has('assert-no-native-select'));
        self::assertFileDoesNotExist($this->project.'/'.$brief->draftPath);
    }

    /**
     * @param list<array{string, string}> $edits
     */
    #[DataProvider('refused')]
    public function testADraftThatCannotBeTrustedIsRefusedAndNothingIsWritten(array $edits, string $cause): void
    {
        new AssertionProposal($this->directory())->brief(self::RULE);
        new AssertionRepository($this->directory())->save(new Assertion('assert-taken', 'rule-other', ['src/**'], new PatternCheck('/x/', Expect::MustMatch), 'Taken.'));
        $draft = self::DRAFT;

        foreach ($edits as [$from, $to]) {
            $draft = str_replace($from, $to, $draft);
        }

        file_put_contents($this->project.'/.devtools/pending/assertion.'.self::RULE.'.draft.xml', $draft);
        $applied = new DraftApplier()->apply(new ProjectRoot($this->project));

        self::assertSame([], $applied->accepted);
        self::assertStringContainsString($cause, implode("\n", $applied->refused['assertion.'.self::RULE] ?? []));
        self::assertNull(new RuleRepository($this->directory())->get(self::RULE)->assertion);
        self::assertFalse(new AssertionRepository($this->directory())->has('assert-no-native-select'));
    }

    /**
     * @return iterable<string, array{list<array{string, string}>, string}>
     */
    public static function refused(): iterable
    {
        yield 'it does not tell the defect from its fix' => [[['/&lt;select\b(?![^&gt;]*data-controller=&quot;select2&quot;)/i', '/&lt;marquee/i']], 'too, where its feedback was reported: it does not tell the defect from its fix'];
        yield 'it fails where the rule is followed' => [[['/&lt;select\b(?![^&gt;]*data-controller=&quot;select2&quot;)/i', '/form_widget/']], 'It fails on the project as it is, where the rule is followed: templates/order/_form.html.twig:2'];
        yield 'another rule' => [[['rule="rule-product-fields-use-select2"', 'rule="rule-other"']], 'The assertion enforces the rule of its brief'];
        yield 'an identifier taken' => [[['id="assert-no-native-select"', 'id="assert-taken"']], '"assert-taken" exists already'];
        yield 'a glob that matches nothing' => [[['templates/**/*.twig', 'templates/invoice/*.twig']], '"templates/invoice/*.twig" matches no file'];
        yield 'a glob that leaves the project' => [[['templates/**/*.twig', 'templates/../../*.twig']], 'leaves the project'];
        yield 'a regular expression PHP does not read' => [[['/&lt;select\b(?![^&gt;]*data-controller=&quot;select2&quot;)/i', '/(/']], 'is not a regular expression PHP reads'];
        yield 'a DOM without a run' => [[['<pattern regex="/&lt;select\b(?![^&gt;]*data-controller=&quot;select2&quot;)/i" expect="must-not-match"/>', '<dom selector="#order_product" expect="must-exist" scenario="route.order.new"/>']], 'It cannot be checked on the project as it is: no scenario was replayed'];
        yield 'not an assertion' => [[['<message>', '<messages>'], ['</message>', '</messages>']], 'messages'];
    }

    /**
     * A DOM assertion passes on the captures of the last run; no commit has a DOM, and the human is told so.
     */
    public function testADomAssertionIsCheckedOnTheLastRunAndSaysWhatItCouldNotCheck(): void
    {
        $artifacts = $this->project.'/.devtools/runs/2026-10-20-100000';
        new Filesystem()->dumpFile($artifacts.'/route.order.new/happy-path/after-load.html', '<!DOCTYPE html><html><body><select id="order_product" class="select2-hidden-accessible"></select></body></html>');
        new XmlRunWriter()->write($artifacts.'/result.xml', new RunResult(new \DateTimeImmutable('2026-10-20T10:00:00+02:00'), 'http://app.localhost', [new ScenarioResult('route.order.new/happy-path', [new StepResult(1, 'screenshot', Verdict::Passed, screenshot: 'route.order.new/happy-path/after-load.png', dom: 'route.order.new/happy-path/after-load.html')])]));

        new AssertionProposal($this->directory())->brief(self::RULE);
        self::assertSame([['scenario' => 'route.order.new/happy-path', 'name' => 'after-load', 'dom' => '.devtools/runs/2026-10-20-100000/route.order.new/happy-path/after-load.html']], new XmlAssertionBriefStore()->read($this->project.'/.devtools/pending/assertion.'.self::RULE.'.brief.xml')->captures);

        file_put_contents($this->project.'/.devtools/pending/assertion.'.self::RULE.'.draft.xml', str_replace('<pattern regex="/&lt;select\b(?![^&gt;]*data-controller=&quot;select2&quot;)/i" expect="must-not-match"/>', '<dom selector="#order_product.select2-hidden-accessible" expect="must-exist" scenario="route.order.new"/>', self::DRAFT));
        $applied = new DraftApplier()->apply(new ProjectRoot($this->project));

        self::assertSame(['assert-no-native-select'], $applied->accepted);
        self::assertSame(['assert-no-native-select passes on the project as it is; a dom assertion is not checked at a commit — make sure it fails where the defect is.'], $applied->warnings);
    }

    /**
     * The defect may not have been committed when it was reported: --before names a commit that has it — and one
     * that has it not refuses an assertion which passes there.
     */
    public function testBeforeNamesTheCommitsWhereItMustFail(): void
    {
        $propose = new CommandTester(new AssertionsProposeCommand($this->project));

        self::assertSame(0, $propose->execute(['rule' => self::RULE, '--before' => [$this->initial, 'deadbeef']]));
        self::assertSame([$this->initial], new XmlAssertionBriefStore()->read($this->project.'/.devtools/pending/assertion.'.self::RULE.'.brief.xml')->before, 'A commit git does not know is left out.');

        file_put_contents($this->project.'/.devtools/pending/assertion.'.self::RULE.'.draft.xml', self::DRAFT);
        self::assertStringContainsString('It passes at '.$this->initial.' too', implode("\n", new DraftApplier()->apply(new ProjectRoot($this->project))->refused['assertion.'.self::RULE] ?? []));

        self::assertSame(0, $propose->execute(['rule' => self::RULE, '--before' => ['deadbeef']]));
        $applied = new DraftApplier()->apply(new ProjectRoot($this->project));
        self::assertSame(['assert-no-native-select'], $applied->accepted);
        self::assertSame(['assert-no-native-select passes on the project as it is; no commit says where its feedback was reported, so nothing showed it fails there.'], $applied->warnings);
    }

    public function testWhatCannotBeProposedIsSaid(): void
    {
        $rules = new RuleRepository($this->directory());
        $propose = new CommandTester(new AssertionsProposeCommand($this->project));

        self::assertSame(1, $propose->execute(['rule' => 'rule-nowhere']));
        self::assertStringContainsString('No rule "rule-nowhere"', $propose->getDisplay());

        $rules->save($rules->get(self::RULE)->withStatus(RuleStatus::Archived));
        self::assertSame(1, $propose->execute(['rule' => self::RULE]));
        self::assertStringContainsString('is archived', $propose->getDisplay());

        $rules->save($rules->get(self::RULE)->withStatus(RuleStatus::Active)->withAssertion('assert-there'));
        self::assertSame(1, $propose->execute(['rule' => self::RULE]));
        self::assertStringContainsString('is already checked by assert-there', $propose->getDisplay());

        $rules->save(new Rule('rule-orphan', RuleStatus::Active, '2026-10-20', ['fb-2026-01-01-gone'], new AppliesTo(['src/**']), 'Orphan.'));
        self::assertSame(1, $propose->execute(['rule' => 'rule-orphan']));
        self::assertStringContainsString('comes from no feedback this project still has', $propose->getDisplay());

        self::assertSame(2, new CommandTester(new AssertionsProposeCommand())->execute(['rule' => self::RULE, '--path' => $this->project.'/nowhere']));
    }

    public function testADraftWithoutItsBriefIsRefused(): void
    {
        file_put_contents($this->project.'/.devtools/pending/assertion.'.self::RULE.'.draft.xml', self::DRAFT);

        self::assertSame(['assertion.'.self::RULE => ['The draft has no brief: assertions:propose writes one.']], new DraftApplier()->apply(new ProjectRoot($this->project))->refused);
    }

    private function directory(): DevToolsDirectory
    {
        return DevToolsDirectory::documented(new ProjectRoot($this->project));
    }
}
