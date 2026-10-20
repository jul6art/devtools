# ADR-0022 — Captures, baselines et diff visuel

- **Statut** : Accepted — 2026-10-20 (proposée le 2026-09-16 ; phase 1 lancée par jul6art) · Livré le 2026-10-20
- **Décideurs** : jul6art
- **Specs** : § 5.2 (captures, snapshot DOM, diff visuel contre baseline), § 5.4 (capture / baseline / diff)

## Contexte

Les remontées humaines du § 1.2 sont souvent **visuelles** : un filtre qui ne s'affiche pas, un select
natif là où il fallait un select2. Un scénario vert peut rendre un écran cassé. Comparer une capture à
une référence validée détecte ce que les assertions n'ont pas prévu, et donne à la revue humaine
(ADR-0028) le trio capture / baseline / diff.

## Décision

### Baselines

- `.devtools/baselines/<scenario-id>/<screenshot>.png` + `.dom.html`, **versionnées** (Git LFS
  recommandé dans le README au-delà de 50 Mo, jamais imposé).
- Une baseline n'existe que si un humain l'a **acceptée** : `devtools baselines:accept <run> [id]`
  copie les captures d'une exécution. Jamais d'acceptation automatique : une baseline acceptée par
  l'agent auteur reproduirait le biais du § 1.3.

### Comparaison (`Scenario\Visual\`)

- **Image** : comparaison pixel à pixel sur des captures de même taille, tolérance configurable
  (`<visual threshold="0.1%"/>`), masques par sélecteur CSS pour les zones volatiles (dates, avatars)
  appliqués **avant** la capture (éléments masqués par CSS injecté).
- Implémentation par `ext-gd` (en `suggest`), sans dépendance Composer ; image de diff produite
  (pixels différents en rouge sur l'original atténué).
- **DOM** : diff structurel normalisé (attributs triés, identifiants générés masqués) — il dit
  **quoi** a changé (`<select>` remplacé par un `<input>`) là où l'image dit seulement **où**.
- Verdict par capture : `identical | within-threshold | different | no-baseline`. `different` fait
  échouer le scénario ; `no-baseline` non (il le signale).

### Résultat

Ajouté à `result.xml` (ADR-0021) : verdict, pourcentage, chemins de l'image de diff et du diff DOM.

### Précisions à l'acceptation (2026-10-20)

- **Configuration** : `<visual threshold="0.1%">` et ses `<mask selector="…"/>` vivent dans `<scenarios>` de
  `config.xml` (seuil par défaut 0,1 % des pixels). Les masques sont globaux pour ce lot : un masque par
  capture serait un attribut de `<screenshot>`, donc une version mineure du vocabulaire.
- **Masque** : une feuille de style injectée cache les éléments (`visibility: hidden`, leur place gardée) le
  temps de la capture, puis est retirée — l'étape suivante voit la page intacte ; dans le DOM capturé, ces
  éléments sont vidés et marqués `data-devtools-masked`, sur une copie du document. Un sélecteur invalide est
  ignoré.
- **Comparaison** : seules les étapes `<screenshot>` sont comparées, jamais la capture d'un échec. Image :
  octets identiques d'abord, puis pixel à pixel (`imagecolorat`, le format brut de GD étant absent des
  builds récentes) — 87 ms mesurés sur 1280×800. Tailles différentes : 100 %. L'image de diff est la
  baseline en gris éclairci, les pixels différents en rouge.
- **DOM** : analyseur HTML5 de PHP 8.4 (`Dom\HTMLDocument`) ; une ligne par élément (attributs triés) et par
  texte ; `script`, `style`, `noscript`, `template` écartés ; valeur des champs cachés, `nonce`,
  `integrity`, attributs `*token*` masqués ; parties générées des identifiants (`select2-…-a3f9`, `item12`)
  remplacées par `#`. Le diff est par plus longue sous-suite commune, après les préfixe et suffixe communs ;
  le résumé apparie, par bloc, le retrait et l'ajout les moins indentés : `<select …> became <input …>`.
- **Sans GD**, le DOM seul décide (`identical` ou `different`) et `scenarios:run` l'annonce par un
  avertissement.
- **`baselines:accept <run> [scénarios…]`** : `<run>` est le nom du dossier de `.devtools/runs/` ou `last` ;
  les captures sont celles des étapes `screenshot` de `result.xml`, copiées à l'octet près. Le mode PHPUnit
  écrit aussi son `result.xml`, un par classe de test : on y accepte les captures de la même façon.

## Budget d'exécution

Comparaison en mémoire, une par capture ; < 200 ms pour une capture 1280×800.

## Hors périmètre

Comparaison perceptuelle (SSIM) ; baselines par navigateur ; stockage externe des captures.

## Critères d'acceptation

- [x] Images identiques, sous le seuil, au-dessus, tailles différentes : verdicts corrects (fixtures
      PNG) *(dessinées par le test avec GD ; octets différents, pixels identiques : `identical`)*
- [x] Masque par sélecteur : une date changeante ne produit aucune différence *(dans Chrome, page
      `/clock` ; sans masque, `different` et l'étape échoue)*
- [x] Diff DOM : un `<select>` devenu `<input>` est nommé dans le résultat
- [x] `baselines:accept` copie exactement les captures d'une exécution ; sans acceptation, verdict
      `no-baseline`
- [x] Sans `ext-gd` : comparaison d'image désactivée avec avertissement, diff DOM maintenu
- [x] README : section « Baselines visuelles » *(« Visual baselines »)*

## Conséquences

- Les baselines alourdissent le dépôt : c'est un choix par projet, et les masques sont la seule
  manière de garder les faux positifs sous contrôle.

## Dépendances

ADR-0021.
