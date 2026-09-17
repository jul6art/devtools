# /orders/new.php
`route.orders.new` · type : routes · dernière mise à jour : 2026-09-16 · commit : 2620449

## Résumé

—

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `/orders/new.php` (`orders.new`) |
| Sécurité | — |
| Préconditions | — |

## Parcours

```mermaid
flowchart TD
  n1["/orders/new.php"]
  n2["Repository · lib/OrderRepository.php"]
  n3["Autre · lib/db.php"]
  n4["Contrôleur · public/orders/new.php"]
  n1 --> n4
  n4 --> n2
  n4 --> n3
```

## Navigation / états

```mermaid
flowchart LR
  n1["orders.new"]
  n2["index"]
  n3["index"]
  n1 -->|"link"| n2
  n1 -->|"redirect"| n3
```

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Repository | `lib/OrderRepository.php` |  |
| Autre | `lib/db.php` |  |
| Contrôleur | `public/orders/new.php` | point d'entrée |

## Données

—

## Mécanismes transverses

—

## Points d'attention

—

## Tests existants

| Test | Fichier | Couvre |
|---|---|---|
| OrderRepositoryTest | `tests/OrderRepositoryTest.php` | — |

## Workflows liés

- [`route.index`](index.md) — navigation

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | 2620449 | initial |
