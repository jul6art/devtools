# POST /orders/{id}/validate
`route.order.validate` · type : routes · dernière mise à jour : 2026-09-16 · commit : 978ffc6

## Résumé

—

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `POST /orders/{id}/validate` (`app_order_validate`) |
| Sécurité | `ROLE_USER`, `ROLE_MANAGER` |
| Préconditions | — |

## Parcours

```mermaid
flowchart TD
  n1["POST /orders/{id}/validate"]
  n2["Contrôleur · src/Controller/OrderController.php"]
  n3["Entité · src/Entity/Order.php"]
  n4["Repository · src/Repository/OrderRepository.php"]
  n5["Autre · src/ValueObject/Money.php"]
  n1 --> n2
  n2 --> n3
  n2 --> n4
  n2 --> n5
```

## Navigation / états

```mermaid
flowchart LR
  n1["app_order_validate"]
  n2["app_order_show"]
  n1 -->|"redirect"| n2
```

```mermaid
stateDiagram-v2
  state "draft" as s1
  state "validated" as s2
  state "shipped" as s3
  [*] --> s1
  s1 --> s2 : validate
  s2 --> s3 : ship
```

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Configuration | `config/packages/framework.yaml` |  |
| Configuration | `config/packages/security.yaml` |  |
| Configuration | `config/routes.yaml` |  |
| Contrôleur | `src/Controller/OrderController.php` | point d'entrée |
| Entité | `src/Entity/Order.php` |  |
| Repository | `src/Repository/OrderRepository.php` |  |
| Autre | `src/ValueObject/Money.php` |  |

Paquets : `symfony/framework-bundle` 7.4.19, `symfony/http-foundation` 7.4.19, `symfony/security-http` 7.4.19, `symfony/workflow` 7.4.9

## Données

—

## Mécanismes transverses

- [`event.locale-listener`](../events/locale-listener.md)

## Points d'attention

—

## Tests existants

—

## Workflows liés

- [`event.locale-listener`](../events/locale-listener.md) — dépend de
- [`route.order.show`](order.show.md) — navigation

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | 978ffc6 | initial |
