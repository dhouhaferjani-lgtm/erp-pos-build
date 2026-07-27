# GATE 4 Review — Treasury Lane — Wave 4 Analytics/Dashboards + Task 0 DTO follow-ups

> Controller-run 2026-07-22. Reviewer: treasury-reviewer (Opus). Request: `.gates/gate-4-request.md`. Handoff: `docs/handoff/CODEX-multiloc-wave4-2026-07-22.md`. Tip reviewed: `0945d39e0` (commits `de0f49a1f`, `430e4fa3f`, `0945d39e0`). Scope: treasury/accounting portion only (Task 0a/0b + Wave 4 treasury consumption).

## Test execution (all PostgreSQL runs owned by this lane; by path only — full suite never run)
- **PG Task 0 paths** (`LocationReconciliationTest`, `MaturingInstrumentsTest`, `UpcomingPaymentsTest`, `UpcomingPaymentsRecurringTest`, `UpcomingPaymentsInstrumentsTest`) — **PASS, 24 tests / 176 assertions.** Matches the request claim.
- **PG POS paths** (`AnalyticsTest`, `ZReportListTest`) — **PASS, 19 tests / 82 assertions.** Matches the claim.
- **Transformer**: `CACHE_STORE=array php artisan typescript:transform` (447 types) then `git diff --exit-code packages/shared/types/generated.d.ts` → **zero diff.** Committed generated types match source DTOs.

## Task 0a — DTO-emitted `buckets_by_location` — VERIFIED CORRECT
- New Treasury DTO family (`MaturingInstrumentRowData`, `MaturingInstrumentBucketData`, `MaturingInstrumentBucketsData`, `MaturingInstrumentsMetaData`, `MaturingInstrumentsData`) matches what the endpoint serializes; `MaturingInstrumentsController::index` returns `MaturingInstrumentsData::from([...])->toArray()` (MaturingInstrumentsController.php:163-170) instead of an ad-hoc array. `formatRow` supplies every declared field incl. new `location_id`/`location_name` (:231-232). No row field dropped vs the round-2 shape.
- `LocationReportBucketData` extended with **defaulted** `count`/`total_in`/`total_out`/`net` (LocationReportBucketData.php:17-20). Tinker-probed: `MaturingInstrumentsMetaData.buckets_by_location` serializes `{location_id, location_name, total, count, total_in, total_out, net}` — the per-direction fields survive, so the maturing-by-direction reconciliation assertion is real. Reconciliation-by-construction holds: `grand_total` and `buckets_by_location` both iterate the same `$instruments` collection and sum by direction (MaturingInstrumentsController.php:118-152); the restricted-mode Unattributed skip in the bucket loop is a defensive no-op (query already excludes NULL when restricted, :63-68).
- FE duplicates removed: `InstrumentListPage.tsx:79` uses generated `MaturingInstrumentsData`; the `UpcomingLocationBucket`/`UpcomingPaymentsData` augmentation is gone from `finance/types.ts`.

## Task 0b — GR-IR accrual reconciliation case — VERIFIED REAL
`test_auto_generated_received_purchase_order_grir_accrual_reconciles_by_location` (LocationReconciliationTest.php:289-308) seeds two genuine auto-generated **Received** POs (`payload->auto_generated`, posted `GoodsReceipt` + `GoodsReceiptLine` with `accrual_unit_cost`, `quantity_invoiced=0`) where the accrual **differs from raw total**: location A raw `100.000` vs accrual `2.0000×12.5=25`; NULL-location raw `90.000` vs accrual `1.0000×30=30`. Asserts bucket A = `25.0000`, bucket NULL = `30.0000`, `sum(buckets)==grand_total=='55.0000'` — proving buckets derive from the accrual-mutated `balance_due`, not raw total. Passes SQLite + PG.

## Upcoming-payments refactor (round-2 concern) — VERIFIED, no math change + a latent bug fixed
- Bucket-building moved from the controller into `UpcomingPaymentsService::locationBuckets()` (UpcomingPaymentsService.php:104-146), summing `$line->balance_due` by direction from the same `$incomingLines`/`$outgoingLines` that feed `totalIn`/`totalOut` — identical source and `bcadd` math to round-2. Controller-side `upcomingLocationBuckets()`/`documentLocationBuckets()` removed; unused `CurrencyScaleResolverInterface` dropped from `ReportsController`'s constructor.
- **Latent fix**: in round-2 the service signature was `generate(string, int)`, so the location-scope 3rd arg the controller passed was silently ignored (upcoming was NOT actually location-filtered). Task 0 added `array $locationIds = [], bool $groupByLocation = false` and real per-query `whereIn('location_id', ...)` filters (incl. recurrence templates via `payment_repository_id` → repository location, UpcomingPaymentsService.php:293-296). Improvement, well-formed, covered by passing tests.

