<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeCanvas;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Copies a knowledge file into `resources/knowledge/` so it ships with the package (ADR-0041).
 *
 * The deposit into the library is automatic; embedding a sheet in the published package is not — it is
 * a pull request a human opens, and this command only prepares it.
 */
#[AsCommand(name: 'knowledge:promote', description: 'Copy a knowledge file into the knowledge DevTools ships')]
final class KnowledgePromoteCommand extends Command
{
    public function __construct(
        private readonly AtomicFileWriter $writer = new AtomicFileWriter(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'The stack and its major version: symfony-7, angular-18…')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Take the file from this project\'s .devtools/knowledge/ instead of the library');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = $input->getArgument('key');
        $key = \is_string($key) ? $key : '';

        if (!KnowledgeLibrary::embeddedIsWritable()) {
            $io->error(\sprintf('%s cannot be written: promotion only makes sense in a source checkout of DevTools.', Resources::path('knowledge')));

            return Command::FAILURE;
        }

        $target = Resources::path('knowledge/'.$key.'.md');

        if (is_file($target)) {
            $io->error(\sprintf('DevTools already ships "%s" (%s); correct that file instead.', $key, $target));

            return Command::FAILURE;
        }

        $source = $this->source($input, $key);

        if (null === $source) {
            $io->error(\sprintf('No knowledge file for "%s" to promote.', $key));

            return Command::FAILURE;
        }

        $markdown = (string) file_get_contents($source);
        $problems = new KnowledgeCanvas()->problems($markdown);

        if ([] !== $problems) {
            $io->error([\sprintf('%s does not follow the canvas:', $source), ...$problems]);

            return Command::FAILURE;
        }

        $this->writer->write($target, $markdown);
        $io->success(\sprintf('%s → %s — commit it to ship it.', $source, $target));

        return Command::SUCCESS;
    }

    private function source(InputInterface $input, string $key): ?string
    {
        $from = $input->getOption('from');

        if (\is_string($from)) {
            $file = new DevToolsDirectory(new ProjectRoot($from))->path('knowledge/'.$key.'.md');

            return is_file($file) ? $file : null;
        }

        $path = $this->defaultPath ?? (string) getcwd();
        $library = is_dir($path)
            ? KnowledgeLibrary::forProject(new ProjectRoot($path), new XmlConfigReader()->read(new DevToolsDirectory(new ProjectRoot($path))->configFile()))
            : KnowledgeLibrary::locate();

        return $library->file($key);
    }
}
