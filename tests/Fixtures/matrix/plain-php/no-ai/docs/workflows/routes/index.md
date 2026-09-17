# /index.php
`route.index` · type : routes · dernière mise à jour : 2026-09-16 · commit : 2620449

## Résumé

—

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `/index.php` (`index`) |
| Sécurité | — |
| Préconditions | — |

## Parcours

```mermaid
flowchart TD
  n1["/index.php"]
  n2["Repository · lib/OrderRepository.php"]
  n3["Autre · lib/db.php"]
  n4["Autre · lib/views/header.php"]
  n5["Contrôleur · public/index.php"]
  n1 --> n5
  n5 --> n2
  n5 --> n3
  n5 --> n4
```

## Navigation / états

```mermaid
flowchart LR
  n1["index"]
  n2["orders.new"]
  n3["orders.new"]
  n1 -->|"form"| n2
  n1 -->|"link"| n3
```

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Repository | `lib/OrderRepository.php` |  |
| Autre | `lib/db.php` |  |
| Autre | `lib/views/header.php` |  |
| Contrôleur | `public/index.php` | point d'entrée |

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

- [`route.orders.new`](orders.new.md) — navigation

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | 2620449 | initial |
