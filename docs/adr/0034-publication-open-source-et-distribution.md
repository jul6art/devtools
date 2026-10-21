# ADR-0034 — Publication open source, phar, image Docker et site de documentation

- **Statut** : Accepted — 2026-10-21 (proposée le 2026-09-16 ; phase 3 lancée par jul6art le 2026-10-21)
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

- [x] Phar construit en CI ; `devtools.phar workflows:inspect` sur `symfony-minimal` = même sortie
      qu'en Composer *(même sortie vérifiée — `DistributionTest`, Box 4.7 ; `release.yml` joué le
      2026-10-21 pour la v3.4.0 : phar de 2,1 Mo déposé sur la release avec sa somme SHA-256 — vérifiée —
      et son attestation de provenance — `gh attestation verify` l'accepte ; image `ghcr.io/jul6art/devtools:3.4.0`
      de 218 Mo, qui répond `DevTools 3.4.0`)*
- [x] `self-update` refuse un binaire dont la somme ne correspond pas (test avec un faux serveur)
      *(et une attestation que la CLI GitHub ne vérifie pas, ou l'absence de cette CLI)*
- [x] Image Docker : inspection de `angular-minimal` monté, sortie identique *(construite en local depuis
      le phar, 198 Mo ; publiée sur ghcr.io par `release.yml`, pas encore joué)*
- [x] `export:site` : site fonctionnel hors ligne (aucune requête réseau, vérifié par test navigateur
      Panther réseau coupé) *(`SiteBrowserTest` : ouvert depuis le disque, diagrammes dessinés, recherche,
      aucune ressource hors du site)*
- [x] Checklist de publication cochée ; site publié *(le 2026-10-21 : `SECURITY.md` à jour des surfaces
      des phases 1 à 4, Packagist annonce la v3.4.0, signalement privé activé, GitHub Pages en mode
      workflow et `site.yml` joué — https://jul6art.github.io/devtools/ —, CI verte au décompte : 1 145
      tests en `highest` comme en `lowest`, couverture, MSI 97 %. La CI n'est **pas** laissée active :
      la règle de jul6art du 2026-10-01 coupe les Actions entre deux passages mensuels, ce qui l'emporte
      sur le « réactivée » de la décision)*
- [x] README : installation phar, Docker, export

## Écarts assumés à la livraison

- **`self-update` exige la CLI GitHub** (`gh attestation verify`) pour vérifier l'attestation : sans elle, il
  refuse et dit comment mettre à jour à la main. C'est, avec le client de l'ADR-0035, la seule classe qui
  ouvre une connexion ; le test d'architecture de l'ADR-0002 les nomme.
- **Le phar n'emporte pas le bridge Symfony** (`src/Bridge`, qui exige le kernel) ; le script du pont Node et
  le routeur de la page de revue en sont recopiés dans le répertoire temporaire, Node et `php -S` ne lisant
  pas `phar://`.
- **Mermaid 11.12.1** est embarqué dans `resources/site/mermaid/` avec sa licence MIT ; le site n'utilise
  aucune police ni image.
- **Un répertoire de sortie que DevTools n'a pas écrit n'est jamais vidé** : un marqueur `.devtools-site`
  désigne le sien.

## Conséquences

- Un projet sans PHP peut utiliser DevTools ; chaque release porte trois artefacts à garder
  cohérents, ce que la CI vérifie.

## Dépendances

ADR-0015 ; ADR-0031 (pour que l'image embarque Node utilement).
