<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Glossary;

use Jul6Art\DevTools\Ai\DraftApplier;
use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Glossary\Glossary;
use Jul6Art\DevTools\Glossary\GlossaryBriefWriter;
use Jul6Art\DevTools\Glossary\GlossaryCollector;
use Jul6Art\DevTools\Glossary\GlossaryDraftApplier;
use Jul6Art\DevTools\Glossary\GlossaryRenderer;
use Jul6Art\DevTools\Glossary\Term;
use Jul6Art\DevTools\Glossary\TermStatus;
use Jul6Art\DevTools\Glossary\XmlGlossaryStore;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Inspection\Graph\GraphFixture;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(GlossaryCollector::class)]
#[CoversClass(Glossary::class)]
#[CoversClass(Term::class)]
#[CoversClass(XmlGlossaryStore::class)]
#[CoversClass(GlossaryBriefWriter::class)]
#[CoversClass(GlossaryDraftApplier::class)]
#[CoversClass(GlossaryRenderer::class)]
final class GlossaryTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const string DRAFT = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <glossary-draft xmlns="https://github.com/jul6art/devtools/schema/glossary-draft/1" schema-version="1">
          <term name="%s">
            <definition>%s</definition>
            <synonym>reimbursement</synonym>
          </term>
        </glossary-draft>
        XML;

    /**
     * symfony-minimal's graph: its entities are terms however few workflows reach them; `order`, which three
     * routes share, is the `Order` entity; `status`, `health` and `import-catalog`, each in one identifier, are not.
     */
    public function testTheEntitiesAndTheSharedWordsOfSymfonyMinimalAreItsTerms(): void
    {
        $workflows = new WorkflowBuilder(new Config())->build(GraphFixture::root(), GraphFixture::stack(), GraphFixture::candidates(), ['templates'])->result->workflows;

        self::assertSame([
            'order' => ['name' => 'Order', 'workflows' => ['route.order.index', 'route.order.new', 'route.order.show'], 'files' => ['src/Entity/Order.php']],
            'product' => ['name' => 'Product', 'workflows' => ['command.app.import-catalog', 'route.order.new'], 'files' => ['src/Entity/Product.php']],
        ], new GlossaryCollector()->collect($workflows));
    }

    /**
     * ADR-0037: a word three identifiers share names a business thing; two is a coincidence, and a word every
     * project has — `new`, `show` — names none.
     */
    public function testAWordThreeWorkflowsShareIsACandidateAndTwoAreACoincidence(): void
    {
        $project = $this->project();
        $glossary = $this->glossary($project);

        self::assertSame(['refunds'], array_keys($glossary->terms));
        self::assertSame(TermStatus::Candidate, $glossary->get('refunds')?->status);
        self::assertSame(3, $glossary->get('refunds')->occurrences);
        self::assertSame(['route.refunds.draft', 'route.refunds.paid', 'route.refunds.sent'], $glossary->get('refunds')->workflows);

        $brief = (string) file_get_contents($project.'/.devtools/'.GlossaryBriefWriter::BRIEF);
        self::assertStringContainsString('<term name="refunds">', $brief);
        self::assertStringContainsString('<workflow id="route.refunds.paid" title=', $brief);
        self::assertStringContainsString('<draft path=".devtools/pending/glossary.draft.xml"/>', $brief);
        self::assertFileDoesNotExist($project.'/docs/workflows/glossary.md', 'Nothing defined, no page.');
    }

    public function testATermSetAsideIsNeverProposedAgain(): void
    {
        $project = $this->project();
        $file = $project.'/.devtools/glossary.xml';
        file_put_contents($file, str_replace('status="candidate"', 'status="ignored"', (string) file_get_contents($file)));

        $glossary = $this->glossary($project);

        self::assertSame(TermStatus::Ignored, $glossary->get('refunds')?->status);
        self::assertFileDoesNotExist($project.'/.devtools/'.GlossaryBriefWriter::BRIEF);

        file_put_contents($project.'/.devtools/'.GlossaryBriefWriter::DRAFT, \sprintf(self::DRAFT, 'refunds', 'Un remboursement.'));
        $applied = new DraftApplier()->apply(new ProjectRoot($project));

        self::assertSame(['glossary' => ['"refunds" was set aside (status="ignored"): it is not defined again.']], $applied->refused);
    }

    /**
     * A definition is a sentence or two; a draft breaking a rule is refused whole, and kept for Claude to fix.
     */
    public function testADraftBreakingARuleIsRefusedWholeAndKept(): void
    {
        $project = $this->project();
        $draft = $project.'/.devtools/'.GlossaryBriefWriter::DRAFT;

        foreach ([
            ['refunds', 'Un remboursement. Accordé par un opérateur. Versé sous huit jours.', 'The definition of "refunds" has 3 sentences; two at most.'],
            ['invoices', 'Une facture.', '"invoices" is not a term of the glossary.'],
        ] as [$name, $definition, $refusal]) {
            file_put_contents($draft, \sprintf(self::DRAFT, $name, $definition));
            $applied = new DraftApplier()->apply(new ProjectRoot($project));

            self::assertSame(['glossary' => [$refusal]], $applied->refused);
            self::assertFileExists($draft);
            self::assertSame(TermStatus::Candidate, $this->stored($project)->get('refunds')?->status);
        }

        file_put_contents($draft, '<glossary-draft/>');
        self::assertStringContainsString('glossary-draft', implode(' ', new DraftApplier()->apply(new ProjectRoot($project))->refused['glossary'] ?? []), 'An invalid draft is refused, not fatal.');
    }

    /**
     * Defined, a term is on its page, in the brief of every page that meets it, and its synonym is said — never
     * refused — where a page uses it.
     */
    public function testADefinedTermReachesItsPageThePageBriefsAndTheSynonymsAreSaid(): void
    {
        $project = $this->project();
        file_put_contents($project.'/.devtools/'.GlossaryBriefWriter::DRAFT, \sprintf(self::DRAFT, 'refunds', 'Un remboursement accordé à un client. Il est versé sous huit jours.'));

        $applied = new DraftApplier()->apply(new ProjectRoot($project));

        self::assertSame(['glossary'], $applied->accepted);
        self::assertSame(TermStatus::Defined, $this->stored($project)->get('refunds')?->status);
        self::assertStringContainsString('| **refunds** | Un remboursement accordé à un client. Il est versé sous huit jours. | reimbursement | `route.refunds.draft`, `route.refunds.paid`, `route.refunds.sent` |', (string) file_get_contents($project.'/docs/workflows/glossary.md'));
        self::assertStringContainsString('[Glossary](glossary.md)', (string) file_get_contents($project.'/docs/workflows/workflows.md'));
        self::assertFileDoesNotExist($project.'/.devtools/'.GlossaryBriefWriter::DRAFT);
        self::assertFileDoesNotExist($project.'/.devtools/'.GlossaryBriefWriter::BRIEF);

        // A page brief waits for its stack's knowledge: the sheet the matrix applies to plain-php will do.
        new Filesystem()->copy(__DIR__.'/../Fixtures/drafts/plain-php/knowledge.php-8.draft.md', $project.'/.devtools/pending/knowledge.php.draft.md');
        new DraftApplier()->apply(new ProjectRoot($project));
        $this->glossary($project);
        self::assertStringContainsString('<term name="refunds">Un remboursement accordé à un client. Il est versé sous huit jours.</term>', (string) file_get_contents($project.'/.devtools/pending/page.route.refunds.paid.brief.xml'));
        self::assertStringNotContainsString('<term ', (string) file_get_contents($project.'/.devtools/pending/page.route.invoices.sent.brief.xml'));
        self::assertFileDoesNotExist($project.'/.devtools/'.GlossaryBriefWriter::BRIEF, 'Nothing left to define.');

        $synonyms = new XmlGlossaryStore()->read($project.'/.devtools/glossary.xml')->synonymsIn('The Reimbursement is paid; a reimbursements list is not this word.');
        self::assertSame([['reimbursement', 'refunds']], $synonyms);
    }

    /**
     * Five pages: `refunds` named by three, `invoices` by two.
     */
    private function project(): string
    {
        $project = $this->temporaryDirectory().'/billing';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($project.'/composer.json', '{"name": "acme/billing"}');

        foreach (['invoices/draft', 'invoices/sent', 'refunds/draft', 'refunds/sent', 'refunds/paid'] as $page) {
            $filesystem->dumpFile($project.'/public/'.$page.'.php', "<?php\necho 'page';\n");
        }

        GitRepository::initialise($project)->commitAll('pages');
        $this->glossary($project);

        return $project;
    }

    private function glossary(string $project): Glossary
    {
        new InspectionPipeline()->run(new InspectionOptions($project));

        return $this->stored($project);
    }

    private function stored(string $project): Glossary
    {
        return new XmlGlossaryStore()->read($project.'/.devtools/glossary.xml');
    }
}
