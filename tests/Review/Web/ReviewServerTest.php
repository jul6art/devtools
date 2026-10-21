<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Review\Web;

use Jul6Art\DevTools\Command\ReviewCommand;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Review\Web\ReviewServer;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * ADR-0028 over HTTP: `devtools review` serves the page with PHP's built-in server, on 127.0.0.1 only, and a request
 * without the token of the address it printed is refused.
 */
#[CoversClass(ReviewServer::class)]
#[CoversClass(ReviewCommand::class)]
final class ReviewServerTest extends TestCase
{
    use CopiesFixtureProjects;
    use RecordsARun;

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->copyFixtureProject('symfony-minimal');
        new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]))->run(new InspectionOptions($this->project, noAi: true));
        $this->recordRun($this->project);
    }

    public function testTheCommandServesThePageOnTheLoopbackBehindItsToken(): void
    {
        $port = self::freePort();
        $answers = [];
        $command = new CommandTester(new ReviewCommand($this->project, static function (Process $server, string $url) use ($port, &$answers): int {
            self::assertContains(ReviewServer::HOST.':'.$port, explode(' ', str_replace("'", '', $server->getCommandLine())), 'Bound to the loopback only.');
            $token = (string) parse_url($url, \PHP_URL_QUERY);
            $base = \sprintf('http://%s:%d', ReviewServer::HOST, $port);

            $answers = [
                'without' => self::fetch($base.'/api/run'),
                'wrong' => self::fetch($base.'/api/run?token=0000'),
                'with' => self::fetch($base.'/api/run?'.$token),
                'page' => self::fetch($url),
                'script' => self::fetch($base.'/assets/review.js'),
                'capture' => self::fetch($base.'/files/run/'.self::CAPTURE.'.html?'.$token),
            ];
            $server->stop();

            return 0;
        }));

        self::assertSame(0, $command->execute(['--port' => (string) $port]), $command->getDisplay());
        self::assertMatchesRegularExpression('#The review page: http://127\.0\.0\.1:'.$port.'/\?token=[0-9a-f]{32}#', (string) preg_replace('/\s+/', ' ', $command->getDisplay()));
        self::assertSame(403, $answers['without'][0]);
        self::assertSame(403, $answers['wrong'][0]);
        self::assertSame(200, $answers['with'][0]);
        self::assertStringContainsString('"run":"'.self::RUN.'"', $answers['with'][1]);
        self::assertStringContainsString('<title>DevTools review</title>', $answers['page'][1]);
        self::assertStringContainsString('window.DevToolsReview', $answers['script'][1]);
        self::assertStringContainsString("content-security-policy: default-src 'none'", strtolower($answers['capture'][2]));
        self::assertFalse(self::answers($port), 'Stopped with the command.');
    }

    public function testTheCommandRefusesWhatItCannotServe(): void
    {
        self::assertSame(2, new CommandTester(new ReviewCommand())->execute(['--path' => $this->project.'/nowhere']));
        self::assertSame(2, new CommandTester(new ReviewCommand($this->project))->execute(['--port' => 'eighty']));
        self::assertSame(2, new CommandTester(new ReviewCommand($this->project))->execute(['--port' => '70000']));

        $unknown = new CommandTester(new ReviewCommand($this->project));
        self::assertSame(2, $unknown->execute(['--run' => '2026-01-01-000000']));
        self::assertStringContainsString('No run "2026-01-01-000000"', $unknown->getDisplay());

        $taken = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($taken);
        $port = (int) substr((string) stream_socket_get_name($taken, false), (int) strrpos((string) stream_socket_get_name($taken, false), ':') + 1);

        try {
            $busy = new CommandTester(new ReviewCommand($this->project, static fn (): int => 0));
            self::assertSame(1, $busy->execute(['--port' => (string) $port]));
            $display = (string) preg_replace('/\s+/', ' ', $busy->getDisplay());
            self::assertStringContainsString('The review server did not start', $display);
            self::assertStringContainsString('Address already in use', $display);
            self::assertStringContainsString('--port gives another one.', $display);
        } finally {
            fclose($taken);
        }
    }

    public function testTheServerOfAGivenRunAndItsAddress(): void
    {
        $server = ReviewServer::process(DevToolsDirectory::documented(new ProjectRoot($this->project)), 9999, 'secret', self::RUN);

        self::assertSame(self::RUN, $server->getEnv()[ReviewServer::RUN]);
        self::assertSame('secret', $server->getEnv()[ReviewServer::TOKEN]);
        $autoload = $server->getEnv()[ReviewServer::AUTOLOAD];
        self::assertIsString($autoload);
        self::assertFileExists($autoload);
        self::assertSame('http://127.0.0.1:9999/?token=abc', ReviewServer::url(9999, 'abc'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', ReviewServer::token());
        self::assertNotSame(ReviewServer::token(), ReviewServer::token());
    }

    /**
     * A server that says nothing in time is stopped, and the command says why.
     */
    public function testAServerThatDoesNotAnswerInTimeIsStopped(): void
    {
        $server = ReviewServer::process(DevToolsDirectory::documented(new ProjectRoot($this->project)), self::freePort(), 'secret');

        self::assertSame('no answer in 0 s.', ReviewServer::start($server, 0.0));
        self::assertFalse($server->isRunning());
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }

    /**
     * Whether something answers on the port — the test's probe; DevTools itself opens no connection (ADR-0002).
     */
    private static function answers(int $port): bool
    {
        $socket = @fsockopen(ReviewServer::HOST, $port, $code, $message, 0.2);

        if (false === $socket) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * @return array{int, string, string} the status, the body, the headers
     */
    private static function fetch(string $url): array
    {
        $body = (string) @file_get_contents($url, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]));
        $headers = http_get_last_response_headers() ?? [];

        return [(int) (explode(' ', $headers[0] ?? '')[1] ?? 0), $body, implode("\n", $headers)];
    }
}
