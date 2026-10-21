<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

/**
 * One call of a client to a server (ADR-0038): the endpoint as the client writes it, the workflow that calls
 * it, and the route that answers — null for an orphan call, a screen calling a route that does not exist.
 *
 * Pages are relative to the root of the project they belong to: never an absolute path.
 */
final readonly class Call
{
    public function __construct(
        public string $endpoint,
        public string $client,
        public ?string $server = null,
        public ?string $clientPage = null,
        public ?string $serverPage = null,
    ) {
    }

    public function isOrphan(): bool
    {
        return null === $this->server;
    }

    /**
     * The workflow of the project on `$side`'s side of the call.
     */
    public function workflowOn(LinkRole $side): ?string
    {
        return LinkRole::Client === $side ? $this->client : $this->server;
    }

    public function pageOn(LinkRole $side): ?string
    {
        return LinkRole::Client === $side ? $this->clientPage : $this->serverPage;
    }
}
