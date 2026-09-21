---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Liste les commandes existantes. La page affiche le résumé du panier (composant live `CartSummary`), un lien de
création et, pour chaque commande, un lien vers sa fiche.

## Preconditions

Utilisateur authentifié (ROLE_USER, imposé par access_control sur ^/orders).

## Journey

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant C as OrderController::index
  participant R as OrderRepository
  participant T as order/index.html.twig
  U->>C: GET /orders
  C->>R: all()
  R-->>C: list<Order>
  C->>T: render(orders)
  T-->>U: liste, liens app_order_new et app_order_show
```

## Decisions

—

## Data

Lit toutes les `Order` du dépôt en mémoire `src/Repository/OrderRepository.php`. N'écrit rien.

## Cross-cutting mechanisms

`LocaleListener` sur `kernel.request` ; access_control ROLE_USER sur `^/orders`.

## Points of attention

Pas de pagination : le dépôt renvoie toutes les commandes. Le composant `CartSummary` rendu dans la page a son
propre workflow (`ui.cart-summary`).

## Change

rédaction initiale
