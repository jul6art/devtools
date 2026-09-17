# GET /orders
`route.get-orders` · type : routes · dernière mise à jour : 2026-09-16 · commit : 0ad6cf2

## Résumé

`GET /orders` renvoie en JSON toutes les commandes connues de `orderService`.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `GET /orders` (`GET /orders`) |
| Sécurité | — |
| Préconditions | — |

## Parcours

```mermaid
sequenceDiagram
  participant C as Client HTTP
  participant A as app.js
  participant R as routes/orders.js
  participant S as orderService
  C->>A: GET /orders
  A->>R: router monté sur /orders
  R->>S: list()
  S-->>R: orders[]
  R-->>C: 200 JSON
```

## Navigation / états

—

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Configuration | `src/app.js` |  |
| Contrôleur | `src/routes/orders.js` | point d'entrée |
| Service | `src/services/orderService.js` |  |

## Données

Lit le tableau `orders` gardé en mémoire par `src/services/orderService.js`.

## Mécanismes transverses

`express.json()` est monté sur toute l'application avant les routeurs.

## Points d'attention

Le stockage est en mémoire : la liste est vide à chaque redémarrage et n'est pas partagée entre processus.

## Tests existants

| Test | Fichier | Couvre |
|---|---|---|
| orders.test.js | `test/orders.test.js` | — |

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | 0ad6cf2 | rédaction initiale |
