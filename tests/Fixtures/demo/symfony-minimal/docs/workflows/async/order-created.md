# OrderCreated
`async.order-created` · type: async · last updated: 2026-09-17 · commit: —

## Summary

Traite le message `OrderCreated` : le handler recharge la commande désignée par `orderId` depuis le dépôt.

## Trigger

| Element | Value |
|---|---|
| Entry point | `App\MessageHandler\NotifyOnOrderCreated` (message-handler) |
| Satellite | `App\MessageHandler\OrderCreatedHandler` (message-handler) |
| Message | `App\Message\OrderCreated` |
| Security | — |
| Preconditions | Un message OrderCreated est distribué sur le bus (transport sync). |

## Journey

```mermaid
sequenceDiagram
  participant B as MessageBus (sync)
  participant H as OrderCreatedHandler
  participant R as OrderRepository
  B->>H: __invoke(OrderCreated)
  H->>R: get(orderId)
  R-->>H: Order ou null
```

## Navigation / states

—

## Decisions

**`App\Entity\Order::status`**

```mermaid
flowchart TD
  d1{"la commande n'a pas de client"}
  d1 -->|oui| v1["status = draft"]
  d1 -->|non| d2{"le produit n'a pas de prix"}
  d2 -->|oui| v2["status = quoted"]
  d2 -->|non| v3["status = priced"]
```

**`App\Entity\Order::currency`**

```mermaid
flowchart TD
  d1{"pays de la commande"}
  d1 -->|CH| v1["currency = CHF"]
  d1 -->|GB| v2["currency = GBP"]
  d1 -->|tout autre pays| v3["currency = EUR"]
```

## Data

Lit une `Order` dans `src/Repository/OrderRepository.php` ; le routage vers le transport `sync` est déclaré
dans `config/packages/framework.yaml`.

## Cross-cutting mechanisms

—

## Points of attention

Le handler ne fait rien du résultat, et aucun code du projet ne distribue `OrderCreated` : ce workflow n'est
aujourd'hui jamais déclenché.

## Related workflows

—

## History

| Date | Commit | Changement |
|---|---|---|
| 2026-09-17 | — | rédaction initiale |
