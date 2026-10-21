---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Importe une commande d'exemple.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant O as Opérateur
  participant S as import-orders.js
  O->>S: npm run import-orders
  S-->>O: fin
```

## Decisions

—

## Data

Écrit une commande en mémoire.

## Cross-cutting mechanisms

Aucun.

## Points of attention

L'import se perd à la fin du processus.

## Change

rédaction initiale
