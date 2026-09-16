# ADR-0040 — Intégration à `symfony-skeleton-generator`

- **Statut** : Proposed — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 9 (phase 0 : intégration au générateur de squelettes)

## Contexte

Les applications de l'écosystème naissent de `symfony-skeleton-generator` (cegeta, cereezer : mode
`backoffice`). Si DevTools n'y est pas, chaque projet doit l'installer et le configurer à la main, et
les premiers workflows ne sont documentés que le jour où quelqu'un y pense. L'ADR-0001 a reporté cette
intégration après la première version publiée : un générateur ne peut pas requérir une version qui
n'existe pas sur Packagist.

## Décision

Dans `symfony-skeleton-generator` (dépôt distinct, commit distinct) :

- `jul6art/devtools` ajouté en `require-dev` de **tous** les modes, contrainte `^<version publiée>`.
- Bundle enregistré pour `dev` et `test` uniquement, jamais `all` (README de DevTools).
- `.devtools/config.xml` généré avec la console du mode (`docker compose exec -T php bin/console` en
  mode `--docker`), les exclusions du squelette et la langue des pages.
- `devtools claude:install --hooks --mcp --agents` exécuté en fin de génération ; les fichiers
  `.claude/` produits sont ceux qui seront committés.
- Premier `workflows:inspect --no-ai` exécuté et committé avec le squelette : le projet naît documenté.
- `.gitignore` du squelette : `.devtools/pending/`, `.devtools/reports/`, `.devtools/runs/`,
  `.devtools/gate/`.
- Le `docs/claude/claude_project.md` généré mentionne `.devtools/workflows.md` comme document à consulter
  avant de modifier un workflow.

## Budget d'exécution

Génération allongée d'une inspection sans IA (< 5 s sur un squelette vide).

## Hors périmètre

Rétro-installation dans les projets existants (une commande `devtools init` suffit, documentée) ;
intégration à `symfony-bundle-generator` (un bundle n'a pas de workflows applicatifs).

## Critères d'acceptation

- [ ] Squelette généré dans chaque mode : `composer qa` vert, `workflows:check` à 0
- [ ] Mode `--docker` : `config.xml` avec la console Docker ; inspection réussie dans la stack
- [ ] `.claude/settings.json` et `.mcp.json` générés et valides
- [ ] `bundles.php` : `dev` et `test` uniquement
- [ ] README du générateur : section DevTools

## Conséquences

- Tout nouveau projet de l'écosystème a sa carte des workflows dès le premier commit.

## Dépendances

ADR-0034 (version publiée) ; ADR-0018, ADR-0019, ADR-0024 pour l'installation complète.
