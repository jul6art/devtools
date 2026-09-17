<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Rendering;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Rendering\RenderingContext;
use Jul6Art\DevTools\Tests\Inspection\Model\ModelFixtures;
use Jul6Art\DevTools\Tracking\Revision;

final class RenderingFixtures
{
    /**
     * One workflow of each native type, written by hand, plus the route of ModelFixtures.
     *
     * @return array<string, Workflow>
     */
    public static function workflows(): array
    {
        return [
            'routes' => ModelFixtures::orderNewWorkflow(),
            'commands' => ModelFixtures::commandWorkflow(),
            'async' => self::simple('async.order-created', WorkflowType::async(), 'message-handler', 'App\MessageHandler\OrderCreatedHandler', 'src/MessageHandler/OrderCreatedHandler.php', FileRole::Handler, ['message' => 'App\Message\OrderCreated']),
            'events' => self::simple('event.locale-listener', WorkflowType::events(), 'listener', 'App\EventListener\LocaleListener', 'src/EventListener/LocaleListener.php', FileRole::Listener, ['events' => 'kernel.request']),
            'ui' => self::simple('ui.cart-summary', WorkflowType::ui(), 'component', 'App\Twig\Components\CartSummary', 'src/Twig/Components/CartSummary.php', FileRole::Component, ['live' => 'true']),
            'integrations' => self::simple('integration.stripe', WorkflowType::integrations(), 'integration', 'stripe', 'src/Payment/StripeClient.php', FileRole::Service),
            'data' => self::simple('data.migrations', WorkflowType::data(), 'migration', 'migrations', 'migrations/Version20260901000000.php', FileRole::Other),
        ];
    }

    /**
     * The same workflow, reaching more files.
     *
     * @param list<FileRef> $files
     */
    public static function withFiles(Workflow $workflow, array $files): Workflow
    {
        return new Workflow(
            $workflow->id,
            $workflow->type,
            $workflow->title,
            $workflow->main,
            $workflow->satellites,
            [...$workflow->files, ...$files],
            $workflow->packages,
            $workflow->dependsOn,
            $workflow->tests,
            $workflow->navigation,
            $workflow->states,
            $workflow->confidence,
            $workflow->source,
        );
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function simple(string $id, WorkflowType $type, string $kind, string $name, string $file, FileRole $role, array $attributes = []): Workflow
    {
        return new Workflow(
            id: new WorkflowId($id),
            type: $type,
            title: $name,
            main: new EntryPoint($kind, $name, new FileRef($file, $role), $attributes),
            files: [new FileRef($file, $role)],
            confidence: Confidence::High,
            source: new WorkflowSource('native:symfony'),
        );
    }

    public static function context(): RenderingContext
    {
        $workflows = array_values(self::workflows());
        $workflows[] = new Workflow(
            id: new WorkflowId('route.order.show'),
            type: WorkflowType::routes(),
            title: 'GET /orders/{id}',
            main: new EntryPoint('route', 'app_order_show', new FileRef('src/Controller/OrderController.php', FileRole::Controller)),
        );

        return RenderingContext::of($workflows);
    }

    /**
     * @return list<Revision>
     */
    public static function history(): array
    {
        return [
            new Revision(new \DateTimeImmutable('2026-08-02T10:01:00+02:00'), '9f8e7d6c5b4a39281706f5e4d3c2b1a098765432', 'initial'),
            new Revision(new \DateTimeImmutable('2026-09-16T14:22:31+02:00'), 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', 'ajout du service de pricing'),
        ];
    }
}
