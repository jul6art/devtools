<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

/**
 * One rewrite of a workflow's page, cumulated forever (specs § 4.5: the history is appended, never
 * rewritten).
 */
final readonly class Revision
{
    public string $reason;

    public function __construct(public \DateTimeImmutable $at, public ?string $commit, string $reason)
    {
        if (null !== $commit && 1 !== preg_match('/^[0-9a-f]{7,64}$/', $commit)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a commit hash.', $commit));
        }

        $this->reason = trim($reason);

        if ('' === $this->reason) {
            throw new \InvalidArgumentException('A revision must say why the page was rewritten.');
        }
    }
}
