<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A fresh directory per test, removed afterwards, resolved through realpath so that assertions on
 * paths hold on macOS, where the system temp directory is itself a symbolic link.
 */
trait UsesTemporaryDirectory
{
    private ?string $temporaryDirectory = null;

    protected function temporaryDirectory(): string
    {
        if (null === $this->temporaryDirectory) {
            $path = sys_get_temp_dir().'/devtools-test-'.bin2hex(random_bytes(6));
            mkdir($path, 0o777, true);
            $this->temporaryDirectory = (string) realpath($path);
        }

        return $this->temporaryDirectory;
    }

    #[After]
    protected function removeTemporaryDirectory(): void
    {
        if (null !== $this->temporaryDirectory) {
            new Filesystem()->remove($this->temporaryDirectory);
            $this->temporaryDirectory = null;
        }
    }

    /**
     * Everything an inspection wrote and a project commits: the machinery of `.devtools/` and the pages of
     * the documentation directory, under their project-relative paths — work areas and schema copies left
     * out, since they are recomputed and ignored by git.
     *
     * @return array<string, string>
     */
    protected static function documentedTree(string $project, string $docs = 'docs/workflows'): array
    {
        $files = [];

        foreach (['.devtools', $docs] as $directory) {
            foreach (is_dir($project.'/'.$directory) ? self::tree($project.'/'.$directory) : [] as $path => $content) {
                if (!preg_match('#^(reports|pending|schemas)/#', $path)) {
                    $files[$directory.'/'.$path] = $content;
                }
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Every file under a directory with its content, to compare a tree before and after an operation.
     *
     * @return array<string, string>
     */
    protected static function tree(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[substr($file->getPathname(), \strlen($directory) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
