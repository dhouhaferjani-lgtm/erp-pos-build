# T-2 S1 receipt spine — implementation gate r3 — tenancy-authz-reviewer

Reviewed: lane/t2-receipt-spine @ 208449350 (fix-round-2 diff d7123fa30..208449350; slice de3007fe9..208449350), plan rev 10, handback Fix round 2
Date: 2026-09-11

## Verdict
VERDICT: ACCEPT
BLOCKER=0 MAJOR=0 MINOR=3

The gate-r2 BLOCKER (T-9(r2), i18n baseline/pin integrity) is fully closed on all five sub-checks with independent evidence. T-5, T-10..T-15 are closed. All r1/r2 authz citations regress clean at the new HEAD. Nine PostgreSQL classes re-run green and reproduce the handback's counts exactly. No cross-company path, no route outside the rule-12 group, no grant beyond the plan, no CI filter loss, no baseline/pin edit.

## Gate r2 closure table (T-5, T-9..T-15(r2))

| r2 item | Claimed resolution | Status | Evidence |
|---|---|---|---|
| **T-9(r2) BLOCKER** i18n | i18n.ts reverted byte-identical to `93b106461`; ar overlay deleted; pin untouched | **VERIFIED** | `git diff 93b106461 -- apps/web/src/lib/i18n.ts` → **empty**. Alias restored at `apps/web/src/lib/i18n.ts:477` (`'stock-transfers': enStockTransfers`), `arStockTransfers` import removed. `apps/web/src/locales/ar/stock-transfers.json` **absent** (`--diff-filter=D` confirms deletion). `git hash-object apps/web/tools/i18n-completeness-baseline.json` = `fd6dbe3952cc3daaec15dc432e6b99e007f50dd6` (exact protected blob). `git diff de3007fe9..208449350 -- apps/web/tools/` → **empty** (no baseline/pin/mirror edit anywhere in the slice). Auditor re-run by me under Node 20.19.4 with the protected blob: **exit 0** — "55 namespaces, authored keys: en=9527, fr=9544, ar=5164 authored (1929 behind aliases); **2816 known gap(s) held at the baseline**". No `ar` case in the badge test (`grep "'ar'"` in `StockTransferStatusBadge.test.tsx` → no match; statuses array is 7 English pairs, `:7-15`). Ticket present: `docs/superpowers/tickets/2026-09-10-stock-transfers-arabic-namespace.md` (explicitly forbids regenerating/re-pinning the baseline) |
| T-10 preflight truth | Outcomes recorded; no CI result inferred | **VERIFIED (recorded) + 6 gates re-run by me** | Handback `:509-510` records "Scoped preflight completed with all checks passed" with a named scope file and enumerates each family; `:511` explicitly retains the nonzero React-Doctor result rather than claiming green; `:504` concedes runtimes were under swap pressure. `lint:ratchet` block `:516-529` — web baseline=6448 current=**6410** (improved, not grown), pos 84 held, `RESULT: PASS`. My independent re-runs: see **Static gates** |
| T-5 scoped identity | complete/cancel/initiate take the caller-resolved model; predicates retained; foreign identity → zero writes | **VERIFIED** | `StockTransferService.php:310` `public function complete(StockTransfer $identity, …)` — the former `findOrFail($transferId)` is gone; `:325` `cancel(StockTransfer $identity, …)`; `:396` `moveSourceToInTransit(StockTransfer $identity, …)`. Both identity predicates survive downstream: `StockTransferMovementSupport.php:47-52` re-locks with `where('tenant_id', $identity->tenant_id)->where('company_id', $identity->company_id)->lockForUpdate()->findOrFail()`, and `complete` forwards `$identity->tenant_id, $identity->company_id` into `receiveAllRemaining` (`:312-313`). Controller passes scoped models: `StockTransferController.php:240` (`complete($existing, …)`), `:280` (`identity: $existing`), each resolved at `:228-231` / `:265-268` under `where('tenant_id')->where('company_id')` with a `Str::isUuid` 404 guard at `:225-227` / `:262-264`. Subprocess fixtures now resolve scoped too (`StockTransferCompleteConcurrencyPostgresTest.php:103`, `StockTransferReceiveConcurrencyPostgresTest.php:86`). Deny test with data-meaning assertions: `TransferReceiptAuthorityGateTest.php:54-70` mutates `tenant_id`/`company_id` on a cloned identity for both actions and asserts `ModelNotFoundException` **and** a byte-identical 6-table row snapshot (`denialSnapshot()` `:112-120` serialises full rows of `stock_levels, stock_movements, stock_transfers, stock_transfer_lines, stock_transfer_receipts, journal_entries`). Green on PG: 9/23 |
| T-12 nest guard + fixtures | Guard unchanged; migrate-once-per-class trait; no wrapping transaction; runtime improved; CI forbids `--parallel` | **VERIFIED** | Guard byte-identical: `git diff d7123fa30..208449350 -- …/StockTransferReceiptService.php` → **empty**; `:97-99` still `if (DB::transactionLevel() !== 0) throw new \LogicException('StockTransferReceiptService must be the outermost transaction')`. New trait `apps/api/tests/Support/TruncatesRootTransactionDatabase.php` — forces `RefreshDatabaseState::$migrated = false` once per class (`:24-27`) so vendor `DatabaseTruncation::truncateDatabaseTables()` migrates once then row-cleans (vendor `DatabaseTruncation.php:30-42`); **no** `beginDatabaseTransaction` path is entered, so transaction level stays 0. Used by exactly three classes (`StockTransferEdgeCasesTest.php:42`, `InventoryTransferServiceTest.php:52`, `StockTransferVariantTest.php:37`). Runtime: I measured EdgeCases at **00:50.842, 27 tests / 115 assertions / 2 skipped** — matches the handback's counts and beats its own 01:28.290. CI: comment `Receipt fixtures migrate/truncate a shared database: never --parallel this step.` at `.github/workflows/ci.yml:1144`, directly **above** `php artisan test` at `:1145`. Replayed the step with a stubbed `php` echoing argv: argc=5, `--filter=…` present as a single 6852-char argv entry containing every named receipt class, `--parallel` absent; no `--parallel` anywhere in ci.yml |
| T-12 cleanup safety | `session_replication_role` usage is safe and restores | **VERIFIED** | `TruncatesRootTransactionDatabase.php:50-52` refuses any non-`testing` environment or a database name without `_test`; `:55` captures the prior role via `SHOW session_replication_role`; `:56` sets `replica`; `:61-63` `finally` restores it with `set_config(…, false)` before any fixture creation. Cleanup is one server-side `DO` block of `DELETE`s (`:59-60`), `migrations` excluded (`:53`). Non-pgsql drivers fall through to the framework path (`:43-47`), and the `:memory:` PDO is cached/restored (`:29-37`) so SQLite keeps one database across cases. Single-connection cleanup is sufficient because `DB_CENTRAL_DATABASE` is deliberately unpinned and falls back to `DB_DATABASE` (`apps/api/phpunit-pgsql.xml:58-63`), and the parked inventory lane pins both to the same DB (`ci.yml:1726-1727`) |
| T-13 PG version | "PostgreSQL 15.15, port 5432" | **VERIFIED** | Handback `:16` — "The original runs below omitted DB_PORT and resolved to native PostgreSQL 15.15 on 5432". My own `SHOW server_version` on `autoerp_test_u` returns `15.15 (Homebrew)`. The claim is correctly framed as *not* PG-16 evidence |
| T-14 badge palette | Badge atom restored; StatusBadge migration deferred to S4 | **VERIFIED** | `StockTransferStatusBadge.tsx:2,5` now `Badge`/`BadgeVariant` with a 7-status `Record<StockTransferStatus, BadgeVariant>` (`:5-13`); deferral disclosed in `PROMOTION-CHECKLIST-2026-09-09-t2t3.md` ("F-2/F-9: S4 owns migration to the canonical `StatusBadge`") |
| T-15 / F-11 checklist | Location-denial intent + missing FE module counterpart recorded | **VERIFIED** | `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md` — T-6/T-15 bullet: "`LOCATION_ACCESS_DENIED` intentionally returns 403 with `location_id` and caller `user_id` for receive/close, matching complete/cancel. S4 owns any change to 404 hiding."; T-4 bullet carries the exact F-11 wording "…has no `hasModule('Inventory')` FE counterpart to the backend `module:Inventory` group gate" |
| T-11 no plan/review edits | None | **VERIFIED** | `git diff --name-only d7123fa30..208449350 \| grep superpowers` → exactly one path, `docs/superpowers/tickets/2026-09-10-stock-transfers-arabic-namespace.md`. Nothing under `plans/` or `reviews/` |

