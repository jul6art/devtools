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
use PHPUnit\Framework\Attributes\DataProvider;
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
     * What renaming route.order.new writes in the documented symfony-minimal.
     */
    private const array DRY_RUN = [
        '* .devtools/baselines/route.order.create/happy-path/form.png',
        '* .devtools/feedback/FEEDBACK.xml',
        '* .devtools/feedback/index.xml',
        '* .devtools/glossary.xml',
        '* .devtools/graph/files-to-workflows.xml',
        '* .devtools/graph/workflows.mermaid',
        '* .devtools/index.xml',
        '* .devtools/scenarios/route.order.create/happy-path.xml',
        '* .devtools/workflows/routes/order.create.xml',
        '* docs/workflows/glossary.md',
        '* docs/workflows/routes/order.create.md',
        '* docs/workflows/routes/order.index.md',
        '* docs/workflows/workflows.md',
    ];

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
        self::assertDirectoryDoesNotExist($project.'/.devtools/baselines/route.order.new');
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

        // A tracking file left for the new identifier, which the index does not name: still a history.
        copy($project.'/.devtools/workflows/routes/order.show.xml', $project.'/.devtools/workflows/routes/order.create.xml');
        $tester = $this->rename($project, 'route.order.new', 'route.order.create');
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('route.order.create already exists', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testADryRunListsWhatItWouldWriteAndWritesNothing(): void
    {
        $project = $this->documented();
        $tree = self::tree($project);
        $tester = $this->rename($project, 'route.order.new', 'route.order.create', ['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        // The feedback is named after the day it was given: the one line that moves from one run to another.
        $listed = preg_replace('#^\* \.devtools/feedback/\d{4}/fb-\d{4}-\d{2}-\d{2}-route-order-new\.xml$#', '* .devtools/feedback/FEEDBACK.xml', array_values(array_filter(array_map(trim(...), explode("\n", $tester->getDisplay())), static fn (string $line): bool => str_starts_with($line, '* '))));
        self::assertSame(self::DRY_RUN, $listed, 'Relative to the project, sorted: only what changes.');
        self::assertStringNotContainsString('config.xml names', $tester->getDisplay(), 'Its comments name example identifiers: they are no reference.');
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

    /**
     * What the rename must not rewrite: a file of the documentation that is no page, a link out of it, and the
     * configuration — which is the human's, and is only said to name the old identifier.
     */
    public function testWhatIsNotOneOfItsReferencesIsLeftAsItIs(): void
    {
        $project = $this->documented();
        $image = $project.'/docs/workflows/routes/order.new.png';
        file_put_contents($image, 'png route.order.new');
        $index = $project.'/docs/workflows/routes/order.index.md';
        file_put_contents($index, str_replace('## Summary'."\n\n", '## Summary'."\n\n".'See [the forms](https://symfony.com/doc/current/forms.html). ', (string) file_get_contents($index)));
        $readme = $project.'/docs/workflows/README.txt';
        file_put_contents($readme, 'route.order.new');
        $moved = $project.'/docs/workflows/routes/order.new.md';
        file_put_contents($moved, str_replace('## Summary'."\n\n", '## Summary'."\n\n".'Built on [Symfony forms](https://symfony.com/doc/current/forms.html). ', (string) file_get_contents($moved)));
        file_put_contents($index, str_replace('## Summary'."\n\n", '## Summary'."\n\n".'Opens [the detail](./order.show.md) and [the creation](order.new.md#journey); the CSS class route-order-new stays. ', (string) file_get_contents($index)));
        $config = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1"><!-- route.order.new in a comment is no reference --><aliases><alias entrypoint="app_order_new" id="route.order.new"/></aliases></devtools>'."\n";
        file_put_contents($project.'/.devtools/config.xml', $config);

        $tester = $this->rename($project, 'route.order.new', 'route.order.create');

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('.devtools/config.xml names route.order.new: DevTools never rewrites it — update it yourself.', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame($config, file_get_contents($project.'/.devtools/config.xml'));
        self::assertSame('png route.order.new', file_get_contents($image), 'Not a page: not rewritten.');
        self::assertStringContainsString('See [the forms](https://symfony.com/doc/current/forms.html). ', (string) file_get_contents($index), 'A link out of the documentation is left alone.');
        self::assertSame('route.order.new', file_get_contents($readme), 'The first file of the documentation is no page either.');
        self::assertStringContainsString('Built on [Symfony forms](https://symfony.com/doc/current/forms.html). ', (string) file_get_contents($project.'/docs/workflows/routes/order.create.md'), 'Not even in the page that moved.');
        self::assertStringContainsString('Opens [the detail](./order.show.md) and [the creation](order.create.md#journey); the CSS class route-order-new stays. ', (string) file_get_contents($index), 'A link to another page is left as written; one to the moved page keeps its anchor; a look-alike is no identifier.');
    }

    /**
     * Gone and new, the best match wins: of two new pages, the one sharing more files; of two gone pages, the
     * first takes a new one and the second the next — a workflow still there is neither; 80 % is enough.
     *
     * @param array<string, int> $before   page — its entry file, relative to the project, without .php — => the libraries it requires, before
     * @param array<string, int> $after    the same, after
     * @param list<string>       $expected
     */
    #[DataProvider('leftOvers')]
    public function testTheRenameProposedIsTheBestMatchOfTheWorkflowsLeftOver(array $before, array $after, array $expected): void
    {
        $project = $this->temporaryDirectory().'/left-over-'.bin2hex(random_bytes(3));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($project.'/composer.json', '{"name": "acme/plain"}');
        $page = static function (int $libraries, string $name) use ($filesystem, $project): string {
            $content = str_starts_with($name, 'bin/') ? "#!/usr/bin/env php\n<?php\n" : "<?php\n";

            for ($library = 1; $library <= $libraries; ++$library) {
                $filesystem->dumpFile($project.'/lib/l'.$library.'.php', "<?php\n");
                $content .= "require __DIR__.'".str_repeat('/..', substr_count($name, '/')).'/lib/l'.$library.".php';\n";
            }

            return $content;
        };

        foreach ($before as $name => $libraries) {
            $filesystem->dumpFile($project.'/'.$name.'.php', $page($libraries, $name));
        }

        $repository = GitRepository::initialise($project);
        $pipeline = new InspectionPipeline();
        $pipeline->run(new InspectionOptions($project, noAi: true));

        $filesystem->remove(array_map(static fn (string $name): string => $project.'/'.$name.'.php', array_keys(array_diff_key($before, $after))));

        foreach ($after as $name => $libraries) {
            $filesystem->dumpFile($project.'/'.$name.'.php', $page($libraries, $name));
        }

        $repository->commitAll('pages changed');
        $proposed = [];

        foreach ($pipeline->run(new InspectionOptions($project, dryRun: true, noAi: true))->warnings as $warning) {
            if (1 === preg_match('/^Probably a rename, (\S+) → (\S+) \((\d+) %/u', $warning, $match)) {
                $proposed[] = \sprintf('%s → %s %s %%', $match[1], $match[2], $match[3]);
            }
        }

        self::assertSame($expected, $proposed);
    }

    /**
     * @return iterable<string, array{array<string, int>, array<string, int>, list<string>}>
     */
    public static function leftOvers(): iterable
    {
        yield 'the new page sharing more files' => [['public/orders/old' => 10], ['public/orders/aaa' => 9, 'public/orders/bbb' => 10], ['route.orders.old → route.orders.bbb 91 %']];
        yield 'one new page each' => [['public/orders/add' => 5, 'public/orders/new' => 5], ['public/orders/create' => 5, 'public/orders/edit' => 5], ['route.orders.add → route.orders.create 83 %', 'route.orders.new → route.orders.edit 83 %']];
        yield 'a workflow still there is neither' => [['public/orders/browse' => 5, 'public/orders/new' => 5], ['public/orders/browse' => 5, 'public/orders/create' => 5], ['route.orders.new → route.orders.create 83 %']];
        yield 'a workflow of another type is no rename' => [['public/orders/new' => 5], ['bin/new' => 5], []];
        yield '80 % is enough' => [['public/orders/new' => 4], ['public/orders/create' => 4], ['route.orders.new → route.orders.create 80 %']];
    }

    /**
     * Two pages gone, one new that shares their files: it is the rename of one of them only — the other is
     * gone, and two histories never merge.
     */
    public function testTwoGoneWorkflowsNeverClaimTheSameNewOne(): void
    {
        $project = $this->temporaryDirectory().'/plain-twice';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($project.'/composer.json', '{"name": "acme/plain"}');
        $requires = '';

        foreach (['a', 'b', 'c', 'd', 'e'] as $library) {
            $filesystem->dumpFile($project.'/lib/'.$library.'.php', "<?php\n");
            $requires .= "require __DIR__.'/../../lib/".$library.".php';\n";
        }

        $filesystem->dumpFile($project.'/public/orders/add.php', "<?php\n".$requires);
        $filesystem->dumpFile($project.'/public/orders/new.php', "<?php\n".$requires);
        $repository = GitRepository::initialise($project);
        $repository->commitAll('pages');
        $pipeline = new InspectionPipeline();
        $pipeline->run(new InspectionOptions($project, noAi: true));

        $filesystem->remove([$project.'/public/orders/add.php', $project.'/public/orders/new.php']);
        $filesystem->dumpFile($project.'/public/orders/create.php', "<?php\n".$requires);
        $repository->commitAll('one page instead of two');

        $proposed = array_values(array_filter($pipeline->run(new InspectionOptions($project, dryRun: true, noAi: true))->warnings, static fn (string $warning): bool => str_starts_with($warning, 'Probably a rename')));

        self::assertCount(1, $proposed);
        self::assertStringContainsString('route.orders.add → route.orders.create (83 % of their files shared)', $proposed[0]);
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
