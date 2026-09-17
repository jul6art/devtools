# OrderCreated
`async.order-created` · type : async · dernière mise à jour : 2026-09-16 · commit : d3202e4

## Résumé

—

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `App\MessageHandler\OrderCreatedHandler` (message-handler) |
| Message | `App\Message\OrderCreated` |
| Sécurité | — |
| Préconditions | — |

## Parcours

```mermaid
flowchart TD
  n1["OrderCreated"]
  n2["Entité · src/Entity/Order.php"]
  n3["Message · src/Message/OrderCreated.php"]
  n4["Handler · src/MessageHandler/OrderCreatedHandler.php"]
  n5["Repository · src/Repository/OrderRepository.php"]
  n6["Autre · src/ValueObject/Money.php"]
  n1 --> n4
  n4 --> n2
  n4 --> n3
  n4 --> n5
  n4 --> n6
```

## Navigation / états

—

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Configuration | `config/packages/framework.yaml` |  |
| Entité | `src/Entity/Order.php` |  |
| Message | `src/Message/OrderCreated.php` |  |
| Handler | `src/MessageHandler/OrderCreatedHandler.php` | point d'entrée |
| Repository | `src/Repository/OrderRepository.php` |  |
| Autre | `src/ValueObject/Money.php` |  |

## Données

—

## Mécanismes transverses

—

## Points d'attention

—

## Tests existants

—

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | d3202e4 | initial |
