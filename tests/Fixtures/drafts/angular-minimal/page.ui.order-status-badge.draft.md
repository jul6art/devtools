---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Badge qui affiche le statut d'une commande, partagé par la liste et le détail.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant E as Écran
  participant B as OrderStatusBadgeComponent
  E->>B: [status]
  B-->>E: <span class=badge>
```

## Decisions

—

## Data

Aucune : reçoit le statut en entrée.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Le statut est affiché brut, sans traduction.

## Change

rédaction initiale
