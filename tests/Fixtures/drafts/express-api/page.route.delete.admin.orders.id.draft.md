---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Supprime une commande, réservé à l'administration.

## Preconditions

`requireAuth` au montage de `/admin`, puis `requireAdmin` sur la route.

## Journey

```mermaid
sequenceDiagram
  participant A as Administrateur
  participant R as admin.js
  participant S as orderService
  A->>R: DELETE /admin/orders/:id
  R->>S: remove(id)
  R-->>A: 204
```

## Decisions

—

## Data

Supprime une commande.

## Cross-cutting mechanisms

Deux middlewares : l'un hérité du montage, l'autre de la route.

## Points of attention

Un identifiant inconnu supprime le dernier élément : `findIndex` rend -1.

## Change

rédaction initiale