## Wave 4 treasury consumption (Task 6 widgets) — scope OK, one currency defect
- `CashAcrossStoresWidget` (`useCashPosition`) and `DueThisWeekWidget` (`useMaturingInstruments`): scope enforcement intact — both hooks read `useViewScope()` and inject `location_ids: effectiveLocationIds` + `locationScopedKey([...], scope)` (useCashPosition.ts:64-72, useMaturingInstruments.ts:17-24). Backend cash-position and maturing-instruments both apply `LocationScopeResolver` + `LocationScopeBoundary::isUnrestricted` with Unattributed gated on unrestricted (MaturingInstrumentsController.php:51-68). Decimal-string money throughout.
- Attribution grain **not conflated**: cash-position groups by repository custody location; maturing groups by the instrument's frozen `location_id`. Correct per Wave 3.

## Findings

**[IMPORTANT] apps/web/src/features/owner-dashboard/components/DueThisWeekWidget.tsx:32 — currency hardcoded `'TND'`.** `formatCurrency(amount, { currency: 'TND' })` — the widget has no company-currency source (grep-confirmed). For any non-TND tenant (France/UK/Italy — EUR/GBP), this renders the wrong currency symbol AND wrong decimal scale (TND=3 vs EUR=2). Value math is correct decimal-string `bcadd`, so display-only, but a real correctness defect in a multi-country ERP. Sibling `CashAcrossStoresWidget` does it right (`{ currency: position.currency }`, :25,:30). Fix: source currency from the company store / a response currency field.

**[IMPORTANT] .github/workflows/ci.yml:555 — POS `AnalyticsTest` (modified, new location-scoping SUM/GROUP-BY assertions) is NOT in the pgsql `--filter` allowlist.** The list adds `ZReportListTest` but the POS `AnalyticsTest` class itself is absent (only substring-matched inside the pre-existing `ExpenseAnalyticsTest` token). New `location_ids` narrowing aggregates get **zero Postgres coverage in CI** (they pass when run manually, 19/82 — coverage gap, not failing test). Fix: add `AnalyticsTest` to the pgsql allowlist.

**[MINOR] LocationReportBucketData.php:16-20 — the shared bucket DTO's `total` field has surface-dependent semantics.** AP/AR: outstanding balance; upcoming: net (in−out, can be negative, UpcomingPaymentsService.php:133); maturing: in+out sum (MaturingInstrumentsController.php:148). A consumer reading `.total` generically would misinterpret it; AP/AR bucket JSON also carries zero-noise (`count:0, total_in:'0.000'…`). Non-breaking; consider per-surface bucket DTOs or documenting the `total` contract.

**[MINOR] ci.yml — `UpcomingPaymentsRecurringTest`/`UpcomingPaymentsInstrumentsTest` exercise the materially-refactored `UpcomingPaymentsService` but are not in the pgsql allowlist.** Pre-existing gap, but now the natural PG regression guard for the service's new filtering + bucket math. Consider adding.

**[MINOR] DueThisWeekWidget.tsx:23-26 — the "due this week" amount sums `total_in + total_out`,** conflating inbound and outbound into a single figure. Plan wording ambiguous. Consider showing in/out separately.

## Verified-correct (no action)
- No float/`parseFloat`/`Number()` on money in the new treasury backend or widgets; scale from injected resolver with explicit `$company->currency`.
- Frozen instrument-origin write-freeze untouched; new `location_id`/`location_name` on rows/buckets read the instrument's own frozen `location_id`, never repository custody.
- New locale keys for both treasury widgets exist in en, fr, AND ar — no repeat of the gate-3b missing-locale MAJOR.
- Transformer zero-diff confirms `generated.d.ts` regenerated, not hand-edited.

## What to fix before merge
Replace the hardcoded `'TND'` in `DueThisWeekWidget` with the tenant currency, and add POS `AnalyticsTest` to the pgsql CI allowlist. Both small and isolated; core Task 0 DTO emission, GR-IR accrual reconciliation, upcoming-payments refactor, and Wave 4 scope enforcement are correct and well-tested.

VERDICT: spec ✅ + quality CHANGES-REQUESTED
VERDICT: REJECT
