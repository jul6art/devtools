# Angular 22

## Entry cycle

**Bootstrap.** `src/main.ts` calls `bootstrapApplication(AppComponent, appConfig)`: the providers of
`app.config.ts` — `provideRouter(routes)`, `provideHttpClient()`, `provideZonelessChangeDetection()` or zone.js
— build the root injector, then the root component renders and its `<router-outlet>` waits for the router.
An NgModule application (`platformBrowser().bootstrapModule(AppModule)`) does the same through
`RouterModule.forRoot(routes)`.

**Navigation.** The router matches the URL against the route table, depth first:

1. `canMatch` guards decide whether a route may match at all; `loadChildren` fetches a lazy table,
   `loadComponent` a lazy component, the first time only.
2. Resolvers (`resolve`) and `canActivate` / `canActivateChild` guards run, parents first. A guard returning
   `false` cancels, a `UrlTree` redirects.
3. The component is created in the outlet: route parameters reach it through `ActivatedRoute` or, with
   `withComponentInputBinding()`, as inputs. `canDeactivate` runs before leaving it.

**Change detection.** Signals (`signal`, `computed`, `input()`, `model()`) mark what must render; templates
use the built-in control flow (`@if`, `@for` with a mandatory `track`, `@switch`, `@defer`).

**HTTP.** `HttpClient` returns cold observables: nothing is sent until something subscribes (the `async`
pipe, `toSignal`, `subscribe`). Interceptors (`withInterceptors([fn])`) wrap every request.

## Extension mechanisms

- **Guards and resolvers** — functions (`CanActivateFn`, `CanMatchFn`, `ResolveFn`) using `inject()`;
  class guards are deprecated.
- **HTTP interceptors** — functional (`HttpInterceptorFn`) in `provideHttpClient(withInterceptors(...))`.
- **Directives and pipes** — standalone, imported by the components that use them.
- **Providers** — `providedIn: 'root'` services, environment providers (`makeEnvironmentProviders`),
  `APP_INITIALIZER` replaced by `provideAppInitializer()`.
- **Ionic** — `IonRouterOutlet` keeps pages alive in a navigation stack; tabs are child routes of a
  `TabsPage` whose `ion-tab-button`s name them.

## Dependency injection and naming conventions

`inject(Service)` in a field initializer or constructor; `@Injectable({ providedIn: 'root' })` makes a
singleton. Components are **standalone by default** since Angular 19: their dependencies are listed in
`imports`. Files follow the style guide: `order-list.component.ts` (with `.html`/`.css` beside it when not
inline), `order.service.ts`, `auth.guard.ts`, `app.routes.ts`; selectors carry the application prefix
(`app-order-list`). `tsconfig.json` `paths` map aliases (`@app/*`, `@env`).

## Entry points by type

### routes
The `Routes` array given to `provideRouter()` (or `RouterModule.forRoot/forChild`): `path`, `component`,
`loadComponent`, `loadChildren`, `children`, `redirectTo`, guards. A child path is relative to its parent.

### commands
None in the application itself; `angular.json` targets (`ng build`, `ng test`) are tooling.

### async
None of its own: timers and observables run inside the screens and services that start them.

### events
Outputs (`output()`) and DOM events bound in templates; no application-wide bus.

### ui
Components — routed ones are screens, the others are reused by selector in templates.

### integrations
Services injecting `HttpClient`; the base URL usually comes from `environment.ts` (replaced per build
configuration by `fileReplacements`).

### data
None: persistence belongs to the API, or to the device through Capacitor plugins.

## Tests

`*.spec.ts` beside the code, run by the builder `ng test` (Vitest by default in recent projects, Karma or
Jest in older ones) with `TestBed`; `provideHttpClientTesting()` and `HttpTestingController` stub HTTP.
End-to-end tests (Playwright, Cypress) live in `e2e/`.

## Known traps

- **A cold observable never subscribed sends nothing**: a `this.http.post(...)` without `subscribe` is silent.
- **Lazy chunks need the network**: offline, a `loadComponent` not yet fetched fails the navigation.
- **Route order matters**: the first match wins, `**` must come last, and an empty path without
  `pathMatch: 'full'` matches every URL.
- **Guards of a parent run for its children**, `canMatch` before the lazy table is even loaded.
- **`environment.ts` is replaced at build time**: what a test reads is not what production uses.
- **Standalone components must import what their template uses**, or the element is silently unknown.

## Sources

https://angular.dev/guide/routing
https://angular.dev/guide/routing/common-router-tasks
https://angular.dev/guide/di
https://angular.dev/guide/http
https://angular.dev/guide/signals
https://angular.dev/guide/testing
https://ionicframework.com/docs/angular/navigation
