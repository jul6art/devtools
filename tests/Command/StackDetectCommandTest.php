<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Command;

use Jul6Art\DevTools\Command\StackDetectCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(StackDetectCommand::class)]
final class StackDetectCommandTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testItShowsTheProfileAndWritesTheStackFile(): void
    {
        new Filesystem()->mirror(__DIR__.'/../Fixtures/projects/monorepo', $this->temporaryDirectory());
        $tester = new CommandTester(new Application()->find('stack:detect'));

        self::assertSame(0, $tester->execute(['path' => $this->temporaryDirectory()], ['verbosity' => OutputInterface::VERBOSITY_NORMAL]));
        self::assertFileExists($this->temporaryDirectory().'/.devtools/stack.xml');
        self::assertStringContainsString('angular', $tester->getDisplay());
        self::assertStringContainsString('symfony', $tester->getDisplay());
    }
}
