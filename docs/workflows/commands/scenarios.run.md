# scenarios:run
`command.scenarios.run` · type: commands · last updated: 2026-10-20 · commit: 58c02fb

## Summary

Rejoue les scénarios dans Chrome contre l'application là où elle est servie (ADR-0021), et écrit leurs verdicts dans un résultat par exécution. Un seul navigateur pour l'exécution, nettoyé entre deux scénarios ; chaque étape attend ce qu'elle cherche, jamais une pause fixe ; la première étape qui échoue arrête son scénario et garde l'écran et le DOM. Chaque capture est comparée à sa baseline (ADR-0022) ; la commande n'existe que si Panther est installé.

## Trigger

| Element | Value |
|---|---|
| Entry point | `scenarios:run` (command) |
| Security | — |
| Preconditions | Panther et un ChromeDriver de la version de Chrome sont installés, et l'application répond à l'URL de base. |

## Journey

```mermaid
sequenceDiagram
  participant U as Développeur
  participant C as scenarios:run
  participant R as PantherRunner
  participant B as Chrome
  participant A as Application
  U->>C: devtools scenarios:run --base-url=…
  C->>R: scénarios, connexions, fixtures
  loop chaque scénario
    R->>B: cookies et en-têtes effacés, connexion
    B->>A: étapes : goto, fill, click, assert…
    A-->>B: pages
    R->>R: capture, comparaison à la baseline
  end
  R-->>C: verdicts
  C-->>U: une ligne par scénario, code 0 ou 1
```

## Decisions

**`DateTimeImmutable::timezone`**

```mermaid
flowchart TD
  d1{"SOURCE_DATE_EPOCH fixe l'instant (builds reproductibles)"}
  d1 -->|oui| v1["cet instant, au fuseau de la machine"]
  d1 -->|non| v2["maintenant"]
```

**`Jul6Art\DevTools\Command\ScenariosRunCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin n'est pas un dossier"}
  d1 -->|oui| v1["INVALID"]
  d1 -->|non| d2
  d2{"aucune URL de base, ni en option ni dans la configuration"}
  d2 -->|oui| v2["INVALID"]
  d2 -->|non| d3
  d3{"l'URL, le délai ou la fenêtre sont mal formés"}
  d3 -->|oui| v3["INVALID"]
  d3 -->|non| v4["rejoue : 0 si tout passe, 1 sinon"]
```

**`Jul6Art\DevTools\Scenario\Run\ScenarioResult::verdict`**

```mermaid
flowchart TD
  d1{"le scénario n'a pas pu commencer (connexion, fixture)"}
  d1 -->|oui| v1["error — ses étapes sont skipped"]
  d1 -->|non| v2["le pire verdict de ses étapes : error, puis failed, puis passed"]
```

**`element::textContent`**

```mermaid
flowchart TD
  d1{"un texte est donné à l'élément XML"}
  d1 -->|oui| v1["le texte devient le contenu"]
  d1 -->|non| v2["l'élément reste vide"]
```

## Data

Lit les scénarios, les fichiers de suivi des routes, la configuration et ses variables d'environnement ; écrit dans .devtools/runs/ le résultat, les captures, les DOM et les diffs. Les identifiants de connexion ne sont jamais écrits.

## Cross-cutting mechanisms

Aucun.

## Points of attention

ChromeDriver doit être de la version majeure de Chrome : celui de drivers/ passe d'abord. Une connexion HTTP basic passe par un en-tête posé par le protocole DevTools de Chrome, jamais par des identifiants dans l'URL. La décision sur `element::textContent` est celle de DomBuilder, sur le chemin de toute lecture ou écriture XML : elle n'est pas propre à ce workflow.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-10-20 | 4979790 | rédaction initiale |
| 2026-10-20 | 58c02fb | Aucun changement de fond : la configuration apprend les catégories de retours du projet, le client git et les chemins de .devtools ce que demandent les retours, les règles et le journal, sans rien changer de ce que fait la commande ; des tests s'ajoutent. La prose tient. (added test tests/Assertion/AssertionProposalTest.php (+10)) |
