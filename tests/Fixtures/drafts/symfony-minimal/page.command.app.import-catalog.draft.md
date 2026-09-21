---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Commande console d'import du catalogue : ajoute le produit « Widget XL » au dépôt des produits.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant O as Opérateur (CLI)
  participant C as ImportCatalogCommand
  participant R as ProductRepository
  O->>C: bin/console app:import-catalog
  C->>R: add(new Product('Widget XL'))
  C-->>O: code de sortie 0
```

## Decisions

—

## Data

Écrit un `Product` dans `src/Repository/ProductRepository.php`, en mémoire.

## Cross-cutting mechanisms

—

## Points of attention

Le dépôt est en mémoire et vit le temps de la commande : l'import n'a aucun effet durable. Le produit est
écrit en dur ; aucun fichier de catalogue n'est lu.

## Change

rédaction initiale
