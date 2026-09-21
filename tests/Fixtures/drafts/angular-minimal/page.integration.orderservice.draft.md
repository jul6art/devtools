---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

`OrderService` est le seul point d'accès aux commandes : un service Angular fourni à la racine qui
appelle l'API REST des commandes avec `HttpClient`.

## Preconditions

`HttpClient` est fourni à l'application (`provideHttpClient`).

## Journey

```mermaid
sequenceDiagram
  participant C as Composant
  participant S as OrderService
  participant A as API
  C->>S: list() / get(id)
  S->>A: GET /api/orders ou /api/orders/{id}
  A-->>S: JSON
  S-->>C: Observable<Order[]> / Observable<Order>
```

## Decisions

—

## Data

Lit `Order` (`id: number`, `customer: string`) ; l'interface est déclarée dans le service.

## Cross-cutting mechanisms

Aucun : ni intercepteur, ni cache, ni reprise sur erreur.

## Points of attention

Les réponses ne sont pas validées : le typage `Order` est une promesse, pas un contrôle.

## Change

rédaction initiale
