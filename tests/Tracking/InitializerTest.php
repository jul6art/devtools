<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Tracking;

use Jul6Art\DevTools\Command\InitCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\Initializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Initializer::class)]
#[CoversClass(DevToolsDirectory::class)]
#[CoversClass(InitCommand::class)]
final class InitializerTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testItCreatesTheTreeTheConfigTheSchemasAndTheGitignore(): void
    {
        $directory = new DevToolsDirectory(new ProjectRoot($this->temporaryDirectory()));

        new Initializer()->initialize($directory);

        foreach (['workflows', 'knowledge', 'graph', 'schemas', 'pending', 'reports'] as $folder) {
            self::assertDirectoryExists($directory->path($folder));
        }

        self::assertFileExists($directory->configFile());
        self::assertFileExists($directory->path('schemas/workflow-tracking.xsd'));
        self::assertFileEquals(\dirname(__DIR__, 2).'/resources/schemas/index.xsd', $directory->path('schemas/index.xsd'));
        self::assertStringContainsString("/.devtools/pending/\n/.devtools/reports/\n", (string) file_get_contents($this->temporaryDirectory().'/.gitignore'));
    }

    public function testRunningItAgainModifiesNothing(): void
    {
        $directory = new DevToolsDirectory(new ProjectRoot($this->temporaryDirectory()));
        new Initializer()->initialize($directory);
        $before = self::tree($this->temporaryDirectory());

        $created = new Initializer()->initialize($directory);

        self::assertSame([], $created);
        self::assertSame($before, self::tree($this->temporaryDirectory()));
    }

    public function testItKeepsAnExistingGitignoreAndDoesNotDuplicateAnEquivalentLine(): void
    {
        file_put_contents($this->temporaryDirectory().'/.gitignore', "/vendor/\n.devtools/pending\n");

        new Initializer()->initialize(new DevToolsDirectory(new ProjectRoot($this->temporaryDirectory())));

        $gitignore = (string) file_get_contents($this->temporaryDirectory().'/.gitignore');
        self::assertStringStartsWith("/vendor/\n.devtools/pending\n", $gitignore);
        self::assertSame(1, substr_count($gitignore, 'pending'));
        self::assertSame(1, substr_count($gitignore, '/.devtools/reports/'));
    }

    public function testItNeverOverwritesAConfigTheProjectEdited(): void
    {
        $directory = new DevToolsDirectory(new ProjectRoot($this->temporaryDirectory()));
        new Initializer()->initialize($directory);
        file_put_contents($directory->configFile(), '<edited/>');

        new Initializer()->initialize($directory);

        self::assertStringEqualsFile($directory->configFile(), '<edited/>');
    }

    public function testTheCommandInitialisesThePathItIsGiven(): void
    {
        $tester = new CommandTester(new Application()->find('init'));

        self::assertSame(0, $tester->execute(['path' => $this->temporaryDirectory()], ['verbosity' => OutputInterface::VERBOSITY_NORMAL]));
        self::assertFileExists($this->temporaryDirectory().'/.devtools/config.xml');
        self::assertStringContainsString('.devtools/config.xml', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['path' => $this->temporaryDirectory()]));
        self::assertStringContainsString('already initialised', $tester->getDisplay());
    }

    public function testTheCommandRefusesAPathThatIsNotADirectory(): void
    {
        $tester = new CommandTester(new Application()->find('init'));

        self::assertSame(2, $tester->execute(['path' => $this->temporaryDirectory().'/missing']));
    }
}
