# ADR-0025 — Retours typés et détection de récurrence

- **Statut** : Accepted — 2026-10-20 (proposée le 2026-09-16 ; phase 2 lancée par jul6art) · Livré le 2026-10-20
- **Décideurs** : jul6art
- **Specs** : § 1.2, § 1.3, § 2 (P2), § 3.1 (module Feedback & Rules), § 5.1 (étape 1), § 5 (schémas « à réviser »)

## Contexte

« Tant qu'un retour reste une phrase, il se dilue » (§ 1.3). Les mêmes remontées reviennent parce
qu'elles sont stockées en prose et relues de mémoire. Un retour doit être une **donnée** : rattaché à
un workflow, à un élément ou un fichier, rangé dans une catégorie fermée, compté.

⚠️ Le § 5 conserve des schémas `feedback` de la version 0.1 « à réviser une fois le module Workflows
livré ». **Cette ADR est cette révision** : elle s'écrit contre les identifiants de workflow et le
vocabulaire du MVP. Elle se relit, et au besoin se remplace, une fois la phase 1 livrée.

## Décision

### Format (`.devtools/feedback/<AAAA>/<id>.xml`, `feedback.xsd`)

```xml
<feedback xmlns="https://github.com/jul6art/devtools/schema/feedback/1" schema-version="1"
          id="fb-2026-09-20-order-product-select2" status="open">
  <reported at="2026-09-20T10:12:00+02:00" by="human|verifier" commit="a1b2c3d"/>
  <target workflow="route.order.new" selector="#order_product" file="templates/order/_form.html.twig"/>
  <category>ui-component</category>
  <summary>Le champ produit doit être un select2, pas un select natif.</summary>
  <occurrences>
    <occurrence at="2026-09-20T10:12:00+02:00" workflow="route.order.new" commit="a1b2c3d"/>
  </occurrences>
  <promoted-to rule=""/>
</feedback>
```

- **Une ligne** de résumé (§ 5.4) : 200 caractères au plus, refus au-delà.
- `target` : au moins un de `workflow`, `selector`, `file` ; chacun vérifié (workflow existant,
  fichier dans la racine).
- **Catégories fermées**, dans `resources/feedback/categories.xml`, extensibles par `config.xml` :
  `ui-component`, `filter`, `validation`, `navigation`, `permission`, `translation`, `layout`,
  `performance`, `data-display`, `error-handling`. Une catégorie sert à regrouper, pas à décrire.
- `status` : `open | fixed | promoted | wont-fix`.

### Commandes

- `devtools feedback:add --workflow= --selector= --file= --category= "<résumé>"` — et le même en
  interactif.
- `devtools feedback:list [--status] [--category] [--workflow]`.
- `devtools feedback:resolve <id> --status=fixed|wont-fix`.

### Récurrence

À l'ajout, `Feedback\RecurrenceDetector` cherche un retour existant de **même catégorie** et de cible
proche : même sélecteur, ou même fichier, ou workflows de même type partageant un composant. Trouvé →
proposition (humain) ou décision (`--auto`) d'**ajouter une occurrence** au lieu de créer un doublon.
La 2ᵉ occurrence déclenche la proposition de promotion (ADR-0026).

### Précisions à l'acceptation (2026-10-20)

- **Format.** Chaque `<occurrence>` porte aussi `by`, et la cible où le retour a été revu (`workflow`,
  `selector`, `file`, facultatifs) ; `<promoted-to rule="…"/>` n'apparaît qu'une fois le retour promu, jamais
  vide. Au moins une occurrence : la première est le signalement lui-même. L'identifiant vaut
  `fb-<date>-<ce que vise le retour>` (sélecteur, sinon nom du fichier, sinon workflow, réduits à des mots), suffixé
  `-2`, `-3`… quand il est pris.
- **Ce que le schéma ne peut pas dire**, `FeedbackRecorder::refusals()` le dit avant toute écriture : une cible
  non vide, une catégorie de la liste, un workflow de l'index, un fichier existant dans la racine. Tout fichier
  écrit est revalidé contre `feedback.xsd` ; `reported commit` vaut `HEAD`, absent hors git.
- **La liste des catégories** (`resources/feedback/categories.xml`, `feedback-categories.xsd`) est **celle du
  vérificateur** (ADR-0024) : le manifeste de vérification la porte, et une catégorie ajoutée par le projet
  vaut pour ses constats comme pour ses retours. Une catégorie de DevTools redéclarée n'est pas doublée.
- **Récurrence.** Même catégorie seulement ; proximité notée : même sélecteur 4, même fichier 3, même workflow
  2, workflows de même type rendant un même composant (template, composant front, lu dans
  `graph/files-to-workflows.xml`) 1 ; à égalité, le retour le plus vu. Un retour `wont-fix` n'est pas
  cherché ; un retour `fixed` revu est rouvert (`open`). Sans personne à qui demander, ni `--auto` ni
  `--new` : la commande s'arrête (code 1) en nommant le retour trouvé — elle ne tranche pas à la place de
  l'humain.
- **`--by=verifier`** enregistre un constat du vérificateur comme retour ; la conversion automatique des
  constats reste hors périmètre.
- **Le dossier `.devtools/feedback/` est versionné** : un retour se partage comme le code qu'il vise.
  `index.xml` (`feedback-index.xsd`) porte le résumé et la cible de chaque retour, triés par identifiant :
  `list` et la récurrence ne lisent que lui.

## Budget d'exécution

Détection : lecture de l'index des retours (`feedback/index.xml`), jamais un parcours des fichiers.

## Hors périmètre

Promotion (ADR-0026) ; saisie depuis le navigateur (ADR-0028) ; similarité sémantique par IA des
résumés ; import depuis un gestionnaire de tickets.

## Critères d'acceptation

- [x] `feedback.xsd` : exemple valide ; résumé trop long, cible vide, catégorie inconnue, workflow
      inexistant rejetés *(l'exemple se relit et se réécrit à l'identique ; le schéma refuse résumé de deux
      lignes ou trop long, statut inconnu, aucune occurrence ; cible vide, catégorie inconnue, workflow
      inexistant, fichier absent ou hors racine refusés avant écriture)*
- [x] `feedback:add` puis `list` et `resolve` : un test chacun *(et les refus de chaque commande, et la saisie
      interactive)*
- [x] Récurrence : même sélecteur et catégorie → occurrence ajoutée ; catégorie différente → nouveau
      retour *(et sans `--auto` ni personne à qui demander : arrêt, rien d'écrit)*
- [x] 2ᵉ occurrence → proposition de promotion affichée
- [x] `index.xml` des retours stable à l'octet après relecture
- [x] README : section « Retours » *(« Feedback »)*

## Conséquences

- Plus aucun retour en prose libre dans le chat (§ 5.4) : c'est une discipline d'équipe, que l'outil
  rend moins chère que la prose — sans pouvoir l'imposer.

## Dépendances

ADR-0023 (phase 1 livrée).
