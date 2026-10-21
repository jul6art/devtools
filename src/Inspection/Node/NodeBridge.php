<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Node;

use Jul6Art\DevTools\Inspection\Adapter\Symfony\CommandLine;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleFailed;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Stack\StackProfile;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Reads JavaScript and TypeScript through Node and the project's own TypeScript compiler (ADR-0031): ONE
 * process per scan, every file of the stack in it, whatever their number.
 *
 * Not `readonly`: it counts the processes it launched (ADR-0042). Not final: a test replaces the process
 * with a recorded answer — the seam the adapters are tested through.
 */
class NodeBridge
{
    public const array QUERIES = ['imports', 'decorators', 'calls', 'news', 'variables', 'classes', 'exports'];

    private int $calls = 0;

    /**
     * @param string $binary how to run Node, e.g. `node` or `docker compose exec -T front node`
     */
    public function __construct(private readonly string $binary = 'node', private readonly float $timeout = 120)
    {
    }

    public function calls(): int
    {
        return $this->calls;
    }

    /**
     * @param list<string> $files   project paths
     * @param list<string> $queries among {@see self::QUERIES}
     * @param list<string> $calls   the names of the calls to report, `get` matching `router.get(…)`
     * @param list<string> $news    the classes whose `new` to report
     */
    public function analyze(ProjectRoot $root, StackProfile $stack, array $files, array $queries, array $calls = [], array $news = []): NodeFacts
    {
        ++$this->calls;
        $input = json_encode(['root' => $root->path, 'base' => $root->absolute($stack->root), 'files' => $files, 'queries' => $queries, 'calls' => $calls, 'news' => $news], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return $this->parse($this->run([...CommandLine::split($this->binary), self::script()], $root->path, $input));
    }

    /**
     * The script, where Node can read it: inside a phar (ADR-0034) it is copied out once, next to the system's
     * temporary files, under a name that changes with its content.
     */
    public static function script(): string
    {
        $script = Resources::path('node/devtools-analyze.mjs');

        if (!str_starts_with($script, 'phar://')) {
            return $script;
        }

        $content = (string) file_get_contents($script);
        $copy = sys_get_temp_dir().'/devtools-analyze-'.substr(hash('sha256', $content), 0, 16).'.mjs';

        if (!is_file($copy)) {
            file_put_contents($copy, $content);
        }

        return $copy;
    }

    /**
     * @param list<string> $command
     */
    protected function run(array $command, string $workingDirectory, string $input): string
    {
        $process = new Process($command, $workingDirectory, null, $input, $this->timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new NodeBridgeFailed(\sprintf('Node did not answer within %d seconds.', (int) $this->timeout));
        } catch (RuntimeException $failure) {
            throw new NodeBridgeFailed('Node could not be started: '.$failure->getMessage(), 0, $failure);
        }

        if (127 === $process->getExitCode()) {
            throw new NodeBridgeFailed(\sprintf('Node could not be started: `%s` is not on the PATH. Install Node, or say how to run it in .devtools/config.xml: <node binary="…"/>.', $command[0]));
        }

        // 2: the script refused, and said why in its JSON; anything else non-zero is Node itself failing.
        if (0 !== $process->getExitCode() && 2 !== $process->getExitCode()) {
            throw new NodeBridgeFailed(\sprintf("`%s` exited with code %d:\n%s", implode(' ', $command), (int) $process->getExitCode(), ConsoleFailed::firstLines('' !== trim($process->getErrorOutput()) ? $process->getErrorOutput() : $process->getOutput())));
        }

        return $process->getOutput();
    }

    private function parse(string $output): NodeFacts
    {
        try {
            $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new NodeBridgeFailed("The Node bridge did not answer with JSON; it printed first:\n".ConsoleFailed::firstLines($output));
        }

        if (\is_array($data) && \is_string($data['error'] ?? null)) {
            throw new NodeBridgeFailed($data['error']);
        }

        if (!\is_array($data) || !\is_array($data['files'] ?? null) || !\is_string($data['typescript'] ?? null)) {
            throw new NodeBridgeFailed("The Node bridge answered with JSON of an unexpected shape:\n".ConsoleFailed::firstLines($output));
        }

        $files = [];

        foreach ($data['files'] as $file => $facts) {
            if (\is_string($file) && \is_array($facts)) {
                $files[$file] = array_filter($facts, \is_string(...), \ARRAY_FILTER_USE_KEY);
            }
        }

        $errors = [];

        foreach (\is_array($data['errors'] ?? null) ? $data['errors'] : [] as $error) {
            if (\is_array($error) && \is_string($error['file'] ?? null) && \is_string($error['message'] ?? null)) {
                $errors[] = ['file' => $error['file'], 'message' => $error['message']];
            }
        }

        return new NodeFacts($data['typescript'], $files, $errors);
    }
}
