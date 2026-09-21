---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

`GET /orders` renvoie en JSON toutes les commandes connues de `orderService`.

## Preconditions

—

## Journey

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

## Decisions

—

## Data

Lit le tableau `orders` gardé en mémoire par `src/services/orderService.js`.

## Cross-cutting mechanisms

`express.json()` est monté sur toute l'application avant les routeurs.

## Points of attention

Le stockage est en mémoire : la liste est vide à chaque redémarrage et n'est pas partagée entre processus.

## Change

rédaction initiale
