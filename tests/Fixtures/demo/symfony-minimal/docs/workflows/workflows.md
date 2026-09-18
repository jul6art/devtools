# Workflows — acme/symfony-minimal

Stack : Symfony 8.1 · 9 workflows · dernier scan : 2026-09-17

[Vue d'ensemble](../../.devtools/graph/workflows.mermaid) · [Stack](../../.devtools/stack.xml) · [Connaissances symfony-8](../../.devtools/knowledge/symfony-8.md)

## Routes (5)

- [GET /health](routes/health.md) — `route.health` · MAJ 2026-09-17
- [GET /orders](routes/order.index.md) — `route.order.index` · MAJ 2026-09-17
- [GET|POST /orders/new](routes/order.new.md) — `route.order.new` · MAJ 2026-09-17
- [GET /orders/{id}](routes/order.show.md) — `route.order.show` · MAJ 2026-09-17
- [POST /orders/{id}/validate](routes/order.validate.md) — `route.order.validate` · MAJ 2026-09-17

## Commandes (1)

- [app:import-catalog](commands/app.import-catalog.md) — `command.app.import-catalog` · MAJ 2026-09-17

## Asynchrone (1)

- [OrderCreated](async/order-created.md) — `async.order-created` · MAJ 2026-09-17

## Interface (1)

- [CartSummary](ui/cart-summary.md) — `ui.cart-summary` · MAJ 2026-09-17

## Données (1)

- [Migrations](data/migrations.md) — `data.migrations` · MAJ 2026-09-17

Aucun workflow trouvé pour : Intégrations.

## À vérifier

—

## Non couvert

- `src/EventListener/LocaleListener.php` — aucun workflow ne référence ce fichier
- `src/Kernel.php` — aucun workflow ne référence ce fichier
- `src/Util/StringHelper.php` — aucun workflow ne référence ce fichier
