---
paths:
  - 'app/Support/**'
---

# Helpers

## Centralize Shared Helper Functions
Place shared stateless utility functions in `app/Support/functions.php` under `App\Support`; use dependency-injected services for behavior that needs collaborators or state.

## Follow Helper Function Naming
Use descriptive function names consistent with existing helpers, type parameters and return values, and avoid duplicating PHP or Hyperf helpers; add `function_exists()` guards only when redeclaration is a real integration concern.

## Keep Local Utilities Local
Keep utilities used only inside one class as private methods and reuse existing helpers before adding another shared function.

## Keep Business Workflows Out of Helpers
Keep actor-specific decisions and database, queue, or external-service workflows in their owning entry points or services, not in global helpers.
