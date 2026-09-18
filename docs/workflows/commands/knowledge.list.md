# knowledge:list
`command.knowledge.list` · type : commands · dernière mise à jour : 2026-09-18 · commit : b8049a4

## Résumé

Dit où vit la bibliothèque de connaissances de stack partagée par tous les projets de la machine, si elle est accessible en écriture, ce qu'elle contient et d'où chaque fiche vient. Les fiches que DevTools embarque et que la bibliothèque ne couvre pas sont nommées à la suite : on voit d'un coup ce qui sera demandé à Claude au prochain projet.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `knowledge:list` (command) |
| Sécurité | — |
| Préconditions | Aucune : sans projet ni configuration, la bibliothèque par défaut est listée. |

## Parcours

```mermaid
sequenceDiagram
  participant U as Développeur
  participant C as knowledge:list
  participant L as KnowledgeLibrary
  participant I as library.xml
  U->>C: devtools knowledge:list .
  C->>L: locate(env, config, paquet, ~/.devtools)
  L->>I: lecture de l'index
  I-->>L: clé, projet d'origine, date
  L-->>C: les fiches présentes
  C-->>U: chemin, écriture possible, table
```

## Navigation / états

—

## Décisions

**`Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary::deposit`**

```mermaid
flowchart TD
  d1{"la bibliothèque a déjà cette fiche"}
  d1 -->|oui| v1["AlreadyPresent — rien n'est écrasé"]
  d1 -->|non| d2{"la bibliothèque est accessible en écriture"}
  d2 -->|non| v2["NotWritable — la fiche reste dans le projet"]
  d2 -->|oui| v3["Deposited — la fiche et sa ligne d'index"]
```

**`element::textContent`**

```mermaid
flowchart TD
  d1{"un texte est donné à l'élément XML"}
  d1 -->|oui| v1["le texte devient le contenu"]
  d1 -->|non| v2["l'élément reste vide"]
```

## Données

Lit `library.xml` de la bibliothèque et les fiches présentes sur le disque ; lit la configuration du projet quand un chemin est donné. N'écrit rien.

## Mécanismes transverses

Aucun.

## Points d'attention

Une fiche déposée à la main dans le dossier est listée même si `library.xml` l'ignore : le disque fait foi sur ce qui existe, l'index sur ce qui vient d'où.

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-18 | b8049a4 | rédaction initiale |
