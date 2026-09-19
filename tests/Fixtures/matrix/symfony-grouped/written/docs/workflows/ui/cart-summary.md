# CartSummary
`ui.cart-summary` · type : ui · dernière mise à jour : 2026-09-16 · commit : f815420

## Résumé

Composant live `CartSummary` : affiche le nombre de commandes et le recalcule à la demande, par l'action live
`refresh`, sans recharger la page.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `App\Twig\Components\CartSummary` (component) |
| Live | `true` |
| Sécurité | — |
| Préconditions | — |

## Parcours

```mermaid
sequenceDiagram
  participant U as Navigateur
  participant L as LiveComponent (/_components)
  participant C as CartSummary
  participant R as OrderRepository
  U->>L: action refresh
  L->>C: refresh()
  C->>R: all()
  R-->>C: list<Order>
  C-->>U: rendu de components/CartSummary.html.twig
```

## Navigation / états

—

## Décisions

—

## Données

Lit les commandes dans `src/Repository/OrderRepository.php` ; la propriété `count` est une LiveProp modifiable
côté client.

## Mécanismes transverses

Route `/_components/{_live_component}/{_live_action}` fournie par LiveComponentBundle.

## Points d'attention

`count` est `writable` : le client peut le modifier sans passer par `refresh`. Au premier rendu il vaut 0 tant
que `refresh` n'a pas été appelé.

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | f815420 | rédaction initiale |
