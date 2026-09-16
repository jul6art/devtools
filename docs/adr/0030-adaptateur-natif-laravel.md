# ADR-0030 — Adaptateur natif Laravel

- **Statut** : Proposed — 2026-09-16
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

- [ ] Fixture `laravel-minimal` (routes nommées et non nommées, commande, job, listener, Livewire,
      migration, seeder) : workflows extraits = liste attendue
- [ ] Sorties Artisan enregistrées pour les tests unitaires ; un test de bout en bout avec Artisan réel
- [ ] Repli statique annoncé si Artisan échoue
- [ ] Sécurité extraite des middlewares
- [ ] Bascule depuis la voie Claude : écarts listés, `--switch-adapter` requis
- [ ] Matrice de bout en bout de l'ADR-0015 étendue à `laravel-minimal`
- [ ] README : Laravel ajouté aux stacks natives

## Conséquences

- Les deux frameworks PHP majeurs sont documentés sans IA pour leurs faits.

## Dépendances

ADR-0015.