Citation-accuracy note (non-finding): the handback's T-9 row cites `apps/web/src/lib/i18n.ts:475`; that line is the explanatory comment and the alias itself is at `:477`. Two-line drift, content as claimed.

## Required verifications (items 1–10) and citation regression

| # | Requirement | Status | Evidence |
|---|---|---|---|
| 1 | T-9(r2) closure, all five sub-checks + auditor exit 0 | **VERIFIED** | See closure table row 1 |
| 2 | Preflight/ratchet truthfully recorded; 5 gates re-run | **VERIFIED** | See Static gates. Per brief I did **not** run full preflight or Vitest |
| 3 | T-5 scoped model + zero-write deny test | **VERIFIED** | See closure table row 3 |
| 4 | Nest guard unchanged; trait correctness; CI filter in argv; cleanup safe | **VERIFIED** | See closure table rows 4–5 |
| 5 | T-13/T-15 | **VERIFIED** | See closure table rows 6, 8 |
| 6 | T-11 | **VERIFIED** | See closure table row 9 |
| 7a | Permissions/roles — seeder only at `:201-202`, `:604` | **VERIFIED** | `git diff de3007fe9..208449350 -- …/RolesAndPermissionsSeeder.php` = exactly two hunks: `+'inventory.transfers.reconcile'`, `+'inventory.transfers.close'` in `permissionNames()` (`:201-202`) and the same two appended to the `manager` grant list (`:604`). No other role, no other permission. Both permissions therefore exist **and** are granted — no orphan-permission 403-for-everyone. Seeder re-sync obligation documented at `PROMOTION-CHECKLIST-2026-09-09-t2t3.md` §1 |
| 7b | Route matrix | **VERIFIED** | `apps/api/app/Modules/Inventory/Presentation/routes.php:31` group = `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Inventory']` — rule 12 complete **and** module-gated. All three new routes sit inside it: `receive` `:114-116` (`can:inventory.transfers.complete`), `close` `:118-120` (`['can:…reconcile','can:…close']` — both required), `reconciliation` `:122-124` (`can:…reconcile`). index/show widened to `require.any.permission:view,complete,reconcile` (`:99`, `:108`). **Unchanged in round 2** (`git diff d7123fa30..208449350 -- routes.php` empty) |
| 7c | Uniques + the three `EXCLUDED_TABLES` entries | **VERIFIED** | Both catalogue-shaped keys carry `company_id`: `…_company_number_unique (tenant_id, company_id, receipt_number)` and `…_idempotency_unique (tenant_id, company_id, idempotency_key)` (`2026_09_09_100100_create_stock_transfer_receipt_tables.php:101-102`); the rest are parent-scoped (`(transfer_id, sequence)` `:103`, `(receipt_id, transfer_line_id)` `:106`, `(receipt_line_id, batch_allocation_id)` `:108`) or PG partial one-per-movement indexes (`:113-118`). Three waivers with reasons at `TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:168-170`. Ratchet green on PG: 3/137 |
| 7d | `RequireAnyPermission` | **VERIFIED** | `apps/api/app/Http/Middleware/RequireAnyPermission.php:14-35` — variadic `string ...$permissions`, allow iff `$user !== null &&` any `$user->can(...)`, else a typed 403 envelope. Registered `'require.any.permission' => RequireAnyPermission::class` at `apps/api/bootstrap/app.php:120`, evaluated after `SetPermissionsTeam` so the Spatie team is set. `uuid_create()` at `:29` resolves via `symfony/polyfill-uuid` (`vendor/symfony/polyfill-uuid/bootstrap80.php:55`) — no ext dependency; matches the existing pattern (`EnsureTenantIsActive.php:32`). **Deny path is actually exercised**: `TransferReceiptAuthorityGateTest.php:25-30` gives `inventory.view` only and asserts 403 on both index and show; `:18-23` proves a complete-only actor reaches both |
| 7e | Second-company data isolation | **VERIFIED** | `TransferReceiptSecondOfEverythingTest` green on PG (2 tests / **30 assertions**). Service-level foreign-company refusal with a full-row snapshot: `TransferReceiptAuthorityGateTest.php:40-52`. Destination-only actor cannot return to an inaccessible source, with a zero-write snapshot: `:93-99`. Every controller action resolves under `where('tenant_id')->where('company_id')` (`:228-231, :265-268, :303, :326`) with a `Str::isUuid` pre-guard, and the location gate runs **before** the service in all five: `:145` (store), `:235` (complete), `:270` (cancel), `:304` (receive), `:327` + `:330` (close, source leg only for `return_to_source`) |
| 7f | Manifest numbers + checker exit 0 | **VERIFIED** | `apps/api/tests/feature-lane-manifest.json` — Inventory 129→**141** (+12), Migrations 14→**15** (+1), Replenishment 8→**9** (+1) = +14; `gated_ceiling` 1253→**1267** (+14). Arithmetic closes. Checker re-run by me: **exit 0**, "1532 Feature classes in 74 groups … every `--filter` entry is anchored and uniquely matched against 1944 test classes", parked 1267 classes (= the new ceiling) |
| 7g | Raise notes | **VERIFIED** | Three new notes (`raise_note_2026_09_09_t2t3_s1` in Inventory, Migrations, Replenishment) each name every class, state the parked-lane rationale, name the `backend-test-pgsql` allowlist as the only live gate, carry the standard "NOT on push->dev" CAVEAT, and disclaim any observed CI run |
| 7h | `canAccessLocationOrFail` before the service | **VERIFIED** | See 7e. Helper at `StockTransferController.php:360-363` |
| 7i | Generated DTOs byte-identical after a fresh `typescript:transform` | **VERIFIED** | I ran `php artisan typescript:transform` ("Transformed 576 PHP types"); `git status --short` afterwards → **empty**. The I-12 field drop is correctly reflected: `TransferReconciliationSummaryData.php:14-18` now has 3 members, `packages/shared/types/generated.d.ts:1411-1416` dropped exactly `total_sent/received/damaged/written_off/returned/remaining` |
| 7j | `PROMOTION-CHECKLIST-2026-08-26.md` still one added line | **VERIFIED** | Whole-slice diff = a single `+` line (the lane-scoped successor pointer) |
| 7k | `permissionsMap.generated.ts` matches the seeder | **VERIFIED** | Slice diff adds exactly `'inventory.transfers.close': ['admin','manager']` and `'inventory.transfers.reconcile': ['admin','manager']` plus the source-hash line — congruent with the seeder's manager grant + admin via `permissionNames()`. `ExportFrontendPermissionsMapCommandTest` green on PG (3/19) |
| 8 | Web widening from the authz side | **VERIFIED** | `git diff --name-only de3007fe9..208449350 -- apps/web/src/routes/` → **empty** (whole slice). Web surface is list/detail/badge/types + locales only — no receive UI, no close UI, no route guard change |
| 9 | Scope census of the 33 files | **VERIFIED** | See Scope census |
| 10 | Anything new in routes / middleware / seeder / migrations / queued paths in round 2 | **VERIFIED — none** | `git diff --name-only d7123fa30..208449350 \| grep -E "routes\.php\|Middleware/\|Seeder\|migrations/\|Jobs/\|Console/"` → **empty**. Expected none; confirmed none |

