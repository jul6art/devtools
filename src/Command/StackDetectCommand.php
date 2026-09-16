<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackDetector;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Stack\XmlStackStore;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'stack:detect', description: 'Detect the stacks of a project and write .devtools/stack.xml')]
final class StackDetectCommand extends Command
{
    public function __construct(
        private readonly StackDetector $detector = new StackDetector(),
        private readonly XmlStackStore $store = new XmlStackStore(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'The project to analyse (default: the current directory)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());

        if (!is_dir($path)) {
            $io->error(\sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        $directory = new DevToolsDirectory(new ProjectRoot($path));
        $previous = is_file($directory->stackFile()) ? $this->store->read($directory->stackFile()) : null;
        $document = $this->detector->detect($directory->root, new XmlConfigReader()->read($directory->configFile()), $previous);
        $this->store->write($directory->stackFile(), $document);

        $io->title($document->projectName);
        $io->table(
            ['Root', 'Language', 'Framework', 'Adapter', 'Knowledge', 'Sources'],
            array_map(static fn (StackProfile $stack): array => [
                $stack->root,
                $stack->language,
                null === $stack->framework ? '—' : trim($stack->framework.' '.$stack->version),
                $stack->adapter,
                $stack->knowledgeKey ?? '—',
                implode(', ', $stack->sourceDirs),
            ], $document->stacks),
        );
        $io->success('Written to .devtools/stack.xml. Pin an element with locked="true" to keep a correction.');

        return Command::SUCCESS;
    }
}
