<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Model;

use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\WorkflowIdDeriver;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The derivation table of ADR-0003, case by case. It is a contract with every project using
 * DevTools: changing one expected value here orphans real pages on their next scan.
 */
#[CoversClass(WorkflowIdDeriver::class)]
final class WorkflowIdDeriverTest extends TestCase
{
    /**
     * @param array<string, string> $attributes
     */
    #[DataProvider('derivations')]
    public function testItDerivesTheIdentifierFromTheEntryPoint(WorkflowType $type, string $name, array $attributes, string $expected): void
    {
        $entryPoint = new EntryPoint('any', $name, new FileRef('src/Any.php'), $attributes);

        self::assertSame($expected, (string) new WorkflowIdDeriver()->derive($type, $entryPoint));
    }

    /**
     * @return iterable<string, array{WorkflowType, string, array<string, string>, string}>
     */
    public static function derivations(): iterable
    {
        // The table of ADR-0003.
        yield 'route, prefix stripped' => [WorkflowType::routes(), 'app_order_new', [], 'route.order.new'];
        yield 'command' => [WorkflowType::commands(), 'app:import-catalog', [], 'command.app.import-catalog'];
        yield 'async, from the message class' => [WorkflowType::async(), 'App\\MessageHandler\\OrderCreatedHandler', ['message' => 'App\\Message\\OrderCreated'], 'async.order-created'];
        yield 'event' => [WorkflowType::events(), 'App\\EventListener\\LocaleListener', [], 'event.locale-listener'];
        yield 'ui' => [WorkflowType::ui(), 'App\\Twig\\Components\\CartSummary', [], 'ui.cart-summary'];
        yield 'integration' => [WorkflowType::integrations(), 'stripe', [], 'integration.stripe'];
        yield 'data' => [WorkflowType::data(), 'migrations', [], 'data.migrations'];

        // Normalisation.
        yield 'route without the prefix' => [WorkflowType::routes(), 'order_new', [], 'route.order.new'];
        yield 'route that is only the prefix keeps it' => [WorkflowType::routes(), 'app_', [], 'route.app'];
        yield 'route with dots' => [WorkflowType::routes(), 'api.orders.list', [], 'route.api.orders.list'];
        yield 'double underscore' => [WorkflowType::routes(), 'app_order__new', [], 'route.order.new'];
        yield 'uppercase' => [WorkflowType::routes(), 'app_Order_NEW', [], 'route.order.new'];
        yield 'accents' => [WorkflowType::routes(), 'app_commande_créée_à_l_été', [], 'route.commande.creee.a.l.ete'];
        yield 'ligatures and eszett' => [WorkflowType::integrations(), 'Œuvre Straße', [], 'integration.oeuvre-strasse'];
        yield 'other characters become hyphens' => [WorkflowType::commands(), 'app:import catalog (full)', [], 'command.app.import-catalog-full'];
        yield 'acronym in a class name' => [WorkflowType::events(), 'App\\EventListener\\HTTPCacheListener', [], 'event.http-cache-listener'];
        yield 'digits in a class name' => [WorkflowType::async(), 'App\\Handler', ['message' => 'App\\Message\\Oauth2TokenRefreshed'], 'async.oauth2-token-refreshed'];
        yield 'async without message attribute' => [WorkflowType::async(), 'App\\Scheduler\\NightlyReport', [], 'async.nightly-report'];
        yield 'custom type' => [WorkflowType::custom('webhooks', 'webhook'), 'stripe:payment_succeeded', [], 'webhook.stripe.payment.succeeded'];
    }

    public function testTheRoutePrefixIsConfigurable(): void
    {
        $entryPoint = new EntryPoint('route', 'admin_user_list', new FileRef('src/Controller/UserController.php'));

        self::assertSame('route.user.list', (string) new WorkflowIdDeriver('admin_')->derive(WorkflowType::routes(), $entryPoint));
        self::assertSame('route.admin.user.list', (string) new WorkflowIdDeriver('')->derive(WorkflowType::routes(), $entryPoint));
    }

    public function testANameWithNothingUsableIsRefusedWithTheEntryPointNamed(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('"___"');

        new WorkflowIdDeriver()->derive(WorkflowType::routes(), new EntryPoint('route', '___', new FileRef('src/X.php')));
    }
}
