# claude:uninstall
`command.claude.uninstall` · type: commands · last updated: 2026-10-20 · commit: 58c02fb

## Summary

Retire de la configuration Claude Code d'un projet ce que `claude:install` y a ajouté, et rien d'autre (ADR-0019, ADR-0018, ADR-0024) : les hooks de DevTools, son serveur MCP, l'agent vérificateur s'il n'a pas été adapté. Un fichier qui ne contenait que les entrées de DevTools disparaît ; un fichier de l'équipe retrouve son état d'avant.

## Trigger

| Element | Value |
|---|---|
| Entry point | `claude:uninstall` (command) |
| Security | — |
| Preconditions | Le chemin est un dossier, et au moins une option dit quoi retirer : --hooks, --mcp ou --agents. |

## Journey

```mermaid
sequenceDiagram
  participant U as Développeur
  participant C as claude:uninstall
  participant S as ClaudeSettings
  participant A as Agent installé
  U->>C: devtools claude:uninstall --hooks --mcp --agents
  C->>S: hooks de DevTools retirés de settings.json
  C->>S: serveur devtools retiré de .mcp.json
  C->>A: identique à celui livré ?
  A-->>C: oui : retiré ; non : gardé
  C-->>U: une ligne par élément
```

## Decisions

**`Jul6Art\DevTools\Command\ClaudeUninstallCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin n'est pas un dossier"}
  d1 -->|oui| v1["INVALID"]
  d1 -->|non| d2
  d2{"aucune option ne dit quoi retirer"}
  d2 -->|oui| v2["INVALID — --hooks, --mcp, --agents"]
  d2 -->|non| v3["retire ce qui est à DevTools ; un fichier illisible est FAILURE"]
```

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

Lit et réécrit .claude/settings.json et .mcp.json en objets JSON — un objet vide reste un objet ; supprime l'agent installé s'il est intact. Ne touche à rien d'autre.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Les décisions de `claude:install` et de `mcp:serve` figurent ici parce que la désinstallation lit des constantes de leurs classes — le chemin de l'agent, le nom de la commande — sans les exécuter. Un serveur nommé devtools que DevTools n'a pas écrit, comme un agent adapté par l'équipe, reste en place.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-10-20 | 4979790 | initial |
| 2026-10-20 | 4979790 | rédaction initiale (files changed: src/Command/McpServeCommand.php) |
| 2026-10-20 | 58c02fb | Aucun changement de fond : des tests s'ajoutent au périmètre du workflow. La prose a été relue contre le code et tient. (added test tests/Assertion/AssertionProposalTest.php (+10)) |
