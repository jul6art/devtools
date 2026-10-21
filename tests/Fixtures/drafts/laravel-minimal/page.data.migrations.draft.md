---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Crée la table `orders` : identifiant, référence de 32 caractères, horodatages.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant O as Opérateur
  participant M as Migrations
  O->>M: php artisan migrate
  M-->>O: orders créée
```

## Decisions

—

## Data

Schéma de `orders`.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Aucune méthode `down()` : la migration ne se défait pas.

## Change

rédaction initiale
