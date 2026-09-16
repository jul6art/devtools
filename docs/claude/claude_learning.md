## III. LEARNING LOG (APPEND-ONLY — NEVER MODIFY EXISTING ENTRIES)

> Mémoire chronologique des corrections, découvertes et retours humains sur **ce dépôt**.
> Les entrées existantes ne se modifient **jamais** : on ajoute en bas, daté (`YYYY-MM-DD`).
>
> **Chaque entrée est une règle à faire respecter.** C'est le seul document qui liste des erreurs
> déjà commises ici — donc les plus probables. REVIEWER le relit à chaque passe et vérifie
> qu'aucune correction passée n'a été réintroduite.
>
> Format d'une entrée : ce qui s'est passé, la **règle** qui en découle (prescriptive,
> actionnable), et si possible le test ou le garde-fou qui l'empêche de revenir.

---

### 2026-09-16 — Naissance du dépôt

- **Le squelette vient de `symfony-bundle-generator`, puis s'en écarte volontairement.** Layout
  `src/` + `tests/` + `bin/`, bundle dans `src/Bridge/Symfony/`, pas de Flex, alias `devtools`.
  Les cinq écarts sont listés dans `claude_project.md` : **ne jamais « réaligner » un fichier sur le
  gabarit** du générateur sans relire cette liste.

- **Le nom du paquet est celui du dépôt, pas celui des specs.** `docs/specs.md` (brouillon 0.2) dit
  `provencale/devtools` ; le remote git est `jul6art/devtools`, comme tout l'écosystème. **Règle :**
  quand les specs et le dépôt divergent sur un nom, le dépôt fait foi et l'écart se signale au
  décideur — il ne se résout pas en silence dans le code.

- **Symfony déduit l'alias `dev_tools` de `DevToolsBundle`, et refuse `devtools` au boot.**
  `LogicException: Users will expect the alias of the default extension of a bundle to be the
  underscored version of the bundle name ("dev_tools")`. **Règle :** l'alias `devtools` exige la
  surcharge de `DevToolsBundle::getContainerExtension()` **et** de `DevToolsExtension::getAlias()`.
  Garde-fou : `ContainerTest` — vérifié par mutation (surcharge retirée → 2 erreurs).

- **Le jeu `lowest` a trouvé un écart dès le premier commit.** `ApplicationTest::testItListsItsCommands`
  était vert sur symfony/console 8.1 et lisait un affichage **vide** sur 7.4.0 : `phpunit.xml.dist`
  pose `SHELL_VERBOSITY=-1`, que l'`ApplicationTester` de 8.x neutralise et celui de 7.4.0 non.
  **Règle :** un test qui lit la sortie d'un `ApplicationTester` force la verbosité
  (`'--verbose' => true`) ; et tout changement touchant une API Symfony s'exerce sur
  `composer update --prefer-lowest --prefer-stable` avant de se déclarer prêt.

- **`bin/devtools` n'est analysé par PHPStan que parce qu'il est listé explicitement.**
  `$GLOBALS['_composer_autoload_path']` est `mixed` : il a fallu le restreindre par `is_string()`
  plutôt que par `??` seul. **Règle :** un fichier PHP sans extension `.php` (binaire) est ajouté
  **nommément** dans `phpstan.dist.neon`, `rector.php` et `.php-cs-fixer.dist.php`.

- **Le générateur produit encore `dirname(__DIR__)` dans `Tests/bootstrap.php`**, que Rector 2.6.7
  réécrit en `__DIR__.'/../'` (corrigé à la main dans tous les bundles le 2026-09-14). Ici, la forme
  corrigée a été posée d'emblée. **Règle :** ce défaut se corrige dans le générateur
  (`common/overlay/Tests/bootstrap.php`), pas bundle par bundle.
