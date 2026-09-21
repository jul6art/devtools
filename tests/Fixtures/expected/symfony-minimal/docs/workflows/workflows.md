# Workflows — acme/symfony-minimal

Stack: Symfony 8.1 · 9 workflows · last scan: 2026-09-16

[Overview](../../.devtools/graph/workflows.mermaid) · [Stack](../../.devtools/stack.xml) · [Knowledge symfony-8](../../.devtools/knowledge/symfony-8.md)

## Routes (5)

- [GET /health](routes/health.md) — `route.health` · updated 2026-09-16
- [GET /orders](routes/order.index.md) — `route.order.index` · updated 2026-09-16
- [GET|POST /orders/new](routes/order.new.md) — `route.order.new` · updated 2026-09-16
- [GET /orders/{id}](routes/order.show.md) — `route.order.show` · updated 2026-09-16
- [POST /orders/{id}/validate](routes/order.validate.md) — `route.order.validate` · updated 2026-09-16

## Commands (1)

- [app:import-catalog](commands/app.import-catalog.md) — `command.app.import-catalog` · updated 2026-09-16

## Async (1)

- [OrderCreated](async/order-created.md) — `async.order-created` · updated 2026-09-16

## UI (1)

- [CartSummary](ui/cart-summary.md) — `ui.cart-summary` · updated 2026-09-16

## Data (1)

- [Migrations](data/migrations.md) — `data.migrations` · updated 2026-09-16

No workflow found for: Integrations.

## To check

—

## Not covered

- `src/EventListener/LocaleListener.php` — no workflow references this file
- `src/EventListener/TotalsListener.php` — no workflow references this file
- `src/Kernel.php` — no workflow references this file
- `src/Util/StringHelper.php` — no workflow references this file
