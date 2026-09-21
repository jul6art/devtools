---
model: claude-opus-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Page d'accueil du back-office : liste le client de chaque commande, lue par `OrderRepository::all()`, et
propose de créer une commande (bouton de formulaire et lien vers `/orders/new.php`).

## Preconditions

La base SQLite var/app.sqlite existe et contient la table `orders` (migration `001_create_orders.sql`).

## Journey

```mermaid
sequenceDiagram
  participant U as Opérateur
  participant P as public/index.php
  participant H as lib/views/header.php
  participant R as OrderRepository
  participant B as SQLite
  U->>P: GET /index.php
  P->>H: include (en-tête)
  P->>R: all()
  R->>B: SELECT customer FROM orders
  B-->>R: lignes
  R-->>P: commandes
  P-->>U: liste + accès à /orders/new.php
```

## Decisions

—

## Data

Lit la colonne `customer` de la table `orders` ; aucune écriture.

## Cross-cutting mechanisms

`lib/db.php` fournit une connexion PDO unique par requête (variable statique de `db()`).

## Points of attention

La connexion vise var/app.sqlite en dur : aucune configuration par environnement. Aucune pagination ni
tri, et aucune authentification ne protège la page.

## Change

rédaction initiale
