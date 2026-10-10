# Logging spike

Debug aid for the local app. Each milestone is one stacked branch and one commit. Start at the first unchecked milestone. Do not push.

Parent of M-01: `provider-framework` at `7f716d3`.

**Local durable logs (IDP-aligned):** when `LOG_SPOOL_ENABLED=true` (default), each method-log JSON line is buffered for the request and appended once at shutdown to `LOG_ROOT/spool/active.jsonl`. Run **`bin/log-sqlite-writer.php`** (or the optional `log-writer` compose profile) to drain spool into hourly SQLite WAL files under `LOG_ROOT/trace/YYYY-MM-DD/HH.logs.sqlite`. Query with **`bin/denarius-logs.php query --request-id=…`** or export **`bundle --request-id=…`**. See [sqlite-logging-checklist.md](../planning/sqlite-logging-checklist.md).

**stderr / jsonl:** Lines still go to stderr as JSON so `docker logs` shows which method ran. `logs/method-trace.jsonl` is the same JSON as the spool (not a different schema); it is only an optional on-disk mirror. With spool enabled, the implicit `APP_DEBUG` default path is not written unless you set `DENARIUS_METHOD_LOG` explicitly.

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

JSON fields: `time`, `level`, `channel`, `event` (`enter`, `leave`, `fail`, `branch`), `method`, `request_id`, `context`, and for branch lines `branch` (decision name). Channel comes from the method's namespace: Controller and `Utilities/Http` → `http`; `Utilities/Auth`, `Domain/Access`, `Service/Access` → `auth`; `Domain/Bank`, `Service/Ledger`, `Service/Enrollment`, `Worker` → `ledger`; everything else → `app`.

Decision branches use `DenariusLog::debugBranch`, `infoBranch`, and `warnBranch`. They emit `event: branch` with a stable `branch` key (for example `csrf_reject`, `principal_sync_session`, `webhook_auth_denied`, `auth_admin_denied`). Info and warn branches always write to stderr; debug branches follow `APP_DEBUG`. Context is redacted like trace context. Tests assert branch lines separately via `MethodLogAssert::assertBranchLogged`.

Redact context values whose key matches `secret`, `token`, `password`, `authorization`, `cookie`, `client_secret`, `access_token`, or `refresh_token` (case-insensitive, nested). Replace the value with `[redacted]`.

`APP_DEBUG=true` writes enter, leave, and fail. Otherwise write fail only, and still run the closure. Logging I/O must not escape the logger (Monolog `WhatFailureGroupHandler` to `php://stderr`).

`RequestLogContext` holds `request_id` for the process, same shape as `CurrentActor` (`set`, `id`, `reset`). HTTP middleware accepts `X-Request-Id` when it matches `/^[A-Za-z0-9_-]{8,64}$/` and otherwise uses 16 hex chars. The worker process sets one id at startup.

Tests install a recording logger from `tests/bootstrap.php`. It runs the closure and keeps enter/leave/fail method names. A PHPUnit subscriber fails a test when an entered method was not left or failed. That kills a removed `trace()` on void methods.

## Milestones

- [x] M-01 `logging-core` — Logger, redaction, stderr JSON, request id, test recorder. Wire install in `public/index.php`, `bin/ledger-worker.php`, and `config/container.php`. Add the HTTP middleware. Instrument only the new logging classes' public methods via the same `trace`/`enter` rules where that would not recurse. Gates: `composer test` line coverage of `src/` at least 95%, Infection MSI and covered MSI at least 80%.
- [x] M-02 `logging-http` — Every method in `src/Controller` and `src/Utilities/Http`.
- [x] M-03 `logging-auth` — Every method in `src/Utilities/Auth`, `src/Domain/Access`, and `src/Service/Access`.
- [x] M-04 `logging-persistence` — Every method in `src/Persistence` and `src/Persistence/Orm.php`.
- [x] M-05 `logging-services` — Every method in `src/Service` except `Service/Access`, and in `src/Worker`.
- [x] M-06 `logging-bank` — Every method in `src/Domain/Bank`.
- [x] M-07 `logging-rest` — Every method in `src/Domain/Statement`, `src/Domain/Kingdom`, `src/Utilities/Setup`, `src/Utilities/Queue`, `src/Utilities/Session`, and `src/Utilities/Security`.

Skip interfaces with no body. After M-07, every remaining method under `src/` either traces or is a constructor that assigns `enter`.

- [x] M-08 `branch-logging` — Branch helpers on `DenariusLog`, `PostCsrfMiddleware`, CSRF/auth/webhook/principal decision branches at info/warn/debug, and `BranchLoggingTest` assertions. Gates: `composer test`, `composer infection:ci`.

Each milestone appends a section to [milestones.md](milestones.md): branch, what changed, line-coverage fraction, Infection covered MSI. Check the box here in the same commit.
