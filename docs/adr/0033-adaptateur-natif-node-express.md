# ADR-0033 — Adaptateur natif Node / Express

- **Statut** : Proposed — 2026-09-16
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

- [ ] `node-express` (routeurs montés, script, worker BullMQ, cron, émetteur) : workflows attendus
- [ ] Variante TypeScript ESM de la même fixture : mêmes workflows
- [ ] Chemin non littéral → `confidence=medium`
- [ ] Bascule depuis la voie Claude avec écarts listés
- [ ] Matrice de bout en bout étendue
- [ ] README : Express ajouté aux stacks natives

## Conséquences

- Les quatre stacks visées par le § 8 sont natives.

## Dépendances

ADR-0031.
