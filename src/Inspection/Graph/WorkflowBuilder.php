<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowIdAssigner;
use Jul6Art\DevTools\Inspection\Model\WorkflowIdDeriver;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * Turns an adapter's entry points into the workflows of a PHP stack (specs § 4.3 steps 4 and 5,
 * ADR-0006): grouping, identifiers, traversed files, tests, coverage.
 *
 * Grouping is conservative: one entry point is one workflow, except several routes to the same method —
 * localised variants, one route per HTTP method — and the groups `.devtools/config.xml` declares.
 */
final readonly class WorkflowBuilder
{
    public function __construct(private Config $config)
    {
    }

    /**
     * @param list<EntryPointCandidate> $candidates
     * @param list<string>              $templateDirectories relative to the stack root
     */
    public function build(ProjectRoot $root, StackProfile $stack, array $candidates, array $templateDirectories = [], PhpReferenceExtractor $extractor = new PhpReferenceExtractor()): BuildResult
    {
        $locator = ClassLocator::for($root, $stack);
        $resolver = new DependencyResolver($root, $stack, $locator, $extractor, new TwigReferenceExtractor(), $this->config->graphDepth, $templateDirectories);
        $tests = new TestLocator($root, $stack, $locator, $extractor);
        $assigner = new WorkflowIdAssigner(new WorkflowIdDeriver($this->config->routePrefix), $this->config->aliases);

        $groups = $this->group($candidates);
        $ids = [];

        foreach ($groups as $key => $group) {
            $ids[$key] = $assigner->assign($group[0]->type, $group[0]->entryPoint);
        }

        $workflows = [];
        $warnings = [];

        foreach ($groups as $key => $group) {
            [$main, $satellites] = [$group[0], \array_slice($group, 1)];
            $files = [];
            $direct = [];
            $packages = [];

            foreach ($group as $member) {
                $methods = null === $member->method ? [] : [$member->method];
                $resolved = $resolver->resolve($member->entryPoint->declaredIn, $methods, $member->structuralFiles);
                $warnings = [...$warnings, ...$resolved->warnings];

                foreach ($resolved->files as $file) {
                    $files[$file->path] ??= $file;
                }

                foreach ($resolved->direct as $file) {
                    $direct[$file->path] ??= $file;
                }

                foreach ($resolved->packages as $package) {
                    $packages[$package->name] ??= $package;
                }
            }

            $testFiles = [];

            foreach ([...$tests->testsOf(array_values($direct)), ...array_merge(...array_map(static fn (EntryPointCandidate $member): array => $member->extraTests, $group))] as $test) {
                $testFiles[$test->path] ??= $test;
            }

            $navigation = [];

            foreach ($group as $member) {
                foreach ($member->navigation as $edge) {
                    $navigation[$edge->sortKey()] ??= $edge;
                }
            }

            $workflows[] = new Workflow(
                id: $ids[$key],
                type: $main->type,
                title: $main->title,
                main: $main->entryPoint,
                satellites: array_map(static fn (EntryPointCandidate $satellite): EntryPoint => $satellite->entryPoint, $satellites),
                files: array_values($files),
                packages: array_values($packages),
                dependsOn: $this->dependencies($ids[$key], $group, $groups, $ids),
                tests: array_values($testFiles),
                navigation: array_values($navigation),
                states: $main->states,
                confidence: $main->confidence,
                source: $main->source,
            );
        }

        return new BuildResult(
            new InspectionResult($stack->knowledgeKey ?? $stack->language, $workflows, CoverageCalculator::uncovered($root, $stack, $workflows)),
            array_values(array_unique($warnings)),
        );
    }

    /**
     * @param list<EntryPointCandidate> $candidates
     *
     * @return array<string, non-empty-list<EntryPointCandidate>> group key => main first, then satellites
     */
    private function group(array $candidates): array
    {
        usort($candidates, static fn (EntryPointCandidate $a, EntryPointCandidate $b): int => [$a->entryPoint->kind, $a->entryPoint->name] <=> [$b->entryPoint->kind, $b->entryPoint->name]);

        $byName = [];

        foreach ($candidates as $candidate) {
            $byName[$candidate->entryPoint->name] = $candidate;
        }

        // Declared groups first: they take their members out of automatic grouping.
        $groups = [];
        $taken = [];

        foreach ($this->config->groups as $main => $satellites) {
            foreach ([$main, ...$satellites] as $name) {
                if (!isset($byName[$name])) {
                    throw new InvalidModel(\sprintf('The group of "%s" in .devtools/config.xml references the entry point "%s", which does not exist.', $main, $name));
                }

                $taken[$name] = true;
            }

            $groups['declared:'.$main] = array_map(static fn (string $name): EntryPointCandidate => $byName[$name], [$main, ...$satellites]);
        }

        foreach ($candidates as $candidate) {
            if (isset($taken[$candidate->entryPoint->name])) {
                continue;
            }

            $key = null === $candidate->method
                ? 'single:'.$candidate->entryPoint->kind.':'.$candidate->entryPoint->name
                : 'method:'.$candidate->type->name.':'.$candidate->entryPoint->declaredIn->path.'::'.$candidate->method;

            $groups[$key][] = $candidate;
        }

        return $groups;
    }

    /**
     * @param non-empty-list<EntryPointCandidate>                $group
     * @param array<string, non-empty-list<EntryPointCandidate>> $groups
     * @param array<string, WorkflowId>                          $ids
     *
     * @return list<WorkflowId>
     */
    private function dependencies(WorkflowId $self, array $group, array $groups, array $ids): array
    {
        $dependencies = [];

        foreach ($group as $member) {
            foreach ($member->dependsOn as $entryPoint) {
                $id = $this->idOf($entryPoint, $groups, $ids);

                // A satellite depending on its own main is not a dependency between workflows.
                if (!$id->equals($self)) {
                    $dependencies[$id->value] = $id;
                }
            }
        }

        return array_values($dependencies);
    }

    /**
     * @param array<string, non-empty-list<EntryPointCandidate>> $groups
     * @param array<string, WorkflowId>                          $ids
     */
    private function idOf(EntryPoint $entryPoint, array $groups, array $ids): WorkflowId
    {
        foreach ($groups as $key => $group) {
            foreach ($group as $member) {
                if ($member->entryPoint->sameAs($entryPoint)) {
                    return $ids[$key];
                }
            }
        }

        throw new InvalidModel(\sprintf('A workflow depends on the entry point "%s", which no adapter reported.', $entryPoint->name));
    }
}
