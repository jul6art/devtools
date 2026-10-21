<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rendering\MenuRefresher;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\Index;
use Jul6Art\DevTools\Tracking\TrackingStatus;
use Jul6Art\DevTools\Tracking\XmlIndexStore;

/**
 * `workflows:inspect --link` (ADR-0038), once every project named was inspected: the calls between this
 * project and each neighbour its `config.xml` declares, read from both last inspections — in memory, no
 * inspection more — then `.devtools/graph/links.xml`, the "Related workflows" of the pages whose links
 * changed, and the menu, which lists the orphan calls.
 *
 * Only this project is written: a neighbour gets its own links when it is linked in turn — which is why
 * `--link` links every project it inspected. A neighbour never inspected keeps the links it had.
 */
final readonly class ProjectLinker
{
    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private LinkCalculator $calculator = new LinkCalculator())
    {
    }

    public function link(ProjectRoot $root): LinkReport
    {
        $directory = DevToolsDirectory::documented($root);
        $report = new LinkReport(basename($root->path));
        $config = new XmlConfigReader()->read($directory->configFile());

        if ([] === $config->links) {
            $report->warnings[] = \sprintf('%s declares no linked project: add <links> to its .devtools/config.xml.', $report->project);

            return $report;
        }

        $types = new WorkflowTypeRegistry($config->customTypes);
        $store = new XmlLinksStore($this->writer);
        $before = $store->read($directory->path(XmlLinksStore::FILE));
        $local = LinkSide::read($directory, $types);
        $projects = [];

        foreach ($config->links as $neighbour) {
            $path = realpath($root->path.'/'.$neighbour->path);

            if (false === $path || !is_file($path.'/'.DevToolsDirectory::NAME.'/index.xml')) {
                $report->warnings[] = \sprintf('%s (%s) was never inspected: inspect both projects together with --link. Its links are kept as they were.', $neighbour->name, $neighbour->path);
                $kept = $before->project($neighbour->name);

                if ($kept instanceof ProjectLinks) {
                    $projects[] = new ProjectLinks($neighbour->name, $neighbour->path, $neighbour->role, $kept->calls);
                }

                continue;
            }

            $remoteDirectory = DevToolsDirectory::documented(new ProjectRoot($path));
            $remoteConfig = new XmlConfigReader()->read($remoteDirectory->configFile());
            $remote = LinkSide::read($remoteDirectory, new WorkflowTypeRegistry($remoteConfig->customTypes));
            $baseUrl = $neighbour->baseUrl ?? self::declaredBack($remoteConfig, $path, $root)?->baseUrl;
            $calls = LinkRole::Client === $neighbour->role ? $this->calculator->calls($remote, $local, $baseUrl) : $this->calculator->calls($local, $remote, $baseUrl);
            $linked = new ProjectLinks($neighbour->name, $neighbour->path, $neighbour->role, $calls);
            $projects[] = $linked;
            $report->projects[$neighbour->name] = ['calls' => \count($calls) - \count($linked->orphans()), 'orphans' => \count($linked->orphans())];
        }

        usort($projects, static fn (ProjectLinks $a, ProjectLinks $b): int => $a->name <=> $b->name);
        $links = new Links($projects);
        $store->write($directory->path(XmlLinksStore::FILE), $links);

        $index = new XmlIndexStore($types)->read($directory->indexFile());
        $report->pagesWritten = $this->rewritePages($directory, $index, $before, $links);
        new MenuRefresher($this->writer)->refresh($directory, $index, $config);

        return $report;
    }

    /**
     * The "Related workflows" of every page whose links changed, rewritten in place: the rest of the page is
     * left as it is, and no revision is added — the workflow did not change, its neighbour did. A page its
     * owner took over (`manual`) is left alone, as everywhere else.
     */
    private function rewritePages(DevToolsDirectory $directory, Index $index, Links $before, Links $after): int
    {
        $written = 0;

        foreach ($index->entries as $entry) {
            $pageDirectory = $index->docs.'/'.\dirname($entry->page());
            $linked = RelatedLinks::lines($after, $entry->id->value, $pageDirectory);
            $page = $directory->root->absolute($index->docs.'/'.$entry->page());

            if (TrackingStatus::Manual === $entry->status || !is_file($page) || $linked === RelatedLinks::lines($before, $entry->id->value, $pageDirectory)) {
                continue;
            }

            if ($this->writer->write($page, RelatedLinks::rewrite((string) file_get_contents($page), $linked))) {
                ++$written;
            }
        }

        return $written;
    }

    /**
     * The neighbour's own declaration of this project, which may carry the base URL of the client's calls.
     */
    private static function declaredBack(Config $config, string $neighbour, ProjectRoot $root): ?LinkedProject
    {
        foreach ($config->links as $project) {
            if (realpath($neighbour.'/'.$project->path) === realpath($root->path)) {
                return $project;
            }
        }

        return null;
    }
}
