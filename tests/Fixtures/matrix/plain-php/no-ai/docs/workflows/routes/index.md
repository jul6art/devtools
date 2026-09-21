# /index.php
`route.index` · type: routes · last updated: 2026-09-16 · commit: 2620449

## Summary

—

## Trigger

| Element | Value |
|---|---|
| Entry point | `/index.php` (`index`) |
| Security | — |
| Preconditions | — |

## Journey

—

## Navigation / states

```mermaid
flowchart LR
  n1["index"]
  n2["orders.new"]
  n3["orders.new"]
  n1 -->|"form"| n2
  n1 -->|"link"| n3
```

## Decisions

—

## Data

—

## Cross-cutting mechanisms

—

## Points of attention

—

## Related workflows

- [`route.orders.new`](orders.new.md) — navigation

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-16 | 2620449 | initial |
