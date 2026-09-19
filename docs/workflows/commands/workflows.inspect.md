# workflows:inspect
`command.workflows.inspect` · type : commands · dernière mise à jour : 2026-09-19 · commit : d9d6b8f

## Résumé

Documente tous les workflows d'un projet : détection de stack, extraction des points d'entrée par l'adaptateur, graphe de dépendances, décision de fraîcheur, écriture des pages et des fichiers de suivi, index, menu et graphe d'ensemble. Une seconde exécution sur un projet inchangé ne modifie aucun fichier hors `reports/`. La commande annonce chaque étape et finit sur ce qu'elle a fait et ce que ça a coûté.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `workflows:inspect` (command) |
| Sécurité | — |
| Préconditions | Le chemin donné est un dossier existant, et aucune autre inspection ne tourne sur ce projet. |

## Parcours

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

## Navigation / états

—

## Décisions

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

**`Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor::parser`**

```mermaid
flowchart TD
  d1{"un parseur est fourni"}
  d1 -->|oui| v1["celui-là"]
  d1 -->|non| v2["celui de la dernière version de PHP supportée"]
```

**`Jul6Art\DevTools\Inspection\Model\Edge::label`**

```mermaid
flowchart TD
  d1{"l'arête porte un libellé"}
  d1 -->|oui| v1["le libellé, non vide"]
  d1 -->|non| v2["label = null"]
```

## Données

Lit le code du projet sans jamais l'exécuter, ses manifestes et son dépôt git ; écrit `.devtools/` et le dossier de documentation, plus un rapport horodaté.

## Mécanismes transverses

Aucun listener : c'est une commande console. Le verrou `ProjectLock` interdit deux inspections simultanées sur le même projet.

## Points d'attention

Les champs décidés listés ici sont ceux de l'outil lui-même — `scope`, `method`, `truncated` sont des états internes de l'extracteur, pas du métier. C'est la limite de l'extraction : elle trouve toute la logique conditionnelle atteinte, sans savoir laquelle intéresse un lecteur. Sur un projet applicatif, ce sont les champs d'entités qui remontent.

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-18 | b8049a4 | rédaction initiale |
| 2026-09-19 | d9d6b8f | added test tests/Bridge/PHPStan/WorkflowDriftTest.php (+3) |
