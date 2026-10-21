<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Release;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Replaces the running phar by the latest release (ADR-0034) — once its SHA-256 matches the one published
 * beside it AND GitHub's provenance attestation, checked by the GitHub CLI, says the CI of jul6art/devtools
 * built it. Either fails: nothing is replaced.
 */
final readonly class SelfUpdater
{
    public const string REPOSITORY = 'jul6art/devtools';

    /**
     * @param string|null $gh the GitHub CLI; null to find it on the PATH, '' for none
     */
    public function __construct(private GitHubReleases $releases = new GitHubReleases(), private ?string $gh = null)
    {
    }

    /**
     * @return array{string, string|null} the latest version, and the version replaced — null when already current
     */
    public function update(string $phar, string $current): array
    {
        $latest = $this->releases->latest();

        if (ltrim($current, 'v') === $latest['version']) {
            return [$latest['version'], null];
        }

        $content = $this->releases->download($latest['phar']);
        $expected = strtolower(trim(explode(' ', trim($this->releases->download($latest['checksum'])))[0]));
        $actual = hash('sha256', $content);

        if (!hash_equals($expected, $actual)) {
            throw new UpdateRefused(\sprintf('The downloaded devtools.phar does not match its published SHA-256 (expected %s, got %s): nothing was replaced.', $expected, $actual));
        }

        $candidate = \dirname($phar).'/.devtools-'.$latest['version'].'-'.bin2hex(random_bytes(4)).'.phar';
        file_put_contents($candidate, $content);

        try {
            $this->attest($candidate);
            chmod($candidate, (fileperms($phar) ?: 0o755) & 0o777);

            if (!rename($candidate, $phar)) {
                throw new UpdateRefused(\sprintf('%s could not be replaced.', $phar));
            }
        } finally {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }

        return [$latest['version'], $current];
    }

    private function attest(string $candidate): void
    {
        $gh = $this->gh ?? new ExecutableFinder()->find('gh');

        if (null === $gh || '' === $gh) {
            throw new UpdateRefused('The GitHub CLI (gh) is needed to verify the provenance of the release, and it is not installed: install it, or download the release yourself from https://github.com/'.self::REPOSITORY.'/releases.');
        }

        $verify = new Process([$gh, 'attestation', 'verify', $candidate, '--repo', self::REPOSITORY], timeout: 120);
        $verify->run();

        if (!$verify->isSuccessful()) {
            throw new UpdateRefused("The provenance of the release could not be verified, nothing was replaced:\n".trim($verify->getErrorOutput().$verify->getOutput()));
        }
    }
}
