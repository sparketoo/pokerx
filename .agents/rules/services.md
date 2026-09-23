---
paths:
  - 'app/Service/**'
---

# Services

## Reserve Services for Public Capabilities
Create a cohesive `{Noun}Service` for reusable domain capabilities; keep an entry-point-only workflow at its entry point until reuse or complexity justifies extraction.

## Keep Business Scenarios Out of Services
Keep request parsing, response formatting, and actor-specific authorization at entry points; services may own reusable reads, writes, and complete domain workflows such as the existing `GameService`.

## Keep Services Stateless
Store dependencies rather than request-specific data on a service; pass user, request, event, and command values through method parameters or an appropriate domain value object.

## Keep services independent of entry points
Services may depend on models, Hyperf components, clients, and contracts, and may enqueue a Job; do not make them call controllers, commands, listeners, or an active HTTP or queue context to reuse behavior.
