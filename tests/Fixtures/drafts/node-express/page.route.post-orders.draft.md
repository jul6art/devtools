---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Résumé

`POST /orders` crée une commande à partir de `customer` et `product` du corps JSON et la renvoie avec le
statut 201.

## Préconditions

Corps de requête en JSON (`Content-Type: application/json`).

## Parcours

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

## Décisions

—

## Données

Ajoute une commande au tableau en mémoire ; l'identifiant vaut la taille du tableau plus un.

## Mécanismes transverses

`express.json()` analyse le corps avant le routeur.

## Points d'attention

Aucune validation : un corps sans `customer` crée une commande incomplète, et un corps absent lève une
erreur sur `req.body.customer`. Aucun test ne couvre la création.

## Changement

rédaction initiale
