# ADR-0006 — Graphe de dépendances PHP et regroupement

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 4.3 étapes 4 et 5, § 4.6.2 étape 4, § 4.7 (« Non couvert »), § 11 (faux « inchangé »), § 12 question 2

## Contexte

Un point d'entrée seul ne dit pas ce qu'un changement impacte : c'est la **liste des fichiers
traversés** qui alimente la section « Composants impliqués », le `<files>` du XML, la fraîcheur, et
la section « Non couvert ». Le § 12 laisse la profondeur ouverte.

Cette résolution est commune à tous les adaptateurs PHP (Symfony, générique) ; elle ne dépend pas du
framework. Les stacks non PHP reçoivent leurs fichiers de la voie Claude (ADR-0013).

Alternatives écartées :
- *Autoloader Composer + réflexion* — exécute le code du projet analysé, et ne voit pas les
  templates.
- *Profondeur illimitée* — un service partagé (logger, `EntityManager`) rattache tout à tout, et
  chaque modification rend tout périmé.

## Décision

### Résolution (namespace `Inspection\Graph\`)

- `PhpReferenceExtractor` (sur `nikic/php-parser`) extrait d'un fichier, **sans l'exécuter** : les
  `use`, types de paramètres et de propriétés (injection constructeur), `new X`, `X::`, `X::class`,
  `extends`/`implements`, attributs, et les chaînes littérales passées à `render()`, `renderView()`,
  `#[Template]` (templates Twig).
- `ClassLocator` résout une classe en fichier via l'`autoload` PSR-4 de `composer.json` du projet
  (pas via l'autoloader) ; une classe qui tombe dans `vendor/` devient un `PackageRef`.
- `TwigReferenceExtractor` : `extends`, `include`, `embed`, `use`, `{{ include() }}` et
  `component()` à chaîne littérale — par expressions régulières sur le source, pas par le moteur
  Twig (aucune dépendance).
- `DependencyResolver` : parcours en largeur depuis le fichier du point d'entrée, **profondeur 3 par
  défaut** (`<graph depth>`), fichiers du projet uniquement. Profondeur comptée en sauts : contrôleur
  (0) → service (1) → repository (2) → entité (3).
- **Fichiers structurants toujours inclus**, quelle que soit la profondeur (garde contre le faux
  « inchangé » du § 11) : la configuration déclarant le point d'entrée (fournie par l'adaptateur),
  et les listeners globaux passent par `dependsOn` (ADR-0007), pas par `files`.
- Le rôle (`FileRef::role`) est déduit du chemin et du suffixe (`*Controller`, `*Type`,
  `Repository/`, `.twig`…) ; inconnu → `other`.
- **Cache d'analyse** en mémoire pour l'exécution : un fichier partagé par N workflows est analysé
  une fois — *sauf un fichier lu une seule fois (les tests du projet, lus par `TestLocator`), dont l'arbre
  est relâché aussitôt : garder ceux de quelques centaines de fichiers de test épuisait les 128 Mo d'un
  dépôt réel (précision du 2026-09-17)*.
- **Autoload hors PSR-4** : `classmap`, `psr-0` et `files` ne sont pas résolus. *Précision du
  2026-09-17 (audit du MVP) : ce silence donnait des pages qui paraissent complètes sur un projet
  legacy — exactement le public de l'ADR-0014. `ClassLocator` produit désormais un avertissement qui
  nomme le `composer.json` et les sections en cause.*

### Regroupement (étape 5)

- Par défaut, **un point d'entrée = un workflow**.
- Satellites **automatiques** dans deux cas certains : plusieurs routes vers la **même méthode de
  contrôleur** (variantes localisées, méthodes HTTP séparées) ; et, *depuis le 2026-09-17*, plusieurs
  **handlers du même message** — un projet réel en a quatre pour un seul message, et les documenter
  séparément demandait un alias par handler (ADR-0003).
- Satellites **déclarés** : `<groups><group main="app_order_index"><satellite>app_order_export
  </satellite></group></groups>` dans `config.xml`. Un groupe qui référence un point d'entrée
  inconnu est une erreur.

### Couverture

`CoverageCalculator` : fichiers des `sourceDirs` (ADR-0005), extensions connues, moins l'union des
`files` de tous les workflows → `InspectionResult::uncovered`, trié. C'est la section « Non couvert »
du menu.

### Tests existants

`TestLocator` : fichiers sous les dossiers de test (`tests/`, `autoload-dev`) qui référencent un
fichier du workflow à profondeur ≤ 1 (même extracteur), plus ce que l'adaptateur ajoute
(ADR-0007 : chemins d'URL littéraux). *Précision du 2026-09-17 (constatée sur le `.devtools/` de ce
dépôt) : seuls les fichiers **nommés** comme un test comptent (`*Test.php`, `*TestCase.php`,
`*Spec.php`, `*_test.php`) ; un dossier de tests contient aussi des fabriques et des classes de base,
et une page listait `GraphFixture.php` comme test d'un workflow qu'il n'exerce pas.*

Dépendance ajoutée : `nikic/php-parser`.

## Budget d'exécution

- Chaque fichier est **lu et analysé au plus une fois** par exécution, quel que soit le nombre de
  workflows qui le traversent.
- Coût linéaire en nombre de fichiers atteints ; aucun parcours de `vendor/`.

## Hors périmètre

Résolution des services injectés par nom (`#[Autowire(service: 'x')]`) et des appels dynamiques
(`$this->container->get()`) ; analyse du JavaScript et des assets ; regroupement proposé par Claude ;
profondeur variable par type de fichier.

## Critères d'acceptation

- [x] Sur `symfony-minimal`, le workflow d'une route liste exactement contrôleur, formulaire,
      service, repository, entité, template et templates parents attendus — ni plus, ni moins
- [x] Profondeur 1, 2, 3 : trois snapshots distincts et justifiés sur la même route
- [x] Une classe de `vendor/` apparaît en `PackageRef` avec la version du lock, jamais en `FileRef`
- [x] Un cycle (A use B, B use A) termine
- [x] Deux handlers du même message forment un workflow, l'un satellite de l'autre
- [x] Deux routes vers la même méthode forment un workflow ; un groupe déclaré rattache un satellite ;
      un groupe vers un point d'entrée inconnu échoue avec son nom
- [x] Un fichier source que rien ne référence apparaît dans `uncovered`
- [x] Un fichier PHP syntaxiquement invalide produit un avertissement nommé et n'arrête pas le scan
- [x] Un fichier partagé par 50 workflows est analysé une fois (compteur de l'extracteur)
- [x] Un projet déclarant `classmap`, `psr-0` ou `files` produit un avertissement qui les nomme ;
      un projet PSR-4 n'en produit aucun
- [x] Une classe d'aide d'un dossier de tests (`tests/Support/OrderFactory.php`) n'est pas listée
      comme test, même quand elle utilise les fichiers du workflow

## Conséquences

- Un fichier atteint à profondeur 4 n'invalide pas le workflow : c'est le compromis assumé du
  § 12 question 2, réglable par projet.
- Le graphe est **statique** : ce qui est câblé dynamiquement n'y apparaît pas, et le menu le montre
  honnêtement dans « Non couvert ».

## Dépendances

ADR-0003, ADR-0005.
