# ADR-0044 — Introspection approfondie : connaissance amendable et logique conditionnelle complète

- **Statut** : Accepted — 2026-09-18 · Livré le 2026-10-20
- **Décideurs** : jul6art
- **Specs** : § 4.3 étape 2, § 4.5, § 12 question 3

## Contexte

Les ADR-0041 et ADR-0043 ont livré deux choses volontairement incomplètes, et ont nommé ce qu'elles
laissaient de côté. L'usage réel — 76 workflows sur cereezer, 7 sur ce dépôt — a montré que ces
manques sont les six mêmes, tous du même côté : **DevTools voit la logique conditionnelle écrite
droit devant lui, et rien de ce qui la porte ailleurs.**

1. Une fiche de connaissance est écrite **une fois pour toutes** : un projet qui exhibe un mécanisme
   qu'elle ne couvre pas n'a aucun moyen de l'y ajouter, alors que la bibliothèque de l'ADR-0041
   existe précisément pour que la correction profite à tous.
2. Un **voter** décide `granted` ou `denied` selon le rôle, le locataire et l'objet. C'est la
   décision la plus lourde de conséquences d'une application, et elle n'apparaît nulle part.
3. Un **formulaire** décide les valeurs qu'un champ peut prendre — `choices` calculés depuis
   l'organisation de l'utilisateur. Un lecteur qui cherche « pourquoi ce site-là et pas l'autre »
   ne le trouve pas.
4. Une **contrainte de validation** (`Assert\Choice`, `Assert\Expression`, `Assert\When`) borne un
   champ avant qu'il n'existe en base. C'est une décision sur la valeur, écrite en attribut.
5. L'extraction est **intra-procédurale** : `$order->setStatus($this->decide($order))` n'enregistre
   rien, parce que la condition est dans `decide()`. Sur cereezer, c'est la forme la plus courante
   du code propre — celle qu'on récompense en revue.
6. Le `stateDiagram` d'une machine à états vit dans « Navigation / états » alors qu'il **est** une
   décision, sur le champ `marking`. L'ADR-0043 l'a laissé là en disant d'attendre d'avoir lu de
   vraies sections « Décisions » avant de fusionner. Elles existent maintenant.

Alternatives écartées : une analyse de flot complète type PHPStan (DevTools n'est pas un analyseur
statique, et le coût par workflow deviendrait le coût du projet entier) ; demander ces décisions à
Claude (un fait ne vient jamais de Claude, ADR-0003).

## Décision

### 1. `knowledge:amend` — une fiche qui se complète

Une fiche de connaissance devient **amendable** :

- `devtools knowledge:amend <clé>` écrit un brief `knowledge` de type `amend`, portant la fiche
  actuelle, la stack, et **ce que l'inspection a rencontré sans le comprendre** : les fichiers de
  « Non couvert », les stacks en repli statique, les adaptateurs absents ;
- le brouillon est la fiche **entière** réécrite, validée par `KnowledgeCanvas` comme une première
  rédaction, plus une règle de plus : aucune section ne rétrécit ;
- l'amendement écrase la fiche du projet, et **est déposé dans la bibliothèque sous un numéro de
  révision** (`angular-18.md` reste, `library.xml` gagne une ligne) — la règle « jamais
  d'écrasement » de l'ADR-0041 tient : une révision n'écrase pas, elle succède.

### 2. Les voters entrent dans les mécanismes

Un voter est un {@see Mechanism} de kind `voter`, rattaché aux workflows dont une route porte son
attribut (`#[IsGranted]`, `denyAccessUnlessGranted`, `access_control`). Ses décisions sont celles de
`voteOnAttribute()` : `granted` ou `denied`, sous la condition écrite. La colonne « Écrit » de
« Mécanismes transverses » devient « Décide », et un voter y lit `accès`.

### 3. Les choix de formulaire et les contraintes de validation

Deux nouvelles sources de `DecisionPoint`, dans l'adaptateur Symfony :

| Source | Cible | Valeurs |
|---|---|---|
| `->add('site', EntityType::class, ['choices' => …, 'query_builder' => …])` | `App\Entity\Asset::site` | ce que l'option calcule, condition = ce dont elle dépend |
| `['required' => $x]`, `['disabled' => $x]` | `App\Form\AssetType::site.required` | vrai / faux, condition = `$x` |
| `#[Assert\Choice([...])]` | la propriété annotée | les valeurs admises, condition = `groups`/`when` s'il y en a |
| `#[Assert\Expression(...)]`, `#[Assert\When(...)]` | la propriété annotée | l'expression, telle qu'écrite |

### 4. L'extraction devient inter-procédurale, à un saut

