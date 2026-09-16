<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\GenericPhp;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterInterface;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Graph\EntryPointCandidate;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\SourceFiles;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * PHP without a framework (ADR-0014): the files of the web directory are the routes, the console commands or
 * the scripts are the commands, `migrations/` is the data. Everything else is the PHP graph's job.
 */
final readonly class GenericPhpAdapter implements AdapterInterface
{
    private const array WEB_ROOTS = ['public', 'web', 'www'];

    /**
     * `href="orders/new.php"`, `action="/save.php?id=1"`, `header('Location: /index.php')`.
     */
    private const string LINKS = '/\b(?:(href|action)\s*=\s*["\']|header\(\s*["\']Location:\s*)([^"\'?#\s]+\.php)/i';

    #[\Override]
    public function name(): string
    {
        return 'generic-php';
    }

    #[\Override]
    public function extract(ProjectRoot $root, StackProfile $stack, Config $config, PhpReferenceExtractor $extractor): AdapterResult
    {
        $source = new WorkflowSource('native:generic-php');
        $composer = $this->composer($root, $stack);
        $candidates = [...$this->routes($root, $stack, $config, $source)];
        $scanner = new ConsoleCommandScanner($root, $stack, $extractor);

        if (\is_array($composer['require'] ?? null) && isset($composer['require']['symfony/console'])) {
            foreach ($scanner->commands() as $command) {
                $candidates[] = new EntryPointCandidate(WorkflowType::commands(), new EntryPoint('command', $command['name'], $command['file']), $command['name'], confidence: Confidence::High, source: $source);
            }
        } else {
            $candidates = [...$candidates, ...$this->scripts($root, $stack, $composer, $source)];
        }

        $migrations = array_map(static fn (string $path): FileRef => new FileRef($path, FileRole::Other), SourceFiles::in($root, $stack, ['migrations'], ['php', 'sql']));

        if ([] !== $migrations) {
            $candidates[] = new EntryPointCandidate(WorkflowType::data(), new EntryPoint('migration', 'migrations', $migrations[0]), 'Migrations', structuralFiles: $migrations, confidence: Confidence::High, source: $source);
        }

        return new AdapterResult($candidates, warnings: $scanner->warnings());
    }

    /**
     * @return list<EntryPointCandidate>
     */
    private function routes(ProjectRoot $root, StackProfile $stack, Config $config, WorkflowSource $source): array
    {
        $pages = [];

        foreach (null === $config->phpWebRoot ? self::WEB_ROOTS : [$config->phpWebRoot] as $webRoot) {
            foreach (SourceFiles::in($root, $stack, [$webRoot], ['php']) as $path) {
                $pages[$path] = ['webRoot' => $stack->projectPath($webRoot), 'url' => substr($path, \strlen($stack->projectPath($webRoot)))];
            }
        }

        $candidates = [];

        foreach ($pages as $path => ['webRoot' => $webRoot, 'url' => $url]) {
            $htaccess = $root->absolute($webRoot).'/.htaccess';

            $candidates[] = new EntryPointCandidate(
                type: WorkflowType::routes(),
                entryPoint: new EntryPoint('route', self::routeName($url), new FileRef($path, FileRole::Controller), ['path' => $url]),
                title: $url,
                structuralFiles: is_file($htaccess) ? [new FileRef($webRoot.'/.htaccess', FileRole::Config)] : [],
                navigation: $this->navigation($root, $path, $webRoot, $pages),
                confidence: Confidence::High,
                source: $source,
            );
        }

        return $candidates;
    }

    /**
     * `/orders/new.php` → `orders.new`, which derives `route.orders.new`.
     */
    private static function routeName(string $url): string
    {
        return str_replace('/', '.', substr(ltrim($url, '/'), 0, -4));
    }

    /**
     * @param array<string, array{webRoot: string, url: string}> $pages
     *
     * @return list<Edge>
     */
    private function navigation(ProjectRoot $root, string $page, string $webRoot, array $pages): array
    {
        preg_match_all(self::LINKS, (string) file_get_contents($root->absolute($page)), $links, \PREG_SET_ORDER);
        $edges = [];

        foreach ($links as [, $attribute, $link]) {
            $target = str_starts_with($link, '/') ? $webRoot.$link : \dirname($page).'/'.$link;

            try {
                $target = $root->relative($root->absolute($target));
            } catch (PathOutsideProject) {
                continue;
            }

            if ($target !== $page && isset($pages[$target])) {
                $label = match (strtolower($attribute)) {
                    'href' => 'link',
                    'action' => 'form',
                    default => 'redirect',
                };
                $edges[$target.'|'.$label] = new Edge(self::routeName($pages[$target]['url']), $label);
            }
        }

        ksort($edges, \SORT_STRING);

        return array_values($edges);
    }

    /**
     * Commands of a PHP project without a console library: the `bin` entries of composer.json, the scripts
     * running a PHP file of the project, the PHP files of `bin/`. One workflow per file, named after the
     * script that runs it when there is one — that is the name the team types.
     *
     * @param array<mixed> $composer
     *
     * @return list<EntryPointCandidate>
     */
    private function scripts(ProjectRoot $root, StackProfile $stack, array $composer, WorkflowSource $source): array
    {
        $composerFile = [new FileRef($stack->projectPath('composer.json'), FileRole::Config)];
        $commands = [];

        foreach (\is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [] as $name => $lines) {
            foreach ((array) $lines as $line) {
                if (\is_string($name) && \is_string($line) && 1 === preg_match('#^(?:@php|php)\s+(?:-d\s*\S+\s+)*([^\s-]\S*)#', $line, $matches)) {
                    $commands[$this->commandPath($root, $stack, $matches[1])] ??= [$name, $composerFile];
                }
            }
        }

        foreach (\is_array($composer['bin'] ?? null) ? $composer['bin'] : [] as $bin) {
            if (\is_string($bin)) {
                $commands[$this->commandPath($root, $stack, $bin)] ??= [self::scriptName($bin), $composerFile];
            }
        }

        foreach (SourceFiles::in($root, $stack, ['bin'], []) as $path) {
            if (PhpReferenceExtractor::isPhpFile($root->absolute($path))) {
                $commands[$path] ??= [self::scriptName($path), []];
            }
        }

        unset($commands['']);
        ksort($commands, \SORT_STRING);
        $candidates = [];

        foreach ($commands as $path => [$name, $structural]) {
            $candidates[] = new EntryPointCandidate(WorkflowType::commands(), new EntryPoint('command', $name, new FileRef($path, FileRole::Other)), $name, structuralFiles: $structural, confidence: Confidence::High, source: $source);
        }

        return $candidates;
    }

    /**
     * The project path of a PHP file a command runs, or '' when it is not one.
     */
    private function commandPath(ProjectRoot $root, StackProfile $stack, string $relative): string
    {
        try {
            $path = $root->relative($root->absolute($stack->projectPath((string) preg_replace('#^\./#', '', $relative))));
        } catch (PathOutsideProject) {
            return '';
        }

        return PhpReferenceExtractor::isPhpFile($root->absolute($path)) ? $path : '';
    }

    private static function scriptName(string $path): string
    {
        return basename($path, '.php');
    }

    /**
     * @return array<mixed>
     */
    private function composer(ProjectRoot $root, StackProfile $stack): array
    {
        $file = $root->absolute($stack->projectPath('composer.json'));
        $composer = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return \is_array($composer) ? $composer : [];
    }
}
