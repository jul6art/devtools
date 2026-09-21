# GET /orders/{id}
`route.order.show` · type: routes · last updated: 2026-09-16 · commit: f815420

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

## Navigation / states

—

## Decisions

—

## Data

—

## Cross-cutting mechanisms

| Mechanism | Event | Priority | Writes |
|---|---|---|---|
| `App\EventListener\LocaleListener` | `kernel.request` | 20 | — |
| `App\EventListener\TotalsListener` | `prePersist` | — | — |

## Points of attention

—

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-16 | f815420 | initial |
