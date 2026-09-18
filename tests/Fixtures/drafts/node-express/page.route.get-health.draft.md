---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Résumé

`GET /health` répond `{"status": "ok"}` pour signaler que le processus tourne.

## Préconditions

—

## Parcours

```mermaid
sequenceDiagram
  participant M as Supervision
  participant A as app.js
  M->>A: GET /health
  A-->>M: 200 {"status": "ok"}
```

## Décisions

—

## Données

Aucune.

## Mécanismes transverses

Déclarée directement sur `app`, hors de tout routeur.

## Points d'attention

La réponse ne vérifie aucune dépendance : elle reste « ok » même si le service de commandes est cassé.

## Changement

rédaction initiale
