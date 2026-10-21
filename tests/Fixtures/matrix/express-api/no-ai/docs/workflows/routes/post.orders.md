# POST /orders
`route.post.orders` · type: routes · last updated: 2026-09-16 · commit: b3f961c

## Summary

—

## Trigger

| Element | Value |
|---|---|
| Entry point | `POST /orders` (`post.orders`) |
| Security | `requireAuth` |
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
