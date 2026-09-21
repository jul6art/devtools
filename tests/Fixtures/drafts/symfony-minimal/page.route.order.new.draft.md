---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Crée une commande à partir du formulaire `OrderType` : un opérateur saisit le client et le produit, la
commande est tarifée par `OrderPricing` puis enregistrée, et l'opérateur est redirigé vers sa fiche.

## Preconditions

Le catalogue de produits est chargé.

## Journey

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

## Decisions

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

## Data

Écrit une `Order` en mémoire via `src/Repository/OrderRepository.php` ; lit le prix des produits.

## Cross-cutting mechanisms

`LocaleListener` fixe la locale à chaque requête avant le contrôleur.

## Points of attention

Le prix vaut toujours 0 : `Product` crée son prix à zéro et rien ne le renseigne.

## Change

rédaction initiale
