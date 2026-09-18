# workflows:apply
`command.workflows.apply` · type : commands · dernière mise à jour : 2026-09-18 · commit : b8049a4

## Résumé

Relit les brouillons que Claude a écrits dans `.devtools/pending/`, les refuse ou les applique, et reconstruit la page : les faits viennent du modèle, la prose du brouillon. Les connaissances passent d'abord — une page ne se rédige pas sans savoir comment la stack fonctionne — puis les découvertes, puis les pages. Une fiche de connaissance acceptée est déposée dans la bibliothèque partagée.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `workflows:apply` (command) |
| Sécurité | — |
| Préconditions | Une inspection a écrit des briefs, et Claude a répondu à au moins un. |

## Parcours

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

## Navigation / états

—

## Décisions

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

## Données

Lit les briefs, les modèles et les brouillons de `.devtools/pending/` ; écrit les pages, les fichiers de suivi, l'index, et dépose les fiches de connaissance.

## Mécanismes transverses

Aucun listener. Un brouillon est une donnée non fiable : chaque chemin qu'il cite doit être un fichier du modèle, et la révision doit être celle du brief.

## Points d'attention

Un brouillon refusé ne change rien et reste dans `pending/` pour être corrigé. Une page rédigée survit aux réécritures factuelles ultérieures : seuls les faits sont régénérés.

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-18 | b8049a4 | rédaction initiale |
