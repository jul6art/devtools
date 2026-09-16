# ADR-0028 — Interface de revue humaine

- **Statut** : Proposed — 2026-09-16
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

## Budget d'exécution

Pages servies depuis les fichiers d'exécution existants ; aucun scénario relancé par la revue.

## Hors périmètre

Revue à plusieurs, hébergée ou authentifiée ; commentaires en fil ; annotation dessinée sur la capture ;
extension du MCP Chrome.

## Critères d'acceptation

- [ ] Serveur lié à `127.0.0.1`, requête sans jeton refusée (test HTTP)
- [ ] Snapshot DOM rendu sans exécution de script (fixture contenant un `<script>` qui écrirait dans la
      page parente)
- [ ] Calcul de sélecteur : identifiant, attribut `name`, `data-*`, chemin court — un test chacun, et
      unicité vérifiée dans le DOM
- [ ] Soumission → fichier `feedback` valide ; récurrence proposée ; promotion proposée à la 2ᵉ
- [ ] Aucune écriture hors `feedback/` et `baselines/` (arborescence comparée)
- [ ] Vérifié à la main dans Chrome sur une exécution de `symfony-minimal`
- [ ] README : section « Revue »

## Conséquences

- Saisir un retour structuré prend moins de temps que l'écrire en prose : c'est la condition pour que la
  phase 2 reçoive des données.

## Dépendances

ADR-0022, ADR-0025.
