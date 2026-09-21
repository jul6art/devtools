---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Affiche une commande : `OrderDetailComponent` lit l'identifiant dans l'URL et charge la commande via
`OrderService.get(id)`, puis affiche le nom du client.

## Preconditions

La commande existe côté API ; on arrive en général depuis la liste `/orders`.

## Journey

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant D as OrderDetailComponent
  participant S as OrderService
  participant A as API
  U->>D: navigue vers /orders/:id
  D->>S: get(Number(id))
  S->>A: GET /api/orders/{id}
  A-->>S: Order
  S-->>D: order$
  D-->>U: nom du client
```

## Decisions

—

## Data

Lit une `Order` (`id`, `customer`) depuis l'API HTTP ; aucune écriture.

## Cross-cutting mechanisms

Aucun intercepteur HTTP ni garde de route n'est déclaré dans `src/app`.

## Points of attention

L'identifiant est lu dans le snapshot de la route : naviguer d'une fiche à une autre sans recréer le
composant n'actualise pas la commande. Un identifiant non numérique donne `NaN` et appelle `/api/orders/NaN`.

## Change

rédaction initiale
