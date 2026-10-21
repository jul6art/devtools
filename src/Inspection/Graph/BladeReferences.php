<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

final readonly class BladeReferences
{
    /**
     * @param list<string> $views   paths under the views directory, parents included
     * @param list<string> $classes the component classes Laravel resolves `<x-…>` and `<livewire:…>` to
     * @param list<string> $routes  literal route names given to route()
     * @param list<string> $parents the views among $views reached by `@extends`: a layout's links belong to
     *                              every page, so navigation does not follow them
     */
    public function __construct(public array $views = [], public array $classes = [], public array $routes = [], public array $parents = [])
    {
    }
}
