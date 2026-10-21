---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Peuple la base avec une commande d'exemple, `A-1`.

## Preconditions

Les migrations sont passées.

## Journey

```mermaid
sequenceDiagram
  participant O as Opérateur
  participant S as DatabaseSeeder
  O->>S: php artisan db:seed
  S-->>O: Order A-1
```

## Decisions

—

## Data

Écrit une `Order`.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Relancé, il crée une seconde commande `A-1`.

## Change

rédaction initiale
