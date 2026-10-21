<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;

/**
 * What one project offers to a link (ADR-0038), read from its last inspection — never from its code: the
 * routes it answers (method, path) and the calls its integrations make (`endpoints`, ADR-0032).
 */
final readonly class LinkSide
{
    /**
     * @param list<array{id: string, methods: list<string>, path: string, page: string}> $routes
     * @param list<array{id: string, endpoints: list<string>, page: string}>             $clients
     */
    public function __construct(public array $routes = [], public array $clients = [])
    {
    }

    public static function read(DevToolsDirectory $directory, WorkflowTypeRegistry $types): self
    {
        $index = new XmlIndexStore($types)->read($directory->indexFile());
        $tracking = new XmlTrackingStore($types);
        $routes = [];
        $clients = [];

        foreach ($index->entries as $entry) {
            if (!\in_array($entry->type->name, ['routes', 'integrations'], true) || !is_file($directory->trackingFile($entry->type, $entry->id))) {
                continue;
            }

            $attributes = $tracking->read($directory->trackingFile($entry->type, $entry->id))->main->attributes;
            $page = $index->docs.'/'.$entry->page();

            if ('routes' === $entry->type->name && isset($attributes['path'])) {
                $methods = array_values(array_filter(preg_split('/[|,\s]+/', strtoupper($attributes['methods'] ?? '')) ?: [], static fn (string $method): bool => '' !== $method && 'ANY' !== $method));
                $routes[] = ['id' => $entry->id->value, 'methods' => $methods, 'path' => $attributes['path'], 'page' => $page];
            }

            if ('integrations' === $entry->type->name && isset($attributes['endpoints'])) {
                $clients[] = ['id' => $entry->id->value, 'endpoints' => array_values(array_filter(array_map(trim(...), explode(', ', $attributes['endpoints'])))), 'page' => $page];
            }
        }

        return new self($routes, $clients);
    }

    /**
     * @return list<string>
     */
    public function identifiers(): array
    {
        return [...array_column($this->routes, 'id'), ...array_column($this->clients, 'id')];
    }
}
