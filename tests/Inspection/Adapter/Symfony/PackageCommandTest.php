<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Tests\Inspection\Graph\GraphFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A package's command that bears the project's name is the project's workflow (ADR-0053): auth-bundle's
 * `app:user:create` and `app:dev:login-link`, which the applications turn on instead of their own copies.
 */
#[CoversClass(SymfonyAdapter::class)]
final class PackageCommandTest extends TestCase
{
    private const array COMMANDS = [
        'App\Command\ReportCommand' => 'app:report',
        'Jul6Art\AuthBundle\Account\Command\CreateUserCommand' => 'app:user:create',
        // A bundle's own command, in the bundle's namespace: not the project's.
        'Jul6Art\AclBundle\Command\SeedPermissionsCommand' => 'acl:permissions:seed',
        // The framework's.
        'Symfony\Bundle\FrameworkBundle\Command\CacheClearCommand' => 'cache:clear',
    ];

    private string $project;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/jul6art-devtools-tests/package-command-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();

        $filesystem->dumpFile($this->project.'/composer.json', (string) json_encode([
            'require' => ['symfony/framework-bundle' => '^8.0', 'jul6art/auth-bundle' => '^2.8', 'jul6art/acl-bundle' => '^1.3'],
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
        ]));
        $filesystem->dumpFile($this->project.'/composer.lock', (string) json_encode(['packages' => [
            ['name' => 'jul6art/acl-bundle', 'version' => 'v1.3.0', 'autoload' => ['psr-4' => ['Jul6Art\AclBundle\\' => '']]],
            ['name' => 'jul6art/auth-bundle', 'version' => 'v2.8.0', 'autoload' => ['psr-4' => ['Jul6Art\AuthBundle\\' => '']]],
            ['name' => 'symfony/framework-bundle', 'version' => 'v8.1.7', 'autoload' => ['psr-4' => ['Symfony\Bundle\FrameworkBundle\\' => '']]],
        ], 'packages-dev' => []]));
        $filesystem->dumpFile($this->project.'/src/Command/ReportCommand.php', <<<'PHP'
            <?php

            namespace App\Command;

            use Symfony\Component\Console\Attribute\AsCommand;

            #[AsCommand(name: 'app:report')]
            final class ReportCommand
            {
            }
            PHP);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->project);
    }

    public function testAPackagesCommandInTheProjectsNameIsItsWorkflow(): void
    {
        $result = $this->inspect();

        self::assertSame(
            ['command.app.report', 'command.app.user.create'],
            array_map(static fn (Workflow $workflow): string => (string) $workflow->id, $result->workflows),
            'Neither the bundle\'s own command nor the framework\'s.',
        );

        $create = $this->workflow($result, 'command.app.user.create');
        self::assertSame('composer.json', $create->main->declaredIn->path, 'Declared where the project requires its packages.');
        self::assertSame(['class' => 'Jul6Art\AuthBundle\Account\Command\CreateUserCommand'], $create->main->attributes, 'What answers, since no file of the project says it.');
        self::assertSame(['jul6art/auth-bundle@2.8.0'], array_map(static fn (PackageRef $package): string => $package->name.'@'.$package->version, $create->packages));
        self::assertContains('composer.json', array_map(static fn (FileRef $file): string => $file->path, $create->files));

        $report = $this->workflow($result, 'command.app.report');
        self::assertSame('src/Command/ReportCommand.php', $report->main->declaredIn->path);
        self::assertSame([], $report->packages, 'The project\'s own command is unchanged.');
        self::assertSame([], $report->main->attributes);
    }

    public function testAnotherPackagesCommandOfTheProjectsNameStaysOutWhenNoPackageHoldsIt(): void
    {
        // The lock no longer knows auth-bundle: no package holds the class, the command is no one's.
        new Filesystem()->dumpFile($this->project.'/composer.lock', (string) json_encode(['packages' => [], 'packages-dev' => []]));

        self::assertSame(['command.app.report'], array_map(static fn (Workflow $workflow): string => (string) $workflow->id, $this->inspect()->workflows));
    }

    private function inspect(): InspectionResult
    {
        $definitions = [];
        foreach (self::COMMANDS as $class => $name) {
            $definitions['service.'.$name] = ['class' => $class, 'tags' => [['name' => 'console.command', 'parameters' => ['command' => $name]]]];
        }

        $root = GraphFixture::root($this->project);
        $stack = GraphFixture::stack($this->project);
        $extractor = new PhpReferenceExtractor();
        // No route: only the commands are this test's question.
        $adapter = new SymfonyAdapter(new RecordedConsoleRunner(['router' => '{}', 'commands' => (string) json_encode(['definitions' => $definitions])]))->extract($root, $stack, new Config(), $extractor);
        self::assertNull($adapter->fallbackCause, 'The console answered: no static stand-in.');

        return new WorkflowBuilder(new Config())->build($root, $stack, $adapter->candidates, $adapter->templateDirectories, $extractor, $adapter->mechanisms)->result;
    }

    private function workflow(InspectionResult $result, string $id): Workflow
    {
        foreach ($result->workflows as $workflow) {
            if ($id === (string) $workflow->id) {
                return $workflow;
            }
        }

        self::fail(\sprintf('No workflow "%s".', $id));
    }
}
