# rules:archive
`command.rules.archive` · type: commands · last updated: 2026-10-21 · commit: 240bcc3

## Summary

Archive une règle qui ne sert plus (ADR-0029) : elle n'est plus donnée à Claude, son assertion n'est plus exécutée par la gate ; elle reste, avec son histoire, et rules:activate la ramène. Seul un humain archive : la rétrospective propose.

## Trigger

| Element | Value |
|---|---|
| Entry point | `rules:archive` (command) |
| Security | — |
| Preconditions | Les règles nommées existent. |

## Journey

```mermaid
sequenceDiagram
  participant H as Humain
  participant C as rules:archive
  participant R as Règles
  H->>C: devtools rules:archive rule-…
  C->>R: la règle
  C->>R: statut archived, et l'index
  C-->>H: archivée, ou déjà archivée
```

## Decisions

**`Jul6Art\DevTools\Command\RulesArchiveCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin n'est pas un dossier"}
  d1 -->|oui| v1["INVALID"]
  d1 -->|non| v2["chaque règle : inconnue — FAILURE ; déjà archivée — dit ; sinon archivée"]
```

**`element::textContent`**

```mermaid
flowchart TD
  d1{"un texte est donné à l'élément XML"}
  d1 -->|oui| v1["le texte devient le contenu"]
  d1 -->|non| v2["l'élément reste vide"]
```

## Data

Lit l'index et le fichier de chaque règle ; réécrit la règle et l'index.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Archiver ne retire pas l'assertion du dépôt : son fichier reste, la gate cesse seulement de l'exécuter. La décision sur `element::textContent` est celle de DomBuilder, sur le chemin de toute lecture ou écriture XML : elle n'est pas propre à ce workflow.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-10-20 | 58c02fb | rédaction initiale |
| 2026-10-21 | 240bcc3 | Aucun changement de fond : la configuration lit désormais les projets liés (ADR-0038) et le dossier .devtools/ connaît l'index de recherche (ADR-0039), sans rien changer de ce que fait la commande. La prose a été relue et tient. (added test tests/Ask/AskTest.php (+8)) |
