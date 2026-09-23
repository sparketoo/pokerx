---
paths:
  - 'app/Command/**'
---

# Commands

## Do Not Reuse Entry Points
Do not call Controllers, other Commands, Jobs, or Listeners to reuse business behavior; use a Service or an existing domain operation.

## Delegate Only Reusable Domain Operations
Keep command argument parsing, prompts, output, and command-only orchestration in the Command; delegate reusable domain operations to a Service or Action if present.

## Check the command runtime
Use `Hyperf\Command\Command` and `php bin/hyperf.php`; verify whether a command runs inside a coroutine before using coroutine-only clients or spawning child coroutines.
