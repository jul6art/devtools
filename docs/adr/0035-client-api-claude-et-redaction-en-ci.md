# ADR-0035 — Client API Claude et rédaction en CI

- **Statut** : Proposed — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 3 (couche Claude : API ou Claude Code), § 11 (coût, non-déterminisme), § 12 question 1

## Contexte

L'ADR-0002 a choisi Claude Code comme seule voie IA du MVP, en prévoyant que l'API ne serait « qu'un
autre producteur de brouillons ». Le besoin apparaît dès qu'une équipe veut que la documentation se
rédige **sans session humaine** : après un merge, en CI, sur un dépôt que personne n'ouvre ce jour-là.

## Décision

### `devtools ai:run [--tasks=page,knowledge,discovery,scenario,rule,assertion] [--max-tasks=N]`

- Lit les briefs de `.devtools/pending/`, appelle l'API Messages d'Anthropic pour chacun, écrit le
  brouillon, puis lance `workflows:apply`. **Aucune validation n'est contournée** : un brouillon API
  passe par les mêmes validateurs qu'un brouillon Claude Code.
- Client : `symfony/http-client` en `suggest` (commande absente sans lui) ; aucun SDK tiers requis.
- Modèle configurable (`<ai model="…" max-tokens="…"/>`), **par défaut le modèle Claude le plus
  capable disponible au moment de l'implémentation**, vérifié alors dans la documentation Anthropic et
  pas figé ici.
- Clé lue **uniquement** dans `ANTHROPIC_API_KEY` ; jamais dans `config.xml`, jamais écrite dans un
  rapport, un brief, un XML, une exception.
- Prompts : les mêmes fichiers versionnés que pour Claude Code (ADR-0002) ; température basse (§ 11).
- `knowledge` : la tâche exige une recherche web ; via l'API elle utilise l'outil de recherche web
  côté serveur s'il est activé pour la clé, sinon elle est **sautée** avec un message (pas de
  connaissance rédigée de mémoire).

### Garde-fous de coût

- `--max-tasks` (défaut 20) et `<ai budget-tokens="…"/>` par exécution : dépassement → arrêt propre,
  briefs restants conservés.
- Rapport : tâches traitées, tokens d'entrée et de sortie, estimation de coût, refus de validation.
- Mise en cache du préfixe commun des prompts (consignes + knowledge) quand l'API le permet.

### Filtrage avant envoi

Avant tout appel, le brief est **re-vérifié** : aucun fichier exclu par `config.xml`, aucun `.env*`,
aucune chaîne correspondant aux motifs de secrets (`resources/secrets/patterns.xml` : clés AWS, jetons
GitHub, clés privées PEM, `password=`…). Correspondance → tâche refusée, rien n'est envoyé
(`SECURITY.md`).

### CI

Exemple de workflow GitHub Actions dans le README : après merge sur la branche principale,
`workflows:inspect` → `ai:run` → pull request automatique avec la documentation mise à jour (jamais un
push direct).

## Budget d'exécution

Plafonné par `--max-tasks` et le budget de tokens ; la fraîcheur (ADR-0010) garantit qu'un merge sans
changement pertinent ne produit aucun appel.

## Hors périmètre

Autres fournisseurs de modèles ; exécution parallèle des tâches ; API Batch.

## Critères d'acceptation

- [ ] Client HTTP simulé : brief → requête conforme (snapshot sans clé) → brouillon → `apply`
- [ ] Clé absente → message ; clé jamais présente dans une sortie (recherche dans tous les fichiers
      écrits par le test)
- [ ] Brief contenant un secret de fixture → tâche refusée, aucune requête émise
- [ ] `--max-tasks` et budget de tokens respectés, briefs restants conservés
- [ ] Brouillon API invalide → refusé comme un brouillon Claude Code
- [ ] Session réelle vérifiée une fois avec une clé, sur `symfony-minimal`
- [ ] README : section « Rédaction en CI »

## Conséquences

- La rédaction devient possible sans humain, et donc facturée : les garde-fous de coût font partie de
  la fonctionnalité, pas de sa finition.

## Dépendances

ADR-0015.
