# CartSummary
`ui.cart-summary` · type : ui · dernière mise à jour : 2026-09-16 · commit : d3202e4

## Résumé

—

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `App\Twig\Components\CartSummary` (component) |
| Live | `true` |
| Sécurité | — |
| Préconditions | — |

## Parcours

```mermaid
flowchart TD
  n1["CartSummary"]
  n2["Composant · src/Twig/Components/CartSummary.php"]
  n3["Entité · src/Entity/Order.php"]
  n4["Repository · src/Repository/OrderRepository.php"]
  n5["Autre · src/ValueObject/Money.php"]
  n6["Template · templates/components/CartSummary.html.twig"]
  n1 --> n2
  n2 --> n3
  n2 --> n4
  n2 --> n5
  n2 --> n6
```

## Navigation / états

—

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Entité | `src/Entity/Order.php` |  |
| Repository | `src/Repository/OrderRepository.php` |  |
| Composant | `src/Twig/Components/CartSummary.php` | point d'entrée |
| Autre | `src/ValueObject/Money.php` |  |
| Template | `templates/components/CartSummary.html.twig` |  |

Paquets : `symfony/ux-live-component` 2.36.0

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
