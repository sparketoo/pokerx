---
paths:
  - 'app/Exception/**'
  - 'config/autoload/exceptions.php'
  - 'storage/languages/*/errors/**'
---

# Exception Handling Best Practices

## Choose the Correct Exception Type
Use `RuntimeException` or a native exception for runtime, configuration, environment, and programming failures; use `AppException` for expected business or input failures that need the project's localized error response.

## Place Application Exceptions by Scope
Put cross-module failures in `FoundationException`, authentication failures in `AuthException`, and game or provider failures in `GameException`; add a focused exception class only when a new domain needs it, and reuse an existing factory when semantics match.

## Derive Error Codes from Factory Methods
Give each business error one public static factory named for the resource or failure reason; `AppException` derives the snake_case code from that camelCase method name, so avoid duplicate method names that produce ambiguous codes.

## Follow Exception Translation Naming
Use the camelCase factory method as the translation key in `storage/languages/{locale}/errors/{exception_without_Exception_in_snake_case}.php` for each supported locale.

## Preserve the Exception Message Fallback Chain
Preserve `AppException::getLocaleMessage()` fallback order: current locale, configured fallback locale, `errors/foundation.serverError`, then the safe hard-coded message.

## Separate Public and Diagnostic Data
Use `replace` for translation placeholders, `details` only for client-safe structured data, and `context` only for logs; do not expose secrets or internal diagnostics in the response.

## Assign Report Levels Consistently
Leave routine business failures at the default `info` level, and use `warning()` or `error()` in exception factories only for failures that warrant that severity.

## Let the Hyperf handler render HTTP failures
Keep HTTP error serialization in `AppExceptionHandler` configured by `config/autoload/exceptions.php`; preserve the existing business error envelope and status behavior unless the API contract is explicitly changed.
