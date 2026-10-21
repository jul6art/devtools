---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Lance `orders:import` chaque jour, par le planificateur de `routes/console.php`.

## Preconditions

Un cron lance `schedule:run` chaque minute.

## Journey

```mermaid
sequenceDiagram
  participant S as Planificateur
  participant C as orders:import
  S->>C: daily()
  C-->>S: SUCCESS
```

## Decisions

—

## Data

Celles de `orders:import`.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Sans cron sur le serveur, la tâche ne s'exécute jamais, sans erreur.

## Change

rédaction initiale
