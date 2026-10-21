# ADR-0036 — Renommage de workflows : `workflows:rename`

- **Statut** : Accepted — 2026-10-21 (proposée le 2026-09-16 ; compléments lancés par jul6art le 2026-10-21) · Livré le 2026-10-21
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

- [x] Renommage sur une fixture complète (page rédigée, scénarios, retour, règle) : toutes les
      références suivent, aucune ne reste (recherche de l'ancien identifiant dans `.devtools/`) *(la
      documentation rédigée de `symfony-minimal`, un scénario, sa baseline et un retour ; les règles et le
      journal suivent par le même remplacement)*
- [x] Historique conservé, révision ajoutée ; aucune tâche IA créée
- [x] Refus : cible existante, source absente *(et changement de type)*
- [x] Détection : route renommée dans le fixture → renommage probable proposé ; recouvrement < 80 % →
      rien *(une page PHP renommée, cinq fichiers sur six partagés ; `--apply-renames` l'applique)*
- [x] `--dry-run` n'écrit rien
- [x] README : section « Renommer un workflow »

## Écarts assumés à la livraison

- **Recouvrement = fichiers communs / le plus grand des deux ensembles**, même type obligatoire ; un workflow
  disparu ne s'apparie qu'au meilleur candidat, et un candidat qu'à un seul disparu.
- **Les fichiers relus sont ceux des dossiers qui portent des identifiants** (`index.xml`, `graph/`,
  `workflows/`, `scenarios/`, `feedback/`, `rules/`, `assertions/`, `journal/`) et les pages ; seuls ceux qui
  contiennent l'identifiant sont réécrits. Les espaces de travail (`pending/`, `runs/`, `gate/`, `reports/`,
  `stats/`) se refont au prochain passage.
- **La page n'est pas réécrite** : son historique en Markdown attend la prochaine réécriture de ses faits,
  la révision est dans le XML de suivi.

### Mutation (2026-10-21)

Infection couvre `src/Tracking/Rename/` avec le reste de `Tracking` : 918 mutants tués, 97 %. Les tests
qu'il a demandés ont trouvé un défaut — le dossier `baselines/<ancien>/` restait, vide, après le
renommage —, et deux simplifications : un renommage garde le type et le groupe, donc le dossier de la page,
si bien que seuls les liens vers l'ancienne page bougent ; les dossiers propres au workflow (`scenarios/`,
`baselines/`) sont retirés en entier une fois leurs fichiers déplacés. Quatre mutants restent, équivalents :

- `RenameDetector`, `max(…, 1)` changé en `max(…, 0)` ou `max(…, 2)` : le 1 ne protège que d'un fichier de
  suivi édité à la main sans aucun fichier ; tout workflow suit au moins son fichier d'entrée, et deux
  workflows qui partagent un fichier unique n'ont pas le même.
- `WorkflowRenamer::read()` et `RenamePlan::copy()`, le transtypage de `file_get_contents()` retiré : les
  chemins lus viennent d'un parcours du disque fait juste avant, et ne manquent pas.

## Conséquences

- La règle « changer un identifiant est cassant » (ADR-0003) garde son sens pour la **dérivation** ;
  pour un renommage de route, il existe désormais un chemin sans perte.

## Dépendances

ADR-0015 ; s'étend aux formats de phase 2 (scénarios, retours, règles) quand ils existent.
