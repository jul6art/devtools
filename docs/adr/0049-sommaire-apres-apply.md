# ADR-0049 — Le sommaire ment jusqu'à la prochaine inspection

- **Statut** : Proposed
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

## Critères d'acceptation

**Planchers de l'ADR-0015 :**

- [ ] `composer coverage` vert : `src/` ≥ 90 %, `Inspection/Freshness/` et `Tracking/` à 100 %, sans que
      `Inspection/Diff/` ni `Review/` quittent leurs 100 % — par des tests unitaires, aucun plancher abaissé
- [ ] Infection : chaque mutant survivant de `Freshness/` et `Tracking/` tué ou justifié un par un
      (critère de l'ADR-0010), puis `minCoveredMsi` fixé dans `infection.json5` à la valeur atteinte
- [ ] Les jobs « Coverage floors » et « Mutation » verts en CI ; cochées, les deux cases ouvertes de
      l'ADR-0015 (seuils et MSI en CI, CI verte) et celle de l'ADR-0010 (couverture 100 % et mutants) ;
      la ligne de l'ADR-0015 dans `docs/adr/README.md` passée à livrée

**Sommaire :**

- [ ] Après `apply` d'un brouillon, `workflows.md` ne liste plus ce workflow en « rédaction en
      attente » — test sur un projet-fixture à deux workflows, un appliqué, un non
- [ ] Le workflow dont le brief **reste** y figure toujours : `apply` ne blanchit pas ce qui n'a pas
      été rédigé
- [ ] La section « Non couvert » est identique, octet pour octet, avant et après `apply`
- [ ] Un `apply` sans brouillon accepté ne modifie pas `workflows.md` — arborescence comparée
- [ ] Un projet sans `workflows.md` : `apply` n'en crée pas
- [ ] `apply` ne lance ni parcours de fichiers ni processus git — compteurs du test
- [ ] Une inspection lancée juste après un `apply` produit un sommaire **identique** à celui
      qu'`apply` a écrit : les deux chemins ne peuvent pas diverger
- [ ] README : la section de `workflows:apply` dit qu'il tient le sommaire à jour

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
