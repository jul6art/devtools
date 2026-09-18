# ADR-0045 — Le contrôleur est un niveau de menu, pas un workflow

- **Statut** : Accepted — 2026-09-18
- **Décideurs** : jul6art
- **Specs** : § 4.3 étape 4, § 4.7
- **Amende** : ADR-0006 (regroupement), ADR-0043 (gabarit de page)

## Contexte

`<routes group="controller"/>` fait aujourd'hui d'un contrôleur **un workflow** : ses routes
deviennent des satellites, et la ressource porte une page unique. L'ADR-0006 l'a introduit pour une
bonne raison — 233 pages de back-office disant toutes « applique une transition » ne valent pas une
page portant la machine à états.

L'usage l'a démenti sur la moitié de son promesse. Trois générations réelles :

| Projet | Routes | Pages en mode `controller` |
|---|---|---|
| cereezer | 244 | 50 |
| cegeta | 99 | 33 |
| devinlive | 89 | 23 |

**Une page de contrôleur à une route se lit ; une page de contrôleur à treize routes ne se lit
pas.** `docs/workflows/routes/home.md` de devinlive (une route) porte un `sequenceDiagram` qu'on
suit du regard. `docs/workflows/routes/admin.user.md` de cereezer (treize routes) porte :

- un « Déclencheur » de quatorze lignes de table, une par satellite ;
- **un seul** diagramme « Parcours » qui doit raconter treize gestes différents — liste, création,
  activation, suppression douce, restauration, export, quatre actions de masse — donc un diagramme
  que personne ne lit en entier ;
- une section « Décisions » qui agrège les champs décidés par les treize routes, si bien que le
  lecteur venu pour « pourquoi ce compte est-il inactif » traverse les diagrammes de l'export CSV.

Le problème que l'ADR-0006 voulait résoudre était celui du **menu** : 233 entrées sous une seule
section est une liste que personne ne parcourt. Ce n'était pas celui de la **page**. Le remède a
déplacé le défaut : au lieu de 233 pages pauvres, 50 pages illisibles.

