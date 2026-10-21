# ADR-0039 — Mode question : `devtools ask`

- **Statut** : Accepted — 2026-10-21 (proposée le 2026-09-16)
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

- [x] Jeu de questions de fixture sur `symfony-minimal` : pages attendues dans les trois premières
      (`AskTest`, huit questions, en français et en anglais)
- [x] Élargissement par `dependsOn` vérifié (`laravel-minimal` : la planification mène à la commande)
- [x] `search_workflows` exposé par le serveur MCP
- [x] Réponse simulée qui cite une page absente du contexte → signalée
- [x] Source périmée → avertissement
- [x] README : section « Asking the documentation »

## Mise en œuvre (2026-10-21)

- Paquet `src/Ask/` : `Tokenizer` (minuscules, accents ôtés, identifiants découpés, mots vides français
  et anglais, racinisation par le plus long suffixe commun qui laisse quatre lettres — `valide`,
  `validate`, `validation` se rejoignent), `SearchIndex` (BM25, k1 = 1,2, b = 0,75), `SearchIndexBuilder`,
  `SearchIndexStore`, `PageSelector`, `SelectedPage`, `QuestionAnswerer`, `Answer` ; commande `ask`.
- L'index pèse les mots selon leur place : titre et identifiant ×3, résumé et termes du glossaire
  (nom, libellé, synonymes) ×2, reste de la page et noms de fichiers ×1 ; l'historique et les workflows
  liés n'y entrent pas. Ce que la sélection élargit (dépendances et navigation) est gardé à part.
- `.devtools/search.idx` est du JSON (jamais `unserialize`), écrit à chaque inspection et reconstruit
  par une question quand son empreinte — l'index, le glossaire, taille et date de chaque page — ne
  correspond plus : `workflows:apply` écrit des pages sans inspecter. Ignoré par git (`init` l'ajoute au
  `.gitignore` comme les zones de travail), ignoré par la gate, écarté des instantanés.
- Sélection : les pages à au moins un quart du meilleur score, puis un saut depuis les trois meilleures ;
  huit au plus. 15 ms sur `symfony-minimal`, sous 200 ms sur 300 workflows (test).
- Réponse hors session : refusée tant que `<ai enabled="true"/>` manque (comme `ai:run`, à la demande de
  l'utilisateur : « peur des tokens »), clé lue dans `ANTHROPIC_API_KEY` seulement ; les pages passent le
  `SecretScanner` de l'ADR-0035 avant de partir ; effort `low`, 4 000 jetons de sortie au plus ; une
  question qui ne partage aucun mot avec les pages n'est pas envoyée.
- L'outil MCP `search_workflows` reconstruit un index périmé en mémoire sans l'écrire : tous les outils du
  serveur restent en lecture seule.
- `claude:install` installe désormais deux skills : `devtools-inspect` et `devtools-ask`.

## Conséquences

- La documentation devient interrogeable ; sa qualité se voit dans les réponses, ce qui incite à la
  rédiger.

## Dépendances

ADR-0018 ; ADR-0035 pour la réponse hors session ; ADR-0037 facultative.
