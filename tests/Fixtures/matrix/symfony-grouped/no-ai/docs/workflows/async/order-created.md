# OrderCreated
`async.order-created` · type: async · last updated: 2026-09-16 · commit: 4290ff5

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

## Decisions

—

## Data

—

## Cross-cutting mechanisms

| Mechanism | Event | Priority | Decides |
|---|---|---|---|
| `App\EventListener\TotalsListener` | `prePersist` | — | — |

## Points of attention

—

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-16 | 4290ff5 | initial |
