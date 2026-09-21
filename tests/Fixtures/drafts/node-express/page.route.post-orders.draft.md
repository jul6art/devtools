---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

`POST /orders` crée une commande à partir de `customer` et `product` du corps JSON et la renvoie avec le
statut 201.

## Preconditions

Corps de requête en JSON (`Content-Type: application/json`).

## Journey

```mermaid
sequenceDiagram
  participant C as Client HTTP
  participant R as routes/orders.js
  participant S as orderService
  C->>R: POST /orders {customer, product}
  R->>S: create(customer, product)
  S-->>R: {id, customer, product}
  R-->>C: 201 JSON
```

## Decisions

—

## Data

Ajoute une commande au tableau en mémoire ; l'identifiant vaut la taille du tableau plus un.

## Cross-cutting mechanisms

`express.json()` analyse le corps avant le routeur.

## Points of attention

Aucune validation : un corps sans `customer` crée une commande incomplète, et un corps absent lève une
erreur sur `req.body.customer`. Aucun test ne couvre la création.

## Change

rédaction initiale
