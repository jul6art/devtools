<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * Two entry points derive the same identifier.
 *
 * Deliberately an error rather than a numeric suffix: a suffix would depend on the order entry points
 * are discovered in, and change from one scan to the next.
 */
final class WorkflowIdCollision extends \RuntimeException
{
    public static function between(WorkflowId $id, EntryPoint $first, EntryPoint $second): self
    {
        return new self(\sprintf(
            'The workflow identifier "%s" is claimed by two entry points: %s "%s" (%s) and %s "%s" (%s). Separate them with an <alias entrypoint="…" id="…"/> in .devtools/config.xml.',
            $id,
            $first->kind,
            $first->name,
            $first->declaredIn->path,
            $second->kind,
            $second->name,
            $second->declaredIn->path,
        ));
    }
}
