<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Ask;

use Jul6Art\DevTools\Ai\Api\AnthropicClient;

/**
 * A client of the Claude API that answers in its place (ADR-0039): what it was asked is kept, nothing leaves the
 * machine.
 */
final class AnsweringClient extends AnthropicClient
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $requests = [];

    public string $answer = '';

    public string $stop = 'end_turn';

    #[\Override]
    public function messages(array $request): array
    {
        $this->requests[] = $request;

        return ['stop_reason' => $this->stop, 'content' => 'refusal' === $this->stop ? [] : [['type' => 'text', 'text' => $this->answer]], 'usage' => ['input_tokens' => 900, 'output_tokens' => 120]];
    }
}
