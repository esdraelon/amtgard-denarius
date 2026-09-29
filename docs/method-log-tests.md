# Method log test orchestrator

Parent stack tip: `logging-rest` at `6cead41`. Each milestone is one branch and one commit. Do not push.

## Why coverage stayed high

The logging spike wrapped existing method bodies in `DenariusLog::trace()`. PHPUnit already exercised many of those bodies through hand-built fakes in `ApplicationTest`, adapter tests, and `ControllerTest`. The wrapper lines run whenever the body runs, so line coverage barely moved even though almost no new assertions checked logging.

That is weak assurance: the recorder can show unbalanced or missing traces only when a test actually calls the instrumented method. Most methods are never invoked in tests.

## Contract

Each milestone adds tests that prove the **enter/leave** (or **enter** for constructors) path ran for every `DenariusLog::trace` / `DenariusLog::enter` in its scope.

Use `Amtgard\Denarius\Tests\Support\MethodLogAssert`:

- `MethodLogAssert::reset()` clears the active `RecordingMethodLog` (installed in `tests/bootstrap.php`).
- `MethodLogAssert::assertTraced(string $method)` fails if `$method` was not both entered and left (or failed) during the test.
- `MethodLogAssert::assertConstructorEntered(string $method)` for `enter`-only constructors.

Prefer extending existing fakes and one focused call per method over a giant generated file. One test method may assert several related traces if they share one arrange block.

Also assert **stderr JSON** only in `LoggingCoreTest` / `StderrMethodLog` tests; do not duplicate stderr checks in every area.

### TracedMethodCatalog

`Amtgard\Denarius\Tests\Support\TracedMethodCatalog::forProject()->all()` returns every `__METHOD__` string referenced by `DenariusLog::trace(__METHOD__` or `DenariusLog::enter(__METHOD__` under `src/`, sorted and deduplicated. Use `methodsInFile($absolutePath)` when scoping a milestone to one directory.

When adding or extending a test for an area milestone (M-02 onward):

1. Filter the catalog (or a subtree of `src/`) to methods in your scope, e.g. `Controller` and `Utilities/Http`.
2. For each catalog entry not yet covered, extend an existing fake test or add one focused call that invokes that method, then `MethodLogAssert::assertTraced($method)` or `assertConstructorEntered($method)` as appropriate.
3. Re-run the catalog locally to confirm the method string matches what the runtime recorder sees (`__METHOD__` in the instrumented body).

Gates: `composer test` line coverage of `src/` at least 95%, Infection MSI and covered MSI at least 80%.

## Milestones

- [x] M-01 `log-test-support` — `MethodLogAssert`, optional `TracedMethodCatalog` (reflection list of `__METHOD__` strings under `src/`), and a short note in this doc on how to map catalog entries to tests. Gates pass.
- [x] M-02 `log-test-http` — Every `DenariusLog` site under `src/Controller` and `src/Utilities/Http` has at least one test assertion (new or extended tests).
- [x] M-03 `log-test-auth` — Same for `Utilities/Auth`, `Domain/Access`, `Service/Access`.
- [x] M-04 `log-test-persistence` — Same for `Persistence`.
- [ ] M-05 `log-test-services` — Same for `Service` (except `Service/Access`) and `Worker`.
- [ ] M-06 `log-test-bank` — Same for `Domain/Bank`.
- [ ] M-07 `log-test-rest` — Same for Statement, Kingdom, Setup, Queue, Session, Security.

After M-07, run a catalog diff: any `DenariusLog::trace` / `enter` in `src/` without a documented test reference fails CI (optional script in `bin/` or a PHPUnit test that reads a manifest updated each milestone).

Each milestone checks its box here and appends to `docs/milestones.md`.
