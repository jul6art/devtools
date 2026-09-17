# app:import-catalog
`command.app.import-catalog` · type : commands · dernière mise à jour : 2026-09-16 · commit : b0cc0c4

## Résumé

—

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `app:import-catalog` (command) |
| Sécurité | — |
| Préconditions | — |

## Parcours

```mermaid
flowchart TD
  n1["app:import-catalog"]
  n2["Autre · src/Command/ImportCatalogCommand.php"]
  n3["Entité · src/Entity/Product.php"]
  n4["Repository · src/Repository/ProductRepository.php"]
  n5["Autre · src/ValueObject/Money.php"]
  n1 --> n2
  n2 --> n3
  n2 --> n4
  n2 --> n5
```

## Navigation / états

—

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Configuration | `config/services.yaml` |  |
| Autre | `src/Command/ImportCatalogCommand.php` | point d'entrée |
| Entité | `src/Entity/Product.php` |  |
| Repository | `src/Repository/ProductRepository.php` |  |
| Autre | `src/ValueObject/Money.php` |  |

Paquets : `symfony/console` 8.1.7

## Données

—

## Mécanismes transverses

—

## Points d'attention

—

## Tests existants

| Test | Fichier | Couvre |
|---|---|---|
| OrderPricingTest | `tests/Service/OrderPricingTest.php` | — |

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | b0cc0c4 | initial |
