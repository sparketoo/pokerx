---
paths:
  - 'app/Vo/**'
---

# Value Objects

## Require a Shared Data Contract
Create a `Vo` for structured data used across production files; keep single-use data local and return a scalar or void when no structured result is needed.

## Centralize and Name Value Objects
Place concrete value objects under `App\Vo\<Domain>`, suffix their names with `Vo`, and extend `App\Vo\Vo` when its JSON serialization contract applies.

## Declare Fixed Payloads Explicitly
Use typed constructor properties for stable fields, make immutable fields `readonly`, and keep persistence, external calls, and workflow orchestration outside the value object.

## Treat dynamic payloads as arrays until needed
Use documented array shapes or a deliberate domain type for variable keys; do not introduce a `Fluent` dependency that this project does not use.
