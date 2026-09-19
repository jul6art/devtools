# workflows:review
`command.workflows.review` · type : commands · dernière mise à jour : 2026-09-19 · commit : d9d6b8f

## Résumé

La revue, fait par fait : le changement, son diff de code, et une question à trois réponses — accepter, refuser, passer. **Rien n'est écrit avant la dernière réponse** : une revue interrompue laisse le projet exactement comme il était. Sans terminal, la commande refuse de deviner et renvoie vers le contrôle.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `workflows:review` (command) |
| Sécurité | — |
| Préconditions | Le projet porte un `.devtools/` déjà inspecté, et la sortie est un terminal. |

## Parcours

```mermaid
sequenceDiagram
  participant U as relecteur
  participant C as WorkflowsReviewCommand
  participant R as ChangeReview
  participant P as InspectionPipeline

  U->>C: workflows:review
  alt pas de terminal
    C-->>U: 1 — « utilisez workflows:check ou workflows:diff »
  else
    C->>R: les faits changés, groupés
    loop chaque fait, le plus large d'abord
      C-->>U: le fait, ses deux valeurs, sa portée
      U-->>C: accepter, refuser ou passer
    end
    C->>P: UNE inspection, bornée aux workflows acceptés
    C-->>U: les pages réécrites, puis les commandes de restauration des refus
  end
```

## Navigation / états

—

## Décisions

**`DateTimeImmutable::timezone`**

```mermaid
flowchart TD
  d1{"SOURCE_DATE_EPOCH est posé et ne contient que des chiffres"}
  d1 -->|oui| v1["le fuseau par défaut de PHP — l'horloge devient reproductible, et une même exécution rend les mêmes octets"]
  d1 -->|non| v2["l'instant courant, tel quel"]
```

**`Jul6Art\DevTools\Command\WorkflowsReviewCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin est un dossier"}
  d1 -->|non| v1["INVALID"]
  d1 -->|oui| d2{"la sortie est interactive"}
  d2 -->|non| v2["FAILURE — une revue sans personne pour répondre n'est pas une revue"]
  d2 -->|oui| d3{"l'inspection a rencontré une erreur"}
  d3 -->|oui| v3["INVALID"]
  d3 -->|non| v4["SUCCESS — que l'on ait accepté, refusé ou tout passé"]
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

**`Jul6Art\DevTools\Rendering\MarkdownWriter::table`**

```mermaid
flowchart TD
  d1{"la table a des lignes"}
  d1 -->|non| v1["le tiret cadratin — une table vide se lit moins bien qu'un « rien »"]
  d1 -->|oui| v2["l'en-tête, le séparateur, puis les lignes"]
```

**`Jul6Art\DevTools\Rendering\PageRenderer::crossCutting`**

```mermaid
flowchart TD
  d1{"le workflow porte des mécanismes"}
  d1 -->|non| v1["le tiret cadratin"]
  d1 -->|oui| v2["la table : mécanisme, événement, priorité, ce qu'il écrit"]
```

**`Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary::deposit`**

```mermaid
flowchart TD
  d1{"la bibliothèque est accessible en écriture"}
  d1 -->|non| v1["NotWritable — le projet garde sa fiche, et l'exécution le dit"]
  d1 -->|oui| d2{"une fiche existe déjà pour cette clé"}
  d2 -->|oui| v2["AlreadyPresent — une fiche déposée n'est jamais écrasée"]
  d2 -->|non| v3["Deposited"]
```

**`Jul6Art\DevTools\Stack\StackDetector::detectors`**

```mermaid
flowchart TD
  d1{"une liste de détecteurs est fournie"}
  d1 -->|oui| v1["celle-là"]
  d1 -->|non| v2["les détecteurs natifs, dans l'ordre : Composer, Node, puis le repli par langage"]
```

## Données

Écriture, seulement pour les faits acceptés : les pages et les suivis des workflows qui les portent.

## Mécanismes transverses

—

## Points d'attention

- **Une seule inspection à la fin, quel que soit le nombre de faits acceptés** : deux passes ajouteraient deux révisions à la même page pour la même revue.
- **Refuser n'écrit rien ici non plus** : la commande imprime ce qu'il faut lancer, et c'est `workflows:reject --restore` qui exécute, jamais la revue.

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-19 | d9d6b8f | Rédaction initiale. |
