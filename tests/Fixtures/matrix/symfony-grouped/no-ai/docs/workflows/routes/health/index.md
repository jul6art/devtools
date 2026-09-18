# GET /health
`route.health` · type : routes · dernière mise à jour : 2026-09-16 · commit : d15a772

## Résumé

—

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `GET /health` (`app_health`) |
| Satellite | `GET /status` (`app_status`) |
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
| `App\EventListener\LocaleListener` | `kernel.request` | 20 | — |

## Points d'attention

—

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | d15a772 | initial |
