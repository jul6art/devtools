# Migrations
`data.migrations` · type : data · dernière mise à jour : 2026-09-16 · commit : 65429e2

## Résumé

Migrations de la base : une seule version, `Version20260901000000`, dont la méthode `up()` est vide.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `migrations` (migration) |
| Sécurité | — |
| Préconditions | — |

## Parcours

```mermaid
flowchart TD
  A[doctrine:migrations:migrate] --> B[Version20260901000000::up]
```

## Navigation / états

—

## Décisions

—

## Données

Aucune modification de schéma.

## Mécanismes transverses

—

## Points d'attention

Le projet n'installe pas Doctrine Migrations : ce fichier n'est exécuté par rien. Il est documenté parce qu'il
se trouve dans `migrations/`.

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | 65429e2 | rédaction initiale |
