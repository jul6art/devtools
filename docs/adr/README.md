# DevTools — Plan d'attaque en lots (ADR)

> Chaque lot est une **Architecture Decision Record** : une décision datée, son contexte, ses
> conséquences, et des critères d'acceptation **testables**. Un lot ne démarre pas tant que son
> ADR n'est pas `Accepted` ; une décision ne se rediscute pas sans une nouvelle ADR qui la remplace
> (`Superseded by`). Le format et le processus sont fixés par [ADR-0000](0000-processus-adr.md).
>
> Source : `docs/specs.md` (brouillon 0.2). Chaque ADR cite les § qu'elle couvre, et **nomme** les
> écarts qu'elle prend avec les specs.
>
> **Objectif n°1 : le MVP, qui livre toute la problématique P1** — introspection du code et
> génération maintenue des workflows. Ses ADR (0000 à 0015) sont **acceptées**. Les suivantes sont
> écrites pour que le MVP fige des formats qui les servent, mais restent `Proposed` : elles se
> relisent, et au besoin se remplacent, à la lumière de ce que le MVP aura appris.

## Vue d'ensemble

| ADR | Lot | Phase | Statut | Livré |
|---|---|---|---|---|
| [0000](0000-processus-adr.md) | Processus ADR et définition d'un lot | méta | Accepted | 2026-09-17 |
| [0001](0001-architecture-et-perimetre-du-mvp.md) | Architecture du cœur et périmètre du MVP | méta | Accepted | 2026-09-17 |
| [0002](0002-voie-ia-claude-code.md) | Voie IA du MVP : Claude Code rédige, DevTools reste déterministe | MVP 1 — Socle | Accepted | 2026-09-17 |
| [0003](0003-modele-intermediaire-et-identifiants.md) | Modèle intermédiaire et identifiants stables | MVP 1 — Socle | Accepted | 2026-09-16 |
| [0004](0004-dossier-devtools-et-suivi-xml.md) | Dossier `.devtools/`, configuration et XML de suivi | MVP 1 — Socle | Accepted | 2026-09-16 |
| [0005](0005-detection-de-stack.md) | Détection de stack et `stack.xml` | MVP 2 — Tranche factuelle | Accepted | 2026-09-16 |
| [0006](0006-graphe-de-dependances-php.md) | Graphe de dépendances PHP et regroupement | MVP 2 — Tranche factuelle | Accepted | 2026-09-16 |
| [0007](0007-adaptateur-symfony.md) | Adaptateur Symfony : la console du projet comme source de vérité | MVP 2 — Tranche factuelle | Accepted | 2026-09-16 |
| [0008](0008-rendu-norme-des-pages-et-du-menu.md) | Rendu normé des pages, du menu et du graphe | MVP 2 — Tranche factuelle | Superseded by 0043 | 2026-09-16 |
| [0009](0009-commande-workflows-inspect.md) | Commande `workflows:inspect` et pipeline | MVP 2 — Tranche factuelle | Accepted | 2026-09-16 |
| [0010](0010-fraicheur.md) | Fraîcheur : ne réécrire que ce qui a changé | MVP 3 — Fraîcheur | Accepted | 2026-09-16 (Infection : CI) |
| [0011](0011-redaction-des-pages-par-claude-code.md) | Rédaction des pages par Claude Code | MVP 4 — Rédaction et langages | Accepted | 2026-09-17 |
| [0012](0012-connaissances-de-stack.md) | Connaissances de stack (`knowledge`) | MVP 4 — Rédaction et langages | Superseded by 0041 | 2026-09-17 |
| [0013](0013-voie-claude-pour-les-stacks-sans-adaptateur.md) | Voie Claude pour les stacks sans adaptateur natif | MVP 4 — Rédaction et langages | Accepted | 2026-09-17 |
| [0014](0014-adaptateur-php-generique.md) | Adaptateur PHP générique (sans framework) | MVP 4 — Rédaction et langages | Accepted | 2026-09-17 |
| [0015](0015-livraison-du-mvp.md) | Livraison du MVP : bout en bout, mesures, dogfooding | MVP — Livraison | Accepted | en cours (CI, MSI, commit du dogfooding) |
| [0016](0016-carte-d-impact.md) | Carte d'impact : `workflows:impact` | 1 — Impact, MCP, gate | Proposed | — |
| [0017](0017-controle-de-fraicheur-et-hooks-git.md) | Contrôle de fraîcheur en CI et hooks git | 1 — Impact, MCP, gate | Proposed | — |
| [0018](0018-serveur-mcp.md) | Serveur MCP `devtools` | 1 — Impact, MCP, gate | Proposed | — |
| [0019](0019-hooks-claude-code.md) | Hooks Claude Code : l'impact avant la modification | 1 — Impact, MCP, gate | Proposed | — |
| [0020](0020-scenarios-derives-des-workflows.md) | Scénarios reproductibles dérivés des workflows | 1 — Impact, MCP, gate | Proposed | — |
| [0021](0021-runner-de-scenarios.md) | Runner de scénarios (Panther) | 1 — Impact, MCP, gate | Proposed | — |
| [0022](0022-captures-baselines-et-diff-visuel.md) | Captures, baselines et diff visuel | 1 — Impact, MCP, gate | Proposed | — |
| [0023](0023-gate.md) | Gate : Claude ne peut pas déclarer la tâche finie tant qu'elle échoue | 1 — Impact, MCP, gate | Proposed | — |
| [0024](0024-sous-agent-verifier.md) | Sous-agent `verifier` | 1 — Impact, MCP, gate | Proposed | — |
| [0025](0025-retours-types.md) | Retours typés et détection de récurrence | 2 — Retours et règles | Proposed | — |
| [0026](0026-regles-et-injection-contextuelle.md) | Promotion en règles et injection contextuelle | 2 — Retours et règles | Proposed | — |
| [0027](0027-assertions-mecaniques.md) | Assertions mécaniques | 2 — Retours et règles | Proposed | — |
| [0028](0028-interface-de-revue-humaine.md) | Interface de revue humaine | 2 — Retours et règles | Proposed | — |
| [0029](0029-retrospective-et-metriques.md) | Rétrospective et métriques | 2 — Retours et règles | Proposed | — |
| [0030](0030-adaptateur-natif-laravel.md) | Adaptateur natif Laravel | 3 — Généralisation | Proposed | — |
| [0031](0031-pont-node-pour-javascript-et-typescript.md) | Pont Node pour l'analyse JavaScript et TypeScript | 3 — Généralisation | Proposed | — |
| [0032](0032-adaptateur-natif-angular.md) | Adaptateur natif Angular | 3 — Généralisation | Proposed | — |
| [0033](0033-adaptateur-natif-node-express.md) | Adaptateur natif Node / Express | 3 — Généralisation | Proposed | — |
| [0034](0034-publication-open-source-et-distribution.md) | Publication open source, phar, Docker, site | 3 — Généralisation | Proposed | — |
| [0035](0035-client-api-claude-et-redaction-en-ci.md) | Client API Claude et rédaction en CI | Compléments | Proposed | — |
| [0036](0036-renommage-de-workflows.md) | Renommage de workflows : `workflows:rename` | Compléments | Proposed | — |
| [0037](0037-glossaire-metier.md) | Glossaire métier | Compléments | Proposed | — |
| [0038](0038-multi-projets.md) | Multi-projets : relier un front et son API | Compléments | Proposed | — |
| [0039](0039-mode-question.md) | Mode question : `devtools ask` | Compléments | Proposed | — |
| [0040](0040-integration-symfony-skeleton-generator.md) | Intégration à `symfony-skeleton-generator` | Compléments | Proposed | — |
| [0041](0041-bibliotheque-de-connaissances.md) | Bibliothèque de connaissances partagée | MVP+ — Retours d'usage | Accepted | 2026-09-18 |
| [0042](0042-progression-et-compteurs-en-console.md) | Progression et compteurs de la sortie console | MVP+ — Retours d'usage | Accepted | 2026-09-18 |
| [0043](0043-pages-decisions-et-mecanismes.md) | Pages : faits au XML, listeners en mécanismes, décisions en graphiques | MVP+ — Retours d'usage | Accepted | 2026-09-18 |
| [0044](0044-introspection-approfondie.md) | Introspection approfondie : connaissance amendable, logique conditionnelle complète | MVP+ — Retours d'usage | Accepted | — |

