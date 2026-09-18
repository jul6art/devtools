# OrderCreated
`async.order-created` · type : async · dernière mise à jour : 2026-09-16 · commit : 65429e2

## Résumé

Traite le message `OrderCreated` : le handler recharge la commande désignée par `orderId` depuis le dépôt.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `App\MessageHandler\OrderCreatedHandler` (message-handler) |
| Message | `App\Message\OrderCreated` |
| Sécurité | — |
| Préconditions | Un message OrderCreated est distribué sur le bus (transport sync). |

## Parcours

```mermaid
sequenceDiagram
  participant B as MessageBus (sync)
  participant H as OrderCreatedHandler
  participant R as OrderRepository
  B->>H: __invoke(OrderCreated)
  H->>R: get(orderId)
  R-->>H: Order ou null
```

## Navigation / états

—

## Décisions

—

## Données

Lit une `Order` dans `src/Repository/OrderRepository.php` ; le routage vers le transport `sync` est déclaré
dans `config/packages/framework.yaml`.

## Mécanismes transverses

—

## Points d'attention

Le handler ne fait rien du résultat, et aucun code du projet ne distribue `OrderCreated` : ce workflow n'est
aujourd'hui jamais déclenché.

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | 65429e2 | rédaction initiale |
