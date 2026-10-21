---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Client HTTP des commandes : liste, détail et annulation.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant S as OrderService
  participant A as API
  S->>A: GET /api/orders
  S->>A: GET /api/orders/:id
  S->>A: POST /api/orders/:id/cancel
```

## Decisions

—

## Data

Les commandes de l'API, sous `environment.apiUrl`.

## Cross-cutting mechanisms

Aucun intercepteur.

## Points of attention

Les observables sont froids : sans abonnement, rien n'est envoyé.

## Change

rédaction initiale
