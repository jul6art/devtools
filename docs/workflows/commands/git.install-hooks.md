# git:install-hooks
`command.git.install-hooks` · type : commands · dernière mise à jour : 2026-09-19 · commit : d9d6b8f

## Résumé

Installe les hooks git du projet : le contrôle sur `pre-commit`, les faits rafraîchis sur `post-merge` et `post-checkout`. **Un hook existant n'est jamais écrasé** — le bloc s'ajoute entre deux marqueurs — et aucun hook ne bloque un commit pour une raison technique.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `git:install-hooks` (command) |
| Sécurité | — |
| Préconditions | Le chemin est un dépôt git ; `core.hooksPath` est respecté quand le projet en configure un. |

## Parcours

```mermaid
sequenceDiagram
  participant U as développeur
  participant C as GitInstallHooksCommand
  participant H as GitHooks
  participant Git as git

  U->>C: git:install-hooks [--strict]
  C->>H: installation
  H->>Git: où sont les hooks (core.hooksPath ou .git/hooks)
  alt pas un dépôt git
    C-->>U: 1 — il n'y a rien à installer
  else
    loop pre-commit, post-merge, post-checkout
      H->>H: bloc ajouté entre les marqueurs, ou remplacé s'il y était
    end
    C-->>U: les hooks écrits
  end
```

## Navigation / états

—

## Décisions

**`Jul6Art\DevTools\Command\GitInstallHooksCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin est un dossier"}
  d1 -->|non| v1["INVALID"]
  d1 -->|oui| d2{"le dossier est un dépôt git"}
  d2 -->|non| v2["FAILURE — il n'y a pas de hook à installer"]
  d2 -->|oui| v3["SUCCESS, avec les hooks écrits"]
```

## Données

Écriture : les trois fichiers de hooks, rendus exécutables.

## Mécanismes transverses

—

## Points d'attention

- **Le hook laisse passer le commit quand DevTools n'est pas là**, ou quand le projet n'a pas de `.devtools/` : une gate qui bloque le travail à cause d'elle-même est une gate qu'on désinstalle, et avec elle la seule chose qui tenait la documentation à jour.
- **`--strict` refuse le commit** au lieu d'avertir : c'est un choix d'équipe, pas le défaut.
- **Installer deux fois remplace le bloc** au lieu de l'empiler.

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-19 | d9d6b8f | Rédaction initiale. |
