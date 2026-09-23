---
paths:
  - 'migrations/**'
---

# Migrations

## Persist Enum Case Names
Define enum columns with XxxEnum::names() and enum defaults with XxxEnum::CASE->name.

## Migrate Renamed or Removed Cases
When renaming or removing a persisted enum case, migrate existing data and verify compatibility in the same change.

## Follow the Hyperf migration structure
Use `Hyperf\Database\Migrations\Migration` and `Hyperf\Database\Schema\Blueprint` in `migrations/`; inspect neighboring migrations for IDs, timestamps, precision, indexes, and `down()` behavior.

## Preserve authentication relationships
When changing users or user tokens, review foreign keys and deletion behavior so dependent authentication records do not outlive their owner; do not add unrelated device tables or cascades without a feature requirement.
