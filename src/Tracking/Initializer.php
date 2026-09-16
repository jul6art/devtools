<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Resources;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Creates `.devtools/` in a project, and keeps doing nothing once it exists (ADR-0004).
 */
final readonly class Initializer
{
    public function __construct(
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return list<string> what was created or updated, relative to the project root
     */
    public function initialize(DevToolsDirectory $directory): array
    {
        $changed = [];

        foreach (['', ...DevToolsDirectory::FOLDERS] as $folder) {
            if (!is_dir($directory->path($folder))) {
                $this->filesystem->mkdir($directory->path($folder));
                $changed[] = rtrim(DevToolsDirectory::NAME.'/'.$folder, '/').'/';
            }
        }

        // The project's configuration is never overwritten, even when it differs from the template.
        if (!is_file($directory->configFile())) {
            $this->writer->write($directory->configFile(), self::read(Resources::path('templates/config.xml')));
            $changed[] = DevToolsDirectory::NAME.'/config.xml';
        }

        // A copy of each schema, so that anyone can validate .devtools/ without DevTools installed.
        foreach (glob(Resources::path('schemas/*.xsd')) ?: [] as $schema) {
            if ($this->writer->write($directory->path('schemas/'.basename($schema)), self::read($schema))) {
                $changed[] = DevToolsDirectory::NAME.'/schemas/'.basename($schema);
            }
        }

        if ($this->ignoreWorkAreas($directory)) {
            $changed[] = '.gitignore';
        }

        return $changed;
    }

    private function ignoreWorkAreas(DevToolsDirectory $directory): bool
    {
        $path = $directory->root->absolute('.gitignore');
        $current = is_file($path) ? (string) file_get_contents($path) : '';
        $present = array_map(static fn (string $line): string => trim(trim($line), '/'), explode("\n", $current));

        $missing = array_values(array_filter(
            array_map(static fn (string $area): string => DevToolsDirectory::NAME.'/'.$area, DevToolsDirectory::IGNORED),
            static fn (string $entry): bool => !\in_array($entry, $present, true),
        ));

        if ([] === $missing) {
            return false;
        }

        $block = "\n# DevTools work areas — https://github.com/jul6art/devtools\n".implode('', array_map(static fn (string $entry): string => '/'.$entry."/\n", $missing));
        $separator = '' === $current || str_ends_with($current, "\n") ? '' : "\n";

        return $this->writer->write($path, ltrim($current.$separator.$block, "\n"));
    }

    private static function read(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
