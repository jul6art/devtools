---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Résumé

`GET /orders` renvoie en JSON toutes les commandes connues de `orderService`.

## Préconditions

—

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

## Données

Lit le tableau `orders` gardé en mémoire par `src/services/orderService.js`.

## Mécanismes transverses

`express.json()` est monté sur toute l'application avant les routeurs.

## Points d'attention

Le stockage est en mémoire : la liste est vide à chaque redémarrage et n'est pas partagée entre processus.

## Changement

rédaction initiale
