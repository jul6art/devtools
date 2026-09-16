<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * The workflow types in force for a project: the seven native ones, then the project's own.
 */
final readonly class WorkflowTypeRegistry
{
    /**
     * @var array<string, WorkflowType>
     */
    private array $types;

    /**
     * @param list<WorkflowType> $customTypes
     */
    public function __construct(array $customTypes = [])
    {
        $types = [];

        foreach (array_keys(WorkflowType::NATIVE) as $name) {
            $types[$name] = WorkflowType::native($name);
        }

        foreach ($customTypes as $type) {
            if (isset($types[$type->name])) {
                throw new InvalidModel(\sprintf('The workflow type "%s" is declared twice.', $type->name));
            }

            $types[$type->name] = WorkflowType::custom($type->name, $type->idPrefix);
        }

        $this->types = $types;
    }

    public function get(string $name): WorkflowType
    {
        return $this->types[$name] ?? throw new InvalidModel(\sprintf('The workflow type "%s" is unknown; declare it in .devtools/config.xml.', $name));
    }

    /**
     * @return list<WorkflowType>
     */
    public function all(): array
    {
        return array_values($this->types);
    }
}
