---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Résumé

Liste les commandes : `OrderListComponent` charge toutes les commandes via `OrderService.list()` et
affiche pour chacune le nom du client, avec un lien vers sa fiche `/orders/:id`.

## Préconditions

L'API `/api/orders` est joignable depuis le navigateur (même origine ou proxy de développement).

## Parcours

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant L as OrderListComponent
  participant S as OrderService
  participant A as API
  U->>L: navigue vers /orders
  L->>S: list()
  S->>A: GET /api/orders
  A-->>S: Order[]
  S-->>L: orders$
  L-->>U: un lien par commande vers /orders/:id
```

## Décisions

—

## Données

Lit des `Order` (`id`, `customer`) depuis l'API HTTP ; aucune écriture.

## Mécanismes transverses

Aucun intercepteur HTTP ni garde de route n'est déclaré dans `src/app`.

## Points d'attention

Aucun état de chargement ni de gestion d'erreur : si l'API échoue, la liste reste vide sans message.

## Changement

rédaction initiale
