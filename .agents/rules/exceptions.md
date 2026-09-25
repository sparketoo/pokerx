---
paths:
  - 'app/**/*.php'
  - 'config/autoload/exceptions.php'
  - 'storage/languages/*/messages.php'
---

# Exception Handling

## Construct Exceptions at the Failure Site
Use `throw new ...Exception(...)` where the failure is detected, and do not reintroduce static exception factories that move the recorded file and line.

## Keep Error Codes Numeric and Centralized
Pass an integer constant from `App\Constants\ErrorCode` to the exception constructor and use native `getCode()` for the client error code; never derive codes from class names or messages.

## Use Exception Classes by Responsibility
Use `BusinessException` for ordinary business rules, `AuthException` for authentication, `GameException` for game state, and `ProviderException` for external decision services; retain native exceptions for runtime and programming failures.

## Translate Before Construction
Pass a localized message from `Hyperf\Translation\__()` into the constructor using an explicit message key, and keep translation lookup out of `AppException`.

## Respect Coroutine Locale Boundaries
Use the HTTP locale middleware for HTTP requests, restore a WebSocket message's locale after processing, and pass locale explicitly to background coroutines that construct client-facing exceptions.

## Preserve Causes and Diagnostic Data
Pass wrapped failures as `previous`, keep client-safe fields in `details`, and keep sanitized upstream errors and transport details in log-only `context`.

## Keep Responses Safe and Consistent
Return integer `code`, localized `message`, and optional `details` for expected errors; map unknown exceptions to `ErrorCode::SERVER_ERROR` without exposing their messages.

## Log the Original Failure
Log the exception object, its creation file and line, and its diagnostic context for provider and unexpected failures while excluding credentials and session identifiers.
