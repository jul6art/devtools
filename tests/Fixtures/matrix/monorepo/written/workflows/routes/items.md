# /items
`route.items` · type : routes · dernière mise à jour : 2026-09-16 · commit : a8278df

## Résumé

Page `/items` du front Angular : la route rend `ItemListComponent`, qui n'affiche pour l'instant qu'un titre.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `/items` (`items`) |
| Sécurité | — |
| Préconditions | — |

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

## Navigation / états

—

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Configuration | `front/src/app/app.routes.ts` | point d'entrée |
| Composant | `front/src/app/item-list.component.ts` |  |

## Données

Aucune : le composant ne charge rien.

## Mécanismes transverses

Aucun garde ni intercepteur n'est déclaré dans `front/src/app`.

## Points d'attention

Le composant n'utilise pas `GET /api/items` de l'application `api` : la page est un squelette.

## Tests existants

—

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | a8278df | rédaction initiale |
