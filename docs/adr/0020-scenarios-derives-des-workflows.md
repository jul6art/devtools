# ADR-0020 — Scénarios reproductibles dérivés des workflows

- **Statut** : Accepted — 2026-10-20 (proposée le 2026-09-16 ; phase 1 lancée par jul6art) · Livré le 2026-10-20
- **Décideurs** : jul6art
- **Specs** : § 1.2, § 1.3 (biais de l'auteur), § 3.1 (module Scenarios), § 5.2, § 9 (phase 1)

## Contexte

« J'ai vérifié avec le MCP Chrome » n'est ni reproductible ni vérifiable (§ 1.3). Un scénario écrit,
versionné et rejoué par un runner (ADR-0021) l'est. Les workflows du MVP donnent déjà ce qu'il faut
pour en dériver un premier jet : route, méthodes, sécurité (ADR-0007), navigation, formulaires
impliqués.

⚠️ **Écart avec le § 5.2** : les specs écrivent les scénarios en **YAML**. Ils sont en **XML validé
par XSD**, comme tout ce que DevTools lit et écrit (ADR-0003, 0004) : une seule pile de validation, et
pas de `symfony/yaml` dans le cœur. Claude les écrit ; un humain les lit dans la revue (ADR-0028).

## Décision

### Format (`.devtools/scenarios/<workflow-id>/<nom>.xml`, `scenario.xsd`)

```xml
<scenario xmlns="https://github.com/jul6art/devtools/schema/scenario/1" schema-version="1"
          id="route.order.new/happy-path" workflow="route.order.new">
  <given>
    <login role="ROLE_OPERATOR"/>              <!-- résolu par un LoginProvider du projet -->
    <fixture name="catalog-loaded"/>           <!-- résolu par un FixtureProvider du projet -->
  </given>
  <steps>
    <goto route="app_order_new"/>
    <fill field="order[customer]" value="ACME"/>
    <select2 field="order[product]" search="Widget" pick="Widget XL"/>
    <click text="Enregistrer"/>
    <assert-route name="app_order_show"/>
    <assert-visible selector=".flash-success"/>
    <screenshot name="after-save"/>
  </steps>
</scenario>
```

- Vocabulaire **fermé** et versionné : `goto`, `fill`, `select`, `check`, `click`, `submit`, `wait-for`,
  `assert-route`, `assert-visible`, `assert-text`, `assert-count`, `screenshot`, et les **actions
  métier** (`select2`, `datatable-filter`, `modal-confirm`) — ajoutées une par une, chacune avec son
  implémentation dans le runner.
- `login` et `fixture` sont **des noms**, résolus par des services que le projet fournit
  (ADR-0021) : un scénario ne contient ni mot de passe ni donnée.
- Un scénario référence un **workflow existant** ; `inspect` signale un scénario dont le workflow
  est `orphaned`.

### Génération (`devtools scenarios:generate [id]`)

- Tâche IA `scenario` (ADR-0002) : brief = workflow, page, knowledge, scénarios existants, vocabulaire
  XSD ; brouillon = un ou plusieurs XML ; `workflows:apply` valide XSD, routes et workflow existants.
- **Squelette factuel** sans IA (`--no-ai`) : pour une route `GET`, un scénario `goto` +
  `assert-route` + `screenshot` avec le rôle extrait — immédiatement rejouable, et base du brouillon.
- Un scénario **modifié à la main** (hash enregistré dans `scenarios/index.xml`) n'est jamais
  régénéré.

### Lien avec la fraîcheur

Un workflow réécrit (ADR-0010) marque ses scénarios **à revoir** (listés dans le menu) ; ils ne sont
pas supprimés.

### Précisions à l'acceptation (2026-10-20)

- **Vocabulaire livré** : les douze actions génériques et une seule action métier, `select2` — celle de
  l'exemple. `datatable-filter` et `modal-confirm` viendront une par une, chacune avec son implémentation
  dans le runner (ADR-0021). `goto` prend ses paramètres de route en enfants `<param name value>` ; `click`
  désigne un `text` **ou** un `selector`, ce que DevTools vérifie à la lecture (XSD 1.0 ne sait pas le dire).
- **Un brouillon** est `<scenarios>` (un ou plusieurs `<scenario>`, même schéma) dans
  `pending/scenario.<id>.draft.xml`, à côté de son brief ; prompt `resources/prompts/scenario/v1.md`.
- **Squelette** : seulement pour une route `GET` **sans paramètre** — une valeur de paramètre ne se
  devine pas, et un squelette doit se rejouer tel quel. Nommé `smoke` ; rôle = le dernier `ROLE_*` que la
  sécurité nomme (`ROLE_USER, ROLE_OPERATOR` → `ROLE_OPERATOR`), à défaut `IS_AUTHENTICATED_*`, sinon
  aucun `login`. Les autres routes et les composants sont nommés en sortie (`--no-ai`), ou briefés (IA).
  En mode IA, le squelette est écrit **et** le brief : rejouable tout de suite, puis complété.
- **Index** `scenarios/index.xml` (`scenario-index.xsd`) : par scénario, `origin` (`skeleton`, `claude`,
  `human`), `sha256` du fichier tel qu'enregistré, `revision` du workflow contre laquelle il a été écrit.
  Un fichier dont le hash ne correspond plus devient `human` ; un fichier écrit à la main est inscrit
  `human` ; un fichier disparu quitte l'index. `workflows:apply` refuse un brouillon qui récrirait un
  scénario humain, même modifié depuis le dernier `scenarios:generate`.
- **« À revoir »** se calcule, il ne s'écrit pas : un scénario est à revoir quand la dernière révision de
  son workflow (`index.xml`) est postérieure à la sienne. Un squelette est réécrit par le `generate`
  suivant, ceux de Claude re-briefés ; un scénario humain reste tel quel jusqu'à
  `scenarios:generate --reviewed=<id>`, qui enregistre la relecture — et rend le scénario humain s'il ne
  l'était pas.
- **Orphelin** : workflow absent de l'index ou `orphaned` — ligne du menu et avertissement de `inspect`
  (code de sortie 1, comme tout avertissement).
- Brief : raison, page, fichier de suivi, knowledge de la stack, vocabulaire, routes du workflow, rôle,
  scénarios existants avec leur origine. Un brief que plus rien ne justifie est supprimé.

## Budget d'exécution

Génération : une tâche IA par workflow sans scénario ou marqué à revoir ; zéro sinon.

## Hors périmètre

Exécution (ADR-0021) ; comparaison visuelle (ADR-0022) ; scénarios de commandes console et de
handlers asynchrones (routes et `ui` seulement) ; enregistrement d'un scénario depuis le navigateur.

## Critères d'acceptation

- [x] `scenario.xsd` : exemple ci-dessus valide ; action inconnue, workflow absent, identifiant mal
      formé rejetés *(aussi : un identifiant d'un autre workflow, un `click` sur rien ou sur deux cibles)*
- [x] `--no-ai` sur `symfony-minimal` : un squelette par route GET, rôle inclus (snapshot) *(par route
      GET sans paramètre : `route.health`, `route.order.index`, `route.order.new` —
      `tests/Fixtures/snapshots/scenarios/skeletons.xml`)*
- [x] Brouillon enregistré appliqué ; brouillon référant une route inconnue refusé *(aussi : un autre
      workflow, sans brief, invalide, périmé)*
- [x] Scénario modifié à la main non régénéré *(ni par `generate`, ni par un brouillon)*
- [x] Workflow réécrit → scénarios « à revoir » au menu ; workflow orphelin → scénario signalé *(menu
      et avertissement d'`inspect`)*
- [x] README : section « Scénarios » *(« Scenarios »)*

## Conséquences

- Le vocabulaire fermé est un contrat : une action ajoutée est une version mineure, une action
  retirée une majeure.

## Dépendances

ADR-0015.
