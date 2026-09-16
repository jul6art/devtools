# ADR-0031 — Pont Node pour l'analyse JavaScript et TypeScript

- **Statut** : Proposed — 2026-09-16
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

- [ ] `imports` résolus avec `paths` de `tsconfig` sur `angular-minimal`
- [ ] Décorateurs `@Component`, `@Injectable` et leurs arguments littéraux extraits
- [ ] `typescript` absent du projet → message explicite, pas de trace Node brute
- [ ] Un seul processus Node par scan (compteur)
- [ ] Le script ne lit aucun fichier hors de la racine (test avec un import `../../..`)
- [ ] Tests du script Node lui-même lancés par PHPUnit via `node --test`, ignorés avec raison si Node
      absent

## Conséquences

- Les adaptateurs JavaScript demandent Node sur la machine qui inspecte ; la voie Claude reste le repli.

## Dépendances

ADR-0015.
