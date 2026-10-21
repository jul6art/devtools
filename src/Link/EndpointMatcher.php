<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

/**
 * A client's call against a server's routes (ADR-0038): the method, then the path segment by segment —
 * `/api/items/${id}` is `/api/items/{id}`, `/items/:id` too. A parameter matches any segment; a literal only
 * itself. The best routes win: two literals alike weigh more than two parameters, which weigh more than a
 * parameter against a literal — `/items/${id}` is `/items/{id}` rather than `/items/export`.
 *
 * The client's URL loses its scheme and host (`https://api.example.com/api/items` → `/api/items`), its query
 * and its fragment. A URL whose start the analysis could not read — `*` for an `environment.apiUrl` it could
 * not resolve — is replaced by the declared base URL, or matched by its end: `*` + `/items` answers
 * `/api/items` and `/items`.
 */
final class EndpointMatcher
{
    private const string PARAMETER = '{}';

    /**
     * @param list<array{id: string, methods: list<string>, path: string}> $routes
     *
     * @return list<string> the identifiers of the routes that answer best: those matching the most literal
     *                      segments, several when they tie
     */
    public static function answering(string $endpoint, array $routes, ?string $baseUrl = null): array
    {
        [$method, $url] = explode(' ', $endpoint, 2) + [1 => ''];
        [$segments, $anyPrefix] = self::client($url, $baseUrl);
        $best = [];
        $bestScore = -1;

        foreach ($routes as $route) {
            if ([] !== $route['methods'] && !\in_array(strtoupper($method), $route['methods'], true)) {
                continue;
            }

            $score = self::score($segments, $anyPrefix, self::server($route['path']));

            if (null === $score || $score < $bestScore) {
                continue;
            }

            if ($score > $bestScore) {
                $best = [];
                $bestScore = $score;
            }

            $best[] = $route['id'];
        }

        sort($best, \SORT_STRING);

        return $best;
    }

    /**
     * @return array{list<string>, bool} the segments, and whether any prefix may precede them
     */
    public static function client(string $url, ?string $baseUrl = null): array
    {
        if (str_starts_with($url, '*') && null !== $baseUrl) {
            $url = rtrim($baseUrl, '/').substr($url, 1);
        }

        $url = (string) preg_replace(['#^[a-z][a-z0-9+.-]*://[^/]*#i', '#^//[^/]*#', '/[?#].*$/'], '', $url);
        $anyPrefix = str_starts_with($url, '*');

        if ($anyPrefix) {
            $url = !str_contains($url, '/') ? '' : substr($url, (int) strpos($url, '/'));
        }

        return [self::segments($url), $anyPrefix];
    }

    /**
     * @return list<string>
     */
    public static function server(string $path): array
    {
        return self::segments((string) preg_replace('/[?#].*$/', '', $path));
    }

    /**
     * @param list<string> $client
     * @param list<string> $server
     *
     * @return int|null how alike they are, or null when they do not match
     */
    private static function score(array $client, bool $anyPrefix, array $server): ?int
    {
        if (\count($client) > \count($server) || (!$anyPrefix && \count($client) !== \count($server))) {
            return null;
        }

        $server = \array_slice($server, \count($server) - \count($client));
        $score = 0;

        foreach ($client as $index => $segment) {
            if ($segment === $server[$index]) {
                $score += self::PARAMETER === $segment ? 1 : 2;
            } elseif (self::PARAMETER !== $segment && self::PARAMETER !== $server[$index]) {
                return null;
            }
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_map(
            static fn (string $segment): string => 1 === preg_match('/^:|[*{]|\$\{/', $segment) ? self::PARAMETER : $segment,
            array_filter(explode('/', $path), static fn (string $segment): bool => '' !== $segment),
        ));
    }
}
