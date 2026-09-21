# Symfony 7

## Entry cycle

**HTTP.** `public/index.php` hands the request to the Runtime component, which boots the `Kernel`
(`MicroKernelTrait`) and calls `HttpKernel::handle()`:

1. `kernel.request` (`RequestEvent`) — routing (`RouterListener` sets `_controller` and route attributes),
   locale, firewall and `access_control`. A listener may return a response and stop here.
2. Controller resolution, then `kernel.controller` (`ControllerEvent`) — attributes on the controller such
   as `#[IsGranted]` or `#[Cache]` are handled around this point.
3. Argument resolution (`ArgumentResolver`: request, route parameters, services, `#[MapRequestPayload]`,
   entities…), then `kernel.controller_arguments`.
4. The controller runs. If it does not return a `Response`, `kernel.view` must turn its result into one.
5. `kernel.response` (`ResponseEvent`), then `kernel.finish_request`; after sending, `kernel.terminate`.
6. Any throwable goes through `kernel.exception` (`ExceptionEvent`), which the ErrorHandler renders.

**Console.** `bin/console` boots the kernel and a `FrameworkBundle\Console\Application`; commands are
services tagged `console.command`, loaded lazily. Events: `console.command`, `console.error`,
`console.terminate`.

**Messages.** `MessageBusInterface::dispatch()` runs the bus middlewares; `SendMessageMiddleware` sends the
envelope to a transport according to `framework.messenger.routing`, or `HandleMessageMiddleware` calls the
handlers synchronously. Workers (`messenger:consume`) receive and handle asynchronously.

## Extension mechanisms

- **Event listeners and subscribers** — `#[AsEventListener]` on a class or a method, or a class
  implementing `EventSubscriberInterface`. Higher priority runs first; Symfony's own listeners sit at known
  priorities (routing 32, firewall 8 on `kernel.request`).
- **Messenger middlewares** — per bus, around every dispatch.
- **Service decoration** — `#[AsDecorator]`: replaces a service while keeping the inner one.
- **Voters** — `VoterInterface`, consulted by `isGranted()` / `#[IsGranted]`.
- **Compiler passes** — alter the container at build time; run before the container is dumped.
- **Twig extensions and components** — `#[AsTwigFilter]` / `#[AsTwigFunction]`, `#[AsTwigComponent]`,
  `#[AsLiveComponent]` (Symfony UX).

## Dependency injection and naming conventions

`config/services.yaml` (or `.php`) declares `App\` from `src/` with `autowire` and `autoconfigure`:
constructor arguments are injected by type, and interfaces or attributes add tags automatically
(`AsCommand` → `console.command`, `AsMessageHandler` → `messenger.message_handler`…). Scalar arguments are
never autowired: bind them (`#[Autowire('%env(X)%')]`, `bind:`). Several implementations of one interface
are chosen by argument name (`WorkflowInterface $orderStateMachine` → the `order` state machine) or
`#[Target]`. Services are private by default; only the container itself and tests reach them.

## Entry points by type

### routes
`#[Route]` on controller methods (and on the class for a prefix), imported by `config/routes.yaml`
(`type: attribute`); YAML or PHP route files. `bin/console debug:router` lists them all, bundles' routes
included.

### commands
Classes with `#[AsCommand(name: …)]`, extending `Command` or invokable. `debug:container --tag=console.command`.

### async
Message handlers: `#[AsMessageHandler]` on a class (`__invoke`) or a method; the handled message is the type
of the first parameter. Routing to transports in `framework.messenger.routing`. Scheduled tasks:
`#[AsCronTask]`, `#[AsPeriodicTask]`, or a `ScheduleProviderInterface`.

### events
Listeners and subscribers (see above). `debug:event-dispatcher [event]` shows them with priorities.

### ui
Forms (`AbstractType`), Twig templates rendered by controllers, Twig and Live components.

### integrations
Outgoing HTTP through `HttpClientInterface` (scoped clients in `framework.http_client`), webhooks through the
Webhook and RemoteEvent components (`#[AsRemoteEventConsumer]`).

### data
Doctrine migrations (`migrations/`), fixtures (`src/DataFixtures/`), imports usually exposed as commands.

## Tests

`tests/`, run with PHPUnit. `KernelTestCase` boots the kernel and exposes the test container
(`static::getContainer()`, private services included); `WebTestCase` adds `createClient()` and response
assertions; Panther drives a real browser. The `test` environment reads `.env.test`.

## Known traps

- **XML configuration still works in 7.4 but is gone in 8.0**: new code declares services and routes in YAML, PHP or attributes, so an upgrade does not start with a rewrite.
- **A listener's priority decides what it sees**: before the router (priority > 32) there is no route yet;
  before the firewall there is no user.
- **`access_control` stops at the first matching rule**, in declaration order.
- **An attribute on a class outside `App\` does nothing** unless that directory is autoconfigured.
- **The `debug:config` output starts with a title line** before its JSON.
- **Scalar constructor arguments are not autowired**: the container fails at compile time, not at runtime.
- **Private services are removed or inlined**: fetching one from the container outside tests fails.

## Sources

https://symfony.com/doc/current/components/http_kernel.html
https://symfony.com/doc/current/reference/events.html
https://symfony.com/doc/current/service_container.html
https://symfony.com/doc/current/messenger.html
https://symfony.com/doc/current/console.html
https://symfony.com/doc/current/testing.html
