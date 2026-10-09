# Month cache + transaction review revision plan

Reference: Amtgard IDP (`amtgard/amtgard-bastion-idp`) JWT PVH cache/worker pattern on `amtgard/redis-set-queue` **1.1.x** (`PubSubQueue::publish`, `redrive`, `subscribe`, `callConsumers` — not README `send`/`pump`).

Denarius already wires `PubSubQueue` + `SetQueue` for `LedgerWorker::QUEUE` (`denarius-refresh`) via `PubSubMessageQueue`.

---

## Goals

1. **No TTL on public month statement cache** — entries live until explicitly replaced or deleted on a change signal.
2. **Signal-driven cache maintenance** — use `redis-set-queue` to rebuild (warm) cache after ledger/publication changes, IDP-style: publish on write path, worker rebuilds Redis blob.
3. **Transaction review paginated by calendar month** — manage UI shows one month at a time with prev/next navigation (same mental model as public kingdom page).

---

## Current behavior (baseline)

| Piece | Behavior |
|-------|----------|
| `KingdomPageController` | Uses `MonthReader` → `CachingMonthReader` → `KingdomPageQuery` (public pipeline). |
| `CachingMonthReader` | JSON in Redis via `RedisKeyValueStore::setex`, **TTL 86400s**. Keys include a **generation** suffix from `denarius:month-gen:{kingdomId}`. |
| `MonthInvalidator::forget` | Increments generation; old keys become unreachable but **orphan in Redis** until TTL (without TTL this leaks). |
| Signals calling `forget` | `TransactionReviewService::update`, `TransactionSynchronizer::sync`, parts of `EnrollmentService`. **Not** kingdom settings / account publish. |
| Manage review | `TransactionReviewQueue::rowsForManage` loads **all** candidates on published accounts, sorted by `postedOn` desc. |
| Worker | `LedgerWorker` only runs `ledger` refresh jobs. |

---

## Part A — Persistent cache keys (no TTL)

### A1. `KeyValueStore` / `RedisKeyValueStore`

- Add `setPersistent(string $key, string $value): void` using Redis `SET` (no expiry).
- Keep `set($key, $value, $ttlSeconds)` for other callers (sessions unrelated).
- `CachingMonthReader` uses **only** `setPersistent`.

### A2. Key strategy (drop generation counter OR pair with deletion)

**Recommended:** stable keys per kingdom/mode/month:

`denarius:month:{kingdomId}:{displayMode}:{monthKey}`  
(example: `denarius:month:4:less_redacted:2026-09`)

- **Invalidate:** `DELETE` that key (or keys matching kingdom) then enqueue rebuild (Part B).
- Remove `MonthCacheKeys::generation()` / `MonthInvalidator` increment pattern **or** retain increment only if worker deletes old keys — prefer **delete + stable key** for clarity without SCAN.

Migration note: deploy will leave old `denarius:month:*:*:gen:*` keys harmless; optional one-time Redis cleanup not required for correctness.

### A3. Read path (`CachingMonthReader`)

1. `GET` stable key → decode → return if valid.
2. On miss / corrupt JSON → call origin `KingdomPageQuery::statement`, `setPersistent`, return (sync fill — keeps page usable if worker down).

No TTL anywhere on month statements.

---

## Part B — Queue-driven cache refresh (IDP pattern)

### B1. IDP pattern (to mirror)

- `RedisCacheRepository::setPvhRecord` — `Redis::set` **without TTL**.
- `queueUserValidation` — `PubSubQueue::publish($queueName, $key, $json)`.
- Dedicated worker (`bin/jwt-pvh-worker.php`) — `redrive`, `subscribe`, loop `callConsumers`, on failure re-`publish`.

### B2. Denarius design

**New queue name:** `denarius-month-cache` (constant on worker class).

**Message payload (JSON):**

```json
{
  "type": "month_cache",
  "kingdom_id": 4,
  "month": "2026-09",
  "modes": ["less_redacted"]
}
```

- `modes` optional; default all modes that `DisplayMode::fromStored` might use for that kingdom, or always warm three keys: `summarized`, `redacted`, `less_redacted` for the month (simplest, ~3 Redis writes per job).
- **Dedup key** for publish: `{kingdom_id}:{month}` (SetQueue replaces same key — use `publish(..., $replace = true)` as today).

**Publisher service:** `MonthCacheRefreshPublisher`

- `schedule(int $kingdomId, ?MonthWindow $month = null): void`
- If `$month` null, enqueue **each distinct `posted_on` month** among published-account transactions for kingdom (cap e.g. last 24 months) OR enqueue “full kingdom” job that worker expands — prefer **explicit month list** from caller when known.
- Call sites replace or supplement `MonthInvalidator::forget`:
  - `TransactionReviewService::update` — months touched by updated teller ids + kingdom display mode.
  - `TransactionSynchronizer` — months affected by synced rows (or schedule wide refresh for kingdom).
  - `KingdomSettings::update` — all months with published rows (or generation wipe + schedule 12 months).
  - Manager **accounts** publish save — same as settings.

