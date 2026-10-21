---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Liste des utilisateurs, chargée paresseusement avec les routes d'administration.

## Preconditions

Être connecté : `canMatch` empêche même le chargement du module.

## Journey

```mermaid
sequenceDiagram
  participant U as Administrateur
  participant R as Routeur
  participant L as UserListComponent
  U->>R: /admin/users
  R->>R: import admin.routes
  R->>L: affiche
```

## Decisions

—

## Data

Aucune.

## Cross-cutting mechanisms

`authGuard` en `canMatch` : sans connexion, la table d'administration n'est jamais téléchargée.

## Points of attention

Hors ligne, le premier accès échoue : le morceau de code n'est pas encore chargé.

## Change

rédaction initiale
