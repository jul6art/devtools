# ADR-0010 — Fraîcheur : ne réécrire que ce qui a changé

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 2 (P1, « très important »), § 4.3 étape 6, § 4.6.2, § 7.2 (familles fraîcheur, idempotence, mutation), § 8, § 11

## Contexte

C'est **le cœur de la demande** (§ 4.3 étape 6) : relancée, la commande « ne réécrit pas les
workflows dont on sait qu'ils n'ont pas changé ». Sans ce lot, chaque scan réécrit tout — et, dès
l'ADR-0011, chaque réécriture coûte une rédaction par Claude.

Les données nécessaires sont écrites depuis l'ADR-0009 (commit, `sha256` par fichier). Ce lot ne
produit rien de nouveau : il **décide**, et il prouve sa décision.

## Décision

### `FreshnessResolver` (namespace `Inspection\Freshness\`)

Entrée : le modèle du scan courant + les XML de suivi existants. Sortie : une `FreshnessDecision`
par workflow — `create | rewrite | keep | orphan | manual-stale` — avec **ses raisons** (liste
d'objets, pas une chaîne) : `files-changed[paths]`, `files-removed[paths]`, `files-added[paths]`,
`package-major[name, from, to]`, `entrypoint-gone`, `forced`, `no-previous-tracking`.

L'algorithme du § 4.6.2, dans cet ordre :

1. **Git (rapide)** — `GitProvider` : si `git` est disponible et que le commit enregistré existe
   (`git cat-file -e`), **une seule** `git diff --name-only <plus ancien commit enregistré>..HEAD`
   et **une seule** `git status --porcelain=v1 -z` pour tout le scan. Intersection avec `<files>` →
   candidats.
2. **Hash (fiable, et seul juge)** — `Hasher` recalcule le `sha256` des fichiers **candidats**, et
   de **tous** les fichiers listés quand git est absent, que le commit est introuvable (rebase,
   copie de projet) ou que `--since` est donné. Aucun hash différent → `keep`, même si git signalait
   un candidat.
3. **Fichiers supprimés** — `<file>` absent du disque → `rewrite` avec statut `stale` ; point d'entrée
   disparu du modèle → `orphan` (page conservée, listée dans « À vérifier » ; suppression seulement
   avec `--prune`).
4. **Fichiers nouveaux** — liste des fichiers du modèle absents de l'ancien `<files>` → `rewrite`
   (ils ont été découverts par l'adaptateur, qui repasse sur tout ; la « passe de découverte limitée »
   du § 4.6.2 ne concerne que la voie Claude, ADR-0013).
5. **Paquets** — changement de **version majeure** d'un `<package>` → `rewrite`.
6. **`manual`** — jamais réécrit ; son XML est mis à jour, et s'il aurait dû l'être : alerte et
   décision `manual-stale`.

⚠️ La décision **ne s'appuie jamais sur git seul** : git sert à éviter de hasher, jamais à conclure
qu'un fichier est inchangé. Un faux « inchangé » est plus grave qu'une réécriture inutile (§ 11).

### Effets sur l'écriture

- `keep` : ni la page ni le XML ne sont réécrits — **même octets**. Seuls `workflows.md`,
  `index.xml`, `graph/` sont régénérés (§ 4.6.2, dernier paragraphe) — et ils ne changent pas non
  plus si rien n'a changé.
- `rewrite` / `create` : page factuelle réécrite (sections Claude conservées, ADR-0008), XML réécrit
  avec une nouvelle `<revision reason="…">` construite depuis les raisons, et une ligne ajoutée à la
  section « Historique ».

### Options

| Option | Effet |
|---|---|
| `--dry-run` | affiche chaque décision **et ses raisons**, n'écrit rien |
| `--force` / `--force=<id>` | décision `rewrite` (raison `forced`) pour tous / un workflow |
| `--since=<commit>` | candidats git calculés depuis ce commit, puis hash de tout le reste |
| `--prune` | supprime page et XML des workflows `orphaned` (liste affichée avant) |

## Budget d'exécution

- **Au plus quatre processus git par scan** (`rev-parse HEAD`, `cat-file -e`, `diff`, `status`), quel
  que soit le nombre de workflows.
- **Un hash par fichier distinct** par scan (un fichier partagé par N workflows : un hash).
- **Re-scan sans changement d'un projet de 300 routes : < 10 s sans IA** (§ 8), mesuré par un
  test de performance sur un projet généré (ADR-0015 le fige en CI).
- **0 % de réécriture inutile** (§ 8) : c'est le critère d'idempotence ci-dessous.

## Hors périmètre

Cache d'AST persistant entre exécutions ; hash parallélisé (§ 11 « gros dépôts » : après mesure) ;
`workflows:check` et `workflows:impact` (phase 1 des specs).

Dépendance de développement ajoutée : `infection/infection` (mutation, § 7.2).

## Critères d'acceptation

Chaque cas du § 7.2 « Fraîcheur » dans un dépôt git temporaire créé par le test :

- [ ] Aucun changement → deux exécutions consécutives, **zéro fichier modifié** hors `reports/`
      (idempotence, § 7.2)
- [ ] Modification d'un service partagé par deux routes → exactement ces deux workflows réécrits,
      avec `files-changed` et le chemin
- [ ] Modification puis annulation (même contenu, nouveau commit) → `keep` (le hash tranche)
- [ ] Suppression d'un fichier non-entrée → `stale` ; suppression du contrôleur et de la route →
      `orphan`, page conservée ; `--prune` la supprime
- [ ] Nouveau fichier injecté dans un service existant → workflows concernés réécrits
- [ ] Rebase (commit enregistré absent de l'historique) → repli sur le hash, décisions correctes
- [ ] Working tree sale (modifié non commité, non suivi) → pris en compte
- [ ] Sans git (copie du projet) → hash de tout, décisions correctes, `vcs="none"`
- [ ] Changement de version majeure d'un paquet référencé → `rewrite` avec `package-major`
- [ ] Workflow `manual` périmé → non réécrit, alerte, XML mis à jour
- [ ] `--force=<id>`, `--since`, `--dry-run` : un test chacun
- [ ] Compteurs : au plus 4 processus git et un hash par fichier distinct (instrumentés)
- [ ] Couverture 100 % de `Inspection/Freshness/` et `Tracking/` ; Infection lancé sur ces deux
      dossiers, mutants survivants justifiés un par un ou tués
- [ ] Fichier de performance : projet généré de 300 routes, re-scan sans changement < 10 s

## Conséquences

- À la fin de ce lot, la documentation factuelle d'un projet Symfony est **maintenue** à coût quasi
  nul : c'est le socle sur lequel la rédaction IA peut s'ajouter sans exploser le coût.

## Dépendances

ADR-0009.
