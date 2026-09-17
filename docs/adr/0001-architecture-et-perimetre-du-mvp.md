# ADR-0001 — Architecture du cœur et périmètre du MVP

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 2 (P1), § 3, § 4, § 6, § 7, § 9 (phase 0), § 12

## Contexte

Le premier objectif est un **MVP qui couvre toute la problématique P1** : analyser un projet dans
n'importe quel langage, produire et **maintenir** une documentation normée de ses workflows, avec un
XML de suivi qui évite de réécrire ce qui n'a pas changé, en standalone ou en `require-dev` Symfony.
Tout ce qui sert P2 (scénarios, retours, règles, gate) et les idées du § 4.8 attend.

Il faut fixer une fois pour toutes l'architecture que tous les lots du MVP remplissent, l'ordre dans
lequel ils la remplissent, et trancher les questions ouvertes du § 12 qui bloqueraient un lot.

Alternatives écartées :
- *Construire le pipeline étape par étape dans l'ordre du § 4.3* — rien de démontrable avant la
  dixième étape ; on préfère une **tranche verticale factuelle** (sans IA) le plus tôt possible.
- *Commencer par la voie Claude, langage-agnostique d'emblée* — le non-déterminisme rend les tests
  fragiles tant que le modèle, le rendu et le suivi ne sont pas stables ; la voie native Symfony
  sert d'étalon.

## Décision

### Le périmètre du MVP

Le MVP est la phase 0 du § 9, **moins** l'intégration à `symfony-skeleton-generator` (qui attend une
version publiée). Il est découpé en quatre étapes, chacune démontrable :

| Étape | ADR | Ce qu'on peut démontrer à la fin |
|---|---|---|
| **1 — Socle** | 0002, 0003, 0004 | le modèle, les formats et leurs schémas existent et se relisent à l'identique |
| **2 — Tranche verticale factuelle** | 0005, 0006, 0007, 0008, 0009 | `devtools workflows:inspect --no-ai` sur un projet Symfony produit un `.devtools/` exact |
| **3 — Fraîcheur** | 0010 | un re-scan ne réécrit que ce qui a changé, et dit pourquoi |
| **4 — Rédaction et langages** | 0011, 0012, 0013, 0014 | Claude Code rédige les pages ; une stack sans adaptateur est documentée |
| **Livraison** | 0015 | P1 est livré, testé de bout en bout, et DevTools documente ses propres workflows |

### Le pipeline et son découpage en code

Chaque étape du § 4.3 a un propriétaire unique :

| § 4.3 | Étape | Namespace | ADR |
|---|---|---|---|
| 1 | Détection de stack | `Stack\` | 0005 |
| 2 | Connaissances de stack | `Stack\Knowledge\` | 0012 |
| 3 | Points d'entrée | `Inspection\Adapter\` | 0007, 0013, 0014 |
| 4 | Dépendances | `Inspection\Graph\` | 0006 |
| 5 | Regroupement, identifiants | `Inspection\Model\`, `Inspection\Graph\` | 0003, 0006 |
| 6 | Fraîcheur | `Inspection\Freshness\` | 0010 |
| 7 | Rédaction | `Rendering\`, `Ai\` | 0008, 0011 |
| 8 | XML de suivi | `Tracking\` | 0004 |
| 9 | Menu `workflows.md` | `Rendering\` | 0008 |
| 10 | Rapport | `Command\` | 0009 |

`Inspection\InspectionPipeline` orchestre ; les commandes (`Command\`) ne font que lire les options, appeler le
pipeline et afficher. **Rien hors de `Bridge\Symfony\` ne dépend de `symfony/http-kernel`.**

### Questions ouvertes du § 12 — tranchées pour le MVP

| Question | Décision MVP | Où |
|---|---|---|
| 1. API Claude ou Claude Code ? | **Claude Code**, sans clé ni client HTTP ; l'API vient après le MVP | ADR-0002 |
| 2. Profondeur du graphe ? | **3 niveaux** de fichiers du projet depuis le point d'entrée, configurable | ADR-0006 |
| 3. `knowledge` remonté dans le cœur par PR ? | **Non.** Remontée manuelle | ADR-0012 |
| 4. Un gabarit ou des variantes ? | **Un seul gabarit** pour tous les types ; section sans objet = `—` | ADR-0008 |
| 5. Distribution ? | **Composer** (`global` et `require-dev`) ; phar et Docker après | ADR-0015 |

### Dépendances Composer du cœur, et le lot qui les introduit

| Paquet | Lot |
|---|---|
| `ext-dom`, `ext-libxml`, `symfony/filesystem` | 0004 |
| `nikic/php-parser` | 0006 |
| `symfony/process` | 0007 |

Aucune autre sans ADR. En particulier : pas de `symfony/yaml` dans le cœur (la configuration d'un
projet Symfony se lit par sa console, ADR-0007), pas de bibliothèque JSON Schema (ADR-0003).

### Conventions de test communes à tous les lots

- **Projets-fixtures** : `tests/Fixtures/projects/<nom>/` (code source réel, dans son langage) et
  leur sortie attendue dans `tests/Fixtures/expected/<nom>/.devtools/` — puis, pour la matrice de bout
  en bout, dans `tests/Fixtures/matrix/<nom>/{no-ai,written}/` (ADR-0015). Un test compare
  arborescence et contenu ; `DEVTOOLS_UPDATE_SNAPSHOTS=1 composer test` régénère les attendus, et le
  diff se relit avant commit.
- **Temps figé** : le cœur ne lit jamais l'horloge directement mais une `Clock` injectée ;
  les tests en fixent la valeur.
- **Git** : un test qui a besoin d'un historique crée un dépôt temporaire (`Tests\Support\GitRepository`) ; les fixtures versionnées dans ce dépôt ne sont pas des dépôts git.
- Les fixtures sont exclues de PHP-CS-Fixer, Rector et PHPStan (à ajouter avec la première).

## Budget d'exécution

Cible du § 8, tenue par l'ADR-0010 et mesurée par l'ADR-0015 : **re-scan sans changement d'un projet
de 300 routes en < 10 s sans IA**, et **0 % de réécriture inutile**.

## Hors périmètre

Tout le reste du § 4.8 et les § 5 : `workflows:impact`, `workflows:check`, serveur MCP, hooks Claude
Code, gate, scénarios, retours, règles, revue, rétro, `workflows:rename`, glossaire, export statique,
multi-projets, `devtools ask`. Adaptateurs natifs Laravel, Angular, Node. Client API Claude. Phar.
Intégration à `symfony-skeleton-generator`.

## Critères d'acceptation

- [x] Chaque ADR du MVP (0002 à 0015) existe et référence ses dépendances
- [x] Les dossiers de `src/` suivent le tableau « pipeline et découpage » ; aucun n'est créé vide
- [x] Les conventions de test (fixtures, snapshots, horloge, git temporaire) sont en place dès le
      premier lot qui en a besoin, et décrites dans `.github/CONTRIBUTING.md`

## Conséquences

- La voie native Symfony arrive avant la voie Claude : un projet non Symfony n'est pris en charge
  qu'à l'étape 4.
- Tout ce qui n'est pas dans le tableau du MVP demande une nouvelle ADR, même si les specs le
  décrivent déjà.

## Dépendances

ADR-0000.
