---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Traite la file BullMQ `emails`.

## Preconditions

Un Redis joignable.

## Journey

```mermaid
sequenceDiagram
  participant Q as File emails
  participant W as emailWorker
  Q->>W: job
  W-->>Q: terminé
```

## Decisions

—

## Data

Aucune.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Le worker démarre au chargement du module.

## Change

rédaction initiale
