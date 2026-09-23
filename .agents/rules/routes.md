---
paths:
  - 'config/routes.php'
---

# Routing Best Practices

## Use Only GET and POST
Follow the current `Hyperf\HttpServer\Router\Router::get()` and `Router::post()` convention for HTTP endpoints; add another method only when the public contract requires it.

## Keep Route URIs Static
Keep existing API URIs parameter-free, passing GET input in query strings and POST input in request bodies; introduce path parameters only with an explicit contract change.

## Keep route registration inspectable
Keep route declarations valid under `php bin/hyperf.php describe:routes`, avoid duplicate method/path pairs, and use `Router::addServer()` for the `poker` WebSocket server routes.

## Group Routes by Module
Keep routes for a module together in `config/routes.php`; use Hyperf router groups when they simplify repeated prefixes without changing existing URIs or middleware behavior.
