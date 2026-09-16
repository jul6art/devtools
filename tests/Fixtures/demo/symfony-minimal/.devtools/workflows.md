# Workflows — acme/symfony-minimal

Stack : Symfony 8.1 · 10 workflows · dernier scan : 2026-09-16

[Vue d'ensemble](graph/workflows.mermaid) · [Stack](stack.xml) · [Connaissances symfony-8](knowledge/symfony-8.md)

## Routes (5)

- [GET /health](workflows/routes/health.md) — `route.health` · MAJ 2026-09-16
- [GET /orders](workflows/routes/order.index.md) — `route.order.index` · MAJ 2026-09-16
- [GET|POST /orders/new](workflows/routes/order.new.md) — `route.order.new` · MAJ 2026-09-16
- [GET /orders/{id}](workflows/routes/order.show.md) — `route.order.show` · MAJ 2026-09-16
- [POST /orders/{id}/validate](workflows/routes/order.validate.md) — `route.order.validate` · MAJ 2026-09-16

## Commandes (1)

- [app:import-catalog](workflows/commands/app.import-catalog.md) — `command.app.import-catalog` · MAJ 2026-09-16

## Asynchrone (1)

- [OrderCreated](workflows/async/order-created.md) — `async.order-created` · MAJ 2026-09-16

## Événements (1)

- [kernel.request → LocaleListener](workflows/events/locale-listener.md) — `event.locale-listener` · MAJ 2026-09-16

## Interface (1)

- [CartSummary](workflows/ui/cart-summary.md) — `ui.cart-summary` · MAJ 2026-09-16

## Intégrations (0)

—

## Données (1)

- [Migrations](workflows/data/migrations.md) — `data.migrations` · MAJ 2026-09-16

## À vérifier

—

## Non couvert

- `src/Kernel.php` — aucun workflow ne référence ce fichier
- `src/Util/StringHelper.php` — aucun workflow ne référence ce fichier
