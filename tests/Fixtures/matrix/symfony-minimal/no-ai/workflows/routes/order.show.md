# GET /orders/{id}
`route.order.show` · type : routes · dernière mise à jour : 2026-09-16 · commit : b0cc0c4

## Résumé

—

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `GET /orders/{id}` (`app_order_show`) |
| Sécurité | `ROLE_USER` |
| Préconditions | — |

## Parcours

```mermaid
flowchart TD
  n1["GET /orders/{id}"]
  n2["Contrôleur · src/Controller/OrderController.php"]
  n3["Entité · src/Entity/Order.php"]
  n4["Repository · src/Repository/OrderRepository.php"]
  n5["Autre · src/ValueObject/Money.php"]
  n6["Template · templates/base.html.twig"]
  n7["Template · templates/order/show.html.twig"]
  n1 --> n2
  n2 --> n3
  n2 --> n4
  n2 --> n5
  n2 --> n6
  n2 --> n7
```

## Navigation / états

—

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Configuration | `config/packages/security.yaml` |  |
| Configuration | `config/routes.yaml` |  |
| Configuration | `config/routes/ux_live_component.yaml` |  |
| Configuration | `config/services.yaml` |  |
| Contrôleur | `src/Controller/OrderController.php` | point d'entrée |
| Entité | `src/Entity/Order.php` |  |
| Repository | `src/Repository/OrderRepository.php` |  |
| Autre | `src/ValueObject/Money.php` |  |
| Template | `templates/base.html.twig` |  |
| Template | `templates/order/show.html.twig` |  |

Paquets : `symfony/framework-bundle` 8.1.7, `symfony/http-foundation` 8.1.7, `symfony/routing` 8.1.6

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

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | b0cc0c4 | initial |
