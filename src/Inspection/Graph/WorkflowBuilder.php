<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowGroup;
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
     * @param list<MechanismAttachment> $mechanisms          listeners and filters to attach (ADR-0043)
     */
    public function build(ProjectRoot $root, StackProfile $stack, array $candidates, array $templateDirectories = [], PhpReferenceExtractor $extractor = new PhpReferenceExtractor(), array $mechanisms = []): BuildResult
    {
        $decisions = new DecisionExtractor($extractor);
        $locator = ClassLocator::for($root, $stack);
        $resolver = new DependencyResolver($root, $stack, $locator, $extractor, new TwigReferenceExtractor(), $this->config->graphDepth, $templateDirectories);
        $tests = new TestLocator($root, $stack, $locator, $extractor);
        $assigner = new WorkflowIdAssigner(new WorkflowIdDeriver($this->config->routePrefix), $this->config->aliases);

        $groups = $this->group($candidates);
        $ids = [];

        foreach ($groups as $key => $group) {
            $ids[$key] = $assigner->assign($group[0]->type, $group[0]->entryPoint);
        }

        // ⚠️ A deriver of its own, never the assigner: a group's directory is not an identifier handed out
        // for this scan, and asking the assigner for it would make a single-route controller collide with
        // its own route.
        $presentation = $this->presentationGroups($groups, $ids, new WorkflowIdDeriver($this->config->routePrefix));

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

            $attached = array_values(array_map(
                static fn (MechanismAttachment $attachment): Mechanism => $attachment->mechanism,
                array_filter($mechanisms, static fn (MechanismAttachment $attachment): bool => $attachment->applies($main->type, array_values($files), $main->states)),
            ));

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
                decisions: self::decisionsOf($root, $decisions, $group, array_values($files)),
                mechanisms: $attached,
                states: $main->states,
                confidence: $main->confidence,
                source: $main->source,
                group: $presentation[$key] ?? null,
            );
        }

        return new BuildResult(
            new InspectionResult($stack->knowledgeKey ?? $stack->language, $workflows, CoverageCalculator::uncovered($root, $stack, $workflows)),
            array_values(array_unique($warnings)),
        );
    }

    /**
     * Where this workflow decides a field's value: the entry point's own methods, then every file it
     * traverses that is neither configuration nor a test — those declare, they do not decide.
     *
     * @param non-empty-list<EntryPointCandidate> $group
     * @param list<FileRef>                       $files
     *
     * @return list<DecisionPoint>
     */
    private static function decisionsOf(ProjectRoot $root, DecisionExtractor $extractor, array $group, array $files): array
    {
        $scoped = [];

        foreach ($group as $member) {
            $path = $member->entryPoint->declaredIn->path;
            $scoped[$path] = [...$scoped[$path] ?? [], ...null === $member->method ? [] : [$member->method]];
        }

        $points = [];

        foreach ($files as $file) {
            if (\in_array($file->role, [FileRole::Config, FileRole::Test, FileRole::Template], true) || !str_ends_with($file->path, '.php')) {
                continue;
            }

            foreach ($extractor->extract($root->absolute($file), $file, array_values(array_unique($scoped[$file->path] ?? []))) as $point) {
                $points[$point->sortKey()] ??= $point;
            }

            if (\count($points) >= DecisionExtractor::MAX_POINTS) {
                break;
            }
        }

        return self::richest(DecisionPoint::branching(array_values($points)));
    }

    /**
     * The {@see DecisionExtractor::MAX_TARGETS} fields whose value depends on the most: a page carrying a
     * diagram for every entity setter a route reaches is the inventory ADR-0043 removed.
     *
     * @param list<DecisionPoint> $points
     *
     * @return list<DecisionPoint>
     */
    private static function richest(array $points): array
    {
        $counts = [];

        foreach ($points as $point) {
            $counts[$point->target] = ($counts[$point->target] ?? 0) + 1;
        }

        // Most branches first, then by name: two scans of an unchanged project keep the same fields.
        uksort($counts, static fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);
        $kept = \array_slice(array_keys($counts), 0, DecisionExtractor::MAX_TARGETS);

        return \array_slice(
            array_values(array_filter($points, static fn (DecisionPoint $point): bool => \in_array($point->target, $kept, true))),
            0,
            DecisionExtractor::MAX_POINTS,
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

        return $groups;
    }

    /**
     * The entry point a controller's routes have in common — the resource they serve — from which the
     * group's directory and title are derived (ADR-0045).
     *
     * It is named after what the route names have in common (`admin_work_order`), so that adding a route
     * neither renames the group nor moves the pages of its siblings (ADR-0003).
     *
     * @param non-empty-list<EntryPointCandidate> $routes
     */
    private static function resource(array $routes): EntryPoint
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

        unset($states);

        return new EntryPoint('resource', '' === $name ? $routes[0]->entryPoint->name : $name, $routes[0]->entryPoint->declaredIn, array_filter([
            'path' => '' === $path ? null : $path,
            'routes' => (string) \count($routes),
        ], static fn (?string $value): bool => null !== $value));
    }

    /**
     * The groups the pages are laid out in, when `<routes group="controller"/>` asks for them (ADR-0045):
     * one per controller, derived from the routes it declares.
     *
     * ⚠️ The grouping is a matter of PRESENTATION only: the workflows are the same ones `entry-point`
     * builds, with the same identifiers. A group owns a directory and a title, never a workflow.
     *
     * @param array<string, non-empty-list<EntryPointCandidate>> $groups group key => members, main first
     * @param array<string, WorkflowId>                          $ids    group key => workflow identifier
     *
     * @return array<string, WorkflowGroup> group key => the group its page is written in
     */
    private function presentationGroups(array $groups, array $ids, WorkflowIdDeriver $deriver): array
    {
        if (Config::ROUTES_BY_CONTROLLER !== $this->config->routeGrouping) {
            return [];
        }

        $byController = [];

        foreach ($groups as $key => $group) {
            if ('route' !== $group[0]->entryPoint->kind) {
                continue;
            }

            $byController[$group[0]->entryPoint->declaredIn->path][$key] = $group[0];
        }

        $result = [];

        foreach ($byController as $path => $mains) {
            $routes = array_values($mains);
            $resource = self::resource($routes);
            $directory = $deriver->derive($routes[0]->type, $resource)->pageName();
            $keys = array_keys($mains);

            // ⚠️ Two workflows of one controller may not write the same file. It happens when a route is
            // named exactly like what the others have in common (`admin_user` next to `admin_user_index`):
            // both would claim `index.md`. The controller's own name then becomes the directory, and every
            // page keeps its whole identifier — a corner, handled rather than hoped away.
            if (self::collides($directory, $keys, $ids)) {
                $directory = $deriver->derive($routes[0]->type, new EntryPoint('resource', self::controllerName($path), $routes[0]->entryPoint->declaredIn))->pageName();
            }

            $states = null;

            foreach ($routes as $route) {
                $states ??= $route->states;
            }

            $group = new WorkflowGroup($directory, $resource->attributes['path'] ?? $routes[0]->title, $routes[0]->entryPoint->declaredIn, $states);

            foreach ($keys as $key) {
                $result[$key] = $group;
            }
        }

        return $result;
    }

    /**
     * @param list<string>              $keys
     * @param array<string, WorkflowId> $ids
     */
    private static function collides(string $directory, array $keys, array $ids): bool
    {
        $leaves = array_map(static fn (string $key): string => WorkflowGroup::leaf($directory, $ids[$key]), $keys);

        return \count($leaves) !== \count(array_unique($leaves));
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
