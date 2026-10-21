---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Crée une commande et annonce `order.created`.

## Preconditions

Un en-tête `Authorization` (`requireAuth`).

## Journey

```mermaid
sequenceDiagram
  participant C as Client
  participant R as orders.js
  participant S as orderService
  participant B as bus
  C->>R: POST /orders
  R->>S: create(customer)
  R->>B: emit order.created
  R-->>C: 201 commande
```

## Decisions

—

## Data

Écrit une commande en mémoire.

## Cross-cutting mechanisms

`notifyWarehouse` écoute `order.created` et s'exécute pendant la requête.

## Points of attention

`requireAuth` vérifie la présence de l'en-tête, pas sa valeur.

## Change

rédaction initiale
