<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\XmlIndexStore;

/**
 * What `.devtools/graph/links.xml` says that no longer holds (ADR-0038), causes of `workflows:check`: an
 * orphan call, a linked workflow of this project that is gone, one of the neighbour's that is gone. A
 * neighbour that is not on disk — a CI job that checked out one repository — is not checked against.
 */
final class LinkCheck
{
    /**
     * @param list<string> $workflows the workflows of this project, as the inspection finds them now
     *
     * @return list<string>
     */
    public function problems(DevToolsDirectory $directory, Links $links, array $workflows): array
    {
        $problems = [];

        foreach ($links->projects as $project) {
            $local = $project->localSide();
            $remote = self::workflowsOf($directory->root, $project);

            foreach ($project->calls as $call) {
                if ($call->isOrphan()) {
                    $problems[] = LinkRole::Server === $project->role
                        ? \sprintf('%s calls %s: no route of %s answers', $call->client, $call->endpoint, $project->name)
                        : \sprintf('%s of %s calls %s: no route answers', $call->client, $project->name, $call->endpoint);
                }

                $mine = $call->workflowOn($local);
                $theirs = $call->workflowOn($project->role);

                if (null !== $mine && !\in_array($mine, $workflows, true)) {
                    $problems[] = \sprintf('%s is linked to %s and is gone: workflows:inspect --link', $mine, $project->name);
                }

                if (null !== $theirs && null !== $remote && !\in_array($theirs, $remote, true)) {
                    $problems[] = \sprintf('%s of %s is gone: workflows:inspect --link', $theirs, $project->name);
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @return list<string>|null null when the neighbour was never inspected where it is declared
     */
    private static function workflowsOf(ProjectRoot $root, ProjectLinks $project): ?array
    {
        $path = realpath($root->path.'/'.$project->path);

        if (false === $path || !is_file($path.'/'.DevToolsDirectory::NAME.'/index.xml')) {
            return null;
        }

        $directory = DevToolsDirectory::documented(new ProjectRoot($path));
        $types = new WorkflowTypeRegistry(new XmlConfigReader()->read($directory->configFile())->customTypes);

        return array_map(static fn (IndexEntry $entry): string => $entry->id->value, new XmlIndexStore($types)->read($directory->indexFile())->entries);
    }
}
