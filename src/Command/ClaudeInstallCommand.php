<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Claude\ClaudeSettings;
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

/**
 * What DevTools adds to a project for Claude Code: the skill that writes the pages (ADR-0011), and on demand
 * the hooks that give Claude the impact of a change before it makes it (ADR-0019).
 */
#[AsCommand(name: 'claude:install', description: 'Install the Claude Code skill that writes the workflow pages, and on demand the hooks')]
final class ClaudeInstallCommand extends Command
{
    private const string SKILL = 'claude/skills/devtools-inspect/SKILL.md';

    /**
     * The hooks of ADR-0019: event => matcher.
     */
    public const array HOOKS = ['PreToolUse' => 'Edit|Write|MultiEdit', 'SessionStart' => null];

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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite a skill that was modified in the project')
            ->addOption('skill', null, InputOption::VALUE_NONE, 'The skill that writes the pages — what is installed when nothing else is asked')
            ->addOption('hooks', null, InputOption::VALUE_NONE, 'The hooks of .claude/settings.json: the impact of a file before Claude edits it');
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

        $root = new ProjectRoot($path);
        $hooks = true === $input->getOption('hooks');
        $status = Command::SUCCESS;

        if (true === $input->getOption('skill') || !$hooks) {
            $status = $this->installSkill($io, $root, true === $input->getOption('force'));
        }

        if ($hooks) {
            try {
                new ClaudeSettings($this->writer)->installHooks($root->absolute('.claude/settings.json'), self::HOOKS, self::binary($root));
            } catch (\InvalidArgumentException $refusal) {
                $io->error($refusal->getMessage());

                return Command::FAILURE;
            }

            $io->success('Installed the DevTools hooks in .claude/settings.json: '.implode(', ', array_keys(self::HOOKS)).'. Commit it: the whole team gets them.');
        }

        return $status;
    }

    /**
     * How Claude Code runs DevTools in this project: the one Composer installed in it, or the one on the
     * PATH of a global installation.
     */
    public static function binary(ProjectRoot $root): string
    {
        return is_file($root->path.'/vendor/bin/devtools') ? '"${CLAUDE_PROJECT_DIR}/vendor/bin/devtools"' : 'devtools';
    }

    private function installSkill(SymfonyStyle $io, ProjectRoot $root, bool $force): int
    {
        $target = $root->absolute('.'.self::SKILL);
        $shipped = (string) file_get_contents(Resources::path(self::SKILL));

        // A skill adapted to the project is the team's work: never overwritten without --force.
        if (is_file($target) && file_get_contents($target) !== $shipped && !$force) {
            $io->warning('.claude/skills/devtools-inspect/SKILL.md was modified in this project and was left as is. Use --force to replace it.');

            return Command::FAILURE;
        }

        $this->writer->write($target, $shipped);
        $io->success('Installed .claude/skills/devtools-inspect/SKILL.md. Commit it: the whole team gets the skill.');

        return Command::SUCCESS;
    }
}
