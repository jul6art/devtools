<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Console\ImpactRenderer;
use Jul6Art\DevTools\Impact\ImpactResolver;
use Jul6Art\DevTools\Impact\ImpactUnavailable;
use Jul6Art\DevTools\Impact\XmlImpactWriter;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\XmlStackStore;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Which workflows a change reaches (ADR-0016): files given by name, the files of a commit or of a range,
 * or the working tree — read from the map the last inspection wrote, never from the code.
 */
#[AsCommand(name: 'workflows:impact', description: 'List the workflows a change reaches: files, a commit, a range or the working tree')]
final class WorkflowsImpactCommand extends Command
{
    private const array FORMATS = ['text', 'json', 'xml'];

    /**
     * A reference git reads, never an option it would take one for.
     */
    private const string REFERENCE = '/^[A-Za-z0-9_][A-Za-z0-9._\/~^@{}-]*$/';

    public function __construct(
        private readonly GitClient $git = new GitClient(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('files', InputArgument::IS_ARRAY, 'Files of the project, relative to it or absolute')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)')
            ->addOption('commit', null, InputOption::VALUE_REQUIRED, 'The files of this commit')
            ->addOption('range', null, InputOption::VALUE_REQUIRED, 'The files of this range of commits: a..b')
            ->addOption('changed', null, InputOption::VALUE_NONE, 'The files modified, staged or untracked in the working tree')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text, json or xml', 'text')
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, '1 follows mechanisms and dependencies, 0 does not', '1');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $format = $input->getOption('format');
        $depth = $input->getOption('depth');
        $commit = $input->getOption('commit');
        $range = $input->getOption('range');
        $files = $input->getArgument('files');
        $files = \is_array($files) ? array_values(array_filter($files, \is_string(...))) : [];
        $sources = \count(array_filter([[] !== $files, \is_string($commit), \is_string($range), true === $input->getOption('changed')]));

        $refused = match (true) {
            !is_dir($path) => \sprintf('"%s" is not a directory.', $path),
            !\in_array($format, self::FORMATS, true) => \sprintf('"%s" is not a format: text, json or xml.', \is_string($format) ? $format : ''),
            !\in_array($depth, ['0', '1'], true) => '--depth is 0 or 1.',
            1 !== $sources => 'Give files, or one of --commit, --range, --changed.',
            \is_string($commit) && 1 !== preg_match(self::REFERENCE, $commit) => \sprintf('"%s" is not a commit reference.', $commit),
            \is_string($range) && 1 !== preg_match('/^[A-Za-z0-9_][A-Za-z0-9._\/~^@{}-]*\.\.\.?[A-Za-z0-9_][A-Za-z0-9._\/~^@{}-]*$/', $range) => \sprintf('"%s" is not a range: a..b.', $range),
            default => null,
        };

        if (null !== $refused) {
            $io->error($refused);

            return Command::INVALID;
        }

        $root = new ProjectRoot($path);
        $files = match (true) {
            \is_string($commit) => $this->git->filesOfCommit($root, $commit),
            \is_string($range) => $this->git->filesOfRange($root, $range),
            true === $input->getOption('changed') => $this->git->workingTreeChanges($root),
            default => $files,
        };

        if (null === $files) {
            $io->error('git could not answer: the project is not in a repository, or the reference is unknown.');

            return Command::FAILURE;
        }

        $directory = DevToolsDirectory::documented($root);

        try {
            $impact = new ImpactResolver(new WorkflowTypeRegistry(new XmlConfigReader()->read($directory->configFile())->customTypes))->resolve($directory, $files, (int) $depth);
        } catch (ImpactUnavailable|PathOutsideProject $refusal) {
            $io->error($refusal->getMessage());

            return $refusal instanceof PathOutsideProject ? Command::INVALID : Command::FAILURE;
        }

        match ($format) {
            'json' => $output->writeln(json_encode($impact->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)),
            'xml' => $output->write(new XmlImpactWriter()->serialize($impact)),
            default => new ImpactRenderer()->render($io, self::projectName($directory), $impact),
        };

        return Command::SUCCESS;
    }

    private static function projectName(DevToolsDirectory $directory): string
    {
        return is_file($directory->stackFile()) ? new XmlStackStore()->read($directory->stackFile())->projectName : basename($directory->root->path);
    }
}
