# GET|POST /customers/{id}/edit
`route.customer.edit` · type: routes · last updated: 2026-09-16 · commit: —

## Summary

—

## Trigger

| Element | Value |
|---|---|
| Entry point | `GET\|POST /customers/{id}/edit` (`app_customer_edit`) |
| Security | `ROLE_USER`, `CUSTOMER_EDIT` |
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
| `App\EventListener\TotalsListener` | `prePersist` | — | — |
| `App\Security\CustomerVoter` | `CUSTOMER_EDIT` | — | `access` |

## Points of attention

—

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-16 | — | initial |
