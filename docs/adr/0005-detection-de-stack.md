# ADR-0005 — Détection de stack et `stack.xml`

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 4.3 étape 1, § 7.1, § 7.2 (famille détection de stack)

## Contexte

Tout le reste dépend de la réponse à « qu'est-ce que ce projet ? » : quel adaptateur utiliser, quelles
connaissances charger, quels dossiers parcourir, lesquels ignorer. Le § 4.3 veut une détection par
heuristiques sur la racine, écrite dans `stack.xml`, **modifiable à la main et relue à chaque
exécution**.

## Décision

### Détecteurs (namespace `Stack\Detector\`)

Un `StackDetectorInterface` par écosystème, tous exécutés, résultats fusionnés :

| Détecteur | Fichier lu | Détecte |
|---|---|---|
| `ComposerDetector` | `composer.json`, `composer.lock` | PHP (contrainte), `symfony/framework-bundle`, `laravel/framework` — version **résolue** lue dans le lock, sinon contrainte |
| `NodeDetector` | `package.json`, lock npm/yarn/pnpm | Node, `@angular/core`, `next`, `express` |
| `LanguageOnlyDetector` (un jeu de règles par manifeste) | `go.mod`, `pyproject.toml`, `Cargo.toml`, `pom.xml` | langage et gestionnaire uniquement |

- Un monorepo produit **plusieurs** entrées `<stack>` (§ 7.1 `monorepo`), chacune avec sa racine
  relative.
- Lecture des fichiers de manifeste **sans exécuter quoi que ce soit** du projet ; `pyproject.toml`
  et `Cargo.toml` : lecture ligne à ligne des seules clés utiles, pas de parseur TOML (aucune
  dépendance).
- `StackProfile` (objet) : `language`, `framework?`, `version?` (majeure.mineure), `packageManager`,
  `root`, `sourceDirs`, `excludedDirs`, `adapter` (nom de l'adaptateur natif retenu, ou `claude`),
  `knowledgeKey` (`symfony-7`, `angular-18`…).
- **Dossiers sources probables** : conventions par framework (`src/`, `templates/`, `config/`,
  `migrations/` pour Symfony ; `src/app/` pour Angular ; `autoload.psr-4` de `composer.json`) ;
  **exclus par défaut** : `vendor/`, `node_modules/`, `var/`, `dist/`, `build/`, `.git/`,
  `.devtools/`, plus ceux de `config.xml`.
- **Nom du projet** (titre du menu) : `name` de `composer.json` ou `package.json`, sinon nom du
  dossier.

### `stack.xml` et les modifications humaines

- Écrit à chaque exécution, validé par `stack.xsd`.
- Un élément portant **`locked="true"`** n'est jamais modifié par la détection : c'est ainsi qu'un
  humain corrige une version, ajoute un dossier source ou force un adaptateur. Tout le reste est
  recalculé.
- Réécrire un `stack.xml` dont rien n'a changé produit les mêmes octets (ADR-0004).

### Commande

`devtools stack:detect [path]` affiche le profil et écrit `stack.xml` ; `workflows:inspect`
l'appelle en première étape.

## Budget d'exécution

Lecture de manifestes à la racine (et aux racines des sous-projets déclarés) : **aucun parcours de
l'arborescence** à cette étape.

## Hors périmètre

Détection d'une stack par le contenu des sources (sans manifeste) ; versions d'outils système
(`php -v`, `node -v`) ; Laravel, Angular, Express au-delà de la détection (leurs adaptateurs natifs
sont hors MVP, la voie Claude les couvre — ADR-0013).

## Critères d'acceptation

- [x] Projets-fixtures créés : `symfony-minimal`, `plain-php`, `node-express`, `angular-minimal`,
      `monorepo` — avec leur `stack.xml` attendu, comparé en snapshot
- [x] La version Symfony vient du `composer.lock` quand il existe, de la contrainte sinon (deux tests)
- [x] Un élément `locked="true"` survit à une nouvelle détection qui le contredit
- [x] Un projet sans manifeste reconnu produit un `stack.xml` valide, langage `unknown`, adaptateur
      `claude`
- [x] Un manifeste JSON malformé produit une erreur qui nomme le fichier, pas une exception PHP brute
- [x] Deux exécutions consécutives : `stack.xml` inchangé à l'octet

## Conséquences

- Un projet mal détecté se corrige dans `stack.xml`, pas par une option de commande : la correction
  est versionnée et partagée par l'équipe.

## Dépendances

ADR-0004.
