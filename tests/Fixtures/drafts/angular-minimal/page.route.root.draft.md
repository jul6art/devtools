---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Écran d'accueil : deux liens, vers les commandes et vers les utilisateurs.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant H as HomeComponent
  U->>H: /
  H-->>U: liens /orders et /admin/users
```

## Decisions

—

## Data

Aucune.

## Cross-cutting mechanisms

Aucun garde : l'accueil est public.

## Points of attention

Les liens mènent à des écrans gardés : un visiteur non connecté revient ici.

## Change

rédaction initiale
