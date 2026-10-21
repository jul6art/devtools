---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Rend une commande par son identifiant.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant C as Client
  participant R as orders.js
  participant S as orderService
  C->>R: GET /orders/:id
  R->>S: find(id)
  R-->>C: 200 commande
```

## Decisions

—

## Data

Lit une commande.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Un identifiant inconnu répond 200 avec un corps vide, pas 404.

## Change

rédaction initiale
