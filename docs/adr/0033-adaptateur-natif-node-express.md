# ADR-0033 — Adaptateur natif Node / Express

- **Statut** : Accepted — 2026-10-21 (proposée le 2026-09-16 ; phase 3 lancée par jul6art le 2026-10-21) · Livré le 2026-10-21
- **Décideurs** : jul6art
- **Specs** : § 4.2, § 7.1 (`node-express`), § 8, § 9 (phase 3)

## Contexte

Le § 8 vise Node/Express parmi les stacks couvertes au lancement ; le MVP l'a fait par la voie Claude.
Un adaptateur natif sur le pont Node (ADR-0031) la rend déterministe.

## Décision

`ExpressAdapter` (namespace `Inspection\Adapter\Express\`), choisi quand `stack.xml` détecte `express`.

| Type | Source | Identifiant |
|---|---|---|
| routes | appels `app|router.<verbe>('<chemin littéral>', …)` ; montages `app.use('<préfixe>', router)` suivis pour concaténer les chemins | `route.<verbe>.<chemin normalisé>` |
| commands | entrées `bin` et `scripts` de `package.json` qui lancent un fichier du projet | `command.<nom>` |
| async | consommateurs de files reconnus (`bullmq` : `new Worker('<file>')`), `node-cron` : `cron.schedule` littéral | `async.<nom de file ou kebab(fichier)>` |
| events | `emitter.on('<événement>', …)` à nom littéral, sur un émetteur exporté par le projet | `event.<nom>` |

- **Sécurité** : middlewares passés avant le gestionnaire, par nom (`requireAuth`) → attribut
  `security`.
- JavaScript **et** TypeScript (même script) ; CommonJS et ESM.
- `source=native:express`, `confidence=high` ; route à chemin non littéral → `medium`.
- `resources/knowledge/express-4.md` et `express-5.md`.

## Budget d'exécution

Celui du pont Node : un processus par scan.

## Hors périmètre

Fastify, NestJS, Next.js (routes par fichiers) — chacun une ADR si un projet réel en a besoin ;
middlewares d'erreur comme mécanismes transverses.

## Critères d'acceptation

- [x] `node-express` (routeurs montés, script, worker BullMQ, cron, émetteur) : workflows attendus *(fixture `express-api`, Express 5)*
- [x] Variante TypeScript ESM de la même fixture : mêmes workflows *(`express-api-ts`)*
- [x] Chemin non littéral → `confidence=medium`
- [x] Bascule depuis la voie Claude avec écarts listés *(mécanisme commun, `AdapterSwitch`)*
- [x] Matrice de bout en bout étendue *(`express-api` natif ; `node-express`, sans TypeScript, reste la démonstration de la voie Claude)*
- [x] README : Express ajouté aux stacks natives

## Écarts assumés à la livraison

- **Fixture `express-api`, Express 5**, et non `node-express` : celle-ci, Express 4 sans TypeScript, reste
  le projet de la voie Claude (ADR-0013) que ses tests exercent — sans TypeScript, le pont refuse (ADR-0031).
- **Les émetteurs sont des mécanismes** (ADR-0043) attachés aux workflows qui traversent un fichier
  émettant l'événement — sans portée de méthode en JavaScript, tous ceux qui traversent ce fichier.
- **Fiche `express-5` seule** : `express-4` (en maintenance) reste rédigée par Claude.
- **Une route écrite en ligne dans le fichier de l'application** n'en parcourt pas les imports : ce fichier
  monte tous les routeurs, il déclare.

## Conséquences

- Les quatre stacks visées par le § 8 sont natives.

## Dépendances

ADR-0031.
