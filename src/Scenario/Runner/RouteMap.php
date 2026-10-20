<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Runner;

use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;

/**
 * The routes a scenario names, and their paths, as the last inspection recorded them (ADR-0021): a scenario
 * says `app_order_show`, the runner goes to `/orders/1` — the same in the URL mode and in PHPUnit, without the
 * application's router.
 */
final readonly class RouteMap
{
    /**
     * `{id}`, `{id<\d+>}`, `{page?1}`, `{!slug}`.
     */
    private const string PLACEHOLDER = '/\{!?(\w+)(?:<([^>]*)>)?(\?[^}]*)?\}/';

    /**
     * @param array<string, string> $paths route name => path
     */
    public function __construct(private array $paths)
    {
    }

    public static function fromTracking(DevToolsDirectory $directory, WorkflowTypeRegistry $types): self
    {
        $store = new XmlTrackingStore($types);
        $paths = [];

        foreach (glob($directory->path('workflows/routes/*.xml')) ?: [] as $file) {
            $document = $store->read($file);

            foreach ([$document->main, ...$document->satellites] as $point) {
                if ($point instanceof EntryPoint && isset($point->attributes['path'])) {
                    $paths[$point->name] = $point->attributes['path'];
                }
            }
        }

        return new self($paths);
    }

    /**
     * The path of a route, its parameters in place and the others as the query string — as Symfony does.
     *
     * @param array<string, string> $parameters
     *
     * @throws \InvalidArgumentException when the route is unknown or a parameter is missing
     */
    public function url(string $route, array $parameters = []): string
    {
        $path = $this->path($route);
        $used = [];
        $url = (string) preg_replace_callback(self::PLACEHOLDER, static function (array $placeholder) use ($parameters, $route, &$used): string {
            $name = $placeholder[1];
            $default = isset($placeholder[3]) ? substr($placeholder[3], 1) : null;
            $used[] = $name;

            return rawurlencode($parameters[$name] ?? $default ?? throw new \InvalidArgumentException(\sprintf('The route "%s" needs the parameter "%s": <param name="%2$s" value="…"/>.', $route, $name)));
        }, $path);
        $query = array_diff_key($parameters, array_flip($used));

        return [] === $query ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * Whether a URL is a page of this route.
     */
    public function matches(string $route, string $url): bool
    {
        $pattern = '';
        $offset = 0;
        $path = $this->path($route);
        preg_match_all(self::PLACEHOLDER, $path, $placeholders, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);

        foreach ($placeholders as $placeholder) {
            $pattern .= preg_quote(substr($path, $offset, $placeholder[0][1] - $offset), '#');
            $requirement = isset($placeholder[2]) && '' !== $placeholder[2][0] ? $placeholder[2][0] : '[^/]+';
            $pattern .= isset($placeholder[3]) && '' !== $placeholder[3][0] ? '(?:'.$requirement.')?' : '(?:'.$requirement.')';
            $offset = $placeholder[0][1] + \strlen($placeholder[0][0]);
        }

        $pattern .= preg_quote(substr($path, $offset), '#');

        return 1 === preg_match('#^'.$pattern.'/?$#', (string) parse_url($url, \PHP_URL_PATH));
    }

    /**
     * @throws \InvalidArgumentException when the route is unknown
     */
    public function path(string $route): string
    {
        return $this->paths[$route] ?? throw new \InvalidArgumentException(\sprintf('The route "%s" is unknown to the last inspection: run workflows:inspect.', $route));
    }
}
