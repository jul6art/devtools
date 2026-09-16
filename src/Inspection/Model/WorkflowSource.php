<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * Who produced a workflow: `native:<adapter>` or `claude`.
 */
final readonly class WorkflowSource implements \Stringable
{
    public function __construct(public string $value)
    {
        if (1 !== preg_match('/^(native:[a-z][a-z0-9-]*|claude)$/', $value)) {
            throw new InvalidModel(\sprintf('The workflow source "%s" must be "claude" or "native:<adapter>".', $value));
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function isNative(): bool
    {
        return str_starts_with($this->value, 'native:');
    }
}
