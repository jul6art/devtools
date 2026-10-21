<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Express;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterInterface;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Adapter\JavaScript\JavaScriptSources;
use Jul6Art\DevTools\Inspection\Graph\EntryPointCandidate;
use Jul6Art\DevTools\Inspection\Graph\MechanismAttachment;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\RoleGuesser;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Inspection\Node\NodeBridge;
use Jul6Art\DevTools\Inspection\Node\NodeBridgeFailed;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * The Express adapter (ADR-0033), on the Node bridge (ADR-0031): the routes of the applications and of the
 * routers they mount, the commands `package.json` names, BullMQ workers and node-cron schedules — JavaScript
 * and TypeScript, CommonJS and ESM. A listener of the project's emitter is a mechanism of the workflows that
 * emit its event (ADR-0043).
 */
final readonly class ExpressAdapter implements AdapterInterface
{
    public function __construct(private ?NodeBridge $bridge = null)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'express';
    }

    #[\Override]
    public function extract(ProjectRoot $root, StackProfile $stack, Config $config, PhpReferenceExtractor $extractor): AdapterResult
    {
        $bridge = $this->bridge ?? JavaScriptSources::bridge($config);
        $package = self::package($root, $stack);
        $entries = $this->commandFiles($root, $stack, $package);

        try {
            $sources = JavaScriptSources::read($bridge, $root, $stack, array_values(array_unique(array_map(\dirname(...), array_values($entries)))), [...ExpressReading::VERBS, 'use', 'route', 'schedule', 'on', 'once', 'emit'], ['Worker']);
        } catch (NodeBridgeFailed $failure) {
            return JavaScriptSources::claudePath($root, $stack, $config, $extractor, $failure->getMessage(), $bridge->calls());
        }

        $files = array_values(array_filter($sources->files, $sources->isSource(...)));
        $reading = new ExpressReading($sources->facts, $files);
        $source = new WorkflowSource('native:express');
        $manifest = [new FileRef($stack->projectPath('package.json'), FileRole::Config)];
        $candidates = [];

        foreach ($reading->routes() as $route) {
            $attributes = ['path' => $route['path']];

            if ('all' !== $route['verb']) {
                $attributes['methods'] = strtoupper($route['verb']);
            }

            if ([] !== $route['middleware']) {
                $attributes['security'] = implode(', ', $route['middleware']);
            }

            $candidates[] = new EntryPointCandidate(
                type: WorkflowType::routes(),
                // The application's file is read as configuration there: it declares, its routers run.
                entryPoint: new EntryPoint('route', $route['name'], new FileRef($route['declaredIn'], $route['inApplication'] ? FileRole::Config : RoleGuesser::guess($route['declaredIn'])), $attributes),
                title: $route['title'],
                structuralFiles: array_map(static fn (string $path): FileRef => new FileRef($path, RoleGuesser::guess($path)), $route['chain']),
                extraTests: $this->testsRequesting($root, $sources->tests, $route['path']),
                confidence: $route['literal'] ? Confidence::High : Confidence::Medium,
                source: $source,
            );
        }

        foreach ($entries as $name => $path) {
            $candidates[] = new EntryPointCandidate(WorkflowType::commands(), new EntryPoint('command', $name, new FileRef($path, FileRole::Other)), $name, structuralFiles: $manifest, confidence: Confidence::High, source: $source);
        }

        foreach ($reading->workers() as $queue => $path) {
            $candidates[] = new EntryPointCandidate(WorkflowType::async(), new EntryPoint('worker', $queue, new FileRef($path, FileRole::Handler)), $queue.' (BullMQ)', confidence: Confidence::High, source: $source);
        }

        foreach ($reading->schedules() as $path => $expression) {
            $name = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', pathinfo($path, \PATHINFO_FILENAME)));
            $candidates[] = new EntryPointCandidate(WorkflowType::async(), new EntryPoint('scheduled', $name, new FileRef($path, FileRole::Handler), ['schedule' => $expression]), $name.' ('.$expression.')', confidence: Confidence::High, source: $source);
        }

        $mechanisms = [];

        foreach ($reading->listeners() as $listener) {
            $mechanism = new Mechanism('listener', $listener['name'], $listener['event'], new FileRef($listener['file'], FileRole::Listener));
            $mechanisms[] = new MechanismAttachment($mechanism, files: [] === $listener['emitters'] ? [$listener['file']] : $listener['emitters']);
        }

        return new AdapterResult(
            $candidates,
            warnings: [...$reading->warnings(), ...array_map(static fn (array $error): string => $error['file'].' cannot be read: '.$error['message'], $sources->facts->errors)],
            consoleCalls: $bridge->calls(),
            mechanisms: $mechanisms,
            graph: $sources->graph($root, $stack),
            tests: $sources->testLocator(),
        );
    }

    /**
     * Commands: the `bin` entries of `package.json` and the scripts running a file of the project with Node —
     * not the one starting the server (`main`), which is the application, nor a test runner.
     *
     * @param array<mixed> $package
     *
     * @return array<string, string> name => project path
     */
    private function commandFiles(ProjectRoot $root, StackProfile $stack, array $package): array
    {
        $main = \is_string($package['main'] ?? null) ? self::normalise($package['main']) : null;
        $commands = [];
        $bin = $package['bin'] ?? null;

        foreach (\is_string($bin) && \is_string($package['name'] ?? null) ? [(string) preg_replace('#^@[^/]+/#', '', $package['name']) => $bin] : (\is_array($bin) ? $bin : []) as $name => $file) {
            if (\is_string($name) && \is_string($file)) {
                $commands[$name] = self::normalise($file);
            }
        }

        foreach (\is_array($package['scripts'] ?? null) ? $package['scripts'] : [] as $name => $line) {
            if (\is_string($name) && \is_string($line) && 1 === preg_match('#^(?:node|tsx|ts-node)\s+(?:--?[\w-]+(?:=\S+)?\s+)*([^\s-]\S*\.[cm]?[jt]s)\b#', $line, $matches) && self::normalise($matches[1]) !== $main) {
                $commands[$name] ??= self::normalise($matches[1]);
            }
        }

        $existing = [];

        foreach ($commands as $name => $file) {
            try {
                $path = $root->relative($root->absolute($stack->projectPath($file)));
            } catch (PathOutsideProject) {
                continue;
            }

            if (is_file($root->absolute($path))) {
                $existing[$name] = $path;
            }
        }

        ksort($existing, \SORT_STRING);

        return $existing;
    }

    /**
     * Tests whose source holds the route's path as a literal: `request(app).get('/orders')`.
     *
     * @param list<string> $tests
     *
     * @return list<FileRef>
     */
    private function testsRequesting(ProjectRoot $root, array $tests, string $path): array
    {
        if (str_contains($path, ':') || str_contains($path, '*')) {
            return [];
        }

        $found = [];

        foreach ($tests as $test) {
            $content = (string) file_get_contents($root->absolute($test));

            if (str_contains($content, "'".$path."'") || str_contains($content, '"'.$path.'"') || str_contains($content, '`'.$path.'`')) {
                $found[] = new FileRef($test, FileRole::Test);
            }
        }

        return $found;
    }

    /**
     * @return array<mixed>
     */
    private static function package(ProjectRoot $root, StackProfile $stack): array
    {
        $file = $root->absolute($stack->projectPath('package.json'));
        $package = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return \is_array($package) ? $package : [];
    }

    private static function normalise(string $path): string
    {
        return (string) preg_replace('#^\./#', '', $path);
    }
}
