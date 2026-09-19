# GET /orders/{id}
`route.order.show` · type : routes · dernière mise à jour : 2026-09-16 · commit : f815420

## Résumé

—

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `GET /orders/{id}` (`app_order_show`) |
| Sécurité | `ROLE_USER` |
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
| `App\EventListener\LocaleListener` | `kernel.request` | 20 | — |
| `App\EventListener\TotalsListener` | `prePersist` | — | — |

## Points d'attention

—

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | f815420 | initial |
