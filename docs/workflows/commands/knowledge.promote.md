# knowledge:promote
`command.knowledge.promote` · type : commands · dernière mise à jour : 2026-09-19 · commit : d9d6b8f

## Résumé

Copie une fiche de connaissance — de la bibliothèque partagée, ou du `.devtools/knowledge/` d'un projet avec `--from` — dans `resources/knowledge/` de DevTools, pour qu'elle parte avec le paquet publié. Le dépôt dans la bibliothèque est automatique ; l'embarquer dans le paquet ne l'est pas : cette commande prépare la pull request, un humain la relit.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `knowledge:promote` (command) |
| Sécurité | — |
| Préconditions | DevTools tourne depuis une copie source, et la fiche existe quelque part. |

## Parcours

```mermaid
sequenceDiagram
  participant U as Développeur
  participant C as knowledge:promote
  participant L as KnowledgeLibrary
  participant K as KnowledgeCanvas
  participant R as resources/knowledge/
  U->>C: devtools knowledge:promote angular-18
  C->>L: la fiche, dans la bibliothèque ou --from
  L-->>C: le Markdown
  C->>K: problems(markdown)
  K-->>C: conforme au canevas
  C->>R: écriture
  C-->>U: à committer
```

## Navigation / états

—

## Décisions

**`Jul6Art\DevTools\Command\KnowledgePromoteCommand::execute`**

```mermaid
flowchart TD
  d1{"resources/knowledge n'est pas accessible en écriture"}
  d1 -->|oui| v1["FAILURE — hors copie source"]
  d1 -->|non| d2{"DevTools livre déjà cette clé"}
  d2 -->|oui| v2["FAILURE — corriger le fichier livré"]
  d2 -->|non| d3{"aucune fiche à promouvoir, ou canevas non respecté"}
  d3 -->|oui| v3["FAILURE — la raison est nommée"]
  d3 -->|non| v4["SUCCESS — fiche copiée"]
```

**`Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary::deposit`**

```mermaid
flowchart TD
  d1{"la bibliothèque a déjà cette fiche"}
  d1 -->|oui| v1["AlreadyPresent"]
  d1 -->|non| d2{"accessible en écriture"}
  d2 -->|non| v2["NotWritable"]
  d2 -->|oui| v3["Deposited"]
```

**`element::textContent`**

```mermaid
flowchart TD
  d1{"un texte est donné à l'élément XML"}
  d1 -->|oui| v1["le texte devient le contenu"]
  d1 -->|non| v2["l'élément reste vide"]
```

## Données

Lit une fiche Markdown et la configuration du projet ; écrit dans `resources/knowledge/` du paquet.

## Mécanismes transverses

Aucun.

## Points d'attention

La promotion ne vérifie que le canevas, pas la justesse : une fiche fausse promue fausse toutes les rédactions de la stack. C'est la relecture de la pull request qui tranche.

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-18 | b8049a4 | rédaction initiale |
| 2026-09-19 | d9d6b8f | added test tests/Project/GitHooksTest.php |
