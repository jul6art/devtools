<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Review\Web;

use Composer\Autoload\ClassLoader;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Process\Process;

/**
 * The review page served by PHP's built-in server (ADR-0028): bound to `127.0.0.1` only, every request through
 * `resources/review/router.php`, the project, the token and the run passed in its environment.
 */
final class ReviewServer
{
    public const string HOST = '127.0.0.1';

    public const int PORT = 8765;

    public const string PROJECT = 'DEVTOOLS_REVIEW_PROJECT';

    public const string TOKEN = 'DEVTOOLS_REVIEW_TOKEN';

    public const string RUN = 'DEVTOOLS_REVIEW_RUN';

    public const string AUTOLOAD = 'DEVTOOLS_REVIEW_AUTOLOAD';

    /**
     * A secret of the address only: another application of the machine cannot write feedback in the human's name.
     */
    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function url(int $port, string $token): string
    {
        return \sprintf('http://%s:%d/?token=%s', self::HOST, $port, $token);
    }

    public static function process(DevToolsDirectory $directory, int $port, string $token, ?string $run = null): Process
    {
        return new Process(
            [\PHP_BINARY, '-S', self::HOST.':'.$port, Resources::file('review/router.php')],
            $directory->root->path,
            [self::PROJECT => $directory->root->path, self::TOKEN => $token, self::RUN => $run ?? '', self::AUTOLOAD => self::autoload()],
            timeout: null,
        );
    }

    /**
     * Starts the server and waits until it says it listens — PHP's own line, never a probe of the port: DevTools
     * opens no connection (ADR-0002). Null once it listens; else what it said before it stopped, as on a port
     * already taken.
     */
    public static function start(Process $server, float $seconds = 5.0): ?string
    {
        $server->start();
        $deadline = microtime(true) + $seconds;

        while ($server->isRunning() && microtime(true) < $deadline) {
            if (str_contains($server->getErrorOutput(), ') started')) {
                return null;
            }

            usleep(20_000);
        }

        $said = trim($server->getErrorOutput());
        $server->stop(0);

        return '' === $said ? \sprintf('no answer in %d s.', (int) $seconds) : $said;
    }

    /**
     * The autoloader DevTools runs with — the project's `vendor/` or DevTools' own.
     */
    private static function autoload(): string
    {
        return \dirname((string) new \ReflectionClass(ClassLoader::class)->getFileName(), 2).'/autoload.php';
    }
}
