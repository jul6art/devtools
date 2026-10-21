<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering\Site;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * The documentation as a site that works offline (specs § 4.8, ADR-0034): every page and the menu as HTML,
 * the machinery they link to — stack, knowledge, overview — beside them, the Mermaid diagrams drawn by the
 * script copied into the site, and a search over the pages that needs no server.
 *
 * The output directory is DevTools': one it did not write is never emptied.
 */
final readonly class SiteExporter
{
    /**
     * Written in every site, so that the next export knows the directory is its own to replace.
     */
    public const string MARKER = '.devtools-site';

    private const string MACHINERY = '_devtools';

    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    /**
     * @return array{pages: int, bytes: int}
     */
    public function export(DevToolsDirectory $directory, string $output, string $projectName): array
    {
        $docs = $directory->root->absolute($directory->docs);

        if (!is_file($directory->menuFile())) {
            throw new \RuntimeException(\sprintf('%s does not exist: run workflows:inspect first.', Path::makeRelative($directory->menuFile(), $directory->root->path)));
        }

        $this->prepare($output);
        $pages = [];

        foreach (self::files($docs, 'md') as $relative) {
            $pages[$relative] = (string) file_get_contents($docs.'/'.$relative);
        }

        $machinery = [];
        $index = [];
        $bytes = 0;

        foreach ($pages as $relative => $markdown) {
            $site = self::html($relative);
            $html = $this->page($markdown, $site, $projectName, $this->linker($directory, \dirname($docs.'/'.$relative), $site, $machinery));
            $bytes += $this->write($output.'/'.$site, $html);
            $index[] = ['title' => MarkdownRenderer::title($markdown) ?? $relative, 'url' => $site, 'text' => self::excerpt($markdown)];
        }

        $bytes += $this->write($output.'/index.html', $this->page($pages['workflows.md'] ?? '', 'index.html', $projectName, $this->linker($directory, $docs, 'index.html', $machinery)));

        // What the pages link to in .devtools/, read in turn: a knowledge sheet links to nothing else.
        foreach ($machinery as $relative => $site) {
            $bytes += $this->write($output.'/'.$site, $this->machinery($directory, $relative, $site, $projectName));
        }

        $bytes += $this->write($output.'/assets/search-index.js', 'window.DEVTOOLS_SEARCH = '.json_encode($index, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR).";\n");

        foreach (['site.css', 'search.js', 'mermaid/mermaid.min.js', 'mermaid/LICENSE'] as $asset) {
            $content = is_file(Resources::path('site/'.$asset)) ? file_get_contents(Resources::path('site/'.$asset)) : false;
            $bytes += $this->write($output.'/assets/'.$asset, \is_string($content) ? $content : throw new \RuntimeException(\sprintf('resources/site/%s is missing from this installation of DevTools.', $asset)));
        }

        $this->write($output.'/'.self::MARKER, "Written by devtools export:site; replaced by the next export.\n");

        return ['pages' => \count($pages) + 1 + \count($machinery), 'bytes' => $bytes];
    }

    /**
     * What a link of a page becomes in the site: another page, the machinery of `.devtools/` copied beside —
     * or its text alone, for a target the site does not hold.
     *
     * @param array<string, string> $machinery `.devtools/` path => site path, filled as links are met
     *
     * @return \Closure(string): ?string
     */
    private function linker(DevToolsDirectory $directory, string $from, string $site, array &$machinery): \Closure
    {
        $docs = $directory->root->absolute($directory->docs);
        $dotDevtools = $directory->path();

        return static function (string $target) use ($from, $site, $docs, $dotDevtools, &$machinery): ?string {
            if (1 === preg_match('#^(https?:|mailto:|\#)#', $target)) {
                return $target;
            }

            [$path, $anchor] = str_contains($target, '#') ? explode('#', $target, 2) : [$target, null];
            $absolute = Path::canonicalize($from.'/'.$path);
            $suffix = null === $anchor ? '' : '#'.$anchor;

            if (Path::isBasePath($docs, $absolute) && str_ends_with($absolute, '.md')) {
                return self::relative($site, self::html(Path::makeRelative($absolute, $docs))).$suffix;
            }

            if (Path::isBasePath($dotDevtools, $absolute) && is_file($absolute)) {
                $relative = Path::makeRelative($absolute, $dotDevtools);
                $machinery[$relative] = self::MACHINERY.'/'.self::html($relative);

                return self::relative($site, $machinery[$relative]).$suffix;
            }

            return null;
        };
    }

    private function page(string $markdown, string $site, string $projectName, \Closure $link): string
    {
        $assets = self::relative($site, 'assets');
        $home = self::relative($site, 'index.html');
        $title = MarkdownRenderer::title($markdown) ?? $projectName;
        // The menu and the glossary already name the project in their heading: once is enough.
        $title = str_ends_with($title, $projectName) ? $title : $title.' — '.$projectName;
        $body = new MarkdownRenderer($link)->render($markdown);
        $root = self::relative($site, '');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$this->escape($title)}</title>
            <link rel="stylesheet" href="{$assets}/site.css">
            </head>
            <body>
            <header><a href="{$home}">{$this->escape($projectName)}</a><input type="search" id="search" placeholder="Search the workflows" aria-label="Search the workflows"><ul id="results" hidden></ul></header>
            <main>
            {$body}
            </main>
            <footer>Generated by <a href="https://github.com/jul6art/devtools">DevTools</a> from <code>.devtools/</code>.</footer>
            <script src="{$assets}/mermaid/mermaid.min.js"></script>
            <script src="{$assets}/search-index.js"></script>
            <script src="{$assets}/search.js" data-root="{$root}"></script>
            </body>
            </html>

            HTML;
    }

    private function machinery(DevToolsDirectory $directory, string $relative, string $site, string $projectName): string
    {
        $content = (string) file_get_contents($directory->path($relative));
        $markdown = match (true) {
            str_ends_with($relative, '.md') => $content,
            str_ends_with($relative, '.mermaid') => "# Overview\n\n```mermaid\n".trim($content)."\n```\n",
            default => '# '.basename($relative)."\n\n```".pathinfo($relative, \PATHINFO_EXTENSION)."\n".rtrim($content)."\n```\n",
        };

        return $this->page($markdown, $site, $projectName, static fn (string $target): ?string => 1 === preg_match('#^https?:#', $target) ? $target : null);
    }

    /**
     * Refuses a directory DevTools did not write, then empties the one it did.
     */
    private function prepare(string $output): void
    {
        if (is_dir($output) && !is_file($output.'/'.self::MARKER) && [] !== array_diff(scandir($output) ?: [], ['.', '..'])) {
            throw new \RuntimeException(\sprintf('%s is not empty and DevTools did not write it: choose another --output, or empty it yourself.', $output));
        }

        if (is_dir($output)) {
            $this->filesystem->remove($output);
        }

        $this->filesystem->mkdir($output);
    }

    private function write(string $path, string $content): int
    {
        $this->filesystem->dumpFile($path, $content);

        return \strlen($content);
    }

    /**
     * @return list<string> paths relative to $directory, sorted
     */
    private static function files(string $directory, string $extension): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $extension === $file->getExtension()) {
                $files[] = Path::makeRelative($file->getPathname(), $directory);
            }
        }

        sort($files, \SORT_STRING);

        return $files;
    }

    /**
     * `routes/order.new.md` → `routes/order.new.html`; `workflows.mermaid` → `workflows.mermaid.html`.
     */
    private static function html(string $relative): string
    {
        return str_ends_with($relative, '.md') ? substr($relative, 0, -3).'.html' : $relative.'.html';
    }

    /**
     * The link from a page of the site to another of its files.
     */
    private static function relative(string $fromPage, string $to): string
    {
        $directory = \dirname($fromPage);
        $relative = Path::makeRelative('/'.$to, '/'.('.' === $directory ? '' : $directory));

        return '' === $relative ? '.' : $relative;
    }

    /**
     * The summary of a page — what the search shows under its title.
     */
    private static function excerpt(string $markdown): string
    {
        $summary = 1 === preg_match('/^## (?:Summary|Résumé)\s*\n+(.+?)(?:\n## |\z)/ms', $markdown, $section) ? $section[1] : $markdown;
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('/[`*#|>\[\]]/', '', $summary))));

        return mb_substr($text, 0, 240);
    }

    private function escape(string $text): string
    {
        return MarkdownRenderer::escape($text);
    }
}
