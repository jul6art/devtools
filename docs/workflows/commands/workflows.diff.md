# workflows:diff
`command.workflows.diff` · type: commands · last updated: 2026-10-20 · commit: 58c02fb

## Summary

Ce qui a changé **dans les workflows** depuis que leurs pages ont été écrites : un fait, sa valeur d'avant, celle d'après, et les workflows qu'il touche. La sortie groupe **par fait et jamais par workflow** — sur un projet réel, 233 des 259 workflows traversent la même entité, et une ligne changée dans un de ses mutateurs est une chose à lire, pas 233. La commande n'écrit rien : elle exécute le pipeline en lecture seule.

## Trigger

| Element | Value |
|---|---|
| Entry point | `workflows:diff` (command) |
| Security | — |
| Preconditions | Le projet porte un `.devtools/` déjà inspecté : le diff compare les suivis au modèle qu'il vient de reconstruire. |

## Journey

```mermaid
sequenceDiagram
  participant U as développeur
  participant C as WorkflowsDiffCommand
  participant P as InspectionPipeline
  participant G as ChangeGroup
  participant Git as git

  U->>C: workflows:diff [--code] [--exit-code]
  C->>P: inspection en lecture seule
  P->>P: pour chaque workflow, l'ancien suivi contre le nouveau modèle
  P-->>C: les changements, par workflow
  C->>G: regroupement par fait, le plus large d'abord
  opt --code
    C->>Git: un diff par référence distincte, restreint aux fichiers des faits
  end
  C-->>U: un bloc par fait, et 1 si --exit-code avec au moins un changement
```

## Decisions

**`DateTimeImmutable::timezone`**

```mermaid
flowchart TD
  d1{"SOURCE_DATE_EPOCH est posé et ne contient que des chiffres"}
  d1 -->|oui| v1["le fuseau par défaut de PHP — l'horloge devient reproductible, et une même exécution rend les mêmes octets"]
  d1 -->|non| v2["l'instant courant, tel quel"]
```

**`Jul6Art\DevTools\Command\WorkflowsDiffCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin est un dossier"}
  d1 -->|non| v1["INVALID — on ne devine pas un projet"]
  d1 -->|oui| d2{"le format demandé est text ou md"}
  d2 -->|non| v2["INVALID, avec les deux formats possibles"]
  d2 -->|oui| d3{"l'inspection a rencontré une erreur"}
  d3 -->|oui| v3["INVALID — un diff calculé sur un modèle incomplet mentirait"]
  d3 -->|non| v4["SUCCESS, ou 1 avec --exit-code si un fait a changé"]
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

**`Jul6Art\DevTools\Inspection\Graph\DecisionExtractor::class`**

```mermaid
flowchart TD
  d1{"la lecture rencontre une déclaration de classe"}
  d1 -->|oui| v1["cette classe : un appel de méthode s'y résout"]
  d1 -->|non| v2["la première classe du fichier, trouvée au départ"]
```

**`Jul6Art\DevTools\Inspection\Graph\DecisionExtractor::dataClass`**

```mermaid
flowchart TD
  d1{"le fichier déclare un data_class par constante de classe"}
  d1 -->|oui| v1["cette entité : les champs du formulaire décident ses propriétés"]
  d1 -->|non| v2["aucune : les choix restent sur le formulaire, en confiance moyenne"]
```

## Data

Lecture : les fichiers de suivi et le code du projet. Aucune écriture — pas même un rapport.

## Cross-cutting mechanisms

—

## Points of attention

- **Un fichier dont les octets changent sans qu'aucun fait ne bouge ne produit aucun changement** : c'est la règle qui rend l'outil utilisable après un refactoring, et `file:line` n'entre jamais dans l'identité d'un fait.
- **`--code` prend le commit dans le suivi** : chaque page sait de quel commit elle a été écrite, donc il n'y a rien à stocker pour retrouver le diff.
- **Le coût est celui d'une inspection** : la comparaison elle-même n'ajoute ni entrée-sortie ni processus, parce qu'elle a lieu là où les deux versions sont déjà en mémoire.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-19 | d9d6b8f | Rédaction initiale. |
| 2026-09-20 | 9e40da9 | Aucun changement de fond : l'adaptateur Symfony fusionne désormais deux sources de mécanismes, ce qui enrichit le modèle sans rien changer à la façon dont le diff le compare. La prose a été relue contre le code et tient. (files changed: src/Inspection/Adapter/Symfony/SymfonyAdapter.php, src/Inspection/InspectionPipeline.php) |
| 2026-09-21 | e5a6438 | l'outil passe en anglais : ce que la commande écrit, et les titres des pages qu'elle produit (files changed: src/Ai/PageDraft.php, src/Console/ChangeRenderer.php, src/Inspection/InspectionPipeline.php, src/Rendering/GroupPageRenderer.php, src/Rendering/GroupPageSection.php, src/Rendering/Labels.php, src/Rendering/MenuRenderer.php, src/Rendering/PageRenderer.php, src/Rendering/PageSection.php, src/Rendering/ParsedPage.php, src/Stack/Knowledge/KnowledgeCanvas.php, src/Stack/Knowledge/XmlKnowledgeBriefStore.php, src/Tracking/TrackingStatus.php) |
| 2026-09-21 | e5a6438 | l'en-tête de l'historique passe en anglais, et une page restée sous un gabarit antérieur est remise à jour (files changed: src/Inspection/InspectionPipeline.php, src/Rendering/PageRenderer.php) |
| 2026-10-20 | 2885138 | Aucun changement de fond : la détection reconnaît désormais une application Node sans framework (ADR-0050) et le rendu du menu a gagné la relecture de sa section « Not covered », sans rien changer à ce que fait cette commande. La prose a été relue contre le code et tient. (added test tests/Ai/MenuAfterApplyTest.php (+1)) |
| 2026-10-20 | 4979790 | l'extraction lit plus loin — contraintes, formulaires, voters, un saut d'appel — et deux de ses champs entrent dans les huit dessinés quand deux en sortent ; le gabarit passe à neuf sections : la navigation et la machine à états ouvrent « Decisions » (ADR-0044) (added decision Jul6Art\DevTools\Inspection\Graph\DecisionExtractor::class: — → (new \PhpParser\NodeFinder())->findFirstInstanceOf($statements, \PhpParser\Node\Stmt\ClassLike::class) (+10)) |
| 2026-10-20 | 4979790 | files changed: src/Inspection/Freshness/GitClient.php, src/Tracking/FileRelation.php, src/Tracking/FilesToWorkflows.php |
| 2026-10-20 | 4979790 | L'analyse relève désormais la limite mémoire de PHP à 1 Go, comme l'inspection ; des tests s'ajoutent au périmètre et symfony/process passe en 8.1.9. La prose a été relue et tient. (added test tests/Claude/ClaudeHookTest.php (+7)) |
| 2026-10-20 | 58c02fb | Aucun changement de fond : la configuration apprend les catégories de retours du projet, le client git et les chemins de .devtools ce que demandent les retours, les règles et le journal, sans rien changer de ce que fait la commande ; des tests s'ajoutent. La prose tient. (added test tests/Assertion/AssertionProposalTest.php (+9)) |
| 2026-10-20 | 58c02fb | Aucun changement de fond : le client git lit plus simplement les fichiers du projet et les commits d'une plage, sans rien changer de ce que fait la commande. La prose a été relue et tient. (files changed: src/Inspection/Freshness/GitClient.php) |
