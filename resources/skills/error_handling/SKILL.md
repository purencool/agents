---
name: error-handling
description: Handle application errors, exceptions, and failure responses following PNC conventions. Use when an error occurs, an exception is thrown, or a non-2xx response is received.
---

# Error Handling

## Principles

1. Catch at the boundary, not deep in logic.
2. Log the full context (request ID, user ID, stack trace) via `PncServices\Logging\LoggingService`.
3. Return structured error responses — never expose internal details to end users.

## 500 Errors

- Use `App\Exceptions\Handlers\Pnc500Handler` for unhandled exceptions.
- Always include a correlation ID in the response body.
- Trigger the `pnc:test-500-loggin` artisan command in staging to verify logging works.

## Retry Policy

- Idempotent operations: retry up to 3 times with exponential backoff.
- Non-idempotent operations: do NOT auto-retry; surface the error to the user.

## References

- See `references/exception-hierarchy.md` for the full exception class tree.
