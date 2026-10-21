<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Impact\Impact;
use Jul6Art\DevTools\Impact\ImpactResolver;
use Jul6Art\DevTools\Impact\ImpactUnavailable;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;

/**
 * `workflows:impact --linked` (ADR-0038): beyond the project, the workflows of its neighbours a change
 * reaches — through `.devtools/graph/links.xml`, then, in a client, the screens that go through the
 * integration calling a route reached: a controller of the API changes, the mobile screens are named.
 *
 * Read from the maps both last inspections wrote, never from the code. A neighbour not on disk is skipped.
 */
final class LinkedImpactResolver
{
    /**
     * @return list<LinkedImpact> sorted by project, relation, identifier
     */
    public function resolve(DevToolsDirectory $directory, Impact $impact): array
    {
        $links = new XmlLinksStore()->read($directory->path(XmlLinksStore::FILE));
        $reached = [];
        $integrations = [];

        foreach ($impact->workflows as $workflow) {
            foreach ($links->of($workflow->id->value) as [$project, $call]) {
                $remote = $call->workflowOn($project->role);

                if (null === $remote) {
                    continue;
                }

                $relation = LinkRole::Client === $project->role ? 'calls' : 'answers';
                $reached[$project->name.' '.$relation.' '.$remote] ??= new LinkedImpact($project->name, $remote, $relation, $workflow->id->value, $call->endpoint, $call->pageOn($project->role));

                if (LinkRole::Client === $project->role) {
                    $integrations[$project->name][$remote] = $project;
                }
            }
        }

        foreach ($integrations as $name => $through) {
            foreach ($this->screens($directory->root, array_first($through), array_keys($through)) as $screen) {
                $reached[$name.' uses '.$screen->id] ??= $screen;
            }
        }

        ksort($reached, \SORT_STRING);

        return array_values($reached);
    }

    /**
     * The workflows of the client that traverse the files of these integrations, the integrations aside.
     *
     * @param list<string> $integrations
     *
     * @return list<LinkedImpact>
     */
    private function screens(ProjectRoot $root, ProjectLinks $project, array $integrations): array
    {
        $path = realpath($root->path.'/'.$project->path);

        if (false === $path || !is_file($path.'/'.DevToolsDirectory::NAME.'/index.xml')) {
            return [];
        }

        $directory = DevToolsDirectory::documented(new ProjectRoot($path));
        $types = new WorkflowTypeRegistry(new XmlConfigReader()->read($directory->configFile())->customTypes);
        $entries = [];

        foreach (new XmlIndexStore($types)->read($directory->indexFile())->entries as $entry) {
            $entries[$entry->id->value] = $entry;
        }

        $tracking = new XmlTrackingStore($types);
        $screens = [];

        foreach ($integrations as $integration) {
            $entry = $entries[$integration] ?? null;

            if (!$entry instanceof IndexEntry || !is_file($directory->trackingFile($entry->type, $entry->id))) {
                continue;
            }

            try {
                $reached = new ImpactResolver($types)->resolve($directory, [$tracking->read($directory->trackingFile($entry->type, $entry->id))->main->declaredIn->path], 0);
            } catch (ImpactUnavailable) {
                continue;
            }

            foreach ($reached->workflows as $workflow) {
                if (!\in_array($workflow->id->value, $integrations, true)) {
                    $screens[] = new LinkedImpact($project->name, $workflow->id->value, 'uses', $integration, page: $workflow->page);
                }
            }
        }

        return $screens;
    }
}
