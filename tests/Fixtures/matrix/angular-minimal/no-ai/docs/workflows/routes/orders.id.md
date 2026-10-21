# /orders/:id
`route.orders.id` · type: routes · last updated: 2026-09-16 · commit: e40e7e1

## Summary

—

## Trigger

| Element | Value |
|---|---|
| Entry point | `/orders/:id` (`orders.id`) |
| Security | `authGuard` |
| Preconditions | — |

## Journey

—

## Decisions

**Navigation**

```mermaid
flowchart LR
  n1["orders.id"]
  n2["orders"]
  n1 -->|"navigate"| n2
```

<!-- Above: drawn by DevTools from the code. Below: written by Claude. -->

## Data

—

## Cross-cutting mechanisms

—

## Points of attention

—

## Related workflows

- [`route.orders`](orders.md) — navigation

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-16 | e40e7e1 | initial |