`DecisionExtractor` suit **un appel de profondeur**, et un seul : quand la valeur décidée est un
appel à une méthode du projet dont le corps est déjà parsé, les conditions de cette méthode
remontent dans la condition du point de décision, préfixées de son nom
(`decide() : null === $vatNumber`). Deux sauts, jamais : au troisième, la condition ne se lit plus.

Les bornes de l'ADR-0043 tiennent (`MAX_DEPTH = 3`, `MAX_POINTS = 50`, `MAX_TARGETS = 8`), et la
profondeur d'appel s'y ajoute comme une quatrième : `MAX_CALL_DEPTH = 1`.

### 5. « Navigation / états » et « Décisions » fusionnent

Le gabarit passe de dix sections à **neuf** :

| Section | Propriétaire |
|---|---|
| Résumé, Parcours, Données, Points d'attention | Claude |
| Déclencheur, Workflows liés, Historique | DevTools |
| **Décisions** | DevTools pour le `stateDiagram` et le `flowchart LR` de navigation, Claude pour les graphiques de champs |
| Mécanismes transverses | DevTools, enrichie par Claude |

La machine à états est le premier diagramme de « Décisions », sous le champ `marking` : c'est la
même question posée du même côté.

## Budget d'exécution

- `knowledge:amend` : une tâche Claude **à la demande**, jamais automatique — l'inspection ne la
  déclenche pas, sinon une fiche changerait à chaque scan.
- Voters, formulaires, contraintes : lus dans les arbres syntaxiques déjà en cache, comme les
  décisions de l'ADR-0043. Zéro analyse supplémentaire.
- Saut d'appel : un corps de méthode de plus par point de décision, borné à un saut et au cache de
  `PhpReferenceExtractor`. **Le test de performance du § 8 (re-scan de 300 routes sous dix secondes)
  est le critère d'arrêt** : si le saut le fait franchir, il est retiré.

## Hors périmètre

L'analyse de flot (quelle valeur une variable porte réellement) ; plus d'un saut d'appel ; les
décisions d'un langage autre que PHP ; la résolution d'un `choices` calculé à l'exécution depuis la
base ; les voters d'un bundle tiers ; la péremption d'une fiche de connaissance ; un format de page
par type de workflow (§ 12 question 4, tranché en ADR-0008 et reconduit en ADR-0043).

## Ce que la livraison a tranché (2026-10-20)

Écarts pris avec le texte ci-dessus, chacun pour une raison que le code a rencontrée :

- **Le saut d'appel ne suit que les méthodes de la classe lue** (`$this->decide()`, `self::`, `static::`).
  Un appel sur un autre objet est enregistré tel qu'écrit : deviner sa classe nommerait une décision sur
  la foi d'un nom. Un `return` dans une boucle n'est pas lu, et l'appel reste alors la valeur. Une branche
  qui retourne rend la suite conditionnelle à son contraire (`!($order->paid) && …`).
- **Les conditions sont imprimées avec les noms complets** que la résolution des noms produit
  (`\Symfony\…\UserInterface`), comme toutes les décisions de l'ADR-0043 ; les réécrire est le travail de
  Claude.
- **Une contrainte `Choice` donne un point par valeur admise**, sans condition hors `groups` : c'est ce
  qui la fait passer le filtre de l'ADR-0043 (un champ à plusieurs valeurs). Une `Expression` donne
  `valid` sous l'expression et `invalid` sous son contraire ; un `When`, ses contraintes sous l'expression
  et celles de `otherwise` sous son contraire.
- **Un champ `required` ou `disabled` conditionnel** est une décision sur le formulaire, nommée
  `App\Form\CustomerType::site.disabled` ; les `choices` d'un champ sans `data_class` restent sur le
  formulaire, en confiance moyenne. La condition d'un `choices` calculé est `depends on …` : les services,
  options et variables qu'il lit.
- **Un voter est découvert à ce qu'il étend** (`Voter` ou `VoterInterface`), ses attributs lus dans
  `supports()` et `supportsAttribute()`, constantes du projet résolues. Un voter qui étend un voter du
  projet n'est pas suivi ; un voter dont aucun attribut ne se lit n'est rattaché à rien et le dit. Sa
  colonne « Event » porte ses attributs ; la colonne « Écrit », devenue « Decides » depuis le passage à
  l'anglais, y lit `access`. `denyAccessUnlessGranted()` entre dans la sécurité de la route, `isGranted()`
  non : il conditionne, il ne ferme pas.
