# ADR-0035 — Client API Claude et rédaction en CI

- **Statut** : Accepted — 2026-10-21 (proposée le 2026-09-16 ; compléments lancés par jul6art le 2026-10-21, « désactivé par défaut, peur des tokens »)
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

- [x] Client HTTP simulé : brief → requête conforme (snapshot sans clé) → brouillon → `apply` *(une
      fausse API servie par `php -S` sur 127.0.0.1, `AiRunTest`)*
- [x] Clé absente → message ; clé jamais présente dans une sortie (recherche dans tous les fichiers
      écrits par le test)
- [x] Brief contenant un secret de fixture → tâche refusée, aucune requête émise
- [x] `--max-tasks` et budget de tokens respectés, briefs restants conservés
- [x] Brouillon API invalide → refusé comme un brouillon Claude Code
- [ ] Session réelle vérifiée une fois avec une clé, sur `symfony-minimal` *(non faite : elle dépense des
      tokens, ce que jul6art a demandé d'éviter par défaut — à jouer quand il le décide)*
- [x] README : section « Rédaction en CI » *(« Writing in CI »)*

## Écarts assumés à la livraison

- **Désactivé par défaut** (demande de jul6art, 2026-10-21) : `ai:run` refuse tant que `config.xml` ne porte pas
  `<ai enabled="true"/>`, et la commande n'est lancée par rien d'autre — ni `inspect`, ni un hook.
- **Pas de `symfony/http-client`** : le client est du HTTP brut par les flux de PHP (TLS d'`ext-openssl`), dans
  une seule classe, `Ai\Api\AnthropicClient` — que le test d'architecture de l'ADR-0002 nomme avec celle de
  `self-update`. La commande existe donc toujours, éteinte.
- **Modèle par défaut `claude-opus-5-5`**, et non `claude-fable-5-1`, plus capable mais deux fois et demie plus
  cher : le coût est la crainte exprimée. `model` le change. Le repli côté serveur sur refus d'un classifieur
  (`fallbacks: "default"`) est demandé pour les modèles qui l'acceptent.
- **Pas de température** : les modèles courants refusent les paramètres d'échantillonnage (400) ; l'effort
  (`medium` par défaut) et les validateurs tiennent le déterminisme.
- **Ce qui part** : le brief, son prompt, la fiche de connaissance, et chaque fichier du projet qu'il nomme
  (`path="…"`, un niveau de plus pour le modèle XML) — 200 Ko par fichier, 600 Ko par tâche au plus, sinon la
  tâche est laissée à Claude Code.

## Conséquences

- La rédaction devient possible sans humain, et donc facturée : les garde-fous de coût font partie de
  la fonctionnalité, pas de sa finition.

## Dépendances

ADR-0015.
