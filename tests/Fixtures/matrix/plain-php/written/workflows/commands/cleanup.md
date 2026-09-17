# cleanup
`command.cleanup` · type : commands · dernière mise à jour : 2026-09-16 · commit : 84499fa

## Résumé

`bin/cleanup`, déclaré dans `bin` de `composer.json`, supprime les commandes dont le client est vide.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `cleanup` (command) |
| Sécurité | — |
| Préconditions | La table `orders` existe. |

## Parcours

```mermaid
sequenceDiagram
  participant O as Opérateur
  participant S as bin/cleanup
  participant B as SQLite
  O->>S: vendor/bin/cleanup
  S->>B: DELETE FROM orders WHERE customer = ""
```

## Navigation / états

—

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Autre | `bin/cleanup` | point d'entrée |
| Configuration | `composer.json` |  |
| Autre | `lib/db.php` |  |

## Données

Supprime des lignes de `orders`.

## Mécanismes transverses

`lib/db.php` fournit la connexion PDO.

## Points d'attention

La condition `customer = ""` utilise des guillemets doubles : SQLite les accepte comme chaîne par
tolérance, d'autres bases y verraient un nom de colonne. Rien n'est affiché ni journalisé.

## Tests existants

—

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | 84499fa | rédaction initiale |
