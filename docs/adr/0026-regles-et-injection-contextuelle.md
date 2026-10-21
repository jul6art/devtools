# ADR-0026 — Promotion en règles et injection contextuelle

- **Statut** : Accepted — 2026-10-20 (proposée le 2026-09-16 ; phase 2 lancée par jul6art) · Livré le 2026-10-20
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

### Précisions à l'acceptation (2026-10-20)

- **Format.** `<stats>` ne figure pas dans le fichier de la règle : les compteurs vivent dans
  **`.devtools/stats/rules.xml`** (`rule-stats.xsd`) et non `rules/stats.xml` — une zone de travail ignorée est
  un dossier du `.gitignore` (`/.devtools/stats/`, qu'`init` ajoute), pas un fichier perdu dans un dossier
  versionné. `<assertion ref>` n'apparaît qu'une fois l'assertion écrite (ADR-0027), jamais vide. `<category>`
  est facultative et sert à regrouper, jamais à faire correspondre. L'instruction est un `xs:token` (espaces
  repliés : écrite sur plusieurs lignes, c'est un seul texte), 600 caractères au plus.
- **`applies-to`** : d'un seul genre de critère, l'un quelconque ; des deux genres, un de chaque — des globs **et**
  un type de workflow désignent un fichier de ce type de workflow. Globs relatifs à la racine : `*` et `?`
  restent dans un dossier, `**` les traverse (`**/` vaut aussi zéro dossier), `{a,b}` l'un ou l'autre ; un
  segment `..` est refusé.
- **Les phrases** se comptent ainsi : une fin de phrase est `.`, `!`, `?` ou `…` suivi d'une majuscule ou de la
  fin du texte, et un texte qui ne finit pas par l'un d'eux finit tout de même une phrase. « p. ex. select2 »
  ne coupe pas ; une abréviation suivie d'une majuscule, si — le refus le dit, la règle se reformule.
- **Index** `rules/index.xml` (`rule-index.xsd`) : il porte le champ d'application et l'instruction de chaque
  règle ; le hook ne lit que lui.
- **Promotion.** Le brief `pending/rule.<premier retour>.brief.xml` (`rule-brief.xsd`, prompt `rule/v1`) donne
  les retours, les fichiers qu'ils visent — cible et occurrences — et ceux de leurs workflows (carte
  `files-to-workflows`, relation `file`), les types de workflow et les catégories que la règle peut nommer, les
  règles existantes. `workflows:apply` refuse un brouillon dont le statut n'est pas `proposed`, dont l'origine
  n'est pas exactement les retours du brief, dont l'identifiant est pris, qui porte une assertion, dont un
  retour a été promu ou écarté entre-temps, ou que `RuleChecks` refuse : plus de trois phrases, glob hors
  racine ou ne correspondant à aucun fichier du projet, type de workflow ou catégorie inconnus. « Fichier du
  projet » : ceux que git connaît (suivis, ou nouveaux et non ignorés), à défaut ceux du disque, jamais sous un
  dossier exclu (`Project\ProjectFiles`). `created` vaut la date du brief.
- **Les retours passent `promoted` dès l'application** — la règle `proposed` — et non à l'activation : un retour
  promu ne déclenche plus de proposition de promotion, et une troisième occurrence ne produit pas une
  deuxième règle pendant que la première attend l'humain.
- **`rules:activate`** revérifie la règle (le projet a pu bouger : un glob qui ne correspond plus à rien refuse
  l'activation) ; une règle archivée se réactive de la même façon. `rules:list` montre les règles proposées et
  actives (`--status` pour une seule), avec leurs compteurs.
- **Injection.** Ordre : le glob correspondant le plus écrit (caractères hors jokers), puis la règle qui
  demande fichier **et** type, puis celle qui nomme le moins de types, puis l'identifiant. Les règles données
  incrémentent `applied` (et `last-applied`) ; celles écartées par la limite de 5, non. Un index illisible
  donne une ligne d'avertissement et l'impact reste donné ; des statistiques illisibles repartent de zéro.
  Mesuré : 84 ms de bout en bout sur 300 routes avec 40 règles actives (budget 300 ms).

## Budget d'exécution

Correspondance glob sur un index des règles chargé une fois ; reste dans le budget de 300 ms du hook.
Au plus **5 règles** injectées par appel (les plus spécifiques d'abord), pour ne pas recréer le mur de
texte qu'on veut éviter.

## Hors périmètre

Assertion mécanique (ADR-0027) ; promotion automatique sans humain ; règles partagées entre projets.

## Critères d'acceptation

- [x] `rule.xsd` : exemple valide ; règle sans `applies-to`, instruction de quatre phrases rejetées *(le schéma
      refuse une règle sans fichier ni type, sans retour d'origine, sans instruction, au glob absolu ; les
      quatre phrases sont refusées à l'application, comme tout ce que `RuleChecks` vérifie)*
- [x] Promotion par brouillon enregistré ; glob ne correspondant à rien → refus *(et onze autres refus — dont brief
      absent, retour écarté entre-temps —, rien d'écrit, le brouillon gardé pour correction)*
- [x] `proposed` jamais injectée ; `active` injectée sur le bon fichier, pas sur un autre
- [x] Au plus 5 règles injectées, les plus spécifiques *(ordre vérifié sur neuf règles actives et une proposée)*
- [x] Retours d'origine marqués `promoted`
- [x] README : section « Règles » *(« Rules »)*

## Conséquences

- Une règle ne coûte du contexte que là où elle sert ; son efficacité se mesure (ADR-0029), ce que
  personne ne pouvait faire d'une ligne dans un fichier d'apprentissage.

## Dépendances

ADR-0019, ADR-0025.
