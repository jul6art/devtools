## II. PROJECT-SPECIFIC CONTEXT (DO NOT GENERALIZE)

> Ce fichier contient les règles et contraintes qui s'appliquent **EXCLUSIVEMENT** à **ce dépôt**.
> Elles ne doivent **JAMAIS** être généralisées à d'autres projets.

---

## Identité du projet

### Nom
**DevTools** — sans suffixe « bundle », même si la distribution Symfony en est techniquement un.
Paquet `jul6art/devtools`, namespace `Jul6Art\DevTools`, dépôt <https://github.com/jul6art/devtools>.

### Ce que c'est
Un outil qui donne à un projet un dossier **`.devtools/`** versionné, analogue à `.git`, dans lequel
Claude Code maintient une **cartographie exacte des workflows** du projet — puis, dans les phases
suivantes, la mémoire structurée des retours humains, promus en règles et en assertions mécaniques
qu'une gate fait respecter.

### Ce que porte CE dépôt
- un **cœur PHP autonome** (`bin/devtools`, Symfony Console), qui analyse un projet **dans n'importe
  quel langage** ;
- un **bundle wrapper** (`src/Bridge/Symfony/DevToolsBundle`), installé en `require-dev` dans un
  projet Symfony, qui enregistre les mêmes commandes dans `bin/console` sous le préfixe `devtools:`.

### Type technique
PHP 8.5, Symfony Console `^7.4 || ^8.0`, PHPUnit 13, PHPStan niveau max sans baseline, Rector,
PHP-CS-Fixer. Licence **MIT**. Aucun Docker, aucune base de données, aucun front.

### D'où vient le squelette
⚠️ **`symfony-bundle-generator`** (`../../symfony-bundle-generator`), brique `console` :

```shell
./bin/new-bundle devtools --with=console --namespace='Jul6Art\DevTools' --dir=…
```

⚠️ **Cinq écarts au gabarit, voulus, à ne jamais « corriger » vers le gabarit** :
**(1)** layout **`src/` + `tests/` + `bin/`** et non plat à la racine — imposé par
`docs/specs.md` §6 : le cœur est un outil autonome, le bundle n'en est qu'un sous-dossier ;
**(2)** le bundle vit dans **`src/Bridge/Symfony/`** et `symfony/http-kernel`, `config`,
`dependency-injection`, `yaml` sont en **`require-dev` + `suggest`**, jamais en `require` — un
projet Node qui installe DevTools ne doit pas tirer le kernel Symfony ;
**(3)** **pas de `symfony/flex`**, ni dans `require-dev` ni dans la CI (donc pas de
`SYMFONY_REQUIRE`) : ses recipes écrivent `src/Kernel.php` dans le namespace du cœur ;
**(4)** l'alias de configuration est **`devtools`**, pas `dev_tools` que Symfony déduirait de
`DevToolsBundle` : `getContainerExtension()` et `getAlias()` sont surchargés, et `ContainerTest` est
le test qui passe au rouge si on les retire (vérifié par mutation le 2026-09-16) ;
**(5)** pas de brique `symfony/lock` que la brique `console` ajoute : aucune commande planifiée.

⚠️ **Un défaut du générateur découvert ici se reporte dans le générateur**, pas seulement ici.

### Documents de référence — à relire à CHAQUE prompt
- `docs/specs.md` — ce que l'outil est, module par module, et la feuille de route (**français**,
  version brouillon 0.2 « à valider »). ⚠️ Il nomme le paquet `provencale/devtools` et le
  générateur `provencale/symfony-skeleton-generator` : le dépôt réel est **`jul6art/devtools`**,
  c'est lui qui fait foi. Ne pas renommer quoi que ce soit d'après les specs sans décision explicite.
- `docs/adr/` — les décisions et les lots du MVP ; **un lot ne démarre pas sans son ADR `Accepted`**
  (ADR-0000). `docs/adr/README.md` donne l'ordre, le graphe de dépendances et les écarts assumés
  avec les specs. ⚠️ Quand une ADR acceptée contredit `docs/specs.md`, **l'ADR fait foi**.
- `README.md` — ce qu'un utilisateur fait de l'outil (anglais)
- `.github/CONTRIBUTING.md` — les règles maison qu'une pull request se voit refuser (anglais)

### Langues du code et de la documentation — règle absolue
- **Code, identifiants, commentaires, docblocs : ANGLAIS**, de bout en bout. DevTools est un paquet
  **publié**, comme les bundles `jul6art/*` : ce que d'autres dépôts installent ne se commente pas
  dans la langue de celui qui l'a écrit.
