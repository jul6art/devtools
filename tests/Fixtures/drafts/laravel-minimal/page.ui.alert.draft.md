---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Composant Blade d'alerte, typé (`info` par défaut).

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant V as Vue
  participant A as Alert
  V->>A: <x-alert type="success"/>
  A-->>V: components.alert
```

## Decisions

—

## Data

Aucune.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Le contenu vient du slot : une alerte sans contenu reste vide.

## Change

rédaction initiale
