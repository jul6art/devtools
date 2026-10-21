# /
`route.root` · type: routes · last updated: 2026-09-16 · commit: e40e7e1

## Summary

—

## Trigger

| Element | Value |
|---|---|
| Entry point | `/` (`root`) |
| Security | — |
| Preconditions | — |

## Journey

—

## Decisions

**Navigation**

```mermaid
flowchart LR
  n1["root"]
  n2["admin.users"]
  n3["orders"]
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

- [`route.admin.users`](admin.users.md) — navigation
- [`route.orders`](orders.md) — navigation

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-16 | e40e7e1 | initial |
