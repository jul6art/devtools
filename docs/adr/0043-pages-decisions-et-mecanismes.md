# ADR-0043 — Pages : les faits au XML, les listeners en mécanismes, les décisions en graphiques

- **Statut** : Accepted — 2026-09-18
- **Décideurs** : jul6art
- **Specs** : § 4.2, § 4.5, § 4.7, § 12 question 4
- **Remplace** : ADR-0008, passée `Superseded by ADR-0043`

## Contexte

Le MVP a produit 84 pages sur cereezer. Trois défauts se lisent sur la page
`docs/workflows/routes/admin.asset.md` :

**1. La page est un inventaire.** Elle fait 344 lignes, dont **250 de tables « Composants
impliqués » et « Tests existants »** — 88 fichiers et 160 tests, listés un par un. Sur les
84 pages : 17 741 lignes, dont 11 776 de lignes de tableau. Ces faits sont **déjà** dans
`.devtools/workflows/routes/admin.asset.xml` (`<files sha256=…>`, `<tests>`), qui est le fichier qui
fait le lien avec le workflow et que la fraîcheur lit. Les répéter en Markdown n'apporte rien à un
lecteur humain et noie les cinq sections qu'il vient chercher.

**2. Un listener n'est pas un workflow.** Le menu de cereezer ouvre une section « Événements » de
huit entrées (`event.locale-listener`, `event.soft-delete-filter-listener`…). Personne n'ouvre la
page d'un subscriber. Ce qu'on veut savoir d'un listener, c'est **ce qu'il peut faire pendant un
workflow précis** — et cela se lit depuis la page du workflow, pas depuis la sienne.

**3. Les diagrammes ne répondent pas à la bonne question.** « Parcours » en mode factuel dessine
l'éventail des fichiers atteints, c'est-à-dire la table qu'on vient de supprimer. « Navigation »
liste `admin_asset → admin_asset_edit`, que le menu dit déjà. Le seul diagramme qui répond
aujourd'hui à « pourquoi cette valeur-là et pas l'autre » est le `stateDiagram` d'un workflow
d'états. **C'est ce genre-là qu'il faut généraliser** : quand une logique décide qu'un champ vaut
`A` plutôt que `B`, c'est là qu'un graphique vaut mieux qu'un paragraphe.

