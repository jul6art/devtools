---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Page « À propos », statique, rendue par un contrôleur invocable.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant U as Visiteur
  participant C as AboutController
  U->>C: GET /about
  C-->>U: View::make('about')
```

## Decisions

—

## Data

Aucune.

## Cross-cutting mechanisms

Groupe `web`.

## Points of attention

La route n'a pas de nom : son identifiant vient de sa méthode et de son URI, et changera avec elle.

## Change

rédaction initiale
