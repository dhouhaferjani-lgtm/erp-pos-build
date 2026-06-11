# Adversarial Plan Review: pos-location-aware-stock

**Date:** 2026-06-11
**Reviewer:** Codex (adversarial, r1)
**Plan:** `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md`
**Spec:** `docs/superpowers/specs/2026-06-11-pos-location-aware-stock-design.md`
**Prior spec reviews:** r1 REQUEST-CHANGES (resolved), r2 APPROVE-WITH-MINOR-EDITS 88%

**Verdict: REQUEST-CHANGES**
**Confidence: 86%**

---

## §1 Plan-vs-Spec Coverage

| Spec Section | Plan Task(s) | Status |
|---|---|---|
| §4.1 Location-scoped stock read (advisory, READ COMMITTED) | Task 5 (`StockLevelService` `as_of` query) | COVERED — see P2-3 |
| §4.2 Terminal→location resolution (auth-based, no client selector) | Task 2 (TerminalResource `location_id`) + Task 6 (sync endpoint) | CONTRADICTION — see BLOCKER-1 |
| §4.3 Server sync endpoint auth-resolves location | Task 6 (`GET /pos/stock-levels`) | CONTRADICTION — plan adds `?terminal_id` param |
| §4.4 SQLite `stock_levels` table | Task 3 (POS migration) | COVERED — see P1-3 (wrong migration shape) |
| §4.5 Stock sync service (client-side pull) | Tasks 8–9 (`StockSyncService`) | COVERED — see P1-7, P2-2 |
| §4.6 Pending-sale quantity deduction | Task 10 (`pendingSaleQty`) | COVERED — see P1-5 |
| §4.7 Cart UI stock gating | Task 11 (`cartStore` guard + toast) | COVERED — see P1-4 (`await addItem`) |
| §4.8 Location-aware stock display / `StockBadge` | Task 12 | COVERED |
| §4.9 Parity fixture update | Task 13 | COVERED — see P2-1 (PHP side missing) |

---

## §2 Code Snippet Verification

| Item | Status | Evidence |
|---|---|---|
| `Company::factory()` exists | VERIFIED | `apps/api/database/factories/CompanyFactory.php` — `Company` model has `HasFactory`; `Company::factory()` is valid |
| `Terminal` has factories | VERIFIED | `apps/api/database/factories/TerminalFactory.php` exists |
| `Terminal` has `company()` relation | MISMATCH | `apps/api/app/Modules/POS/Domain/Terminal.php` — no direct `company()` method; Terminal→location→company. Plan snippets using `$terminal->company` will 500. |
| `QuantityScale::round()` signature | MISMATCH | `apps/api/app/Shared/Domain/QuantityScale.php` — method is `bcformat(string $value, int $scale): string`, not `round(float, int)`. Task 4 snippet calls `QuantityScale::round($qty, 4)` which does not exist. |
| `DocumentType` namespace | VERIFIED | `App\Modules\Document\Domain\Enums\DocumentType` |
| `DocumentStatus` namespace | VERIFIED | `App\Modules\Document\Domain\Enums\DocumentStatus` |
| `StockLevel` `quantity`/`reserved` types | VERIFIED | Both cast to `string` (decimal columns); `getAvailableQuantity()` returns `string` via `bcsub` |
| `tenant()` callable in tenant migrations | NOT-FOUND | Zero hits for `tenant()` in `apps/api/database/migrations/tenant/`. Existing seeding migrations (e.g. `2026_04_25_100001_seed_company_fraud_detection_defaults.php`) use `Company::all()` directly — the per-tenant DB connection is already active via `DatabaseTenancyBootstrapper`. Do NOT call `tenant()` in tenant migrations. |
| `apiGet` strips pagination `meta` | VERIFIED | `apps/pos/src/lib/api.ts` — `apiGet` unwraps `response.data.data`; `meta.next_cursor` is lost. Task 9 must use raw `api.get(...)`. |
| `bcsub`/`bccomp` in `apps/pos` | NOT-FOUND | No PHP bc-math in `apps/pos/src/`. The POS uses `apps/pos/src/lib/decimal.ts` (Decimal.js-based) with `decimalAdd`, `decimalSub`, `decimalGte`. Task 10 snippet calling `bcsub` in TypeScript will fail at runtime. |
| `offline_receipts` line storage shape | VERIFIED | `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts` — lines stored as JSON blob in `offline_receipts.lines` column, NOT a child table. |
| `cartStore.addItem` async? | VERIFIED | `apps/pos/src/stores/cartStore.ts` — `addItem` is synchronous (Zustand `set()`). Task 11 snippet calling `await cartStore.addItem()` is incorrect. |
| `sonner` toast available | VERIFIED | Multiple files in `apps/pos/src/` import from `'sonner'`. Available. |
| SQLite migration object shape | MISMATCH | `apps/pos/src/lib/db/migrations.ts` uses `{ version: number, name: string, sql: string }`. Plan Task 3 uses `{ version, description, statements: string[] }` — wrong keys, migration will silently fail to register. |

