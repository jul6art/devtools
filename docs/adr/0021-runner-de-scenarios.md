# ADR-0021 — Runner de scénarios (Panther)

- **Statut** : Accepted — 2026-10-20 (proposée le 2026-09-16 ; phase 1 lancée par jul6art) · Livré le 2026-10-20
- **Décideurs** : jul6art
- **Specs** : § 5.2, § 9 (phase 1 : runner Panther)

## Contexte

Un scénario (ADR-0020) ne vaut que rejoué à l'identique, sans l'agent qui a écrit le code. Le § 5.2
propose **Symfony Panther** (compatible PhpUnit et `dama/doctrine-test-bundle`) ou Playwright ; le MCP
Chrome reste l'outil d'**exploration** de Claude, la régression passe par le runner.

Alternatives écartées :
- *Playwright* — ajoute Node à un outil PHP ; reste possible plus tard derrière la même interface.
- *MCP Chrome comme runner* — non reproductible hors d'une session Claude, et c'est précisément le
  « j'ai vérifié » qu'on veut remplacer.

## Décision

### Deux façons de lancer, un seul moteur

| Mode | Commande | Pour |
|---|---|---|
| **URL** (tout projet web, toute stack) | `devtools scenarios:run --base-url=https://app.localhost [id…]` | Angular, projets Docker-first, recette |
| **PhpUnit** (projet Symfony) | `ScenarioTestCase` fourni par le bridge, un test par scénario via `#[DataProvider]` | CI, base de test isolée par `dama/doctrine-test-bundle` |

