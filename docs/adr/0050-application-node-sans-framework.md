# ADR-0050 — Une application Node sans framework est une stack

- **Statut** : Accepted — 2026-10-17 · Livré le 2026-10-17 (v3.2.0)
- **Décideurs** : jul6art
- **Specs** : § 4.3 (détection de stack), § 7.1 (monorepo)
- **Dépend de** : ADR-0005 (détection de stack), ADR-0013 (voie Claude pour les stacks sans adaptateur)

## Contexte

Tradebot est un monorepo : un back-office Symfony à la racine et, dans `bot/`, le runtime de trading
en Node 24 — sans framework (`mysql2`, `ccxt`, `ajv`…), avec ses points d'entrée dans `bin/`
(`runtime.js`, `account-worker.js`, `backtest-worker.js`). Mesuré le 2026-10-17 :

```
 Root   Language   Framework     Adapter   Knowledge
 .      php        symfony 7.4   symfony   symfony-7
```

`bot/` n'apparaît pas. `StackDetector` ne garde un sous-dossier, à côté d'une stack à la racine, que
s'il porte un framework. La règle a une raison, écrite dans le code : le `package.json` de Webpack
Encore, posé à côté d'une application Symfony, est de l'outillage, pas une stack. Mais elle écarte
aussi une application Node complète, dont les workflows (le superviseur, les workers, les commandes
du back-office qu'ils exécutent) ne seraient jamais documentés.

Alternatives écartées :

- *Épingler `bot/` à la main dans `stack.xml`* : `locked` corrige un élément d'une stack détectée, il
  n'en crée pas. Et chaque projet dans ce cas devrait le redécouvrir.
- *Garder tout sous-dossier qui a un `package.json`* : le `package.json` d'outillage redevient une
  stack, ce que la règle actuelle évite à raison.
- *Un adaptateur natif Node* (ADR-0031, 0033, `Proposed`) : il reste à écrire. La voie Claude
  (ADR-0013) documente déjà une stack sans adaptateur ; il manque seulement de la détecter.

## Décision

### 1. Une application, pas de l'outillage

`NodeDetector` reconnaît une **application Node sans framework** à deux signes réunis dans son
`package.json` :

- un **point d'entrée** : `bin` (chaîne ou table), `main`, ou un script `start` ;
- des **dépendances d'exécution** : `dependencies` non vide.

Le `package.json` d'Encore n'a ni l'un ni l'autre (des `devDependencies` et des scripts de build) : il
reste de l'outillage.

### 2. Son framework est `node`

Une application reconnue reçoit `framework="node"` : c'est son environnement d'exécution, et c'est ce
qui la fait garder par la règle des sous-dossiers, sans la changer. Sa version vient
d'`engines.node`, sinon de `.nvmrc` ou `.node-version` (majeure.mineure), sinon reste vide ; sa clé de
connaissance est `node-<majeure>`, ou `node` sans version. L'adaptateur reste `claude` (ADR-0013).

⚠️ Changement visible : un projet Node sans framework **à la racine**, qui avait jusqu'ici
`framework` vide et la clé `javascript`, passe à `node` à sa prochaine détection, sauf élément
épinglé. Rien d'autre ne change pour lui.

### 3. Ses sources : `src/` et les dossiers de ses points d'entrée

`main` et chaque entrée de `bin` désignent un fichier ; leurs dossiers rejoignent les sources
(`bin/` pour Tradebot), avec `src/` s'il existe.

### 4. JavaScript vérifié n'est pas TypeScript

Un `tsconfig.json` ne fait plus, seul, un projet TypeScript : avec `compilerOptions.checkJs` à vrai,
le projet écrit du JavaScript que `tsc` vérifie, et sa langue reste `javascript`.

## Hors périmètre

- Un adaptateur natif Node (ADR-0031, 0033).
- La détection au-delà des sous-dossiers immédiats (règle d'ADR-0005 inchangée : une seule liste de
  répertoire, jamais une descente dans l'arbre).

## Critères d'acceptation

- [x] Une fixture `node-app-monorepo` (Symfony à la racine, Encore dans le `package.json` racine, une
      application Node dans `worker/`) détecte deux stacks, et le `package.json` d'outillage n'en
      fait pas une troisième.
- [x] Un `package.json` avec un point d'entrée mais sans dépendances d'exécution, ou l'inverse,
      reste de l'outillage (tests).
- [x] La version vient d'`engines.node`, sinon de `.nvmrc`, sinon reste vide (tests).
- [x] `checkJs` garde la langue `javascript` ; un `tsconfig.json` sans lui donne `typescript` (tests).
- [x] Sur Tradebot : `bot` apparaît, `javascript`, `node`, adaptateur `claude`, sources `src`, `bin`.
- [x] Les fixtures existantes gardent leur `stack.xml` attendu, sauf la ligne du § 2 pour une
      application Node à la racine.
- [ ] `composer qa` vert, jeux `highest` et `lowest`.

## Conséquences

- Une application Node rangée à côté d'un back-office est documentée comme lui, par la voie Claude :
  une brève de connaissance, puis une brève de découverte, puis une page par workflow.

## Mise en œuvre (2026-10-17)

- Un `tsconfig.json` se lit sans `json()` : beaucoup portent des commentaires, que `json_decode`
  refuse. Illisible, il garde le verdict que sa seule présence donnait, TypeScript — rien ne lève.
- Les deux règles du § 1 et le § 4 vérifiés par mutation : retirer la condition des dépendances, ou
  celle de `checkJs`, fait échouer la suite.
