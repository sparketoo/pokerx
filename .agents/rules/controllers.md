---
paths:
  - 'app/Controller/**'
  - 'app/Request/**'
  - 'config/routes.php'
  - 'test/Feature/Controller/**'
---

# Controller Best Practices

## Separate controller domains
Place related API controllers under the existing `App\Controller` domain structure such as `Mine`; keep WebSocket handlers under `app/Game` and isolate future public or third-party callback controllers by domain.

## Extend the project API controller
Keep `AbstractController` and `ApiController` abstract; extend `ApiController` for JSON API controllers that use its user, response, and normalization helpers.

## Preserve route URI structure
Keep the explicit `config/routes.php` mapping and existing `/api/...` URI structure; new action segments should follow its snake_case convention, and renaming a controller must not silently change a public URI.

## Keep Controller Route URIs Unique
Do not register two actions with the same server, HTTP method, and full URI.

## Use Named Controller Actions
Expose HTTP endpoints through named public action methods; keep the existing direct `GameServer::class` WebSocket route as a separate server contract.

## Require Project Form Requests
For actions consuming query, body, or uploaded-file data, prefer a concrete `App\Request\FormRequest` and typed semantic getters; shared authentication headers may continue through the existing `ApiController` helpers.

## Mirror Form Request Directories
Place concrete Form Requests under `app/Request` directories matching their controller's domain and business path.

## Use Shared Response Helpers
Declare JSON API actions with `Psr\Http\Message\ResponseInterface` or the existing `JsonResponse` alias and return successes through `ApiController::success()`; keep the `code`, `message`, and `data` envelope rather than ad hoc arrays.

## Let Exceptions Use Global Rendering
Throw `AppException` subclasses from controllers and let `AppExceptionHandler` render failures; catch locally only for necessary cleanup, compensation, or translation of an external failure.

## Keep Business Middleware Out of Controllers
Configure global middleware in `config/autoload/middlewares.php` or route-specific middleware through Hyperf routing, rather than hiding authorization or validation behavior inside an action.

## Restrict Public Methods to Actions
Declare only route actions as public on concrete controllers; keep local helpers private and reusable controller helpers protected on an abstract base controller.

## Test Controller Route Contracts
When adding or changing an HTTP route, verify its server, method, URI, and handler mapping with `php bin/hyperf.php describe:routes` or an HTTP feature test; assert route names only if the route actually declares one.

## Assert Internal Success Responses
For changed successful API responses, assert HTTP 200, integer `code=0`, the expected `message`, and the endpoint-specific `data` shape and values.

## Assert Internal Failure Responses
For expected business or validation failures, assert the HTTP status and `code` used by `AppExceptionHandler`, plus client-visible `details`; do not assume framework `HttpException` failures use the business status.

## Assert Project Form Request Signatures
When changing an action that consumes input, test its concrete `App\Request\FormRequest` validation and semantic getters through the request or HTTP boundary.

## Delegate Only Reusable Domain Operations
Keep controller-only orchestration at the HTTP boundary and delegate complete reusable domain operations to a Service or an Action if that layer has been introduced.

## Keep Controller Tests at the HTTP Boundary
Controller Feature tests should cover route registration, middleware and authentication, Form Request validation, normalized responses, and observable behavior owned by the endpoint.

## Do Not Reuse Entry Points
Do not call Controllers, Jobs, Commands, or Listeners to reuse behavior; use Services or cohesive domain operations.

## Use Hyperf model queries for lists
Build database lists with the project's `Hyperf\DbConnection` model query builder and validated request filters; preserve user scoping, ordering, cursor or page pagination, and response shape unless the API contract changes.

## Preserve external callback contracts if introduced
If payment or provider callbacks are added, isolate them from internal APIs and preserve their registered public URIs, methods, signatures, and response contract when refactoring; coordinate any external endpoint change.

## Separate future channel authorization contracts
If cross-node broadcasting or channel authorization is added, define its authentication and 401/403 response contract explicitly using Hyperf middleware and shared state; do not silently reuse the internal business error wrapper.
