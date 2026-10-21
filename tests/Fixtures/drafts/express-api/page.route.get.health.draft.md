---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Signale que le processus répond.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant M as Supervision
  participant A as app.js
  M->>A: GET /health
  A-->>M: 200 {status: ok}
```

## Decisions

—

## Data

Aucune.

## Cross-cutting mechanisms

Déclarée sur l'application, hors de tout routeur.

## Points of attention

Ne vérifie aucune dépendance.

## Change

rédaction initiale
