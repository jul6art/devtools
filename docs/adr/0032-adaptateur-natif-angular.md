# ADR-0032 — Adaptateur natif Angular

- **Statut** : Accepted — 2026-10-21 (proposée le 2026-09-16 ; phase 3 lancée par jul6art le 2026-10-21) · Livré le 2026-10-21
- **Décideurs** : jul6art
- **Specs** : § 1.1 (Angular en interne), § 4.2, § 7.1 (`angular-minimal`), § 8, § 9 (phase 3)

## Contexte

Angular est la deuxième stack de l'équipe (`cegeta-mobile`, `cereezer-mobile`, `mobile-kit` :
Angular + Ionic + Capacitor). La voie Claude la couvre depuis le MVP ; un adaptateur natif rend ses
routes et ses écrans déterministes, sur le pont Node (ADR-0031).

## Décision

`AngularAdapter` (namespace `Inspection\Adapter\Angular\`), choisi quand `stack.xml` détecte
`@angular/core`.

| Type | Source | Identifiant |
|---|---|---|
| routes | tableaux `Routes` (`path`, `component`, `loadComponent`, `loadChildren`, `children`, `canActivate`) suivis depuis `app.routes.ts` / `provideRouter` / `RouterModule.forRoot`, chemins concaténés à travers les enfants et le lazy loading | `route.<chemin, / → ., :param → param>` ; chemin vide → `route.root` |
| ui | composants `@Component` non routés utilisés par au moins deux écrans (sinon composants de l'écran) | `ui.<kebab(sélecteur sans préfixe)>` |
| integrations | services qui injectent `HttpClient` : appels `get/post/…` à URL littérale ou préfixée d'une constante résolue | `integration.<kebab(service)>` |
| async | — | (aucun déclencheur propre côté front) |

- **Sécurité** : noms des guards (`canActivate`, `canMatch`) → attribut `security`.
- **Navigation** : `routerLink` littéraux des templates et `router.navigate([...])` littéraux.
- **Ionic** : `IonRouterOutlet` et routes d'onglets traitées comme des routes Angular ; pages Ionic =
  composants routés.
- Templates externes (`templateUrl`) et styles ajoutés aux fichiers impliqués.
- `source=native:angular`, `confidence=high` ; lazy loading dont le chemin n'est pas littéral →
  workflow avec `confidence=medium` et avertissement.
- `resources/knowledge/angular-18.md` à `angular-22.md` embarqués selon ce qui a été acquis et relu.

## Budget d'exécution

Celui du pont Node : un processus par scan.

## Hors périmètre

Routes générées dynamiquement ; NgModules hérités au-delà de `forRoot`/`forChild` ; relier
`integrations` aux routes d'un back (ADR-0038).

## Critères d'acceptation

- [x] `angular-minimal` (routes imbriquées, lazy loading, guard, composant partagé, service HTTP) :
      workflows attendus *(Angular 22 ; vérifié aussi sur une copie de cegeta-mobile : ses huit écrans)*
- [x] Fixture Ionic à onglets : routes d'onglets extraites *(`ionic-tabs`, Ionic 9)*
- [x] Chemin lazy non littéral → `confidence=medium` et avertissement
- [x] Bascule depuis la voie Claude avec écarts listés (même mécanisme que l'ADR-0030)
- [x] Matrice de bout en bout étendue
- [x] README : Angular ajouté aux stacks natives

## Écarts assumés à la livraison

- **Une seule fiche embarquée, `angular-22`** : la version de la fixture et des trois applications de
  l'équipe ; les autres versions restent rédigées par Claude, comme avant.
- **L'identifiant d'une intégration est `integration.<kebab(service)>`** (`integration.order-service`), et
  ses `endpoints` (méthode et URL, constantes d'`environment` résolues) sont un attribut : c'est ce que
  l'ADR-0038 appariera aux routes d'un back.
- **Une route avec des enfants est leur layout**, pas un écran : son composant est un fichier de chacun.
- **Sans Node ni TypeScript**, la stack retourne à la voie Claude et le rapport le dit (code de sortie `1`).

## Conséquences

- Le type `integrations` existe enfin avec des données : c'est le préalable du multi-projets
  (ADR-0038).

## Dépendances

ADR-0031.
