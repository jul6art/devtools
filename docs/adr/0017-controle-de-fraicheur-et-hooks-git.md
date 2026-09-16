# ADR-0017 — Contrôle de fraîcheur en CI et hooks git

- **Statut** : Proposed — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 4.8 (contrôle de fraîcheur en CI, hook `pre-commit` / `post-merge`), § 9 (phase 1), § 11

## Contexte

Une documentation qui peut dériver silencieusement finit par dériver. Le MVP sait **décider** ce qui
est périmé (ADR-0010) ; rien n'oblige encore à agir. Le § 4.8 propose deux garde-fous : un contrôle
qui échoue en CI, et des hooks git qui gardent le suivi à jour sans y penser.

## Décision

### `devtools workflows:check [path]`

- Exécute la résolution de fraîcheur **en lecture seule** (le `--dry-run` de l'ADR-0010, sans
  rapport écrit).
- Codes de sortie : `0` tout est frais ; `1` au moins un workflow `stale`, `orphaned`, `create`
  (point d'entrée non documenté) ou `manual-stale` ; `2` erreur.
- `--require-ai` : échoue aussi sur un workflow jamais rédigé (`mode="no-ai"`) — pour les projets
  qui exigent une documentation rédigée.
- `--format=github` : annotations `::error file=…::` pointant le fichier déclencheur, pour que la
  pull request montre la ligne en cause.
- Sortie : une ligne par workflow en défaut, avec ses raisons (objets de l'ADR-0010).

### Hooks git (`devtools git:install-hooks`)

| Hook | Action | Coût |
|---|---|---|
| `pre-commit` | `workflows:check --staged` sur les seuls fichiers indexés ; **avertit**, n'empêche pas le commit (sauf `--strict`) | < 1 s |
| `post-merge`, `post-checkout` | `workflows:inspect --no-ai` : XML et menu à jour ; la rédaction reste différée (§ 4.8) | un re-scan |

- Installés dans `.git/hooks/` **en chaînant** un hook existant (jamais écrasé), ou déclarés dans
  `core.hooksPath` s'il est configuré ; `git:uninstall-hooks` retire exactement ce qui a été ajouté.
- Un hook qui échoue pour une raison technique (DevTools absent, PHP absent) **n'empêche jamais** un
  commit : il affiche la cause et sort à 0.

### CI

Un exemple de job GitHub Actions dans le README, avec `workflows:check --format=github`. Le dépôt
DevTools lui-même l'applique à son `.devtools/` (dogfooding, ADR-0015).

## Budget d'exécution

`check` : le coût d'un re-scan sans écriture (ADR-0010 : < 10 s pour 300 routes). `pre-commit` :
limité aux fichiers indexés via `files-to-workflows.xml`, sans extraction.

## Hors périmètre

Mise à jour automatique commitée par la CI ; rédaction IA déclenchée par un hook ; hooks d'autres
gestionnaires (Husky, GrumPHP, CaptainHook) — un exemple de configuration suffit.

## Critères d'acceptation

- [ ] `check` : 0 sur un projet frais ; 1 après modification d'un fichier listé ; 1 pour un point
      d'entrée non documenté ; raisons affichées
- [ ] `--require-ai` : 1 si une page n'a jamais été rédigée
- [ ] `--format=github` : annotations conformes (snapshot)
- [ ] `check` n'écrit aucun fichier (arborescence comparée)
- [ ] `git:install-hooks` chaîne un hook existant et `git:uninstall-hooks` le restaure à l'octet
- [ ] Hook sans DevTools installé : message, sortie 0, commit effectué
- [ ] README : sections « Contrôle en CI » et « Hooks git »

## Conséquences

- Une pull request qui change un comportement sans que la documentation suive devient visible dans
  la CI — ce qui suppose que `inspect` tourne vite, d'où le budget de l'ADR-0010.

## Dépendances

ADR-0015.
