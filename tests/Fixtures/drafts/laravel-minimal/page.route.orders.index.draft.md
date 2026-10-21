---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Liste des commandes, des plus récentes aux plus anciennes, avec un formulaire de création.

## Preconditions

Être connecté (`auth`).

## Journey

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant C as OrderController::index
  participant M as Order
  U->>C: GET /orders
  C->>M: latest()->get()
  C-->>U: view('orders.index')
```

## Decisions

—

## Data

Lit toutes les `Order`, sans pagination.

## Cross-cutting mechanisms

`auth` redirige un visiteur non connecté vers la connexion.

## Points of attention

Sans pagination, la page grossit avec la table `orders`.

## Change

rédaction initiale
