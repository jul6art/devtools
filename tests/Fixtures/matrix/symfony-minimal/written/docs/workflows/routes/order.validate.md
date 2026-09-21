# POST /orders/{id}/validate
`route.order.validate` · type: routes · last updated: 2026-09-16 · commit: f815420

## Summary

Valide une commande : applique la transition `validate` de la machine à états `order`, qui fait passer la
commande de `draft` à `validated`, puis redirige vers sa fiche.

## Trigger

| Element | Value |
|---|---|
| Entry point | `POST /orders/{id}/validate` (`app_order_validate`) |
| Security | `ROLE_USER`, `ROLE_MANAGER` |
| Preconditions | Commande à l'état draft ; utilisateur avec ROLE_MANAGER. |

## Journey

```mermaid
sequenceDiagram
  participant U as Manager
  participant C as OrderController::validate
  participant R as OrderRepository
  participant W as WorkflowInterface (order)
  U->>C: POST /orders/{id}/validate
  C->>R: get(id)
  R-->>C: Order
  C->>W: apply(order, 'validate')
  W-->>C: status = validated
  C-->>U: redirect app_order_show
```

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

Lit l'`Order` et modifie sa propriété `status` (marking store `method`) ; la machine est déclarée dans
`config/packages/framework.yaml`.

## Cross-cutting mechanisms

`LocaleListener` sur `kernel.request` ; access_control ROLE_USER, puis `#[IsGranted]` ROLE_MANAGER.

## Points of attention

La commande modifiée n'est pas ré-enregistrée (`save` n'est pas appelé). Une transition impossible (commande
déjà validée) lève une exception non interceptée : réponse 500 au lieu d'un message.

## Related workflows

- [`route.order.show`](order.show.md) — navigation

## History

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | f815420 | rédaction initiale |
