# POST /orders/{id}/validate
`route.order.validate` · type: routes · last updated: 2026-09-16 · commit: f815420

## Summary

—

## Trigger

| Element | Value |
|---|---|
| Entry point | `POST /orders/{id}/validate` (`app_order_validate`) |
| Security | `ROLE_USER`, `ROLE_MANAGER` |
| Preconditions | — |

## Journey

—

## Navigation / states

```mermaid
flowchart LR
  n1["app_order_validate"]
  n2["app_order_show"]
  n1 -->|"redirect"| n2
```

```mermaid
stateDiagram-v2
  state "draft" as s1
  state "validated" as s2
  state "shipped" as s3
  [*] --> s1
  s1 --> s2 : validate
  s2 --> s3 : ship
```

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

- [`route.order.show`](order.show.md) — navigation

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-16 | f815420 | initial |
