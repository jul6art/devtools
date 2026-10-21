# Express 5

## Entry cycle

**HTTP.** The entry file (`main` of `package.json`, often `server.js`) creates the application with
`express()`, registers middleware and routers with `app.use()` and routes with `app.get()`, `post()`…, then
calls `app.listen(port)`. For each request:

1. The middleware stack runs **in registration order**: each function receives `(req, res, next)` and either
   answers or calls `next()`. `app.use('/orders', router)` runs a router for the URLs under `/orders`, the
   prefix stripped from `req.url` inside it.
2. A route matches its method and path (`/orders/:id` puts `id` in `req.params`); its handlers run in order —
   the middleware written before the last handler (`requireAuth`) included.
3. The handler answers (`res.json()`, `res.status(201).send()`, `res.end()`); nothing answering is a
   request left hanging.
4. A thrown error or a rejected promise — Express 5 catches rejected promises of async handlers — and
   `next(err)` skip to the error middleware, the functions of **four** parameters `(err, req, res, next)`.

**Background work.** Queue workers (BullMQ: `new Worker('emails', processor)`) and schedules (node-cron:
`cron.schedule('0 2 * * *', task)`) run in the same process when their module is loaded, or in a separate one
started by its own script.

## Extension mechanisms

- **Middleware** — application-level (`app.use(fn)`), router-level (`router.use(fn)`), route-level
  (between the path and the handler), error-handling (four parameters, registered last).
- **Routers** — `express.Router()` (or `Router()` imported) groups routes, mounted under a prefix; a router
  can mount another.
- **Events** — Node's `EventEmitter`: `bus.on('order.created', listener)` runs synchronously inside the
  `bus.emit()` of whoever emits.
- **Settings** — `app.set('trust proxy', 1)`, read with `app.get('setting')` (one argument: not a route).

## Dependency injection and naming conventions

No container: modules import what they use (`require()` in CommonJS, `import` in ESM with `"type": "module"`).
Common layout: `src/app.js` (the application), `src/routes/` (routers), `src/controllers/` (handlers),
`src/services/`, `src/middleware/`. Configuration comes from `process.env`, often loaded by `dotenv`.

## Entry points by type

### routes
`app.<verb>(path, …handlers)`, `router.<verb>(…)`, `router.route(path).get(…).post(…)`; the full path is the
concatenation of the prefixes of the `app.use()` that mount the router.

### commands
`bin` entries and `scripts` of `package.json` that run a file of the project with Node.

### async
BullMQ workers, node-cron schedules, `setInterval` loops started at boot.

### events
Listeners of the project's emitters (`.on`, `.once`), and the code that `.emit()`s.

### ui
Server-rendered views (`res.render()` with Pug, EJS, Handlebars) when the application has a view engine.

### integrations
Outgoing HTTP (`fetch`, axios), webhooks received as routes.

### data
Migrations of the ORM in use (Knex, Prisma, Sequelize, TypeORM), seeds.

## Tests

`node --test`, Jest, Mocha or Vitest; Supertest drives the application in process
(`request(app).get('/orders')`) without opening a port.

## Known traps

- **Order is behaviour**: a route registered after a catch-all, or middleware registered after the router
  it should protect, never runs.
- **Path syntax changed in Express 5**: `*` must be named (`/*splat`), optional segments are written
  `{/:id}`, and regular-expression characters in strings are no longer supported.
- **`req.body` is undefined without `express.json()`** (or `urlencoded()`) registered before the route.
- **An error middleware needs four parameters**: with three, Express treats it as an ordinary one.
- **`app.listen()` in the module the tests import** opens a port in every test file.
- **Listeners run synchronously**: a slow listener slows the request that emitted.

## Sources

https://expressjs.com/en/5x/api.html
https://expressjs.com/en/guide/routing.html
https://expressjs.com/en/guide/using-middleware.html
https://expressjs.com/en/guide/error-handling.html
https://expressjs.com/en/guide/migrating-5.html
https://docs.bullmq.io/guide/workers
https://nodejs.org/api/events.html
