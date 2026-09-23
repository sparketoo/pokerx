---
paths:
  - 'app/Subscriber/**'
---

# Subscribers (if introduced)

## Do Not Reuse Entry Points
Do not call Controllers, Commands, Jobs, or Listeners to reuse business behavior; use a Service or an existing domain operation.

## Delegate Only Reusable Domain Operations
Keep subscriber-specific event handling in the Subscriber and delegate reusable operations to a Service or Action; first consider the existing Hyperf Listener pattern because this project has no Subscriber module today.
