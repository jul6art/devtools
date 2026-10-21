# feedback:list
`command.feedback.list` · type: commands · last updated: 2026-10-20 · commit: 58c02fb

## Summary

Liste les retours d'un projet (ADR-0025) : les ouverts par défaut, ou ceux d'un statut, d'une catégorie, d'un workflow — avec ce qu'ils visent, combien de fois ils ont été vus et leur ligne. --categories donne la liste fermée des catégories et ce que chacune couvre, celles du projet comprises.

## Trigger

| Element | Value |
|---|---|
| Entry point | `feedback:list` (command) |
| Security | — |
| Preconditions | Aucune : sans retour, la commande le dit. |

## Journey

```mermaid
sequenceDiagram
  participant H as Humain
  participant C as feedback:list
  participant I as Index des retours
  H->>C: devtools feedback:list --status=open --workflow=route.order.new
  C->>I: les entrées
  I-->>C: statut, catégorie, cible, occurrences, résumé
  C-->>H: une table, ou « No open feedback. »
```

## Decisions

**`DateTimeImmutable::timezone`**

```mermaid
flowchart TD
  d1{"SOURCE_DATE_EPOCH fixe l'instant (builds reproductibles)"}
  d1 -->|oui| v1["cet instant, au fuseau de la machine"]
  d1 -->|non| v2["maintenant"]
```

**`Jul6Art\DevTools\Command\FeedbackListCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin n'est pas un dossier, ou --status n'est pas un statut"}
  d1 -->|oui| v1["INVALID"]
  d1 -->|non| d2
  d2{"--categories est demandé"}
  d2 -->|oui| v2["SUCCESS — la liste fermée et ce que couvre chaque catégorie"]
  d2 -->|non| d3
  d3{"aucun retour ne passe les filtres"}
  d3 -->|oui| v3["SUCCESS — dit en une ligne"]
  d3 -->|non| v4["SUCCESS — la table des retours"]
```

**`element::textContent`**

```mermaid
flowchart TD
  d1{"un texte est donné à l'élément XML"}
  d1 -->|oui| v1["le texte devient le contenu"]
  d1 -->|non| v2["l'élément reste vide"]
```

## Data

Lit l'index des retours et la configuration du projet. N'écrit rien.

## Cross-cutting mechanisms

Aucun.

## Points of attention

La liste ne lit que l'index : un fichier de retour déposé à la main sans sa ligne d'index n'y figure pas. La décision d'horloge vient de l'enregistreur que la liste partage avec feedback:add : elle ne date rien. La décision sur `element::textContent` est celle de DomBuilder, sur le chemin de toute lecture ou écriture XML : elle n'est pas propre à ce workflow.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-10-20 | 58c02fb | rédaction initiale |
| 2026-10-20 | 58c02fb | Aucun changement de fond : le client git lit plus simplement les fichiers du projet et les commits d'une plage, sans rien changer de ce que fait la commande. La prose a été relue et tient. (files changed: src/Inspection/Freshness/GitClient.php) |
