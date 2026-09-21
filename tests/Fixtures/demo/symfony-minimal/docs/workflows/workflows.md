# Workflows — acme/symfony-minimal

Stack: Symfony 8.1 · 9 workflows · last scan: 2026-09-17

[Overview](../../.devtools/graph/workflows.mermaid) · [Stack](../../.devtools/stack.xml) · [Knowledge symfony-8](../../.devtools/knowledge/symfony-8.md)

## Routes (5)

- [GET /health](routes/health.md) — `route.health` · MAJ 2026-09-17
- [GET /orders](routes/order.index.md) — `route.order.index` · MAJ 2026-09-17
- [GET|POST /orders/new](routes/order.new.md) — `route.order.new` · MAJ 2026-09-17
- [GET /orders/{id}](routes/order.show.md) — `route.order.show` · MAJ 2026-09-17
- [POST /orders/{id}/validate](routes/order.validate.md) — `route.order.validate` · MAJ 2026-09-17

## Commands (1)

- [app:import-catalog](commands/app.import-catalog.md) — `command.app.import-catalog` · MAJ 2026-09-17

## Async (1)

- [OrderCreated](async/order-created.md) — `async.order-created` · MAJ 2026-09-17

## UI (1)

- [CartSummary](ui/cart-summary.md) — `ui.cart-summary` · MAJ 2026-09-17

## Data (1)

- [Migrations](data/migrations.md) — `data.migrations` · MAJ 2026-09-17

No workflow found for: Intégrations.

## To check

—

## Not covered

- `src/EventListener/LocaleListener.php` — no workflow references this file
- `src/Kernel.php` — no workflow references this file
- `src/Util/StringHelper.php` — no workflow references this file
