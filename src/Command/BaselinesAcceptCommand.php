<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Scenario\Run\XmlRunWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Makes the captures of a run the baselines the next runs are compared with (ADR-0022) — once a human has
 * looked at them. Nothing accepts a baseline on its own: one the author of the change accepted would
 * reproduce the very bias the comparison is there to catch.
 */
#[AsCommand(name: 'baselines:accept', description: 'Make the screenshots of a run the baselines the next runs are compared with')]
final class BaselinesAcceptCommand extends Command
{
    public function __construct(private readonly ?string $defaultPath = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('run', InputArgument::REQUIRED, 'The run: the name of its directory in .devtools/runs/, or "last"')
            ->addArgument('scenarios', InputArgument::IS_ARRAY, 'Scenarios or workflows: route.order.new/happy-path, route.order.new (default: every capture of the run)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $run = $input->getArgument('run');
        $run = \is_string($run) ? $run : 'last';
        $asked = $input->getArgument('scenarios');
        $asked = \is_array($asked) ? array_values(array_filter($asked, \is_string(...))) : [];

        if (!is_dir($path)) {
            $io->error(\sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        $directory = new DevToolsDirectory(new ProjectRoot($path));
        $runs = glob($directory->path('runs/*/result.xml')) ?: [];
        sort($runs, \SORT_STRING);
        $result = 'last' === $run ? array_last($runs) : $directory->path('runs/'.basename($run).'/result.xml');

        if (null === $result || !is_file($result)) {
            $io->error('last' === $run ? 'No run yet: scenarios:run writes them.' : \sprintf('No run "%s" in .devtools/runs/.', $run));

            return Command::INVALID;
        }

        try {
            $captures = self::captures($result, $asked);
        } catch (InvalidXml $invalid) {
            $io->error($invalid->getMessage());

            return Command::FAILURE;
        }

        if ([] === $captures) {
            $io->text([] === $asked ? 'The run has no screenshot to accept.' : \sprintf('No screenshot of %s in this run.', implode(', ', $asked)));

            return [] === $asked ? Command::SUCCESS : Command::INVALID;
        }

        $filesystem = new Filesystem();
        $artifacts = \dirname($result);

        foreach ($captures as $capture => [$screenshot, $dom]) {
            $baseline = $directory->baselinesDirectory().'/'.$capture;
            $filesystem->copy($artifacts.'/'.$screenshot, $baseline.'.png', true);
            $filesystem->copy($artifacts.'/'.$dom, $baseline.'.dom.html', true);
            $io->writeln(\sprintf('<info>✓</info> %s', $capture));
        }

        $io->text(\sprintf('%d baselines in .devtools/baselines/: commit them with the change they show.', \count($captures)));

        return Command::SUCCESS;
    }

    /**
     * The captures of the `screenshot` steps — never those of a failure — of the scenarios asked.
     *
     * @param list<string> $asked scenarios or workflows; all when empty
     *
     * @return array<string, array{string, string}> `<scenario-id>/<name>` => the image and the DOM, relative to the run
     */
    private static function captures(string $result, array $asked): array
    {
        $root = DomBuilder::root(new SafeXmlLoader()->loadValidated((string) file_get_contents($result), $result, Resources::path('schemas/run.xsd'), 1));
        $dom = new DomBuilder(XmlRunWriter::NAMESPACE);
        $captures = [];

        foreach ($dom->children($root, 'scenario') as $scenario) {
            $id = $scenario->getAttribute('id');

            if ([] !== $asked && !\in_array($id, $asked, true) && !\in_array(explode('/', $id)[0], $asked, true)) {
                continue;
            }

            foreach ($dom->children($scenario, 'step') as $step) {
                $screenshot = $dom->optional($step, 'screenshot')?->getAttribute('path');
                $page = $dom->optional($step, 'dom')?->getAttribute('path');

                if ('screenshot' === $step->getAttribute('action') && null !== $screenshot && null !== $page) {
                    $captures[substr($screenshot, 0, -\strlen('.png'))] = [$screenshot, $page];
                }
            }
        }

        return $captures;
    }
}
