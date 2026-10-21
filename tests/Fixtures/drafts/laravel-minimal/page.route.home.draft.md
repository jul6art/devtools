---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Page d'accueil : un message d'information, le résumé du panier et un lien vers les commandes.

## Preconditions

—

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

—

## Data

Aucune.

## Cross-cutting mechanisms

Le groupe `web` (session, cookies, CSRF) s'applique comme à toute route de `routes/web.php`.

## Points of attention

Le panier est un composant Livewire : il se rafraîchit seul, sans recharger la page.

## Change

rédaction initiale
