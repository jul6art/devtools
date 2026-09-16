# ADR-0022 — Captures, baselines et diff visuel

- **Statut** : Proposed — 2026-09-16
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

## Budget d'exécution

Comparaison en mémoire, une par capture ; < 200 ms pour une capture 1280×800.

## Hors périmètre

Comparaison perceptuelle (SSIM) ; baselines par navigateur ; stockage externe des captures.

## Critères d'acceptation

- [ ] Images identiques, sous le seuil, au-dessus, tailles différentes : verdicts corrects (fixtures
      PNG)
- [ ] Masque par sélecteur : une date changeante ne produit aucune différence
- [ ] Diff DOM : un `<select>` devenu `<input>` est nommé dans le résultat
- [ ] `baselines:accept` copie exactement les captures d'une exécution ; sans acceptation, verdict
      `no-baseline`
- [ ] Sans `ext-gd` : comparaison d'image désactivée avec avertissement, diff DOM maintenu
- [ ] README : section « Baselines visuelles »

## Conséquences

- Les baselines alourdissent le dépôt : c'est un choix par projet, et les masques sont la seule
  manière de garder les faux positifs sous contrôle.

## Dépendances

ADR-0021.
