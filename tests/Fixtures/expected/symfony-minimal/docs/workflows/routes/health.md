# GET /health
`route.health` · type: routes · last updated: 2026-09-16 · commit: —

## Summary

—

## Trigger

| Element | Value |
|---|---|
| Entry point | `GET /health` (`app_health`) |
| Satellite | `GET /status` (`app_status`) |
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
| `App\EventListener\LocaleListener` | `kernel.request` | 20 | — |

## Points of attention

—

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-16 | — | initial |
