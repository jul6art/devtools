# ADR-0049 — Le sommaire ment jusqu'à la prochaine inspection

- **Statut** : Accepted — 2026-10-20
- **Décideurs** : jul6art
- **Specs** : § 4.7 (menu)
- **Dépend de** : ADR-0011 (rédaction par Claude), ADR-0008 puis 0043 (rendu du menu) ; ADR-0015 pour
  les planchers de couverture que ce lot reprend (§ « Aussi dans ce lot »)

## Contexte

`workflows.md` est la page d'accueil de la documentation. Sa section « À vérifier » liste, entre
autres, les workflows dont un **brief est en attente** — c'est-à-dire ceux dont la page n'a pas
encore été rédigée (ADR-0011). Elle est rendue par `MenuRenderer`, appelé une seule fois dans tout
le code : à la fin de `InspectionPipeline::run`.

⚠️ **Or `workflows:apply` est précisément la commande qui fait disparaître ces briefs**, et elle ne
réécrit pas le sommaire. Elle rafraîchit pourtant déjà l'index (`DraftApplier::refreshIndex` écrit
`.devtools/index.xml` dès qu'un brouillon est accepté) : le fichier que lit la machine est à jour,
celui que lit l'humain ne l'est pas. L'asymétrie n'est écrite nulle part, elle est le résultat d'un
oubli.

Mesure, le 2026-09-19 sur **superp** : 951 workflows documentés en une campagne, 951 pages écrites
et appliquées, `workflows:check` vert — et un sommaire qui réclamait la rédaction des 951, une ligne
chacune, sur 952 lignes. Sur ce dépôt même, 7 lignes sur 14. Le fichier est faux **entre la fin
d'une campagne de rédaction et la prochaine inspection**, c'est-à-dire exactement au moment où on
l'ouvre pour constater qu'elle est finie.

