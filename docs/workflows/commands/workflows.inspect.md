# workflows:inspect
`command.workflows.inspect` · type: commands · last updated: 2026-10-20 · commit: 58c02fb

## Summary

Documente tous les workflows d'un projet : détection de stack, extraction des points d'entrée par l'adaptateur, graphe de dépendances, décision de fraîcheur, écriture des pages et des fichiers de suivi, index, menu et graphe d'ensemble. Une seconde exécution sur un projet inchangé ne modifie aucun fichier hors `reports/`. La commande annonce chaque étape et finit sur ce qu'elle a fait et ce que ça a coûté. Un scénario dont le workflow a disparu est signalé en avertissement, et ceux qu'une réécriture met à revoir sont listés dans le menu.

## Trigger

| Element | Value |
|---|---|
| Entry point | `workflows:inspect` (command) |
| Security | — |
| Preconditions | Le chemin donné est un dossier existant, et aucune autre inspection ne tourne sur ce projet. |

## Journey

```mermaid
sequenceDiagram
  participant U as Développeur
  participant C as workflows:inspect
  participant P as InspectionPipeline
  participant A as Adaptateur
  participant F as Fraîcheur
  participant D as .devtools/ + docs/
  U->>C: devtools workflows:inspect .
  C->>P: run(options, reporter)
  P->>A: extract(racine, stack, config)
  A-->>P: points d'entrée, mécanismes
  P->>P: graphe, décisions, groupement
  P->>F: ce qui a changé depuis la dernière révision
  F-->>P: créer / réécrire / garder
  P->>D: pages, suivi, index, menu, briefs
  P-->>C: rapport et compteurs
  C-->>U: bilan coloré
```

## Decisions

**`Jul6Art\DevTools\Command\WorkflowsInspectCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin n'est pas un dossier, ou une option est refusée"}
  d1 -->|oui| v1["INVALID — rien n'est écrit"]
  d1 -->|non| v2["le code de sortie du rapport : 0, 1 ou 2"]
```

**`Jul6Art\DevTools\Console\ConsoleProgressReporter::stage`**

```mermaid
flowchart TD
  d1{"une étape s'ouvre"}
  d1 -->|oui| v1["stage = son nom"]
  d1 -->|elle se ferme| v2["stage = vide"]
```

**`Jul6Art\DevTools\Console\ConsoleProgressReporter::bar`**

```mermaid
flowchart TD
  d1{"sortie décorée, non silencieuse, travail dénombrable"}
  d1 -->|oui| v1["bar = une ProgressBar"]
  d1 -->|non| v2["bar = null — une ligne par étape"]
```

**`Jul6Art\DevTools\Inspection\Graph\DecisionExtractor::scope`**

```mermaid
flowchart TD
  d1{"la classe a un nom qualifié"}
  d1 -->|oui| v1["scope = le nom qualifié"]
  d1 -->|non| d2{"elle a un nom court"}
  d2 -->|oui| v2["scope = le nom court"]
  d2 -->|non| v3["scope = ce qu'il était, ou vide"]
```

**`Jul6Art\DevTools\Inspection\Graph\DecisionExtractor::method`**

```mermaid
flowchart TD
  d1{"le nœud est une méthode de classe"}
  d1 -->|oui| v1["method = son nom"]
  d1 -->|non| v2["method = vide au début de chaque fichier"]
```

**`Jul6Art\DevTools\Inspection\Graph\DecisionExtractor::truncated`**

