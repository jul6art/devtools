# GET /orders/{id}
`route.order.show` · type: routes · last updated: 2026-09-16 · commit: 643805e

## Summary

—

## Trigger

| Element | Value |
|---|---|
| Entry point | `GET /orders/{id}` (`app_order_show`) |
| Security | `ROLE_USER` |
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
| `App\EventListener\LocaleListener` | `kernel.request` | 20 | — |

## Points of attention

—

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-16 | 643805e | initial |
