# ADR-0042 — Progression et compteurs de la sortie console

- **Statut** : Accepted — 2026-09-18
- **Décideurs** : jul6art
- **Specs** : § 4.3 étape 10, § 8 (métriques de succès)

## Contexte

`workflows:inspect` sur cereezer prend une douzaine de secondes et n'affiche rien avant la fin :
ni ce qu'il fait, ni où il en est. La fin est un tableau nu de quatre colonnes, sans couleur, sans
durée, sans rien qui dise ce que l'exécution a coûté. Une commande Symfony de l'écosystème
`jul6art/*` annonce son titre, sa progression et son bilan ; celle-ci non.

Ce que la sortie doit dire tient en une phrase : **combien de workflows, quoi de neuf, ce que ça a
coûté, et ce qui s'est mal passé**. Les métriques du § 8 (temps de re-scan, couverture) ne sont
mesurables aujourd'hui que par un test de performance dédié ; elles doivent l'être à chaque
exécution.

Contrainte structurante : **le pipeline ne connaît pas la console** — il tourne aussi bien sous
`bin/devtools` que sous `bin/console`, et ses tests l'instancient sans entrée-sortie.

## Décision

### Une interface de progression dans le cœur

`Inspection\Progress\ProgressReporter`, sans aucun type Symfony :

```php
public function stage(string $name, ?int $steps = null): void;  // ouvre une étape
public function advance(string $label): void;                   // un pas, étiqueté
public function finish(): void;                                 // ferme l'étape courante
```

`NullProgressReporter` par défaut — le pipeline se teste sans rien brancher. `InspectionPipeline`
ouvre sept étapes : `stack`, `extract` (une par stack), `graph`, `freshness`, `render` (bornée au
nombre de workflows), `briefs`, `index`.

`Console\ConsoleProgressReporter` implémente l'interface au-dessus de `SymfonyStyle` et d'une
`ProgressBar`. **Aucune barre** quand la sortie n'est pas décorée (CI, redirection) ou quand la
verbosité est `quiet` : dans ces cas l'étape s'annonce en une ligne et rien d'autre.

### Des compteurs mesurés, jamais déduits

`InspectionReport` gagne des compteurs incrémentés **par l'opération elle-même**, jamais relus d'un
état (journal 2026-09-16) :

| Compteur | Incrémenté par |
|---|---|
| `filesParsed` | `PhpReferenceExtractor`, à chaque analyse syntaxique réelle |
| `filesHashed` | `FileHasher`, à chaque `sha256` calculé |
| `gitProcesses` | `GitClient`, à chaque processus lancé |
| `consoleCalls` | `SymfonyConsole`, à chaque appel à la console du projet |
| `briefsWritten`, `briefBytes` | l'écriture d'un brief `page`, `discovery` ou `knowledge` |
| `pagesWritten`, `bytesWritten` | `AtomicFileWriter`, quand l'écriture change réellement le fichier |
| `peakMemoryBytes` | `memory_get_peak_usage(true)` en fin de `run()` |

### Le bilan

Un `Console\SummaryRenderer` rend le même bilan pour `workflows:inspect` et `workflows:apply` :

```
 DevTools — cereezer                                        Symfony 7.4

 ✔ 84 workflows      12 créés · 3 mis à jour · 69 inchangés · 0 orphelins
 ✔ 1 247 fichiers parcourus · 412 hachés · 3 processus git · 4 appels console
 ✔ 15 briefs écrits pour Claude · 152 Ko (~38 000 tokens estimés)
 ⚠ 1 repli statique (symfony @ .)

 Durée 12,4 s · mémoire 214 Mo · rapport .devtools/reports/inspect-….md
```

Couleurs : vert pour une exécution sans réserve, jaune dès qu'il y a un avertissement ou un repli,
rouge sur erreur — c'est-à-dire la couleur du code de sortie que `exitCode()` rend déjà.

**Les tokens** : DevTools n'appelle jamais Claude (ADR-0002), il n'a donc aucun token à mesurer.
La ligne affiche la taille réelle des briefs, et entre parenthèses une **estimation** explicitement
nommée telle, à raison de quatre octets par token. Quand l'ADR-0035 livrera le client API, le
compteur réel remplacera l'estimation sur cette même ligne.

Les mêmes compteurs entrent dans `InspectionReport::toMarkdown()`, sous la ligne de durée.

### Ce que la sortie ne doit pas casser

- `-q` reste muet : ni titre, ni barre, ni bilan.
- `--no-ansi` reste lisible : les symboles `✔ ⚠ ✖` restent, les couleurs partent.
- Les fichiers produits dans `.devtools/` et dans `docs/` ne changent pas d'un octet : la
  progression n'écrit que sur la sortie.
- Les rapports ne sont déjà pas comparés par les tests de bout en bout (ils portent une durée) ; la
  mémoire s'y ajoute sans changer cette règle.

## Budget d'exécution

Aucun coût mesurable : sept ouvertures d'étape, un `advance()` par workflow, une lecture de
`memory_get_peak_usage`. Aucun processus, aucune lecture de fichier supplémentaire. Le test de
performance du § 8 (re-scan de 300 routes en moins de dix secondes) reste la garde.

## Hors périmètre

Une sortie machine (`--format=json`) ; l'affichage temps réel de l'étape `extract` par fichier
(la console du projet répond d'un bloc) ; la progression de `workflows:apply` par section de page ;
toute mesure qui exigerait de chronométrer chaque étape (une horloge par étape rendrait le rapport
instable).

## Critères d'acceptation

- [x] `InspectionPipeline` tourne sans reporter (implémentation nulle) : aucun test existant modifié
- [x] Chaque compteur a un test qui le fait varier par l'**opération** : désactiver le cache de
      `PhpReferenceExtractor` fait monter `filesParsed`, forcer un workflow fait monter
      `filesHashed`
- [x] `--quiet` : sortie vide, code de sortie inchangé
- [x] Sortie non décorée : aucune séquence d'échappement ANSI, aucune barre, le bilan reste
- [x] `--no-ansi` et sortie décorée produisent le **même texte** aux couleurs près
- [x] Le bilan est jaune avec un repli, rouge avec une erreur, vert sinon — un test par couleur
- [x] Deux inspections consécutives d'un projet inchangé n'écrivent toujours aucun fichier hors
      `reports/` (l'idempotence de l'ADR-0010 n'est pas touchée)
- [x] `workflows:apply` affiche le même bilan : brouillons acceptés, refusés, fiches déposées
- [x] Le jeu `lowest` est exercé : `ProgressBar` et `SymfonyStyle` sont des API Symfony

## Conséquences

- Le cœur gagne une couture (`ProgressReporter`) qu'un consommateur peut doubler — elle n'est donc
  pas `final`, et le docbloc dit pourquoi.
- Un compteur affiché devient un contrat : le retirer se verra. C'est voulu — ce sont les métriques
  du § 8.
- L'estimation de tokens est un chiffre que DevTools n'a pas mesuré ; il est étiqueté comme tel, et
  il disparaîtra le jour où le vrai sera disponible.

## Dépendances

ADR-0009, ADR-0010, ADR-0011.