## Findings

### BLOCKER
None.

### MAJOR
None.

### MINOR

**T-16(r3) — MINOR — `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php:137-138`** (with `:59-61` and `:79-85`) — an unscoped, database-global assertion in a class that deliberately does not migrate. `$journalsBefore = DB::table('journal_entries')->count();` / `self::assertSame(0, $journalsBefore);` asserts that the **whole database** holds zero journal entries, while `setUp` skips migration whenever `tenants` already exists (`:59-61`) and `tearDown`'s cleanup list (`:79`) never clears `journal_entries` / `journal_lines`. Why it matters: every `TransferReceiptFeatureTestCase` subclass commits its rows (`StockTransferReceiveTest.php:38` `use RefreshDatabase` combined with `:55-58` `connectionsToTransact(): return []`, i.e. no wrapping transaction, plus `:61` `RefreshDatabaseState::$migrated = false`), so each such class leaves its last test's rows behind for anything later in the same database. **Reproduced red**: after `TransferReceiptSecondOfEverythingTest` ran on `autoerp_test_u`, this test failed — `Failed asserting that 1 is identical to 0` at `:138`, residue being one `inventory_exit` entry with two 25.000 journal lines. **Green once isolated**: on a cleaned database the same file is `OK (2 tests, 20 assertions)`, exactly the handback's number. **CI's actual adjacency is green**: `StockTransferCloseTest.php` (directory position 113) immediately precedes this class (114) inside the single `ci.yml:1145-1146` process, and I ran that ordered pair — `OK, 12 tests, 64 assertions` in 17:22 (= Close 10/44 + Complete 2/20, matching both handback rows). It passes only because Close's last-declared test (`:137`) happens to post no journal entry; the one before it (`:78`) posts exactly one. Not introduced or worsened by round 2 — round 2's two trait conversions (`StockTransferEdgeCasesTest.php:42`, `StockTransferVariantTest.php:37`) sit at positions 115 and 126, *after* this class, and the only class that follows EdgeCases with a no-migrate setUp (`StockTransferIdempotencyCollisionPostgresTest.php:142-146`) scopes all of its count assertions (`:206-210`, `:249-253`). Suggested fix (ticket, not a merge blocker): scope the assertion to `->where('tenant_id', $this->tenant->id)`, or add `journal_lines` and `journal_entries` to the `:79` cleanup list.

