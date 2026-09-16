<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Claude;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterInterface;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Graph\CoverageCalculator;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\RoleGuesser;
use Jul6Art\DevTools\Inspection\Graph\SourceFiles;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\Serialization\ModelXmlSerializer;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * The path for a stack without a native adapter (ADR-0013): Claude discovers its entry points once, DevTools
 * reads that discovery on every later scan — and only asks Claude again about source files nobody has seen.
 */
final class ClaudeDrivenAdapter implements AdapterInterface
{
    #[\Override]
    public function name(): string
    {
        return 'claude';
    }

    #[\Override]
    public function extract(ProjectRoot $root, StackProfile $stack, Config $config, PhpReferenceExtractor $extractor): AdapterResult
    {
        $file = Discovery::file(new DevToolsDirectory($root), Discovery::slug($stack));

        if (!is_file($file)) {
            return new AdapterResult([], workflows: [], discovery: []);
        }

        $discovered = new ModelXmlSerializer(new WorkflowTypeRegistry($config->customTypes))->deserialize((string) file_get_contents($file), $file);
        $exists = static fn (FileRef $ref): bool => is_file($root->absolute($ref));
        $workflows = [];
        $known = [];

        foreach ($discovered->workflows as $workflow) {
            foreach ([...$workflow->files, ...$workflow->tests, $workflow->main->declaredIn] as $ref) {
                $known[$ref->path] = true;
            }

            // An entry point whose file is gone is gone: the freshness of the scan orphans its workflow.
            if (!$exists($workflow->main->declaredIn)) {
                continue;
            }

            $workflows[] = new Workflow(
                id: $workflow->id,
                type: $workflow->type,
                title: $workflow->title,
                main: $workflow->main,
                satellites: array_values(array_filter($workflow->satellites, static fn (EntryPoint $satellite): bool => $exists($satellite->declaredIn))),
                files: array_values(array_filter($workflow->files, $exists)),
                packages: $workflow->packages,
                dependsOn: $workflow->dependsOn,
                tests: array_values(array_filter($workflow->tests, $exists)),
                navigation: $workflow->navigation,
                states: $workflow->states,
                confidence: $workflow->confidence,
                source: $workflow->source,
            );
        }

        foreach ($discovered->uncovered as $ref) {
            $known[$ref->path] = true;
        }

        $newFiles = array_values(array_filter(
            SourceFiles::in($root, $stack, $stack->sourceDirs, CoverageCalculator::CODE_EXTENSIONS),
            static fn (string $path): bool => !isset($known[$path]),
        ));

        $uncovered = [
            ...array_values(array_filter($discovered->uncovered, $exists)),
            ...array_map(static fn (string $path): FileRef => new FileRef($path, RoleGuesser::guess($path)), $newFiles),
        ];

        return new AdapterResult([], workflows: $workflows, uncovered: $uncovered, discovery: [] === $newFiles ? null : $newFiles);
    }
}
