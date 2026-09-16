# ADR-0003 — Modèle intermédiaire et identifiants stables

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 4.2, § 4.3 étapes 3 et 5, § 4.8 (score de confiance), § 11 (stabilité des identifiants), § 13

## Contexte

Le § 4.3 pose que l'adaptateur natif et la voie Claude « produisent le même modèle intermédiaire ;
c'est ce qui garantit un rendu identique quelle que soit la stack ». Tout le reste du pipeline —
graphe, fraîcheur, rendu, suivi — ne lit que ce modèle. C'est donc la première chose à figer, et la
plus coûteuse à changer ensuite.

L'identifiant d'un workflow nomme sa page, son XML et ses liens : s'il change entre deux scans,
chaque page devient orpheline (§ 11).

⚠️ **Écart avec le § 4.3 étape 3** : les specs disent « JSON normé, validé par schéma ». Le modèle
est sérialisé en **XML validé par XSD**, pour n'avoir qu'une pile de validation (`ext-dom`, déjà
requise pour le suivi, § 4.6.3) et aucune dépendance JSON Schema. Claude écrit du XML aussi bien que
du JSON.

## Décision

### Les objets (namespace `Inspection\Model\`)

Objets **immuables**, `final readonly`, construits validés (un invariant violé lève à la
construction, jamais plus tard) :

```
WorkflowType      enum : routes | commands | async | events | ui | integrations | data
                  (valeur = nom du dossier et du sous-menu) ; extensible par config.xml → la liste
                  réelle est un WorkflowTypeRegistry, l'enum ne porte que les sept natifs
EntryPoint        kind (route|command|message-handler|scheduled|listener|component|migration|
                  fixture|script|…), name, attributes (map string → string : path, methods,
                  event, message…), declaredIn (FileRef)
FileRef           path relatif à la racine du projet, normalisé (séparateur /, pas de ./ ni ..),
                  role (controller|service|form|template|entity|repository|config|listener|
                  message|handler|component|test|other)
PackageRef        name, version
Workflow          id (WorkflowId), type, title, main (EntryPoint), satellites (list<EntryPoint>),
                  files (list<FileRef>, triés, sans doublon), packages (list<PackageRef>),
                  dependsOn (list<WorkflowId>), tests (list<FileRef>),
                  navigation (list<Edge> : routes/écrans atteints), states (?StateMachine),
                  confidence (high|medium|low), source (native:<adaptateur>|claude)
InspectionResult  stack (référence stack.xml), workflows (list<Workflow>), uncovered (list<FileRef>)
```

- **Aucun chemin absolu, jamais.** `FileRef` refuse un chemin qui sort de la racine : c'est la
  garde du « aucun chemin inventé » et de l'écriture hors du projet (`SECURITY.md`).
- Les fichiers de `vendor/`, `node_modules/`… ne sont pas des `FileRef` mais des `PackageRef`
  (§ 4.3 étape 4).
- Les listes sont **triées** à la construction : deux scans identiques produisent un modèle
  identique, octet pour octet une fois sérialisé.

### Identifiants (`WorkflowId`)

Format : `<préfixe>.<segments>` en minuscules, segments `[a-z0-9-]+` séparés par `.`, préfixe au
singulier du type. Dérivation déterministe, **uniquement** à partir du point d'entrée principal :

| Type | Préfixe | Source | Exemple |
|---|---|---|---|
| routes | `route` | nom de route, préfixe configurable retiré (`app_` par défaut), `_` → `.` | `app_order_new` → `route.order.new` |
| commands | `command` | nom de commande, `:` → `.` | `app:import-catalog` → `command.app.import-catalog` |
| async | `async` | classe du message, nom court en kebab-case | `OrderCreated` → `async.order-created` |
| events | `event` | classe du listener, nom court en kebab-case | `LocaleListener` → `event.locale-listener` |
| ui | `ui` | classe du composant, nom court en kebab-case | `CartSummary` → `ui.cart-summary` |
| integrations | `integration` | nom du consommateur / de l'intégration | `stripe` → `integration.stripe` |
| data | `data` | famille (`migrations`, `fixtures`) | `data.migrations` |

- La page vit dans `workflows/<type>/<id sans préfixe>.md` (§ 4.4 : `route.order.create` →
  `workflows/routes/order.create.md`).
- **Collision** (deux points d'entrée → même identifiant) : **erreur**, avec les deux sources, et
  résolution par un alias explicite dans `config.xml` (`<alias entrypoint="…" id="…"/>`). Jamais de
  suffixe numérique automatique : il dépendrait de l'ordre de découverte, et changerait d'un scan à
  l'autre.

### Sérialisation

`resources/schemas/inspection-model.xsd` décrit `InspectionResult` et `Workflow`. Un
`ModelXmlSerializer` lit et écrit ce format ; il sert à la voie Claude (ADR-0013) et aux snapshots
de test. Namespace XML : `https://github.com/jul6art/devtools/schema/inspection-model/1`.

⚠️ Les specs utilisent `https://devtools.provencale.lu/schema/…` : l'URI d'un namespace XML n'a pas
besoin d'être résolvable, mais elle ne se change plus une fois publiée. Elle suit le dépôt réel.

## Budget d'exécution

Construction et tri en O(n log n) sur le nombre de fichiers ; aucune entrée/sortie dans le modèle.

## Hors périmètre

Regroupement automatique des satellites (ADR-0006), score de confiance calculé (chaque adaptateur le
fixe), migration de `schema-version` (aucune version antérieure n'existe), `workflows:rename`.

## Critères d'acceptation

- [x] Chaque invariant (chemin hors racine, identifiant mal formé, doublon de fichier, type inconnu)
      a son test qui prouve le rejet à la construction
- [x] Table de dérivation des identifiants couverte cas par cas, y compris les caractères à
      normaliser (majuscules, accents, `__`, segments vides)
- [x] Deux points d'entrée en collision lèvent une erreur qui nomme les deux sources ; un alias de
      configuration la résout
- [x] Round-trip XML : modèle → XML → modèle identique ; XML invalide rejeté avec la ligne fautive
- [x] Deux modèles construits à partir des mêmes données dans un ordre différent sérialisent à
      l'octet près

## Conséquences

- Changer la dérivation d'un identifiant est un **changement cassant** pour tout projet utilisateur
  (`CONTRIBUTING.md`) ; la table ci-dessus devient un contrat.
- Un renommage de route change l'identifiant : c'est assumé jusqu'à `workflows:rename` (hors MVP).

## Dépendances

ADR-0001.
