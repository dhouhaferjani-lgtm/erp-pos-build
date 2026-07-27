# Gate 4 round 2 — POS analytics + Z-report backend re-review (lane: fiscal-pos)

> Controller-run 2026-07-22. Reviewer: fiscal-pos-reviewer (Opus). Request: `.gates/gate-4-request-r2.md`. Round-1: `.gates/gate-4-verdict-pos.md` (REJECT, Critical). Tip reviewed: `62035d815`.

Re-verified `git diff 329a5e8f5..62035d815` for the POS paths. SQLite by-path run (`phpunit.xml`): **27 tests / 100 assertions passing** (was 19/82). Treasury lane owns PG.

## B1 (prior Critical) — RESOLVED
- All conditional scope guards are gone. `grep '!== \[\]\|->when('` on `PosAnalyticsService.php` returns nothing (the only `!== []` left, `ReportController.php:141`, is an unrelated cash-count check). Every one of the 8 methods and all internal subqueries now route through the new `locationScope()` helper (`PosAnalyticsService.php:426-437`), which applies `whereIn($column, $locationIds)` **unconditionally** inside a nested `->where(Closure)` and adds `orWhereNull` only when `$includeNull && $unrestricted`.
- **Fail-closed proven:** empty `$locationIds` yields `whereIn(column, [])` → `0 = 1` → empty result. Confirmed behaviorally by `AnalyticsTest::test_zero_allowed_locations_returns_empty_analytics_result` (receipt_count 0) and `ZReportListTest::test_zero_allowed_locations_returns_empty_list` (count 0), both green.
- **Boundary injected, not `app()`:** `PosAnalyticsService.php:19-21` (ctor `LocationScopeBoundary`), `AnalyticsController.php:24`, `ReportController.php:49`.
- **`ReportController::listZReports`** now applies the qualified `whereIn('pos_terminals.location_id', $locationIds)` unconditionally in a nested group with `orWhereNull` only when unrestricted (`ReportController.php:271-284`). Empty set → no terminal matches → no Z-reports (fail closed).
- **Unrestricted path did not regress:** the resolver returns the concrete all-company-locations list; since `pos_receipts.location_id`/`pos_terminals.location_id` are NOT NULL FKs, `whereIn(allIds)` still captures every row. For nullable `pos_orders.location_id`, `getFnbMetrics` now takes `$includeUnattributed` and adds `orWhereNull` when unrestricted (`PosAnalyticsService.php:334,350,367,378`; controller passes `isUnrestricted(...)` at `AnalyticsController.php:156`), so an owner again sees Unattributed F&B orders. Matches the sibling `CashPositionController.php:89-94` semantics exactly.

## B2 (prior Important) — RESOLVED
- **Z-report: all 5 plan cases now present** (`ZReportListTest.php`) — unfiltered+names (`:25`), terminal+location narrowing (`:37`), location-only narrowing that exercises the qualified `pos_terminals.location_id` predicate (`:60`), out-of-scope ⇒ 403 (`:72`), restricted/no-param own-location-only (`:84`), plus zero-allowed empty (`:95`). Real behavioral tests: `RefreshDatabase`, `RolesAndPermissionsSeeder`, real memberships with explicit `allowed_location_ids`, ZReports persisted across two locations, asserting `assertJsonCount`/`location_name`.
- **Analytics: restricted + zero-allowed cases added** (`AnalyticsTest.php:208,222,234`) with an explicit restricted membership (`allowed_location_ids = [L1]`) and receipts seeded across two locations, asserting counts.
- **Old-fail-open catchability confirmed by reading:** the zero-allowed tests set `allowed_location_ids = []` → resolver returns `[]`. Under the old `->when([] !== [], …)`/`if ($locationIds !== [])` code the predicate was skipped → all seeded receipts/Z-reports returned; the new assertions demand count 0. So both tests genuinely regress-guard B1. (The restricted-narrowing tests pass on old and new code — they guard the narrowing guarantee, not the leak; correct division of labor.)

## Prior two Minors — RESOLVED
- **F&B `orWhereNull`:** folded in via the `$includeUnattributed`/`$includeNull` path (above).
- **PG location-branch coverage:** `ci.yml:555` now includes `AnalyticsTest` as an exact `|AnalyticsTest|` token (B4), and `ZReportListTest::test_location_filter_executes_the_terminal_location_predicate` exercises the `whereIn('pos_terminals.location_id', …)` branch — run on PostgreSQL by the treasury lane.

## New adversarial pass on the fix diff
- **No new write path / zero fiscal-field touch:** the diff is read predicates + one private helper + constructor injection + tests + ci.yml. No hash-chain/signed-byte/`ZReportResource` write.
- **No return-shape change:** `getFnbMetrics` gained a scalar parameter (`bool $includeUnattributed`), not a DTO field; `FnbMetricsData` unchanged, so the `typescript:transform` zero-diff claim holds. Its only caller is `AnalyticsController.php:151` (correct args); no other caller exists.
- **Type safety:** `ReportController` nested closures use the imported `Illuminate\Database\Eloquent\Builder` (`:27`); service closures type `Illuminate\Database\Query\Builder` matching the `DB::table(...)` builders. Tests compile and run green.

### Findings
**[Minor] ci.yml:555 — dead/renamed token `FiscalEventQuarantineTest`** — the r2 edit that added `AnalyticsTest` also mutated `FiscalEventQuarantineTableTest` → `FiscalEventQuarantineTest`, which does not regex-match the real class `apps/api/tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php`, so that class is dropped from the `backend-test-pgsql` job's `--filter`. Not a coverage loss — the class is still run on PostgreSQL by the `t6-phase0b-pgsql` job (`ci.yml:652` correct token) — but the line-555 token now matches nothing. Fix: restore `FiscalEventQuarantineTableTest` on line 555 (keep `AnalyticsTest`).

## VERDICT: APPROVE

What to fix before merge (non-blocking): restore the accidentally-mangled `FiscalEventQuarantineTableTest` token on `.github/workflows/ci.yml:555` (the intended B4 edit was only to add `AnalyticsTest`). All B1/B2 blocking items and both prior Minors are resolved and behaviorally regression-guarded.
