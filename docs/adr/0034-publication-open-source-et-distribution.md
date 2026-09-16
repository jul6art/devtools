# ADR-0034 — Publication open source, phar, image Docker et site de documentation

- **Statut** : Proposed — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 2 (« potentiellement open source »), § 4.8 (export statique), § 9 (phase 3), § 12 question 5

## Contexte

Le MVP est distribué par Composer (ADR-0015). Pour un projet Angular ou Go, installer PHP et Composer
afin de documenter ses workflows est une barrière. Le § 12 évoque phar et Docker ; le § 9 une
publication open source et un site ; le § 4.8 un export statique de `.devtools/` pour la lecture hors
IDE.

## Décision

### Phar

- Construit par **Box** (`humbug/box`, outil de build, pas dépendance) en CI sur chaque tag ;
  attaché à la release GitHub avec sa somme SHA-256 et une signature
  (attestation de provenance GitHub).
- `devtools self-update` : télécharge la dernière release, **vérifie la somme et l'attestation** avant
  de remplacer le binaire ; refuse sinon.
- Commandes qui exigent un paquet optionnel (MCP, Panther) : absentes du phar, message qui explique
  l'installation Composer.

### Image Docker

`ghcr.io/jul6art/devtools:<version>` : PHP CLI, git, Node LTS (pour le pont de l'ADR-0031) ; lancée avec
le projet monté (`docker run -v "$PWD:/project" …`). Construite et publiée en CI sur tag.

### Export statique (§ 4.8)

`devtools export:site [--output=build/devtools-site]` : site HTML autonome depuis `.devtools/` — menu,
pages, diagrammes Mermaid rendus côté client par le script Mermaid **copié dans le site** (pas de CDN),
recherche plein texte locale. Aucun générateur externe (Docsify, MkDocs) : un moteur de moins à
installer.

### Open source

- Le dépôt est déjà MIT et conforme aux standards communautaires GitHub.
- Avant l'annonce : `SECURITY.md` à jour des surfaces des phases 1–3, signalement privé activé, CI
  verte et **réactivée**, site de documentation publié (GitHub Pages depuis `export:site` appliqué au
  `.devtools/` du dépôt, plus une page d'accueil).
- Packagist : paquet enregistré, webhook GitHub.

## Budget d'exécution

Phar < 20 Mo ; image < 250 Mo ; `export:site` < 5 s pour 300 workflows.

## Hors périmètre

Homebrew, apt, npm ; hébergement du site ailleurs que GitHub Pages ; télémétrie d'usage.

## Critères d'acceptation

- [ ] Phar construit en CI ; `devtools.phar workflows:inspect` sur `symfony-minimal` = même sortie
      qu'en Composer
- [ ] `self-update` refuse un binaire dont la somme ne correspond pas (test avec un faux serveur)
- [ ] Image Docker : inspection de `angular-minimal` monté, sortie identique
- [ ] `export:site` : site fonctionnel hors ligne (aucune requête réseau, vérifié par test navigateur
      Panther réseau coupé)
- [ ] Checklist de publication cochée ; site publié
- [ ] README : installation phar, Docker, export

## Conséquences

- Un projet sans PHP peut utiliser DevTools ; chaque release porte trois artefacts à garder
  cohérents, ce que la CI vérifie.

## Dépendances

ADR-0015 ; ADR-0031 (pour que l'image embarque Node utilement).
