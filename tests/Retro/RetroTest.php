<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Retro;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Command\RetroCommand;
use Jul6Art\DevTools\Command\RulesArchiveCommand;
use Jul6Art\DevTools\Feedback\Feedback;
use Jul6Art\DevTools\Feedback\FeedbackRepository;
use Jul6Art\DevTools\Feedback\FeedbackSource;
use Jul6Art\DevTools\Feedback\FeedbackStatus;
use Jul6Art\DevTools\Feedback\FeedbackTarget;
use Jul6Art\DevTools\Feedback\Occurrence;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Retro\JournalRepository;
use Jul6Art\DevTools\Retro\RetroMarkdown;
use Jul6Art\DevTools\Retro\RetroReport;
use Jul6Art\DevTools\Retro\Retrospective;
use Jul6Art\DevTools\Retro\Task;
use Jul6Art\DevTools\Retro\XmlRetroWriter;
use Jul6Art\DevTools\Rule\AppliesTo;
use Jul6Art\DevTools\Rule\Rule;
use Jul6Art\DevTools\Rule\RuleRepository;
use Jul6Art\DevTools\Rule\RuleStat;
use Jul6Art\DevTools\Rule\RuleStats;
use Jul6Art\DevTools\Rule\RuleStatus;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0029: four months of a project — eight tasks, their remarks, its rules — and the retrospective that says
 * whether feedback goes down, section by section.
 */
#[CoversClass(Retrospective::class)]
#[CoversClass(RetroReport::class)]
#[CoversClass(RetroMarkdown::class)]
#[CoversClass(XmlRetroWriter::class)]
#[CoversClass(RetroCommand::class)]
#[CoversClass(RulesArchiveCommand::class)]
final class RetroTest extends TestCase
{
    use AssertsSnapshots;
    use CopiesFixtureProjects;

