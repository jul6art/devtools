<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\GenericPhp;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Adapter\GenericPhp\ConsoleCommandScanner;
use Jul6Art\DevTools\Inspection\Adapter\GenericPhp\GenericPhpAdapter;
use Jul6Art\DevTools\Inspection\Graph\EntryPointCandidate;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\InspectionReport;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackDetector;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tracking\TrackedFile;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(GenericPhpAdapter::class)]
#[CoversClass(ConsoleCommandScanner::class)]
final class GenericPhpAdapterTest extends TestCase
{
    use CopiesFixtureProjects;

    public function testAPlainPhpProjectGivesItsPagesItsScriptsAndItsMigrations(): void
    {
        $result = $this->extract($this->copyFixtureProject('plain-php'));

        self::assertSame([
            'routes index public/index.php',
            'routes orders.new public/orders/new.php',
            'commands cleanup bin/cleanup',
            'commands import-orders bin/import.php',
            'data migrations migrations/001_create_orders.sql',
        ], array_map(static fn (EntryPointCandidate $candidate): string => implode(' ', [$candidate->type->name, $candidate->entryPoint->name, $candidate->entryPoint->declaredIn->path]), $result->candidates));
        self::assertSame(['/index.php', '/orders/new.php'], [$result->candidates[0]->entryPoint->attributes['path'], $result->candidates[1]->title]);
    }

    public function testLinksFormsAndRedirectsBetweenPagesAreTheNavigation(): void
    {
        $result = $this->extract($this->copyFixtureProject('plain-php'));

        self::assertSame(['orders.new form', 'orders.new link'], $this->edges($result->candidates[0]));
        self::assertSame(['index link', 'index redirect'], $this->edges($result->candidates[1]), 'A form posting to its own page is not navigation.');
    }

    public function testAScriptRunningAPhpToolIsNotACommandButAnOptionOfPhpIsAllowed(): void
    {
        $project = $this->copyFixtureProject('plain-php');
        $this->editComposer($project, static function (array $composer): array {
            $composer['scripts'] = ['lint' => 'php -l public/index.php', 'import-orders' => ['@php -d memory_limit=-1 bin/import.php'], 'outside' => 'php ../../etc/passwd.php', 'deploy' => 'php bin/deploy.sh'];
            $composer['bin'] = ['bin/import.php'];

            return $composer;
        });

        $commands = array_filter($this->extract($project)->candidates, static fn (EntryPointCandidate $candidate): bool => 'commands' === $candidate->type->name);

        self::assertSame(['cleanup', 'import-orders'], array_values(array_map(static fn (EntryPointCandidate $candidate): string => $candidate->entryPoint->name, $commands)));
        self::assertSame([[], ['composer.json']], array_values(array_map(static fn (EntryPointCandidate $candidate): array => array_map(static fn (FileRef $file): string => $file->path, $candidate->structuralFiles), $commands)), 'A script is declared in composer.json; a bare bin/ file is not.');
        self::assertSame('import-orders', array_values($commands)[1]->title, 'The script names the file, even when it is also a bin entry.');
    }

    public function testTheConfiguredWebRootReplacesTheConventionalOnes(): void
    {
        $project = $this->copyFixtureProject('plain-php');
        new Filesystem()->rename($project.'/public', $project.'/htdocs');
        file_put_contents($project.'/htdocs/.htaccess', "RewriteEngine On\n");

        self::assertSame([], $this->routes($this->extract($project)));

        $routes = $this->routes($this->extract($project, new Config(phpWebRoot: 'htdocs')));

        self::assertSame(['index', 'orders.new'], array_map(static fn (EntryPointCandidate $candidate): string => $candidate->entryPoint->name, $routes));
        self::assertSame('htdocs/.htaccess', $routes[0]->structuralFiles[0]->path);
    }