```mermaid
flowchart TD
  d1{"cinquante points atteints, ou trois conditions imbriquées"}
  d1 -->|oui| v1["truncated = true — le rapport le dit"]
  d1 -->|non| v2["truncated reste false"]
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

Lit le code du projet sans jamais l'exécuter, ses manifestes et son dépôt git ; écrit `.devtools/` et le dossier de documentation, plus un rapport horodaté. Les décisions viennent aussi des contraintes de validation d'une entité, des options d'un champ de formulaire et d'une méthode de la même classe appelée pour calculer la valeur ; un voter entre dans les mécanismes des routes qui demandent son attribut (ADR-0044).

## Cross-cutting mechanisms

Aucun listener : c'est une commande console. Le verrou `ProjectLock` interdit deux inspections simultanées sur le même projet.

## Points of attention

Les champs décidés listés ici sont ceux de l'outil lui-même — `scope`, `method`, `truncated` sont des états internes de l'extracteur, pas du métier. C'est la limite de l'extraction : elle trouve toute la logique conditionnelle atteinte, sans savoir laquelle intéresse un lecteur. Sur un projet applicatif, ce sont les champs d'entités qui remontent.

- **`--prune` emporte le dossier avec le README** : un contrôleur dont la dernière route disparaît ne laisse plus derrière lui un répertoire vide qui ne liste rien. Les workflows élagués sont désormais passés aux pages de groupe au lieu d'être écartés en cours de boucle.
- **Les écouteurs Doctrine entrent dans les mécanismes** : ils vivent sur le gestionnaire d'événements de Doctrine et non sur le répartiteur, donc la console ne les mentionnait jamais. Les deux sources sont fusionnées, et un écouteur déclaré par attribut est résolu.
- **Une page écrite sous un gabarit antérieur est remise à jour, même quand rien n'a bougé.** La fraîcheur ne réécrit que ce qu'un fait touche ; une page dont le code ne change jamais gardait donc ses titres français indéfiniment. Le rendu est comparé au fichier — la décision étant « inchangé », les deux doivent coïncider — et l'écart est réécrit. Rien d'autre ne bouge : ni révision, ni mode, ni brief.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-09-18 | b8049a4 | rédaction initiale |
| 2026-09-19 | d9d6b8f | added test tests/Bridge/PHPStan/WorkflowDriftTest.php (+3) |
| 2026-09-20 | 9e40da9 | Deux trous comblés dans le pipeline, trouvés en passant le système à l'épreuve : l'élagage laissait derrière lui le dossier d'un groupe vidé, et les écouteurs Doctrine n'apparaissaient sur aucune page. Le reste de la commande est inchangé. (files changed: src/Inspection/Adapter/Symfony/SymfonyAdapter.php, src/Inspection/InspectionPipeline.php) |
| 2026-09-21 | e5a6438 | l'outil passe en anglais : ce que la commande écrit, et les titres des pages qu'elle produit (files changed: src/Ai/PageDraft.php, src/Console/SummaryRenderer.php, src/Inspection/InspectionPipeline.php, src/Rendering/GroupPageRenderer.php, src/Rendering/GroupPageSection.php, src/Rendering/Labels.php, src/Rendering/MenuRenderer.php, src/Rendering/PageRenderer.php, src/Rendering/PageSection.php, src/Rendering/ParsedPage.php, src/Stack/Knowledge/KnowledgeCanvas.php, src/Stack/Knowledge/XmlKnowledgeBriefStore.php, src/Tracking/TrackingStatus.php) |
| 2026-09-21 | e5a6438 | l'inspection remet une page restée sous un gabarit antérieur dans le gabarit courant (files changed: src/Inspection/InspectionPipeline.php, src/Rendering/PageRenderer.php) |
| 2026-10-20 | 2885138 | Aucun changement de fond : la détection reconnaît désormais une application Node sans framework (ADR-0050) et le rendu du menu a gagné la relecture de sa section « Not covered », sans rien changer à ce que fait cette commande. La prose a été relue contre le code et tient. (added test tests/Ai/MenuAfterApplyTest.php (+1)) |
| 2026-10-20 | 4979790 | l'extraction lit plus loin — contraintes, formulaires, voters, un saut d'appel — et deux de ses champs entrent dans les huit dessinés quand deux en sortent ; le gabarit passe à neuf sections : la navigation et la machine à états ouvrent « Decisions » (ADR-0044) (added decision Jul6Art\DevTools\Inspection\Graph\DecisionExtractor::class: — → (new \PhpParser\NodeFinder())->findFirstInstanceOf($statements, \PhpParser\Node\Stmt\ClassLike::class) (+11)) |
| 2026-10-20 | 4979790 | files changed: src/Inspection/Freshness/GitClient.php, src/Tracking/FileRelation.php, src/Tracking/FilesToWorkflows.php |
| 2026-10-20 | 4979790 | Signale les scénarios orphelins et liste au menu ceux qu'une réécriture met à revoir ; la limite mémoire passe à 1 Go. (added test tests/Claude/ClaudeHookTest.php (+7)) |
| 2026-10-20 | 58c02fb | Aucun changement de fond : la configuration apprend les catégories de retours du projet, le client git et les chemins de .devtools ce que demandent les retours, les règles et le journal, sans rien changer de ce que fait la commande ; des tests s'ajoutent. La prose tient. (added test tests/Assertion/AssertionProposalTest.php (+8)) |
| 2026-10-20 | 58c02fb | Aucun changement de fond : le client git lit plus simplement les fichiers du projet et les commits d'une plage, sans rien changer de ce que fait la commande. La prose a été relue et tient. (files changed: src/Inspection/Freshness/GitClient.php) |
