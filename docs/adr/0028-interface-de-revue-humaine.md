# ADR-0028 — Interface de revue humaine

- **Statut** : Accepted — 2026-10-21 (proposée le 2026-09-16 ; phase 2 lancée par jul6art le 2026-10-20)
- **Décideurs** : jul6art
- **Specs** : § 3.1 (module Review), § 5.4

## Contexte

Le retour humain est la matière première de toute la phase 2. S'il reste plus cher à saisir proprement
qu'à écrire en prose dans le chat, la prose gagnera. Le § 5.4 décrit une page locale : capture,
baseline, diff ; clic sur un élément → sélecteur résolu ; catégorie fermée ; une ligne ; détection de
récurrence ; proposition de promotion.

## Décision

### `devtools review [--run=<id>] [--port=8765]`

- Serveur **local** : serveur intégré de PHP, lié à `127.0.0.1` uniquement, jeton aléatoire dans l'URL
  affichée (une autre application locale ne peut pas écrire de retours à la place de l'humain).
- Aucune dépendance front : une page HTML, un fichier CSS, un fichier JavaScript, dans
  `resources/review/`. Aucun CDN.
- Lecture seule du dépôt **sauf** `.devtools/feedback/` et `.devtools/baselines/`.

### Écrans

1. **Exécution** : liste des scénarios de la dernière exécution (ADR-0021) — verdict, capture,
   baseline, diff (ADR-0022) côte à côte ; filtre sur les workflows impactés par le dernier changement.
2. **Capture** : le snapshot DOM est rendu dans une `iframe` **sandboxée sans scripts** ; survol =
   surlignage ; clic = sélecteur CSS stable calculé (identifiant, sinon attributs `name`/`data-*`,
   sinon chemin court) et affiché.
3. **Retour** : catégorie (liste fermée, ADR-0025), une ligne (compteur à 200), workflow et sélecteur
   pré-remplis → `feedback:add` ; si la récurrence est détectée, la page propose « ajouter une
   occurrence à … » et, à la 2ᵉ, « promouvoir en règle ».
4. **Baseline** : « accepter cette capture comme référence » → `baselines:accept` (ADR-0022).

### Extension du MCP Chrome

Évoquée au § 5.4 : **hors périmètre**. La page locale sert la même fonction sans dépendre d'une
extension.

### Précisions à l'acceptation (2026-10-21)

- **Serveur** : `php -S 127.0.0.1:<port>` sur `resources/review/router.php`, qui confie toute requête à
  `Review\Web\ReviewApp` ; le projet, le jeton (32 hexadécimaux, `random_bytes`) et l'exécution passent par
  l'environnement du processus. Le jeton est attendu dans `?token=` ou l'en-tête `X-DevTools-Token`, comparé par
  `hash_equals` ; seuls la feuille de style et le script de la page s'en passent — ils ne disent rien du projet.
  La commande attend que PHP annonce lui-même qu'il écoute (« Development Server … started ») : elle ne sonde
  jamais le port, DevTools n'ouvrant aucune connexion (ADR-0002, que le test de garde a fait respecter). Un port
  déjà pris — PHP le dit et s'arrête —, un serveur muet 5 s : la commande le dit et rend la main.
- **Exécution** : `--run=<horodatage>`, sinon la dernière, relue à chaque requête (une exécution nouvelle apparaît
  au rechargement). Chaque scénario donne ses captures `screenshot` — baseline et différence quand elles
  existent — et, s'il a échoué, la capture que l'exécution a gardée de l'étape fautive (`XmlRunReader` rend
  désormais aussi `failure`) : on peut la commenter, jamais l'accepter comme baseline. Le filtre « workflows
  impactés » prend l'impact des changements de l'arbre de travail (git) ; hors git, il est vide.
- **Page capturée** : servie sous `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline';
  img-src data:` — aucun script, aucune requête sortante, même ouverte seule —, montrée dans une `iframe`
  `sandbox="allow-same-origin"` sans `allow-scripts` : la page de revue la lit (même origine) pour surligner et
  calculer le sélecteur, elle n'exécute rien. Elle s'affiche sans les feuilles de style de l'application : la
  capture d'écran montre l'apparence, la page sert à désigner.
- **Sélecteur** (dans `review.js`, `DevToolsReview.selectorOf`) : `#id` s'il est unique ; sinon `tag[name="…"]` ;
  sinon le premier `tag[data-…="…"]` unique (hors `data-devtools-*`) ; sinon le chemin le plus court, en
  `:nth-of-type`, remonté jusqu'à ce qu'il soit unique ou jusqu'à un ancêtre à identifiant. L'unicité est
  toujours vérifiée par `querySelectorAll` dans la page capturée.
- **Retour** : mêmes vérifications et même récurrence que `feedback:add` — réponse `422` avec les raisons, `409`
  avec le retour déjà dit (la page propose « ajouter une occurrence » ou « à part »), `201` avec, dès la 2ᵉ
  occurrence, `devtools rules:promote <id>`. **Baseline** : `Scenario\Visual\Baselines`, extraite de
  `baselines:accept`, que les deux partagent.
- **Fichiers servis** : ceux de l'exécution et des baselines seulement, `.png`, `.html` et `.diff`, jamais un
  chemin qui en sort (`..`, encodé ou non). Page : `script-src 'self'`, rien d'inline, rien d'ailleurs.

## Budget d'exécution

Pages servies depuis les fichiers d'exécution existants ; aucun scénario relancé par la revue.

## Hors périmètre

Revue à plusieurs, hébergée ou authentifiée ; commentaires en fil ; annotation dessinée sur la capture ;
extension du MCP Chrome.

## Critères d'acceptation

- [x] Serveur lié à `127.0.0.1`, requête sans jeton refusée (test HTTP) *(le vrai `php -S` : sans jeton, avec un
      faux, `403` ; avec, `200`)*
- [x] Snapshot DOM rendu sans exécution de script (fixture contenant un `<script>` qui écrirait dans la
      page parente) *(et un `onerror` ; dans Chrome)*
- [x] Calcul de sélecteur : identifiant, attribut `name`, `data-*`, chemin court — un test chacun, et
      unicité vérifiée dans le DOM *(dans Chrome ; et un `name` partagé, un chemin ancré sur un identifiant)*
- [x] Soumission → fichier `feedback` valide ; récurrence proposée ; promotion proposée à la 2ᵉ *(en processus
      et dans Chrome, du clic sur l'élément à la proposition de règle)*
- [x] Aucune écriture hors `feedback/` et `baselines/` (arborescence comparée)
- [ ] Vérifié à la main dans Chrome sur une exécution de `symfony-minimal` *(vu par Claude dans Chrome sans
      tête, captures d'écran regardées — deux défauts corrigés ainsi : le formulaire visible avant tout choix,
      la capture d'un échec absente ; reste le regard d'un humain)*
- [x] README : section « Revue » *(« The review page », sous « Feedback »)*

## Conséquences

- Saisir un retour structuré prend moins de temps que l'écrire en prose : c'est la condition pour que la
  phase 2 reçoive des données.

## Dépendances

ADR-0022, ADR-0025.
