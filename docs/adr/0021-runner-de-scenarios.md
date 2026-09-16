# ADR-0021 — Runner de scénarios (Panther)

- **Statut** : Proposed — 2026-09-16
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

## Budget d'exécution

Un navigateur par exécution, réutilisé entre scénarios ; délai par étape configurable (10 s par
défaut). Aucune attente fixe (`sleep`) : `wait-for` explicite ou attente implicite de Panther.

## Hors périmètre

Diff visuel (ADR-0022) ; parallélisation ; Playwright ; navigateurs autres que Chrome ; scénarios
mobiles (viewport au-delà d'une option `--viewport=360x800`).

## Critères d'acceptation

- [ ] Chaque action du vocabulaire testée contre une page HTML de fixture (serveur PHP intégré)
- [ ] `select2` testé sur une vraie intégration Select2 servie en fixture
- [ ] Mode URL sur `symfony-minimal` servi localement : squelettes de l'ADR-0020 verts
- [ ] Mode PhpUnit : `ScenarioTestCase` dans `symfony-minimal`, scénarios verts
- [ ] Étape échouée → capture, DOM, message, URL dans `result.xml` ; code de sortie 1
- [ ] Sans Panther : commandes absentes, message d'installation
- [ ] Identifiants de connexion jamais écrits dans `result.xml` ni dans les captures de logs
- [ ] README : section « Rejouer les scénarios » (deux modes, providers, piège ChromeDriver)

## Conséquences

- La CI d'un projet qui rejoue ses scénarios a besoin de Chrome et ChromeDriver : c'est documenté, et
  c'est le prix de la reproductibilité.

## Dépendances

ADR-0020.
