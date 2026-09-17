# ADR-0009 — Commande `workflows:inspect` et pipeline (tranche verticale)

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 3.2, § 4.3 (étapes 1, 3, 4, 5, 7 `--no-ai`, 8, 9, 10), § 4.6.2 (options), § 7.2 (famille bout en bout)

## Contexte

C'est le lot qui rend l'étape 2 du MVP démontrable : une commande, lancée sur un vrai projet Symfony,
qui écrit un `.devtools/` complet et exact. Il assemble 0005 à 0008 sans rien leur ajouter de
métier.

La fraîcheur (ADR-0010) n'existe pas encore : **ce lot réécrit tout à chaque exécution**. C'est
volontaire — il faut une tranche qui marche avant d'optimiser ce qu'elle réécrit.

## Décision

### Commande

```
devtools workflows:inspect [path] [--only=<type>] [--dry-run]
bin/console devtools:workflows:inspect [path] …          (bridge, path par défaut : project_dir)
```

- `path` absent → répertoire courant. Un `path` qui n'est pas un dossier → code 2.
- `--only=routes` limite **l'écriture des pages** à un type ; l'index, le graphe et le menu restent
  complets (ils se calculent sur tout le modèle).
- `--dry-run` : tout est calculé, rien n'est écrit, le rapport s'affiche.
- Codes de sortie : `0` succès, `1` succès avec avertissements (repli statique, fichiers illisibles),
  `2` erreur (configuration invalide, collision d'identifiants, schéma).

### Pipeline (`Inspection\InspectionPipeline`)

```
StackDetector → AdapterResolver → Adapter::extract() → DependencyResolver + Grouping
  → CoverageCalculator → PageRenderer / TrackingWriter (par workflow)
  → IndexWriter, FilesToWorkflowsWriter, GraphRenderer, MenuRenderer → Report
```

- `AdapterResolver` choisit l'adaptateur d'après `stack.xml` ; un adaptateur absent → erreur
  explicite jusqu'à l'ADR-0013 (qui ajoutera la voie Claude).
- Le XML de suivi reçoit, par fichier, son `sha256` et le commit courant (`git rev-parse HEAD`,
  `vcs="none"` hors git) — les données dont la fraîcheur aura besoin sont écrites **dès ce lot**.
- Un workflow disparu (XML présent, identifiant absent du modèle) est marqué `orphaned` et listé ;
  sa page n'est pas supprimée.
- **Verrou** : `.devtools/reports/.inspect.lock` (`flock`, sans dépendance ; dans `reports/`, qui est
  déjà ignoré par git — un verrou n'est pas de la documentation) ; une seconde exécution concurrente sur
  le même projet échoue immédiatement avec un message clair.

### Rapport (§ 4.3 étape 10)

`reports/inspect-<AAAA-MM-JJ-HHMMSS>.md` et sortie console : stack et adaptateur, mode et confiance,
compteurs **créés / mis à jour / inchangés / orphelins** par type, avertissements, durée.

### Le README

La section « Usage » du README décrit la commande sur un projet réel : ce qu'elle écrit, où, ce
qu'on committe, et le piège (projet Docker-first → `config.xml` `<symfony console>`).

## Budget d'exécution

- `symfony-minimal` : < 3 s hors lancement console.
- Processus lancés : ceux de l'ADR-0007 (≤ 6) + 1 `git rev-parse` ; **aucun processus par
  workflow**.

## Hors périmètre

Sélection de ce qui est réécrit, `--force`, `--since`, `--prune`, idempotence (ADR-0010) ; briefs IA
et `--no-ai` comme option (ADR-0011 ; ici tout est factuel) ; stacks sans adaptateur natif
(ADR-0013).

## Critères d'acceptation

- [x] `bin/devtools workflows:inspect tests/Fixtures/projects/symfony-minimal` en processus séparé :
      `.devtools/` égal au snapshot attendu (pages, XML, index, graphe, menu, stack)
- [x] Même résultat via `bin/console devtools:workflows:inspect` du projet-fixture (bridge installé)
- [x] Chaque XML produit est valide ; chaque page est conforme au gabarit (tests de l'ADR-0008
      rejoués sur la sortie réelle)
- [x] `--dry-run` n'écrit aucun fichier (arborescence comparée avant/après)
- [x] `--only=commands` n'écrit que les pages de commandes ; le menu reste complet
- [x] Une route supprimée du fixture entre deux exécutions → workflow `orphaned`, page conservée
- [x] Deux exécutions concurrentes : la seconde échoue avec le message de verrou
- [x] Codes de sortie 0, 1, 2 couverts chacun par un test
- [x] README : section Usage de `workflows:inspect` écrite

## Conséquences

- À la fin de ce lot, l'outil est **utilisable** sur tout projet Symfony, sans IA, mais réécrit tout à
  chaque passage : l'ADR-0010 suit immédiatement.

## Dépendances

ADR-0005, ADR-0006, ADR-0007, ADR-0008.
