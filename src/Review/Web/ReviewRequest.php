<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Review\Web;

/**
 * A request to the review page (ADR-0028), as PHP's built-in server hands it over.
 */
final readonly class ReviewRequest
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers lowercase names
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $headers = [],
        public string $body = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $headers = [];
        $query = [];

        // The headers as PHP hands them over in $_SERVER: X-DevTools-Token is HTTP_X_DEVTOOLS_TOKEN.
        foreach ($server as $name => $value) {
            if (\is_string($name) && \is_string($value) && str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, \strlen('HTTP_'))))] = $value;
            }
        }

        foreach ($_GET as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $query[$name] = $value;
            }
        }

        return new self(
            \is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET',
            (string) parse_url(\is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/', \PHP_URL_PATH),
            $query,
            $headers,
            (string) file_get_contents('php://input'),
        );
    }

    public function token(): string
    {
        return $this->query['token'] ?? $this->headers['x-devtools-token'] ?? '';
    }

    /**
     * @return array<string, mixed>|null null when the body is not a JSON object
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        if (!\is_array($decoded) || ([] !== $decoded && array_is_list($decoded))) {
            return null;
        }

        $object = [];

        foreach ($decoded as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }
}
