<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Knowledge;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Version;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * The knowledge shared between every project analysed on this machine (ADR-0041).
 *
 * A stack sheet is generic per stack and major version, so asking Claude for it once per project is
 * asking for what DevTools already knows. What Claude writes for one project is deposited here, and the
 * next project reads it instead of asking again.
 *
 * Nothing already in the library is ever overwritten: a sheet is corrected where it lives, and a
 * correction made in a project only reaches the library through `knowledge:promote`.
 */
final readonly class KnowledgeLibrary
{
    public const string HOME_ENV = 'DEVTOOLS_KNOWLEDGE_HOME';

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/knowledge-library/1';

    public const string INDEX = 'library.xml';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(
        public string $path,
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private SafeXmlLoader $loader = new SafeXmlLoader(),
    ) {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    /**
     * Where the library lives, in the order of ADR-0041. The chosen path is shown by `knowledge:list` and
     * by the inspection report: a library that moves without saying so is worse than no library.
     *
     * @param string|null $configured `<knowledge library="…"/>`, already resolved against the project
     */
    public static function locate(?string $configured = null, ?AtomicFileWriter $writer = null, ?SafeXmlLoader $loader = null): self
    {
        $environment = getenv(self::HOME_ENV);
        $path = match (true) {
            \is_string($environment) && '' !== trim($environment) => trim($environment),
            null !== $configured && '' !== trim($configured) => trim($configured),
            self::embeddedIsWritable() => Resources::path('knowledge'),
            default => self::userHome(),
        };

        return new self(rtrim($path, '/'), $writer ?? new AtomicFileWriter(), $loader ?? new SafeXmlLoader());
    }

    /**
     * True only for a source checkout: a package installed under `vendor/` belongs to Composer, and
     * writing into it loses the deposit at the next `composer install`.
     */
    public static function embeddedIsWritable(): bool
    {
        $knowledge = Resources::path('knowledge');

        return !str_contains($knowledge, '/vendor/') && is_dir($knowledge) && is_writable($knowledge);
    }

    /**
     * The library of one project: `<knowledge library="…"/>` is the project's, so a relative path is
     * relative to the project.
     */
    public static function forProject(ProjectRoot $root, Config $config, ?AtomicFileWriter $writer = null): self
    {
        return self::locate(match (true) {
            null === $config->knowledgeLibrary => null,
            str_starts_with($config->knowledgeLibrary, '/') => $config->knowledgeLibrary,
            default => $root->path.'/'.$config->knowledgeLibrary,
        }, $writer);
    }

    /**
     * `$XDG_DATA_HOME/devtools/knowledge`, or `~/.devtools/knowledge` — the case of an installation under
     * `vendor/`, which nothing ever writes to.
     */
    public static function userHome(): string
    {
        $xdg = getenv('XDG_DATA_HOME');

        if (\is_string($xdg) && str_starts_with($xdg, '/')) {
            return rtrim($xdg, '/').'/devtools/knowledge';
        }

        $home = getenv('HOME');

        return (\is_string($home) && '' !== $home ? rtrim($home, '/') : sys_get_temp_dir()).'/.devtools/knowledge';
    }

    /**
     * True when the library is the `resources/knowledge/` of a source checkout — the sheets deposited
     * there are versioned, so the deposit is announced as something to commit.
     */
    public function isEmbedded(): bool
    {
        return $this->path === rtrim(Resources::path('knowledge'), '/');
    }

    /**
     * @return string|null the absolute path of the sheet, or null when the library does not hold it
     */
    public function file(string $key): ?string
    {
        $file = $this->path.'/'.$key.'.md';

        return is_file($file) ? $file : null;
    }

    public function has(string $key): bool
    {
        return null !== $this->file($key);
    }

    /**
     * Adds a sheet the library does not hold yet, and records where it came from.
     */
    public function deposit(string $key, string $markdown, string $project): DepositOutcome
    {
        if ($this->has($key)) {
            return DepositOutcome::AlreadyPresent;
        }

        if (!$this->isWritable()) {
            return DepositOutcome::NotWritable;
        }

        $this->writer->write($this->path.'/'.$key.'.md', $markdown);
        $entries = $this->entries();
        $entries[$key] = new LibraryEntry($key, $project, new \DateTimeImmutable('@'.$this->now()), 'devtools '.Version::current(), hash('sha256', $markdown));
        $this->writeIndex($entries);

        return DepositOutcome::Deposited;
    }

    /**
     * @return array<string, LibraryEntry> by key, sorted
     */
    public function entries(): array
    {
        $file = $this->path.'/'.self::INDEX;
        $content = is_file($file) ? file_get_contents($file) : false;

        if (false === $content) {
            return [];
        }

        $entries = [];

        foreach ($this->dom->children(DomBuilder::root($this->loader->loadValidated($content, $file, Resources::path('schemas/knowledge-library.xsd'), self::SCHEMA_VERSION)), 'entry') as $entry) {
            $key = $entry->getAttribute('key');
            $entries[$key] = new LibraryEntry($key, $entry->getAttribute('project'), new \DateTimeImmutable($entry->getAttribute('at')), $entry->getAttribute('tool'), $entry->getAttribute('sha256'));
        }

        ksort($entries, \SORT_STRING);

        return $entries;
    }

    /**
     * The sheets on disk, whether or not the index knows about them: a file dropped in by hand counts.
     *
     * @return list<string> keys, sorted
     */
    public function keys(): array
    {
        $keys = array_map(
            static fn (string $file): string => basename($file, '.md'),
            glob($this->path.'/*.md') ?: [],
        );
        sort($keys, \SORT_STRING);

        return array_values(array_filter($keys, static fn (string $key): bool => '_canvas' !== $key));
    }

    public function isWritable(): bool
    {
        return is_dir($this->path) ? is_writable($this->path) : is_writable(\dirname($this->path));
    }

    /**
     * @param array<string, LibraryEntry> $entries
     */
    private function writeIndex(array $entries): void
    {
        ksort($entries, \SORT_STRING);
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'knowledge-library', ['schema-version' => (string) self::SCHEMA_VERSION]);

        foreach ($entries as $entry) {
            $this->dom->element($root, 'entry', [
                'key' => $entry->key,
                'project' => $entry->project,
                'at' => $entry->at->format(\DATE_ATOM),
                'tool' => $entry->tool,
                'sha256' => $entry->sha256,
            ]);
        }

        $this->writer->write($this->path.'/'.self::INDEX, $this->dom->toXml($xml));
    }

    /**
     * The reproducible-builds convention, as {@see \Jul6Art\DevTools\Clock\SystemClock} uses it: two runs
     * of the test suite must be able to produce the same index.
     */
    private function now(): int
    {
        $epoch = getenv('SOURCE_DATE_EPOCH');

        return \is_string($epoch) && 1 === preg_match('/^\d+$/', $epoch) ? (int) $epoch : time();
    }
}
