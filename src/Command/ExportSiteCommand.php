<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rendering\Site\SiteExporter;
use Jul6Art\DevTools\Stack\XmlStackStore;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;

/**
 * `devtools export:site`: the documentation as a static site, readable offline and outside the IDE
 * (specs § 4.8, ADR-0034).
 */
#[AsCommand(name: 'export:site', description: 'Export the documentation as a static HTML site that works offline')]
final class ExportSiteCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'The project (default: the current directory)')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Where the site is written, relative to the project or absolute', 'build/devtools-site');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');
        $path = \is_string($path) ? $path : (string) getcwd();

        try {
            $directory = DevToolsDirectory::documented(new ProjectRoot($path));
        } catch (\InvalidArgumentException $invalid) {
            $io->error($invalid->getMessage());

            return Command::INVALID;
        }

        $target = $input->getOption('output');
        $target = \is_string($target) ? $target : 'build/devtools-site';
        $target = Path::isAbsolute($target) ? $target : Path::join($directory->root->path, $target);
        $name = is_file($directory->stackFile()) ? new XmlStackStore()->read($directory->stackFile())->projectName : basename($directory->root->path);
        $started = microtime(true);

        try {
            $written = new SiteExporter()->export($directory, $target, $name);
        } catch (\RuntimeException $refused) {
            $io->error($refused->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d pages written to %s in %.1f s (%d KB): open index.html, no server needed.', $written['pages'], $target, microtime(true) - $started, intdiv($written['bytes'], 1024)));

        return Command::SUCCESS;
    }
}
