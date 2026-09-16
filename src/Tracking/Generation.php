<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

/**
 * When and how a page was last generated.
 */
final readonly class Generation
{
    public function __construct(
        public \DateTimeImmutable $at,
        public string $tool,
        public GenerationMode $mode,
        public ?string $model = null,
        public ?string $prompt = null,
    ) {
        if ('' === trim($tool)) {
            throw new \InvalidArgumentException('The generating tool must be named.');
        }

        if (GenerationMode::NoAi === $mode && (null !== $model || null !== $prompt)) {
            throw new \InvalidArgumentException('A page generated without AI has no model and no prompt.');
        }
    }
}
