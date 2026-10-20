<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rendering\MenuRenderer;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeBrief;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeCanvas;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeProvider;
use Jul6Art\DevTools\Stack\Knowledge\XmlKnowledgeBriefStore;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Stack\XmlStackStore;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Asks Claude to complete a knowledge sheet the project showed to be incomplete (ADR-0044).
 *
 * On demand only: an inspection never asks for it, or a sheet would change at every scan. The brief carries
 * the sheet and what the last inspection met without understanding it; the draft is the whole sheet,
 * applied by `workflows:apply`, and reaches the library as a revision.
 */
#[AsCommand(name: 'knowledge:amend', description: 'Ask Claude to complete a knowledge sheet with what the project showed it lacks')]
final class KnowledgeAmendCommand extends Command
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
            ->addArgument('key', InputArgument::REQUIRED, 'The stack and its major version: symfony-8, angular-18…')
            ->addArgument('path', InputArgument::OPTIONAL, 'The project (default: the current directory)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = $input->getArgument('key');
        $key = \is_string($key) ? $key : '';
        $path = $input->getArgument('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());

        if (!is_dir($path)) {
            $io->error(\sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        $root = new ProjectRoot($path);
        $index = new DevToolsDirectory($root)->indexFile();
        $directory = new DevToolsDirectory($root, is_file($index) ? new XmlIndexStore(new WorkflowTypeRegistry())->read($index)->docs : null);
        $config = new XmlConfigReader()->read($directory->configFile());
        $current = new KnowledgeProvider($this->writer, KnowledgeLibrary::forProject($root, $config, $this->writer))->provide($directory, $key);

        if (null === $current) {
            $io->error(\sprintf('No knowledge sheet "%s" to amend: neither this project, the library nor DevTools holds one. knowledge:list names those that exist.', $key));

            return Command::FAILURE;
        }

        $stacks = is_file($directory->stackFile()) ? array_values(array_filter(new XmlStackStore()->read($directory->stackFile())->stacks, static fn (StackProfile $stack): bool => $key === $stack->knowledgeKey)) : [];
        $stack = $stacks[0] ?? null;
        $briefPath = $directory->path('pending/'.KnowledgeBrief::amendmentFileName($key, 'brief.xml'));

        new XmlKnowledgeBriefStore($this->writer)->write($briefPath, new KnowledgeBrief(
            key: $key,
            language: $stack->language ?? 'unknown',
            framework: $stack?->framework,
            version: null === $stack?->version ? null : self::majorMinor($stack->version),
            canvasPath: KnowledgeCanvas::path(),
            promptPath: Resources::path('prompts/knowledge/amend-v1.md'),
            draftPath: DevToolsDirectory::NAME.'/pending/'.KnowledgeBrief::amendmentFileName($key, 'draft.md'),
            kind: KnowledgeBrief::AMEND,
            currentPath: $current,
            uncovered: $this->uncovered($directory, $stacks),
            fallbacks: $this->fallbacks($directory, $config),
            withoutAdapter: array_values(array_map(static fn (StackProfile $profile): string => $profile->root, array_filter($stacks, static fn (StackProfile $profile): bool => 'claude' === $profile->adapter))),
        ));

        $io->success([
            \sprintf('%s written.', DevToolsDirectory::NAME.'/pending/'.KnowledgeBrief::amendmentFileName($key, 'brief.xml')),
            'Ask Claude to follow the prompt it names, then run workflows:apply.',
        ]);

        return Command::SUCCESS;
    }

    /**
     * The files the last inspection attached to no workflow, under the roots of the stacks of this key — read
     * back from the menu, the one place they are written (ADR-0049).
     *
     * @param list<StackProfile> $stacks
     *
     * @return list<string>
     */
    private function uncovered(DevToolsDirectory $directory, array $stacks): array
    {
        if (!is_file($directory->menuFile())) {
            return [];
        }

        $roots = array_map(static fn (StackProfile $stack): string => $stack->root, $stacks);
        $files = [];

        foreach (MenuRenderer::uncoveredIn((string) file_get_contents($directory->menuFile())) as $file) {
            if ([] === $roots || \in_array('.', $roots, true) || array_any($roots, static fn (string $root): bool => str_starts_with($file->path, $root.'/'))) {
                $files[] = $file->path;
            }
        }

        return $files;
    }

    /**
     * A workflow a native adapter produced at medium confidence is one its static scan found alone: the
     * framework's console could not be asked (ADR-0007).
     *
     * @return list<string>
     */
    private function fallbacks(DevToolsDirectory $directory, Config $config): array
    {
        $tracking = new XmlTrackingStore(new WorkflowTypeRegistry($config->customTypes));
        $counts = [];

        foreach (glob($directory->path('workflows/*/*.xml')) ?: [] as $file) {
            $document = $tracking->read($file);

            if (str_starts_with($document->producer->value, 'native:') && 'medium' === $document->confidence->value) {
                $counts[$document->producer->value] = ($counts[$document->producer->value] ?? 0) + 1;
            }
        }

        ksort($counts, \SORT_STRING);

        return array_values(array_map(
            static fn (string $producer, int $count): string => \sprintf('%s answered with medium confidence for %d workflow(s): its console could not be queried at the last inspection.', $producer, $count),
            array_keys($counts),
            $counts,
        ));
    }

    private static function majorMinor(string $version): ?string
    {
        return 1 === preg_match('/^(\d+)\.(\d+)/', $version, $matches) ? $matches[1].'.'.$matches[2] : (1 === preg_match('/^(\d+)$/', $version, $matches) ? $matches[1].'.0' : null);
    }
}
