<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

/**
 * A fixture project copied into the test's temporary directory, so a test can write `.devtools/` into it
 * and change its code without touching the versioned fixture.
 */
trait CopiesFixtureProjects
{
    use UsesTemporaryDirectory;

    protected function copyFixtureProject(string $project, bool $withDependencies = false): string
    {
        $source = __DIR__.'/../Fixtures/projects/'.$project;
        $copy = $this->temporaryDirectory().'/'.$project;
        $filesystem = new Filesystem();

        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !\in_array($file->getFilename(), $withDependencies ? ['var', '.devtools'] : ['vendor', 'var', '.devtools'], true),
        ), \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $target = $copy.substr($file->getPathname(), \strlen($source));

            if ($file->isDir()) {
                $filesystem->mkdir($target);
            } else {
                $filesystem->copy($file->getPathname(), $target);
            }
        }

        return $copy;
    }
}
