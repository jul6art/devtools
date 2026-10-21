# ai:run
`command.ai.run` · type: commands · last updated: 2026-10-21 · commit: 240bcc3

## Summary

Rédige les briefs en attente par l'API Claude, hors session — en CI par exemple —, puis les applique comme `workflows:apply` (ADR-0035). Une tâche par brief — pages, groupes, connaissances, découvertes, scénarios, règles, assertions, dans cet ordre —, avec les fichiers que le brief nomme ; chaque brouillon revient dans `.devtools/pending/` et passe les mêmes validations qu'un brouillon écrit en session. La commande est **coupée par défaut** : elle ne fait rien tant que le config.xml du projet ne pose pas `<ai enabled="true"/>`, parce qu'elle dépense des jetons.

## Trigger

| Element | Value |
|---|---|
| Entry point | `ai:run` (command) |
| Security | — |
| Preconditions | Le projet a été inspecté avec des briefs, `<ai enabled="true"/>` est posé, et ANTHROPIC_API_KEY porte la clé. |

## Journey

```mermaid
sequenceDiagram
  participant U as CI ou humain
  participant C as ai:run
  participant R as AiRunner
  participant S as SecretScanner
  participant A as API Claude
  participant D as DraftApplier
  U->>C: devtools ai:run --tasks --max-tasks
  C->>C: coupée ? clé absente ? options refusées ?
  loop chaque brief, dans la limite des tâches et du budget
    R->>S: fichiers du brief
    S-->>R: un secret, un fichier exclu : rien n'est envoyé
    R->>A: une requête par brief
    A-->>R: le brouillon, ou un refus
  end
  R->>D: brouillons écrits
  D-->>C: acceptés, refusés
  C-->>U: rapport, jetons, coût estimé, code 0 ou 1
```

## Decisions

**`DateTimeImmutable::timezone`**

```mermaid
flowchart TD
  d1{"SOURCE_DATE_EPOCH fixe l'instant (builds reproductibles)"}
  d1 -->|oui| v1["cet instant, au fuseau de la machine"]
  d1 -->|non| v2["maintenant"]
```

**`Jul6Art\DevTools\Ai\Api\AiRunReport::applied`**

```mermaid
flowchart TD
  d1{"au moins un brouillon a été écrit par l'API"}
  d1 -->|oui| v1["le résultat de workflows:apply sur le projet"]
  d1 -->|non| v2["un résultat vide : rien à appliquer"]
```

**`Jul6Art\DevTools\Ai\Api\AiRunReport::stopped`**

```mermaid
flowchart TD
  d1{"les jetons dépensés atteignent le budget de config.xml"}
  d1 -->|oui| v1["arrêt : le budget est dépensé, les briefs restants sont gardés"]
  d1 -->|non| d2{"--max-tasks briefs ont été envoyés et il en reste"}
  d2 -->|oui| v2["arrêt : la limite de tâches est atteinte"]
  d2 -->|non| v3["pas d'arrêt"]
```

**`Jul6Art\DevTools\Ai\ApplyResult::accepted`**

```mermaid
flowchart TD
  s1["connaissances et découvertes acceptées"] --> s2["+ scénarios"]
  s2 --> s3["+ règles"] --> s4["+ assertions"] --> s5["+ glossaire"] --> s6["+ pages"]
```

**`Jul6Art\DevTools\Ai\ApplyResult::refused`**

```mermaid
flowchart TD
  s1["refus des connaissances et découvertes"] --> s2["+ scénarios"]
  s2 --> s3["+ règles"] --> s4["+ assertions"] --> s5["+ glossaire"] --> s6["+ pages"]
```

**`Jul6Art\DevTools\Command\AiRunCommand::execute`**

```mermaid
flowchart TD
  d1{"config.xml ne pose pas ai enabled"}
  d1 -->|oui| v1["FAILURE : coupée, elle dépenserait des jetons"]
  d1 -->|non| d2{"ANTHROPIC_API_KEY est vide"}
  d2 -->|oui| v2["FAILURE : rien n'est envoyé"]
  d2 -->|non| d3{"une tâche inconnue, aucune tâche, ou --max-tasks inférieur à 1"}
  d3 -->|oui| v3["INVALID"]
  d3 -->|non| d4{"un brief ou un brouillon a été refusé"}
  d4 -->|oui| v4["FAILURE, rapport écrit"]
  d4 -->|non| v5["SUCCESS, rapport écrit"]
```

**`Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary::deposit`**

```mermaid
flowchart TD
  d1{"la bibliothèque partagée a déjà cette fiche"}
  d1 -->|oui| v1["déjà présente : rien n'est écrit"]
  d1 -->|non| d2{"la bibliothèque n'est pas accessible en écriture"}
  d2 -->|oui| v2["non déposée, dit"]
  d2 -->|non| v3["déposée"]
```

**`Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary::depositRevision`**

```mermaid
flowchart TD
  d1{"la dernière révision a déjà ce contenu"}
  d1 -->|oui| v1["déjà présente"]
  d1 -->|non| d2{"la bibliothèque n'est pas accessible en écriture"}
  d2 -->|oui| v2["non déposée, dit"]
  d2 -->|non| v3["révision déposée"]
```

## Data

Lit config.xml, les briefs de `.devtools/pending/` et les fichiers qu'ils nomment, la clé dans ANTHROPIC_API_KEY ; envoie une requête HTTPS par brief à l'API Claude ; écrit les brouillons dans `.devtools/pending/`, puis ce qu'écrit `workflows:apply`, et un rapport dans `.devtools/reports/`. La clé n'est écrite nulle part : ni fichier, ni message, ni rapport.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Avant chaque envoi, les fichiers d'une tâche repassent le SecretScanner : un fichier `.env*`, un dossier exclu par la configuration, un contenu qui ressemble à un secret, et rien n'est envoyé pour cette tâche. Une fiche de connaissance demande une recherche sur le web, qu'il faut autoriser (`web-search`) ; sans elle, la fiche s'écrit en session, jamais de mémoire. Le budget plafonne une exécution, entrées et sorties comprises ; le coût affiché est une estimation. Une tâche dont le brouillon attend déjà `workflows:apply` n'est pas renvoyée. La décision sur la bibliothèque est celle de `workflows:apply`, qui dépose une fiche acceptée.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-10-21 | 240bcc3 | rédaction initiale |
| 2026-10-21 | 240bcc3 | Aucun changement de fond : le suivi d'un workflow sait s'allonger d'une révision, le renommage et l'initialisation ont été simplifiés (ADR-0036, ADR-0039), sans rien changer de ce que fait la commande. La prose a été relue et tient. (files changed: src/Ai/Api/AiRunner.php, src/Tracking/TrackingDocument.php) |
