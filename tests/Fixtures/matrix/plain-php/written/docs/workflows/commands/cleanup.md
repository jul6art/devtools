# cleanup
`command.cleanup` · type: commands · last updated: 2026-09-16 · commit: 2620449

## Summary

`bin/cleanup`, déclaré dans `bin` de `composer.json`, supprime les commandes dont le client est vide.

## Trigger

| Element | Value |
|---|---|
| Entry point | `cleanup` (command) |
| Security | — |
| Preconditions | La table `orders` existe. |

## Journey

```mermaid
sequenceDiagram
  participant O as Opérateur
  participant S as bin/cleanup
  participant B as SQLite
  O->>S: vendor/bin/cleanup
  S->>B: DELETE FROM orders WHERE customer = ""
```

## Navigation / states

—

## Decisions

—

## Data

Supprime des lignes de `orders`.

## Cross-cutting mechanisms

`lib/db.php` fournit la connexion PDO.

## Points of attention

La condition `customer = ""` utilise des guillemets doubles : SQLite les accepte comme chaîne par
tolérance, d'autres bases y verraient un nom de colonne. Rien n'est affiché ni journalisé.

## Related workflows

—

## History

| Date | Commit | Changement |
|---|---|---|
| 2026-09-16 | 2620449 | rédaction initiale |
