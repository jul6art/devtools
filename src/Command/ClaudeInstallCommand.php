<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'claude:install', description: 'Install the Claude Code skill that writes the workflow pages')]
final class ClaudeInstallCommand extends Command
{
    private const string SKILL = 'claude/skills/devtools-inspect/SKILL.md';

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
            ->addArgument('path', InputArgument::OPTIONAL, 'The project (default: the current directory)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite a skill that was modified in the project');
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

        $target = new ProjectRoot($path)->absolute('.'.self::SKILL);
        $shipped = (string) file_get_contents(Resources::path(self::SKILL));

        // A skill adapted to the project is the team's work: never overwritten without --force.
        if (is_file($target) && file_get_contents($target) !== $shipped && true !== $input->getOption('force')) {
            $io->warning('.claude/skills/devtools-inspect/SKILL.md was modified in this project and was left as is. Use --force to replace it.');

            return Command::FAILURE;
        }

        $this->writer->write($target, $shipped);
        $io->success('Installed .claude/skills/devtools-inspect/SKILL.md. Commit it: the whole team gets the skill.');

        return Command::SUCCESS;
    }
}
