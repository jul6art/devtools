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
        $warnings = array_filter([$locator->limitation()]);

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

            if (Config::ROUTES_BY_CONTROLLER === $this->config->routeGrouping && 'route' === $candidate->entryPoint->kind) {
                $groups['controller:'.$candidate->entryPoint->declaredIn->path][] = $candidate;

                continue;
            }

            $message = $candidate->entryPoint->attributes['message'] ?? null;

            $key = match (true) {
                // Every handler of one message is one workflow: "what happens when this message is
                // published". A project routinely has several, and their identifier is the message's
                // (ADR-0003) — without this they would collide and each need an alias by hand.
                'message-handler' === $candidate->entryPoint->kind && null !== $message => 'message:'.$candidate->type->name.':'.$message,
                null === $candidate->method => 'single:'.$candidate->entryPoint->kind.':'.$candidate->entryPoint->name,
                default => 'method:'.$candidate->type->name.':'.$candidate->entryPoint->declaredIn->path.'::'.$candidate->method,
            };

            $groups[$key][] = $candidate;
        }

        foreach ($groups as $key => $group) {
            if (str_starts_with($key, 'controller:')) {
                $groups[$key] = [self::resource($group), ...$group];
            }
        }

        return $groups;
    }

    /**
     * The workflow a controller's routes belong to: the resource they serve. Its entry point is the
     * controller itself, named after what its routes have in common (`admin_work_order`), so that adding a
     * route neither renames the workflow nor orphans its page (ADR-0003).
     *
     * @param non-empty-list<EntryPointCandidate> $routes
     */
    private static function resource(array $routes): EntryPointCandidate
    {
        $names = array_map(static fn (EntryPointCandidate $route): string => $route->entryPoint->name, $routes);
        $paths = array_map(static fn (EntryPointCandidate $route): string => $route->entryPoint->attributes['path'] ?? '', $routes);
        $path = self::commonPrefix($paths, '/');
        $path = '' === $path ? '' : '/'.$path;
        // What the route names have in common, when that says something: `app_order_new` and
        // `app_order_show` give `app_order`. Two routes sharing only `app_` say nothing, and the resource
        // is then named after its controller.
        $name = self::commonPrefix($names, '_');
        $name = 2 > \count(explode('_', $name)) ? self::controllerName($routes[0]->entryPoint->declaredIn->path) : $name;
        $states = null;

        foreach ($routes as $route) {
            $states ??= $route->states;
        }

        return new EntryPointCandidate(
            type: $routes[0]->type,
            entryPoint: new EntryPoint('resource', '' === $name ? $routes[0]->entryPoint->name : $name, $routes[0]->entryPoint->declaredIn, array_filter([
                'path' => '' === $path ? null : $path,
                'routes' => (string) \count($routes),
            ], static fn (?string $value): bool => null !== $value)),
            title: '' === $path ? $routes[0]->title : $path,
            structuralFiles: array_merge(...array_map(static fn (EntryPointCandidate $route): array => $route->structuralFiles, $routes)),
            dependsOn: array_merge(...array_map(static fn (EntryPointCandidate $route): array => $route->dependsOn, $routes)),
            states: $states,
            extraTests: array_merge(...array_map(static fn (EntryPointCandidate $route): array => $route->extraTests, $routes)),
            confidence: $routes[0]->confidence,
            source: $routes[0]->source,
        );
    }

    /**
     * `src/Controller/Admin/WorkOrderController.php` → `work_order`.
     */
    private static function controllerName(string $path): string
    {
        $class = preg_replace('/Controller$/', '', basename($path, '.php')) ?? '';

        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $class));
    }

    /**
     * What a list of names has in common, cut at a separator: `admin_work_order_new` and
     * `admin_work_order_edit` → `admin_work_order`.
     *
     * @param list<string>     $values
     * @param non-empty-string $separator
     */
    private static function commonPrefix(array $values, string $separator): string
    {
        $segments = null;

        foreach ($values as $value) {
            $parts = explode($separator, trim($value, $separator));
            $segments ??= $parts;
            $common = [];

            foreach ($segments as $index => $segment) {
                if (($parts[$index] ?? null) !== $segment) {
                    break;
                }

                $common[] = $segment;
            }

            $segments = $common;
        }

        return implode($separator, $segments ?? []);
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
