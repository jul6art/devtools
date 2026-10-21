---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Liste les commandes en JSON.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant C as Client
  participant R as orders.js
  participant S as orderService
  C->>R: GET /orders
  R->>S: list()
  R-->>C: 200 [commandes]
```

## Decisions

—

## Data

Lit les commandes en mémoire.

## Cross-cutting mechanisms

`express.json()` sur toute l'application.

## Points of attention

Les commandes vivent en mémoire : un redémarrage les perd.

## Change

rédaction initiale