**T-17(r3) — MINOR — `apps/web/src/features/stock-transfers/types/index.ts:4` and `:6-9`** — hand-rolled FE types beside generated DTOs. `export type StockTransferType = 'intracompany' | 'intercompany'` and the three-member `TransferCostDistribution` union duplicate generated `App.Modules.Inventory.Domain.Enums.TransferType` (`packages/shared/types/generated.d.ts:1448`) and `App.Modules.Inventory.Domain.Enums.TransferCostDistribution` (`:1442`). Convention 11 forbids a second FE surface for a concept that already has a generated DTO; F-1/F-10 converted the three transport aliases (`:2`, `:11-13`) but left these two. Members are currently identical, so there is no behavioural drift today — only an undeclared second surface that a backend enum change would silently desynchronise. Suggested fix: alias both to the generated enums, as `StockTransferStatus` already does at `:2`.

**T-18(r3) — MINOR (recorded, no change required) — `apps/api/tests/Support/TruncatesRootTransactionDatabase.php:55-56`** — the new cleanup path depends on `session_replication_role`, a superuser-context GUC. Verified safe everywhere it runs today: the `POSTGRES_USER: autoerp` of every CI postgres service is the container superuser (`ci.yml:1197-1199`, `:1694-1696`; stated outright at `ci.yml:1263`), and locally `pg_user.usesuper = t`. The environment guard (`:50-52`) and the `finally` restore (`:61-63`) are correct, and the parked inventory lane runs `tests/Feature/Inventory/` serially (`ci.yml:1782-1783`, no `--parallel`). Recorded because the failure mode of a future least-privilege test role is a hard error in `setUp` for all three classes, not a skip — the handback's own caveat at `:479` ("requires the privileged test database role") should travel with the trait as a docblock line.

