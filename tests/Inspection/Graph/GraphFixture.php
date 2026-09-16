<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Graph;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Graph\EntryPointCandidate;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackDetector;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * The symfony-minimal project and the entry points a Symfony adapter would find in it — written by hand,
 * so the graph is tested before any adapter exists.
 */
final class GraphFixture
{
    public const string PROJECT = __DIR__.'/../../Fixtures/projects/symfony-minimal';

    public static function root(string $path = self::PROJECT): ProjectRoot
    {
        return new ProjectRoot($path);
    }

    public static function stack(string $path = self::PROJECT): StackProfile
    {
        return new StackDetector()->detect(self::root($path), new Config())->stacks[0];
    }

    public static function route(string $name, string $method, string $path, string $controller = 'OrderController'): EntryPointCandidate
    {
        return new EntryPointCandidate(
            type: WorkflowType::routes(),
            entryPoint: new EntryPoint('route', $name, new FileRef('src/Controller/'.$controller.'.php', FileRole::Controller), ['path' => $path]),
            title: $path,
            method: $method,
            confidence: Confidence::High,
            source: new WorkflowSource('native:symfony'),
        );
    }

    /**
     * @return list<EntryPointCandidate>
     */
    public static function candidates(): array
    {
        return [
            self::route('app_order_new', 'new', '/orders/new'),
            self::route('app_order_index', 'index', '/orders'),
            self::route('app_order_show', 'show', '/orders/{id}'),
            self::route('app_status', '__invoke', '/status', 'HealthController'),
            self::route('app_health', '__invoke', '/health', 'HealthController'),
            new EntryPointCandidate(
                type: WorkflowType::commands(),
                entryPoint: new EntryPoint('command', 'app:import-catalog', new FileRef('src/Command/ImportCatalogCommand.php')),
                title: 'app:import-catalog',
                confidence: Confidence::High,
                source: new WorkflowSource('native:symfony'),
            ),
            new EntryPointCandidate(
                type: WorkflowType::events(),
                entryPoint: new EntryPoint('listener', 'App\\EventListener\\LocaleListener', new FileRef('src/EventListener/LocaleListener.php'), ['event' => 'kernel.request']),
                title: 'kernel.request → LocaleListener',
                confidence: Confidence::High,
                source: new WorkflowSource('native:symfony'),
            ),
        ];
    }
}
