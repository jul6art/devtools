# ADR-0017 — Contrôle de fraîcheur en CI et hooks git

- **Statut** : Accepted — 2026-09-18 (réécrite : la version `Proposed` du 2026-09-16 précédait le diff typé)
- **Décideurs** : jul6art
- **Specs** : § 4.8 (contrôle de fraîcheur en CI, hook `pre-commit` / `post-merge`), § 9 (phase 1), § 11
- **Dépend de** : ADR-0046 (diff typé), qui lui donne ce qu'elle a à dire

## Contexte

Une documentation qui peut dériver silencieusement finit par dériver. Le MVP sait **décider** ce qui est
périmé (ADR-0010) et, depuis l'ADR-0046, **dire quel fait a changé** ; rien n'oblige encore à agir.

La version `Proposed` de cette ADR décrivait un `workflows:check` qui n'aurait fait que répéter la
fraîcheur : « ce workflow est `stale` ». L'usage des trois projets documentés le 2026-09-18 a montré ce
qui manque vraiment. Leur gate lançait :

```shell
$DEVTOOLS workflows:inspect --no-ai --locale=fr
if [ -n "$(git status --porcelain docs/workflows .devtools)" ]; then …
```

Deux défauts, tous deux vérifiés sur cereezer :

1. **Elle régénère avant de dire quoi que ce soit.** Au moment où l'humain lit l'avertissement, les pages
   ont déjà été réécrites : il ne peut plus comparer, seulement relire 127 fichiers modifiés.
2. **Elle échoue sur du bruit.** Une date de régénération, un `sha256`, un commit dans un suivi suffisent
   à rendre la gate rouge alors qu'aucun fait n'a bougé.

## Décision

### 1. `devtools workflows:check [path]` — la fraîcheur ET les faits

Une seule commande de contrôle, en **lecture seule**, dont l'échec se lit :

```
 ✖ 2 workflows à documenter · 1 fait changé

 route.order.export                        non documenté
 route.order.legacy                        orphelin
 modifié attribut app_order_new#security   ROLE_USER → ROLE_ADMIN   3 workflows
```

| Cause d'échec | Sortie |
|---|---|
| un point d'entrée sans page (`create`) | `non documenté` |
| une page dont le workflow a disparu (`orphan`) | `orphelin` |
| un fait changé depuis la dernière écriture (ADR-0046) | le fait, groupé, avec sa portée |
| `--require-ai` : une page jamais rédigée (`mode="no-ai"`) | `jamais rédigé` |

⚠️ **Un fichier modifié sans fait changé n'est PAS une cause d'échec.** C'est la différence avec la
fraîcheur, et elle est délibérée : la gate ne réveille personne pour un commentaire ajouté. La prose peut
devenir fausse sans qu'un fait bouge — c'est l'affaire de la revue (ADR-0047), pas d'une gate.

Codes de sortie : `0` rien à signaler ; `1` au moins une cause ; `2` le projet n'a pas pu être inspecté.

### 2. `--format=github`

Des annotations `::error file=…,line=…::` sur le fichier qui porte le fait, pour que la pull request
montre la ligne en cause. Un fait sans fichier — une dépendance, un paquet — s'annote sans ligne.

### 3. `devtools git:install-hooks [path]`

| Hook | Ce qu'il fait |
|---|---|
| `pre-commit` | `workflows:check`, **avertit sans bloquer** — sauf `--strict`, qui refuse le commit |
| `post-merge`, `post-checkout` | `workflows:inspect --no-ai` : les faits suivent la branche, la rédaction reste différée |

- Installés dans `.git/hooks/`, ou dans `core.hooksPath` s'il est configuré. **Un hook existant n'est
  jamais écrasé** : le bloc DevTools est ajouté entre deux marqueurs, et `git:uninstall-hooks` retire
  exactement ce bloc, à l'octet.
- ⚠️ **Un hook qui échoue pour une raison technique n'empêche jamais un commit** : DevTools absent, PHP
  absent, projet sans `.devtools/` — il le dit et sort à 0. Une gate qui bloque le travail à cause
  d'elle-même est une gate qu'on désinstalle.

### 4. Ce que la gate d'un projet devient

```shell
$DEVTOOLS workflows:check          # ⇢ ce qui a changé, et rien n'est réécrit
```

La régénération redevient un geste délibéré (`workflows:inspect`), suivi d'une relecture.

## Budget d'exécution

`check` : le coût d'un `inspect --dry-run` (ADR-0010 : < 10 s pour 300 routes ; 5 s mesurées sur
cereezer, 259 workflows). Aucune écriture, donc aucun verrou.

## Hors périmètre

- Mise à jour automatique commitée par la CI ; rédaction IA déclenchée par un hook.
- Hooks d'autres gestionnaires (Husky, GrumPHP, CaptainHook) : un exemple de configuration suffit.
- L'acceptation d'un changement : ADR-0047.

## Critères d'acceptation

- [x] `check` : 0 sur un projet frais ; 1 pour un point d'entrée non documenté, pour un orphelin, pour
      un fait changé ; chaque cause nommée dans la sortie
- [x] Un fichier modifié sans fait changé : **0**, avec la ligne qui le dit
- [x] `--require-ai` : 1 si une page n'a jamais été rédigée
- [x] `--format=github` : annotations conformes, fichier et ligne compris (snapshot)
- [x] `check` n'écrit aucun fichier : arborescence comparée
- [x] `git:install-hooks` chaîne un hook existant, `git:uninstall-hooks` le restaure à l'octet
- [x] `core.hooksPath` respecté quand il est configuré
- [x] Hook sans DevTools installé : message, sortie 0, commit effectué
- [x] README : sections « Contrôle en CI » et « Hooks git »

## Conséquences

- Une pull request qui change un comportement sans que la documentation suive devient visible en CI, et
  **ce qu'elle montre est le fait**, pas un nom de fichier.
- La gate des projets consommateurs change de commande : c'est un changement à porter chez eux, et il
  supprime une régénération par passe.

## Dépendances

ADR-0015 (MVP livré), ADR-0046 (diff typé).
