---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Importe les commandes d'un export CSV ; le fichier est facultatif.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant O as Opérateur
  participant C as ImportOrders::handle
  O->>C: php artisan orders:import
  C-->>O: SUCCESS
```

## Decisions

—

## Data

Compte les `Order` existantes ; n'écrit encore rien.

## Cross-cutting mechanisms

Aucun.

## Points of attention

L'import n'est pas écrit : la commande se contente de compter les commandes.

## Change

rédaction initiale
