# ADR-0004 — Dossier `.devtools/`, configuration et XML de suivi

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 4.4, § 4.6.1, § 4.6.3, § 7.2 (famille XML)

## Contexte

Le XML par workflow est ce que le demandeur a qualifié de **« très important »** (§ 2) : sans lui, un
re-scan ne sait pas ce qui a changé. Son format, le dossier qui le contient et le fichier de
configuration que les deux modes lisent sont les contrats sur lesquels tous les lots écrivent. Les
fixer avant tout adaptateur évite qu'un format naisse de ce que le premier adaptateur savait faire.

## Décision

### Arborescence (conforme au § 4.4, plus `pending/`)

```
.devtools/
├── config.xml          config.xsd
├── stack.xml           stack.xsd            (ADR-0005)
├── knowledge/                                (ADR-0012)
├── workflows.md                              (ADR-0008)
├── workflows/<type>/<id>.md + <id>.xml       workflow-tracking.xsd
├── index.xml           index.xsd
├── graph/files-to-workflows.xml  files-to-workflows.xsd ; graph/workflows.mermaid
├── schemas/            copie des XSD utilisés, pour qu'un lecteur valide sans DevTools installé
├── pending/            briefs et brouillons IA (ADR-0002) — ignoré par git
└── reports/            un rapport par exécution — ignoré par git
```

`devtools init [path]` crée l'arborescence, un `config.xml` par défaut commenté, et ajoute
`.devtools/pending/` et `.devtools/reports/` au `.gitignore` du projet (sans dupliquer une ligne
existante). `inspect` appelle `init` si le dossier n'existe pas.

### `config.xml`

```xml
<devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1">
  <paths>
    <exclude>vendor</exclude><exclude>node_modules</exclude><exclude>var</exclude>
  </paths>
  <graph depth="3"/>                                   <!-- ADR-0006 -->
  <identifiers route-prefix="app_"/>                   <!-- ADR-0003 -->
  <aliases/>  <groups/>                                <!-- ADR-0003, ADR-0006 -->
  <types/>                                             <!-- types supplémentaires (§ 4.2) -->
  <symfony console="bin/console" env="dev"/>           <!-- ADR-0007 -->
  <language pages="fr"/>                               <!-- langue de rédaction (ADR-0011) -->
</devtools>
```

Chaque élément est optionnel ; un `Config` immuable porte les valeurs par défaut. Le fichier est
**lu, jamais réécrit** par DevTools après `init` : c'est celui de l'humain.

### XML de suivi (`workflow-tracking.xsd`)

Le format du § 4.6.1, avec trois précisions :
- `<generated at tool model mode prompt/>` : `mode` = `ai` | `no-ai` ; `model` et `prompt` absents
  en `no-ai` ;
- `<confidence>high|medium|low</confidence>` et `<producer>native:symfony|claude|…</producer>` dès la
  version 1 du schéma (§ 4.8), pour ne pas incrémenter `schema-version` au premier lot qui s'en sert —
  *le nom `<source>` des specs § 4.6.1 est déjà pris par `<source vcs commit branch dirty>` ; le modèle
  garde `Workflow::source`, le XML écrit `<producer>` (précision du 2026-09-17)* ;
- `<source vcs commit branch dirty>` accepte `vcs="none"` sans commit (projet hors git, fixtures).

### `graph/files-to-workflows.xml`

Index inverse fichier → workflows, avec la **relation** : `relation="file"` (fichier impliqué) ou
`relation="test"` (test existant). Sans la seconde, l'impact d'une modification de test (ADR-0016)
serait vide. Trié par chemin, puis par identifiant.

### Couche `Tracking\`

- `TrackingDocument` (objet), `TrackingReader`, `TrackingWriter`, `IndexWriter`,
  `FilesToWorkflowsWriter`, `ConfigReader` — chacun derrière une interface, pour qu'un backend JSON
  puisse s'ajouter sans toucher au pipeline (§ 4.6.3).
- **Validation XSD à la lecture et à l'écriture.** Un document invalide à l'écriture est un bug de
  DevTools : exception, rien d'écrit.
- **Lecture durcie** : `LIBXML_NONET`, aucune entité externe, aucune DTD chargée ; un
  `<!DOCTYPE>` est refusé. Ces fichiers se modifient par pull request dans le projet analysé
  (`SECURITY.md`).
- **Écriture atomique** : fichier temporaire dans le même dossier, puis `rename`. Un processus
  interrompu ne laisse jamais un XML tronqué.
- **Écriture stable** : indentation, ordre des attributs et des éléments fixes ; réécrire un
  document inchangé produit les mêmes octets.
- `SchemaVersion` : un document de version supérieure à celle que DevTools connaît est refusé avec
  un message qui demande de mettre DevTools à jour.

### Horloge et chemins

`Clock` (interface, `SystemClock`, `FrozenClock` de test) et `ProjectRoot` (racine absolue,
`relative()`/`absolute()`, refus de tout chemin qui en sort, liens symboliques résolus avant
vérification).

Dépendances ajoutées : `ext-dom`, `ext-libxml`, `symfony/filesystem`.

## Budget d'exécution

Validation XSD d'un document de suivi : négligeable devant le hash des fichiers. Les XSD sont chargés
une fois par exécution, pas une fois par document.

## Hors périmètre

Backend JSON ; migration de `schema-version` ; édition de `config.xml` par commande ; lecture des
pages Markdown (ADR-0008).

## Critères d'acceptation

- [x] `devtools init` crée l'arborescence et le `.gitignore` ; relancé, il ne modifie rien
- [x] Round-trip pour chaque type de document : écrire → lire → écrire produit les mêmes octets
- [x] Chaque XSD rejette un document invalide (élément manquant, statut inconnu, chemin absolu)
- [x] Un XML avec `<!DOCTYPE>` ou entité externe est refusé sans tentative de résolution (test avec
      une entité pointant vers un fichier local : son contenu n'apparaît nulle part)
- [x] Une écriture interrompue (exception simulée après le fichier temporaire) laisse l'ancien
      document intact
- [x] Un document de `schema-version` future est refusé avec le message de mise à jour
- [x] `files-to-workflows.xml` distingue `relation="file"` et `relation="test"`
- [x] `ProjectRoot` refuse `../`, un chemin absolu hors racine et un lien symbolique qui en sort

## Conséquences

- Les XSD de `resources/schemas/` sont un contrat public : les modifier de façon incompatible est un
  changement majeur.
- `.devtools/schemas/` duplique des fichiers du paquet ; c'est le prix d'un dossier lisible et
  validable sans DevTools.

## Dépendances

ADR-0003.
