---
paths:
  - 'app/Notification/**'
  - 'app/Service/Notification/**'
  - 'config/autoload/notifications.php'
---

# Notification Best Practices (if introduced)

## Define an SMS channel contract
If Aliyun SMS or another notification provider is added, keep provider transport behind a typed channel/service contract and represent template ID and variables as a small message value object rather than constructing provider requests in controllers.

## Centralize provider configuration
Keep credentials, endpoint, and any shared sign name in `config/autoload/` with environment-backed secrets; do not let arbitrary callers override provider-wide identity settings.

## Resolve recipients at one boundary
Resolve and validate each recipient through a dedicated user/recipient method or notification service before enqueueing, rather than letting each caller assemble phone numbers independently.

## Make delivery failures visible and retryable
Use a runtime or provider exception for configuration and transport failures, and define timeout, retry, idempotency, and logging behavior if delivery runs through `hyperf/async-queue` or `hyperf/amqp`.
