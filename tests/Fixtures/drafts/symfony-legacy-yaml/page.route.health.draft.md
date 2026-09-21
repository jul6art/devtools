---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Point de santé de l'application, joignable sur `/health` et sur `/status` : répond `{"status": "ok"}` en JSON,
sans authentification, pour la supervision.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant S as Supervision
  participant C as HealthController
  S->>C: GET /health (ou /status)
  C-->>S: 200 {"status": "ok"}
```

## Decisions

—

## Data

Aucune donnée lue ni écrite.

## Cross-cutting mechanisms

`LocaleListener` sur `kernel.request`, sans effet sur une réponse JSON ; aucune règle d'accès.

## Points of attention

Répond « ok » sans rien vérifier (ni dépendance, ni stockage) : il dit que PHP répond, pas que l'application
fonctionne.

## Change

rédaction initiale
