# ADR-0030 — Adaptateur natif Laravel

- **Statut** : Accepted — 2026-10-21 (proposée le 2026-09-16 ; phase 3 lancée par jul6art le 2026-10-21) · Livré le 2026-10-21
- **Décideurs** : jul6art
- **Specs** : § 3 (adaptateurs), § 4.2, § 4.3 étape 3, § 7.1 (`laravel-minimal`), § 8, § 9 (phase 3)

## Contexte

Laravel est documenté par la voie Claude depuis le MVP (ADR-0013), avec une confiance moyenne et un
coût IA à chaque nouveau fichier. Un adaptateur natif le rend déterministe, et réutilise le graphe PHP
(ADR-0006) : seule l'extraction des points d'entrée est à écrire. Le principe est celui de Symfony
(ADR-0007) : **la console du projet fait foi**, l'analyse statique complète et sert de repli.

## Décision

`LaravelAdapter` (namespace `Inspection\Adapter\Laravel\`), choisi quand `stack.xml` détecte
`laravel/framework`. Console configurable (`<laravel artisan="php artisan"/>`, préfixe Docker/Sail
possible).

| Type | Source | Identifiant |
|---|---|---|
| routes | `php artisan route:list --json` : nom, URI, méthodes, action, middlewares | `route.<nom>` ; route sans nom → `route.<méthode>.<uri normalisée>` |
| commands | `php artisan list --format=json` filtré sur les classes du projet (statique : `app/Console/Commands`) | `command.<nom>` |
| async | classes `ShouldQueue` (jobs), listeners de `EventServiceProvider` / découverte, planification de `routes/console.php` ou `Kernel::schedule` | `async.<kebab(classe)>` |
| events | `php artisan event:list --json` quand disponible, sinon statique | `event.<kebab(listener)>` |
| ui | composants Livewire et Blade (`app/View/Components`, `app/Livewire`) | `ui.<kebab(composant)>` |
| data | `database/migrations`, `database/seeders` | `data.migrations`, `data.seeders` |

- **Sécurité** : middlewares de la route (`auth`, `can:…`) et policies référencées → attribut
  `security`.
- **Templates** : `view('…')` et `View::make('…')` littéraux → fichiers Blade ;
  `TwigReferenceExtractor` a son pendant `BladeReferenceExtractor` (`@extends`, `@include`,
  `<x-composant>`, `route('…')` pour la navigation).
- `source=native:laravel` ; `confidence=high` avec Artisan, `medium` en repli.
- `resources/knowledge/laravel-11.md` et `laravel-12.md` embarqués (ceux produits par acquisition sur
  la fixture, relus et promus).
- ⚠️ Un projet déjà documenté par la voie Claude change de source : ses identifiants peuvent différer.
  `inspect` **liste les écarts** avant de basculer, et la bascule exige `--switch-adapter`.

## Budget d'exécution

Au plus 4 processus Artisan par scan, quel que soit le nombre de routes.

## Hors périmètre

Lumen ; Octane ; Filament et Nova en tant que types dédiés (leurs routes apparaissent via `route:list`) ;
Laravel < 11.

## Critères d'acceptation

- [x] Fixture `laravel-minimal` (routes nommées et non nommées, commande, job, listener, Livewire,
      migration, seeder) : workflows extraits = liste attendue *(Laravel 13.38 et Livewire 4 ;
      `LaravelAdapterTest::EXPECTED_WORKFLOWS`, douze workflows)*
- [x] Sorties Artisan enregistrées pour les tests unitaires ; un test de bout en bout avec Artisan réel
      *(`tests/Fixtures/console/laravel-minimal/`, `RealArtisanTest`)*
- [x] Repli statique annoncé si Artisan échoue *(groupes, préfixes et middlewares des fichiers de routes
      suivis ; mêmes identifiants, confiance `medium`, cause en tête du rapport)*
- [x] Sécurité extraite des middlewares *(par l'alias écrit : `auth`, `can:view,order`… ; la policy d'un
      `can:` devient un fichier de la route)*
- [x] Bascule depuis la voie Claude : écarts listés, `--switch-adapter` requis *(`AdapterSwitch`, commun
      aux adaptateurs natifs des ADR-0032 et 0033)*
- [x] Matrice de bout en bout de l'ADR-0015 étendue à `laravel-minimal` *(avec le vrai Artisan)*
- [x] README : Laravel ajouté aux stacks natives

## Écarts assumés à la livraison

- **Les listeners ne sont pas des workflows `events`** : l'ADR-0043, postérieure à celle-ci, en a fait des
  mécanismes des workflows qui traversent leur événement. `event:list --json` les liste ; s'il échoue, la
  découverte de Laravel est relue dans le code (`app/Listeners`, type du premier paramètre).
- **Trois processus Artisan**, pas quatre : `route:list`, `list` et `event:list`. La planification se lit
  dans `routes/console.php` (ou `Kernel::schedule`) : `schedule:list` exige le magasin de cache, en base
  par défaut. Une commande planifiée est un workflow `async` qui dépend de la commande ; un job planifié
  porte sa fréquence sur son propre workflow.
- **Fiches `laravel-12` et `laravel-13`**, rédigées depuis la documentation officielle : Laravel 13 est la
  version courante à la livraison, et la fixture la sert.
- **Routes sans nom** : `route.<méthode>.<uri>` avec les segments en points (`GET /about` →
  `route.get.about`, `/` → `route.<méthode>.root`), cohérent avec la dérivation des noms (ADR-0003).

## Conséquences

- Les deux frameworks PHP majeurs sont documentés sans IA pour leurs faits.

## Dépendances

ADR-0015.
