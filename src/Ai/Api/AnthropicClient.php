<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai\Api;

/**
 * The Messages API of Anthropic, over HTTPS (ADR-0035) — with the release download of ADR-0034, the only code of
 * DevTools that opens a connection, and only when `ai:run` is both enabled in `config.xml` and asked for.
 *
 * Raw HTTP through PHP's streams, as the ADR asks for no third-party client: one request per task, no
 * streaming — a draft fits in the default sixteen thousand output tokens. The key is read from
 * `ANTHROPIC_API_KEY` and goes in the request header only: never in a message, an exception or a file.
 *
 * Not final: a test answers in its place.
 */
class AnthropicClient
{
    public const string API = 'https://api.anthropic.com/v1/messages';

    public const string VERSION = '2023-06-01';

    /**
     * The models that accept the server-side refusal fallback; a request to them asks for it.
     */
    private const array FALLBACKS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    /**
     * @param string|null $key the API key; null reads ANTHROPIC_API_KEY
     * @param string|null $api the endpoint; `DEVTOOLS_ANTHROPIC_URL` replaces it for tests
     */
    public function __construct(private readonly ?string $key = null, private readonly ?string $api = null, private readonly float $timeout = 600)
    {
    }

    public function hasKey(): bool
    {
        return '' !== $this->key();
    }

    /**
     * @param array<string, mixed> $request the body of POST /v1/messages
     *
     * @return array<string, mixed> the message
     */
    public function messages(array $request): array
    {
        $key = $this->key();

        if ('' === $key) {
            throw new ApiFailed('ANTHROPIC_API_KEY is not set: ai:run reads the key from that variable only.');
        }

        $model = \is_string($request['model'] ?? null) ? $request['model'] : '';
        $headers = ['content-type: application/json', 'x-api-key: '.$key, 'anthropic-version: '.self::VERSION];

        if (\in_array($model, self::FALLBACKS, true)) {
            $request['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }

        return $this->post($this->endpoint(), $headers, json_encode($request, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param list<string> $headers
     *
     * @return array<string, mixed>
     */
    protected function post(string $url, array $headers, string $body): array
    {
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers)."\r\n", 'content' => $body, 'timeout' => $this->timeout, 'ignore_errors' => true]]);
        $answer = @file_get_contents($url, false, $context);

        if (!\is_string($answer)) {
            throw new ApiFailed(\sprintf('The Claude API could not be reached at %s.', $url));
        }

        $status = 0;

        foreach (http_get_last_response_headers() ?? [] as $header) {
            if (1 === preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        $message = json_decode($answer, true);

        if (!\is_array($message)) {
            throw new ApiFailed(\sprintf('The Claude API answered with something else than JSON (HTTP %d).', $status));
        }

        if ($status >= 400 || 'error' === ($message['type'] ?? null)) {
            $error = \is_array($message['error'] ?? null) ? $message['error'] : [];

            throw new ApiFailed(\sprintf('The Claude API refused the request (HTTP %d, %s): %s', $status, \is_string($error['type'] ?? null) ? $error['type'] : 'error', \is_string($error['message'] ?? null) ? $error['message'] : 'no detail'), \is_string($error['type'] ?? null) ? $error['type'] : null);
        }

        // A message is a JSON object: its keys are names.
        return array_filter($message, \is_string(...), \ARRAY_FILTER_USE_KEY);
    }

    private function key(): string
    {
        $key = $this->key ?? getenv('ANTHROPIC_API_KEY');

        return \is_string($key) ? trim($key) : '';
    }

    private function endpoint(): string
    {
        $url = $this->api ?? (\is_string(getenv('DEVTOOLS_ANTHROPIC_URL')) && '' !== getenv('DEVTOOLS_ANTHROPIC_URL') ? (string) getenv('DEVTOOLS_ANTHROPIC_URL') : self::API);

        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://127.0.0.1:')) {
            throw new ApiFailed(\sprintf('%s is not an HTTPS address: the key is never sent in clear.', $url));
        }

        return $url;
    }
}