**Delete before publish:** for each `(kingdom, mode, month)` delete Redis key, then publish one queue message per month (not per mode) and let worker warm all modes.

**Worker:** extend existing worker infrastructure (choose one):

| Option | Pros |
|--------|------|
| **B2a.** Add `MonthCacheRefreshJob` to `RefreshJobRegistry` + run on **same** `LedgerWorker` queue | One container, shared `bin/ledger-worker.php` |
| **B2b.** New `MonthCacheWorker` + `bin/month-cache-worker.php` + second `addQueue` on `PubSubQueue` | Isolation like IDP PVH worker |

**Recommendation:** **B2a** first (new job type `month_cache` on `denarius-refresh`) to reuse Docker worker compose; split later if needed.

**Job handler:** resolve kingdom by id, build `MonthWindow` from `month`, for each mode call **`KingdomPageQuery::statement` directly** (not `CachingMonthReader`) and write Redis via shared `MonthCacheWriter` helper (encode same as `CachingMonthReader`).

### B3. Wire container

- Register `MonthCacheRefreshPublisher` with `MessageQueue` + kingdom repo.
- Register job in `RefreshJobRegistry`.
- `MonthInvalidator` becomes thin facade: `invalidate(KingdomRecord|int $kingdomId, ?MonthWindow ...)` → delete keys + `publisher->schedule`.

### B4. Tests

- `MonthCacheTest`: remove TTL assertions; assert `setPersistent`; delete + miss refills.
- New `MonthCacheRefreshJobTest`: publisher publishes payload; job writes Redis key.
- Log branches: `month_cache_refresh_scheduled`, `month_cache_refresh_complete`, `month_cache_refresh_failed`.
- Update `TracedServicesMethodsTest` / `ServiceWorkerArrange` counts if new traced methods.

---

## Part C — Transaction review by month

### C1. HTTP / controller

- Query param on manage page: `review_month=YYYY-MM` (same format as public `MonthWindow::fromQuery`).
- Default: `MonthWindow::current(now)` or **latest month that has any review-row** (if current empty, step back — document choice in code).

### C2. `TransactionReviewQueue`

- `rowsForManage(KingdomRecord $kingdom, MonthWindow $month): array`
- Filter `$line->getPostedOn()` with `$month->contains()`.
- Keep sort **postedOn desc** within month.

### C3. `manage.twig`

- Month nav (reuse pattern from `kingdom.twig`: previous / label / next).
- Hidden field or link preserves `review_month` on POST `transactions/review` (only submit rows for **visible month** — do not POST all kingdom txns).
- Copy: “Showing transactions posted in {month}.”

### C4. `TransactionReviewService::update`

- Only process `review_id[]` posted (already scoped by form).
- After update, `MonthCacheRefreshPublisher` for affected transaction months (from `posted_on` on each id).

### C5. Tests

- `TransactionReviewTest`: queue filters by month; two months of data, request one month → count 1.
- Controller/manage test optional; HTTP trace if controller parsing added.

---

## Part D — Implementation order (for implementer)

1. KeyValueStore persistent set + stable cache keys + remove TTL/generation from read/write path.
2. `MonthCacheWriter` + `MonthCacheRefreshJob` + publisher; wire signals; keep sync miss fill.
3. Transaction review month filter + manage UI + form scope.
4. Full `composer test` + coverage gate; update docs `docs/reference/milestones.md` stub if needed.

---

## Part E — Out of scope (follow-ups)

- Redis SCAN cleanup of legacy gen-keys.
- Separate worker container for month cache only (B2b).
- DB-level `posted_on` filtering for review queue (optimize when kingdoms have huge history).
- Fixing `PubSubMessageQueue::callConsumers` return value (currently always `0`).

---

## IDP files to cite during implementation

| IDP artifact | Use |
|--------------|-----|
| `src/Persistence/Server/Repositories/RedisCacheRepository.php` | Persistent Redis set + `publish` enqueue |
| `bin/jwt-pvh-worker.php` | Worker loop, re-publish on failure |
| `config/container/sessions-redis.php` | Second queue on shared `PubSubQueue` |
| `agent/cursor/jwt-pvh-cache/detailed-design.md` | API lock (`publish`, `callConsumers`) |

Denarius analogs: `RedisKeyValueStore`, `PubSubMessageQueue`, `LedgerWorker`, `config/container.php` PubSubQueue setup.