Alternatives écartées : laisser la table et la replier derrière un `<details>` (le poids reste, la
fraîcheur aussi) ; supprimer les diagrammes (ce sont eux qu'on veut garder) ; extraire les décisions
par exécution du code (DevTools n'exécute jamais le projet analysé).

## Décision

### Le gabarit — dix sections

| Section | Propriétaire | Sans IA (`--no-ai`) |
|---|---|---|
| Titre, ligne d'en-tête (`id · type · MAJ · commit`) | DevTools | inchangé |
| Résumé | Claude | `—` |
| Déclencheur | DevTools, ligne « Préconditions » à Claude | inchangé |
| Parcours | Claude | `—` — **plus de repli factuel** |
| Navigation / états | DevTools | inchangé (`flowchart LR` + `stateDiagram-v2`) |
| **Décisions** | Claude, à partir des `<decisions>` du modèle | `—` |
| Données | Claude | `—` |
| Mécanismes transverses | DevTools, enrichissable par Claude | les mécanismes rattachés au workflow |
| Points d'attention | Claude | `—` |
| Workflows liés | DevTools | inchangé |
| Historique | DevTools | inchangé |

**Disparaissent** : « Composants impliqués », « Tests existants », et la ligne « Paquets : … » qui
suivait la première. Les trois restent dans le XML de suivi, seul endroit où ils vivent désormais.

Le `stateDiagram` d'un workflow d'états est lui-même une décision sur le champ `marking` ; fusionner
« Navigation / états » et « Décisions » est un candidat pour une ADR ultérieure, **une fois qu'on
aura lu de vraies sections « Décisions »** — pas avant.

### Les points de décision — ce que DevTools extrait

`Inspection\Model\DecisionPoint` : `target` (`App\Entity\Invoice::vatCategory`, ou
`App\Service\VatSuggester::suggest` pour un retour), `condition` (la condition telle qu'écrite,
ré-imprimée depuis l'arbre), `value` (l'expression affectée, ré-imprimée), `file`, `line`.
`Workflow::$decisions` est une `list<DecisionPoint>` triée au constructeur comme le reste.

`Inspection\Graph\DecisionExtractor` les lit **dans les arbres syntaxiques déjà en cache**
(`PhpReferenceExtractor::syntaxTree()`), sur les fichiers du workflow dont le rôle n'est ni
`config` ni `test` :

1. une écriture de propriété sous condition : `$e->setX(V)` ou `$e->x = V` dans une branche d'un
   `if`/`elseif`/`else`, d'un `match`, d'un ternaire ou d'un `??` ;
2. un `return` de constante de classe ou de cas d'énumération sous la même condition ;
3. la valeur par défaut d'une propriété typée et ce que le constructeur y pose — la branche
   « aucune condition ».

La cible se résout par le type du receveur quand il est connu (`NameResolver` : paramètre typé,
propriété typée, `new X`) ; sinon le nom de la variable, et le point est marqué de confiance
moindre.

Bornes : trois conditions imbriquées au plus, cinquante points par workflow au plus — au-delà,
l'extraction s'arrête et le rapport le dit.

Les points entrent dans `inspection-model.xsd` et `workflow-tracking.xsd` :

```xml
<decisions>
  <decision target="App\Entity\Invoice::vatCategory" value="VatCategory::REVERSE_CHARGE"
            condition="'LU' !== $address-&gt;getCountry()"
            file="src/Billing/VatSuggester.php" line="47"/>
</decisions>
```

Aucune nouvelle raison de fraîcheur : une décision ne change pas sans que son fichier change, et le
`sha256` de ce fichier tranche déjà (ADR-0010).

### Le diagramme — DevTools liste, Claude dessine

Le brief `page` gagne la table des points de décision du workflow (champ, condition, valeur,
`fichier:ligne`) ; le prompt passe en `page/v2.md` et demande **un `flowchart TD` par champ
décidé**, conditions reformulées en langue métier, précédé du champ en code inline :

    **`App\Entity\Invoice::vatCategory`**

    ```mermaid
    flowchart TD
      d1{"le client n'a pas de numéro de TVA"}
      d1 -->|oui| v1["vatCategory = STANDARD"]
      d1 -->|non| d2{"le pays sort du Luxembourg"}
      d2 -->|oui| v2["vatCategory = REVERSE_CHARGE"]
      d2 -->|non| v1
    ```

`PageDraftValidator` refuse la section quand : elle n'est pas vide et ne contient aucun bloc
`mermaid` ; elle nomme en code inline un `Classe::membre` absent de `<decisions>` ; un champ décidé
du modèle n'y est pas nommé. Les **conditions** ne sont pas comparées littéralement — les reformuler
est précisément le travail demandé.

### Les listeners deviennent des mécanismes

`Inspection\Model\Mechanism` : `kind` (`listener`, `subscriber`, `middleware`, `doctrine-filter`),
`name` (la classe), `event`, `priority`, `declaredIn`, et les `DecisionPoint` qu'il porte — ce qu'il
peut écrire pendant le workflow. `Workflow::$mechanisms` est triée comme les autres listes.

`SymfonyAdapter` cesse d'émettre des candidats `WorkflowType::events()` et **rattache** :

| Événements | Rattachés à |
|---|---|
| `kernel.*` | tous les workflows `routes` |
| `console.*` | tous les workflows `commands` |
| middleware Messenger | tous les workflows `async` |
| événements Doctrine ciblés (`#[AsEntityListener(entity: X::class)]`) | les workflows dont les fichiers contiennent `X` |
| événements Doctrine non ciblés, filtres SQL | tous les workflows qui traversent une entité |

Conséquences directes : `WorkflowType::NATIVE` **perd `events`** — écart assumé avec le § 4.2 des
specs, qui le liste ; un projet qui en veut un le déclare en type personnalisé dans `config.xml`.
Les identifiants `event.*` disparaissent, leurs pages et leurs XML deviennent orphelins au premier
scan, et `--prune` les supprime. Les `dependsOn` vers un `event.*` disparaissent aussi.

### Le menu

Un type natif **sans aucune entrée** ne prend plus de section : il est nommé en une ligne à la fin,
`Aucun workflow trouvé pour : Interface, Intégrations.` Le reste du § 4.7 ne bouge pas — compteurs,
troisième niveau par famille au-delà de douze entrées, « À vérifier », « Non couvert ».

## Budget d'exécution

Aucune analyse syntaxique supplémentaire : `DecisionExtractor` lit les arbres que le graphe de
dépendances a déjà construits et mis en cache. L'extraction est bornée (trois niveaux, cinquante
points) pour qu'un contrôleur de mille lignes ne fasse pas exploser le modèle. Le brief grossit
de la table des décisions ; les pages rétrécissent des deux tiers, donc le total écrit baisse.

## Hors périmètre

Les voters (`granted`/`denied` par attribut) — ils relèvent de « Mécanismes transverses », et
demandent leur propre extraction ; les choix de formulaire dépendant de l'utilisateur ; les
contraintes de validation (`Assert\Choice`, `Assert\Expression`) ; l'extraction
**inter-procédurale** (une condition posée dans une méthode appelée par une autre) ; les décisions
dans un langage autre que PHP ; la fusion de « Navigation / états » et « Décisions » ; toute
variante de gabarit par type de workflow (§ 12 question 4 : un seul gabarit, décision de
l'ADR-0008 reconduite).

## Critères d'acceptation

- [x] Test de conformité : toute page produite contient le titre, la ligne d'en-tête et les
      **10** sections `##`, dans l'ordre, et aucune autre ; une section vide contient `—`
- [x] Une page rédigée relue par `PageParser` restitue chaque section ; une page au gabarit
      précédent (11 sections) est détectée et réécrite
- [x] `--no-ai` : « Parcours » et « Décisions » valent `—`, « Mécanismes transverses » liste les
      mécanismes rattachés
- [x] `DecisionExtractor` : un test par forme (`if/else`, `elseif`, `match`, ternaire, `??`, retour
      d'énumération, défaut de propriété), sur un fixture PHP écrit pour ça
- [x] Bornes : un fichier à quatre conditions imbriquées s'arrête à trois ; un workflow à soixante
      décisions en garde cinquante et le rapport le dit — vérifiés par mutation de la borne
- [x] Une cible non résoluble est enregistrée en confiance moindre, pas inventée ni omise en silence
- [x] `PageDraftValidator` : un brouillon citant `App\Entity\X::inexistant` est refusé ; un
      brouillon omettant un champ décidé est refusé ; un brouillon sans bloc `mermaid` est refusé
      — un document invalide dédié par branche (journal 2026-09-16)
- [x] Les mécanismes : un test de rattachement par ligne de la table ; un listener Doctrine ciblé ne
      se rattache **pas** à un workflow qui ne traverse pas son entité (mutant)
- [x] Plus aucun workflow de type `events` n'est produit par `SymfonyAdapter` ; les pages existantes
      passent `orphaned` puis se suppriment au `--prune`
- [x] Menu : un type natif vide ne prend pas de section, et est nommé dans la ligne de fin
- [x] Deux rendus du même modèle : mêmes octets ; deux inspections consécutives sans changement :
      aucun fichier modifié hors `reports/`
- [x] Les XSD `inspection-model` et `workflow-tracking` compilent (`schemaValidate` sur un document
      quelconque) avant d'être utilisés — journal 2026-09-16
- [x] La matrice de bout en bout rejouée sur les quatre projets-fixtures, snapshots régénérés

## Amendement du 2026-09-19 — les listeners Doctrine

⚠️ **Un listener Doctrine n'est pas dans `debug:event-dispatcher`** : il vit sur le gestionnaire
d'événements de Doctrine, pas sur le répartiteur de Symfony. La console était la seule source des
mécanismes quand elle répondait, et deux listeners de cereezer — qui écrivent des totaux avant chaque
`persist` — n'apparaissaient sur aucune page. Les supprimer ne changeait rien à la documentation.

Les deux sources sont désormais **fusionnées** : la console pour ce que le conteneur a compilé (elle
seule connaît la priorité réelle), les attributs pour ce qu'elle ignore. Et `#[AsDoctrineListener(event:
Events::prePersist)]` nomme son événement par une constante de classe : la valeur d'une constante de
`Events` est son propre nom, ce qui se résout sans autoload ; celles de Symfony ne le sont pas, et sont
donc listées une à une — deviner « REQUEST » pour `kernel.request` nommerait un événement que personne
n'écoute.

## Conséquences

- **Changement cassant du gabarit.** Toutes les pages existantes sont réécrites une fois, y compris
  celles de ce dépôt (`docs/workflows/`) et celles de cereezer. Les sections rédigées par Claude
  qui survivent sont Résumé, Parcours, Données, Mécanismes transverses, Points d'attention ;
  « Décisions » naît vide et se remplit à la rédaction suivante.
- **Perte assumée.** Un lecteur qui voulait la liste des fichiers ouvre le XML. Le lien
  page → fichiers n'est plus lisible sans outil ; `workflows:impact` (ADR-0016) le rendra.
- Le § 4.2 des specs liste sept types, DevTools n'en produit plus que six : l'écart entre dans le
  tableau des écarts assumés de `docs/adr/README.md`.
- L'ADR-0008 passe `Superseded by ADR-0043` ; le gabarit de référence est celui-ci.

## Dépendances

ADR-0006, ADR-0008, ADR-0011.
