# app:import-catalog
`command.app.import-catalog` · type: commands · last updated: 2026-09-16 · commit: 65429e2

## Summary

Commande console d'import du catalogue : ajoute le produit « Widget XL » au dépôt des produits.

## Trigger

| Element | Value |
|---|---|
| Entry point | `app:import-catalog` (command) |
| Security | — |
| Preconditions | — |

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

## Navigation / states

—

## Decisions

—

## Data

Écrit un `Product` dans `src/Repository/ProductRepository.php`, en mémoire.

## Cross-cutting mechanisms

—

## Points of attention

Le dépôt est en mémoire et vit le temps de la commande : l'import n'a aucun effet durable. Le produit est
écrit en dur ; aucun fichier de catalogue n'est lu.

## Related workflows

—

## History

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | 65429e2 | rédaction initiale |
