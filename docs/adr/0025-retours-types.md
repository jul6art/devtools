# ADR-0025 — Retours typés et détection de récurrence

- **Statut** : Proposed — 2026-09-16
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

## Budget d'exécution

Détection : lecture de l'index des retours (`feedback/index.xml`), jamais un parcours des fichiers.

## Hors périmètre

Promotion (ADR-0026) ; saisie depuis le navigateur (ADR-0028) ; similarité sémantique par IA des
résumés ; import depuis un gestionnaire de tickets.

## Critères d'acceptation

- [ ] `feedback.xsd` : exemple valide ; résumé trop long, cible vide, catégorie inconnue, workflow
      inexistant rejetés
- [ ] `feedback:add` puis `list` et `resolve` : un test chacun
- [ ] Récurrence : même sélecteur et catégorie → occurrence ajoutée ; catégorie différente → nouveau
      retour
- [ ] 2ᵉ occurrence → proposition de promotion affichée
- [ ] `index.xml` des retours stable à l'octet après relecture
- [ ] README : section « Retours »

## Conséquences

- Plus aucun retour en prose libre dans le chat (§ 5.4) : c'est une discipline d'équipe, que l'outil
  rend moins chère que la prose — sans pouvoir l'imposer.

## Dépendances

ADR-0023 (phase 1 livrée).
