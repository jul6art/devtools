---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Liste des commandes, chacune avec son statut, chargée depuis l'API.

## Preconditions

Être connecté (`authGuard`).

## Journey

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant L as OrderListComponent
  participant S as OrderService
  participant A as API
  U->>L: /orders
  L->>S: list()
  S->>A: GET /api/orders
  A-->>L: commandes
```

## Decisions

—

## Data

Lit les commandes de l'API.

## Cross-cutting mechanisms

`authGuard`, hérité de la route parente `orders`.

## Points of attention

Le badge de statut est partagé avec le détail : le modifier change les deux écrans.

## Change

rédaction initiale