    public function testAConsoleApplicationGivesOneWorkflowPerCommandAndNotItsBinary(): void
    {
        $project = $this->temporaryDirectory().'/console-app';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($project.'/composer.json', '{"name": "acme/tool", "require": {"symfony/console": "^7.4"}, "autoload": {"psr-4": {"Acme\\\\": "src/"}}, "bin": ["bin/tool"]}');
        $filesystem->dumpFile($project.'/bin/tool', "#!/usr/bin/env php\n<?php\n\$application = new Symfony\\Component\\Console\\Application();\n\$application->add(new Acme\\Command\\ImportCommand());\n\$application->run();\n");
        $filesystem->dumpFile($project.'/src/Command/ImportCommand.php', "<?php\nnamespace Acme\\Command;\nuse Symfony\\Component\\Console\\Attribute\\AsCommand;\nuse Symfony\\Component\\Console\\Command\\Command;\n#[AsCommand(name: 'acme:import', description: 'Imports')]\nfinal class ImportCommand extends Command {}\n");
        $filesystem->dumpFile($project.'/src/Command/PurgeCommand.php', "<?php\nnamespace Acme\\Command;\nuse Symfony\\Component\\Console\\Command\\Command;\nfinal class PurgeCommand extends Command { protected function configure(): void { \$this->setName('acme:clear'); } }\n");
        $filesystem->dumpFile($project.'/src/Command/LegacyCommand.php', "<?php\nnamespace Acme\\Command;\nuse Symfony\\Component\\Console\\Command\\Command;\nfinal class LegacyCommand extends Command { protected static \$defaultName = 'acme:legacy'; }\n");
        $filesystem->dumpFile($project.'/src/Command/NamedCommand.php', "<?php\nnamespace Acme\\Command;\nuse Symfony\\Component\\Console\\Command\\Command;\nfinal class NamedCommand extends Command { public function __construct() { parent::__construct('acme:named'); } }\n");
        $filesystem->dumpFile($project.'/src/Command/DynamicCommand.php', "<?php\nnamespace Acme\\Command;\nuse Symfony\\Component\\Console\\Command\\Command;\nfinal class DynamicCommand extends Command { protected \$defaultName = 'acme:not-static'; public function __construct(string \$name) { Registry::__construct('acme:not-parent'); parent::__construct(\$name); } }\n");
        $filesystem->dumpFile($project.'/src/Command/BaseCommand.php', "<?php\nnamespace Acme\\Command;\nuse Symfony\\Component\\Console\\Command\\Command;\nabstract class BaseCommand extends Command {}\n");
        $filesystem->dumpFile($project.'/src/Service/NotACommand.php', "<?php\nnamespace Acme\\Service;\nfinal class NotACommand extends \\ArrayObject { public function run(): void { \$this->setName('nope'); } }\n");

        $result = $this->extract($project);

        self::assertSame(
            ['acme:clear src/Command/PurgeCommand.php', 'acme:import src/Command/ImportCommand.php', 'acme:legacy src/Command/LegacyCommand.php', 'acme:named src/Command/NamedCommand.php'],
            array_map(static fn (EntryPointCandidate $candidate): string => $candidate->entryPoint->name.' '.$candidate->entryPoint->declaredIn->path, $result->candidates),
        );
        self::assertSame(['src/Command/DynamicCommand.php names its command at runtime; give it #[AsCommand] to document it.'], $result->warnings);
    }

    public function testTheInspectionOfPlainPhpMatchesItsSnapshotAndIsIdempotent(): void
    {
        $project = $this->copyFixtureProject('plain-php');

        $first = $this->inspect($project);
        self::assertSame(5, $first->count('created'));
        self::assertSame([['stack' => 'Php (.)', 'adapter' => 'generic-php', 'confidence' => 'high', 'uncovered' => 1]], $first->stacks, 'lib/Mailer.php is reached by no workflow.');

        $tree = self::documentedTree($project);
        $expected = __DIR__.'/../../../Fixtures/expected/plain-php';
        $actual = array_map(static fn (string $content): string => (string) preg_replace('/tool="devtools [^"]*"/', 'tool="devtools"', $content), $tree);

        if ('1' === getenv('DEVTOOLS_UPDATE_SNAPSHOTS')) {
            foreach ($actual as $path => $content) {
                new Filesystem()->dumpFile($expected.'/'.$path, $content);
            }
        }

        self::assertSame(self::tree($expected), $actual);
        self::assertContains('lib/db.php', array_map(static fn (TrackedFile $file): string => $file->file->path, new XmlTrackingStore(new WorkflowTypeRegistry())->read($project.'/.devtools/workflows/routes/orders.new.xml')->files), 'require_once __DIR__.\'/../../lib/db.php\' is a traversed file.');

        $second = $this->inspect($project);
        self::assertSame(5, $second->count('unchanged'));
        self::assertSame($tree, self::documentedTree($project));
    }

    public function testAnAdapterForcedToClaudeInStackXmlTakesTheClaudePath(): void
    {
        $project = $this->copyFixtureProject('plain-php');
        $this->inspect($project);
        file_put_contents($project.'/.devtools/stack.xml', str_replace('<adapter>generic-php</adapter>', '<adapter locked="true">claude</adapter>', (string) file_get_contents($project.'/.devtools/stack.xml')));

        $report = $this->inspect($project, noAi: false);

        self::assertSame('claude', $report->stacks[0]['adapter']);
        self::assertFileExists($project.'/.devtools/pending/knowledge.php-8.brief.xml', 'The Claude path starts from the knowledge of the stack.');
    }

    private function extract(string $project, Config $config = new Config()): AdapterResult
    {
        $root = new ProjectRoot($project);

        return new GenericPhpAdapter()->extract($root, new StackDetector()->detect($root, $config)->stacks[0], $config, new PhpReferenceExtractor());
    }

    private function inspect(string $project, bool $noAi = true): InspectionReport
    {
        $report = new InspectionPipeline(clock: new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($project, noAi: $noAi));
        self::assertSame([], $report->errors, implode("\n", $report->errors));

        return $report;
    }

    /**
     * @return list<string>
     */
    private function edges(EntryPointCandidate $candidate): array
    {
        return array_map(static fn (Edge $edge): string => $edge->target.' '.$edge->label, $candidate->navigation);
    }

    /**
     * @return list<EntryPointCandidate>
     */
    private function routes(AdapterResult $result): array
    {
        return array_values(array_filter($result->candidates, static fn (EntryPointCandidate $candidate): bool => 'routes' === $candidate->type->name));
    }

    /**
     * @param callable(array<mixed>): array<mixed> $edit
     */
    private function editComposer(string $project, callable $edit): void
    {
        $composer = json_decode((string) file_get_contents($project.'/composer.json'), true);
        self::assertIsArray($composer);
        file_put_contents($project.'/composer.json', json_encode($edit($composer), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }
}
