<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * What triggers a workflow: a route, a command, a message handler, a listener or a component.
 *
 * `attributes` carries what depends on the kind — `path`, `methods`, `security` for a route,
 * `message` for a handler — as strings, so the model stays the same whatever the stack.
 */
final readonly class EntryPoint
{
    public string $kind;

    public string $name;

    /**
     * @var array<string, string>
     */
    public array $attributes;

    /**
     * @param array<string, string> $attributes
     */
    public function __construct(string $kind, string $name, public FileRef $declaredIn, array $attributes = [])
    {
        $this->kind = NonEmpty::slug($kind, 'entry point kind');
        $this->name = NonEmpty::string($name, 'entry point name');

        foreach (array_keys($attributes) as $attribute) {
            NonEmpty::slug($attribute, \sprintf('attribute name of entry point "%s"', $this->name));
        }

        ksort($attributes, \SORT_STRING);
        $this->attributes = $attributes;
    }

    /**
     * Two entry points are the same when they have the same kind, name and declaring file; their
     * attributes may differ between two scans (a route gaining a method) without changing identity.
     */
    public function sameAs(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->name === $other->name
            && $this->declaredIn->samePathAs($other->declaredIn);
    }
}
