<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Ai\Api\ApiFailed;
use Jul6Art\DevTools\Ai\Api\Pricing;
use Jul6Art\DevTools\Ask\PageSelector;
use Jul6Art\DevTools\Ask\QuestionAnswerer;
use Jul6Art\DevTools\Ask\SearchIndexStore;
use Jul6Art\DevTools\Ask\SelectedPage;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `devtools ask "<question>"` (ADR-0039): the pages that answer it, selected without AI — `--pages` — or an
 * answer written from them alone through the Claude API, where the project turned it on (ADR-0035).
 */
#[AsCommand(name: 'ask', description: 'Ask the documentation a question: the pages that answer it (--pages), or an answer from them through the Claude API')]
final class AskCommand extends Command
{
    public function __construct(
        private readonly QuestionAnswerer $answerer = new QuestionAnswerer(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('question', InputArgument::REQUIRED, 'The question, in plain words')
            ->addOption('pages', null, InputOption::VALUE_NONE, 'List the pages that answer it, with their score: no AI, nothing sent')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many pages, 1 to '.PageSelector::LIMIT, (string) PageSelector::LIMIT)
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $question = $input->getArgument('question');
        $question = \is_string($question) ? trim($question) : '';
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $limit = $input->getOption('limit');
        $format = $input->getOption('format');

        $refused = match (true) {
            '' === $question => 'Ask a question.',
            !is_dir($path) => \sprintf('"%s" is not a directory.', $path),
            !\is_string($limit) || !ctype_digit($limit) || (int) $limit < 1 || (int) $limit > PageSelector::LIMIT => \sprintf('--limit takes 1 to %d.', PageSelector::LIMIT),
            !\in_array($format, ['text', 'json'], true) => '--format takes text or json.',
            default => null,
        };

        if (null !== $refused) {
            $io->error($refused);

            return Command::INVALID;
        }

        $directory = DevToolsDirectory::documented(new ProjectRoot($path));

        if (!is_file($directory->indexFile())) {
            $io->error('The project was never inspected: run workflows:inspect first.');

            return Command::FAILURE;
        }

        $config = new XmlConfigReader()->read($directory->configFile());
        $types = new WorkflowTypeRegistry($config->customTypes);
        $pages = new PageSelector()->select(new SearchIndexStore()->current($directory, $types), $question, (int) $limit);

        if (true === $input->getOption('pages')) {
            return $this->pages($io, $output, $question, $pages, 'json' === $format);
        }

        if (!$config->aiEnabled) {
            $io->error('Answering sends the pages to the Claude API and spends tokens, which this project has not turned on (<ai enabled="true"/> in .devtools/config.xml). ask --pages lists the pages that answer it, without AI.');

            return Command::FAILURE;
        }

        if (!$this->answerer->hasKey()) {
            $io->error('ANTHROPIC_API_KEY is not set: the key is read from that variable only — never from config.xml.');

            return Command::FAILURE;
        }

        if ([] === $pages) {
            $io->writeln('Not documented: no page shares a word with the question.');

            return Command::SUCCESS;
        }

        try {
            $answer = $this->answerer->answer($directory, $config, $types, $question, $pages);
        } catch (ApiFailed $failed) {
            $io->error($failed->getMessage());

            return Command::FAILURE;
        }

        if ('json' === $format) {
            $output->writeln(json_encode(['question' => $question, 'answer' => $answer->text, 'sources' => array_map(static fn (SelectedPage $page): array => $page->toArray(), $answer->sources), 'warnings' => $answer->warnings, 'usage' => $answer->usage], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $io->writeln($answer->text);
        $io->newLine();
        $io->writeln('<options=bold>Sources</>');
        $io->listing(array_map(static fn (SelectedPage $page): string => \sprintf('%s — %s, revised %s%s', $page->id, $page->page, $page->updated, $page->isStale() ? ' ('.$page->status.')' : ''), $answer->sources));

        foreach ($answer->warnings as $warning) {
            $io->warning($warning);
        }

        $cost = Pricing::estimate($config->aiModel, $answer->usage);
        $io->writeln(\sprintf('<fg=gray>%s · %d input tokens · %d output%s</>', $config->aiModel, $answer->usage['input'], $answer->usage['output'], null === $cost ? '' : \sprintf(' · about $%.2f', $cost)));

        return Command::SUCCESS;
    }

    /**
     * @param list<SelectedPage> $pages
     */
    private function pages(SymfonyStyle $io, OutputInterface $output, string $question, array $pages, bool $json): int
    {
        $warnings = array_map(static fn (SelectedPage $page): string => \sprintf('%s is %s: its page may describe the code as it was (revised %s).', $page->id, $page->status, $page->updated), array_values(array_filter($pages, static fn (SelectedPage $page): bool => $page->isStale())));

        if ($json) {
            $output->writeln(json_encode(['question' => $question, 'pages' => array_map(static fn (SelectedPage $page): array => $page->toArray(), $pages), 'warnings' => $warnings], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        if ([] === $pages) {
            $io->writeln('Not documented: no page shares a word with the question.');

            return Command::SUCCESS;
        }

        $io->table(['#', 'Workflow', 'Score', 'Page', 'Revised', 'Found'], array_map(
            static fn (int $rank, SelectedPage $page): array => [(string) ($rank + 1), $page->id, \sprintf('%.2f', $page->score), $page->page, $page->updated.($page->isStale() ? ' ('.$page->status.')' : ''), null === $page->via ? 'by its words' : 'next to '.$page->via],
            array_keys($pages),
            $pages,
        ));

        foreach ($warnings as $warning) {
            $io->warning($warning);
        }

        return Command::SUCCESS;
    }
}
