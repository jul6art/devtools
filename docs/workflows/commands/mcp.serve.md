# mcp:serve
`command.mcp.serve` · type: commands · last updated: 2026-10-21 · commit: 240bcc3

## Summary

Le serveur MCP du projet (ADR-0018), lancé par Claude Code depuis .mcp.json sur l'entrée et la sortie standard : cinq outils en lecture seule — list_workflows, get_workflow, search_workflows, impact, get_knowledge — qui rendent une page plutôt qu'un dossier à lire. search_workflows donne les pages qui répondent à une question, choisies sans IA (ADR-0039). Rien n'écrit, rien ne lance d'inspection ; un projet jamais inspecté reçoit la commande à lancer. La commande n'existe que si le SDK MCP est installé.

## Trigger

| Element | Value |
|---|---|
| Entry point | `mcp:serve` (command) |
| Security | — |
| Preconditions | Le SDK MCP est installé, et le projet se trouve par --path, CLAUDE_PROJECT_DIR ou le répertoire courant. |

## Journey

```mermaid
sequenceDiagram
  participant CC as Claude Code
  participant S as mcp:serve
  participant Q as ProjectQueries
  participant D as Documentation
  CC->>S: initialize
  S-->>CC: quatre outils et leurs consignes
  CC->>S: tools/call get_workflow(route.order.new)
  S->>Q: getWorkflow
  Q->>D: index, page, fichier de suivi
  D-->>Q: la page et ses métadonnées
  Q-->>S: deux blocs de texte
  S-->>CC: résultat, ou erreur d'outil lisible
```

## Decisions

**`Jul6Art\DevTools\Command\McpServeCommand::execute`**

```mermaid
flowchart TD
  d1{"le projet n'est pas un dossier"}
  d1 -->|oui| v1["INVALID — dit sur la sortie d'erreur, la sortie standard est au protocole"]
  d1 -->|non| v2["sert jusqu'à ce que le client ferme l'entrée"]
```

**`Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary::deposit`**

```mermaid
flowchart TD
  d1{"la bibliothèque a déjà cette fiche"}
  d1 -->|oui| v1["AlreadyPresent — rien n'est écrasé"]
  d1 -->|non| d2{"la bibliothèque est accessible en écriture"}
  d2 -->|non| v2["NotWritable — la fiche reste dans le projet"]
  d2 -->|oui| v3["Deposited — la fiche et sa ligne d'index"]
```

**`Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary::depositRevision`**

```mermaid
flowchart TD
  d1{"la fiche amendée est identique à la dernière révision"}
  d1 -->|oui| v1["AlreadyPresent — aucune révision de plus"]
  d1 -->|non| d2{"la bibliothèque est accessible en écriture"}
  d2 -->|non| v2["NotWritable — l'amendement reste dans le projet"]
  d2 -->|oui| v3["Deposited — une révision rN succède, l'original reste"]
```

## Data

Lit l'index, les pages, les fichiers de suivi, la carte fichiers → workflows, le fichier de stack, les fiches de connaissance et l'index de recherche. N'écrit rien — un index de recherche périmé est reconstruit en mémoire, jamais sur disque : la sortie standard appartient au protocole, et les outils se déclarent en lecture seule.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Les décisions de dépôt de la bibliothèque apparaissent parce que le serveur localise la bibliothèque partagée pour y lire une fiche : il n'y dépose jamais rien, aucun outil n'écrit. Une erreur d'un outil — identifiant inconnu, chemin hors du projet, XML illisible — revient comme une erreur que Claude lit, jamais comme un arrêt du serveur.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-10-20 | 4979790 | rédaction initiale |
| 2026-10-20 | 58c02fb | Aucun changement de fond : la configuration apprend les catégories de retours du projet, le client git et les chemins de .devtools ce que demandent les retours, les règles et le journal, sans rien changer de ce que fait la commande ; des tests s'ajoutent. La prose tient. (added test tests/Assertion/AssertionProposalTest.php (+10)) |
| 2026-10-20 | 58c02fb | Aucun changement de fond : le client git lit plus simplement les fichiers du projet et les commits d'une plage, sans rien changer de ce que fait la commande. La prose a été relue et tient. (files changed: src/Inspection/Freshness/GitClient.php) |
| 2026-10-21 | 240bcc3 | Un cinquième outil, search_workflows : les pages qui répondent à une question, choisies sans IA (ADR-0039). (added test tests/Ask/AskTest.php (+8)) |
| 2026-10-21 | 240bcc3 | Aucun changement de fond : le suivi d'un workflow sait s'allonger d'une révision, le renommage et l'initialisation ont été simplifiés (ADR-0036, ADR-0039), sans rien changer de ce que fait la commande. La prose a été relue et tient. (files changed: src/Ask/SearchIndexBuilder.php, src/Tracking/TrackingDocument.php) |
