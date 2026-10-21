# review
`command.review` · type: commands · last updated: 2026-10-20 · commit: 58c02fb

## Summary

La page de revue (ADR-0028) : les captures d'une exécution à côté de leurs baselines et de leurs différences, et la capture d'un échec ; la page capturée montrée sans jamais être exécutée, où l'on désigne un élément dont le sélecteur est calculé et vérifié unique ; un retour en une ligne, enregistré comme feedback:add l'enregistrerait ; une capture acceptée comme baseline. Servie par le serveur intégré de PHP sur 127.0.0.1 seulement, derrière un jeton aléatoire, jusqu'à Ctrl+C.

## Trigger

| Element | Value |
|---|---|
| Entry point | `review` (command) |
| Security | — |
| Preconditions | Le port est libre et --run, s'il est donné, nomme une exécution existante ; sans exécution, la page le dit. |

## Journey

```mermaid
sequenceDiagram
  participant H as Humain
  participant C as review
  participant S as php -S 127.0.0.1
  participant A as ReviewApp
  participant F as Retours et baselines
  H->>C: devtools review --port=8765
  C->>S: routeur, projet, jeton
  S-->>C: répond sur le port
  C-->>H: l'adresse avec son jeton
  H->>S: la page, la capture, un clic sur un élément
  S->>A: requête, jeton vérifié
  A->>F: un retour, une occurrence, une baseline
  A-->>H: identifiant, récurrence, règle proposée
```

## Decisions

**`Jul6Art\DevTools\Command\ReviewCommand::execute`**

```mermaid
flowchart TD
  d1{"le chemin n'est pas un dossier, ou --port n'est pas un port"}
  d1 -->|oui| v1["INVALID"]
  d1 -->|non| d2
  d2{"--run nomme une exécution absente"}
  d2 -->|oui| v2["INVALID"]
  d2 -->|non| d3
  d3{"PHP ne dit pas qu'il écoute dans les 5 s : port déjà pris, ou serveur muet"}
  d3 -->|oui| v3["FAILURE — ce que PHP a dit"]
  d3 -->|non| v4["SUCCESS — servie jusqu'à l'arrêt"]
```

**`element::textContent`**

```mermaid
flowchart TD
  d1{"un texte est donné à l'élément XML"}
  d1 -->|oui| v1["le texte devient le contenu"]
  d1 -->|non| v2["l'élément reste vide"]
```

## Data

Lit les exécutions de .devtools/runs/, les baselines, l'index, la carte et l'arbre de travail git (le filtre des workflows impactés), les retours ; n'écrit que dans .devtools/feedback/ et .devtools/baselines/.

## Cross-cutting mechanisms

Aucun.

## Points of attention

La page capturée s'affiche sans les feuilles de style de l'application : la capture d'écran montre l'apparence, la page sert à désigner. Toute requête, sauf la feuille de style et le script de la page, porte le jeton. La commande ne rend la main qu'à l'arrêt du serveur. Elle attend que PHP annonce qu'il écoute, sans jamais sonder le port : DevTools n'ouvre aucune connexion (ADR-0002). La décision sur `element::textContent` est celle de DomBuilder, sur le chemin de toute lecture ou écriture XML : elle n'est pas propre à ce workflow.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-10-20 | 58c02fb | rédaction initiale |
| 2026-10-20 | 58c02fb | Le serveur est tenu pour prêt quand PHP annonce qu'il écoute, sans sonder le port (ADR-0002) ; un port déjà pris se dit par ce que PHP en dit. (modified decision Jul6Art\DevTools\Command\ReviewCommand::execute: \Jul6Art\DevTools\Review\Web\ReviewServer::listening($port) → null !== $failure (+1)) |