    private const string SNAPSHOTS = __DIR__.'/../Fixtures/snapshots/retro/';

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]))->run(new InspectionOptions($this->project, noAi: true));
        $this->fourMonths();
    }

    public function testEachSectionIsCountedAsExpected(): void
    {
        $retro = new CommandTester(new RetroCommand($this->clock(), $this->project));

        self::assertSame(0, $retro->execute([]), $retro->getDisplay());
        $display = (string) preg_replace('/\s+/', ' ', $retro->getDisplay());
        self::assertStringContainsString('The retrospective: .devtools/reports/retro-2026-10-21.md', $display);
        self::assertStringContainsString('2026-10: 0.50 human remarks per task, -70 % against the three months before.', $display);
        self::assertStringContainsString('2 rules proposed for archiving: devtools rules:archive <rule>.', $display);
        self::assertStringContainsString('3 feedback seen twice, not promoted', $display);
        self::assertMatchesSnapshot((string) file_get_contents($this->project.'/.devtools/reports/retro-2026-10-21.md'), self::SNAPSHOTS.'retro.md');

        self::assertSame(0, $retro->execute(['--format' => 'xml']));
        self::assertMatchesSnapshot((string) file_get_contents($this->project.'/.devtools/reports/retro-2026-10-21.xml'), self::SNAPSHOTS.'retro.xml');
    }

    /**
     * Proposed, never archived on its own: a human runs rules:archive.
     */
    public function testAnInactiveRuleIsProposedForArchivingNotArchived(): void
    {
        $report = new Retrospective($this->directory(), $this->clock())->report(new \DateTimeImmutable('2026-07-01T00:00:00+02:00'));

        self::assertSame([['rule' => 'rule-labels-in-french', 'last' => '2026-08-20', 'days' => 62], ['rule' => 'rule-never-given', 'last' => '2026-09-01', 'days' => 50]], $report->inactive);
        self::assertSame(RuleStatus::Active, $this->rules()->get('rule-labels-in-french')->status);

        $archive = new CommandTester(new RulesArchiveCommand($this->project));
        self::assertSame(0, $archive->execute(['rules' => ['rule-labels-in-french', 'rule-old']]));
        self::assertStringContainsString('rule-labels-in-french is archived', (string) preg_replace('/\s+/', ' ', $archive->getDisplay()));
        self::assertStringContainsString('rule-old is already archived.', $archive->getDisplay());
        self::assertSame(RuleStatus::Archived, $this->rules()->get('rule-labels-in-french')->status);
        self::assertSame([['rule' => 'rule-never-given', 'last' => '2026-09-01', 'days' => 50]], new Retrospective($this->directory(), $this->clock())->report(new \DateTimeImmutable('2026-07-01T00:00:00+02:00'))->inactive);

        self::assertSame(1, $archive->execute(['rules' => ['rule-nowhere']]));
        self::assertSame(2, new CommandTester(new RulesArchiveCommand())->execute(['rules' => ['rule-old'], '--path' => $this->project.'/nowhere']));
    }

    public function testWithLessThanFourMonthsThereIsNoTrendYet(): void
    {
        $report = new Retrospective($this->directory(), $this->clock())->report(new \DateTimeImmutable('2026-09-01T00:00:00+02:00'), 90);

        self::assertNull($report->trend());
        self::assertSame([], $report->inactive, 'Ninety days: none idle that long.');
        self::assertStringContainsString('No trend yet', RetroMarkdown::render($report));
    }

    public function testAnEmptyProjectSaysSoInEverySection(): void
    {
        $empty = $this->copyFixtureProject('plain-php');
        new InspectionPipeline(new AdapterResolver([]))->run(new InspectionOptions($empty, noAi: true));
        $report = new Retrospective(DevToolsDirectory::documented(new ProjectRoot($empty)), $this->clock())->report(new \DateTimeImmutable('2026-10-01T00:00:00+02:00'));
        $markdown = RetroMarkdown::render($report);

        foreach (['No human remark in the period.', 'No rule blocked the gate', 'Every active rule was given', 'None: everything said twice', 'No task delivered in the period.', 'No remark in the period.'] as $said) {
            self::assertStringContainsString($said, $markdown);
        }

        self::assertStringContainsString('<passes tasks="0" attempts="0"/>', new XmlRetroWriter()->serialize($report));
    }

    public function testTheCommandRefusesWhatItCannotDo(): void
    {
        foreach ([['--since' => 'yesterday'], ['--since' => '2027-01-01'], ['--format' => 'pdf'], ['--inactive-days' => '0'], ['--path' => $this->project.'/nowhere']] as $input) {
            self::assertSame(2, new CommandTester(new RetroCommand($this->clock(), $this->project))->execute($input), (string) json_encode($input));
        }

        file_put_contents($this->project.'/.devtools/journal/2026-10.xml', '<journal/>');
        self::assertSame(1, new CommandTester(new RetroCommand($this->clock(), $this->project))->execute([]));
    }

    /**
     * Eight tasks over four months, the remarks that came after them, three rules still active, one archived.
     */
    private function fourMonths(): void
    {
        $journal = new JournalRepository($this->directory());
        $heads = [];

        foreach ([
            ['2026-07-10', 1, ['route.order.new'], [], []],
            ['2026-07-20', 3, ['route.order.new'], ['scenarios', 'assertions'], ['rule-select2']],
            ['2026-08-05', 1, ['route.order.index'], [], []],
            ['2026-08-25', 2, ['route.order.index'], ['verification'], []],
            ['2026-09-10', 1, ['route.order.show'], [], []],
            ['2026-09-28', 2, ['route.order.new'], ['assertions'], ['rule-select2']],
            ['2026-10-05', 1, ['route.order.new'], [], []],
            ['2026-10-15', 1, ['route.order.index'], [], []],
        ] as $number => [$day, $attempts, $workflows, $steps, $blocked]) {
            $heads[] = $head = str_repeat(dechex($number + 1), 40);
            $delivered = new \DateTimeImmutable($day.'T17:00:00+02:00');
            $journal->record(new Task(Task::id($delivered), $delivered->modify('-2 hours'), $delivered, null, $head, $attempts, $workflows, $steps, $blocked));
        }

        $at = static fn (string $day, int $task, FeedbackSource $by = FeedbackSource::Human): Occurrence => new Occurrence(new \DateTimeImmutable($day.'T10:00:00+02:00'), $by, new FeedbackTarget(), $heads[$task - 1] ?? null);
        $feedback = new FeedbackRepository($this->directory());

        foreach ([
            new Feedback('fb-2026-07-02-order-total', FeedbackStatus::Fixed, new FeedbackTarget(selector: '.total'), 'validation', 'Le total accepte un nombre négatif.', [$at('2026-07-02', 0)]),
            new Feedback('fb-2026-07-11-order-product', FeedbackStatus::Promoted, new FeedbackTarget('route.order.new', '#order_product'), 'ui-component', 'Le champ produit doit être un select2.', [$at('2026-07-11', 1), $at('2026-07-21', 2), $at('2026-08-06', 3)], 'rule-select2'),
            new Feedback('fb-2026-07-12-order-label', FeedbackStatus::Open, new FeedbackTarget('route.order.new'), 'layout', 'Le libellé déborde de la carte.', [$at('2026-07-12', 1)]),
            new Feedback('fb-2026-07-22-order-date', FeedbackStatus::Fixed, new FeedbackTarget('route.order.index'), 'validation', 'La date de livraison accepte le passé.', [$at('2026-07-22', 2)]),
            new Feedback('fb-2026-08-26-index-filter', FeedbackStatus::Open, new FeedbackTarget('route.order.index'), 'filter', 'Le filtre par statut ne filtre rien.', [$at('2026-08-26', 4), $at('2026-09-11', 5)]),
            new Feedback('fb-2026-08-27-index-columns', FeedbackStatus::Fixed, new FeedbackTarget('route.order.index'), 'data-display', 'La colonne total est vide.', [$at('2026-08-27', 4)]),
            new Feedback('fb-2026-09-12-show-labels', FeedbackStatus::Fixed, new FeedbackTarget('route.order.show'), 'translation', 'Les libellés sont en anglais.', [$at('2026-09-12', 5)]),
            new Feedback('fb-2026-09-29-new-layout', FeedbackStatus::Open, new FeedbackTarget('route.order.new'), 'layout', 'Le bouton passe sous le formulaire.', [$at('2026-09-29', 6), $at('2026-10-06', 7)]),
            new Feedback('fb-2026-09-10-show-required', FeedbackStatus::Fixed, new FeedbackTarget('route.order.show'), 'validation', 'Le champ requis n\'est pas signalé.', [$at('2026-09-10', 5, FeedbackSource::Verifier)]),
            new Feedback('fb-2026-10-05-new-select', FeedbackStatus::Open, new FeedbackTarget('route.order.new'), 'ui-component', 'Un select natif reste dans le formulaire.', [$at('2026-10-05', 7, FeedbackSource::Verifier), $at('2026-10-15', 8, FeedbackSource::Verifier)]),
            new Feedback('fb-2026-06-20-old-remark', FeedbackStatus::Fixed, new FeedbackTarget('route.order.new'), 'layout', 'Avant la période.', [$at('2026-06-20', 0)]),
        ] as $one) {
            $feedback->save($one);
        }

        $rules = $this->rules();
        $rules->save(new Rule('rule-select2', RuleStatus::Active, '2026-07-01', ['fb-2026-07-11-order-product'], new AppliesTo(['templates/**/_form.html.twig']), 'Un champ de sélection utilise Select2Type.'));
        $rules->save(new Rule('rule-labels-in-french', RuleStatus::Active, '2026-07-15', ['fb-2026-07-12-order-label'], new AppliesTo(['templates/**']), 'Les libellés sont en français.'));
        $rules->save(new Rule('rule-never-given', RuleStatus::Active, '2026-09-01', ['fb-2026-09-12-show-labels'], new AppliesTo(['translations/**', 'templates/order/show.html.twig']), 'Les traductions vivent dans messages.fr.yaml.'));
        $rules->save(new Rule('rule-fresh', RuleStatus::Active, '2026-10-10', ['fb-2026-09-29-new-layout'], new AppliesTo(['templates/order/new.html.twig']), 'Le bouton reste à droite.'));
        $rules->save(new Rule('rule-old', RuleStatus::Archived, '2026-06-01', ['fb-2026-06-20-old-remark'], new AppliesTo(['src/**']), 'Ancienne règle.'));
        $rules->saveStats(new RuleStats([
            'rule-select2' => new RuleStat('rule-select2', 12, 2, new \DateTimeImmutable('2026-10-14T09:00:00+02:00'), new \DateTimeImmutable('2026-09-28T16:00:00+02:00')),
            'rule-labels-in-french' => new RuleStat('rule-labels-in-french', 3, 0, new \DateTimeImmutable('2026-08-20T09:00:00+02:00')),
        ]));
    }

    private function rules(): RuleRepository
    {
        return new RuleRepository($this->directory());
    }

    private function clock(): FrozenClock
    {
        return new FrozenClock(new \DateTimeImmutable('2026-10-21T12:00:00+02:00'));
    }

    private function directory(): DevToolsDirectory
    {
        return DevToolsDirectory::documented(new ProjectRoot($this->project));
    }
}
