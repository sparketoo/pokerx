---
paths:
  - 'app/Listener/**'
  - 'config/autoload/listeners.php'
---

# Listeners

## Do Not Reuse Entry Points
Do not call Controllers, Commands, Jobs, or other Listeners to reuse business behavior; use a Service or an existing domain operation.

## Delegate Only Reusable Domain Operations
Keep event filtering, logging, and listener-specific orchestration in the Listener; delegate reusable business operations to a Service or Action.

## Respect Hyperf event registration
Use the installed `hyperf/event` listener contract and the project's annotation or `config/autoload/listeners.php` registration pattern; make repeated or cross-worker event effects safe when they update shared state.
