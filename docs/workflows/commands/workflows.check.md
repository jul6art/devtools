# workflows:check
`command.workflows.check` · type: commands · last updated: 2026-09-21 · commit: e5a6438

## Summary

La gate. Elle échoue quand la documentation ne correspond plus au code — un fait changé, un workflow sans page, une page sans workflow — et **elle n'écrit rien du tout**. La version qu'elle remplace régénérait les pages avant de prévenir : au moment où on lisait l'avertissement, il n'y avait plus rien à comparer.

## Trigger

| Element | Value |
|---|---|
| Entry point | `workflows:check` (command) |
| Security | — |
| Preconditions | Le projet porte un `.devtools/` déjà inspecté. |

## Journey

```mermaid
sequenceDiagram
  participant U as gate ou CI
  participant C as WorkflowsCheckCommand
  participant P as InspectionPipeline
  participant R as CheckRenderer

  U->>C: workflows:check [--require-ai] [--strict] [--format=github]
  C->>P: inspection en lecture seule
  P-->>C: décisions de fraîcheur et faits changés
  C->>R: les causes, une ligne chacune
  R-->>C: et, à part, les workflows dont le code a changé sans qu'aucun fait ne bouge
  alt aucune cause
    C-->>U: 0 — rien à signaler, et leur nombre
  else
    C-->>U: 1, ce qu'il faut relire, et leur nombre
  end
```

## Navigation / states

—

## Decisions

**`DateTimeImmutable::timezone`**

```mermaid
flowchart TD
  d1{"SOURCE_DATE_EPOCH est posé et ne contient que des chiffres"}
  d1 -->|oui| v1["le fuseau par défaut de PHP — l'horloge devient reproductible, et une même exécution rend les mêmes octets"]
  d1 -->|non| v2["l'instant courant, tel quel"]
```

**`Jul6Art\DevTools\Command\WorkflowsCheckCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin est un dossier"}
  d1 -->|non| v1["INVALID"]
  d1 -->|oui| d2{"le format demandé est text ou github"}
  d2 -->|non| v2["INVALID"]
  d2 -->|oui| d3{"l'inspection a rencontré une erreur"}
  d3 -->|oui| v3["INVALID — le projet n'a pas pu être inspecté"]
  d3 -->|non| d4{"une cause est trouvée"}
  d4 -->|non| v4["SUCCESS — rien à signaler"]
  d4 -->|oui| v5["1 — la gate est rouge, et elle dit sur quoi"]
```

**`Jul6Art\DevTools\Inspection\Graph\DecisionExtractor::method`**

```mermaid
flowchart TD
  d1{"le nœud visité est une méthode"}
  d1 -->|oui| v1["son nom — c'est lui qui situera la décision"]
  d1 -->|non| v2["la chaîne vide : on sort d'une méthode"]
```

**`Jul6Art\DevTools\Inspection\Graph\DecisionExtractor::scope`**

```mermaid
flowchart TD
  d1{"le nœud visité est une classe, une interface ou un trait"}
  d1 -->|non| v1["la portée ne change pas"]
  d1 -->|oui| d2{"le nom complet est résolu"}
  d2 -->|oui| v2["le nom pleinement qualifié — c'est lui qui préfixe les cibles"]
  d2 -->|non| d3{"la classe a un nom court"}
  d3 -->|oui| v3["le nom court, faute de mieux"]
  d3 -->|non| v4["la chaîne vide : une classe anonyme ne nomme rien"]
```

**`Jul6Art\DevTools\Inspection\Graph\DecisionExtractor::truncated`**

```mermaid
flowchart TD
  d1{"la profondeur d'imbrication atteint la borne"}
  d1 -->|oui| v1["tronqué — la page le dira plutôt que de laisser croire à une liste complète"]
  d1 -->|non| d2{"le nombre de décisions atteint la borne"}
  d2 -->|oui| v2["tronqué"]
  d2 -->|non| v3["la collecte continue"]
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

**`Jul6Art\DevTools\Inspection\Model\Edge::label`**

```mermaid
flowchart TD
  d1{"un libellé est donné"}
  d1 -->|oui| v1["le libellé, refusé s'il est vide — un libellé blanc dans un graphe est un bruit"]
  d1 -->|non| v2["null : l'arête n'est pas étiquetée"]
```

## Data

Lecture : les suivis et le code. Aucune écriture.

## Cross-cutting mechanisms

—

## Points of attention

- **Un fichier modifié sans fait changé n'est pas une cause** : c'est toute la différence avec la fraîcheur, et elle est délibérée — une gate qui réveille pour un commentaire ajouté est une gate qu'on désactive.
- **`--require-ai` est une exigence distincte** : une page qui ne porte que des faits est juste, mais elle n'explique rien ; les projets qui veulent la prose l'exigent explicitement.
- **Le format GitHub annote le fichier et la ligne**, pour que la pull request montre l'endroit plutôt qu'un résumé.
- **Un code changé sans fait changé est COMPTÉ, pas bloquant** : la gate dit combien de workflows sont dans ce cas, parce que leur prose peut être fausse — une condition réécrite dans une méthode, une constante renommée, une valeur par défaut inversée. `--strict` en fait une cause d'échec, pour les projets qui veulent relire à chaque fois.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-19 | d9d6b8f | Rédaction initiale. |
| 2026-09-20 | 9e40da9 | La commande gagne `--strict` et une ligne de compte. Ce qui était tu — un fichier modifié sans fait changé — est maintenant dit, sans faire échouer : la gate annonce le nombre, et `--strict` le transforme en cause. Le format GitHub porte la même annotation. (files changed: src/Command/WorkflowsCheckCommand.php, src/Console/CheckRenderer.php, src/Inspection/Adapter/Symfony/SymfonyAdapter.php, src/Inspection/InspectionPipeline.php) |
| 2026-09-21 | e5a6438 | l'outil passe en anglais : ce que la commande écrit, et les titres des pages qu'elle produit (files changed: src/Ai/PageDraft.php, src/Command/WorkflowsCheckCommand.php, src/Console/ChangeRenderer.php, src/Console/CheckRenderer.php, src/Inspection/InspectionPipeline.php, src/Rendering/GroupPageRenderer.php, src/Rendering/GroupPageSection.php, src/Rendering/Labels.php, src/Rendering/MenuRenderer.php, src/Rendering/PageRenderer.php, src/Rendering/PageSection.php, src/Rendering/ParsedPage.php, src/Stack/Knowledge/KnowledgeCanvas.php, src/Stack/Knowledge/XmlKnowledgeBriefStore.php, src/Tracking/TrackingStatus.php) |
| 2026-09-21 | e5a6438 | l'en-tête de l'historique passe en anglais, et une page restée sous un gabarit antérieur est remise à jour (files changed: src/Inspection/InspectionPipeline.php, src/Rendering/PageRenderer.php) |
