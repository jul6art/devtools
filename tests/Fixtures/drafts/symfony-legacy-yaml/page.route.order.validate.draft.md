---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Valide une commande : applique la transition `validate` de la machine à états `order`, qui fait passer la
commande de `draft` à `validated`, puis redirige vers sa fiche.

## Preconditions

Commande à l'état draft ; utilisateur avec ROLE_MANAGER.

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

## Change

rédaction initiale
