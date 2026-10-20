<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Bridge\Symfony\Functional;

use Jul6Art\DevTools\Bridge\Symfony\Test\ScenarioTestCase;
use Jul6Art\DevTools\Tests\Support\ServedSymfonyMinimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ADR-0021, the PHPUnit mode: what a Symfony project writes to replay its scenarios — a class that extends
 * `ScenarioTestCase` — here on symfony-minimal, served by Panther, one test per skeleton.
 *
 * Without ChromeDriver or the fixture's vendor/, the project has no scenario, and the single case says why it
 * is skipped.
 */
#[Group('browser')]
#[CoversClass(ScenarioTestCase::class)]
final class SymfonyMinimalScenariosTest extends ScenarioTestCase
{
    private static ?string $project = null;

    /**
     * The directory this class created, and the only one it removes.
     */
    private static ?string $directory = null;

    public static function setUpBeforeClass(): void
    {
        ServedSymfonyMinimal::exportCredentials();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        ServedSymfonyMinimal::exportCredentials(false);

        // ⚠️ Never a parent of the project: without the prerequisites, the project IS the created directory, and
        // its parent is the system's temporary directory.
        if (null !== self::$directory && str_starts_with(basename(self::$directory), 'devtools-scenarios-')) {
            new Filesystem()->remove(self::$directory);
        }

        self::$project = null;
        self::$directory = null;
    }

    #[\Override]
    protected static function projectDir(): string
    {
        if (null === self::$project) {
            $directory = sys_get_temp_dir().'/devtools-scenarios-'.bin2hex(random_bytes(6));
            mkdir($directory);
            self::$directory = (string) realpath($directory);
            self::$project = null === ServedSymfonyMinimal::missing() ? ServedSymfonyMinimal::prepare(self::$directory.'/symfony-minimal') : self::$directory;
        }

        return self::$project;
    }

    #[\Override]
    protected static function webServerOptions(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = \is_resource($socket) ? (int) substr((string) stream_socket_get_name($socket, false), (int) strrpos((string) stream_socket_get_name($socket, false), ':') + 1) : 9081;

        if (\is_resource($socket)) {
            fclose($socket);
        }

        return [...parent::webServerOptions(), 'port' => $port, 'env' => ['APP_ENV' => 'dev', 'APP_DEBUG' => '0']];
    }
}
