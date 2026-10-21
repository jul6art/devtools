# GET /
`route.home` · type: routes · last updated: 2026-09-16 · commit: a8254ce

## Summary

Page d'accueil : un message d'information, le résumé du panier et un lien vers les commandes.

## Trigger

| Element | Value |
|---|---|
| Entry point | `GET /` (`home`) |
| Security | — |
| Preconditions | — |

## Journey

```mermaid
sequenceDiagram
  participant U as Visiteur
  participant C as HomeController::index
  participant V as home.blade.php
  U->>C: GET /
  C->>V: view('home')
  V-->>U: page avec <x-alert> et <livewire:cart-summary>
```

## Decisions

**Navigation**

```mermaid
flowchart LR
  n1["home"]
  n2["orders.index"]
  n1 -->|"link"| n2
```

<!-- Above: drawn by DevTools from the code. Below: written by Claude. -->

## Data

Aucune.

## Cross-cutting mechanisms

Le groupe `web` (session, cookies, CSRF) s'applique comme à toute route de `routes/web.php`.

## Points of attention

Le panier est un composant Livewire : il se rafraîchit seul, sans recharger la page.

## Related workflows

- [`route.orders.index`](orders.index.md) — navigation

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-16 | a8254ce | rédaction initiale |
