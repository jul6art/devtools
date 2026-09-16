# ADR-0026 — Promotion en règles et injection contextuelle

- **Statut** : Proposed — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 3.1, § 5.1 (étape 2), § 5 (schémas « à réviser »)

## Contexte

Les ADR, checklists et journaux d'apprentissage existent déjà et les erreurs reviennent quand même
(§ 1.2) : une règle noyée dans un long fichier relu à chaque session pèse sur le contexte et ne
s'applique pas au bon moment. Le § 5.1 veut une règle « injectée à Claude Code **uniquement quand les
fichiers touchés sont concernés** ».

## Décision

### Format (`.devtools/rules/<id>.xml`, `rule.xsd`)

```xml
<rule xmlns="https://github.com/jul6art/devtools/schema/rule/1" schema-version="1"
      id="rule-product-fields-use-select2" status="active" created="2026-09-21">
  <origin feedback="fb-2026-09-20-order-product-select2 fb-2026-09-21-invoice-product-select2"/>
  <applies-to>
    <files glob="templates/**/_form.html.twig"/>
    <workflow-type>routes</workflow-type>
    <category>ui-component</category>
  </applies-to>
  <instruction>Un champ de sélection d'entité à plus de 20 options utilise select2 (Select2Type).</instruction>
  <assertion ref=""/>                         <!-- ADR-0027 -->
  <stats applied="0" blocked="0" last-blocked=""/>
</rule>
```

- `instruction` : **une à trois phrases**, prescriptives. Une règle qui a besoin d'un paragraphe est
  deux règles.
- `applies-to` : globs de fichiers et/ou types de workflows ; au moins un critère — une règle qui
  s'applique partout appartient au `CLAUDE.md` du projet, pas ici.

### Promotion

`devtools rules:promote <feedback-id>…` : tâche IA `rule` (ADR-0002) — brief = les retours, leurs
cibles, les fichiers concernés ; brouillon = une règle ; `apply` valide XSD, globs qui correspondent à
au moins un fichier existant, instruction de trois phrases au plus. Validée par l'humain (statut
`proposed` → `active` par `rules:activate`). Les retours d'origine passent `promoted`.

### Injection

`claude:hook PreToolUse` (ADR-0019) : en plus de l'impact, les règles **actives** dont `applies-to`
correspond au fichier visé ou à un workflow impacté sont ajoutées au contexte — texte de l'instruction
et identifiant, rien d'autre. `stats/@applied` incrémenté (dans `rules/stats.xml`, ignoré par git, pour
ne pas créer de diff à chaque modification).

## Budget d'exécution

Correspondance glob sur un index des règles chargé une fois ; reste dans le budget de 300 ms du hook.
Au plus **5 règles** injectées par appel (les plus spécifiques d'abord), pour ne pas recréer le mur de
texte qu'on veut éviter.

## Hors périmètre

Assertion mécanique (ADR-0027) ; promotion automatique sans humain ; règles partagées entre projets.

## Critères d'acceptation

- [ ] `rule.xsd` : exemple valide ; règle sans `applies-to`, instruction de quatre phrases rejetées
- [ ] Promotion par brouillon enregistré ; glob ne correspondant à rien → refus
- [ ] `proposed` jamais injectée ; `active` injectée sur le bon fichier, pas sur un autre
- [ ] Au plus 5 règles injectées, les plus spécifiques
- [ ] Retours d'origine marqués `promoted`
- [ ] README : section « Règles »

## Conséquences

- Une règle ne coûte du contexte que là où elle sert ; son efficacité se mesure (ADR-0029), ce que
  personne ne pouvait faire d'une ligne dans un fichier d'apprentissage.

## Dépendances

ADR-0019, ADR-0025.
