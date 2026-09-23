---
paths:
  - 'app/Action/**'
---

# Action Best Practices

## Keep Entry-Point-Local Workflows Local
Keep a workflow in its owning Controller, Job, Command, Listener, or other entry point when it serves only that entry point and has no reusable domain meaning; an `Action` layer is optional in this project.

## Keep Pure Queries Out of Actions
Do not create an Action for a pure query; use the existing model or service pattern unless the query is part of an Action's complete domain operation.

## Extract Business Operations, Not Shared Code
Do not create an Action merely to deduplicate statements, reuse technical steps, or shorten an entry point; when introduced, it must represent a complete domain operation with a business outcome.

## Judge Reuse by Domain Meaning
Treat an operation as reusable when the same business capability can be invoked unchanged from different entry points, regardless of its current number of call sites.

## Name Actions by Business Operation
If an Action layer is introduced, group classes under `App\Action` by domain, name each `{Verb}{Noun}Action`, and expose one public `handle()` method.

## Keep Actions Stateless
Store only stable dependencies and pass execution data through `handle()` without retaining request-specific mutable state in a long-lived object.

## Keep Actions Environment-Agnostic
Accept typed domain data, return domain results, and avoid depending on HTTP, queue, console, or listener runtime objects.

## Compose Actions Explicitly
Actions may compose other Actions or Services when that reflects a real domain operation; avoid circular dependencies and unbounded call chains.

## Define Atomic Boundaries at the Owner
Open a `Hyperf\DbConnection\Db` transaction at the layer owning the complete atomic operation, whether Action, Service, or entry point; do not span child coroutines or external I/O with an implicit shared transaction.

## Test Action-Owned Behavior
Test each introduced Action's business result and side effects through `handle()` under the relevant `test/Unit` or `test/Feature` layer.
