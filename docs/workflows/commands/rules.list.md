# rules:list
`command.rules.list` · type: commands · last updated: 2026-10-21 · commit: 240bcc3

## Summary

Liste les règles d'un projet (ADR-0026) : proposées et actives par défaut, ou celles d'un statut — où chacune s'applique, ce qu'elle dit, combien de fois elle a été donnée à Claude et combien de fois elle a bloqué la gate.

## Trigger

| Element | Value |
|---|---|
| Entry point | `rules:list` (command) |
| Security | — |
| Preconditions | Aucune : sans règle, la commande le dit. |

## Journey

```mermaid
sequenceDiagram
  participant H as Humain
  participant C as rules:list
  participant I as Index des règles
  participant S as Statistiques
  H->>C: devtools rules:list --status=active
  C->>I: règles du statut
  C->>S: données, bloquées
  C-->>H: une table, ou une ligne
```

## Decisions

**`Jul6Art\DevTools\Command\RulesListCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin n'est pas un dossier, ou --status n'est pas un statut"}
  d1 -->|oui| v1["INVALID"]
  d1 -->|non| d2
  d2{"aucune règle de ces statuts"}
  d2 -->|oui| v2["SUCCESS — dit en une ligne"]
  d2 -->|non| v3["SUCCESS — la table des règles"]
```

**`element::textContent`**

```mermaid
flowchart TD
  d1{"un texte est donné à l'élément XML"}
  d1 -->|oui| v1["le texte devient le contenu"]
  d1 -->|non| v2["l'élément reste vide"]
```

## Data

Lit l'index des règles et leurs statistiques, .devtools/stats/rules.xml. N'écrit rien.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Les compteurs vivent dans une zone ignorée par git : ils sont propres à la machine, et repartent de zéro sur un clone. La décision sur `element::textContent` est celle de DomBuilder, sur le chemin de toute lecture ou écriture XML : elle n'est pas propre à ce workflow.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-10-20 | 58c02fb | rédaction initiale |
| 2026-10-21 | 240bcc3 | Aucun changement de fond : la configuration lit désormais les projets liés (ADR-0038) et le dossier .devtools/ connaît l'index de recherche (ADR-0039), sans rien changer de ce que fait la commande. La prose a été relue et tient. (added test tests/Ask/AskTest.php (+8)) |
