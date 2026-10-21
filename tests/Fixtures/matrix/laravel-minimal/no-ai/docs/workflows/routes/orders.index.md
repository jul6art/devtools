# GET /orders
`route.orders.index` · type: routes · last updated: 2026-09-16 · commit: a8254ce

## Summary

—

## Trigger

| Element | Value |
|---|---|
| Entry point | `GET /orders` (`orders.index`) |
| Security | `auth` |
| Preconditions | — |

## Journey

—

## Decisions

**Navigation**

```mermaid
flowchart LR
  n1["orders.index"]
  n2["orders.show"]
  n3["orders.store"]
  n1 -->|"link"| n2
  n1 -->|"link"| n3
```

<!-- Above: drawn by DevTools from the code. Below: written by Claude. -->

## Data

—

## Cross-cutting mechanisms

—

## Points of attention

—

## Related workflows

- [`route.orders.show`](orders.show.md) — navigation
- [`route.orders.store`](orders.store.md) — navigation

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-16 | a8254ce | initial |
