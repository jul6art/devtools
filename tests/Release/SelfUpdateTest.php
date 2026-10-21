<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Release;

use Jul6Art\DevTools\Command\SelfUpdateCommand;
use Jul6Art\DevTools\Console\Application;
use Jul6Art\DevTools\Release\GitHubReleases;
use Jul6Art\DevTools\Release\SelfUpdater;
use Jul6Art\DevTools\Release\UpdateRefused;
use Jul6Art\DevTools\Tests\Support\Browser;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * ADR-0034: `self-update` replaces the phar only once the release's checksum and provenance hold — against a
 * fake GitHub served on 127.0.0.1, and a fake GitHub CLI.
 */
#[CoversClass(SelfUpdater::class)]
#[CoversClass(GitHubReleases::class)]
#[CoversClass(SelfUpdateCommand::class)]
final class SelfUpdateTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const string RELEASED = "<?php echo 'DevTools 9.9.9';\n";

    private ?Process $server = null;

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
        parent::tearDown();
    }

    public function testAReleaseWhoseChecksumDoesNotMatchReplacesNothing(): void
    {
        $phar = $this->installed();
        $tester = $this->command($this->releases(hash('sha256', 'something else')), $this->gh(0), $phar);

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('does not match its published SHA-256', $this->display($tester));
        self::assertSame('the running phar', file_get_contents($phar));
        self::assertSame([], glob(\dirname($phar).'/.devtools-*') ?: [], 'No candidate left behind.');
    }

    public function testAReleaseWhoseProvenanceCannotBeVerifiedReplacesNothing(): void
    {
        $phar = $this->installed();

        $failing = $this->command($this->releases(hash('sha256', self::RELEASED)), $this->gh(1), $phar);
        self::assertSame(1, $failing->execute([]));
        self::assertStringContainsString('The provenance of the release could not be verified, nothing was replaced: no attestation found', $this->display($failing));

        $absent = $this->command($this->releases(hash('sha256', self::RELEASED)), '', $phar);
        self::assertSame(1, $absent->execute([]));
        self::assertStringContainsString('The GitHub CLI (gh) is needed to verify the provenance of the release', $this->display($absent));
        self::assertSame('the running phar', file_get_contents($phar));
    }

    public function testAVerifiedReleaseReplacesThePharAndKeepsItsPermissions(): void
    {
        $phar = $this->installed();
        chmod($phar, 0o750);
        $tester = $this->command($this->releases(hash('sha256', self::RELEASED)."  devtools.phar\n"), $this->gh(0), $phar);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('DevTools 9.9.9 replaced 3.3.0: its SHA-256 and its provenance were verified.', $this->display($tester));
        self::assertSame(self::RELEASED, file_get_contents($phar));
        self::assertSame(0o750, fileperms($phar) & 0o777);
    }

    public function testTheLatestReleaseIsNotDownloadedAgainAndComposerUpdatesItsOwnInstallation(): void
    {
        $phar = $this->installed();
        $tester = new CommandTester(new SelfUpdateCommand(new SelfUpdater($this->releases('unused'), ''), $phar, 'v9.9.9'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('DevTools 9.9.9 is the latest release.', $this->display($tester));

        $composer = new CommandTester(new Application()->find('self-update'));
        self::assertSame(1, $composer->execute([]));
        self::assertStringContainsString('installed with Composer: update it with composer update jul6art/devtools', $this->display($composer));
    }

    public function testOnlyHttpsIsEverAsked(): void
    {
        $this->expectException(UpdateRefused::class);
        $this->expectExceptionMessage('http://example.com/latest is not an HTTPS address.');

        new GitHubReleases('http://example.com/latest')->latest();
    }

    private function installed(): string
    {
        $phar = $this->temporaryDirectory().'/bin/devtools';
        mkdir(\dirname($phar));
        file_put_contents($phar, 'the running phar');

        return $phar;
    }

    private function command(GitHubReleases $releases, string $gh, string $phar): CommandTester
    {
        return new CommandTester(new SelfUpdateCommand(new SelfUpdater($releases, $gh), $phar, '3.3.0'));
    }

    /**
     * A fake GitHub: the latest release, its phar and its checksum.
     */
    private function releases(string $checksum): GitHubReleases
    {
        $www = $this->temporaryDirectory().'/github';
        @mkdir($www);
        file_put_contents($www.'/devtools.phar', self::RELEASED);
        file_put_contents($www.'/devtools.phar.sha256', $checksum);
        [$this->server, $base] = Browser::serve($www);
        file_put_contents($www.'/latest.json', json_encode(['tag_name' => 'v9.9.9', 'assets' => [
            ['name' => 'devtools.phar', 'browser_download_url' => $base.'/devtools.phar'],
            ['name' => 'devtools.phar.sha256', 'browser_download_url' => $base.'/devtools.phar.sha256'],
        ]]));

        return new GitHubReleases($base.'/latest.json');
    }

    /**
     * A GitHub CLI that verifies, or does not.
     */
    private function gh(int $exitCode): string
    {
        $gh = $this->temporaryDirectory().'/gh-'.$exitCode;
        file_put_contents($gh, "#!/bin/sh\n".(0 === $exitCode ? 'echo "Loaded 1 attestation"' : 'echo "no attestation found" >&2')."\nexit ".$exitCode."\n");
        chmod($gh, 0o755);

        return $gh;
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
