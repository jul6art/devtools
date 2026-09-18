<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Support;

use Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A fresh directory per test, removed afterwards, resolved through realpath so that assertions on
 * paths hold on macOS, where the system temp directory is itself a symbolic link.
 */
trait UsesTemporaryDirectory
{
    private ?string $temporaryDirectory = null;

    private ?string $previousKnowledgeHome = null;

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
     * Each test gets its own knowledge library (ADR-0041): a sheet one test deposits must never answer
     * the stack the next test means to be unknown, and no test may ever write into `resources/knowledge/`
     * of this repository.
     *
     * Both `putenv()` and `$_SERVER` are set: Symfony Process keeps only the variables present in both,
     * so a sub-process would otherwise fall back to the library of the machine.
     */
    #[Before]
    protected function isolateKnowledgeLibrary(): void
    {
        $previous = getenv(KnowledgeLibrary::HOME_ENV);
        $this->previousKnowledgeHome = \is_string($previous) ? $previous : null;
        self::putKnowledgeHome($this->temporaryDirectory().'/knowledge-library');
    }

    #[After]
    protected function restoreKnowledgeLibrary(): void
    {
        self::putKnowledgeHome($this->previousKnowledgeHome);
        $this->previousKnowledgeHome = null;
    }

    /**
     * The library this test writes to, created on first use like any other library.
     */
    protected function knowledgeLibrary(): KnowledgeLibrary
    {
        return KnowledgeLibrary::locate();
    }

    private static function putKnowledgeHome(?string $path): void
    {
        if (null === $path) {
            putenv(KnowledgeLibrary::HOME_ENV);
            unset($_SERVER[KnowledgeLibrary::HOME_ENV], $_ENV[KnowledgeLibrary::HOME_ENV]);

            return;
        }

        putenv(KnowledgeLibrary::HOME_ENV.'='.$path);
        $_SERVER[KnowledgeLibrary::HOME_ENV] = $_ENV[KnowledgeLibrary::HOME_ENV] = $path;
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
