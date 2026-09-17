# GET|POST /orders/new
`route.order.new` · type : routes · dernière mise à jour : 2026-09-16 · commit : 6753625

## Résumé

Crée une commande à partir du formulaire `OrderType` : un opérateur saisit le client et le produit, la
commande est tarifée par `OrderPricing` puis enregistrée, et l'opérateur est redirigé vers sa fiche.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `GET\|POST /orders/new` (`app_order_new`) |
| Sécurité | `ROLE_USER`, `ROLE_OPERATOR` |
| Préconditions | Le catalogue de produits est chargé. |

## Parcours

```mermaid
sequenceDiagram
  participant U as Opérateur
  participant C as OrderController::new
  participant F as OrderType
  participant P as OrderPricing
  participant R as OrderRepository
  U->>C: POST /orders/new
  C->>F: handleRequest
  F-->>C: données valides
  C->>P: price(order)
  C->>R: save(order)
  C-->>U: redirect app_order_show
```

## Navigation / états

```mermaid
flowchart LR
  n1["app_order_new"]
  n2["app_order_show"]
  n1 -->|"redirect"| n2
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
| Entité | `src/Entity/Product.php` |  |
| Formulaire | `src/Form/OrderType.php` |  |
| Repository | `src/Repository/OrderRepository.php` |  |
| Repository | `src/Repository/ProductRepository.php` |  |
| Service | `src/Service/OrderPricing.php` |  |
| Autre | `src/ValueObject/Money.php` |  |
| Template | `templates/base.html.twig` |  |
| Template | `templates/order/_form.html.twig` |  |
| Template | `templates/order/new.html.twig` |  |

Paquets : `symfony/form` 8.1.7, `symfony/framework-bundle` 8.1.7, `symfony/http-foundation` 8.1.7, `symfony/options-resolver` 8.1.0, `symfony/routing` 8.1.6, `symfony/security-http` 8.1.7

## Données

Écrit une `Order` en mémoire via `src/Repository/OrderRepository.php` ; lit le prix des produits.

## Mécanismes transverses

`LocaleListener` fixe la locale à chaque requête avant le contrôleur.

## Points d'attention

Le prix vaut toujours 0 : `Product` crée son prix à zéro et rien ne le renseigne.

## Tests existants

| Test | Fichier | Couvre |
|---|---|---|
| OrderPricingTest | `tests/Service/OrderPricingTest.php` | — |

## Workflows liés

- [`event.locale-listener`](../events/locale-listener.md) — dépend de
- [`route.order.show`](order.show.md) — navigation

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | 6753625 | rédaction initiale |
