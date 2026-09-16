# ADR-0014 — Adaptateur PHP générique (sans framework)

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 3 (adaptateurs), § 4.3 étape 3, § 7.1 (`plain-php`), § 9 (phase 0)

## Contexte

Le § 9 place un adaptateur « générique PHP » dans la phase 0. La voie Claude (ADR-0013) couvrirait
un projet PHP sans framework, mais avec une confiance moyenne et un coût IA, alors que le graphe PHP
(ADR-0006) existe déjà : un adaptateur natif ne coûte ici que la détection des points d'entrée.

**Priorité la plus basse du MVP** : aucun autre lot n'en dépend ; il peut glisser après la livraison
sans rien bloquer, sur décision explicite.

## Décision

`GenericPhpAdapter` (namespace `Inspection\Adapter\GenericPhp\`), choisi quand `stack.xml` indique
PHP sans framework reconnu :

| Type | Point d'entrée | Identifiant |
|---|---|---|
| routes | chaque fichier `.php` d'un dossier web (`public/`, `web/`, `www/`, ou `<web-root>` de `config.xml`) | `route.<chemin sans extension, / → .>` (`public/orders/new.php` → `route.orders.new`) |
| commands | chaque classe `#[AsCommand]` ou sous-classe de `Symfony\Component\Console\Command\Command` du projet quand `symfony/console` est une dépendance (application console sans framework) ; sinon chaque entrée `bin` de `composer.json`, chaque `scripts` qui appelle un fichier PHP du projet, chaque fichier de `bin/` avec shebang PHP | `command.<nom de commande ou de script>` |
| data | `migrations/` s'il existe | `data.migrations` |

- Fichiers impliqués : `DependencyResolver` (ADR-0006), plus les `require`/`include` à chaîne
  littérale ou `__DIR__ . '…'` (ajout à `PhpReferenceExtractor`).
- Navigation : liens `href`/`action` littéraux vers des fichiers du dossier web.
- `source=native:generic-php`, `confidence=high` pour les points d'entrée, le graphe restant
  statique (ADR-0006).

## Budget d'exécution

Aucun processus lancé ; un parcours du dossier web et de `bin/`.

## Hors périmètre

Routeurs maison (front controller unique avec table de routes) : ils apparaissent comme **une**
route, et le menu montre le reste dans « Non couvert » — un routeur maison se documente par la voie
Claude en forçant `adapter="claude"` dans `stack.xml`. Laravel, Slim, WordPress.

## Critères d'acceptation

- [ ] `plain-php` : pages web, script CLI, script composer et migrations extraits = liste attendue
- [ ] Une application `symfony/console` sans framework : un workflow par commande `#[AsCommand]`, et
      le binaire lui-même n'est pas un workflow en double
- [ ] `require __DIR__.'/../lib/db.php'` apparaît dans les fichiers impliqués
- [ ] `bin/devtools workflows:inspect tests/Fixtures/projects/plain-php` : snapshot complet, puis
      idempotence (ADR-0010)
- [ ] Forcer `adapter="claude"` dans `stack.xml` fait passer le projet par la voie Claude

## Conséquences

- Un projet PHP ancien est documenté sans IA pour ses faits ; la rédaction suit l'ADR-0011 comme
  pour tout projet.

## Dépendances

ADR-0006, ADR-0009.
