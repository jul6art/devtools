<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai\Api;

/**
 * The Claude API did not answer with a message: no key, a refusal of the request, a network failure. Its
 * message never holds the key.
 */
final class ApiFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $type = null)
    {
        parent::__construct($message);
    }
}
