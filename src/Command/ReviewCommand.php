<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Review\Web\ReviewServer;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * The review page (ADR-0028): the captures of a run next to their baselines and diffs, an element picked on the
 * captured page, a feedback in one line — served on this machine only, behind a token, until stopped.
 */
#[AsCommand(name: 'review', description: 'Open the review page: captures, baselines and diffs, and feedback in one line')]
final class ReviewCommand extends Command
{
    /**
     * Seconds the server is given to answer.
     */
    private const int START = 5;

    /**
     * @param \Closure(Process, string): int|null $serve what is done once the server answers, with its address — null:
     *                                                   wait until it is stopped
     */
    public function __construct(private readonly ?string $defaultPath = null, private readonly ?\Closure $serve = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'A run of .devtools/runs/: 2026-10-20-153000 (default: the last one, read again on every page)')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'The port on 127.0.0.1', (string) ReviewServer::PORT)
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $port = $input->getOption('port');
        $port = \is_string($port) && 1 === preg_match('/^\d{1,5}$/', $port) ? (int) $port : 0;
        $run = $input->getOption('run');
        $run = \is_string($run) ? basename($run) : null;

        if (!is_dir($path) || $port < 1 || $port > 65535) {
            $io->error(is_dir($path) ? '--port is a port number: 8765.' : \sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        $directory = DevToolsDirectory::documented(new ProjectRoot($path));

        if (null !== $run && !is_file($directory->path('runs/'.$run.'/result.xml'))) {
            $io->error(\sprintf('No run "%s" in .devtools/runs/.', $run));

            return Command::INVALID;
        }

        $token = ReviewServer::token();
        $server = ReviewServer::process($directory, $port, $token, $run);
        $failure = ReviewServer::start($server, self::START);

        if (null !== $failure) {
            $io->error(['The review server did not start: '.$failure, str_contains($failure, 'in use') ? '--port gives another one.' : 'Run it again with another --port, or read what PHP said above.']);

            return Command::FAILURE;
        }

        $url = ReviewServer::url($port, $token);
        $io->success(\sprintf('The review page: %s', $url));
        $io->text(['Only this machine can reach it, and only with this address. It writes feedback and baselines, nothing else.', 'Ctrl+C stops it.']);

        return ($this->serve ?? static function (Process $server): int {
            $server->wait();

            return Command::SUCCESS;
        })($server, $url);
    }
}