---

## §3 Seam Analysis

### A. Task 1 — Backfill in tenant migration (P1)

**Claim:** Plan calls `tenant()->getKey()` inside the tenant migration to populate `location_id` on `stock_levels`.

**Finding:** No existing tenant migration uses `tenant()`. The pattern in `apps/api/database/migrations/tenant/2026_04_25_100001_seed_company_fraud_detection_defaults.php` uses `Company::all()` directly — `DatabaseTenancyBootstrapper` has already swapped the DB connection before the migration runs. The `tenant()` helper itself may resolve via `TenancyServiceProvider` during `artisan tenants:artisan migrate`, but:

1. **SQLite test path:** Tests running tenant migrations in a shared SQLite DB (no `TenancyBootstrapper` active) will throw because `tenant()` returns null.
2. **Fresh signup backfill:** The migration runs at tenant-creation time via the provisioning job. At that moment, `stock_levels` is empty (no products imported yet), so the backfill `UPDATE stock_levels SET location_id = (SELECT id FROM locations WHERE is_default = 1 LIMIT 1)` will UPDATE 0 rows — safe but useless.
3. **Existing tenant upgrade:** On tenants with existing `stock_levels` rows but no default location yet, the backfill may leave `location_id = NULL`, causing the subsequent `NOT NULL` constraint to fail the migration hard.

**Severity: P1** (migration upgrade failure on existing tenants)

**Fix:** (a) Use `Company::all()` pattern, not `tenant()`. (b) Keep `location_id` nullable in the initial migration + add a separate `--after-backfill-verified` migration to add NOT NULL. (c) Add guard: only alter the column to NOT NULL if the backfill count equals total rows.

---

### B. Task 5 — `as_of` before-read isolation (P2)

**Claim:** Plan captures `$asOf = now()` before the SELECT to handle concurrent writes.

**Finding:** `StockLevel` uses standard Eloquent with PG default `READ COMMITTED` isolation. Concurrent writes between `$asOf = now()` and the actual SELECT are visible; the `$asOf` timestamp does not provide MVCC snapshot isolation unless the read is wrapped in a `REPEATABLE READ` transaction. This is acceptable because the spec (§4.1) explicitly defines stock display as advisory-only; the hard guard is server-side at sale-post time.

**Severity: P2** (semantic imprecision, not a data-loss bug)

**Fix:** Add a plan comment: "Stock display is advisory — READ COMMITTED is acceptable per spec §4.1; hard gating is server-side at sale-post time. The `$asOf` timestamp is informational for the sync response, not a snapshot guarantee."

---

### C. Task 8 — `replaceIncoming`/`replaceAllStock` delete interaction (P1)

**Claim:** `replaceIncoming(page)` upserts per page; `replaceAllStock(allProductIds)` deletes rows not in the server response after all pages complete.

**Risk:** The plan does not show explicit accumulator initialization before the pagination loop. An executor reading the snippet could reasonably implement `allProductIds` as a local variable scoped inside the loop iteration, causing `replaceAllStock` to receive only the last page's IDs and delete all previously upserted rows from earlier pages.

The existing product sync in `apps/pos/src/lib/sync/syncService.ts:492–575` shows the correct pattern: accumulator declared before the loop, `replaceAll` strictly after loop exit. The plan must mirror this explicitly.

**Severity: P1** (could silently delete all but last-page stock rows on every full sync)

**Fix:** Show `const allProductIds: string[] = []` declared before the `while (cursor !== null)` loop, pushed to inside each iteration, and `replaceAllStock(allProductIds)` called strictly after the loop exits.

---

### D. Task 9 — Cursor persistence ordering (NIT)

**Claim:** Cursor is persisted only after all pages succeed.

**Finding:** The existing `syncService.ts:492–575` confirms this pattern. Upserts happen per-page (idempotent by `product_id + location_id`), cursor is written to `syncMetadata` after the full loop. A crash mid-pull leaves upserted rows but no cursor — next sync restarts from page 1 and re-upserts safely. Plan's claim is CORRECT.

**Severity: NIT** (no bug; matches existing precedent — executor should read `syncService.ts:492–575` as the reference implementation)

---

### E. Task 10 — `pendingSaleQty` aggregate (P1)

**Claim:** Plan reads pending sale quantities by querying a structured receipts table or child rows.

**Finding:** `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts` + migrations confirm lines are stored as a JSON blob in `offline_receipts.lines` (TEXT/JSON column). There is NO `receipt_lines` child table. The plan's SQL aggregate assumes relational rows — this will fail or return 0.

