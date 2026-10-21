---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Compte les commandes chaque nuit à 2 h.

## Preconditions

Le module est chargé par le processus.

## Journey

```mermaid
sequenceDiagram
  participant C as node-cron
  participant J as nightlyReport
  C->>J: 0 2 * * *
  J-->>C: log
```

## Decisions

—

## Data

Lit les commandes.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Sans chargement du module, rien ne se planifie.

## Change

rédaction initiale
