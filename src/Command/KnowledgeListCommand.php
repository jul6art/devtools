<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary;
use Jul6Art\DevTools\Stack\Knowledge\LibraryEntry;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What the shared knowledge library holds, and where it lives (ADR-0041).
 */
#[AsCommand(name: 'knowledge:list', description: 'List the stack knowledge shared between projects')]
final class KnowledgeListCommand extends Command
{
    public function __construct(private readonly ?string $defaultPath = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'The project whose configuration names the library (default: the current directory)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $library = KnowledgeLibrary::locate();

        if (is_dir($path)) {
            $root = new ProjectRoot($path);
            $library = KnowledgeLibrary::forProject($root, new XmlConfigReader()->read(new DevToolsDirectory($root)->configFile()));
        }

        $io->title('Knowledge library');
        $io->text([\sprintf('Path: <info>%s</info>', $library->path), \sprintf('Writable: %s', $library->isWritable() ? 'yes' : '<comment>no</comment>')]);

        $entries = $library->entries();
        $rows = array_map(
            static fn (string $key): array => [$key, $entries[$key]->project ?? '—', ($entries[$key] ?? null) instanceof LibraryEntry ? $entries[$key]->at->format('Y-m-d') : '—'],
            $library->keys(),
        );

        if ([] === $rows) {
            $io->text('The library is empty.');
        } else {
            $io->table(['Key', 'From', 'Added'], $rows);
        }

        $embedded = array_values(array_diff(self::embeddedKeys(), $library->keys()));

        if ([] !== $embedded) {
            $io->text(\sprintf('Shipped with DevTools and not in the library: %s', implode(', ', $embedded)));
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    public static function embeddedKeys(): array
    {
        $keys = array_map(static fn (string $file): string => basename($file, '.md'), glob(Resources::path('knowledge/*.md')) ?: []);
        sort($keys, \SORT_STRING);

        return array_values(array_filter($keys, static fn (string $key): bool => '_canvas' !== $key));
    }
}