Alternatives écartées : afficher « relancez `workflows:inspect` » à la fin d'`apply` (l'outil sait
ce qu'il faut faire, le dire au lieu de le faire est une charge de plus sur l'utilisateur) ;
rejouer une inspection complète depuis `apply` (elle reparcourt le code et rehache tout, pour
réécrire un fichier dont toutes les données sont déjà sur disque) ; ne rendre « À vérifier » qu'à
l'inspection et l'ôter du sommaire (c'est le garde-fou qui rend visible ce qui manque, § 4.7).

## Décision

### 1. `apply` réécrit le sommaire quand il a accepté au moins un brouillon

Au même endroit et à la même condition que `refreshIndex` : `[] !== $result->accepted`. Un
`apply` qui n'accepte rien ne touche à rien.

### 2. ⚠️ Il le reconstruit à partir du disque, il ne relance aucune analyse

Tout ce dont `MenuRenderer::render` a besoin est déjà écrit :

| Argument | D'où il vient dans `apply` |
|---|---|
| `$stacks` | `.devtools/stack.xml`, lu par `XmlStackStore` |
| `$index` | celui que `refreshIndex` vient d'écrire |
| `$pending` | les `pending/page.*.brief.xml` qui **restent** après l'application |
| `$customTypes` | la configuration, déjà lue par `apply` |
| `$docs` | `DevToolsDirectory`, déjà construit |

**Sauf `$uncovered`** : la liste des fichiers qu'aucun workflow ne référence est calculée par
`CoverageCalculator` pendant l'inspection et n'est persistée nulle part. `apply` **relit la section
« Non couvert » du sommaire existant et la recopie telle quelle**. Elle ne dépend d'aucun brouillon :
appliquer une page ne peut ni couvrir ni découvrir un fichier. Si le sommaire n'existe pas encore,
`apply` ne l'écrit pas — il n'y a rien à corriger avant la première inspection.

### 3. Aucun format ne change

Pas de nouveau fichier, pas de champ ajouté à l'index, pas de XSD touchée. C'est ce qui distingue
cette décision de l'alternative « persister la couverture », qui résoudrait le même problème au prix
d'un format de plus à tenir.

## Budget d'exécution

Une lecture de `stack.xml`, une lecture du sommaire existant, un `glob` sur `pending/`, une
écriture. Aucun parcours de code, aucun hachage, aucun processus git : le coût ne croît pas avec la
taille du projet. Sur superp (951 workflows), le sommaire fait 1 562 lignes avant correction et 612
après — l'ordre de grandeur est le fichier lui-même.

## Hors périmètre

- **Persister la couverture** pour que `apply` la recalcule au lieu de la recopier : c'est un format
  de plus, et le besoin ne s'est pas présenté.
- Le graphe d'ensemble (`workflows.mermaid`) et `files-to-workflows.xml` : appliquer une page ne
  change ni les dépendances ni les fichiers d'un workflow, seulement sa prose.
- `workflows:accept` et `workflows:reject` (ADR-0047), qui ne consomment pas de brief.
- Faire échouer quoi que ce soit sur un sommaire périmé.

## Aussi dans ce lot : les planchers de couverture de l'ADR-0015 (ajouté le 2026-10-01)

Le premier passage de la CI (2026-10-01, Actions coupées hors du passage mensuel) a **mesuré pour la
première fois** ce que l'ADR-0015 exige et qu'aucun poste ne pouvait mesurer, faute de pilote de
couverture. Deux défauts empêchaient même la mesure ; ils sont corrigés (`ea98f6f`) : le hash de
commit des fixtures dépendait de `config/reference.php`, et PHPUnit 13 ne découpe plus
`--exclude-group` sur la virgule — la couverture jouait aussi les tests de bout en bout.

| Plancher (`CoverageThresholds`) | Mesuré | Exigé |
|---|---|---|
| `src/` hors `Bridge/` | 82,64 % de 4 747 instructions | 90 % |
| `src/Inspection/Freshness/` | 88,79 % de 116 | 100 % |
| `src/Tracking/` | 79,17 % de 360 | 100 % |
| `src/Inspection/Diff/`, `src/Review/` | atteints | 100 % |
| Infection (`Freshness/`, `Tracking/`) | MSI du code couvert 72 % — 380 mutants tués, 146 non détectés | « fixé à la valeur atteinte » |

**Décision (jul6art, 2026-10-01) : on ajoute des tests ; les planchers ne baissent pas.** Les tests de
bout en bout n'y comptent pas, et c'est voulu : ils jouent `bin/devtools` dans un sous-processus, que
pcov ne mesure pas — le plancher se tient par des tests unitaires de ce qui décide.

⚠️ Le poste n'a toujours pas de pilote de couverture : `pecl install pcov` en local, ou la CI —
réactiver les Actions, `gh workflow run ci.yml -R jul6art/devtools`, recouper ensuite.

## Ce que la livraison a mesuré (2026-10-20)

Mesuré en local avec pcov (installé ce jour : `CPPFLAGS=-I$(brew --prefix pcre2)/include pecl install
pcov`, le dossier `pecl/20250925` de Homebrew créé à la main).

| Plancher | 2026-10-01 (CI) | 2026-10-20 | Exigé |
|---|---|---|---|
| `src/` hors `Bridge/` | 82,64 % | 93,57 % de 4 790 instructions | 90 % |
| `src/Inspection/Freshness/` | 88,79 % | 100 % de 116 | 100 % |
| `src/Tracking/` | 79,17 % | 100 % de 360 | 100 % |
| `src/Inspection/Diff/`, `src/Review/` | 100 % | 100 % | 100 % |
| Infection (`Freshness/`, `Tracking/`) | MSI 72 % | 654 mutants tués sur 677, MSI 96,6 % | `minCoveredMsi: 96` |

⚠️ **L'écart sur `src/` était surtout un défaut d'attribution, pas de code jamais exécuté.** PHPUnit 13
ne compte une ligne couverte que pour les classes que le test nomme en `#[CoversClass]` : mesurée sans
ce ciblage (`--disable-coverage-targeting`), la suite exécutait déjà 95,1 % de `src/`. Environ 390
instructions ont été regagnées en nommant, dans le test qui vérifie sa sortie, une classe qu'il
exerçait sans la nommer (`ChangeRenderer` dans le test de `workflows:diff`, l'applicateur et le
pipeline dans les tests de la voie Claude, des connaissances, de la fraîcheur et de la revue…) ;
environ 130 par des tests nouveaux (`GitClientTest`, `FreshnessResolverTest`, `DevToolsDirectoryTest`,
`SourceFilesTest`, l'aller-retour XML d'un suivi groupé à mécanismes et décisions…). La mesure du
plancher, elle, n'a pas changé : elle reste ciblée.

⚠️ **Un dépassement de temps n'est pas un mutant tué.** Lancé avec tous les fils du poste (17), Infection
annonçait 98 % : 68 mutants de `Freshness/` dépassaient les 10 s parce que leurs tests lancent de vrais
processus git sous une machine saturée, et Infection les compte comme détectés. Avec quatre fils, comme
sur un exécuteur GitHub, 47 d'entre eux survivaient. Ils sont tués par des tests unitaires qui
s'exécutent en millisecondes ; le MSI se mesure désormais avec quatre fils (`composer infection`).

### Les 23 mutants survivants, justifiés un par un

Tous équivalents : aucun ne change ce qu'un appelant peut observer.

| Fichier:ligne | Mutant | Pourquoi il est équivalent |
|---|---|---|
| `FreshnessResolver:75` | `UnwrapArrayValues` | `$common` ne sert qu'à `array_intersect` et `array_filter` ; `$changed` est réindexé ligne 77 |
| `FreshnessResolver:76` | `UnwrapArrayValues` | idem : `$worthHashing` ne passe que par `array_filter`, réindexé ligne 77 |
| `FreshnessResolver:77` | `UnwrapArrayValues` | `$changed` passe par `sort()` ligne 87, qui réindexe, avant de devenir une raison |
| `FreshnessResolver:82` | `UnwrapArrayValues` | idem, `sort()` ligne 87 |
| `FreshnessResolver:83` | `UnwrapArrayValues` | idem, `sort()` ligne 87 |
| `FreshnessResolver:130` | `$matches[0]` | le motif `(\d+)` est son propre groupe : la correspondance entière et le groupe 1 sont la même chaîne |
| `GitClient:25` | sans `rtrim` | `explode` ajoute des chaînes vides après les trois lignes ; les lignes 0 à 2 sont les mêmes, et un préfixe vide reste vide |
| `GitClient:27` | sans `^` | en cas de succès, `git rev-parse HEAD` écrit exactement un hash complet sur sa première ligne |
| `GitClient:27` | sans `$` | idem |
| `GitClient:27` | `\|\|` → `&&` | git réussit si et seulement si la première ligne est un hash : dans un dépôt sans commit, `rev-parse` échoue **et** écrit `HEAD` ; les deux formes rendent `null` dans tout état atteignable |
| `GitClient:31` | sans `trim` | la sortie est découpée sur `\n` après `rtrim` : la ligne de la branche ne porte aucun blanc |
| `GitClient:33` | sans `trim` | idem pour la ligne du préfixe |
| `GitClient:50` | `UnwrapArrayValues` | `array_filter` ne retire que la chaîne vide qui suit le dernier NUL : aucun trou ne peut précéder la fin |
| `GitClient:67` | sans `return []` | un `git status` en échec n'écrit rien sur la sortie standard : analyser `''` rend `[]`, comme le retour anticipé |
| `GitClient:77` | `continue` → `break` | une entrée de moins de quatre octets n'est jamais que la chaîne vide finale (l'entrée réelle la plus courte en fait quatre, testé) : les deux terminent la boucle au même endroit |
| `GitClient:91` | `===` → `!==` | `git status` tourne avec le pathspec `.` depuis le dossier du projet : chaque chemin qu'il écrit commence par le préfixe |
| `GitClient:91` | `&&` → `\|\|` | idem, et aucun chemin écrit n'est vide |
| `AtomicFileWriter:38` | `random_bytes(3)` | la longueur du suffixe aléatoire du fichier temporaire ne change aucun comportement |
| `AtomicFileWriter:38` | `random_bytes(5)` | idem |
| `Index:59` | `UnwrapArrayValues` | le constructeur fait passer les groupes par `SortedList::of`, qui réindexe |
| `Initializer:58` | sans `(string)` | gardé par `is_file()` : ne diffère que pour un `.gitignore` qui existe et ne se lit pas (voir ci-dessous) |
| `Initializer:61` | `UnwrapArrayValues` | `$missing` n'est comparé qu'à `[]` et parcouru par `implode(array_map())` : ses clés ne sont jamais lues |
| `VcsXml:22` | sans `(string)` | la branche n'est atteinte que si `isVersioned()`, c'est-à-dire `commit !== null` |

