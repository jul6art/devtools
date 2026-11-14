# ADR-0054 — Une classe trop longue se voit là où on écrit le code : une garde PHPStan de taille

- **Statut** : Accepted — 2026-11-14 (décidé par jul6art : « fais aussi la garde de taille ») · Livré le
  2026-11-14 (v3.8.0)
- **Décideurs** : jul6art
- **Specs** : § 4.8 (la gate), § 9 (phase 1)
- **Dépend de** : ADR-0048 (la règle PHPStan de dérive)
- **Amende** : ADR-0048, § « Conséquences » (« le pont PHPStan ne contient aucune logique propre »)

## Contexte

Six applications de l'écosystème avaient, le 2026-11-10, des classes de plus de 500 lignes. La décision a été de
ne pas lancer de chantier — les raccourcir « au fil de l'eau », à chaque intervention —, mais sans garde, le fil
de l'eau va dans un seul sens : 16 classes ce jour-là, 26 quatre jours plus tard.

La garde voulue est celle posée le même jour sur les contrôleurs, qui ne reçoivent plus l'entity manager :
refuser le nouveau, figer l'existant dans une liste qui ne peut que diminuer. Pour les contrôleurs, une extension tierce (`spaze/phpstan-disallowed-calls`) suffisait ; aucune
extension connue ne mesure la longueur d'une classe (cherché le 2026-11-14 : PHPStan, symplify/phpstan-rules,
spaze), et il faut une règle à soi.

⚠️ **Le piège serait de la recopier dans chaque application** : dix copies d'une règle de quarante lignes, qui
dériveraient comme dérivait le code que le squelette recopiait dans chaque application. Il lui faut un paquet partagé, de
développement, que les applications chargent déjà dans PHPStan : DevTools est le seul.

## Décision

### 1. Une règle à part, qui ne s'active que si le projet l'inclut

`resources/phpstan/class-length.neon`, distinct de `extension.neon` : un projet peut prendre la garde de taille
sans la règle de dérive, et l'inverse. Rien n'est activé par l'installation du paquet.

### 2. DevTools mesure, PHPStan affiche — l'amendement de l'ADR-0048

L'ADR-0048 disait du pont PHPStan qu'il « ne contient aucune logique propre » : la dérive est calculée par
l'inspection, une seule vérité. Ici, il n'y a pas d'inspection à réutiliser, et la logique est petite. Elle vit
quand même hors du pont : `ClassLength` décide (exclusion, longueur, message), sans dépendre de PHPStan, et se
teste seule ; `ClassLengthRule` ne fait que lui passer le nœud.

### 3. Ce qui se mesure

Les lignes de la **déclaration** d'une classe, d'une interface, d'un trait ou d'une énumération : de son docbloc
(ou de son premier attribut) à son accolade fermante. Une classe anonyme n'est pas mesurée — elle vit dans une
autre. ⚠️ Ce n'est pas exactement la mesure du 2026-11-10 (`wc -l` du fichier) : l'en-tête — `namespace`, `use` — ne
compte pas, parce qu'il ne dit rien de ce que la classe fait.

Seuil par défaut **500**, et deux exclusions par défaut, celles de la mesure du 2026-11-10 : `*/Entity/*` et
`*/DataFixtures/*` — une entité porte ses colonnes, des fixtures leurs données. Les deux se règlent dans le
`phpstan.neon` du projet (`devtoolsClassLength.max`, `devtoolsClassLength.exclude`, motifs `fnmatch()` sur le
chemin du fichier).

### 4. Un message stable, pour qu'une liste figée suive la classe

```
 ------ ---------------------------------------------------------------------
  Line   src/Service/ReportBuilder.php
 ------ ---------------------------------------------------------------------
  24     Class App\Service\ReportBuilder is longer than 500 lines.
         🪪 devtools.classLength
         💡 612 lines from its docblock to its closing brace: split what it does
            into classes of their own.
 ------ ---------------------------------------------------------------------
```

⚠️ **Le nombre de lignes est dans le conseil, pas dans le message.** Une baseline PHPStan compare le message :
avec le nombre dedans, chaque ligne ajoutée ou retirée à une classe figée la ferait sortir de la liste et
rapporter une erreur — même quand la classe raccourcit. Le message ne nomme que la classe et le seuil.

### 5. Ce qu'elle ne fait pas

Elle ne fige rien elle-même : la liste est la baseline du projet (`phpstan-class-length-baseline.neon`), qui
ne peut que diminuer — PHPStan refuse une exception qui ne sert plus. ⚠️ **Une classe figée qui grandit encore
n'est pas signalée** : la liste figée dit « ces classes-là ont le droit d'être longues », pas « jusqu'à
combien ». Limite assumée : un plafond par classe se réécrirait à chaque modification.

## Critères d'acceptation

- [x] Une classe de plus de `max` lignes produit une erreur, à sa ligne, identifiant `devtools.classLength`
- [x] Le message ne porte pas le nombre de lignes ; le conseil le porte
- [x] Une classe de `max` lignes exactement ne produit rien
- [x] Un fichier qui répond à un motif d'exclusion ne produit rien ; les deux motifs par défaut excluent entités et fixtures
- [x] Une classe anonyme n'est pas mesurée
- [x] Le docbloc compte, l'en-tête du fichier non
- [x] `resources/phpstan/class-length.neon` s'inclut seul et enregistre la règle ; `max` et `exclude` se règlent
- [x] README : section « PHPStan »

## Conséquences

- Les applications de l'écosystème refusent une classe trop longue nouvelle, au moment où on l'écrit ; les
  existantes sont listées, et chacune qui raccourcit sous le seuil quitte la liste.
- DevTools porte désormais une règle de qualité de code, au-delà de la cartographie. C'est un élargissement : la
  prochaine règle de ce genre se demande si elle a sa place ici, ou si l'écosystème a besoin d'un paquet de
  règles à lui.

## Dépendances

ADR-0048.
