<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Release;

/**
 * The releases of DevTools on GitHub (ADR-0034) — with the Claude API client of ADR-0035, the only code of
 * DevTools that opens a connection, and only when `self-update` is asked to: ADR-0002's rule holds
 * everywhere else, and its architecture test names these exceptions.
 *
 * Not final: a test answers in its place.
 */
class GitHubReleases
{
    public const string API = 'https://api.github.com/repos/jul6art/devtools/releases/latest';

    /**
     * @param string|null $api the latest release's address; `DEVTOOLS_RELEASES_URL` replaces it for tests
     */
    public function __construct(private readonly ?string $api = null)
    {
    }

    /**
     * @return array{version: string, phar: string, checksum: string}
     */
    public function latest(): array
    {
        $release = json_decode($this->get($this->api ?? (\is_string(getenv('DEVTOOLS_RELEASES_URL')) && '' !== getenv('DEVTOOLS_RELEASES_URL') ? (string) getenv('DEVTOOLS_RELEASES_URL') : self::API), 'application/vnd.github+json'), true);

        if (!\is_array($release) || !\is_string($release['tag_name'] ?? null) || !\is_array($release['assets'] ?? null)) {
            throw new UpdateRefused('GitHub did not describe the latest release.');
        }

        $assets = [];

        foreach ($release['assets'] as $asset) {
            if (\is_array($asset) && \is_string($asset['name'] ?? null) && \is_string($asset['browser_download_url'] ?? null)) {
                $assets[$asset['name']] = $asset['browser_download_url'];
            }
        }

        if (!isset($assets['devtools.phar'], $assets['devtools.phar.sha256'])) {
            throw new UpdateRefused(\sprintf('The release %s has no devtools.phar and devtools.phar.sha256: nothing to verify, nothing installed.', $release['tag_name']));
        }

        return ['version' => ltrim($release['tag_name'], 'v'), 'phar' => $assets['devtools.phar'], 'checksum' => $assets['devtools.phar.sha256']];
    }

    public function download(string $url): string
    {
        return $this->get($url, 'application/octet-stream');
    }

    protected function get(string $url, string $accept): string
    {
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://127.0.0.1:')) {
            throw new UpdateRefused(\sprintf('%s is not an HTTPS address.', $url));
        }

        $context = stream_context_create(['http' => ['header' => "User-Agent: jul6art-devtools\r\nAccept: ".$accept."\r\n", 'timeout' => 60, 'follow_location' => 1, 'ignore_errors' => false]]);
        $body = @file_get_contents($url, false, $context);

        if (!\is_string($body)) {
            throw new UpdateRefused(\sprintf('%s could not be downloaded: %s', $url, error_get_last()['message'] ?? 'no answer'));
        }

        return $body;
    }
}