## Les phases, et ce qu'on peut démontrer à la fin de chacune

Les phases 1 à 3 sont celles du § 9 des specs ; le MVP en est la phase 0.

```
MVP — P1 (0000–0015)                         Phase 1 — Impact, MCP, gate (0016–0024)
  1 Socle        0002 0003 0004                0016 impact    0017 check + hooks git
  2 Factuel      0005 0006 0007 0008 0009      0018 MCP       0019 hooks Claude Code
  3 Fraîcheur    0010                          0020 scénarios 0021 runner  0022 visuel
  4 Rédaction    0011 0012 0013 0014           0024 verifier  0023 gate
  Livraison      0015
                                               « Claude ne déclare plus “fini” sans avoir
  « P1 est livré : les workflows de tout         rejoué les scénarios de ce qu'il a touché »
    projet sont documentés et maintenus »

Phase 2 — Retours et règles (0025–0029)      Phase 3 — Généralisation (0030–0034)
  0025 retours typés                           0030 Laravel
  0026 règles injectées                        0031 pont Node ──► 0032 Angular, 0033 Express
  0027 assertions mécaniques                   0034 phar, Docker, site, open source
  0028 revue humaine
  0029 rétrospective                           « quatre stacks natives, installable sans PHP »

  « une erreur signalée deux fois ne peut      Compléments (0035–0040), chacun indépendant
    plus revenir sans casser la gate »           API Claude, rename, glossaire, multi-projets,
                                                 ask, intégration au générateur de squelettes

MVP+ — Retours d'usage (0041–0044), nés de la première inspection d'un projet réel (cereezer,
84 workflows). Indépendants les uns des autres, chacun jouable dès le MVP livré.
  0041 la connaissance d'une stack inconnue grandit une bibliothèque au lieu de rester au projet
  0042 la console dit ce qu'elle fait, où elle en est, et ce que l'exécution a coûté
  0043 la page cesse d'être un inventaire : les faits au XML, les listeners rattachés aux
       workflows qu'ils interceptent, et un graphique là où une logique décide d'une valeur
  0044 ce que 0041 et 0043 ont nommé et laissé : fiche amendable, voters, formulaires,
       contraintes de validation, un saut d'appel, et la machine à états parmi les décisions
       (acceptée le 2026-09-18, démarrage non planifié)

  « une page se lit, et son diagramme répond à “pourquoi cette valeur-là ?” »
```

