# GET /orders
`route.order.index` · type : routes · dernière mise à jour : 2026-09-16 · commit : b0cc0c4

## Résumé

Liste les commandes existantes. La page affiche le résumé du panier (composant live `CartSummary`), un lien de
création et, pour chaque commande, un lien vers sa fiche.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `GET /orders` (`app_order_index`) |
| Sécurité | `ROLE_USER` |
| Préconditions | Utilisateur authentifié (ROLE_USER, imposé par access_control sur ^/orders). |

## Parcours

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant C as OrderController::index
  participant R as OrderRepository
  participant T as order/index.html.twig
  U->>C: GET /orders
  C->>R: all()
  R-->>C: list<Order>
  C->>T: render(orders)
  T-->>U: liste, liens app_order_new et app_order_show
```

## Navigation / états

```mermaid
flowchart LR
  n1["app_order_index"]
  n2["app_order_new"]
  n3["app_order_show"]
  n1 -->|"link"| n2
  n1 -->|"link"| n3
```

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Configuration | `config/packages/security.yaml` |  |
| Configuration | `config/routes.yaml` |  |
| Configuration | `config/routes/ux_live_component.yaml` |  |
| Configuration | `config/services.yaml` |  |
| Contrôleur | `src/Controller/OrderController.php` | point d'entrée |
| Entité | `src/Entity/Order.php` |  |
| Repository | `src/Repository/OrderRepository.php` |  |
| Autre | `src/ValueObject/Money.php` |  |
| Template | `templates/base.html.twig` |  |
| Template | `templates/order/index.html.twig` |  |

Paquets : `symfony/framework-bundle` 8.1.7, `symfony/http-foundation` 8.1.7, `symfony/routing` 8.1.6

## Données

Lit toutes les `Order` du dépôt en mémoire `src/Repository/OrderRepository.php`. N'écrit rien.

## Mécanismes transverses

`LocaleListener` sur `kernel.request` ; access_control ROLE_USER sur `^/orders`.

## Points d'attention

Pas de pagination : le dépôt renvoie toutes les commandes. Le composant `CartSummary` rendu dans la page a son
propre workflow (`ui.cart-summary`).

## Tests existants

| Test | Fichier | Couvre |
|---|---|---|
| OrderControllerTest | `tests/Controller/OrderControllerTest.php` | — |

## Workflows liés

- [`event.locale-listener`](../events/locale-listener.md) — dépend de
- [`route.order.new`](order.new.md) — navigation
- [`route.order.show`](order.show.md) — navigation

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | b0cc0c4 | rédaction initiale |
