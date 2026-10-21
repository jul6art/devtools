---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Écran des rapports, chargé depuis un chemin calculé.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant R as Routeur
  U->>R: /reports
  R->>R: import(reports)
```

## Decisions

—

## Data

Aucune.

## Cross-cutting mechanisms

Aucun garde.

## Points of attention

Le chemin du composant est une variable : DevTools ne peut pas dire quel fichier sert l'écran.

## Change

rédaction initiale
