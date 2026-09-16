# ADR-0015 — Livraison du MVP : bout en bout, mesures et dogfooding

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 7 (en entier), § 8, § 9 (phase 0), § 12 question 5

## Contexte

Chaque lot a livré ses tests (ADR-0000 règle 4). Il reste ce qu'aucun lot ne peut prouver seul : que
les pièces tiennent **ensemble**, sur toutes les fixtures, dans les deux modes, sur les deux jeux de
dépendances ; que les métriques du § 8 sont tenues ; et que l'outil documente son propre dépôt
(§ 7.2, dernière phrase). C'est la définition de « P1 livré ».

## Décision

### Matrice de bout en bout (`tests/EndToEnd/`)

Pour chaque projet-fixture (`symfony-minimal`, `symfony-legacy-yaml`, `plain-php`, `node-express`,
`angular-minimal`, `monorepo`), en processus séparé, dans une copie temporaire devenue dépôt git :

1. `init` → `inspect --no-ai` → snapshot ;
2. `inspect --no-ai` à nouveau → **zéro fichier modifié** ;
3. `inspect` → briefs → brouillons enregistrés copiés → `apply` → snapshot rédigé ;
4. modification scriptée (un fichier modifié, un supprimé, un ajouté) + commit → `inspect` →
   décisions attendues exactement.

Symfony : les étapes 1 et 2 aussi via `bin/console devtools:workflows:inspect`, résultat identique.

### Mesures du § 8

| Métrique | Test |
|---|---|
| Workflows détectés / points d'entrée réels = 100 % | liste attendue par fixture (étape 1) |
| Re-scan 300 routes < 10 s sans IA | projet Symfony **généré** par le test (300 routes, services partagés), chronométré ; groupe PHPUnit `performance`, lancé en CI |
| 0 % de réécriture inutile | étape 2 de la matrice |
| Pages conformes au gabarit = 100 % | validateur de l'ADR-0008 sur toutes les pages de toutes les fixtures |

### Qualité

- Couverture : > 90 % sur `src/` hors `Bridge/`, 100 % sur `Inspection/Freshness/` et `Tracking/` ;
  seuils imposés en CI (`composer coverage`).
- Infection sur `Freshness/` et `Tracking/` en CI, MSI minimal fixé à la valeur atteinte.
- PHPStan niveau max, sans baseline (déjà en place).
- CI réactivée sur `jul6art/devtools` (Actions désactivées au 2026-09-16) **avant** tout tag.

### Dogfooding

`.devtools/` de ce dépôt généré par DevTools lui-même (adaptateur générique PHP, qui voit ses
commandes `#[AsCommand]` — ADR-0014 ; rédaction Claude Code), **committé**. Les workflows de
DevTools (`command.workflows.inspect`, `command.workflows.apply`, …) deviennent sa documentation
d'architecture.

### Distribution (§ 12 question 5)

Composer : `composer global require jul6art/devtools` et `composer require --dev`. Le README décrit
les deux, testés depuis un chemin de dépôt local (`repositories: path`) dans la matrice.

**Tag `v0.1.0`** : décision humaine, après CI verte sur les deux jeux de dépendances. Un tag
Packagist ne se retire pas.

## Budget d'exécution

CI complète (matrice highest/lowest + performance + Infection) : cible < 15 min ; au-delà, la
performance et Infection passent sur `push` de `master` seulement.

## Hors périmètre

Phar, image Docker ; intégration à `symfony-skeleton-generator` (après `v0.1.0`, lot suivant) ;
publication d'un site de documentation ; tout module postérieur à P1.

## Critères d'acceptation

- [ ] Matrice de bout en bout verte sur les six fixtures, étapes 1 à 4
- [ ] Les deux modes produisent un `.devtools/` identique sur `symfony-minimal`
- [ ] Les quatre métriques du § 8 mesurées et tenues, chiffres consignés dans le README
- [ ] Seuils de couverture et MSI appliqués en CI
- [ ] `.devtools/` de ce dépôt committé, et `inspect --no-ai` sur ce dépôt ne modifie rien
- [ ] Installation `global` et `require-dev` testées
- [ ] README complet : les deux modes, `init`, `inspect`, `apply`, `claude:install`, options, ce qu'on
      committe, pièges (Docker, console qui ne boote pas, `--prune`)
- [ ] CI réactivée et verte ; `docs/adr/README.md` : 0000 à 0015 marqués livrés

## Conséquences

- La phase 1 des specs (impact, MCP, gate, ADR-0016 à 0024) démarre sur un socle mesuré ; chacune de ses ADR s'écrira contre
  les formats figés ici.

## Dépendances

ADR-0011, ADR-0012, ADR-0013, ADR-0014.
