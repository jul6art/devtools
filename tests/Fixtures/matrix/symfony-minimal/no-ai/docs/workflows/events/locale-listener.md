# kernel.request → LocaleListener
`event.locale-listener` · type : events · dernière mise à jour : 2026-09-16 · commit : 6753625

## Résumé

—

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `App\EventListener\LocaleListener` (listener) |
| Events | `kernel.request` |
| Sécurité | — |
| Préconditions | — |

## Parcours

```mermaid
flowchart TD
  n1["kernel.request → LocaleListener"]
  n2["Listener · src/EventListener/LocaleListener.php"]
  n1 --> n2
```

## Navigation / états

—

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Configuration | `config/services.yaml` |  |
| Listener | `src/EventListener/LocaleListener.php` | point d'entrée |

Paquets : `symfony/event-dispatcher` 8.1.5, `symfony/http-kernel` 8.1.7

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
| 2026-09-16 | 6753625 | initial |
