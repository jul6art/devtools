# OrderCreated
`async.order-created` · type : async · dernière mise à jour : 2026-09-16 · commit : —

## Résumé

—

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `App\MessageHandler\NotifyOnOrderCreated` (message-handler) |
| Satellite | `App\MessageHandler\OrderCreatedHandler` (message-handler) |
| Message | `App\Message\OrderCreated` |
| Sécurité | — |
| Préconditions | — |

## Parcours

```mermaid
flowchart TD
  n1["OrderCreated"]
  n2["Handler · src/MessageHandler/NotifyOnOrderCreated.php"]
  n3["Entité · src/Entity/Order.php"]
  n4["Entité · src/Entity/Product.php"]
  n5["Message · src/Message/OrderCreated.php"]
  n6["Handler · src/MessageHandler/OrderCreatedHandler.php"]
  n7["Repository · src/Repository/OrderRepository.php"]
  n8["Repository · src/Repository/ProductRepository.php"]
  n9["Service · src/Service/OrderPricing.php"]
  n10["Autre · src/ValueObject/Money.php"]
  n1 --> n2
  n2 --> n3
  n2 --> n4
  n2 --> n5
  n2 --> n6
  n2 --> n7
  n2 --> n8
  n2 --> n9
  n2 --> n10
```

## Navigation / états

—

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Configuration | `config/packages/framework.yaml` |  |
| Configuration | `config/services.yaml` |  |
| Entité | `src/Entity/Order.php` |  |
| Entité | `src/Entity/Product.php` |  |
| Message | `src/Message/OrderCreated.php` |  |
| Handler | `src/MessageHandler/NotifyOnOrderCreated.php` | point d'entrée |
| Handler | `src/MessageHandler/OrderCreatedHandler.php` |  |
| Repository | `src/Repository/OrderRepository.php` |  |
| Repository | `src/Repository/ProductRepository.php` |  |
| Service | `src/Service/OrderPricing.php` |  |
| Autre | `src/ValueObject/Money.php` |  |

Paquets : `symfony/messenger` 8.1.7

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
| 2026-09-16 | — | initial |
