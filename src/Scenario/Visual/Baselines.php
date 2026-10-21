<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Visual;

use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A capture of a run made the baseline of the next ones (ADR-0022): its image and its DOM, copied to
 * `.devtools/baselines/<scenario-id>/<name>` — by `baselines:accept`, or from the review page (ADR-0028), once a
 * human has looked at it.
 */
final readonly class Baselines
{
    public function __construct(private DevToolsDirectory $directory, private Filesystem $filesystem = new Filesystem())
    {
    }

    /**
     * @param string $artifacts  the run's directory
     * @param string $capture    `<scenario-id>/<name>`
     * @param string $screenshot relative to the run
     * @param string $dom        relative to the run
     *
     * @return string the baseline's image, relative to the project
     */
    public function accept(string $artifacts, string $capture, string $screenshot, string $dom): string
    {
        $baseline = $this->directory->baselinesDirectory().'/'.$capture;
        $this->filesystem->copy($artifacts.'/'.$screenshot, $baseline.'.png', true);
        $this->filesystem->copy($artifacts.'/'.$dom, $baseline.'.dom.html', true);

        return $this->directory->root->relative($baseline.'.png');
    }
}
