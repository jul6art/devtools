# Order creation
`route.order.new` · type : routes · dernière mise à jour : 2026-09-16 · commit : a1b2c3d

## Résumé

—

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `GET\|POST /orders/new` (`app_order_new`) |
| Satellite | `GET\|POST /orders/new` (`app_order_new_localized`) |
| Sécurité | `ROLE_OPERATOR` |
| Préconditions | — |

## Parcours

```mermaid
flowchart TD
  n1["Order creation"]
  n2["Contrôleur · src/Controller/OrderController.php"]
  n3["Formulaire · src/Form/OrderType.php"]
  n4["Template · templates/order/new.html.twig"]
  n1 --> n2
  n2 --> n3
  n2 --> n4
```

## Navigation / états

```mermaid
flowchart LR
  n1["app_order_new"]
  n2["app_order_index"]
  n3["app_order_show"]
  n1 -->|"link"| n2
  n1 -->|"redirect"| n3
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
| Contrôleur | `src/Controller/OrderController.php` | point d'entrée |
| Formulaire | `src/Form/OrderType.php` |  |
| Template | `templates/order/new.html.twig` |  |

Paquets : `doctrine/orm` 3.5.2, `symfony/form` 7.4.3

## Données

—

## Mécanismes transverses

- [`event.locale-listener`](../events/locale-listener.md)

## Points d'attention

—

## Tests existants

| Test | Fichier | Couvre |
|---|---|---|
| OrderControllerTest | `tests/Controller/OrderControllerTest.php` | — |

## Workflows liés

- [`event.locale-listener`](../events/locale-listener.md) — dépend de
- `route.order.index` — dépend de
- [`route.order.show`](order.show.md) — navigation

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-08-02 | 9f8e7d6 | initial |
| 2026-09-16 | a1b2c3d | ajout du service de pricing |