- `Scenario\Runner\RunnerInterface` ; `PantherRunner` en est la seule implémentation du lot.
- `symfony/panther` en **`suggest`** : les commandes `scenarios:run` ne sont enregistrées que s'il est
  installé (même règle que l'ADR-0018).
- Chaque action du vocabulaire a une classe (`Scenario\Action\Select2Action`…) ; une action métier
  est testée contre une page HTML de fixture qui reproduit le composant réel.

### Ce que le projet fournit

- `LoginProviderInterface::login(Client, string $role)` et
  `FixtureProviderInterface::load(string $name)`, déclarés dans `.devtools/config.xml` (mode URL :
  une URL de connexion et des identifiants **lus dans l'environnement**, jamais dans un fichier
  versionné) ou en services (mode PhpUnit).

### Résultat

`.devtools/runs/<horodatage>/` (ignoré par git) : `result.xml` (`run.xsd`) — un verdict par scénario
et par étape (`passed|failed|error|skipped`), durée, message, URL courante — plus, par étape
échouée **et** par `<screenshot>` : capture PNG et **snapshot DOM** (HTML sérialisé). Code de sortie
non nul si un scénario échoue.

### Précisions à l'acceptation (2026-10-20)

- **`symfony/panther: ^2.4` et `php-webdriver/webdriver: ^1.16`** en `require-dev` (le second est utilisé
  directement : Panther accepte 1.8.2, qui n'a pas le protocole DevTools), Panther seul en `suggest`. Sans
  lui, `scenarios:run` n'existe pas et la console dit quoi installer (même mécanisme que `mcp:serve`).
  Panther et WebDriver ne sont nommés que dans `Scenario\Action`, `Scenario\Runner`, la commande et
  `Bridge\Symfony\Test` (test d'architecture).
- **Les routes** d'un scénario se résolvent par les fichiers de suivi (`RouteMap`), pas par le routeur de
  l'application : le même chemin en mode URL et en PHPUnit. Paramètres en place, les autres en query string,
  comme Symfony ; `assert-route` compare le chemin courant au motif de la route, exigences en ligne comprises.
- **Connexion** : `form` (champs `_username`/`_password` par défaut, bouton par son texte ou soumission du
  formulaire ; un formulaire qui reste affiché est un échec de connexion) ou `http-basic` — un en-tête
  `Authorization` posé par le protocole DevTools de Chrome, jamais d'identifiants dans l'URL. Entre deux
  scénarios, en-têtes et cookies sont effacés : le suivant commence déconnecté.
- **Fixture** (mode URL) : une commande du projet, lancée depuis sa racine ; un code de sortie non nul est
  une erreur du scénario, avec la dernière ligne de sa sortie.
- **Verdicts** : `failed` quand l'application ne fait pas ce que dit l'étape (assertion, élément introuvable
  ou disparu, attente dépassée) ; `error` quand l'exécution ne peut pas trancher (connexion, fixture, route
  inconnue, paramètre manquant, navigateur). Un échec avant la première étape est le message du scénario,
  ses étapes `skipped`.
- **Identifiants** : nom d'utilisateur et mot de passe sont masqués (`***`) dans les messages, les URL et les
  DOM capturés, sous leurs formes encodées aussi (URL, HTML, base64).
- **`ScenarioTestCase`** démarre lui-même le serveur de Panther et Chrome : `createPantherClient()` démarre le
  noyau, ce qu'un projet sans `KERNEL_CLASS` ne peut pas. Le noyau ne sert qu'à trouver les fournisseurs en
  services. ⚠️ Le serveur web est un autre processus : `dama/doctrine-test-bundle` n'y isole rien, contrairement
  à ce que disait le contexte — c'est une fixture qui remet les données en place qui isole.
- **symfony-minimal servi** : la fixture d'analyse ne répond pas à un navigateur — sa route de composants live
  sans le préfixe de la recette capte toutes les URL, et sa sécurité n'a ni hacheur ni hiérarchie de rôles.
  Plutôt que de la modifier (ses fichiers sont hachés dans tous les instantanés), les tests posent sur une copie
  les deux fichiers de `tests/Fixtures/runtime/symfony-minimal/`. Son `vendor/` n'est installé qu'en local :
  sans lui, ces tests sont ignorés en le disant.
- Délai par étape : `--timeout`, sinon `<scenarios timeout>`, sinon 10 s ; `wait-for` a le sien, en
  millisecondes. Fenêtre `--viewport`, 1280×800 par défaut ; `--headed` pour voir le navigateur.

## Budget d'exécution

Un navigateur par exécution, réutilisé entre scénarios ; délai par étape configurable (10 s par
défaut). Aucune attente fixe (`sleep`) : `wait-for` explicite ou attente implicite de Panther.

## Hors périmètre

Diff visuel (ADR-0022) ; parallélisation ; Playwright ; navigateurs autres que Chrome ; scénarios
mobiles (viewport au-delà d'une option `--viewport=360x800`).

## Critères d'acceptation

- [x] Chaque action du vocabulaire testée contre une page HTML de fixture (serveur PHP intégré)
      *(`tests/Fixtures/browser/`, `PantherRunnerTest` : ce qui passe et ce qui échoue, avec son message)*
- [x] `select2` testé sur une vraie intégration Select2 servie en fixture *(jQuery 3.7.1 et Select2 4.0.13
      embarqués ; options locales et résultats AJAX qui arrivent en retard)*
- [x] Mode URL sur `symfony-minimal` servi localement : squelettes de l'ADR-0020 verts *(avec la surcouche
      d'exécution ; ignoré sans `vendor/` de la fixture)*
- [x] Mode PhpUnit : `ScenarioTestCase` dans `symfony-minimal`, scénarios verts
      *(`SymfonyMinimalScenariosTest` : un test par squelette)*
- [x] Étape échouée → capture, DOM, message, URL dans `result.xml` ; code de sortie 1
- [x] Sans Panther : commandes absentes, message d'installation
- [x] Identifiants de connexion jamais écrits dans `result.xml` ni dans les captures de logs *(ni dans les
      DOM capturés : connexion par formulaire et par HTTP basic)*
- [x] README : section « Rejouer les scénarios » (deux modes, providers, piège ChromeDriver) *(« Replaying
      the scenarios »)*

## Conséquences

- La CI d'un projet qui rejoue ses scénarios a besoin de Chrome et ChromeDriver : c'est documenté, et
  c'est le prix de la reproductibilité.

## Dépendances

ADR-0020.
