# GET|POST /orders/new
`route.order.new` · type : routes · dernière mise à jour : 2026-09-16 · commit : —

## Résumé

Crée une commande. Un opérateur connecté ouvre le formulaire `OrderType`, saisit le client et le produit ; à
la soumission valide, la commande est tarifée par `OrderPricing`, enregistrée par `OrderRepository`, et
l'opérateur est redirigé vers sa fiche.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `GET\|POST /orders/new` (`app_order_new`) |
| Sécurité | `ROLE_USER`, `ROLE_OPERATOR` |
| Préconditions | Utilisateur authentifié avec le rôle ROLE_OPERATOR. |

## Parcours

```mermaid
sequenceDiagram
  participant U as Opérateur
  participant C as OrderController::new
  participant F as OrderType
  participant P as OrderPricing
  participant R as OrderRepository
  U->>C: GET /orders/new
  C-->>U: formulaire (order/new.html.twig)
  U->>C: POST /orders/new
  C->>F: handleRequest(request)
  F-->>C: Order hydratée et valide
  C->>P: price(order)
  P->>P: ProductRepository::byName(product)
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

Écrit une `Order` (client, produit, total) dans le dépôt en mémoire `src/Repository/OrderRepository.php` ;
lit un `Product` et son prix `Money` via `src/Repository/ProductRepository.php`.

## Mécanismes transverses

`LocaleListener` fixe la locale sur `kernel.request` avant le contrôleur ; le pare-feu exige ROLE_USER sur
`^/orders`, puis `#[IsGranted]` exige ROLE_OPERATOR sur cette action.

## Points d'attention

Le total vaut toujours 0 : `src/Entity/Product.php` initialise son prix à zéro et rien ne le renseigne. Le
dépôt est en mémoire : la commande créée disparaît à la requête suivante.

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
| 2026-09-16 | — | rédaction initiale |
