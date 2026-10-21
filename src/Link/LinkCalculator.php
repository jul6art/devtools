<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

/**
 * The calls between a client and a server (ADR-0038), in memory, from what both last inspections wrote:
 * each endpoint of each integration of the client, against the routes of the server.
 */
final class LinkCalculator
{
    /**
     * @return list<Call> sorted by client, endpoint, server; an endpoint no route answers is an orphan
     */
    public function calls(LinkSide $client, LinkSide $server, ?string $baseUrl = null): array
    {
        $pages = array_column($server->routes, 'page', 'id');
        $calls = [];

        foreach ($client->clients as $integration) {
            foreach ($integration['endpoints'] as $endpoint) {
                $answering = EndpointMatcher::answering($endpoint, $server->routes, $baseUrl);

                foreach ([] === $answering ? [null] : $answering as $route) {
                    $calls[] = new Call($endpoint, $integration['id'], $route, $integration['page'], null === $route ? null : $pages[$route] ?? null);
                }
            }
        }

        usort($calls, static fn (Call $a, Call $b): int => [$a->client, $a->endpoint, $a->server ?? ''] <=> [$b->client, $b->endpoint, $b->server ?? '']);

        return $calls;
    }
}