Two correct approaches: (a) Use `json_each(lines)` in SQLite to unnest the JSON blob in the query, or (b) load all pending receipts in JS and aggregate with `decimalAdd` from `apps/pos/src/lib/decimal.ts`.

Additionally, the plan snippet uses `bcsub` in TypeScript — which does not exist in the POS codebase (see §2).

**Severity: P1** (runtime failure on stock deduction)

---

### F. Task 13 — Parity fixture risk (P2)

**Claim:** Task 13 updates the `saleReceiptV2CanonicalParity` fixture to include location-aware fields.

**Finding:** `apps/pos/scripts/check-fiscal-fixture-parity.sh` diffs PHP-generated vs JS-generated canonical fixtures. `apps/pos/src/lib/fiscal/__tests__/saleReceiptV2CanonicalParity.test.ts` confirms the JS side. The plan Task 13 describes updating the JS fixture only — the PHP counterpart in `apps/api/tests/Fiscal/Fixtures/` must also be updated in the same commit or the parity CI gate goes red.

**Severity: P2** (CI gate failure if PHP side is not updated alongside JS)

**Fix:** Task 13 must list explicit steps: (1) update PHP fixture in `apps/api/tests/Fiscal/Fixtures/`, (2) update JS fixture, (3) run `apps/pos/scripts/check-fiscal-fixture-parity.sh` locally to confirm green before committing.

---

## §4 Full Findings List

### BLOCKER

**BLOCKER-1: `?terminal_id` client selector contradicts spec §4.3 auth-based resolution**

- **Plan location:** Task 6 route snippet — `GET /pos/stock-levels?terminal_id={id}`
- **Spec location:** `docs/superpowers/specs/2026-06-11-pos-location-aware-stock-design.md` §4.3 — "location is resolved from the authenticated terminal session — no client-supplied location selector"
- **Finding:** Accepting a client-supplied `terminal_id` allows a malicious or misconfigured client to read a different location's stock by supplying an arbitrary terminal ID. The correct pattern (already used in `TerminalResource`) is to resolve the authenticated terminal's `location_id` server-side from the auth context.
- **Fix:** Remove `terminal_id` from the query string. Resolve `location_id` from the authenticated terminal (e.g. `auth()->user()->currentTerminal()->location_id` or the established POS auth-terminal pattern). This is the same pattern `TerminalResource` uses.

---

### P1

**P1-1: Terminal has no `company()` relation**

- **File:** `apps/api/app/Modules/POS/Domain/Terminal.php`
- **Finding:** No `company()` BelongsTo method. Access requires `$terminal->location->company_id` or `$terminal->location->company`. Plan snippets using `$terminal->company` will throw a `BadMethodCallException` / return null.
- **Fix:** Update all plan snippets to route through `location()`.

**P1-2: `QuantityScale::round()` does not exist — use `bcformat()`**

- **File:** `apps/api/app/Shared/Domain/QuantityScale.php`
- **Finding:** Actual method signature is `public static function bcformat(string $value, int $scale): string`. Task 4 calls `QuantityScale::round($qty, 4)` which throws `BadMethodCallException` at runtime.
- **Fix:** Replace with `QuantityScale::bcformat((string) $qty, 4)` in all plan snippets.

**P1-3: SQLite migration object shape is wrong — `name`+`sql` not `description`+`statements`**

- **File:** `apps/pos/src/lib/db/migrations.ts`
- **Finding:** Real migration entries use `{ version: number, name: string, sql: string }`. Plan Task 3 uses `{ version, description, statements: string[] }`. The migration runner will silently skip or fail to register the new migration because the key names don't match.
- **Fix:** Use `{ version: N, name: 'add_stock_levels', sql: '...' }` with a single SQL string. If multiple DDL statements are needed, add multiple entries with incrementing `version`.

**P1-4: `bcsub`/`bccomp` in TypeScript — use `decimalSub`/`decimalGte` from `decimal.ts`**

- **File:** `apps/pos/src/lib/decimal.ts`
- **Finding:** TypeScript POS has no `bcsub`/`bccomp`. The decimal library is Decimal.js-based with helpers `decimalAdd`, `decimalSub`, `decimalGte` exported from `apps/pos/src/lib/decimal.ts`. Task 10 snippet calling `bcsub(...)` will throw `ReferenceError` at runtime.
- **Fix:** Replace all `bcsub`/`bccomp` calls with the `decimal.ts` helpers.

**P1-5: `pendingSaleQty` assumes child table — actual shape is JSON blob**