- **README, fichiers `.github/`, messages de commit : ANGLAIS.**
- **`docs/specs.md`, ce fichier, `claude_learning.md`, les futurs ADR : FRANÇAIS.**
- ⚠️ **Exception héritée** : quelques commentaires de configuration repris tels quels du générateur
  sont en français (`phpstan.dist.neon`). Ils restent ; ce qu'on écrit **maintenant** est en anglais.
- ⚠️ Les **pages générées dans `.devtools/`** d'un projet analysé suivent la langue configurée pour ce
  projet — c'est une donnée produite, pas du code de ce dépôt.

---

## Ce qui s'applique de `claude_core.md`, et ce qui ne s'applique pas

`claude_core.md` décrit des **applications** Symfony. DevTools n'en est pas une.

| § core | Ici |
|---|---|
| 1 — alignement sur l'existant | **s'applique**, et l'existant inclut les bundles `jul6art/*` et le générateur |
| 2 — code en anglais | **s'applique** (voir « Langues ») |
| 3, 4 — traductions | **ne s'applique pas** : sortie console et fichiers générés, aucune chaîne traduite |
| 5 — contrôleurs fins | **transposé** : les **commandes** sont fines, la logique vit dans `Stack/`, `Inspection/`, `Rendering/`, `Tracking/` |
| 6 — règles PHPStan | **niveau max sans baseline** ; les règles Doctrine/contrôleurs n'ont pas d'objet |
| 7 — PHP idiomatique | **s'applique** |
| 8 — entités | **ne s'applique pas** : aucune base de données |
| 9 — wrappers internes | **s'applique** |
| 10 — paramètres | **transposé** : la configuration de l'outil vit dans `.devtools/config.xml`, lue par les deux modes |
| 11 — Docker | **ne s'applique pas** : tout tourne sur l'hôte (`composer`, `bin/devtools`) |
| 12, 13 — sécurité HTTP, surfaces publiques | **ne s'applique pas** tel quel ; la surface réelle est dans `.github/SECURITY.md` |
| 14 — performance N+1 | **transposé** : une commande `git` pour tous les workflows, jamais une par workflow (specs §4.6.2) |
| 15, 16, 17 | **s'appliquent** |

---

## ⚠️ TOP LINE N°1 — LE CŒUR NE DÉPEND PAS DE SYMFONY HTTP-KERNEL

Toute la logique vit dans le cœur, testable sans Symfony. **Rien hors de `src/Bridge/Symfony/` ne
référence `Symfony\Component\HttpKernel`, `DependencyInjection` ou `Config`.** Le bridge enregistre
les commandes du cœur — rien d'autre. L'adaptateur Symfony interroge la **console du projet analysé**
dans les deux modes (ADR-0007, écart avec le § 3.2 des specs).

⚠️ **Le piège** : une commande écrite d'abord comme service du bundle marche dans `bin/console` et
**n'existe pas** dans `bin/devtools`, sans erreur. Toute commande s'enregistre d'abord dans
`src/Console/Application.php`.

⚠️ Dépendances du `require` limitées à ce que liste `docs/specs.md` §6 : `symfony/console`,
`symfony/filesystem`, `symfony/process`, `nikic/php-parser`, `ext-dom`/`ext-libxml`. **Chacune
n'entre qu'avec le module qui s'en sert**, pas par anticipation.

---

## ⚠️ TOP LINE N°2 — INDÉPENDANCE DU LANGAGE ET FAITS NON INVENTÉS

- Tout ce qui suppose que le projet analysé est en PHP vit dans un **adaptateur de stack**, jamais
  dans le pipeline.
- Adaptateur natif et voie pilotée par Claude produisent **le même modèle intermédiaire**, validé par
  schéma.
- **Aucun chemin de fichier, route ou méthode inventé** : toute donnée factuelle d'une page ou d'un
  XML vient du modèle. Claude apporte descriptions, schémas Mermaid et points d'attention.
- **Tout XML écrit est validé par son XSD**, à l'écriture et à la lecture.
- **Identifiants de workflow stables et lisibles** (`route.order.create`), jamais séquentiels. Changer
  leur dérivation est un **changement cassant** même avec une suite verte.

---

## ⚠️ TOP LINE N°3 — IDEMPOTENCE ET FRAÎCHEUR

Deux exécutions consécutives sans changement ⇒ **zéro fichier modifié** hors `.devtools/reports/`.
**Le hash tranche, git accélère** (ADR-0010) : git désigne les candidats, le `sha256` décide, et
aucune décision « inchangé » ne se prend sur la foi de git seul. Un faux « inchangé » est plus grave
qu'une réécriture inutile : la gate de la phase 1 se construit dessus.

