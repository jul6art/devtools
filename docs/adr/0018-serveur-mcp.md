# ADR-0018 — Serveur MCP `devtools`

- **Statut** : Proposed — 2026-09-16
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

## Budget d'exécution

Chaque appel lit au plus un XML d'index et un couple page + XML. Aucun appel ne parcourt `src/`.

## Hors périmètre

Transport HTTP/SSE ; outils en écriture (`inspect`, `apply`, `feedback:add` — une ADR le jour où c'est
utile) ; ressources et prompts MCP ; authentification.

## Critères d'acceptation

- [ ] Sans `mcp/sdk` : `mcp:serve` absent de `list`, message d'installation affiché
- [ ] Avec : dialogue stdio de test (initialize, tools/list, tools/call) sur `symfony-minimal`,
      réponses figées en snapshot
- [ ] `get_workflow` d'un identifiant inconnu → erreur MCP explicite, pas une exception PHP
- [ ] Un chemin hors racine passé à `impact` est refusé
- [ ] `claude:install --mcp` fusionne un `.mcp.json` existant sans perdre ses entrées
- [ ] Test d'architecture : aucune référence au SDK hors de `src/Mcp/`
- [ ] Session réelle vérifiée une fois dans Claude Code
- [ ] README : section « Serveur MCP »

## Conséquences

- Le SDK pré-1.0 est une dette assumée et bornée à un dossier.

## Dépendances

ADR-0016.
