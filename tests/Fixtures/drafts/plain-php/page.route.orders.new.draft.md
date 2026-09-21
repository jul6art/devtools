---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Crée une commande : en GET, affiche un formulaire avec le nom du client ; en POST, enregistre la commande par
`OrderRepository::add()` puis redirige vers `/index.php`.

## Preconditions

La table `orders` existe.

## Journey

```mermaid
sequenceDiagram
  participant U as Opérateur
  participant P as public/orders/new.php
  participant R as OrderRepository
  participant B as SQLite
  U->>P: GET /orders/new.php
  P-->>U: formulaire
  U->>P: POST customer
  P->>R: add(customer)
  R->>B: INSERT INTO orders (customer)
  P-->>U: Location: /index.php
```

## Decisions

—

## Data

Écrit une ligne dans `orders` (colonne `customer`), par une requête préparée.

## Cross-cutting mechanisms

`lib/db.php` fournit la connexion PDO.

## Points of attention

Aucune validation : un nom vide est enregistré (c'est ce que `bin/cleanup` supprime ensuite), et aucun
jeton CSRF ne protège le formulaire.

## Change

rédaction initiale
