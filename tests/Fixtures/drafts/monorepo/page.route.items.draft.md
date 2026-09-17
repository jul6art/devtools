---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Résumé

Page `/items` du front Angular : la route rend `ItemListComponent`, qui n'affiche pour l'instant qu'un titre.

## Préconditions

—

## Parcours

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant R as Router
  participant L as ItemListComponent
  U->>R: navigue vers /items
  R->>L: rend le composant
  L-->>U: « Items »
```

## Données

Aucune : le composant ne charge rien.

## Mécanismes transverses

Aucun garde ni intercepteur n'est déclaré dans `front/src/app`.

## Points d'attention

Le composant n'utilise pas `GET /api/items` de l'application `api` : la page est un squelette.

## Changement

rédaction initiale
