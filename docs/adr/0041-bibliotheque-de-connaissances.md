# ADR-0041 — Bibliothèque de connaissances partagée

- **Statut** : Accepted — 2026-09-18
- **Décideurs** : jul6art
- **Specs** : § 4.3 étape 2, § 8 (stacks couvertes), § 12 question 3
- **Remplace** : ADR-0012, passée `Superseded by ADR-0041`

## Contexte

L'ADR-0012 résout une connaissance de stack dans l'ordre projet → embarquée → tâche `knowledge`, et
met **hors périmètre** la remontée d'une fiche produite (§ 12 question 3 : « remontée par pull
request manuelle »). Conséquence observée : la fiche `angular-18`, rédigée pendant l'ADR-0013, est
restée dans son projet-fixture. Le prochain projet Angular la redemandera à Claude en entier —
recherche documentaire comprise — alors qu'elle est générique par stack et version majeure, donc
partageable par construction (ADR-0012, Contexte).

Le coût de cet oubli croît avec le nombre de projets analysés, et il croît dans le mauvais sens :
plus DevTools sert, plus il redemande ce qu'il sait déjà. Alternatives écartées : une PR ouverte
automatiquement par l'outil (DevTools ne parle à aucun service réseau) ; un dépôt de fiches
distant (une dépendance réseau dans un outil qui n'en a aucune).

## Décision

### Une quatrième couche : la bibliothèque

`Stack\Knowledge\KnowledgeProvider` résout dans l'ordre :

```
1. .devtools/knowledge/<clé>.md     projet     — prioritaire, versionné, jamais écrasé   (inchangé)
2. <bibliothèque>/<clé>.md          partagée   — ÉCRITE automatiquement                  ← nouveau
3. resources/knowledge/<clé>.md     embarquée  — livrée avec le paquet                   (inchangé)
4. sinon                            tâche `knowledge` pour Claude                        (inchangé)
```

Les couches 2 et 3 se **copient** dans `.devtools/knowledge/` à la première utilisation, comme la
couche 3 le fait déjà : le projet committe ce dont il s'est servi (§ 4.3).

### Où vit la bibliothèque

`Stack\Knowledge\KnowledgeLibrary::locate()`, dans l'ordre :

1. la variable d'environnement `DEVTOOLS_KNOWLEDGE_HOME` ;
2. `<knowledge library="…"/>` de `.devtools/config.xml` (chemin relatif au projet ou absolu) ;
3. **`resources/knowledge/` du paquet, quand il est accessible en écriture** — le cas d'une copie
   source utilisée comme outil autonome, qui est l'usage réel : le dossier grandit ;
4. `$XDG_DATA_HOME/devtools/knowledge`, à défaut `~/.devtools/knowledge` — le cas d'une
   installation en `vendor/`, qu'on n'écrit jamais.

Le chemin retenu s'affiche dans le rapport d'inspection et dans `knowledge:list` : une bibliothèque
qui change d'endroit sans le dire serait pire que pas de bibliothèque.

### Ce qui y entre, et à quelles conditions

Le dépôt a lieu dans `workflows:apply`, quand un brouillon `knowledge` est **accepté** :

- la fiche passe `KnowledgeCanvas::problems()` — la même validation que le fichier projet, donc la
  même que le test de conformité qui couvre tout `resources/knowledge/` ;
- le fichier n'existe pas déjà dans la bibliothèque — **jamais d'écrasement**, dans aucun cas ;
- le dossier est accessible en écriture ; sinon la fiche reste dans le projet et un avertissement le
  dit, sans faire échouer la commande.

Chaque dépôt écrit une ligne dans `<bibliothèque>/library.xml` (clé, projet d'origine, date,
version de l'outil, `sha256` de la fiche), validé par `resources/schemas/knowledge-library.xsd` :
ce qu'un index affiche se lit dans un XML, jamais en relisant les fiches (journal 2026-09-16).

Sortie : `<knowledge share="false"/>` dans `config.xml`, et `--no-share` sur `workflows:apply`.

### Deux commandes

- `devtools knowledge:list` — les fiches de la bibliothèque, leur origine et leur date, et
  les fiches embarquées qu'elle ne couvre pas.
- `devtools knowledge:promote <clé>` — copie une fiche de la bibliothèque (ou du projet, avec
  `--from`) dans `resources/knowledge/` d'une copie source, pour l'embarquer dans le paquet publié.
  Refuse si la copie n'est pas une copie source, si la fiche existe déjà, ou si elle ne passe pas le
  canevas.

## Budget d'exécution

Une lecture de dossier et une lecture de `library.xml` par inspection, quel que soit le nombre de
fiches. Une écriture par fiche **nouvelle**, une fois dans la vie d'une stack et d'une version
majeure — zéro ensuite, comme l'ADR-0012. Aucun processus, aucun accès réseau.

## Hors périmètre

L'**amendement** d'une fiche existante quand un projet exhibe un mécanisme qu'elle ne couvre pas
(tâche `knowledge:amend`) — c'est le second étage de « une base qui grandit », il aura son ADR ;
l'ouverture automatique d'une pull request ; toute synchronisation réseau ou entre machines ; la
mise à jour d'une fiche au changement de version mineure (déjà hors périmètre en ADR-0012) ; la
péremption d'une fiche ancienne.

## Critères d'acceptation

- [x] Résolution à quatre couches : un test par couche, et un test qui prouve que la couche projet
      gagne sur une bibliothèque qui contient la même clé
- [x] `locate()` : un test par cas des quatre (env, config, paquet accessible en écriture, repli
      `~/.devtools`), le cas « paquet en lecture seule » vérifié par mutation des droits
- [x] Un brouillon accepté est déposé, `library.xml` gagne sa ligne, et un second `apply` sur la
      même clé **n'écrase pas** le fichier déposé (mutant : retirer la garde → test rouge)
- [x] Un brouillon refusé par le canevas n'est déposé nulle part
- [x] `<knowledge share="false"/>` et `--no-share` : rien n'est déposé, la fiche projet est écrite
- [x] Une bibliothèque en lecture seule produit un avertissement, pas une erreur : `exitCode()`
      reste celui des avertissements
- [x] Une fiche déposée dans `resources/knowledge/` passe le test de conformité au canevas déjà en
      place, sans modification de ce test
- [x] `knowledge:list` et `knowledge:promote` : un test de sortie chacun, plus le refus de `promote`
      hors copie source
- [x] **Toute la suite pose `DEVTOOLS_KNOWLEDGE_HOME` sur un dossier temporaire** : aucun test
      n'écrit dans `resources/knowledge/` de ce dépôt, vérifié par un test qui hache le dossier
      avant et après la suite

## Conséquences

- Le dossier `resources/knowledge/` devient **écrit par l'outil** sur une copie source : une
  inspection peut salir le dépôt. Le dépôt s'annonce en console (`fiche angular-18 ajoutée à la
  bibliothèque — à committer`) et jamais en silence.
- Une fiche fausse déposée se propage à tous les projets suivants. Elle se corrige à un seul
  endroit, et la correction du fichier projet ne remonte pas : c'est `knowledge:promote` qui décide.
- L'ADR-0012 passe `Superseded by ADR-0041` ; sa décision de résolution est réénoncée ici en entier.

## Dépendances

ADR-0012, ADR-0013.
