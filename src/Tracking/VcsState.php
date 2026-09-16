<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

/**
 * The state of version control at generation time; `none` for a project outside git.
 */
final readonly class VcsState
{
    public function __construct(
        public ?string $commit,
        public ?string $branch = null,
        public bool $dirty = false,
    ) {
        if (null !== $commit && 1 !== preg_match('/^[0-9a-f]{7,64}$/', $commit)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a commit hash.', $commit));
        }

        if (null === $commit && (null !== $branch || $dirty)) {
            throw new \InvalidArgumentException('Without a commit there is no version control, hence no branch and no dirty state.');
        }
    }

    public static function none(): self
    {
        return new self(null);
    }

    public function isVersioned(): bool
    {
        return null !== $this->commit;
    }
}