Alternatives écartées : garder le regroupement et scinder « Parcours » en un diagramme par route
(la page grossit sans que rien ne la structure, et les huit autres sections restent agrégées) ;
plier chaque route derrière un `<details>` (le poids reste, et un contenu replié n'est pas
indexable) ; laisser `entry-point` faire foi et abandonner le regroupement (le menu redevient
illisible, ce que l'ADR-0006 a justement corrigé).

## Décision

### 1. Le regroupement est une décision de PRÉSENTATION

`<routes group="controller"/>` **ne change plus la construction des workflows** : une route est un
workflow, exactement comme en `entry-point`, avec l'identifiant que la dérivation de l'ADR-0003
produit déjà. Le contrôleur devient un **groupe** : un dossier, une page d'index, une entrée de
menu. Il n'est pas un workflow, n'a pas d'identifiant de workflow, et ne compte pas dans le nombre
de workflows que le menu et la console annoncent.

Un groupe porte : un **dossier** (dérivé comme l'était l'identifiant de la ressource — ce que les
noms de route ont en commun, le nom du contrôleur à défaut), un **titre** (le chemin commun des
routes), le **fichier** qui le déclare, et la **machine à états** quand une de ses routes en porte
une.

### 2. Une arborescence, deux niveaux

```
docs/workflows/routes/
├── admin.user/
│   ├── README.md        ← la page du groupe : les 13 routes, en table
│   ├── index.md         ← route.admin.user.index
│   ├── edit.md          ← route.admin.user.edit
│   └── bulk-delete.md   ← route.admin.user.bulk_delete
├── home/
│   ├── README.md        ← le groupe, même à une seule route
│   └── index.md         ← route.home
└── workflows.md
```

- le fichier d'une route est composé des segments de son identifiant **au-delà** de ceux du groupe,
  joints par `.` ; quand il n'en reste aucun — un contrôleur à une seule route, dont l'identifiant
  est celui du groupe — le fichier est `index.md` ;
- la page du groupe est **`README.md`**, et non `index.md`, pour deux raisons : `route.<x>.index`
  existe dans presque tous les contrôleurs de liste, la collision serait la règle ; et une forge
  affiche le `README.md` d'un dossier quand on l'ouvre, donc `routes/admin.user/` rend la table des
  routes sans qu'on ait à cliquer.

⚠️ Les **fichiers de suivi ne bougent pas** : `.devtools/workflows/<type>/<id>.xml`, à plat. Ils
sont lus par la fraîcheur, pas par un humain, et les imbriquer n'apporterait rien.

### 3. La page du groupe : les faits, plus trois phrases

| Section | Propriétaire | Sans IA (`--no-ai`) |
|---|---|---|
| Résumé | Claude | `—` |
| Routes | DevTools | la table : chemin, méthodes, sécurité, lien vers la page, dernière MAJ |
| États | DevTools | le `stateDiagram`, quand une route du groupe en porte un |

Aucune autre section : ce qui décrit un geste appartient à la page du geste. Le brief du groupe
porte le prompt `group/v1` et **une seule** section rédigée, bornée à cinq phrases — un index qui
explique autant qu'une page n'oriente plus.

### 4. Le menu liste les groupes

La section « Routes » de `workflows.md` liste les **groupes** — libellé, nombre de routes, lien vers
le `README.md` — et non plus les workflows. Le troisième niveau par famille (ADR-0043) s'applique
aux groupes. Les autres types (commandes, async, données, types personnalisés) ne sont pas
regroupés et ne changent pas.

Un projet en `entry-point` — le défaut — ne change ni d'arborescence ni de menu.

## Budget d'exécution

- **La première régénération d'un projet en mode `controller` est complète** : cereezer passe de 50
  à 244 pages à rédiger, cegeta de 33 à 99, devinlive de 23 à 89. La fraîcheur (ADR-0010) borne le
  coût **courant** : une route modifiée réécrit sa page, pas celles de son contrôleur.
- La page du groupe se réécrit quand la **liste** de ses routes change, ou quand le fichier qui le
  déclare change — jamais parce qu'une route a changé de contenu.
- Aucun appel console ni aucune requête git de plus : le groupe est dérivé de candidats déjà lus.

## Hors périmètre

- Le mode `entry-point`, inchangé.
- Le regroupement des commandes, des handlers et des types personnalisés : un groupe de routes se
  déduit du fichier déclarant, ce qui n'a pas d'équivalent ailleurs.
- `<groups>` de `config.xml` (ADR-0006), qui reste un regroupement de **workflows** — un satellite
  déclaré à la main reste un satellite.

## Critères d'acceptation

- [x] En mode `controller`, un contrôleur à N routes produit **N workflows**, un par route, avec les
      identifiants qu'`entry-point` produit — comparés par un test qui construit les deux modes sur
      les mêmes candidats
- [x] Le nombre de workflows annoncé par la console et le menu ne compte aucun groupe
- [x] Les pages sont écrites dans `routes/<groupe>/`, la page du groupe est `README.md`, et une route
      dont l'identifiant est celui du groupe est `index.md`
- [x] Une route nommée `route.<x>.index` et la page de son groupe cohabitent sans collision — test
      dédié, sur un contrôleur de liste
- [x] La page du groupe porte exactement trois sections, dans l'ordre ; `--no-ai` laisse « Résumé »
      à `—` et remplit les deux autres
- [x] Un brouillon de groupe qui ajoute une section, ou qui cite une route absente du modèle, est
      refusé par `PageDraftValidator`
- [x] Le menu liste les groupes et leur nombre de routes ; un projet en `entry-point` rend le même
      menu qu'avant (snapshot inchangé)
- [x] Deux inspections consécutives sans changement : aucun fichier modifié hors `reports/`
- [x] Les XSD `workflow-tracking` et `index` compilent, portent le groupe, et un document écrit par
      la version précédente est relu sans groupe plutôt que refusé
- [x] Les pages de l'ancien mode (`routes/admin.user.md`) passent `orphaned` et se suppriment au
      `--prune` — et la page d'un groupe vidé de ses routes avec elles
- [x] `workflows:apply` écrit la page **là où le brief la déclare**, donc dans le dossier du groupe — le
      modèle sérialisé ne porte pas le groupe, et recalculer le chemin depuis lui renvoyait chaque page
      rédigée au chemin plat, à côté de la page que le lecteur ouvre
- [x] Un lien d'une page groupée vers une autre page est relatif au **dossier où la page vit** : voisine
      dans le même contrôleur, `../<groupe>/<page>.md` ailleurs, `../../<type>/<page>.md` dans un autre type
- [x] La matrice de bout en bout rejouée sur les projets-fixtures, snapshots régénérés — `symfony-minimal`
      y est joué **deux fois**, à plat et groupé par contrôleur, avec les mêmes brouillons enregistrés :
      les identifiants de workflow ne changent pas, seule la mise en page le fait

## Conséquences

- **Changement cassant pour un projet en mode `controller`.** Ses pages actuelles deviennent
  orphelines, ses identifiants de workflow changent (`route.admin.user` disparaît au profit de
  `route.admin.user.index`, `route.admin.user.edit`…), et sa documentation se régénère en entier.
  Les trois projets concernés — cereezer, cegeta, devinlive — sont à régénérer avec `--prune`, puis
  à rédiger.
- Le coût de rédaction d'une première passe est multiplié par quatre à cinq sur ces projets. C'est
  le prix de la lisibilité, et il est payé une fois.
- Ce que la page de ressource portait et que plus rien ne porte — un diagramme unique racontant la
  vie d'une ressource — se retrouve dans la machine à états de la page du groupe, quand il y en a
  une, et nulle part sinon. C'est assumé : un diagramme qui raconte treize gestes n'en racontait
  aucun.
- `WorkflowBuilder::resource()` n'est plus une fabrique d'entrée synthétique mais une dérivation de
  groupe : le nom, le chemin commun et les états restent calculés de la même façon.

## Dépendances

ADR-0003 (identifiants), ADR-0006 (regroupement, amendée ici), ADR-0010 (fraîcheur), ADR-0011
(rédaction), ADR-0043 (gabarit de page, amendée ici : la page de groupe est un second gabarit).
