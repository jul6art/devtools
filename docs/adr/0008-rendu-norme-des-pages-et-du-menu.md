# ADR-0008 — Rendu normé des pages, du menu et du graphe (mode factuel)

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 4.3 étapes 7 (`--no-ai`) et 9, § 4.4, § 4.5, § 4.7, § 7.2 (famille rendu), § 12 question 4

## Contexte

Le § 4.5 fixe un gabarit **strict** — titres et ordre des sections fixes, parsables, diffables — et
le § 4.7 un menu régénéré à chaque exécution. En `--no-ai`, « une page factuelle minimale est générée
sans Claude ». Ce rendu factuel est la base sur laquelle Claude écrira (ADR-0011) : il doit exister,
être complet et stable **avant** toute rédaction.

Le rendu ne lit que le modèle (ADR-0003) : il se construit et se teste en parallèle des adaptateurs,
avec des modèles écrits à la main.

## Décision

### Propriété des sections

Un seul gabarit pour tous les types (§ 12 question 4). Chaque section a **un propriétaire** :

| Section | Propriétaire | En mode factuel |
|---|---|---|
| Titre, ligne d'en-tête (`id · type · MAJ · commit`) | DevTools | titre = `Workflow::title` (dérivé du point d'entrée) |
| Résumé | Claude | `—` |
| Déclencheur | DevTools, sauf la ligne « Préconditions » (Claude) | table du § 4.5 : point d'entrée et satellites, sécurité (`EntryPoint::attributes['security']`), préconditions (`—`) |
| Parcours | Claude, avec repli DevTools | `flowchart TD` du graphe de dépendances (Mermaid obligatoire, § 4.5) |
| Navigation / états | DevTools | `flowchart LR` de `navigation` ; `stateDiagram-v2` de `states` ; `—` sinon |
| Composants impliqués | DevTools | table `role · fichier · notes` |
| Données | Claude | `—` |
| Mécanismes transverses | Claude, avec repli DevTools | liste des workflows `dependsOn` de type `events` |
| Points d'attention | Claude | `—` |
| Tests existants | DevTools | table des tests trouvés |
| Workflows liés | DevTools | liens relatifs vers les pages `dependsOn` et navigation |
| Historique | DevTools | une ligne par révision (ADR-0010), cumulée, jamais réécrite |

La table des propriétaires est **dans le code** (`PageSection` enum), pas seulement ici : c'est elle
que la validation des brouillons (ADR-0011) utilisera pour refuser qu'un brouillon touche une section
DevTools.

### Implémentation (namespace `Rendering\`)

- `MarkdownWriter` : construction ligne à ligne en PHP, **sans moteur de template** (aucune
  dépendance) ; échappement des `|` dans les cellules, des backticks dans le code inline.
- `MermaidWriter` : identifiants de nœuds générés (`n1`, `n2`…) et **libellés toujours entre
  guillemets échappés** — un nom de classe avec `\`, une route avec `{id}` ou des crochets casse un
  diagramme non échappé.
- `PageRenderer` : `Workflow` + historique → page ; `PageParser` : page → sections (propriétaire,
  contenu), utilisé pour conserver les sections Claude lors d'une réécriture factuelle d'une page
  déjà rédigée (le repli n'efface jamais une rédaction).
- `MenuRenderer` → `workflows.md` au format du § 4.7 : en-tête (stack, compteur, dernier scan,
  commit), un sous-menu par type **avec son compteur, même à 0**, entrées triées par identifiant,
  sections « À vérifier » (statuts `stale`, `orphaned`, rédactions en attente) et « Non couvert ».
- `GraphRenderer` → `graph/workflows.mermaid` (workflows et `dependsOn`, groupés par type) ; le
  `files-to-workflows.xml` est écrit par `Tracking\` (ADR-0004).
- Liens **relatifs** partout, pour qu'une page se lise sur GitHub comme dans l'IDE.
- Aucune date d'exécution dans une page : la date d'en-tête est celle de la **dernière révision**,
  lue dans le XML — sinon aucune page ne serait stable d'un scan à l'autre.

## Budget d'exécution

Rendu en mémoire, O(taille du modèle) ; une écriture par fichier qui change réellement (comparaison
des octets avant écriture).

## Hors périmètre

Rédaction par Claude (ADR-0011) ; sequenceDiagram factuel (l'ordre des appels n'est pas connu
statiquement) ; validation par `mermaid-cli` ; export statique (§ 4.8) ; langue des titres de
section autre que le français du § 4.5.

## Critères d'acceptation

- [ ] Snapshot de page factuelle pour chaque type de workflow, à partir de modèles écrits à la main
- [ ] Test de conformité : toute page produite contient le titre, la ligne d'en-tête et les
      11 sections `##` du § 4.5, dans l'ordre, et aucune autre ; une section vide contient `—`
- [ ] `PageParser(PageRenderer(x))` restitue chaque section ; une page modifiée à la main dans une
      section DevTools est détectée
- [ ] Une réécriture factuelle d'une page rédigée conserve les sections rédigées par Claude :
      Résumé, Parcours, Données, Mécanismes transverses, Points d'attention
- [ ] Libellés Mermaid piégeux (`App\Controller\X::new`, `/orders/{id}`, `[`, `"`) : échappés
      (cas par cas)
- [ ] Menu : compteurs exacts, sous-menu à 0 présent, ordre stable, « Non couvert » trié
- [ ] Deux rendus du même modèle : mêmes octets

## Conséquences

- Les titres de section français deviennent un contrat de format ; une variante par langue est une
  future ADR.
- Claude ne peut enrichir que ses sections : un fait faux se corrige dans l'adaptateur, pas dans la
  page.

## Dépendances

ADR-0003, ADR-0004.
