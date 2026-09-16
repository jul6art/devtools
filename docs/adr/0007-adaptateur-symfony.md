# ADR-0007 — Adaptateur Symfony : la console du projet comme source de vérité

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 3.2, § 4.2, § 4.3 étape 3, § 6 (`Bridge/Symfony`), § 7.1 (`symfony-minimal`, `symfony-legacy-yaml`), § 11

## Contexte

Symfony est la stack de l'équipe et l'étalon du MVP. Le § 4.3 veut une extraction **déterministe par
introspection** : routeur, conteneur compilé, Messenger, listeners, attributs.

⚠️ **Écart avec le § 3.2** : les specs confient au bundle « l'adaptateur Symfony avec accès au
kernel compilé ». Or le standalone doit documenter un projet Symfony **sans y être installé**, et
deux chemins d'introspection (kernel en processus via le bridge, autre chose en standalone)
produiraient deux modèles à maintenir identiques. La décision : **l'adaptateur interroge la console
du projet analysé**, dans les deux modes. Le bridge n'ajoute que l'enregistrement des commandes
dans `bin/console`.

Vérifié le 2026-09-16 sur un projet de l'écosystème : `debug:router`, `debug:container`,
`debug:event-dispatcher` et `debug:config` acceptent `--format=json` ; **`debug:messenger` non**
(texte seulement).

Alternatives écartées :
- *Booter le kernel du projet dans le processus DevTools* — conflits d'autoloader entre les
  `vendor/` de DevTools et du projet, dès qu'une version de `symfony/*` diffère.
- *Tout en analyse statique* — rate les routes YAML/XML, les préfixes, les listeners déclarés en
  configuration (§ 7.1 `symfony-legacy-yaml`).

## Décision

### Deux sources, un modèle

`SymfonyAdapter` (namespace `Inspection\Adapter\Symfony\`) combine :

1. **Introspection console** (`SymfonyConsole`, via `symfony/process`), commande configurable
   (`<symfony console="docker compose exec -T php bin/console" env="dev"/>` — les projets
   Docker-first de l'écosystème en ont besoin) :

   | Commande | Fournit |
   |---|---|
   | `debug:router --format=json` | nom, chemin, méthodes, `_controller`, conditions |
   | `debug:container --tag=console.command --format=json` | commandes → classe |
   | `debug:container --tag=messenger.message_handler --format=json` | handlers → classe, message |
   | `debug:event-dispatcher --format=json` | listeners par événement → classe::méthode, priorité |
   | `debug:config framework workflows --format=json` | machines à états : places, transitions |
   | `debug:config security access_control --format=json` | règles d'accès par chemin |

   Chaque commande est lancée **une fois** par scan. Sortie JSON validée ; une sortie non JSON
   (avertissement PHP avant le JSON, déprécation) est une erreur qui cite les premières lignes.

2. **Analyse statique** (même `PhpReferenceExtractor`, ADR-0006) pour ce que la console ne dit pas :
   `#[AsCronTask]`, `#[AsPeriodicTask]`, `#[AsTwigComponent]`, `#[AsLiveComponent]`, migrations
   (`migrations/`), fixtures (`DataFixtures/`), et les appels `path()`/`url()` littéraux des templates
   (navigation).

**Repli** : si la console échoue (dépendances non installées, kernel qui ne boote pas), l'adaptateur
passe en **statique seul** (`#[Route]`, `#[AsCommand]`, `#[AsMessageHandler]`,
`#[AsEventListener]`), `confidence=medium`, et le rapport le dit en tête avec la cause. Jamais de
repli silencieux.

### Correspondance avec les types du § 4.2

| Type | Workflow par | Remarque |
|---|---|---|
| routes | route (ADR-0003 pour les satellites) | les routes de `vendor/` (profiler, bundles) sont exclues : contrôleur hors projet |
| commands | commande du projet | |
| async | handler Messenger ; tâche planifiée | |
| events | listener/subscriber **du projet** | les listeners `kernel.*` du projet sont ajoutés en `dependsOn` de **chaque** route (§ 11) |
| ui | composant Twig/Live | ⚠️ **écart § 4.2** : un formulaire n'est pas un workflow (il n'a pas de déclencheur propre) ; il apparaît dans les composants des routes qui l'utilisent |
| data | `data.migrations`, `data.fixtures` | un workflow par famille, pas par migration |
| integrations | — | hors périmètre, compteur 0 |

