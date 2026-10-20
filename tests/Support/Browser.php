<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Panther\Client;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * What the tests that replay scenarios need (ADR-0021): ChromeDriver — `drivers/chromedriver`, or on the PATH
 * as on a GitHub runner — and a site served by `php -S` on a free port. Without ChromeDriver, they are skipped,
 * saying why.
 */
final class Browser
{
    public static function driver(): ?string
    {
        $local = \dirname(__DIR__, 2).'/drivers/chromedriver';
        $environment = getenv('CHROMEWEBDRIVER');

        return match (true) {
            is_file($local) => $local,
            \is_string($environment) && is_file($environment.'/chromedriver') => $environment.'/chromedriver',
            default => new ExecutableFinder()->find('chromedriver'),
        };
    }

    public static function skipWithout(): void
    {
        if (null === self::driver()) {
            TestCase::markTestSkipped('ChromeDriver not found: vendor/bin/bdi detect drivers, or chromedriver on the PATH.');
        }
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{Process, string} the server, and its base URL
     */
    public static function serve(string $documentRoot, ?string $router = null, array $env = []): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        TestCase::assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $server = new Process(array_values(array_filter([\PHP_BINARY, '-S', $address, '-t', $documentRoot, $router], \is_string(...))), $documentRoot, $env);
        $server->start();

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @fsockopen('127.0.0.1', (int) substr($address, (int) strrpos($address, ':') + 1), $code, $error, 0.05);

            if (\is_resource($connection)) {
                fclose($connection);

                return [$server, 'http://'.$address];
            }

            usleep(20_000);
        }

        $server->stop();
        TestCase::fail('php -S did not start: '.$server->getErrorOutput());
    }

    public static function client(string $baseUrl): Client
    {
        return Client::createChromeClient(self::driver(), ['--headless=new', '--window-size=1280,800', '--disable-gpu', '--disable-dev-shm-usage', '--no-sandbox'], [], $baseUrl);
    }
}
