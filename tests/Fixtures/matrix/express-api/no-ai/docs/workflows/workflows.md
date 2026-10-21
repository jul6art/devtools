# Workflows — acme-orders-service

Stack: Express 5.3 · 9 workflows · last scan: 2026-09-16 (commit b3f961c)

[Overview](../../.devtools/graph/workflows.mermaid) · [Stack](../../.devtools/stack.xml) · [Knowledge express-5](../../.devtools/knowledge/express-5.md)

## Routes (5)

- [DELETE /admin/orders/:id](routes/delete.admin.orders.id.md) — `route.delete.admin.orders.id` · updated 2026-09-16
- [GET /health](routes/get.health.md) — `route.get.health` · updated 2026-09-16
- [GET /orders](routes/get.orders.md) — `route.get.orders` · updated 2026-09-16
- [GET /orders/:id](routes/get.orders.id.md) — `route.get.orders.id` · updated 2026-09-16
- [POST /orders](routes/post.orders.md) — `route.post.orders` · updated 2026-09-16

## Commands (2)

- [import-orders](commands/import-orders.md) — `command.import-orders` · updated 2026-09-16
- [orders-cli](commands/orders-cli.md) — `command.orders-cli` · updated 2026-09-16

## Async (2)

- [emails (BullMQ)](async/emails.md) — `async.emails` · updated 2026-09-16
- [nightly-report (0 2 * * *)](async/nightly-report.md) — `async.nightly-report` · updated 2026-09-16

No workflow found for: UI, Integrations, Data.

## To check

—

## Not covered

- `src/listeners/notifyWarehouse.js` — no workflow references this file
- `src/middleware/auth.js` — no workflow references this file
- `src/server.js` — no workflow references this file
