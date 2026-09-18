<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * The controller a set of routes belongs to, as a level of NAVIGATION — never a workflow (ADR-0045).
 *
 * A group owns a directory, a title and the file that declares it. It has no workflow identifier, does
 * not count among the workflows, and carries no files of its own: everything it shows belongs to the
 * routes it lists.
 *
 * `<routes group="controller"/>` used to fold a controller's routes into one workflow. A controller with
 * one route read well that way; a controller with thirteen produced a page whose single sequence diagram
 * had to tell thirteen different gestures. The grouping moved to the menu, and the page followed.
 */
final readonly class WorkflowGroup
{
    /**
     * The directory the pages of this group are written in, relative to their type: `admin.user`.
     */
    public string $directory;

    public string $title;

    public function __construct(string $directory, string $title, public FileRef $declaredIn, public ?StateMachine $states = null)
    {
        $this->directory = NonEmpty::string($directory, 'directory of a group');
        $this->title = NonEmpty::string($title, \sprintf('title of the group "%s"', $directory));

        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)*$/', $this->directory)) {
            throw new InvalidModel(\sprintf('"%s" is not a group directory: lowercase letters, digits, inner hyphens, dots between segments.', $directory));
        }
    }

    /**
     * The file a workflow of this group is written to, without its extension: what its identifier says
     * beyond the group.
     *
     * `route.admin.user.edit` in `admin.user` is `edit`. A controller with a single route — whose
     * identifier IS the group — is `index`, and never collides with `README.md`, which is the group's own
     * page.
     */
    public function leafOf(WorkflowId $id): string
    {
        return self::leaf($this->directory, $id);
    }

    /**
     * The same derivation, from the directory alone: the index reads it back from `index.xml`, where a
     * group is a name and nothing more.
     */
    public static function leaf(string $directory, WorkflowId $id): string
    {
        $page = $id->pageName();

        if ($page === $directory) {
            return 'index';
        }

        return str_starts_with($page, $directory.'.')
            ? substr($page, \strlen($directory) + 1)
            : $page;
    }

    public function holds(WorkflowId $id): bool
    {
        $page = $id->pageName();

        return $page === $this->directory || str_starts_with($page, $this->directory.'.');
    }
}
