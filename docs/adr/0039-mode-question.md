# ADR-0039 — Mode question : `devtools ask`

- **Statut** : Proposed — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 4.8 (mode question)

## Contexte

« Que se passe-t-il quand un opérateur valide une commande ? » : la réponse est dans les pages, pas
besoin de relire le code (§ 4.8). Dans Claude Code, la question se pose déjà en langage naturel et le
serveur MCP (ADR-0018) donne l'accès aux pages ; il manque la **sélection** des pages pertinentes, et
une réponse hors session (terminal, CI, humain sans Claude ouvert).

## Décision

### Sélection sans IA (`Ask\PageSelector`)

- Index plein texte local construit à `inspect` : `.devtools/search.idx` (ignoré par git), sur titres,
  résumés, identifiants, termes du glossaire (ADR-0037), noms de fichiers.
- Classement BM25 simple, implémenté dans le cœur (aucune dépendance), puis élargissement d'un saut par
  `dependsOn` et navigation.
- `devtools ask --pages "<question>"` : **affiche les pages retenues** avec leur score. Utilisable sans
  IA, et c'est ce que le MCP expose (outil `search_workflows` ajouté au serveur de l'ADR-0018).

### Réponse

- Dans Claude Code : le skill `devtools-ask` appelle `ask --pages`, lit les pages retenues, répond **en
  citant les identifiants** des workflows.
- Hors session : `devtools ask "<question>"` utilise le client de l'ADR-0035 (si installé) avec les
  pages retenues comme seul contexte, et la consigne de répondre « non documenté » plutôt que d'inventer.
- Toute réponse liste ses sources (identifiants + dates de dernière révision) et avertit si une page
  source est périmée.

## Budget d'exécution

Sélection < 200 ms pour 300 workflows ; au plus 8 pages en contexte.

## Hors périmètre

Questions sur le code non documenté ; conversation à plusieurs tours ; embeddings.

## Critères d'acceptation

- [ ] Jeu de questions de fixture sur `symfony-minimal` : pages attendues dans les trois premières
- [ ] Élargissement par `dependsOn` vérifié
- [ ] `search_workflows` exposé par le serveur MCP
- [ ] Réponse simulée qui cite une page absente du contexte → signalée
- [ ] Source périmée → avertissement
- [ ] README : section « Poser une question »

## Conséquences

- La documentation devient interrogeable ; sa qualité se voit dans les réponses, ce qui incite à la
  rédiger.

## Dépendances

ADR-0018 ; ADR-0035 pour la réponse hors session ; ADR-0037 facultative.
