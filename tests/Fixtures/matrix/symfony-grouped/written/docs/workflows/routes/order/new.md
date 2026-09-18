# GET|POST /orders/new
`route.order.new` · type : routes · dernière mise à jour : 2026-09-16 · commit : d15a772

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

**`App\Entity\Order::status`**

```mermaid
flowchart TD
  d1{"la commande n'a pas de client"}
  d1 -->|oui| v1["status = draft"]
  d1 -->|non| d2{"le produit n'a pas de prix"}
  d2 -->|oui| v2["status = quoted"]
  d2 -->|non| v3["status = priced"]
```

**`App\Entity\Order::currency`**

```mermaid
flowchart TD
  d1{"pays de la commande"}
  d1 -->|CH| v1["currency = CHF"]
  d1 -->|GB| v2["currency = GBP"]
  d1 -->|tout autre pays| v3["currency = EUR"]
```

## Données

Écrit une `Order` en mémoire via `src/Repository/OrderRepository.php` ; lit le prix des produits.

## Mécanismes transverses

`LocaleListener` fixe la locale à chaque requête avant le contrôleur.

## Points d'attention

Le prix vaut toujours 0 : `Product` crée son prix à zéro et rien ne le renseigne.

## Workflows liés

- [`route.order.show`](show.md) — navigation

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | d15a772 | rédaction initiale |
