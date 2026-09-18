<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Console\CheckRenderer;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `workflows:check` (ADR-0017): the gate. It fails when a workflow is not documented, when a page has
 * lost its workflow, or when a fact changed since the pages were written — and it writes nothing.
 *
 * ⚠️ It does not fail on bytes. A file whose content changed without changing a fact is not a cause:
 * the gate that reported it made people regenerate 127 pages to find out that nothing had happened.
 */
#[AsCommand(name: 'workflows:check', description: 'Fail when the documentation no longer matches the code')]
final class WorkflowsCheckCommand extends Command
{
    public function __construct(
        private readonly InspectionPipeline $pipeline = new InspectionPipeline(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'The project (default: the current directory)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Only this type of workflow')
            ->addOption('require-ai', null, InputOption::VALUE_NONE, 'Fail as well on a page that has never been written')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or github', 'text');
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

        $format = $input->getOption('format');

        if (!\in_array($format, ['text', 'github'], true)) {
            $io->error(\sprintf('"%s" is not a format: text or github.', \is_string($format) ? $format : 'null'));

            return Command::INVALID;
        }

        $only = $input->getOption('only');
        $report = $this->pipeline->run(new InspectionOptions($path, only: \is_string($only) ? $only : null, dryRun: true, noAi: true));

        foreach ($report->errors as $error) {
            $io->error($error);
        }

        if ([] !== $report->errors) {
            return Command::INVALID;
        }

        $groups = $report->changeGroups();
        $undocumented = true === $input->getOption('require-ai') ? $report->neverWritten : [];

        if ('github' === $format) {
            foreach (CheckRenderer::annotations($report, $groups, $undocumented) as $annotation) {
                $output->writeln($annotation);
            }

            return [] === CheckRenderer::causes($report, $groups, $undocumented) ? Command::SUCCESS : 1;
        }

        $causes = CheckRenderer::causes($report, $groups, $undocumented);

        $output->writeln('');
        $output->writeln(\sprintf(' <options=bold>DevTools — %s</>   contrôle des workflows', $report->projectName));
        $output->writeln('');

        if ([] === $causes) {
            $output->writeln(\sprintf(' <info>✔</info> rien à signaler · %d fichiers parcourus', $report->filesParsed));
            $output->writeln('');

            return Command::SUCCESS;
        }

        $output->writeln(\sprintf(' <fg=red>✖</> %d cause%s', \count($causes), 1 === \count($causes) ? '' : 's'));
        $output->writeln('');

        foreach ($causes as $cause) {
            $output->writeln(' '.$cause);
        }

        $output->writeln('');
        $output->writeln(' <fg=gray>devtools workflows:diff --code pour le détail · workflows:inspect pour régénérer</>');
        $output->writeln('');

        return Command::FAILURE;
    }
}