## PostgreSQL runs

Runner: native PostgreSQL **15.15 (Homebrew)** at `127.0.0.1:5432`, `DB_DATABASE=DB_CENTRAL_DATABASE=autoerp_test_u`, one file per process, serially. `pg_stat_activity` showed only my own session before each concurrency file. Swap checked before every leg (12.9 GB at start, peaked at 15.4 GB under an unrelated project's suites, back to 9.5 GB for the later legs).

| File | Result | Matches handback? |
|---|---|---|
| `TransferReceiptAuthorityGateTest.php` | **OK (9 tests, 23 assertions)** — 03:27.888 | **Yes** (9/23) |
| `TenantOnlyUniqueOnCatalogueTablesRatchetTest.php` | **OK (3 tests, 137 assertions)** — 00:19.637 | n/a (not in the r2 table); green |
| `TransferReceiptSecondOfEverythingTest.php` | **OK (2 tests, 30 assertions)** — 00:24.613 | n/a; green |
| `StockTransferCompleteConcurrencyPostgresTest.php` (1st run, DB dirty from the previous leg) | **1 FAILURE** at `:138` — `Failed asserting that 1 is identical to 0`; 2 tests, 11 assertions | **No** — root cause isolated to cross-class residue, see T-16(r3) |
| `StockTransferCompleteConcurrencyPostgresTest.php` (clean DB) | **OK (2 tests, 20 assertions)** — 00:10.124; leaves 0 residue rows | **Yes** (2/20) |
| `StockTransferCloseTest` + `StockTransferCompleteConcurrencyPostgresTest` (CI-faithful ordered pair, one process) | **OK (12 tests, 64 assertions)**, 7 PHPUnit deprecations — 17:22.763 | **Yes** — decomposes to Close 10/44 + Complete 2/20, both handback rows |
| `StockTransferEdgeCasesTest.php` | **OK, 27 tests / 115 assertions / 2 skipped** — **00:50.842** | **Yes** (27/115/2); faster than the claimed 01:28.290 |
| `StockTransferReceiveConcurrencyPostgresTest.php` | **OK (3 tests, 43 assertions)** — 00:38.082 | **Yes** (3/43) |
| `StockTransferVariantTest.php` | **OK (12 tests, 42 assertions)** — 00:29.093 | **Yes** (12/42) |
| `ReplenishmentSettlementTest.php` | **OK (6 tests, 26 assertions)** — 00:07.564 | **Yes** (6/26) |
| `ExportFrontendPermissionsMapCommandTest.php` | **OK (3 tests, 19 assertions)** — 00:01.095 | n/a; green |

Every handback count I could re-run reproduces exactly. No full suite was run.

## Static gates

| Gate | Result |
|---|---|
| `I18N_BASELINE_PROTECTED_BLOB=fd6dbe39… node tools/audit-i18n-completeness.mjs` | **exit 0** — 2816 known gaps held at the baseline |
| `git hash-object apps/web/tools/i18n-completeness-baseline.json` | `fd6dbe3952cc3daaec15dc432e6b99e007f50dd6` — unchanged |
| `bash scripts/factory/check-manifest-drift.sh` | **exit 0**, no output |
| `php apps/api/tools/feature-lane-manifest-check.php` | **exit 0** — 1532 Feature classes / 74 groups; every `--filter` entry anchored and uniquely matched; parked 1267 = the declared ceiling |
| `pnpm audit:keys` | **exit 0** — Gate C: 0 unscoped tenant query keys; 0 new, 0 stale |
| `pnpm audit:design-system` | **exit 0** — 802 acknowledged, **0 new**, 0 stale |
| `pnpm audit:quantity` | **exit 0** — 0 raw quantity display sites |
| CI step argv replay (stubbed `php`) | argc=5; `--filter=` present as one 6852-char argv entry with all named receipt classes; **no `--parallel`** in argv or anywhere in `ci.yml` |
| `php artisan typescript:transform` then `git status --short` | **empty** — generated DTOs byte-identical |
| PHPStan level 8, scoped to the 6 changed `app/` files | **[OK] No errors** |
| `pnpm lint:ratchet` | Not re-run (laptop); handback `:516-529` records web 6448→**6410** (improved), pos 84 held, `RESULT: PASS` |

Precision spot-check (rule 19) on the new logic: the short-line count at `StockTransferDetailPage.tsx:278-281` compares strings (`line.quantity_remaining !== '0.0000'`) with no `parseFloat`/`Number`. The comparison is sound because `quantity_remaining` is served raw at fixed scale 4 — `StockTransferLineData.php:75` uses `$line->remainingQuantity()`, which is `bcsub(…, QuantityScale::SCALE)` with `SCALE = 4` (`StockTransferLine.php:133-141`, `QuantityScale.php:20`) — and unit precision travels separately as `quantity_decimals` (`StockTransferLineData.php:68`), so no unit-precision formatting can shorten the literal.

## Scope census

All 33 fix-round-2 files map to an r2 item or a declared consequence:

- **i18n revert (T-9/F-7/F-12)** — `apps/web/src/lib/i18n.ts`, `locales/ar/stock-transfers.json` (deleted), `__tests__/StockTransferStatusBadge.test.tsx`, `docs/superpowers/tickets/2026-09-10-stock-transfers-arabic-namespace.md`
- **T-5 scoped identity** — `StockTransferService.php`, `StockTransferController.php`, `TransferReceiptAuthorityGateTest.php`, and the four declared consequence fixtures
- **T-12 isolation** — `tests/Support/TruncatesRootTransactionDatabase.php` (new), `StockTransferEdgeCasesTest.php`, `InventoryTransferServiceTest.php`, `StockTransferVariantTest.php`, `.github/workflows/ci.yml`
- **I-11/F-3/F-4** — `StockTransferDetailPage.tsx`, `locales/{en,fr}/stock-transfers.json`, `StockTransferCloseTest.php`, `__tests__/StockTransferDetailPage.test.tsx`
- **I-12 / I-13 / I-14** — `TransferReconciliationSummaryData.php`, `TransferReconciliationService.php`, `packages/shared/types/generated.d.ts`, `StockTransferLineData.php`, `TransferInTransitReadersUseRemainderTest.php`
- **G-9/G-10/G-11** — `StockTransferCloseTest.php`, `StockTransferReceiveDamageTest.php`
- **F-1/F-2/F-5/F-6/F-8/F-10** — `types/index.ts`, `StockTransferStatusBadge.tsx`, `StockTransferListPage.tsx`, the three `__tests__` files
- **T-10/T-11/T-13/T-15** — `HANDBACK-T2-receipt-spine-2026-09-09.md`, `PROMOTION-CHECKLIST-2026-09-09-t2t3.md`

New files in round 2: exactly **two** — `apps/api/tests/Support/TruncatesRootTransactionDatabase.php` and the Arabic ticket; one deletion (`locales/ar/stock-transfers.json`). Confirms the handback's claim at `:483` that no new test class and no ceiling/alternation change was needed. **No assertion weakened** in the declared consequence edits: `StockTransferVariantTest.php:320,350` and `ReplenishmentSettlementTest.php:156,172` are signature-only (`complete($transfer, …)` / `cancel($transfer, …)`), every following `assertSame` retained; `TransferInTransitReadersUseRemainderTest.php:17` only sharpens the failure message and keeps the exact site counts `['LocationStockQueryService' => 2, 'StockMatrixQueryService' => 1, 'WeightedAverageCostService' => 1]`; the two concurrency subprocesses gained *stricter* resolution (tenant+company predicates in the child script, `:103` / `:86`).

Nothing in round 2 touches routes, middleware, seeders, migrations, jobs or console commands — as expected.

## Tree state after review

`git status --short` → **empty**. HEAD still `2084493505f1fb6b99f2c58948fb1ef61ac7b1d4` on `lane/t2-receipt-spine`. No edit, commit, merge or push. `php artisan typescript:transform` was run as a verification tool and produced no change. No stray `apps/api/autoerp_test_u` SQLite file. The only mutation I made anywhere is row deletion in the scratch test database `autoerp_test_u`, which is disposable by construction.

---

**What to fix before merge:** nothing blocking — optionally fold T-16(r3) (scope the global `journal_entries` assertion at `StockTransferCompleteConcurrencyPostgresTest.php:137-138`, or clear `journal_entries`/`journal_lines` in its `tearDown` at `:79`) and T-17(r3) (alias the two hand-rolled enums in `types/index.ts:4,6-9` to the generated ones) into the S2/S4 lane as tickets.

Orchestrator: session_01AcU81as26G2apodoimmyq7 (reviewer = Claude Opus `tenancy-authz-reviewer` agent, PG runner owner).

## Orchestrator rulings (2026-09-11)
- **ACCEPT recorded for the tenancy/authz lens.** T-16(r3) (global `journal_entries` assertion in the no-migrate concurrency class) and T-17(r3) (two hand-rolled enum unions) → folded into the S1 fix round 3 packet as cheap items (scope the assertion; alias the enums); T-18(r3) → docblock line on the trait in the same round.
- Remaining S1 gate r3 reviewers (stock-gl, inventory, frontend) run after the reboot, one at a time; then the S1 fix-round-3 NEW-THREAD packet is written (Codex hold in force until the owner clears it).
