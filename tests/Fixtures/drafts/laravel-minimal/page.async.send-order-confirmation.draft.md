---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Envoie la confirmation d'une commande, en file d'attente.

## Preconditions

Un worker `queue:work` tourne.

## Journey

```mermaid
sequenceDiagram
  participant C as OrderController::store
  participant Q as File d'attente
  participant J as SendOrderConfirmation
  C->>Q: dispatch
  Q->>J: handle()
```

## Decisions

—

## Data

Recharge l'`Order` sérialisée par son identifiant.

## Cross-cutting mechanisms

Aucun.

## Points of attention

`handle()` est vide : aucune confirmation n'est encore envoyée.

## Change

rédaction initiale
