---
paths:
  - 'app/Model/**'
  - 'config/autoload/databases.php'
---

# Hyperf Model Best Practices

## Extend the project model
Place models in `App\Model` and extend `App\Model\Model`, which extends `Hyperf\DbConnection\Model\Model`; follow the project's `$casts`, `$hidden`, and guarded/fillable conventions supported by the installed Hyperf version.

## Keep Model PHPDoc Synchronized
Keep `@property` and `@property-read` declarations aligned with migrations, casts, computed attributes, and relationships.

## Type Relationships Precisely
Declare relationship return types and generic PHPDoc for related model and parent types using the installed Hyperf relationship classes.

## Declare and test changed contracts
When changing hidden fields, casts, accessors, mutators, or relationships, add focused tests for the behavior and serialized output affected by the change.

## Reuse the User Relationship
Use `App\Model\Concerns\BelongsToUser` for a model's `user_id` relationship when it fits, instead of duplicating `user()`.
