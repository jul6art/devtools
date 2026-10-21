---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Crée une commande à partir de sa référence, annonce `OrderPlaced` et met en file l'envoi de la confirmation.

## Preconditions

Être connecté et autorisé par `OrderPolicy::create`.

## Journey

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant C as OrderController::store
  participant M as Order
  participant Q as File d'attente
  U->>C: POST /orders
  C->>M: Order::create(validated)
  C->>C: OrderPlaced::dispatch
  C->>Q: SendOrderConfirmation::dispatch
  C-->>U: redirect orders.show
```

## Decisions

—

## Data

Écrit une `Order` ; la référence est obligatoire, 32 caractères au plus.

## Cross-cutting mechanisms

`NotifyWarehouse` écoute `OrderPlaced` et s'exécute pendant la requête, avant la redirection.

## Points of attention

Une référence invalide renvoie au formulaire avec les erreurs, sans rien écrire.

## Change

rédaction initiale
