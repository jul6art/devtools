# DELETE /admin/orders/:id
`route.delete.admin.orders.id` · type: routes · last updated: 2026-09-16 · commit: b3f961c

## Summary

—

## Trigger

| Element | Value |
|---|---|
| Entry point | `DELETE /admin/orders/:id` (`delete.admin.orders.id`) |
| Security | `requireAuth`, `requireAdmin` |
| Preconditions | — |

## Journey

—

## Decisions

—

## Data

—

## Cross-cutting mechanisms

| Mechanism | Event | Priority | Decides |
|---|---|---|---|
| `notifyWarehouse` | `order.created` | — | — |

## Points of attention

—

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-16 | b3f961c | initial |
