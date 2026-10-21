# workflows:accept
`command.workflows.accept` · type: commands · last updated: 2026-10-20 · commit: 58c02fb

## Summary

Le changement était voulu : la documentation suit. Les pages des workflows portant les faits acceptés sont réécrites — **et elles seules** —, leur historique nomme le fait plutôt que le fichier, et le brief de rédaction le porte aussi, pour que Claude relise là où il faut plutôt que partout.

## Trigger

| Element | Value |
|---|---|
| Entry point | `workflows:accept` (command) |
| Security | — |
| Preconditions | Le projet porte un `.devtools/` déjà inspecté, et les cibles nommées désignent des faits qui ont changé. |

## Journey

```mermaid
sequenceDiagram
  participant U as relecteur
  participant C as WorkflowsAcceptCommand
  participant R as ChangeReview
  participant P as InspectionPipeline

  U->>C: workflows:accept <cible>… ou --all
  C->>R: les faits changés, groupés
  alt aucune cible ne correspond
    C-->>U: 2, avec la liste de ce qui a changé
  else
    C->>P: inspection forcée sur ces workflows, et bornée à eux
    P-->>C: pages réécrites, suivis mis à jour, briefs écrits
    C-->>U: les faits acceptés et ce que ça a coûté
  end
```

## Decisions

**`DateTimeImmutable::timezone`**

```mermaid
flowchart TD
  d1{"SOURCE_DATE_EPOCH est posé et ne contient que des chiffres"}
  d1 -->|oui| v1["le fuseau par défaut de PHP — l'horloge devient reproductible, et une même exécution rend les mêmes octets"]
  d1 -->|non| v2["l'instant courant, tel quel"]
```

**`Jul6Art\DevTools\Command\WorkflowsAcceptCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin est un dossier"}
  d1 -->|non| v1["INVALID"]
  d1 -->|oui| d2{"l'inspection a rencontré une erreur"}
  d2 -->|oui| v2["INVALID"]
  d2 -->|non| d3{"un fait a changé"}
  d3 -->|non| v3["SUCCESS — il n'y a rien à accepter"]
  d3 -->|oui| d4{"les cibles nommées désignent un fait changé"}
  d4 -->|non| v4["INVALID, avec la liste de ce qui a changé"]
  d4 -->|oui| v5["SUCCESS — les pages des workflows concernés sont réécrites"]
```

**`Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor::parser`**

```mermaid
flowchart TD
  d1{"un analyseur est fourni"}
  d1 -->|oui| v1["celui-là — une couture pour les tests"]
  d1 -->|non| v2["celui de la dernière version de PHP supportée par php-parser"]
```

**`Jul6Art\DevTools\Inspection\InspectionReport::path`**

```mermaid
flowchart TD
  d1{"l'exécution écrit, et le dossier .devtools existe"}
  d1 -->|oui| v1["le rapport est écrit dans reports/, horodaté — hors git"]
  d1 -->|non| v2["aucun rapport : une simulation ne laisse pas de trace"]
```

**`Jul6Art\DevTools\Rendering\MarkdownWriter::table`**

```mermaid
flowchart TD
  d1{"la table a des lignes"}
  d1 -->|non| v1["le tiret cadratin — une table vide se lit moins bien qu'un « rien »"]
  d1 -->|oui| v2["l'en-tête, le séparateur, puis les lignes"]
```

**`Jul6Art\DevTools\Rendering\PageRenderer::crossCutting`**

```mermaid
flowchart TD
  d1{"le workflow porte des mécanismes"}
  d1 -->|non| v1["le tiret cadratin"]
  d1 -->|oui| v2["la table : mécanisme, événement, priorité, ce qu'il écrit"]
```

**`Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary::deposit`**

```mermaid
flowchart TD
  d1{"la bibliothèque est accessible en écriture"}
  d1 -->|non| v1["NotWritable — le projet garde sa fiche, et l'exécution le dit"]
  d1 -->|oui| d2{"une fiche existe déjà pour cette clé"}
  d2 -->|oui| v2["AlreadyPresent — une fiche déposée n'est jamais écrasée"]
  d2 -->|non| v3["Deposited"]
```

**`Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary::depositRevision`**

