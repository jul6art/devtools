<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * Hands out identifiers for one scan, and refuses to hand the same one to two entry points.
 */
final class WorkflowIdAssigner
{
    /**
     * @var array<string, EntryPoint>
     */
    private array $assigned = [];

    /**
     * @param array<string, string> $aliases entry point name => identifier, from .devtools/config.xml
     */
    public function __construct(
        private readonly WorkflowIdDeriver $deriver,
        private readonly array $aliases = [],
    ) {
    }

    public function assign(WorkflowType $type, EntryPoint $entryPoint): WorkflowId
    {
        $id = isset($this->aliases[$entryPoint->name])
            ? $this->alias($type, $entryPoint)
            : $this->deriver->derive($type, $entryPoint);

        $holder = $this->assigned[$id->value] ?? null;

        if (null !== $holder && !$holder->sameAs($entryPoint)) {
            throw WorkflowIdCollision::between($id, $holder, $entryPoint);
        }

        $this->assigned[$id->value] = $entryPoint;

        return $id;
    }

    private function alias(WorkflowType $type, EntryPoint $entryPoint): WorkflowId
    {
        $id = new WorkflowId($this->aliases[$entryPoint->name]);

        if ($id->prefix() !== $type->idPrefix) {
            throw new InvalidModel(\sprintf('The alias "%s" of the entry point "%s" must start with "%s", the prefix of the type "%s".', $id, $entryPoint->name, $type->idPrefix, $type->name));
        }

        return $id;
    }
}
