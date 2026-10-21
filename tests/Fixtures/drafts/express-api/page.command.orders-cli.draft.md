---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Affiche les commandes en JSON, depuis la ligne de commande.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant O as Opérateur
  participant B as orders-cli
  O->>B: orders-cli
  B-->>O: JSON
```

## Decisions

—

## Data

Lit les commandes du processus, donc aucune.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Un nouveau processus n'a aucune commande en mémoire.

## Change

rédaction initiale