```mermaid
flowchart TD
  d0{"la bibliothèque n'a pas encore cette fiche"}
  d0 -->|oui| v0["premier dépôt, comme deposit()"]
  d0 -->|non| d1{"la fiche amendée est identique à la dernière révision"}
  d1 -->|oui| v1["AlreadyPresent — aucune révision de plus"]
  d1 -->|non| d2{"la bibliothèque est accessible en écriture"}
  d2 -->|non| v2["NotWritable — l'amendement reste dans le projet"]
  d2 -->|oui| v3["Deposited — une révision rN succède, l'original reste"]
```

## Data

Écriture : les pages, les suivis et les briefs des workflows portant les faits acceptés.

## Cross-cutting mechanisms

—

## Points of attention

- **`--force` seul ne suffisait pas** : il rend un workflow périmé, il n'empêche pas les autres de l'être. Sans la restriction, accepter la sécurité d'une route réécrivait les quatre autres du même contrôleur — vu sur un projet réel.
- **Ce qui n'est pas accepté reste en dérive** : son suivi n'est pas écrit, donc le contrôle continue de le signaler.
- **`--no-ai` accepte les faits sans demander la prose** : la page redevient juste tout de suite, sa rédaction attend.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-19 | d9d6b8f | Rédaction initiale. |
| 2026-09-20 | 9e40da9 | Aucun changement de fond : le pipeline d'inspection a bougé sur l'élagage et sur les écouteurs Doctrine, deux points que cette commande ne touche pas. La prose a été relue contre le code et tient. (files changed: src/Inspection/InspectionPipeline.php) |
| 2026-09-21 | e5a6438 | l'outil passe en anglais : ce que la commande écrit, et les titres des pages qu'elle produit (files changed: src/Ai/PageDraft.php, src/Command/WorkflowsAcceptCommand.php, src/Console/ChangeRenderer.php, src/Inspection/InspectionPipeline.php, src/Rendering/GroupPageRenderer.php, src/Rendering/GroupPageSection.php, src/Rendering/MenuRenderer.php, src/Rendering/PageRenderer.php, src/Rendering/PageSection.php, src/Rendering/ParsedPage.php, src/Stack/Knowledge/KnowledgeCanvas.php, src/Stack/Knowledge/XmlKnowledgeBriefStore.php, src/Tracking/TrackingStatus.php) |
| 2026-09-21 | e5a6438 | l'en-tête de l'historique passe en anglais, et une page restée sous un gabarit antérieur est remise à jour (files changed: src/Inspection/InspectionPipeline.php, src/Rendering/PageRenderer.php) |
| 2026-10-20 | 2885138 | Aucun changement de fond : le rendu du menu a gagné la relecture de sa section « Not covered », que seul workflows:apply appelle ; des tests se sont ajoutés. La prose a été relue contre le code et tient. (added test tests/Command/WorkflowsDiffCommandTest.php) |
| 2026-10-20 | 4979790 | le chemin d'application gagne le dépôt en révision d'une fiche amendée, et un champ moins riche sort des huit dessinés ; le gabarit passe à neuf sections : la navigation et la machine à états ouvrent « Decisions » (ADR-0044) (added decision Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary::depositRevision: — → \Jul6Art\DevTools\Stack\Knowledge\DepositOutcome::AlreadyPresent (+4)) |
| 2026-10-20 | 4979790 | files changed: src/Inspection/Freshness/GitClient.php, src/Tracking/FilesToWorkflows.php |
| 2026-10-20 | 4979790 | L'analyse relève désormais la limite mémoire de PHP à 1 Go, comme l'inspection ; des tests s'ajoutent au périmètre et symfony/process passe en 8.1.9. La prose a été relue et tient. (modified package symfony/process: 8.1.7 → 8.1.9) |
| 2026-10-20 | 58c02fb | Aucun changement de fond : la configuration apprend les catégories de retours du projet, le client git et les chemins de .devtools ce que demandent les retours, les règles et le journal, sans rien changer de ce que fait la commande ; des tests s'ajoutent. La prose tient. (files changed: src/Config/Config.php, src/Config/XmlConfigReader.php, src/Inspection/Freshness/GitClient.php, src/Tracking/DevToolsDirectory.php) |
| 2026-10-20 | 58c02fb | Aucun changement de fond : le client git lit plus simplement les fichiers du projet et les commits d'une plage, sans rien changer de ce que fait la commande. La prose a été relue et tient. (files changed: src/Inspection/Freshness/GitClient.php) |
