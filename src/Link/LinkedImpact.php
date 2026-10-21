<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

/**
 * A workflow of a linked project a change reaches (ADR-0038), and how:
 *
 * - `calls`: an integration of the client calls a route the change reaches;
 * - `uses`: a screen of the client goes through that integration;
 * - `answers`: a route of the server answers an integration the change reaches.
 */
final readonly class LinkedImpact
{
    /**
     * @param string      $via  the workflow it is reached through: of this project for `calls` and `answers`, of
     *                          the neighbour for `uses`
     * @param string|null $page relative to the root of the neighbour
     */
    public function __construct(
        public string $project,
        public string $id,
        public string $relation,
        public string $via,
        public ?string $endpoint = null,
        public ?string $page = null,
    ) {
    }

    /**
     * @return array{project: string, id: string, relation: string, via: string, endpoint: string|null, page: string|null}
     */
    public function toArray(): array
    {
        return ['project' => $this->project, 'id' => $this->id, 'relation' => $this->relation, 'via' => $this->via, 'endpoint' => $this->endpoint, 'page' => $this->page];
    }
}
