---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Résumé

Schéma de la base : une seule migration SQL crée la table `orders` (`id`, `customer`).

## Préconditions

—

## Parcours

```mermaid
flowchart LR
  M["001_create_orders.sql"] --> T[("orders")]
```

## Données

Table `orders` : `id INTEGER PRIMARY KEY`, `customer TEXT NOT NULL`.

## Mécanismes transverses

Aucun outil de migration : le fichier est exécuté à la main, et par `OrderRepositoryTest` sur une base en
mémoire.

## Points d'attention

Rien n'indique quelles migrations ont été jouées sur une base existante.

## Changement

rédaction initiale
