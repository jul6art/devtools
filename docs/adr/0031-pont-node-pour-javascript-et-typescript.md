# ADR-0031 — Pont Node pour l'analyse JavaScript et TypeScript

- **Statut** : Accepted — 2026-10-21 (proposée le 2026-09-16 ; phase 3 lancée par jul6art le 2026-10-21) · Livré le 2026-10-21
- **Décideurs** : jul6art
- **Specs** : § 4.3 étapes 3 et 4, § 9 (phase 3 : adaptateurs Angular, Node)

## Contexte

Angular et Express (ADR-0032, 0033) ont besoin de ce que l'ADR-0006 fait pour PHP : lire les imports,
les décorateurs, les appels littéraux. Parser TypeScript en PHP n'est ni fiable ni raisonnable. Le
compilateur TypeScript, lui, est **déjà installé** dans tout projet Angular, et Node l'est sur la
machine de quiconque y développe.

Alternatives écartées :
- *Parseur TypeScript en PHP* — aucun n'est maintenu à la hauteur de la syntaxe réelle (décorateurs,
  génériques, `satisfies`).
- *Binaire compilé embarqué (esbuild, swc)* — un binaire par plateforme dans un paquet Composer.

## Décision

### Le script

`resources/node/devtools-analyze.mjs`, sans dépendance propre : il charge `typescript` **depuis le
`node_modules` du projet analysé** (`createRequire(projectRoot)`), sinon échoue avec un message clair.

- Entrée (stdin, JSON) : racine, liste de fichiers, requêtes demandées.
- Requêtes : `imports` (spécifieurs résolus avec `tsconfig` : `paths`, `baseUrl`), `decorators`
  (nom, arguments littéraux), `calls` (appels à un nom donné avec arguments littéraux :
  `router.get('/x', …)`, `path: 'orders'`), `exports`.
- Sortie (stdout, JSON) : faits par fichier, **aucune interprétation** — c'est l'adaptateur PHP qui
  décide ce qu'est un point d'entrée.

### Côté PHP

- `Inspection\Node\NodeBridge` : lance **un** processus `node` par scan avec tous les fichiers, via
  `symfony/process`, commande configurable (`<node binary="node"/>`, préfixe Docker possible).
- `Inspection\Graph\TypeScriptDependencyResolver` : même contrat que `DependencyResolver` (ADR-0006),
  alimenté par les `imports` ; un spécifieur de `node_modules` devient un `PackageRef` avec la version
  du lock npm/yarn/pnpm.
- Sortie Node validée (structure attendue) ; invalide → erreur qui cite les premières lignes.

## Budget d'exécution

**Un** processus Node par scan, quel que soit le nombre de fichiers ; la liste est limitée aux dossiers
sources de la stack.

## Hors périmètre

Vue, React, Svelte ; JavaScript sans `typescript` dans le projet (le script refuse, la voie Claude
reste disponible) ; analyse de types.

## Critères d'acceptation

- [x] `imports` résolus avec `paths` de `tsconfig` sur `angular-minimal` *(`NodeBridgeTest`, et les tests Node du script)*
- [x] Décorateurs `@Component`, `@Injectable` et leurs arguments littéraux extraits
- [x] `typescript` absent du projet → message explicite, pas de trace Node brute *(et Node absent : « `node` is not on the PATH »)*
- [x] Un seul processus Node par scan (compteur) *(`NodeBridge::calls()`, rapporté parmi les appels de console)*
- [x] Le script ne lit aucun fichier hors de la racine (test avec un import `../../..`)
- [x] Tests du script Node lui-même lancés par PHPUnit via `node --test`, ignorés avec raison si Node
      absent *(`tests/Node/`, `NodeScriptTest`)*

## Écarts assumés à la livraison

- **Requêtes ajoutées** : `news` (`new Worker(…)` de BullMQ, ADR-0033), `variables` (valeurs littérales de
  premier niveau : une table `Routes`, `module.exports`), `classes` (ce qu'une classe injecte), et les noms
  importés de chaque import — sans eux, `provideRouter(routes)` ne mène pas à sa table.
- **Le TypeScript doit se résoudre dans le projet** : un `typescript` trouvé au-dessus de sa racine est
  refusé comme absent. Dans un phar (ADR-0034), le script est recopié une fois dans le répertoire
  temporaire, Node ne lisant pas `phar://`.
- **Les tests JavaScript** sont les `*.spec.*` / `*.test.*` et les fichiers des dossiers de tests qui
  importent un fichier du premier saut ; un spec à côté du code n'est plus « non couvert ».

## Conséquences

- Les adaptateurs JavaScript demandent Node sur la machine qui inspecte ; la voie Claude reste le repli.

## Dépendances

ADR-0015.
