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

—

## Navigation / états

—

## Décisions

—

## Données

—

## Mécanismes transverses

| Mécanisme | Événement | Priorité | Écrit |
|---|---|---|---|
| `App\EventListener\TotalsListener` | `prePersist` | — | — |

## Points d'attention

—

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | — | initial |
