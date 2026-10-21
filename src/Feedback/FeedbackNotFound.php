<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

final class FeedbackNotFound extends \RuntimeException
{
    public static function id(string $id): self
    {
        return new self(\sprintf('No feedback "%s": feedback:list gives the identifiers.', $id));
    }
}