- **Navigation** : pour une route, les routes atteintes par `path()`/`url()` littéraux dans ses
  templates et par `redirectToRoute()` littéral dans son contrôleur → `Workflow::navigation`.
- **États** : un workflow dont un fichier référence une machine de `framework.workflows` reçoit
  ses places et transitions → `Workflow::states` (diagramme `stateDiagram`, ADR-0008).
- **Sécurité** : pour une route, `#[IsGranted]` de la classe et de la méthode (analyse statique) et
  la première règle `access_control` dont le chemin correspond → `EntryPoint::attributes['security']`
  (ex. `ROLE_OPERATOR`, `IS_AUTHENTICATED_FULLY`, `PUBLIC_ACCESS`), `—` si rien. C'est ce qui dit à
  un scénario (ADR-0020) avec quel rôle se connecter.
- **Tests existants** : en plus d'ADR-0006, un test qui contient le chemin littéral d'une route sans
  paramètre (`'/orders/new'`).
- **Fichiers structurants** : `config/routes*` pour les routes, `config/packages/messenger.yaml`
  pour async, `config/services.yaml` pour tous, ajoutés en `role=config`.
- `source=native:symfony`, `confidence=high` avec console, `medium` en repli.

### Le bridge

`DevToolsBundle` enregistre les commandes du cœur sous `devtools:` ; le chemin par défaut devient
`%kernel.project_dir%`. Aucune logique d'introspection dans `src/Bridge/Symfony/`.

Dépendance ajoutée : `symfony/process`.

## Budget d'exécution

**Au plus 6 processus console par scan**, quel que soit le nombre de routes, commandes ou handlers.
Délai maximal par processus configurable (60 s par défaut) ; dépassement = repli statique annoncé.

## Hors périmètre

`integrations` (clients HTTP sortants, webhooks) ; Messenger middlewares, voters et security comme
mécanismes transverses (la rédaction IA peut les citer, ADR-0011) ; routes API Platform
(elles apparaissent via `debug:router`, sans lien vers la ressource) ; Symfony < 6.4.

## Critères d'acceptation

- [x] `symfony-minimal` (routes, commande, handler, listener, formulaire, Twig, composant Live,
      machine à états, migration) : liste des workflows extraits = liste attendue, 100 % (§ 8)
- [x] `symfony-legacy-yaml` (routes YAML, services XML) : mêmes workflows qu'en attributs, via la
      console
- [x] Les sorties console sont des fixtures enregistrées pour les tests unitaires ; un test de bout
      en bout lance la vraie console de `symfony-minimal` (dépendances installées par le test)
- [x] Sécurité extraite : `#[IsGranted]` de classe, de méthode, `access_control` seul, route publique
- [x] Console absente ou en erreur : repli statique, `confidence=medium`, cause affichée en tête du
      rapport *(affichage en tête du rapport livré et testé avec l'ADR-0009)*
- [x] Une sortie console polluée par une déprécation avant le JSON échoue avec les lignes fautives
- [x] La commande console configurée avec un préfixe Docker est découpée en arguments, jamais passée
      à un shell
- [x] Au plus 6 processus console lancés (compteur), sur un projet de 10 comme de 300 routes

## Conséquences

- Documenter un projet Symfony avec la meilleure précision demande qu'il **boote** en `dev` ; c'est
  aussi ce qu'un développeur a de toute façon.
- Le bridge reste trivial ; le jour où une information n'est accessible que dans le kernel, il
  faudra une nouvelle ADR plutôt qu'un second chemin discret.

## Dépendances

ADR-0003, ADR-0005, ADR-0006.
