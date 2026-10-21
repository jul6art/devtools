---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Affiche une commande, retrouvée par sa clé grâce à la liaison de modèle de la route.

## Preconditions

Être connecté et autorisé par `OrderPolicy::view`.

## Journey

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant C as OrderController::show
  U->>C: GET /orders/{order}
  C-->>U: view('orders.show')
```

## Decisions

—

## Data

Lit une `Order`.

## Cross-cutting mechanisms

`can:view,order` consulte `OrderPolicy::view` avec la commande liée.

## Points of attention

Une clé inconnue répond 404 avant même le contrôleur.

## Change

rédaction initiale
