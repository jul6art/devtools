<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Angular;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterInterface;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Adapter\JavaScript\JavaScriptSources;
use Jul6Art\DevTools\Inspection\Graph\EntryPointCandidate;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\RoleGuesser;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Inspection\Node\NodeBridge;
use Jul6Art\DevTools\Inspection\Node\NodeBridgeFailed;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * The Angular adapter (ADR-0032), on the Node bridge (ADR-0031): the route tables `provideRouter()` and
 * `RouterModule.forRoot()` receive, followed through their children and their lazy loading; the components
 * two screens share; the services that call an API through `HttpClient`. Ionic's tabs are Angular routes.
 */
final readonly class AngularAdapter implements AdapterInterface
{
    private const array ROUTER = ['provideRouter', 'forRoot', 'forChild'];

    private const array HTTP = ['get', 'post', 'put', 'patch', 'delete', 'request', 'head'];

    private const array NAVIGATION = ['navigate', 'navigateByUrl'];

    public function __construct(private ?NodeBridge $bridge = null)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'angular';
    }

    #[\Override]
    public function extract(ProjectRoot $root, StackProfile $stack, Config $config, PhpReferenceExtractor $extractor): AdapterResult
    {
        $bridge = $this->bridge ?? JavaScriptSources::bridge($config);

        try {
            $sources = JavaScriptSources::read($bridge, $root, $stack, ['src'], [...self::ROUTER, ...self::HTTP, ...self::NAVIGATION]);
        } catch (NodeBridgeFailed $failure) {
            return JavaScriptSources::claudePath($root, $stack, $config, $extractor, $failure->getMessage(), $bridge->calls());
        }

        $reading = new AngularReading($root, $sources->facts);
        $routes = $reading->routes($sources->files);
        $source = new WorkflowSource('native:angular');
        $candidates = [];

        foreach ($routes as $route) {
            $navigation = null === $route['component'] ? [] : $this->navigation($reading, $route['component'], $routes, $route['name']);
            $candidates[] = new EntryPointCandidate(
                type: WorkflowType::routes(),
                entryPoint: new EntryPoint('route', $route['name'], new FileRef($route['component'] ?? $route['declaredIn'], RoleGuesser::guess($route['component'] ?? $route['declaredIn'])), array_filter(['path' => $route['path'], 'security' => implode(', ', $route['guards'])], static fn (string $value): bool => '' !== $value)),
                title: $route['path'],
                structuralFiles: array_map(static fn (string $path): FileRef => new FileRef($path, str_ends_with($path, '.routes.ts') || str_ends_with($path, 'app.config.ts') ? FileRole::Config : RoleGuesser::guess($path)), $route['structural']),
                navigation: $navigation,
                confidence: $route['literal'] ? Confidence::High : Confidence::Medium,
                source: $source,
            );
        }

        $screens = array_values(array_filter(array_column($routes, 'component'), \is_string(...)));

        foreach ($reading->sharedComponents($screens) as $component) {
            $candidates[] = new EntryPointCandidate(WorkflowType::ui(), new EntryPoint('component', $component['name'], new FileRef($component['file'], FileRole::Component), ['selector' => $component['selector']]), $component['class'], confidence: Confidence::High, source: $source);
        }

        foreach ($reading->httpServices($sources->files) as $service) {
            $candidates[] = new EntryPointCandidate(WorkflowType::integrations(), new EntryPoint('http-client', $service['name'], new FileRef($service['file'], FileRole::Service), [] === $service['endpoints'] ? [] : ['endpoints' => implode(', ', $service['endpoints'])]), $service['class'], confidence: Confidence::High, source: $source);
        }

        return new AdapterResult(
            $candidates,
            warnings: [...$reading->warnings(), ...array_map(static fn (array $error): string => $error['file'].' cannot be read: '.$error['message'], $sources->facts->errors)],
            consoleCalls: $bridge->calls(),
            graph: $sources->graph($root, $stack),
            tests: $sources->testLocator(),
        );
    }

    /**
     * The routes a screen links to: its template's `routerLink`s and its `router.navigate()` calls, each
     * matched against the route table — `['/orders', order.id]` is `/orders/:id`.
     *
     * @param list<array{name: string, path: string}> $routes
     *
     * @return list<Edge>
     */
    private function navigation(AngularReading $reading, string $component, array $routes, string $self): array
    {
        $edges = [];

        foreach ($reading->links($component) as [$segments, $label]) {
            foreach ($routes as $route) {
                if (AngularReading::matches($route['path'], $segments) && $route['name'] !== $self) {
                    $edges[$route['name'].'|'.$label] = new Edge($route['name'], $label);
                }
            }
        }

        ksort($edges, \SORT_STRING);

        return array_values($edges);
    }
}
