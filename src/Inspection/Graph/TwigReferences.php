<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

final readonly class TwigReferences
{
    /**
     * @param list<string> $templates  literal template names, parents included
     * @param list<string> $components literal component names
     * @param list<string> $routes     literal route names given to path() and url()
     * @param list<string> $parents    the templates among $templates reached by `extends`: a layout's links
     *                                 belong to every page, so navigation does not follow them
     */
    public function __construct(public array $templates = [], public array $components = [], public array $routes = [], public array $parents = [])
    {
    }
}
