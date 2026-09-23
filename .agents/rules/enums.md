---
paths:
  - 'app/Enum/**'
  - 'storage/languages/*/enums/**'
  - 'test/**/Enum/**'
---

# Enum Best Practices

## Define Domain Enums Consistently
Place domain enums in `App\Enum`, suffix class names with `Enum`, use unbacked enums when storing case names, and name cases in `UPPER_SNAKE_CASE`.

## Reuse EnumHelper
Use `App\Enum\Concerns\EnumHelper` for domain enums that need its shared parsing, collection, comparison, wire, or label behavior; keep shared behavior out of individual enums.

## Parse Case Names Through EnumHelper
Use `fromName()` for optional parsing and `fromNameOrFail()` when the case must exist; do not hand-roll loops or maps for the same case-name behavior.

## Compare Enum Instances
Compare enum instances in business code instead of comparing case-name strings.

## Add a Predicate for Every Case
When callers need a case predicate, name it `isXxx(): bool`, derive `Xxx` from the case name in StudlyCase, and delegate to `is()`; do not add unused predicates automatically.

## Follow Enum Translation Naming
Store translations in `storage/languages/{locale}/enums/{enum_without_Enum_in_snake_case}.php` and resolve them through `EnumHelper::label()` with `enums/{file}.{$enum->name}`.

## Reference Enum Cases in Translation Files
Use `XxxEnum::ITEM->name` as translation array keys where the enum exists; avoid duplicating persisted case names as string literals.

## Keep Labels Presentation-Only
Use `label($locale)` only for display; follow the current `EnumHelper` behavior of requested/current locale and case-name fallback unless changing that contract with tests.

## Synchronize Enum Translations
When adding, renaming, or removing a translated case, update the existing `en` and `zh-CN` files and relevant label tests in the same change.

## Test Enum Contracts by Layer
Unit tests cover changed case parsing, predicates, and persisted names; add translation-level tests for changed English and Chinese labels without duplicating the same behavior across layers.

## Keep Enum Case Names Stable
Treat enum case names as stable identifiers for persistence and external contracts.
