# Logging spike

Debug aid for the local app. Each milestone is one stacked branch and one commit. Start at the first unchecked milestone. Do not push.

Parent of M-01: `provider-framework` at `7f716d3`.

This is not the IDP SQLite log-read platform. Lines go to stderr as JSON so `docker logs` shows which method ran. A later milestone can add a query store.

## Contract

`DenariusLog` is a facade installed once per process. Call sites do not construct it.

```php
return DenariusLog::trace(__METHOD__, function () use ($request, $response): ResponseInterface {
    return $this->html->html($response, 'home.twig', []);
});
```

`trace` writes an enter line, runs the closure, writes leave, and returns the closure's value. On a throwable it writes fail, then rethrows. Removing `trace()` removes the work, so existing tests fail.

Constructors assign the enter result so the call is not a bare statement:

```php
$entered = DenariusLog::enter(__METHOD__);
```

Do not log inside `DenariusLog` itself. That recurses.

JSON fields: `time`, `level`, `channel`, `event` (`enter`, `leave`, `fail`), `method`, `request_id`, `context`. Channel comes from the method's namespace: Controller and `Utilities/Http` → `http`; `Utilities/Auth`, `Domain/Access`, `Service/Access` → `auth`; `Domain/Bank`, `Service/Ledger`, `Service/Enrollment`, `Worker` → `ledger`; everything else → `app`.

Redact context values whose key matches `secret`, `token`, `password`, `authorization`, `cookie`, `client_secret`, `access_token`, or `refresh_token` (case-insensitive, nested). Replace the value with `[redacted]`.

`APP_DEBUG=true` writes enter, leave, and fail. Otherwise write fail only, and still run the closure. Logging I/O must not escape the logger (Monolog `WhatFailureGroupHandler` to `php://stderr`).

`RequestLogContext` holds `request_id` for the process, same shape as `CurrentActor` (`set`, `id`, `reset`). HTTP middleware accepts `X-Request-Id` when it matches `/^[A-Za-z0-9_-]{8,64}$/` and otherwise uses 16 hex chars. The worker process sets one id at startup.

Tests install a recording logger from `tests/bootstrap.php`. It runs the closure and keeps enter/leave/fail method names. A PHPUnit subscriber fails a test when an entered method was not left or failed. That kills a removed `trace()` on void methods.

## Milestones

- [x] M-01 `logging-core` — Logger, redaction, stderr JSON, request id, test recorder. Wire install in `public/index.php`, `bin/ledger-worker.php`, and `config/container.php`. Add the HTTP middleware. Instrument only the new logging classes' public methods via the same `trace`/`enter` rules where that would not recurse. Gates: `composer test` line coverage of `src/` at least 95%, Infection MSI and covered MSI at least 80%.
- [ ] M-02 `logging-http` — Every method in `src/Controller` and `src/Utilities/Http`.
- [ ] M-03 `logging-auth` — Every method in `src/Utilities/Auth`, `src/Domain/Access`, and `src/Service/Access`.
- [ ] M-04 `logging-persistence` — Every method in `src/Persistence` and `src/Persistence/Orm.php`.
- [ ] M-05 `logging-services` — Every method in `src/Service` except `Service/Access`, and in `src/Worker`.
- [ ] M-06 `logging-bank` — Every method in `src/Domain/Bank`.
- [ ] M-07 `logging-rest` — Every method in `src/Domain/Statement`, `src/Domain/Kingdom`, `src/Utilities/Setup`, `src/Utilities/Queue`, `src/Utilities/Session`, and `src/Utilities/Security`.

Skip interfaces with no body. After M-07, every remaining method under `src/` either traces or is a constructor that assigns `enter`.

Each milestone appends a section to `docs/milestones.md`: branch, what changed, line-coverage fraction, Infection covered MSI. Check the box here in the same commit.
