# assertions:propose
`command.assertions.propose` · type: commands · last updated: 2026-10-20 · commit: 58c02fb

## Summary

Demande à Claude l'assertion mécanique d'une règle (ADR-0027) : un brief qui donne la règle, ses retours et les commits où ils ont été signalés — où l'assertion devra échouer —, les fichiers de la règle, les captures DOM de la dernière exécution et les outils que le projet a installés. workflows:apply exécute le brouillon avant de l'écrire : il doit passer sur le projet tel qu'il est et, pour un motif, échouer à chaque commit « avant ».

## Trigger

| Element | Value |
|---|---|
| Entry point | `assertions:propose` (command) |
| Security | — |
| Preconditions | La règle existe, n'est pas archivée et n'a pas encore d'assertion ; --before nomme au besoin les commits où le défaut se trouve. |

## Journey

```mermaid
sequenceDiagram
  participant H as Humain
  participant C as assertions:propose
  participant P as AssertionProposal
  participant G as git
  participant B as Brief
  H->>C: devtools assertions:propose rule-… --before=…
  C->>P: refus éventuels
  P->>G: les commits « avant » existent-ils ?
  P->>B: le brief de la règle, dans pending/
  C-->>H: brief écrit ; workflows:apply exécutera le brouillon
```

## Decisions

**`Jul6Art\DevTools\Command\AssertionsProposeCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin n'est pas un dossier"}
  d1 -->|oui| v1["INVALID"]
  d1 -->|non| d2
  d2{"la règle est inconnue, archivée, ou déjà vérifiée par une assertion"}
  d2 -->|oui| v2["FAILURE"]
  d2 -->|non| d3
  d3{"la règle ne vient d'aucun retour que le projet a encore"}
  d3 -->|oui| v3["FAILURE"]
  d3 -->|non| v4["SUCCESS — le brief écrit"]
```

**`element::textContent`**

```mermaid
flowchart TD
  d1{"un texte est donné à l'élément XML"}
  d1 -->|oui| v1["le texte devient le contenu"]
  d1 -->|non| v2["l'élément reste vide"]
```

## Data

Lit la règle, ses retours, les fichiers du projet et la dernière exécution de scénarios ; demande à git si les commits « avant » existent ; écrit le brief dans .devtools/pending/. N'exécute aucun outil : c'est l'application du brouillon qui exécute.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Un commit de --before que git ne connaît pas est écarté sans bruit. Le défaut a pu n'être jamais commité : alors aucun commit ne montre l'échec, et l'application le dit en avertissement plutôt que de refuser. La décision sur `element::textContent` est celle de DomBuilder, sur le chemin de toute lecture ou écriture XML : elle n'est pas propre à ce workflow.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-10-20 | 58c02fb | rédaction initiale |
| 2026-10-20 | 58c02fb | Aucun changement de fond : le client git lit plus simplement les fichiers du projet et les commits d'une plage, sans rien changer de ce que fait la commande. La prose a été relue et tient. (files changed: src/Inspection/Freshness/GitClient.php) |
