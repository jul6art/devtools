# workflows:apply
`command.workflows.apply` · type: commands · last updated: 2026-09-21 · commit: e5a6438

## Summary

Relit les brouillons que Claude a écrits dans `.devtools/pending/`, les refuse ou les applique, et reconstruit la page : les faits viennent du modèle, la prose du brouillon. Les connaissances passent d'abord — une page ne se rédige pas sans savoir comment la stack fonctionne — puis les découvertes, puis les pages. Une fiche de connaissance acceptée est déposée dans la bibliothèque partagée.

## Trigger

| Element | Value |
|---|---|
| Entry point | `workflows:apply` (command) |
| Security | — |
| Preconditions | Une inspection a écrit des briefs, et Claude a répondu à au moins un. |

## Journey

```mermaid
sequenceDiagram
  participant U as Développeur
  participant C as workflows:apply
  participant A as DraftApplier
  participant V as PageDraftValidator
  participant L as Bibliothèque
  participant D as docs/ + .devtools/
  U->>C: devtools workflows:apply .
  C->>A: apply(racine, partage)
  A->>L: dépôt des fiches acceptées
  A->>V: validate(brouillon, modèle, révision)
  V-->>A: règles cassées, ou rien
  A->>D: page reconstruite, suivi mis à jour
  A-->>C: acceptés, refusés, déposés
  C-->>U: bilan
```

## Navigation / states

—

## Decisions

**`Jul6Art\DevTools\Command\WorkflowsApplyCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin n'est pas un dossier"}
  d1 -->|oui| v1["INVALID"]
  d1 -->|non| v2["SUCCESS, ou 1 si un brouillon est refusé"]
```

**`Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary::deposit`**

```mermaid
flowchart TD
  d1{"la bibliothèque a déjà cette fiche"}
  d1 -->|oui| v1["AlreadyPresent — jamais d'écrasement"]
  d1 -->|non| d2{"accessible en écriture"}
  d2 -->|non| v2["NotWritable — un avertissement, pas une erreur"]
  d2 -->|oui| v3["Deposited"]
```

**`Jul6Art\DevTools\Rendering\PageRenderer::crossCutting`**

```mermaid
flowchart TD
  d1{"le workflow porte des mécanismes"}
  d1 -->|non| v1["la section vaut —"]
  d1 -->|oui| v2["une table : mécanisme, événement, priorité, champs écrits"]
```

**`Jul6Art\DevTools\Rendering\MarkdownWriter::table`**

```mermaid
flowchart TD
  d1{"la table n'a aucune ligne"}
  d1 -->|oui| v1["— à la place de la table"]
  d1 -->|non| v2["l'en-tête et les lignes"]
```

**`Jul6Art\DevTools\Inspection\Model\Edge::label`**

```mermaid
flowchart TD
  d1{"l'arête porte un libellé"}
  d1 -->|oui| v1["le libellé, non vide"]
  d1 -->|non| v2["label = null"]
```

**`DateTimeImmutable::timezone`**

```mermaid
flowchart TD
  d1{"SOURCE_DATE_EPOCH est posée et numérique"}
  d1 -->|oui| v1["le fuseau courant, sur l'instant figé"]
  d1 -->|non| v2["maintenant, fuseau par défaut"]
```

## Data

Lit les briefs, les modèles et les brouillons de `.devtools/pending/` ; écrit les pages, les fichiers de suivi, l'index, et dépose les fiches de connaissance.

## Cross-cutting mechanisms

Aucun listener. Un brouillon est une donnée non fiable : chaque chemin qu'il cite doit être un fichier du modèle, et la révision doit être celle du brief.

## Points of attention

Un brouillon refusé ne change rien et reste dans `pending/` pour être corrigé. Une page rédigée survit aux réécritures factuelles ultérieures : seuls les faits sont régénérés.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-18 | b8049a4 | rédaction initiale |
| 2026-09-19 | d9d6b8f | added test tests/Project/GitHooksTest.php |
| 2026-09-20 | 9e40da9 | Aucun changement de fond : seul un test s'est ajouté au périmètre du workflow. La prose a été relue contre le code et tient. (added test tests/Inspection/Adapter/Symfony/DoctrineListenersTest.php) |
| 2026-09-21 | e5a6438 | l'outil passe en anglais : ce que la commande écrit, et les titres des pages qu'elle produit (files changed: src/Ai/DraftApplier.php, src/Ai/PageDraft.php, src/Ai/PageDraftValidator.php, src/Console/SummaryRenderer.php, src/Rendering/GroupPageRenderer.php, src/Rendering/GroupPageSection.php, src/Rendering/PageRenderer.php, src/Rendering/PageSection.php, src/Rendering/ParsedPage.php, src/Stack/Knowledge/KnowledgeCanvas.php, src/Stack/Knowledge/XmlKnowledgeBriefStore.php, src/Tracking/TrackingStatus.php) |
| 2026-09-21 | e5a6438 | l'en-tête de l'historique passe en anglais, et une page restée sous un gabarit antérieur est remise à jour (files changed: src/Rendering/PageRenderer.php) |