## Graphe de dépendances (un lot n'attend que ses flèches entrantes)

```
MVP
0000                      ──► 0001
0001                      ──► 0002, 0003
0003                      ──► 0004
0004                      ──► 0005
0003 + 0004               ──► 0008
0003 + 0005               ──► 0006
0003 + 0005 + 0006        ──► 0007
0005 + 0006 + 0007 + 0008 ──► 0009
0009                      ──► 0010
0002 + 0008 + 0010        ──► 0011
0005 + 0011               ──► 0012
0010 + 0011 + 0012        ──► 0013
0006 + 0009               ──► 0014
0011 + 0012 + 0013 + 0014 ──► 0015

Phase 1
0015                      ──► 0016, 0017, 0020
0016                      ──► 0018, 0019
0020                      ──► 0021 ──► 0022
0019 + 0022               ──► 0024
0017 + 0019 + 0022 + 0024 ──► 0023

Phase 2
0023                      ──► 0025
0019 + 0025               ──► 0026
0022 + 0025               ──► 0028
0023 + 0026               ──► 0027
0027 + 0028               ──► 0029

Phase 3
0015                      ──► 0030, 0031
0031                      ──► 0032, 0033
0015 (+ 0031)             ──► 0034

Compléments
0015                      ──► 0035, 0036, 0037
0016 + 0032               ──► 0038
0018 (+ 0035, 0037)       ──► 0039
0034 + 0018 + 0019 + 0024 ──► 0040

MVP+
0012 + 0013               ──► 0041
0009 + 0010 + 0011        ──► 0042
0006 + 0008 + 0011        ──► 0043
0041 + 0043               ──► 0044
```

