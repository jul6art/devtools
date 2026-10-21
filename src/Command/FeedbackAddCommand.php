<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Feedback\Feedback;
use Jul6Art\DevTools\Feedback\FeedbackIndexEntry;
use Jul6Art\DevTools\Feedback\FeedbackRecorder;
use Jul6Art\DevTools\Feedback\FeedbackSource;
use Jul6Art\DevTools\Feedback\FeedbackStatus;
use Jul6Art\DevTools\Feedback\FeedbackTarget;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
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
 * Records a feedback as data (ADR-0025): a target, a category of the closed list, one line. Already said: an
 * occurrence is added rather than a duplicate — asked, or decided with --auto. Seen twice: a rule is proposed.
 */
#[AsCommand(name: 'feedback:add', description: 'Record a feedback: what it is about, its category, one line')]
final class FeedbackAddCommand extends Command
{
    public function __construct(
        private readonly Clock $clock = new SystemClock(),
        private readonly GitClient $git = new GitClient(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('summary', InputArgument::OPTIONAL, 'One line, 200 characters at most: what is wrong, and what it should be')
            ->addOption('workflow', null, InputOption::VALUE_REQUIRED, 'The workflow it is about: route.order.new')
            ->addOption('selector', null, InputOption::VALUE_REQUIRED, 'The element of the page: #order_product')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'The file: templates/order/_form.html.twig')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'A category of the closed list: feedback:list --categories gives them')
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'human, or verifier for a finding of the verifier', 'human')
            ->addOption('auto', null, InputOption::VALUE_NONE, 'Already said: add an occurrence without asking')
            ->addOption('new', null, InputOption::VALUE_NONE, 'Already said: record it apart all the same')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $by = FeedbackSource::tryFrom(self::option($input, 'by') ?? '');

        if (!is_dir($path) || !$by instanceof FeedbackSource) {
            $io->error(is_dir($path) ? '--by is human or verifier.' : \sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        $recorder = FeedbackRecorder::for(DevToolsDirectory::documented(new ProjectRoot($path)), $this->clock, $this->git);
        $target = new FeedbackTarget(self::option($input, 'workflow'), self::option($input, 'selector'), self::option($input, 'file'));
        $category = self::option($input, 'category');
        $summary = $input->getArgument('summary');
        $summary = \is_string($summary) ? $summary : null;

        // What the command line did not say is asked — when there is someone to ask.
        if ($input->isInteractive()) {
            $category ??= self::text($io->choice('Category', $recorder->categories()->names()));
            $summary ??= self::text($io->ask('In one line, what is wrong, and what it should be'));

            if ($target->isEmpty()) {
                $target = new FeedbackTarget(self::text($io->ask('Workflow (empty: none)')), self::text($io->ask('Selector (empty: none)')), self::text($io->ask('File (empty: none)')));
            }
        }

        $refusals = $recorder->refusals($target, $category ?? '', $summary ?? '');

        if ([] !== $refusals) {
            $io->error($refusals);

            return Command::INVALID;
        }

        $category = (string) $category;
        $known = true === $input->getOption('new') ? null : $recorder->recurrenceOf($target, $category);

        if ($known instanceof FeedbackIndexEntry && true !== $input->getOption('auto')) {
            $again = $input->isInteractive() && $io->confirm(\sprintf('Already said in %s: « %s ». Add an occurrence to it?', $known->id, $known->summary));

            if (!$again && !$input->isInteractive()) {
                $io->warning([\sprintf('Already said in %s: « %s ».', $known->id, $known->summary), '--auto adds an occurrence to it; --new records this one apart.']);

                return Command::FAILURE;
            }

            $known = $again ? $known : null;
        }

        $feedback = $known instanceof FeedbackIndexEntry ? $recorder->seenAgain($known->id, $target, $by) : $recorder->create($target, $category, (string) $summary, $by);
        $io->success($known instanceof FeedbackIndexEntry ? \sprintf('%s seen again: %d occurrences.', $feedback->id, \count($feedback->occurrences)) : \sprintf('Recorded %s.', $feedback->id));
        self::proposePromotion($io, $feedback);

        return Command::SUCCESS;
    }

    /**
     * Seen twice: a rule, injected where it applies, is cheaper than a third time (ADR-0026).
     */
    public static function proposePromotion(SymfonyStyle $io, Feedback $feedback): void
    {
        if (\count($feedback->occurrences) >= 2 && FeedbackStatus::Promoted !== $feedback->status) {
            $io->note(\sprintf('%s was seen %d times: devtools rules:promote %s makes it a rule, given to Claude where it applies.', $feedback->id, \count($feedback->occurrences), $feedback->id));
        }
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        return self::text($input->getOption($name));
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
