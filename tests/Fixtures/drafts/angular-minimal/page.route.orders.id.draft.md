---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Détail d'une commande et son annulation, après laquelle l'écran revient à la liste.

## Preconditions

Être connecté (`authGuard`).

## Journey

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant D as OrderDetailComponent
  participant S as OrderService
  U->>D: /orders/:id
  D->>S: get(id)
  U->>D: Annuler
  D->>S: cancel(id)
  D-->>U: navigate /orders
```

## Decisions

—

## Data

Lit une commande, l'annule par l'API.

## Cross-cutting mechanisms

`authGuard`, hérité de la route parente.

## Points of attention

L'annulation n'est pas confirmée : un clic suffit.

## Change

rédaction initiale
