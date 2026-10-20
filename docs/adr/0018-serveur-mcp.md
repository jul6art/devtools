# ADR-0018 — Serveur MCP `devtools`

- **Statut** : Accepted — 2026-10-20 (proposée le 2026-09-16 ; phase 1 lancée par jul6art)
- **Décideurs** : jul6art
- **Specs** : § 3 (serveur MCP), § 4.8 (serveur MCP dès la phase 0), § 9 (phase 1)

## Contexte

« C'est ce qui fait de la documentation un contexte **ciblé** pour Claude Code plutôt qu'un dossier à
lire en entier » (§ 4.8). Lire `.devtools/` à la main coûte du contexte ; un outil MCP rend la page
d'un workflow, et seulement elle.

Vérifié le 2026-09-16 : `mcp/sdk` (SDK PHP officiel du Model Context Protocol) existe en **v0.8.1**,
avant la 1.0, et tire une quinzaine de dépendances PSR et `opis/json-schema`.

Alternatives écartées :
- *`mcp/sdk` en `require`* — impose ces dépendances à tout projet qui installe DevTools, y compris
  pour ceux qui n'utilisent jamais MCP.
- *Implémentation JSON-RPC maison* — quatre outils en lecture seule y tiendraient, mais le protocole
  évolue et le suivre à la main est un travail sans fin.

## Décision

### Dépendance optionnelle

`mcp/sdk: ^0.8` en **`suggest`** et `require-dev`. La commande `devtools mcp:serve` n'est
enregistrée que si `class_exists()` trouve le SDK ; sinon `devtools mcp:serve` répond par une
commande inconnue suivie d'un message qui donne le `composer require` à lancer. Aucune classe du SDK
n'est référencée hors de `src/Mcp/`.

⚠️ Pré-1.0 : chaque montée de version mineure du SDK est un changement potentiellement cassant ; la
contrainte reste étroite (`^0.8`) et la montée se fait par ADR.

### Transport et périmètre

- **stdio uniquement** : le serveur est lancé par Claude Code, dans le projet. Aucun port ouvert.
- **Lecture seule** : aucun outil n'écrit, aucun ne lance `inspect`.
- Racine fixée au démarrage (`--path`, défaut : répertoire courant) ; tout chemin reçu est résolu par
  `ProjectRoot` et refusé s'il sort de la racine.

### Outils

| Outil | Entrée | Rend |
|---|---|---|
| `list_workflows` | `type?`, `status?` | identifiant, type, titre, statut, confiance, page — lu dans `index.xml` |
| `get_workflow` | `id` | la page Markdown et les métadonnées du XML (fichiers, dépendances, révisions) |
| `impact` | `files[]` ou `changed: true` | le résultat de l'ADR-0016 |
| `get_knowledge` | `stack?` | le `knowledge` de la stack (ADR-0012) |

Les descriptions d'outils disent **quand** les appeler (« avant de modifier un fichier, appelle
`impact` ») — c'est ce que Claude lit pour décider.

### Installation

`devtools claude:install --mcp` ajoute l'entrée `devtools` au `.mcp.json` du projet (fusion, jamais
écrasement, clé existante conservée sauf `--force`).

### Précisions à l'acceptation (2026-10-20)

- **`mcp/sdk: ^0.9`** : la v0.9.0 est sortie le 2026-10-18, et la v0.8 que visait l'ADR ne sera plus
  suivie. La contrainte reste étroite ; la prochaine montée mineure passe par une ADR, comme prévu.
  Son plugin Composer (`php-http/discovery`) est refusé dans `allow-plugins` : DevTools n'en a pas
  besoin, et une CI non interactive échouerait sur la question.
- **Sans le SDK, `mcp:serve` n'existe pas** : demandée quand même, la console répond par son erreur de
  commande inconnue, suivie de la ligne `composer require --dev mcp/sdk:^0.9`.
- **La racine** : `--path`, sinon `CLAUDE_PROJECT_DIR` — que Claude Code pose dans l'environnement des
  serveurs qu'il lance —, sinon le répertoire courant.
- **`.mcp.json`** : `{"type": "stdio", "command": "${CLAUDE_PROJECT_DIR:-.}/vendor/bin/devtools",
  "args": ["mcp:serve"]}` quand le projet installe DevTools, `"command": "devtools"` sinon. Claude Code
  n'étend dans ce fichier que ses propres variables d'environnement, d'où la valeur par défaut.
  L'entrée que DevTools a écrite suit l'installation sans `--force` ; une entrée `devtools` qu'il n'a pas
  écrite est conservée, et `claude:uninstall --mcp` n'y touche pas.
- **Réponses** : du texte, JSON compact pour les listes et l'impact — Claude Code tronque une réponse
  au-delà de 25 000 jetons. `get_workflow` rend deux blocs : la page, puis les métadonnées du XML de
  suivi (fichiers et rôles, tests, dépendances, mécanismes, révisions).
- **`get_knowledge`** lit la fiche comme l'inspection la trouverait — celle du projet, sinon celle de la
  bibliothèque partagée, sinon celle de DevTools — sans la copier dans le projet : le serveur n'écrit
  rien. `stack` désigne une racine de stack ou une clé ; seules les clés de `stack.xml` sont lues.
- **Toute erreur d'un outil est une erreur MCP** (`isError: true`, message lisible), pas seulement un
  identifiant inconnu : projet jamais inspecté, chemin hors racine, XML illisible.
- Le hook `SessionStart` (ADR-0019) nomme déjà le serveur quand `.mcp.json` le déclare.

## Budget d'exécution

Chaque appel lit au plus un XML d'index et un couple page + XML. Aucun appel ne parcourt `src/`.

## Hors périmètre

Transport HTTP/SSE ; outils en écriture (`inspect`, `apply`, `feedback:add` — une ADR le jour où c'est
utile) ; ressources et prompts MCP ; authentification.

## Critères d'acceptation

- [x] Sans `mcp/sdk` : `mcp:serve` absent de `list`, message d'installation affiché *(l'absence est
      simulée : `new Application(false)`)*
- [x] Avec : dialogue stdio de test (initialize, tools/list, tools/call) sur `symfony-minimal`,
      réponses figées en snapshot *(`tests/Fixtures/snapshots/mcp/dialogue.json`, à travers le vrai
      transport stdio du SDK)*
- [x] `get_workflow` d'un identifiant inconnu → erreur MCP explicite, pas une exception PHP
- [x] Un chemin hors racine passé à `impact` est refusé *(relatif et absolu)*
- [x] `claude:install --mcp` fusionne un `.mcp.json` existant sans perdre ses entrées *(et
      `claude:uninstall --mcp` le rend tel qu'il était)*
- [x] Test d'architecture : aucune référence au SDK hors de `src/Mcp/` *(`SdkBoundaryTest`, par les
      jetons PHP)*
- [ ] Session réelle vérifiée une fois dans Claude Code *(dialogue réel par `bin/devtools mcp:serve`
      sur ce dépôt vérifié ; reste une session Claude Code)*
- [x] README : section « Serveur MCP » *(« MCP server »)*

## Conséquences

- Le SDK pré-1.0 est une dette assumée et bornée à un dossier.

## Dépendances

ADR-0016.
