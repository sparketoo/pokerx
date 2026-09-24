---
paths:
  - 'test/**'
---

# Testing Best Practices

## Keep one test file per subject in each layer
For each production file, use at most one matching test file in `test/Unit` and at most one in `test/Feature`, mirror its directory and base name, and use both layers only for distinct contracts; keep existing cross-cutting contract tests intact.

## Keep Feature Tests Subject-Focused
Exercise collaborators through the primary subject's public contract, and assert observable behavior rather than unrelated implementation details.

## Keep Test Support Outside Test Files
Place named fixtures and fakes under `test/Fixtures` with the `Tests\Fixtures` namespace; load shared bootstrap setup from `test/bootstrap.php` and keep one-off helpers local.

## Check for an existing test before adding one
Search both test layers for the subject and behavior, extend the matching file when appropriate, and avoid duplicated cross-layer assertions.

## Follow Hyperf test bootstrap conventions
Use the current `test/Pest.php`, `test/TestCase.php`, and `hyperf/testing` setup; verify any database-reset trait or helper exists and is configured before using it.

## Keep Each Test Case Focused
Make each `it()` or `test()` verify one independent target, with multiple assertions only when they support that target; rerun the changed case after editing it.