- **Chemin critique du MVP** : 0003 → 0004 → 0005 → 0006 → 0007 → 0009 → 0010 → 0011.
- **Parallélisables dans le MVP** : 0006 et 0008 ; 0014 dès 0009 livré.
- **0014 a la priorité la plus basse du MVP** : il peut glisser après la livraison sur décision
  explicite.
- **La phase 3 ne dépend pas des phases 1 et 2** : Laravel, Angular, Express peuvent avancer en
  parallèle dès le MVP livré.

## Décisions déjà tranchées qui contraignent tous les lots

1. **DevTools ne parle jamais à Claude** (ADR-0002) : il écrit des briefs et valide des brouillons.
   L'API (ADR-0035) n'est qu'un autre producteur de brouillons, soumis aux mêmes validations.
2. **Un fait ne vient jamais de Claude** (ADR-0003, 0008, 0011, 0013) : fichiers, routes, méthodes
   viennent du modèle ou sont vérifiés sur disque ; un brouillon qui cite un chemin absent est refusé.
3. **La console du projet est la source de vérité d'un framework** (ADR-0007, 0030), dans les deux
   modes ; le bridge Symfony ne fait qu'enregistrer les commandes.
4. **Le hash tranche, git accélère** (ADR-0010) : jamais de décision « inchangé » sur la foi de git
   seul.
5. **Tout format lu ou écrit est du XML validé par XSD** (ADR-0003, 0004, 0020, 0025…), lu en mode
   durci (pas de DTD, pas d'entité externe).
6. **Une dépendance lourde est optionnelle** (`suggest` + enregistrement conditionnel) : MCP SDK,
   Panther, client HTTP. Le `require` reste celui du § 6.
7. **Un hook DevTools n'empêche jamais de travailler par erreur technique** (ADR-0017, 0019, 0023) :
   seule la gate bloque, et seulement sur un vrai échec, avec une garde anti-boucle.
8. **Chaque lot livre ses tests et ses fixtures** (ADR-0000) ; l'ADR-0015 consolide, elle ne rattrape
   pas.

## Écarts assumés avec `docs/specs.md`

| Specs | Décision | ADR |
|---|---|---|
| § 4.3 : modèle intermédiaire en « JSON normé » | XML + XSD, une seule pile de validation | 0003 |
| § 3.2 : le bundle fournit l'adaptateur Symfony via le kernel | la console du projet, dans les deux modes | 0007 |
| § 4.2 : les formulaires sont des workflows `ui` | un formulaire est un composant de route | 0007 |
| § 4.4 : arborescence de `.devtools/` | + `pending/`, `runs/`, `gate/` (ignorés) ; `discovery/`, `scenarios/`, `baselines/`, `feedback/`, `rules/`, `assertions/`, `journal/` (versionnés) | 0004, 0013, 0020–0029 |
| § 5.2 : scénarios en YAML | XML + XSD | 0020 |
| § 5.4 : extension du MCP Chrome pour la revue | page locale uniquement | 0028 |
| § 4.8 : export via Docsify/MkDocs | générateur intégré, sans outil externe | 0034 |
| § 9 phase 0 : intégration à `symfony-skeleton-generator` | après la première version publiée | 0001, 0040 |
| § 6, § 4.6.1 : `provencale/devtools`, `devtools.provencale.lu` | `jul6art/devtools`, namespaces XML sous `github.com/jul6art/devtools` | 0003 |
| § 5 : schémas `feedback`, `rule`, `scenario` de la v0.1 | réécrits contre les formats du MVP ; à relire après la phase 1 | 0020, 0025, 0026 |
| § 12 question 3 : remontée d'une connaissance par pull request manuelle | dépôt automatique dans une bibliothèque partagée ; `knowledge:promote` reste manuel | 0041 |
| § 4.2 : sept types de déclencheur, dont `events` | six : un listener est un mécanisme rattaché aux workflows qu'il intercepte, pas un workflow | 0043 |
| § 4.5 : gabarit de page à onze sections | dix : « Composants impliqués » et « Tests existants » ne vivent que dans le XML de suivi, et une section « Décisions » apparaît | 0043 |
| § 4.3 étape 7 : « Parcours » a un repli factuel | la section vaut `—` sans Claude : le diagramme des fichiers atteints redisait la table supprimée | 0043 |
