<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Review\Web;

/**
 * What the review page answers.
 */
final readonly class ReviewResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(public int $status, public array $headers, public string $body)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(int $status, array $data): self
    {
        return new self($status, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name.': '.$value);
        }

        echo $this->body;
    }
}
