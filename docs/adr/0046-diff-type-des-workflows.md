# ADR-0046 — Diff typé des workflows : ce qui a changé, pas seulement que ça a changé

- **Statut** : Accepted — 2026-09-18
- **Décideurs** : jul6art
- **Specs** : § 4.6 (fraîcheur), § 4.8 (contrôle), § 9 (phase 1)
- **Amende** : ADR-0010 (fraîcheur), ADR-0017 (contrôle en CI, qui s'appuiera dessus)

## Contexte

La fraîcheur de l'ADR-0010 répond par un verbe : `create`, `rewrite`, `keep`, `orphan`,
`manual-stale`. Elle dit **qu'il faut réécrire**, jamais **ce qui a bougé**. Conséquence mesurée sur
les trois projets documentés le 2026-09-18 : la gate d'un projet affiche « la doc des workflows a
changé : relisez-la », et le relecteur se retrouve devant 127 fichiers modifiés sans savoir lequel
porte un fait nouveau et lesquels n'ont qu'une date de régénération.

Or **tout ce qu'il faut pour répondre est déjà écrit**. Le fichier de suivi
`.devtools/workflows/<type>/<id>.xml` porte, pour chaque workflow : ses points d'entrée et leurs
attributs (`path`, `methods`, `security`), ses fichiers avec leur `sha256`, ses **décisions**
(`target`, `value`, `condition`, `file`, `line`), ses **mécanismes** (classe, événement, priorité, et
leurs propres décisions), ses dépendances, ses tests, ses paquets — et le **commit** de sa dernière
écriture. Il ne manque qu'une comparaison.

⚠️ **Le piège est l'échelle, et il est mesuré** : sur cereezer, **233 des 259 workflows partagent
`src/Entity/User.php`**. Une ligne changée dans un mutateur de cette entité produit 233 workflows
« dérivés ». Une revue présentée par workflow serait morte à sa première utilisation réelle — c'est
exactement le défaut que l'ADR-0045 a corrigé pour les pages, et il se reproduirait ici.

Alternatives écartées : un diff textuel des pages générées (il mélange la prose de Claude, les dates
de régénération et les faits — donc il rend illisible ce qu'on cherche) ; un diff des fichiers de
suivi (du XML, qui change à chaque `sha256` sans qu'aucun fait ne bouge) ; une baseline dédiée dans
un fichier neuf (une seconde source de vérité à tenir synchronisée avec le suivi, donc une source de
divergence — le suivi existe déjà et fait foi).

## Décision

### 1. Le suivi EST la baseline

Aucun fichier neuf, aucun état d'approbation à part. Le diff compare **le suivi lu sur disque** au
**modèle que l'inspection vient de reconstruire**. Accepter un changement, c'est écrire le suivi —
ce que `workflows:inspect` fait déjà. Le refuser, c'est ne pas l'écrire : la dérive reste visible
tant que le code n'est pas revenu.

### 2. Un changement est un objet typé, pas une ligne de texte

`Inspection\Diff\WorkflowChange` : une **nature** (`added`, `removed`, `modified`), un **sujet**, une
**cible**, la valeur d'avant, celle d'après, et le fichier où le fait est écrit.

| Sujet | Cible | Ce qui compte comme « modifié » |
|---|---|---|
| `entrypoint` | `kind:name` | — (un point d'entrée est ajouté ou retiré) |
| `attribute` | `name#attribut` | la valeur (`security`, `path`, `methods`…) |
| `decision` | `Classe::champ` | la condition, ou la valeur |
| `mechanism` | `kind:name@event` | la priorité |
| `dependency`, `test`, `package` | leur identifiant | la version, pour un paquet |

### 3. ⚠️ `file:line` n'entre JAMAIS dans l'identité d'un fait

Deux faits sont le même fait s'ils ont le même sujet et la même cible — le fichier et la ligne sont
des données d'**affichage**. Sans cette règle, déplacer une méthode de vingt lignes produirait
autant de « changements » que le fichier porte de décisions, et le premier refactoring viderait
l'outil de son sens.

Corollaire : **un fichier dont le `sha256` change sans qu'aucun fait ne bouge ne produit aucun
changement.** La commande le dit d'une ligne (« 12 fichiers modifiés · 0 fait changé ») au lieu de
le taire.

### 4. Le diff se calcule là où les deux versions sont déjà en main

Dans `InspectionPipeline`, l'ancien suivi (`$old`) et le workflow reconstruit (`$workflow`) sont
côte à côte au moment de la décision de fraîcheur. Le diff s'y calcule et voyage dans
`InspectionReport::$changes` : **aucun second parcours du code, aucun second hachage, aucun
processus git de plus**. Il n'est calculé que pour les workflows dont la fraîcheur dit qu'ils ont
bougé ; un `create` rend « nouveau workflow » et un `orphan` « workflow disparu », sans énumérer
leurs faits.

### 5. La sortie groupe par FAIT, jamais par workflow

```
App\Entity\User::email — condition modifiée                     src/Entity/User.php:250
  - null === Strings::lowerEmail($email)
  + '' === trim($email)
  233 workflows · route.admin.user.index, route.admin.customer.index, … (+231)
```

Les groupes sont triés par nombre de workflows touchés décroissant : ce qui touche tout le projet se
lit en premier. Un identifiant de workflow n'apparaît qu'en liste, tronquée au-delà de trois, avec
son compte.

### 6. `devtools workflows:diff [path]`

```
--only=<type>     un type de workflow
--code            le diff git du fichier qui porte chaque fait
--format=text|md  console, ou Markdown pour une pull request
--exit-code       sortie 1 s'il existe au moins un changement (défaut : 0)
```

La commande **n'écrit rien** : elle exécute le pipeline en lecture seule (`--dry-run` de
l'ADR-0009). Codes de sortie : `0` aucun changement (ou `--exit-code` absent), `1` au moins un
changement avec `--exit-code`, `2` erreur.

### 7. `--code` : le diff du code, pris depuis le commit du suivi

Chaque suivi porte le commit de sa dernière écriture. Le diff de code d'un fait est donc
`git diff <ce commit>..HEAD -- <le fichier du fait>` : rien à stocker. **Un processus git par
référence distincte**, jamais un par fait ni un par workflow — les suivis d'un projet partagent
presque toujours le même commit.

## Budget d'exécution

Le coût d'un `inspect --dry-run`, plus une comparaison d'ensembles en mémoire : sur cereezer (259
workflows, 731 fichiers), l'inspection mesure 5 s et le diff n'ajoute ni entrée-sortie ni processus.
`--code` ajoute un processus git par référence distincte.

## Hors périmètre

- **Changer la décision de fraîcheur.** Un fichier modifié sans fait changé fait toujours réécrire
  la page : la prose de Claude peut être fausse alors même que les faits tiennent. Revisiter cela
  demande sa propre ADR, et le diff typé est ce qui permettra de la mesurer d'abord.
- L'acceptation et le rejet interactifs, l'amendement ciblé des pages, la restauration du code : lot
  suivant.
- La règle PHPStan : elle n'a rien à dire tant que ce lot n'existe pas.
- `navigation` et `states`, que le suivi ne porte pas : ils ne sont pas comparés ici.

## Critères d'acceptation

- [x] `WorkflowDiffer` rend la nature, le sujet, la cible, l'avant et l'après pour chacun des sept
      sujets, sur des cas construits à la main
- [x] Une décision dont seule la **ligne** change ne produit aucun changement — test dédié
- [x] Une décision dont la condition change rend **un** `modified`, pas un `removed` + un `added`
- [x] Un workflow créé rend « nouveau », un orphelin rend « disparu », sans énumérer leurs faits
- [x] Le regroupement rassemble un fait partagé par N workflows en **une** entrée qui les compte
- [x] `workflows:diff` n'écrit aucun fichier : arborescence comparée avant et après
- [x] `--exit-code` : 1 quand un fait a changé, 0 quand seuls des octets ont changé
- [x] `--code` : un seul processus git pour des faits partageant la même référence (compteur)
- [x] Sorties `text` et `md` figées en snapshot
- [x] README : section « Ce qui a changé »

## Conséquences

- La gate d'un projet peut enfin dire **quoi** relire. L'ADR-0017 s'écrira contre cette sortie
  plutôt que contre un verbe de fraîcheur.
- `InspectionReport` porte les changements : `workflows:inspect` peut les afficher sans travail
  supplémentaire, ce qui rend la régénération explicable au lieu d'être subie.
- Le jour où un lot voudra n'amender que les sections touchées d'une page, la liste des faits
  changés est exactement ce qu'il lui faut — `PageBrief` sait déjà porter une liste de sections.

## Dépendances

ADR-0003 (modèle), ADR-0004 (suivi XML), ADR-0009 (pipeline), ADR-0010 (fraîcheur), ADR-0043
(décisions et mécanismes dans le modèle).
