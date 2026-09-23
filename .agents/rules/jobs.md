---
paths:
  - 'app/Job/**'
  - 'config/autoload/async_queue.php'
---

# Jobs

## Do Not Reuse Entry Points
Do not call Controllers, Commands, other Jobs, or Listeners to reuse business behavior; use a Service or an existing domain operation.

## Delegate Only Reusable Domain Operations
Keep job-specific orchestration in the Job and delegate reusable business operations to a Service or Action; keep payloads serializable and small.

## Make queue effects idempotent
Treat `hyperf/async-queue` jobs as retryable and potentially duplicated across machines; carry a stable business identifier, use atomic writes or uniqueness where needed, and define retry/failure behavior in the Job and queue configuration.

## Centralize future user-task dispatch
If a user-triggered task abstraction is introduced, route dispatch through one Service with a typed payload or validated input, return promptly after enqueueing, and expose task identity and failure state through shared storage rather than worker memory.
