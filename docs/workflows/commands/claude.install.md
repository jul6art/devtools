# claude:install
`command.claude.install` · type: commands · last updated: 2026-10-20 · commit: 58c02fb

## Summary

Installe ce que DevTools ajoute à un projet pour Claude Code : le skill qui rédige les pages (ADR-0011) quand rien d'autre n'est demandé, et à la demande les hooks qui donnent l'impact d'un fichier avant sa modification et lancent la gate en fin de tâche (ADR-0019, ADR-0023), le serveur MCP de .mcp.json (ADR-0018) et l'agent vérificateur (ADR-0024). Tout se fusionne avec la configuration de l'équipe et se committe ; un fichier que l'équipe a adapté n'est jamais écrasé sans --force.

## Trigger

| Element | Value |
|---|---|
| Entry point | `claude:install` (command) |
| Security | — |
| Preconditions | Le chemin donné est un dossier existant ; le serveur MCP demande en plus le SDK MCP, que DevTools ne fait que suggérer. |

## Journey

```mermaid
sequenceDiagram
  participant U as Développeur
  participant C as claude:install
  participant R as Ressources livrées
  participant S as ClaudeSettings
  participant P as Projet
  U->>C: devtools claude:install --hooks --mcp --agents
  C->>R: skill et agent livrés
  C->>P: copie, sauf fichier adapté sans --force
  C->>S: hooks PreToolUse, SessionStart, Stop
  S->>P: settings.json fusionné
  C->>S: serveur devtools
  S->>P: .mcp.json fusionné
  C-->>U: une ligne par élément, à committer
```

## Decisions

**`Jul6Art\DevTools\Command\ClaudeInstallCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin n'est pas un dossier"}
  d1 -->|oui| v1["INVALID"]
  d1 -->|non| d2{"--hooks : .claude/settings.json n'est pas un objet JSON"}
  d2 -->|oui| v2["FAILURE — le fichier de l'équipe est laissé tel quel"]
  d2 -->|non| d3{"--mcp : .mcp.json n'est pas un objet JSON, ou nomme un autre serveur devtools sans --force"}
  d3 -->|oui| v3["FAILURE — rien n'est écrit"]
  d3 -->|non| v4["SUCCESS, ou FAILURE d'un fichier gardé (voir installFile)"]
```

**`Jul6Art\DevTools\Command\ClaudeInstallCommand::installFile`**

```mermaid
flowchart TD
  d1{"le fichier du projet diffère de celui livré, sans --force"}
  d1 -->|oui| v1["FAILURE — la version du projet est gardée"]
  d1 -->|non| v2["SUCCESS — installé, ou déjà identique"]
```

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

**`stdClass::hooks`**

```mermaid
flowchart TD
  d1{"le groupe de hooks est un objet qui porte une liste"}
  d1 -->|oui| v1["n'en garde que les hooks qui ne sont pas de DevTools"]
  d1 -->|non| v2["le groupe reste tel quel"]
```

**`stdClass::matcher`**

```mermaid
flowchart TD
  d1{"l'événement a un matcher (PreToolUse : Edit|Write|MultiEdit)"}
  d1 -->|oui| v1["le groupe ne vaut que pour ces outils"]
  d1 -->|non| v2["le groupe vaut pour chaque occurrence (SessionStart, Stop)"]
```

## Data

Lit le skill et l'agent livrés ; écrit .claude/skills/, .claude/agents/, et fusionne .claude/settings.json et .mcp.json, lus en objets JSON pour qu'un objet vide reste un objet.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Le hook Stop a un délai de 600 s : la gate peut rejouer des scénarios. Les décisions de `mcp:serve` et de la bibliothèque de connaissance apparaissent parce que l'installation nomme la commande par sa classe, et que le serveur lit la bibliothèque : l'installation n'en exécute aucune. Une entrée devtools de .mcp.json que DevTools n'a pas écrite est gardée sauf --force.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-18 | b8049a4 | rédaction initiale |
| 2026-09-19 | d9d6b8f | added test tests/Project/GitHooksTest.php |
| 2026-09-20 | 9e40da9 | Aucun changement de fond : seul un test s'est ajouté au périmètre du workflow. La prose a été relue contre le code et tient. (added test tests/Inspection/Adapter/Symfony/DoctrineListenersTest.php) |
| 2026-10-20 | 2885138 | Aucun changement de fond : seuls des tests se sont ajoutés au périmètre du workflow. La prose a été relue contre le code et tient. (added test tests/Ai/MenuAfterApplyTest.php (+4)) |
| 2026-10-20 | 4979790 | Aucun changement de fond : le gabarit passe à neuf sections : la navigation et la machine à états ouvrent « Decisions » (ADR-0044), et des tests se sont ajoutés. La prose a été relue contre le code et tient. (added test tests/Stack/Knowledge/KnowledgeAmendTest.php) |
| 2026-10-20 | 4979790 | modified decision Jul6Art\DevTools\Command\ClaudeInstallCommand::execute: is_file($target) && file_get_contents($target) !== $shipped && true !== $input->getOption('force') → $hooks (+26) |
| 2026-10-20 | 4979790 | S'étend au serveur MCP (--mcp), à l'agent vérificateur (--agents) et au hook Stop qui lance la gate ; un fichier adapté par l'équipe reste gardé sauf --force. (files changed: src/Command/McpServeCommand.php) |
| 2026-10-20 | 58c02fb | Aucun changement de fond : la configuration apprend les catégories de retours du projet, le client git et les chemins de .devtools ce que demandent les retours, les règles et le journal, sans rien changer de ce que fait la commande ; des tests s'ajoutent. La prose tient. (added test tests/Assertion/AssertionProposalTest.php (+10)) |
| 2026-10-20 | 58c02fb | Aucun changement de fond : le client git lit plus simplement les fichiers du projet et les commits d'une plage, sans rien changer de ce que fait la commande. La prose a été relue et tient. (files changed: src/Inspection/Freshness/GitClient.php) |
