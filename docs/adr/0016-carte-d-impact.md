# ADR-0016 — Carte d'impact : `workflows:impact`

- **Statut** : Accepted — 2026-10-20 (proposée le 2026-09-16 ; phase 1 lancée par jul6art) · Livré le 2026-10-20
- **Décideurs** : jul6art
- **Specs** : § 4.8 (carte d'impact), § 5 (socle des modules ultérieurs), § 9 (phase 1)

## Contexte

« Qu'est-ce que ce changement touche ? » est la question que ni Claude ni l'humain ne savaient
trancher (§ 1.3). Le MVP écrit la réponse dans `graph/files-to-workflows.xml` ; il faut maintenant
**la servir** : à Claude Code avant qu'il modifie un fichier (ADR-0019), à la gate pour choisir les
scénarios à rejouer (ADR-0023), à l'humain en revue de pull request.

## Décision

### Commande

```
devtools workflows:impact <fichier>...            un ou plusieurs chemins
devtools workflows:impact --commit=<ref>          fichiers d'un commit
devtools workflows:impact --range=<a>..<b>        fichiers d'une plage (revue de PR)
devtools workflows:impact --changed               working tree : modifiés, indexés, non suivis
        [--format=text|json|xml] [--depth=0|1]
```

### Calcul (`Impact\ImpactResolver`)

1. Fichiers en entrée normalisés par `ProjectRoot` (ADR-0004) ; un chemin hors racine est refusé.
2. Lecture de `graph/files-to-workflows.xml` (**jamais** de re-scan) :
   - `relation="file"` → impact **direct** ;
   - `relation="test"` → impact **par test** (le test couvre ce workflow).
3. `--depth=1` (défaut) ajoute les workflows qui ont un workflow direct dans leur `dependsOn`
   (un listener global modifié impacte toutes les routes, § 11) ; `--depth=0` s'en abstient.
4. Un fichier absent de l'index → **« non couvert »** : listé à part, jamais ignoré. Un fichier
   source nouveau est le cas typique ; la sortie le dit et suggère `workflows:inspect`.
5. Sortie triée par identifiant : `id`, `type`, `relation` (`direct|test|dependency`), fichiers
   déclencheurs, chemin de la page, statut de fraîcheur (lu dans `index.xml`).

Le format `json` existe ici parce que la sortie est destinée à des outils (hooks Claude Code, CI) ;
il est décrit par un exemple dans le README, et figé par un snapshot de test.

### Avertissement de fraîcheur

Si `index.xml` est plus ancien que le dernier commit touchant un fichier source, la sortie commence
par un avertissement : l'impact est calculé sur une carte **potentiellement périmée**.

### Précisions à l'acceptation (2026-10-20)

Le MVP a changé depuis la rédaction : les listeners sont devenus des mécanismes (ADR-0043), les voters
aussi (ADR-0044), et ni les uns ni les autres ne sont des workflows.

- **La relation `dependency` est précalculée dans `graph/files-to-workflows.xml`** (troisième valeur de
  `relation`, après `file` et `test`) : le fichier qui déclare un mécanisme est lié à chaque workflow qui
  le porte, et les fichiers d'un workflow à chaque workflow qui en dépend (`dependsOn`). L'impact ne lit
  ainsi que deux XML, quel que soit `--depth` : `--depth=0` écarte simplement ces liens.
- **La carte est dite périmée quand un fichier interrogé a été modifié après la dernière inspection** —
  date du dernier rapport d'inspection, à défaut celle de `index.xml`. Comparer au dernier commit
  coûterait un processus git de plus, et un fichier modifié sans commit est précisément le cas d'une
  session Claude Code.
- **Le projet se désigne par `--path`** : les arguments de la commande sont les fichiers.
- La sortie `xml` suit `impact.xsd`, validée à l'écriture comme tout XML de DevTools.
- **Infection** (`Freshness/` et `Tracking/`, que ce lot touche) : le découpage de `GitClient` déplace des
  survivants que l'ADR-0049 justifie — `continue` → `break` passe à la ligne 161, les deux mutants du préfixe à
  la ligne 175 (survivants, ou hors délai selon la charge de la machine) ; celui du `return []` anticipé
  disparaît avec le code qu'il mutait — et en ajoute trois, équivalents : `GitClient:140` (`UnwrapArrayValues` :
  le `sort()` qui suit réindexe), `GitClient:141` (sans `sort()` : git écrit les chemins de `diff-tree` et de
  `diff --name-only` dans l'ordre de l'index, déjà trié, et chaque appelant retrie), `FilesToWorkflows:72`
  (`UnwrapArrayMap` : le constructeur trie chaque liste par `usort`, qui réindexe). `FilesToWorkflows:54` est
  tué par un test : un fichier à la fois parcouru et listé en test reste un fichier du workflow. Bilan au
  2026-10-20 : 699 mutants tués, 25 survivants tous justifiés, MSI couvert 96,5 % (plancher 96).

## Budget d'exécution

Lecture de deux fichiers XML ; **aucun parsing de code, aucun hash**. `--commit`, `--range`,
`--changed` : **un** processus git. Cible : < 200 ms sur un projet de 300 workflows — c'est ce qui
permet de l'appeler à chaque modification de fichier depuis un hook (ADR-0019).

## Hors périmètre

Impact au niveau de la méthode ou de la ligne ; impact transitif au-delà d'un saut ; re-scan
automatique quand la carte est périmée.

## Critères d'acceptation

- [x] Sur `symfony-minimal` inspecté : un service partagé → ses deux routes (direct) ; un listener
      `kernel.request` → toutes les routes (dependency) ; un test → le workflow qu'il couvre (test)
      *(`ImpactTest` : `OrderRepository` atteint ses quatre routes, le gestionnaire d'`OrderCreated` et
      le composant du panier ; `LocaleListener` toutes les routes en `dependency`, aucune à `--depth=0`)*
- [x] Fichier inconnu → section « non couvert », code de sortie 0
- [x] `--commit`, `--range`, `--changed` dans un dépôt git temporaire : un test chacun, un seul
      processus git (compteur)
- [x] Chemin hors racine refusé *(code 2, avant toute lecture)*
- [x] Sorties `text`, `json`, `xml` figées en snapshot *(`tests/Fixtures/snapshots/impact.*`)*
- [x] Carte périmée → avertissement en tête *(fichier modifié après l'inspection, ou disparu)*
- [x] < 200 ms mesurés sur le projet généré de 300 routes (ADR-0015) *(5 ms pour un service que les
      300 routes traversent)*
- [x] README : section « Impact d'un changement » *(« Impact of a change »)*

## Conséquences

- La carte devient une dépendance de travail quotidien : sa fraîcheur (ADR-0017) cesse d'être un
  confort.

## Dépendances

ADR-0015 (MVP livré).
