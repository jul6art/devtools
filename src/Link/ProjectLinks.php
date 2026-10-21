<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

/**
 * The calls between this project and one neighbour, as `.devtools/graph/links.xml` keeps them (ADR-0038).
 */
final readonly class ProjectLinks
{
    /**
     * @param list<Call> $calls sorted by client, endpoint, server
     */
    public function __construct(
        public string $name,
        public string $path,
        public LinkRole $role,
        public array $calls = [],
    ) {
    }

    /**
     * This project's side of the calls: the server when the neighbour is the client.
     */
    public function localSide(): LinkRole
    {
        return LinkRole::Client === $this->role ? LinkRole::Server : LinkRole::Client;
    }

    /**
     * @return list<Call>
     */
    public function orphans(): array
    {
        return array_values(array_filter($this->calls, static fn (Call $call): bool => $call->isOrphan()));
    }
}
