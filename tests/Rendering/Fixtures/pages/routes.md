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

—

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

## Décisions

—

## Données

—

## Mécanismes transverses

| Mécanisme | Événement | Priorité | Écrit |
|---|---|---|---|
| `App\EventListener\LocaleListener` | `kernel.request` | 16 | — |

## Points d'attention

—

## Workflows liés

- `route.order.index` — dépend de
- [`route.order.show`](order.show.md) — dépend de

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-08-02 | 9f8e7d6 | initial |
| 2026-09-16 | a1b2c3d | ajout du service de pricing |
