# POST /orders
`route.post-orders` · type : routes · dernière mise à jour : 2026-09-16 · commit : f2bd976

## Résumé

`POST /orders` crée une commande à partir de `customer` et `product` du corps JSON et la renvoie avec le
statut 201.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `POST /orders` (`POST /orders`) |
| Sécurité | — |
| Préconditions | Corps de requête en JSON (`Content-Type: application/json`). |

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

## Navigation / états

—

## Décisions

—

## Données

Ajoute une commande au tableau en mémoire ; l'identifiant vaut la taille du tableau plus un.

## Mécanismes transverses

`express.json()` analyse le corps avant le routeur.

## Points d'attention

Aucune validation : un corps sans `customer` crée une commande incomplète, et un corps absent lève une
erreur sur `req.body.customer`. Aucun test ne couvre la création.

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | f2bd976 | rédaction initiale |
