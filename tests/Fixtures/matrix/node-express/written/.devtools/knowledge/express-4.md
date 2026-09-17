# Express 4

## Cycle d'entrée

An HTTP request reaches the Node `http` server created by `app.listen()`, which hands it to the Express
application. The application runs its middleware stack in the order of `app.use()` and route declarations:
each middleware receives `(req, res, next)` and either ends the response or calls `next()`. A `Router`
mounted with `app.use('/prefix', router)` runs its own stack for paths under that prefix. The first route
whose method and path match handles the request; if none does, Express answers 404. An error passed to
`next(err)` skips to the error-handling middlewares, those declared with four arguments `(err, req, res, next)`.

## Mécanismes d'extension

- **Middlewares** — `app.use(fn)` or per route (`router.get(path, auth, handler)`), run in declaration order.
- **Routers** — `express.Router()` groups routes and middlewares, mounted under a prefix.
- **Error handlers** — middlewares with four arguments, declared after the routes.
- **Parameter handlers** — `router.param('id', fn)`, run before any route using `:id`.

## Injection de dépendances et conventions de nommage

Express has no container: modules are wired with `require()` or `import`, and dependencies are usually module
singletons (a service module exporting functions). Conventions, not rules: `src/app.js` builds the application,
`src/server.js` or `bin/www` listens, `routes/` holds routers, `services/` business logic, `middlewares/`
cross-cutting functions.

## Points d'entrée par type

### routes
`app.<method>(path, …handlers)` and `router.<method>(path, …handlers)`; the full path is the mount prefix of
`app.use(prefix, router)` followed by the route path.

### commands
The `bin` field and the `scripts` of `package.json`, and executable files under `bin/`.

### async
Queue consumers of the libraries in use (BullMQ `new Worker(queue, fn)`), schedulers (`node-cron`
`cron.schedule(expression, fn)`).

### events
`emitter.on(event, listener)` on an `EventEmitter` the project exports.

### ui
Server-side templates rendered with `res.render(view)` when a view engine is configured.

### integrations
Outgoing HTTP calls (`fetch`, `axios`), webhooks received as routes.

### data
Migrations and seeds of the database tool in use (Knex, Prisma, Sequelize).

## Tests

Usually `test/` or `__tests__/`, run by Jest, Mocha or Node's test runner through `npm test`. Supertest drives
the application in-process: `request(app).get('/orders')`.

## Pièges connus

- **Declaration order is behaviour**: a middleware declared after a route never runs for it.
- **Express 4 does not catch rejected promises** of async handlers: an unhandled rejection never reaches the
  error middlewares unless the handler calls `next(err)` itself.
- **Route paths are strings or patterns**: a path built dynamically cannot be found by reading the code.
- **`res.send()` after a response was sent** throws `ERR_HTTP_HEADERS_SENT`.

## Sources

https://expressjs.com/en/4x/api.html
https://expressjs.com/en/guide/routing.html
https://expressjs.com/en/guide/using-middleware.html
https://expressjs.com/en/guide/error-handling.html