- **Le brief d'amendement s'appelle `knowledge-amend.<clé>.brief.xml`** : l'inspection efface à chaque
  passage les briefs `knowledge.*`, et un amendement demandé doit lui survivre. Le schéma est le même,
  marqué `kind="amend"`. Le repli statique s'y déduit des fichiers de suivi (un workflow d'adaptateur
  natif en confiance moyenne), pas du rapport d'inspection, qui reste local.
- **Une révision s'écrit `<clé>.r<N>.md`** et `library.xml` porte `revision="N"` ; l'unicité y est tenue
  sur le couple clé et révision, celle de l'original par le code — un schéma ne teste pas un attribut absent.
- **La fixture `symfony-legacy-yaml` porte aussi la fonctionnalité client** : c'est « la même
  application déclarée à l'ancienne », et le test de la console réelle exige les mêmes workflows.
- ⚠️ **Le gabarit a changé, et c'est cassant** : toute page est réécrite à la première inspection. Une
  version majeure est une décision humaine, comme le passage à l'anglais l'a été.

## Critères d'acceptation

- [x] `knowledge:amend` : brief de type `amend` portant la fiche actuelle et ce que l'inspection n'a
      pas compris ; brouillon validé par le canevas ; refus quand une section rétrécit
      *(`KnowledgeAmendTest` : brief, survie à une inspection, canevas, rétrécissement nommé)*
- [x] Une fiche amendée est déposée en **révision** dans la bibliothèque, sans écraser la précédente,
      et `library.xml` porte les deux *(`testAnAcceptedAmendmentRewritesTheSheetAndIsDepositedAsARevision` ;
      un projet suivant lit la révision ; la même fiche une seconde fois n'en ajoute pas)*
- [x] Un voter apparaît en mécanisme des workflows qui portent son attribut, et **pas** des autres
      (mutant) ; ses décisions sont `granted` / `denied` avec leur condition *(`SymfonyAdapterTest`,
      `MechanismAttachmentTest`, `DecisionExtractorTest::testAVoterDecidesAccessGrantedOrDenied` ; mutants
      « rattaché partout », « sécurité non transmise » et « corps de route ignoré » tués)*
- [x] Un `EntityType` dont les `choices` dépendent de l'utilisateur produit un point de décision sur
      la propriété de l'entité, pas sur le formulaire *(`App\Entity\Customer::site`, test et fixture)*
- [x] `Assert\Choice`, `Assert\Expression` et `Assert\When` produisent chacun un point de décision,
      un test par contrainte *(fournisseur `constraints`, un cas chacune, plus `callback` et `otherwise`)*
- [x] `$order->setStatus($this->decide($order))` enregistre les conditions de `decide()`, préfixées ;
      un second saut n'est pas suivi (mutant sur `MAX_CALL_DEPTH`) *(mutants 0 et 2 tués)*
- [x] Le re-scan de 300 routes reste sous dix secondes avec le saut d'appel actif *(0,38 à 0,40 s)*
- [x] Le gabarit a **neuf** sections, le `stateDiagram` est dans « Décisions », et une page au gabarit
      précédent (dix sections) est détectée et réécrite *(`PageRendererTest` ; les quinze pages de ce
      dépôt et les neuf de la démonstration migrées, prose intacte)*
- [x] `PageDraftValidator` refuse un brouillon qui réécrit le `stateDiagram` de DevTools *(et le
      séparateur des deux parts ; cas de refus dédiés)*
- [x] La matrice de bout en bout rejouée sur les cinq projets-fixtures, snapshots régénérés, et un
      fixture porte un voter, un formulaire à choix conditionnels et un appel indirect *(sept variantes
      de la matrice, instantanés relus ; `symfony-minimal` et `symfony-legacy-yaml` portent la route
      `app_customer_edit`)*

## Conséquences

- **Deuxième changement cassant du gabarit en deux lots.** Toutes les pages sont réécrites une fois
  de plus. C'est le coût d'avoir attendu de lire de vraies sections « Décisions » avant de fusionner,
  et il est payé une seule fois.
- Le saut d'appel rend la condition d'un point de décision **composite** : elle cite deux endroits du
  code. Le prompt doit dire à Claude de nommer les deux.
- Un voter documenté par workflow rend visible une erreur de sécurité que rien ne rendait visible : un
  écran ouvert parce que le code de permission est mal orthographié (`claude_core.md` § 12). C'est le
  gain principal de ce lot, et il ne sera pas mesurable avant de l'avoir sur un vrai projet.
- L'ADR-0043 n'est pas remplacée : ce lot l'étend. Le gabarit à neuf sections y est réénoncé, et
  l'ADR-0043 passera `Superseded by ADR-0044` **le jour où ce lot démarre**, pas avant.

## Dépendances

ADR-0041, ADR-0043. Aucune ADR de la phase 1 ne l'attend : elle peut glisser.