- **File:** `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts`; `apps/pos/src/lib/db/migrations.ts`
- **Finding:** Receipt lines are a JSON blob in `offline_receipts.lines`, not a relational child table. Task 10's SQL aggregate will return 0 rows.
- **Fix:** Use SQLite `json_each(lines)` in the query to unnest, or aggregate in TypeScript by loading pending receipts and summing with `decimalAdd`.

**P1-6: `NOT NULL` constraint will fail existing-tenant migration if no default location exists**

- **File:** New Task 1 migration
- **Finding:** On existing tenants with `stock_levels` rows but no `default` location (location created after products were imported), the backfill UPDATE sets 0 rows, leaving `location_id = NULL`, which then fails the `NOT NULL` constraint and halts the migration.
- **Fix:** Keep `location_id` nullable in the initial migration. Add a separate migration (gated on `artisan pos:verify-stock-location-backfill`) that adds the `NOT NULL` constraint only after confirming all rows were backfilled. Alternatively, enforce NOT NULL only at the application layer with a validation rule rather than a DB constraint.

**P1-7: `apiGet` strips `meta` — Task 9 must use raw `api.get()`**

- **File:** `apps/pos/src/lib/api.ts`
- **Finding:** `apiGet` unwraps `response.data.data`, silently dropping `meta.next_cursor`. Task 9 pagination will always see `cursor = undefined` and stop after page 1.
- **Fix:** Use `api.get('/pos/stock-levels', { params: { cursor } })` (raw axios instance) and read `response.data.meta.next_cursor` — matching the pattern in `syncService.ts:492–575`.

---

### P2

**P2-1: Task 13 missing PHP-side parity fixture update steps**

- **File:** `apps/pos/scripts/check-fiscal-fixture-parity.sh`; `apps/api/tests/Fiscal/Fixtures/`
- **Finding:** The parity CI gate diffs PHP-generated vs JS-generated fixtures. Task 13 only describes the JS fixture update. Leaving the PHP side stale causes a CI gate failure.
- **Fix:** Add explicit steps to Task 13: (1) update PHP fixture in `apps/api/tests/Fiscal/Fixtures/`, (2) update JS fixture, (3) run `check-fiscal-fixture-parity.sh` locally before committing.

**P2-2: Task 8 accumulator scope not shown explicitly**

- **File:** Plan Task 8 snippet
- **Finding:** `allProductIds` accumulator must be initialized before the pagination loop and passed to `replaceAllStock` after loop exit. The snippet is ambiguous — an executor could scope it per-iteration.
- **Fix:** Show `const allProductIds: string[] = []` before the `while` loop, pushed to inside each iteration, and `await replaceAllStock(allProductIds)` strictly after the loop exits.

**P2-3: `as_of` isolation not documented as advisory-only**

- **File:** Plan Task 5
- **Finding:** Plan implies `$asOf` provides snapshot isolation, but PG default is READ COMMITTED. This is acceptable per spec §4.1 (advisory display) but could mislead an executor into thinking the read is MVCC-safe.
- **Fix:** Add inline comment: "Advisory-only per spec §4.1 — READ COMMITTED is intentional; hard gating is server-side at sale-post time."

---

### NIT

**NIT-1: Task 9 cursor pattern matches `syncService.ts` precedent — no change needed**

`apps/pos/src/lib/sync/syncService.ts:492–575` is the reference implementation for multi-page cursor sync. Task 9's intent is correct; the executor should read those lines directly.

**NIT-2: Tenant migrations should not call `tenant()` — document the correct pattern**

The correct pattern (per existing migrations) is to use Eloquent models directly inside tenant migrations; the per-tenant DB connection is already active. Add a plan note: "Do not call `tenant()` in tenant migrations — use `Company::all()` / `Location::all()` etc. directly."

---

## §5 Verdict + Rationale

**Verdict: REQUEST-CHANGES**
**Confidence: 86%**

The plan has strong structural coverage of the spec and the overall task decomposition is sound. However, it cannot be safely executed as written due to:

**1 BLOCKER:** The `?terminal_id` client selector directly contradicts the spec's authentication-based location resolution requirement (§4.3). An executor following the plan literally would introduce a cross-location stock information disclosure path.

**7 P1s:** Four involve incorrect API/helper signatures that cause immediate runtime failures (`Terminal->company` relation missing, `QuantityScale::round` nonexistent, wrong SQLite migration keys, `bcsub` in TypeScript, wrong pending-sale table shape). Two involve correctness bugs in the sync logic (pagination meta stripped by `apiGet`, accumulator scope). One involves a migration constraint that will hard-fail existing tenant upgrades.

All findings are fixable with targeted edits to the plan's code snippets and Task 1 migration strategy. No spec redesign is required.

**Recommend:** Fix BLOCKER-1 and P1-1 through P1-7 in the plan document, then request a targeted re-review of Tasks 1, 8, 10, and 13 before handing off to an executor.
