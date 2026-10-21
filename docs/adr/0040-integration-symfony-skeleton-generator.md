# ADR-0040 — Intégration à `symfony-skeleton-generator`

- **Statut** : Accepted — 2026-10-21 (proposée le 2026-09-16)
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

- [x] Squelette généré dans chaque mode : `composer qa` vert, `workflows:check` à 0 *(le 2026-10-21,
      depuis Packagist — DevTools 3.4.0 — après correction des trois défauts du générateur relevés plus
      bas : `make qa` vert en `web` (16 tests), `api --docker` (8), `admin` (17), `api-platform` (9) et
      `backoffice` (96) ; `workflows:check` à 0 dans les cinq)*
- [x] Mode `--docker` : `config.xml` avec la console du mode (`symfony console`, voir la mise en œuvre) ;
      inspection réussie dans la stack
- [x] `.claude/settings.json` et `.mcp.json` générés et valides
- [x] `bundles.php` : `dev` et `test` uniquement
- [x] README du générateur : section DevTools

## Mise en œuvre (2026-10-21)

Dans `/private/var/www/PRIVATE/symfony-skeleton-generator` (dépôt distinct, non commité à ce jour) :

- `common/packages-dev.txt` : `jul6art/devtools:^3.4` et `mcp/sdk:^0.9` (le serveur MCP n'existe qu'avec le
  SDK, que DevTools ne fait que suggérer).
- `common/devtools.sh`, lancé par `bin/new-project` **après les hooks des modes** — ils ajoutent du code,
  donc des workflows — et **avant le premier commit** : le pont en `dev` + `test` (garanti, même si Flex le
  fait déjà), `devtools init`, puis `config.xml` complété (console, exclusions `assets/vendor`,
  `public/bundles`, `config/jwt`, `<language pages="fr"/>`), `claude:install --skill --hooks --mcp --agents`,
  et un premier `workflows:inspect --no-ai` (code 1 toléré : des avertissements). Rien de tout cela avec
  `--no-extras`.
- `Makefile` des projets : `make docs` et `make docs-check` ; `CLAUDE.md` des projets : une section « Carte
  des workflows » qui envoie à `docs/workflows/workflows.md` avant de modifier un workflow (le générateur n'a
  pas de `docs/claude/claude_project.md` : son `CLAUDE.md` en tient lieu) ; README : section « DevTools ».
- ⚠️ **La console est `symfony console` dans tous les modes, `--docker` compris.** L'ADR supposait un
  conteneur `php` (`docker compose exec -T php bin/console`) ; or `--docker` est `symfony new --docker`, qui
  met en conteneurs les services (base, mailer), pas PHP — c'est la CLI Symfony qui passe leurs variables aux
  commandes. `symfony console` prend aussi le PHP de `.php-version`, là où `bin/console` prendrait celui du
  PATH.
- ⚠️ **`^3.4`, pas `^3.3`** : la v3.3.0 publiée précède les hooks, le serveur MCP et le vérificateur. La
  vérification s'est faite contre la copie locale de DevTools, par un dépôt `path` déclaré dans un
  `COMPOSER_HOME` temporaire. **Publier DevTools 3.4.0 avant de pousser le générateur**, sans quoi plus aucun
  projet ne se génère.
- Les zones de travail ignorées sont celles qu'écrit `devtools init` (pending, reports, runs, gate, stats,
  search.idx), plus que les quatre de la décision.

Vérifié le 2026-10-21, un projet par mode (`web`, `api --docker`, `admin`, `api-platform`, `backoffice`) :
pont en `dev` + `test`, `.claude/settings.json` et `.mcp.json` valides, deux skills, l'agent, `config.xml`
complété, 16, 5, 20, 4 et 32 workflows lus par la console native, tout committé, `workflows:check` à 0. En
`--docker`, base démarrée : l'inspection passe par `symfony console` et ne change rien.

### Ce qui rendait `make qa` rouge hors `backoffice` (relevé et corrigé le 2026-10-21)

- `common/overlay/tests/Translation/FlashKeyTest.php` lit `App\Security\BackofficeLocales`, qui n'existe que
  dans le mode `backoffice` : PHPStan (`class.notFound`) et PHPUnit échouent dans `web`, `api`, `admin`,
  `api-platform` — depuis le 2026-08-24.
- `UserVoter` (et `AdminVoter` en `admin`) étendent `AbstractVoter` de `core-bundle`, devenu générique, sans
  `@extends` : PHPStan (`missingType.generics`).
- PHP-CS-Fixer 3.95 importe désormais `Override` et réordonne `use LogicException` dans trois fichiers de
  l'overlay (`web`, `admin`).

Corrigés dans le générateur : le test lit `kernel.enabled_locales`, les voters déclarent
`@extends AbstractVoter<string, User|null>` (et `<string, null>`), les imports suivent PHP-CS-Fixer — dans
les deux copies, `web` et `admin`, que portent ces modes.

## Conséquences

- Tout nouveau projet de l'écosystème a sa carte des workflows dès le premier commit.

## Dépendances

ADR-0034 (version publiée) ; ADR-0018, ADR-0019, ADR-0024 pour l'installation complète.
