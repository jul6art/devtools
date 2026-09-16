---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Résumé

Affiche la fiche d'une commande identifiée par son `id` : le client de la commande.

## Préconditions

Utilisateur authentifié (ROLE_USER).

## Parcours

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant C as OrderController::show
  participant R as OrderRepository
  U->>C: GET /orders/{id}
  C->>R: get(id)
  R-->>C: Order ou null
  C-->>U: order/show.html.twig
```

## Données

Lit une `Order` dans `src/Repository/OrderRepository.php`.

## Mécanismes transverses

`LocaleListener` sur `kernel.request` ; access_control ROLE_USER sur `^/orders`.

## Points d'attention

Un identifiant inconnu n'est pas traité : `get()` renvoie null et le template lit `order.customer` sur une
valeur nulle, au lieu d'une réponse 404.

## Changement

rédaction initiale
