# ADR-0020 — Scénarios reproductibles dérivés des workflows

- **Statut** : Proposed — 2026-09-16
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

## Budget d'exécution

Génération : une tâche IA par workflow sans scénario ou marqué à revoir ; zéro sinon.

## Hors périmètre

Exécution (ADR-0021) ; comparaison visuelle (ADR-0022) ; scénarios de commandes console et de
handlers asynchrones (routes et `ui` seulement) ; enregistrement d'un scénario depuis le navigateur.

## Critères d'acceptation

- [ ] `scenario.xsd` : exemple ci-dessus valide ; action inconnue, workflow absent, identifiant mal
      formé rejetés
- [ ] `--no-ai` sur `symfony-minimal` : un squelette par route GET, rôle inclus (snapshot)
- [ ] Brouillon enregistré appliqué ; brouillon référant une route inconnue refusé
- [ ] Scénario modifié à la main non régénéré
- [ ] Workflow réécrit → scénarios « à revoir » au menu ; workflow orphelin → scénario signalé
- [ ] README : section « Scénarios »

## Conséquences

- Le vocabulaire fermé est un contrat : une action ajoutée est une version mineure, une action
  retirée une majeure.

## Dépendances

ADR-0015.
