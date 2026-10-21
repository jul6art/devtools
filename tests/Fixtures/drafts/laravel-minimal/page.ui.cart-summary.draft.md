---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Composant Livewire qui affiche le nombre d'articles du panier.

## Preconditions

—

## Journey

```mermaid
sequenceDiagram
  participant V as Vue
  participant L as CartSummary
  V->>L: <livewire:cart-summary/>
  L-->>V: livewire.cart-summary
```

## Decisions

—

## Data

Aucune : `items` vaut 0.

## Cross-cutting mechanisms

Les requêtes Livewire passent par la route `livewire/update` du paquet.

## Points of attention

Le compteur ne lit aucun panier réel.

## Change

rédaction initiale
