# ADR-0036 — Renommage de workflows : `workflows:rename`

- **Statut** : Proposed — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 11 (stabilité des identifiants)

## Contexte

« Un renommage de route change l'identifiant ; prévoir `devtools workflows:rename old new` qui déplace
page + XML + historique » (§ 11). Sans lui, renommer `app_order_new` en `app_order_create` rend
l'ancienne page orpheline et crée une page neuve **sans historique**, sans scénarios, sans retours
rattachés, et coûte une rédaction IA.

## Décision

### Commande explicite

`devtools workflows:rename <ancien-id> <nouvel-id> [--dry-run]` :
- déplace page et XML ; ajoute une révision `reason="renamed from <ancien-id>"` ; conserve l'historique ;
- réécrit toutes les références : `dependsOn` des autres XML, liens des pages (« Workflows liés »),
  `index.xml`, `files-to-workflows.xml`, scénarios (`workflow=`, dossiers), retours, règles, journal ;
- refuse si le nouvel identifiant existe déjà ou si l'ancien n'existe pas.

### Détection proposée

Lors d'un `inspect`, un workflow `orphan` et un workflow `create` du **même type** dont les fichiers
se recouvrent à ≥ 80 % sont présentés comme **renommage probable**, avec la commande à lancer. Jamais
appliqué sans `--apply-renames` : un faux positif fusionnerait deux histoires.

## Budget d'exécution

Réécriture des seuls fichiers qui contiennent l'ancien identifiant (recherche dans les index, pas dans
tout `.devtools/`).

## Hors périmètre

Fusion ou scission de workflows ; renommage d'un type de workflow.

## Critères d'acceptation

- [ ] Renommage sur une fixture complète (page rédigée, scénarios, retour, règle) : toutes les
      références suivent, aucune ne reste (recherche de l'ancien identifiant dans `.devtools/`)
- [ ] Historique conservé, révision ajoutée ; aucune tâche IA créée
- [ ] Refus : cible existante, source absente
- [ ] Détection : route renommée dans le fixture → renommage probable proposé ; recouvrement < 80 % →
      rien
- [ ] `--dry-run` n'écrit rien
- [ ] README : section « Renommer un workflow »

## Conséquences

- La règle « changer un identifiant est cassant » (ADR-0003) garde son sens pour la **dérivation** ;
  pour un renommage de route, il existe désormais un chemin sans perte.

## Dépendances

ADR-0015 ; s'étend aux formats de phase 2 (scénarios, retours, règles) quand ils existent.