⚠️ **Trouvé en route, laissé hors périmètre** : un `.gitignore` présent mais illisible est lu comme
vide par `Initializer`, et sa réécriture **le remplace** par le seul bloc de DevTools — le mutant, lui,
échouerait sur une `TypeError`. Aucun des deux comportements n'est testé, et le bon est une décision
(refuser ? avertir ?) ; rien n'a été changé.

## Critères d'acceptation

**Planchers de l'ADR-0015 :**

- [x] `composer coverage` vert : `src/` ≥ 90 %, `Inspection/Freshness/` et `Tracking/` à 100 %, sans que
      `Inspection/Diff/` ni `Review/` quittent leurs 100 % — par des tests unitaires, aucun plancher abaissé
      *(93,57 %, 100 %, 100 %, 100 %, 100 % en local le 2026-10-20 ; voir ci-dessus pour la part
      d'attribution)*
- [x] Infection : chaque mutant survivant de `Freshness/` et `Tracking/` tué ou justifié un par un
      (critère de l'ADR-0010), puis `minCoveredMsi` fixé dans `infection.json5` à la valeur atteinte
      *(23 équivalents justifiés ci-dessus ; `minCoveredMsi: 96` pour 96,6 %)*
- [ ] Les jobs « Coverage floors » et « Mutation » verts en CI ; cochées, les deux cases ouvertes de
      l'ADR-0015 (seuils et MSI en CI, CI verte) et celle de l'ADR-0010 (couverture 100 % et mutants) ;
      la ligne de l'ADR-0015 dans `docs/adr/README.md` passée à livrée *(attend le commit, la poussée et
      un passage de la CI, coupée hors du passage mensuel ; la case de l'ADR-0010, qui ne demande pas la
      CI, est cochée)*

**Sommaire :**

- [x] Après `apply` d'un brouillon, `workflows.md` ne liste plus ce workflow en « rédaction en
      attente » — test sur un projet-fixture à deux workflows, un appliqué, un non
      *(`MenuAfterApplyTest::testAnAppliedDraftLeavesTheListOfWhatIsWaitingAndTheOtherStays`)*
- [x] Le workflow dont le brief **reste** y figure toujours : `apply` ne blanchit pas ce qui n'a pas
      été rédigé *(même test)*
- [x] La section « Non couvert » est identique, octet pour octet, avant et après `apply`
      *(`testTheNotCoveredSectionIsCopiedByteForByte` ; la section se lit aussi sous son ancien titre
      français)*
- [x] Un `apply` sans brouillon accepté ne modifie pas `workflows.md` — arborescence comparée
      *(`testAnApplyThatAcceptsNothingChangesNoFile`, sommaire retouché à la main d'abord : sans cela, le
      réécrire à l'identique ne se voyait pas, et le mutant survivait)*
- [x] Un projet sans `workflows.md` : `apply` n'en crée pas *(`testAProjectWithoutAMenuGetsNone`)*
- [x] `apply` ne lance ni parcours de fichiers ni processus git — compteurs du test
      *(⚠️ écart : l'applicateur n'a ni client git ni hacheur dont un test pourrait compter les appels.
      `testTheMenuIsRebuiltFromDiskWithoutAnalysingAnything` supprime le code du projet avant `apply` :
      le sommaire reconstruit ne diffère de l'ancien que par la ligne du workflow rédigé — une analyse
      aurait échoué, ou rendu un autre sommaire)*
- [x] Une inspection lancée juste après un `apply` produit un sommaire **identique** à celui
      qu'`apply` a écrit : les deux chemins ne peuvent pas diverger
      *(`testAnInspectionRightAfterApplyRendersTheSameMenu` ; éprouvé aussi sur ce dépôt : 14 pages
      appliquées, « To check » vide, puis une inspection — 14 inchangés, sommaire identique à l'octet)*
- [x] README : la section de `workflows:apply` dit qu'il tient le sommaire à jour

Chaque garde du sommaire est vérifiée par mutation : sans réécriture, sans recopie de « Non couvert »,
sans les briefs restants, avec création d'un sommaire absent, avec réécriture sans brouillon accepté, ou
sans relecture du titre anglais — chaque fois, le test qui la vise passe au rouge.

## Conséquences

- La fin d'une campagne de rédaction se lit dans le fichier prévu pour ça, au lieu de demander une
  inspection dont l'utilisateur ne peut pas deviner qu'elle est nécessaire.
- `MenuRenderer` acquiert un second appelant. Le dernier critère d'acceptation existe pour ça : le
  jour où le rendu du menu changera, les deux chemins devront bouger ensemble, et un test le dira.
- La recopie de « Non couvert » est une dette nommée : elle est correcte tant qu'`apply` ne touche
  pas aux fichiers d'un workflow. Le jour où ce ne serait plus vrai, il faudra persister la
  couverture — et ce paragraphe dit pourquoi.

## Dépendances

ADR-0011, ADR-0043.
