# OrderCreated
`async.order-created` · type: async · last updated: 2026-09-16 · commit: —

## Summary

—

## Trigger

| Element | Value |
|---|---|
| Entry point | `App\MessageHandler\NotifyOnOrderCreated` (message-handler) |
| Satellite | `App\MessageHandler\OrderCreatedHandler` (message-handler) |
| Message | `App\Message\OrderCreated` |
| Security | — |
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
| `App\EventListener\TotalsListener` | `prePersist` | — | — |

## Points of attention

—

## Related workflows

—

## History

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | — | initial |