Cible de couverture : > 90 % sur le cœur, **100 % sur `Freshness/` et `Tracking/`**, mutation
(Infection) sur ces deux dossiers.

---

## ⚠️ TOP LINE N°4 — LES DEUX JEUX DE DÉPENDANCES

La CI teste `highest` (Symfony 8.x) **et** `lowest` (Symfony 7.4.0). Un test vert localement ne dit
rien du jeu `lowest`. Avant de se déclarer prêt sur un changement qui touche une API Symfony :

```shell
# dans une copie hors du dépôt
composer update --prefer-lowest --prefer-stable && vendor/bin/phpunit
```

Cela a payé dès le premier commit (voir `claude_learning.md`, 2026-09-16).

---

## ⚠️ TOP LINE N°5 — TOUTE PASSE FINIT PAR LA SITUATION EXACTE

Le dernier paragraphe de toute réponse qui a produit du travail répond, séparément :

1. **Dans quelle ADR sommes-nous ?** Son numéro et son titre.
2. **Est-elle terminée ?** Oui ou non — jamais « presque » ; ses critères d'acceptation cochés un à un.
3. **Sinon, que reste-t-il ?** La liste des critères non cochés, pas un résumé.
4. **`composer qa` est-il vert, et le jeu `lowest` a-t-il été exercé ?**

---

## Structure

```
bin/devtools                    entrée standalone ; autoload du dépôt ou du projet hôte
src/
├── Console/Application.php     toutes les commandes s'y enregistrent
└── Bridge/Symfony/             DevToolsBundle, extension, configuration, services.yaml
tests/
├── Console/                    application + binaire en processus séparé
├── Bridge/Symfony/             arbre de configuration, boot du conteneur
└── Fixtures/TestKernel.php     kernel réel pour le bridge
docs/specs.md · docs/claude/
```

Les dossiers `Stack/`, `Inspection/{Model,Adapter,Graph,Freshness}/`, `Rendering/`, `Tracking/`,
`Ai/`, `Mcp/` et `resources/{knowledge,prompts,schemas,templates}/` (specs §6) **se créent avec le
module qui les remplit** — pas de dossier vide ni de `.gitkeep`.

⚠️ Les futurs **projets-fixtures** (`tests/Fixtures/projects/`) sont de vrais mini-projets dans leur
propre langage : exclus de PHP-CS-Fixer (déjà fait), à exclure de PHPStan et Rector dès le premier.

⚠️ Un répertoire ajouté à la racine s'ajoute dans **`.php-cs-fixer.dist.php`, `phpstan.dist.neon`
et `rector.php`**, qui listent leurs chemins explicitement.

---

## Commandes

```shell
composer install
composer qa                 # cs-check + rector-check + phpstan + phpunit — la gate
composer cs && composer rector
bin/devtools list
```

Tout tourne sur l'hôte. `composer.lock` n'est pas commité : la CI résout toujours la dernière version
des outils de qualité, et une dérive Rector/PHP-CS-Fixer peut casser la CI sans commit (c'est arrivé
sur `admin-bundle` et `core-bundle` le 2026-09-14).

---

## Conventions

- `declare(strict_types=1);` partout ; classes `final` par défaut ; injection par constructeur.
- **Exceptions à `final`** : les classes du bridge héritées du générateur (`DevToolsBundle`,
  `DevToolsExtension`, `Configuration`), et toute **couture** qu'un consommateur doit doubler
  (client Claude, fournisseur git) — la raison s'écrit dans le docbloc.
- Tests : `#[CoversClass]` ou `#[CoversNothing]` explicite ; assertions `self::`.
- Un test qui touche git **crée son propre dépôt temporaire** — jamais ce dépôt.
- Un test de non-régression est **vérifié par mutation** : correctif retiré → rouge, remis → vert.
- Ordre d'écriture : **le test**, puis **le code**, puis **la section du README** qui dit comment
  s'en servir — pas qu'elle existe.
- Commits en anglais, Conventional Commits. **Claude ne commite pas** sans demande explicite.

---

## Definition of done

`composer qa` vert · jeu `lowest` exercé quand une API Symfony est touchée · aucune dépendance au
kernel hors `src/Bridge/Symfony/` · idempotence testée pour toute commande qui écrit · XSD à jour
pour tout XML modifié · README mis à jour pour toute commande, option ou format généré ·
`claude_learning.md` complété si une erreur a été corrigée.

---

### Management Rules for This Section

- Add a rule here only when it is:
    - specific to this codebase
    - tied to an architectural or historical decision
    - an intentional and accepted exception
- If a rule later becomes stable and sufficiently generic → **propose** promoting it to
  `claude_core.md`
