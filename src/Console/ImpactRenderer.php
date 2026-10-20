<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Console;

use Jul6Art\DevTools\Impact\Impact;
use Jul6Art\DevTools\Impact\ImpactedWorkflow;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What `workflows:impact` prints for a human (ADR-0016): the workflows a change reaches, how, and where to
 * read about them — the warning first, the files nothing references last.
 */
final readonly class ImpactRenderer
{
    public function render(SymfonyStyle $io, string $project, Impact $impact): void
    {
        $io->writeln(\sprintf(' <options=bold>DevTools — %s</>   workflow impact', $project));
        $io->newLine();

        if (null !== $impact->warning()) {
            $io->writeln(' <comment>⚠ '.$impact->warning().'</comment>');
            $io->newLine();
        }

        $count = \count($impact->workflows);
        $io->writeln(\sprintf(' %d workflow%s reached', $count, 1 === $count ? '' : 's'));

        if ([] !== $impact->workflows) {
            $io->table(['Workflow', 'Relation', 'Through', 'Page', 'Status'], array_map(
                static fn (ImpactedWorkflow $workflow): array => [$workflow->id->value, $workflow->relation->value, implode("\n", $workflow->files), $workflow->page, $workflow->status->value],
                $impact->workflows,
            ));
        } else {
            $io->newLine();
        }

        foreach ($impact->uncovered as $file) {
            $io->writeln(\sprintf(' <comment>Not covered</comment> %s — no workflow references it yet; workflows:inspect will tell where it belongs.', $file));
        }
    }
}
