# Laravel 13

## Entry cycle

**HTTP.** `public/index.php` requires `bootstrap/app.php`, where `Application::configure()` declares the
routing files, the middleware and the exception handling, then calls `$app->handleRequest()`:

1. The HTTP kernel bootstraps the application: environment (`.env`), configuration (`config/*.php`, or the
   framework's defaults for a file the project does not have), facades, then the **service providers** of
   `bootstrap/providers.php` and of the packages Laravel discovered (`bootstrap/cache/packages.php`) —
   `register()` for all of them, then `boot()`.
2. The request goes through the **global middleware**, then the router matches a route of `routes/web.php`
   (group `web`: session, cookies, CSRF) or `routes/api.php` (group `api`, under `/api`).
3. The route's middleware runs — its group's, then its own (`auth`, `can:view,order`, `throttle`…) — and
   **route model binding** turns `{order}` into an `Order` (`SubstituteBindings`), a 404 when none matches.
4. The action runs: a controller method, an invokable controller or a closure. Form requests validate and
   authorize before it. What it returns becomes a response (a view, a redirect, an array as JSON).
5. The response goes back through the middleware; terminable middleware run after it was sent.
6. Any exception goes to the handler configured by `withExceptions()`, which renders or reports it.

**Console.** `artisan` boots the same application through the console kernel; commands are the classes of
`app/Console/Commands` (discovered) and the closures of `routes/console.php`. `schedule:run`, launched every
minute by cron, runs the tasks the schedule declares.

**Queues.** Dispatching a class implementing `ShouldQueue` pushes it to the connection of `config/queue.php`
(`QUEUE_CONNECTION`, `database` by default); `queue:work` pops and handles it. With the `sync` connection the
job runs immediately, in the request.

## Extension mechanisms

- **Middleware** — global, per group (`web`, `api`) or per route, configured in `bootstrap/app.php` with
  `withMiddleware()`; aliases (`auth`, `can`, `verified`, `signed`, `throttle`) name framework classes.
- **Events and listeners** — listeners in `app/Listeners` are **discovered**: the type of the first parameter
  of `handle()` (or `__invoke()`) is the event. `Event::listen()` in a provider adds others. A listener
  implementing `ShouldQueue` runs on the queue.
- **Model events and observers** — `creating`, `saved`, `deleted`…; `#[ObservedBy]` on the model.
- **Service providers** — `register()` binds into the container, `boot()` runs once everything is registered.
- **Policies and gates** — `app/Policies/OrderPolicy.php` is found for `App\Models\Order` by convention;
  `Gate::define()` in a provider; `can:` middleware, `$this->authorize()`, `@can` in Blade.
- **Blade components and Livewire** — `<x-alert>` is `App\View\Components\Alert` (or the anonymous
  `resources/views/components/alert.blade.php`); `<livewire:cart-summary>` is `App\Livewire\CartSummary`.

## Dependency injection and naming conventions

The container resolves constructor and method parameters by type, with no configuration for concrete
classes; interfaces are bound in a provider (`$this->app->bind()`, `singleton()`, `scoped()`). Facades
(`Route`, `Cache`, `Schedule`) are static proxies to container services. Conventions carry meaning:
`Order` model ↔ `orders` table, `OrderPolicy`, `OrderFactory`; `{order}` in a URI binds the `$order`
parameter; a view name `orders.index` is `resources/views/orders/index.blade.php`.

## Entry points by type

### routes
`Route::get()`, `post()`, `put()`, `patch()`, `delete()`, `match()`, `any()`, `resource()` in `routes/web.php`
and `routes/api.php`; groups set a prefix, a name prefix, middleware. `php artisan route:list --json` lists them
all with their action and resolved middleware — packages' routes (Livewire's, the `/up` health check)
included.

### commands
Classes extending `Illuminate\Console\Command`: the first word of `$signature` is the name. Closures:
`Artisan::command('name', fn () => …)` in `routes/console.php`. `php artisan list --format=json`.

### async
Jobs (`ShouldQueue`, `handle()`), queued listeners and mailables; the schedule in `routes/console.php`:
`Schedule::command('orders:import')->daily()`, `Schedule::job(new SendReport)->hourly()`.

### events
Discovered listeners and `Event::listen()`; `php artisan event:list --json` lists them per event.

### ui
Blade views rendered by `view()` / `View::make()`, layouts (`@extends`, `<x-layout>`), components, Livewire
components (their `render()` returns their view).

### integrations
Outgoing HTTP through the `Http` facade (`Http::get()`, macros declared in a provider), notifications,
broadcasting (`routes/channels.php`).

### data
Migrations (`database/migrations`), seeders (`database/seeders`), factories (`database/factories`).

## Tests

`tests/Feature` and `tests/Unit`, run by PHPUnit or Pest (`php artisan test`). `Tests\TestCase` boots the
application; `$this->get('/orders')`, `actingAs($user)`, `assertRedirect()`; `RefreshDatabase` migrates an
SQLite database in memory; `Queue::fake()`, `Event::fake()`, `Http::fake()` replace the real services.

## Known traps

- **`route:list` boots the application**: a provider that queries the database at boot breaks it.
- **The cache store and the session default to `database`**: `schedule:list` and anything touching a lock
  fail without the tables.
- **Configuration caching** (`config:cache`) freezes `env()`: outside `config/*.php`, `env()` returns null.
- **A queued job serializes its models** by identifier and reloads them: a deleted model fails the job.
- **Listener discovery reads the type of the first parameter**: an untyped `handle($event)` listens to
  nothing.
- **Route model binding uses the parameter name**: `{order}` binds `$order`, not `$item`.

## Sources

https://laravel.com/docs/13.x/lifecycle
https://laravel.com/docs/13.x/routing
https://laravel.com/docs/13.x/middleware
https://laravel.com/docs/13.x/events
https://laravel.com/docs/13.x/queues
https://laravel.com/docs/13.x/scheduling
https://laravel.com/docs/13.x/testing
