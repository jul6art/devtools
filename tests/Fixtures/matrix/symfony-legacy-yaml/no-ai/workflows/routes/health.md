# GET /health
`route.health` · type : routes · dernière mise à jour : 2026-09-16 · commit : 978ffc6

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

```mermaid
flowchart TD
  n1["GET /health"]
  n2["Contrôleur · src/Controller/HealthController.php"]
  n1 --> n2
```

## Navigation / états

—

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Configuration | `config/packages/security.yaml` |  |
| Configuration | `config/routes.yaml` |  |
| Contrôleur | `src/Controller/HealthController.php` | point d'entrée |

Paquets : `symfony/http-foundation` 7.4.19

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
| 2026-09-16 | 978ffc6 | initial |
