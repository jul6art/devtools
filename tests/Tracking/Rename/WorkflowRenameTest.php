<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Tracking\Rename;

use Jul6Art\DevTools\Command\WorkflowsRenameCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Tracking\Rename\RenameDetector;
use Jul6Art\DevTools\Tracking\Rename\RenamePlan;
use Jul6Art\DevTools\Tracking\Rename\WorkflowRenamer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(WorkflowRenamer::class)]
#[CoversClass(RenamePlan::class)]
#[CoversClass(RenameDetector::class)]
#[CoversClass(WorkflowsRenameCommand::class)]
final class WorkflowRenameTest extends TestCase
{
    use UsesTemporaryDirectory;

    /**
     * ADR-0036 on a documentation Claude wrote, with a scenario, its baseline and a feedback: every reference
     * follows, none is left, the history goes on.
     */
    public function testEveryReferenceFollowsTheRenameAndNoneIsLeft(): void
    {
        $project = $this->documented();
        $before = (string) file_get_contents($project.'/.devtools/workflows/routes/order.new.xml');

        self::assertSame(0, $this->rename($project, 'route.order.new', 'route.order.create')->getStatusCode());

        $left = [];

        foreach (self::tree($project) as $path => $content) {
            if ('.devtools/config.xml' !== $path && 1 === preg_match('/(?<![\w.-])(?<!renamed from )route\.order\.new(?![\w-])/', $content)) {
                $left[] = $path;
            }
        }

        self::assertSame([], $left, 'No reference to the old identifier is left.');
        self::assertFileDoesNotExist($project.'/.devtools/workflows/routes/order.new.xml');
        self::assertFileDoesNotExist($project.'/docs/workflows/routes/order.new.md');
        self::assertFileExists($project.'/docs/workflows/routes/order.create.md');
        self::assertFileExists($project.'/.devtools/scenarios/route.order.create/happy-path.xml');
        self::assertFileExists($project.'/.devtools/baselines/route.order.create/happy-path/form.png');
        self::assertDirectoryDoesNotExist($project.'/.devtools/scenarios/route.order.new');
        self::assertStringContainsString('workflow="route.order.create"', (string) file_get_contents($project.'/.devtools/scenarios/route.order.create/happy-path.xml'));
        self::assertStringContainsString('[`route.order.create`](order.create.md)', (string) file_get_contents($project.'/docs/workflows/routes/order.index.md'));
        self::assertStringContainsString('page="routes/order.create.md"', (string) file_get_contents($project.'/.devtools/index.xml'));

        $after = (string) file_get_contents($project.'/.devtools/workflows/routes/order.create.xml');
        self::assertStringContainsString('reason="rédaction initiale"', $after, 'The history is kept.');
        self::assertMatchesRegularExpression('/<revision at="[^"]+" reason="renamed from route\.order\.new"\/>\s*<\/history>/', $after);
        self::assertSame(substr_count($before, '<file '), substr_count($after, '<file '));
        self::assertSame([], glob($project.'/.devtools/pending/*') ?: [], 'No task for Claude.');
    }

