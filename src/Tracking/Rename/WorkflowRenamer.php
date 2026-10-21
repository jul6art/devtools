<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking\Rename;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\Revision;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * `workflows:rename` (ADR-0036): a workflow's page, tracking file and history carried to a new identifier, and
 * every reference to it with them — the other tracking files, the links of the pages, the index, the map, the
 * scenarios and their baselines, the feedback, the rules, the assertions, the journal, the glossary. No page is
 * rewritten by Claude: the history goes on, with a revision saying so.
 *
 * Everything is computed, then checked against its schema, then written: a refused rename writes nothing.
 * `config.xml` is the human's and is never rewritten — a rename it mentions is said.
 */
final readonly class WorkflowRenamer
{
    /**
     * The parts of `.devtools/` that hold identifiers; work areas (pending, runs, gate, reports, stats) are
     * rewritten by the next run that needs them.
     */
    private const array HOLDERS = ['index.xml', 'graph', 'workflows', 'scenarios', 'feedback', 'rules', 'assertions', 'journal', 'glossary.xml'];

    public function __construct(
        private Clock $clock = new SystemClock(),
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private GitClient $git = new GitClient(),
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function rename(DevToolsDirectory $directory, WorkflowTypeRegistry $types, WorkflowId $old, WorkflowId $new, bool $dryRun = false): RenamePlan
    {
        $indexStore = new XmlIndexStore($types, $this->writer);
        $index = $indexStore->read($directory->indexFile());
        $entries = [];

        foreach ($index->entries as $entry) {
            $entries[$entry->id->value] = $entry;
        }

        $entry = $entries[$old->value] ?? throw new RenameRefused(\sprintf('%s is not a workflow of this project.', $old->value));

        if (explode('.', $old->value)[0] !== explode('.', $new->value)[0]) {
            throw new RenameRefused(\sprintf('%s and %s are not of the same type: a rename keeps the type.', $old->value, $new->value));
        }

        if (isset($entries[$new->value]) || is_file($directory->trackingFile($entry->type, $new))) {
            throw new RenameRefused(\sprintf('%s already exists: renaming onto it would merge two histories.', $new->value));
        }

        $plan = new RenamePlan($old, $new);
        $renamedEntry = new IndexEntry($new, $entry->type, $entry->title, $entry->status, $entry->confidence, $entry->mode, $entry->updated, $entry->group);
        $docs = $directory->root->absolute($directory->docs);
        $oldPage = $docs.'/'.$entry->page();
        $newPage = $docs.'/'.$renamedEntry->page();
        $oldTracking = $directory->trackingFile($entry->type, $old);
        $newTracking = $directory->trackingFile($entry->type, $new);

        // The tracking file: the identifier changed, the history kept, one revision more.
        $trackingStore = new XmlTrackingStore($types, $this->writer);
        $renamed = $trackingStore->unserialize(self::replace(self::read($oldTracking), $old, $new), $newTracking);
        $plan->write($newTracking, $trackingStore->serialize($renamed->revised(new Revision($this->clock->now(), $this->git->state($directory->root)?->commit, 'renamed from '.$old->value))));
        $plan->move($oldTracking, $newTracking);

        // The index, serialized again so that the page follows the identifier.
        $indexXml = self::replace(self::read($directory->indexFile()), $old, $new);
        $plan->write($directory->indexFile(), $indexStore->serialize($indexStore->unserialize($indexXml, $directory->indexFile())));

        // Every other holder: a file under the workflow's own directory (a scenario) is written where it
        // goes, and moved there with the rest of that directory below.
        foreach (self::HOLDERS as $holder) {
            foreach (array_diff(self::files($directory->path($holder)), [$oldTracking, $directory->indexFile()]) as $file) {
                $content = self::read($file);
                $replaced = self::replace($content, $old, $new);

                if ($replaced !== $content) {
                    $plan->write(str_replace('/'.$old->value.'/', '/'.$new->value.'/', $file), $replaced);
                }
            }
        }

        foreach (['scenarios', 'baselines'] as $area) {
            $from = $directory->path($area.'/'.$old->value);

            if (is_dir($from)) {
                foreach (self::files($from) as $file) {
                    $target = $directory->path($area.'/'.$new->value).substr($file, \strlen($from));

                    // A scenario naming the workflow was already rewritten above; a capture is copied as it is.
                    if (!isset($plan->writes[$target])) {
                        $plan->copy($file, $target);
                    }

                    $plan->move($file, $target);
                }

                $plan->removeDirectory($from);
            }
        }

        // The pages: the identifier in their text, and the links to the moved page.
        foreach (array_filter(self::files($docs), static fn (string $file): bool => str_ends_with($file, '.md')) as $file) {
            $content = self::read($file);
            $target = $file === $oldPage ? $newPage : $file;
            $replaced = self::links(self::replace($content, $old, $new), \dirname($file), $oldPage, $newPage);

            if ($replaced !== $content || $target !== $file) {
                $plan->write($target, $replaced);

                if ($target !== $file) {
                    $plan->move($file, $target);
                }
            }
        }

        // Its comments name example identifiers: only what the configuration says counts.
        if (is_file($directory->configFile()) && 1 === preg_match(self::pattern($old), (string) preg_replace('/<!--.*?-->/s', '', self::read($directory->configFile())))) {
            $plan->notes[] = \sprintf('.devtools/config.xml names %s: DevTools never rewrites it — update it yourself.', $old->value);
        }

        if (!$dryRun) {
            $plan->apply($this->writer, $this->filesystem);
        }

        return $plan;
    }

    private static function replace(string $content, WorkflowId $old, WorkflowId $new): string
    {
        return (string) preg_replace(self::pattern($old), $new->value, $content);
    }

    /**
     * The identifier as a whole: `route.order.new`, not inside `route.order.new-draft` nor `xroute.order.new`.
     */
    private static function pattern(WorkflowId $id): string
    {
        return '/(?<![A-Za-z0-9_.\-])'.preg_quote($id->value, '/').'(?![A-Za-z0-9_\-]|\.[A-Za-z0-9])/';
    }

    /**
     * Markdown links resolved from the page's directory: one reaching the old page reaches the new one, its
     * anchor kept. A rename keeps the type and the group, hence the directory: no other link moves.
     */
    private static function links(string $markdown, string $directory, string $oldPage, string $newPage): string
    {
        return (string) preg_replace_callback(
            '/\]\(([^)\s#]+)(#[^)\s]*)?\)/',
            static fn (array $match): string => Path::canonicalize($directory.'/'.$match[1]) === $oldPage ? ']('.Path::makeRelative($newPage, $directory).($match[2] ?? '').')' : $match[0],
            $markdown,
        );
    }

    /**
     * The files under a directory — leaves only, so no directory — or the file itself.
     *
     * @return list<string> absolute paths
     */
    private static function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (!is_dir($path)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private static function read(string $file): string
    {
        return (string) file_get_contents($file);
    }
}
