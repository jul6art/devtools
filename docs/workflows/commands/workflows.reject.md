# workflows:reject
`command.workflows.reject` · type : commands · dernière mise à jour : 2026-09-19 · commit : d9d6b8f

## Résumé

Le changement n'était pas voulu : **la documentation ne bouge pas, et c'est le code qui revient**. Sans `--restore`, la commande n'écrit rien du tout — elle imprime le `git restore` qui ramène le fichier à l'état où la page a été écrite. Avec, elle l'exécute : c'est la seule chose de tout DevTools qui touche au code d'un projet.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `workflows:reject` (command) |
| Sécurité | — |
| Préconditions | Le projet porte un `.devtools/` déjà inspecté, et les cibles nommées désignent des faits qui ont changé. |

## Parcours

```mermaid
sequenceDiagram
  participant U as relecteur
  participant C as WorkflowsRejectCommand
  participant R as ChangeReview
  participant Git as git

  U->>C: workflows:reject <cible>… [--restore]
  C->>R: les faits changés, groupés
  alt aucune cible ne correspond
    C-->>U: 2, avec la liste de ce qui a changé
  else sans --restore
    C-->>U: la commande à lancer, fichier par fichier
  else avec --restore
    loop chaque fichier refusé
      alt il porte un autre fait changé
        C-->>U: laissé à l'humain — le ramener effacerait l'autre
      else
        C->>Git: restauration depuis le commit du suivi
      end
    end
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

**`Jul6Art\DevTools\Command\WorkflowsRejectCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin est un dossier"}
  d1 -->|non| v1["INVALID"]
  d1 -->|oui| d2{"l'inspection a rencontré une erreur"}
  d2 -->|oui| v2["INVALID"]
  d2 -->|non| d3{"un fait a changé"}
  d3 -->|non| v3["SUCCESS — il n'y a rien à refuser"]
  d3 -->|oui| d4{"les cibles nommées désignent un fait changé"}
  d4 -->|non| v4["INVALID, avec la liste de ce qui a changé"]
  d4 -->|oui| d5{"--restore est demandé"}
  d5 -->|non| v5["SUCCESS — la commande de restauration est imprimée, rien n'est écrit"]
  d5 -->|oui| v6["SUCCESS si tout est ramené, 1 si un fichier porte un autre fait changé"]
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

Aucune écriture dans la documentation. Avec `--restore`, le code des fichiers refusés revient à son état d'origine.

## Mécanismes transverses

—

## Points d'attention

- **Un fichier qui porte un autre fait changé n'est pas restauré** : le ramener effacerait en silence un changement que personne n'a refusé.
- **La restauration ramène le fichier entier**, au commit d'où la page a été écrite : un fait accepté mais non commité repart avec lui.
- **Refuser ne fait pas taire le contrôle** : tant que le code n'est pas revenu, la dérive reste visible — c'est ce qui distingue un refus d'un « ignore ».

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-19 | d9d6b8f | Rédaction initiale. |