    public function testARenameOntoAnExistingWorkflowFromAnUnknownOneOrAcrossTypesIsRefused(): void
    {
        $project = $this->documented();
        $tree = self::tree($project);

        foreach ([['route.order.new', 'route.order.show', 'route.order.show already exists'], ['route.order.gone', 'route.order.create', 'route.order.gone is not a workflow of this project'], ['route.order.new', 'command.order.new', 'are not of the same type'], ['route.order.new', 'Route Order', 'not a valid']] as [$old, $new, $refusal]) {
            $tester = $this->rename($project, $old, $new);

            self::assertSame(1, $tester->getStatusCode());
            self::assertStringContainsString($refusal, (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        }

        self::assertSame($tree, self::tree($project), 'Nothing written.');
    }

    public function testADryRunListsWhatItWouldWriteAndWritesNothing(): void
    {
        $project = $this->documented();
        $tree = self::tree($project);
        $tester = $this->rename($project, 'route.order.new', 'route.order.create', ['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('docs/workflows/routes/order.create.md', $tester->getDisplay());
        self::assertStringContainsString('route.order.new would become route.order.create', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame($tree, self::tree($project));
    }

    /**
     * A page renamed in the code: gone and new, sharing five files of six — proposed, then applied on demand.
     * Sharing less, they are two workflows.
     */
    public function testAnInspectionProposesAProbableRenameAndAppliesItOnlyWhenAsked(): void
    {
        $project = $this->temporaryDirectory().'/plain';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($project.'/composer.json', '{"name": "acme/plain"}');
        $requires = '';

        foreach (['a', 'b', 'c', 'd', 'e'] as $library) {
            $filesystem->dumpFile($project.'/lib/'.$library.'.php', "<?php\n");
            $requires .= "require __DIR__.'/../../lib/".$library.".php';\n";
        }

        $filesystem->dumpFile($project.'/public/orders/new.php', "<?php\n".$requires);
        $filesystem->dumpFile($project.'/public/orders/list.php', "<?php\nrequire __DIR__.'/../../lib/a.php';\n");
        $repository = GitRepository::initialise($project);
        $repository->commitAll('pages');
        $pipeline = new InspectionPipeline();
        $pipeline->run(new InspectionOptions($project, noAi: true));

        $filesystem->rename($project.'/public/orders/new.php', $project.'/public/orders/create.php');
        $filesystem->rename($project.'/public/orders/list.php', $project.'/public/orders/index.php');
        $filesystem->appendToFile($project.'/public/orders/index.php', "require __DIR__.'/../../lib/b.php';\n");
        $repository->commitAll('renamed');

        $proposed = $pipeline->run(new InspectionOptions($project, dryRun: true, noAi: true));
        self::assertContains('Probably a rename, route.orders.new → route.orders.create (83 % of their files shared): devtools workflows:rename route.orders.new route.orders.create keeps its page and history — or inspect with --apply-renames.', $proposed->warnings);
        self::assertSame([], array_values(array_filter($proposed->warnings, static fn (string $warning): bool => str_contains($warning, 'route.orders.list'))), 'One file of two in common: no rename.');

        $applied = $pipeline->run(new InspectionOptions($project, noAi: true, applyRenames: true));
        self::assertContains('route.orders.new renamed route.orders.create: its page, its history and every reference followed.', $applied->warnings);
        self::assertSame('rewrite: files removed: public/orders/new.php; files added: public/orders/create.php', $applied->decision('route.orders.create')?->kind->value.': '.$applied->decision('route.orders.create')?->describe(), 'Its page follows, and its facts are written again — not created.');
        self::assertNull($applied->decision('route.orders.new'), 'No orphan.');
        self::assertStringContainsString('reason="renamed from route.orders.new"', (string) file_get_contents($project.'/.devtools/workflows/routes/orders.create.xml'));
    }

    private function documented(): string
    {
        $project = $this->temporaryDirectory().'/documented';
        $filesystem = new Filesystem();
        $filesystem->mirror(__DIR__.'/../../Fixtures/matrix/symfony-minimal/written', $project);
        $filesystem->copy(__DIR__.'/../../Fixtures/scenarios/happy-path.xml', $project.'/.devtools/scenarios/route.order.new/happy-path.xml');
        $filesystem->dumpFile($project.'/.devtools/baselines/route.order.new/happy-path/form.png', 'png');
        $tester = new CommandTester(new Application()->find('feedback:add'));
        self::assertSame(0, $tester->execute(['summary' => 'The product field must be a select2.', '--workflow' => 'route.order.new', '--category' => 'ui-component', '--path' => $project]));

        return $project;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function rename(string $project, string $old, string $new, array $options = []): CommandTester
    {
        $tester = new CommandTester(new Application()->find('workflows:rename'));
        $tester->execute(['old' => $old, 'new' => $new, 'path' => $project, ...$options]);

        return $tester;
    }
}
