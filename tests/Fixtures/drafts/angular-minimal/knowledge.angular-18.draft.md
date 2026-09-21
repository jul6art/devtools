# Angular 18

## Entry cycle

`main.ts` bootstraps the application (`bootstrapApplication(AppComponent, appConfig)`); `appConfig`
registers the providers, among them `provideRouter(routes)` and `provideHttpClient()`. The router matches the
browser URL against the `Routes` array, resolves guards (`canActivate`, `canMatch`) and resolvers, lazily loads
`loadComponent` / `loadChildren` targets, and renders the matched component into the `<router-outlet>`.
Components render their template; change detection updates the view when signals, inputs or events change.

## Extension mechanisms

- **Guards and resolvers** — functions or classes on a route, run before activation.
- **HTTP interceptors** — `provideHttpClient(withInterceptors([...]))`, around every `HttpClient` call.
- **Providers** — `providedIn: 'root'` services, or providers on a route or a component.
- **Directives and pipes** — reusable behaviour and transformations in templates.

## Dependency injection and naming conventions

Hierarchical injectors: `@Injectable({ providedIn: 'root' })` services are application singletons, obtained
with `inject(Service)` or constructor parameters. File conventions from the Angular CLI: `name.component.ts`,
`name.service.ts`, `app.routes.ts`, `app.config.ts`; standalone components declare their dependencies in
`imports`.

## Entry points by type

### routes
The `Routes` arrays (`app.routes.ts`, feature `*.routes.ts`): `path`, `component`, `loadComponent`,
`loadChildren`, `children`, guards. The full path concatenates parent paths.

### commands
The `scripts` of `package.json` and the Angular CLI builders in `angular.json`.

### async
Web workers (`new Worker(new URL(...))`) and service workers when configured.

### events
Host listeners (`@HostListener`, `host` metadata) and application-wide event streams in services.

### ui
Components (`@Component`) — routed pages and shared components used across templates.

### integrations
Services calling `HttpClient` against a backend API.

### data
State stores (NgRx, signals-based stores) when the application has them.

## Tests

`*.spec.ts` next to the code, run by Karma/Jasmine or Jest through `ng test`; `TestBed` configures a testing
module; end-to-end tests in `e2e/` with Cypress or Playwright.

## Known traps

- **Route order matters**: the first matching route wins, and a wildcard `**` must come last.
- **A lazily loaded path is only known from its literal**: a path computed at runtime is invisible to reading.
- **`providedIn: 'root'` vs providers on a component** create different instances of the same service.
- **An `HttpClient` observable does nothing until subscribed** (in code or with the `async` pipe).

## Sources

https://angular.dev/guide/routing
https://angular.dev/guide/di
https://angular.dev/guide/http
https://angular.dev/guide/testing
