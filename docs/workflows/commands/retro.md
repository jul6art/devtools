# retro
`command.retro` · type: commands · last updated: 2026-10-20 · commit: 58c02fb

## Summary

La rétrospective (ADR-0029) : si les retours diminuent. Remarques humaines par tâche livrée, mois par mois, et le dernier mois contre les trois précédents ; catégories en hausse ou en baisse ; règles qui ont bloqué la gate ; règles inactives, proposées à l'archivage ; retours vus deux fois et non promus ; passages de gate avant le vert ; ce que trouve le vérificateur et ce que disent encore les humains. Un rapport Markdown ou XML dans .devtools/reports/.

## Trigger

| Element | Value |
|---|---|
| Entry point | `retro` (command) |
| Security | — |
| Preconditions | La période commence dans le passé ; les tâches livrées viennent du journal, que le hook Stop écrit quand la gate passe. |

## Journey

```mermaid
sequenceDiagram
  participant H as Humain
  participant C as retro
  participant R as Retrospective
  participant J as Journal
  participant F as Retours
  participant G as git
  H->>C: devtools retro --since=2026-07-01
  C->>R: report(since, jours d'inactivité)
  R->>J: tâches livrées de la période
  R->>F: occurrences, par source et catégorie
  R->>G: les commits de chaque tâche
  R-->>C: les sept sections
  C-->>H: le rapport, la tendance, ce qui est proposé
```

## Decisions

**`DateTimeImmutable::timezone`**

```mermaid
flowchart TD
  d1{"SOURCE_DATE_EPOCH fixe l'instant (builds reproductibles)"}
  d1 -->|oui| v1["cet instant, au fuseau de la machine"]
  d1 -->|non| v2["maintenant"]
```

**`Jul6Art\DevTools\Command\RetroCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin n'est pas un dossier, --since n'est pas un jour passé, --format n'est ni md ni xml, ou --inactive-days n'est pas un nombre de jours"}
  d1 -->|oui| v1["INVALID"]
  d1 -->|non| d2
  d2{"un fichier du journal, des retours ou des règles est illisible"}
  d2 -->|oui| v2["FAILURE"]
  d2 -->|non| v3["SUCCESS — le rapport écrit"]
```

**`element::textContent`**

```mermaid
flowchart TD
  d1{"un texte est donné à l'élément XML"}
  d1 -->|oui| v1["le texte devient le contenu"]
  d1 -->|non| v2["l'élément reste vide"]
```

## Data

Lit le journal (.devtools/journal/), les retours et leurs occurrences, l'index des règles et leurs statistiques ; git seulement pour savoir quels commits une tâche contient. Écrit le rapport dans .devtools/reports/, ignoré par git.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Une tâche dont tout a été commité avant la fin ne laisse aucun changement à la gate, donc aucune livraison au journal. La tendance demande quatre mois. Rien n'est archivé : la commande propose, rules:archive dispose. L'horloge suit SOURCE_DATE_EPOCH quand il est posé : deux exécutions sur un projet inchangé se comparent alors à l'octet. La décision sur `element::textContent` est celle de DomBuilder, sur le chemin de toute lecture ou écriture XML : elle n'est pas propre à ce workflow.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-10-20 | 58c02fb | rédaction initiale |
| 2026-10-20 | 58c02fb | Aucun changement de fond : le code a été remis en forme (Rector, style), sans rien changer de ce que fait la commande. La prose a été relue et tient. (files changed: src/Command/RetroCommand.php, src/Retro/RetroReport.php, src/Retro/Retrospective.php, src/Retro/XmlJournalStore.php; files added: src/Rule/RuleStat.php) |
| 2026-10-20 | 58c02fb | Aucun changement de fond : le client git lit plus simplement les fichiers du projet et les commits d'une plage, sans rien changer de ce que fait la commande. La prose a été relue et tient. (files changed: src/Inspection/Freshness/GitClient.php) |
