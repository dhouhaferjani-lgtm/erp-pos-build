I ran the full review myself (diff, spec §7.5 wave-1 contents, backend tests, PHPStan, Pint, ESLint, vitest). Register below.

---

# M1 / Wave 1 — adversarial merge-gate register (round 1)

Range reviewed: `7d85232cc..24808cc63` (12 commits; note HEAD is `24808cc63`, but the handback commit list stops at `f2bd463b8` and the YAML records `commit: f2bd463b8`).

Lenses: **frontend-conventions** ✔ applied · **tenancy-authz** ✔ applied · **treasury** ✔ applied (currency/no-aggregate rules; no GL or payment write path exists in this wave, so the payments/partial-write half of the lens does not apply) · **general** ✔ applied.

## P1 — blocks

**1. Legacy `receipt_type` axis regression — an existing test in the mandated preflight path now FAILS. CONFIRMED (ran it).**
`apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:117-121` reinterprets the legacy param as `receipt_type=return → invoice_type_code IN ('REFUND','VOID')`, and `:150-167` drops `receipt_type` from the row entirely.

```
CACHE_STORE=array ./vendor/bin/phpunit tests/Feature/POS/ReceiptReturnFlowTest.php
1) Tests\Feature\POS\ReceiptReturnFlowTest::test_receipt_type_filter_returns_only_matching_type
Failed asserting that actual size 0 matches expected size 1.
.../tests/Feature/POS/ReceiptReturnFlowTest.php:230
Tests: 20, Assertions: 111, Failures: 1.
```

Failure scenario: `createReturnReceipt()` (`ReceiptReturnFlowTest.php:1423-1431`) sets `receipt_type => ReceiptType::Return` and leaves `invoice_type_code` at its column default `'SALE'` (`create_pos_receipts_table.php`, per spec §3.a "Legacy rows carry the column defaults `'SALE'`/`false`"). So **every pre-fiscal-era return is now invisible on the refunds axis** — `receipt_type=return` returns zero rows, and the wave-2 refunds register (`invoice_type_codes[]=REFUND&VOID`) will show none of them either. The second half of the same test (`assertEquals('return', $data[0]['receipt_type'])`) would also fail on the dropped key.

Compounding: **BT-2's explicitly required legacy assertion is missing.** Spec §7.1 BT-2 demands "the legacy `receipt_type=return` axis returns that same union (proving it is lossy and the new filter is authoritative)". `apps/api/tests/Feature/POS/ReceiptIndexTypeFilterTest.php` (26 lines) contains no legacy-axis case at all — which is why the regression was not caught.

**2. PHPStan level 8 — 8 errors on new code. CONFIRMED (ran it).**
`phpstan.neon` is `level: 8` over `app/`; preflight runs it (spec §7.4). None are baselined:

```
ReceiptController.php:132  Cannot call method startOfDay() on Carbon\CarbonImmutable|null.
ReceiptController.php:135  Cannot call method addDay() on Carbon\CarbonImmutable|null.
ReceiptController.php:155  Parameter $posted_at of ReceiptListItemData expects string, string|null given.
ReceiptController.php:160  Using nullsafe property access on non-nullable type Location.
ReceiptController.php:162  Expression on left side of ?? is not nullable.
ReceiptFilterOptionsController.php:33  ...expects list<string>|null, array<int, string>|null given.
ReceiptFilterOptionsController.php:49  Cannot call method startOfDay() on Carbon\CarbonImmutable|null.
ReceiptFilterOptionsController.php:56  Cannot call method addDay() on Carbon\CarbonImmutable|null.
 [ERROR] Found 8 errors
```

Failure scenario: CI/preflight red on merge. The `:132/:135/:49/:56` cases are also a latent fatal — `CarbonImmutable::createFromFormat` returns `false` on a parse miss; only the `date_format:Y-m-d` rule keeps it unreachable today.

**3. FT-14 / FT-15 do not exist as specified, and the handback claims they are DONE with evidence that isn't there.**
`apps/web/src/routes/__tests__/ReceiptPermissionParity.test.ts` is a **source-string grep** over `routes/index.tsx` / `Sidebar.tsx` — it never renders the sidebar under a role. The handback states "the 41-test Sidebar suite cover the mapping and route parity"; `grep -n "accountant|cashier|pos.view_receipts|complianceExport" src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx` returns **zero hits**, and that file is untouched by this diff. Unasserted anywhere:
- FT-14: accountant sees exactly {POS Receipts, Z-Reports, Analytics, Vouchers, Compliance-export}, and *not* Terminals/Shift History/Orders/Tables/Kitchen.
- FT-15: cashier now loses **Tables**, and "nothing visible 403s, each hidden entry's route denies this role" (both directions).
- GATE-5's explicit "a group header with zero visible children must not render — **assert this**" (spec §4.2 GATE-5).

The only behavioural proof of any of this is Playwright flow 1, which **never ran** (environment-blocked, handback §Verification). GATE-5 is the change that removes menu entries from live roles (owner-visible OI-14) — it ships here with no behavioural lock.

## P2 — fix before merge

**4. The mandated preflight gate never completed, and it hid P1-1 and P1-2.**
`PREFLIGHT_TEST_PATHS='tests/Feature/POS …' PREFLIGHT_VITEST_PATHS='…' ./scripts/preflight.sh` stops at Pint. I verified the Pint failure is genuinely outside the lane (`./vendor/bin/pint --test` on every changed API path + `tests/Feature/POS` reports exactly one file: `tests/Feature/POS/ZReportListTest.php`, `class_attributes_separation`, untouched by this diff). Honest reporting — but the consequence is that PHPUnit-over-`tests/Feature/POS`, PHPStan, `typescript:transform` drift and `permissions:export-frontend-map` drift were **all** unrun by the executor. Two of those stages are red (P1-1, P1-2). M1 cannot pass with its own exit gate unrun.

**5. BT-16 asserts the wrong database. `apps/api/tests/Feature/POS/ReceiptReportingIndexesTest.php:18-45`.**
Spec §7.1 BT-16 freezes: `pg_indexes` contains both names with the stated columns and the `WHERE training_flag = false` predicate; **re-running the migration is a no-op**; **`down()` drops both**. The test queries `sqlite_master` (phpunit.xml pins `DB_CONNECTION=sqlite`, `:memory:`), and asserts neither idempotency nor `down()`. The class is also absent from the `backend-test-pgsql` `--filter` allowlist (`.github/workflows/ci.yml:629`), so it never runs against Postgres. Mitigating: the pgsql merge-gate job does run all migrations on PG via `RefreshDatabase`, so a PG-invalid DDL would still fail CI — the *assertions*, not the DDL, are what's unverified.

**6. `filter-options` materialises every in-scope receipt in PHP to derive the cashier list.** `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptFilterOptionsController.php:73-84`: `$receiptQuery->select([...])->orderByDesc('posted_at')->get()->unique('cashier_id')`. Spec S-7 mandates `SELECT DISTINCT cashier_id, cashier_name … within scope`. `from_date`/`to_date` are `sometimes` on `ReceiptFilterOptionsRequest` with no server default, so an unwindowed call (or a user widening the range to a year) hydrates the entire receipt table into memory to produce a handful of options. Failure scenario: first tenant at ~200k receipts → the filter dropdown OOMs or times out the register page.

## P3 — note

7. `apps/web/src/features/pos/pages/ReceiptListPage/ReceiptListPage.tsx:138` calls `t('common:notAvailable')`; the key is absent from both `locales/en/common.json` and `locales/fr/common.json` (verified). Multi-location tenants with a null `location_name` render the literal string `notAvailable`. Untested — the page test mocks `hasMultipleLocations: false`.
8. Spec §3.a empty state **3** (`hasTenantScope === false` → shared no-scope state) is not implemented; that case falls through to "No sales receipts for this business day". Mitigating: no such shared component exists anywhere in `apps/web`, and `ZReportListPage.tsx:28-42` behaves identically — the spec cited a component that isn't there. FT-10's "three states" is satisfied by a *different* triple (day / training-day / filtered).
9. i18n §5 action 2 froze "**14 keys carry over**"; four more were dropped (`active`, `voided`, `noReceipts`, `noReceiptsDescription`). Verified harmless — no `receiptSearch` references remain anywhere in `apps/web`/`apps/pos`, and EN↔FR `pos.json` key sets are byte-identical. Record as a deviation.
10. BT-11 (`AccountantReceiptPermissionsTest.php`) extends plain `PHPUnit\Framework\TestCase` and only reads the static grant array; it omits the spec-named `pos.process_returns` refusal and never exercises the seeder against a DB.
11. `ReceiptListPage.tsx:48-57`: `companyTimezone` falls back to `'UTC'` when the company store hasn't hydrated, and `defaultFilters` is captured on first render only. Near midnight in `Africa/Tunis` the default window can land on the wrong calendar day — the exact class of defect §4.6 exists to prevent.
12. No test covers `receipt_number` search or its `addcslashes($v, '\\%_')` escaping (`ReceiptController.php:88-91`). The escape relies on PostgreSQL's default backslash `ESCAPE`; on the SQLite test substrate `LIKE` has no default escape char, so the behaviour differs between test and production and neither is asserted.
13. `test_empty_type_array_is_rejected` (`ReceiptIndexTrainingExclusionTest.php`) sends `invoice_type_codes[]=` — a one-element array of `''` — so it exercises the `in:` rule, not spec rule 4's `min:1` on a genuinely empty array. Right outcome, misleading name.
14. Playwright flow 1 and the M1 screenshots never ran (API not up at `127.0.0.1:8010`). Honestly reported and recorded in the YAML `findings:`; still an open wave-1 evidence item, and it is the sole behavioural proof of finding 3.
15. Handback/YAML drift: the commit list and `commit: f2bd463b8` omit `24808cc63` (Phase 1.1.12).

## Bypasses I tried that FAILED (no defect found)

- **Broken consumers of the reshaped list row** (`receipt_type`, `subtotal`, `tax_amount`, `customer_name`, `partner_id`, `contact_id`, `is_voided`, `void_reason` all dropped): grepped `apps/web/src`, `apps/pos/src`, `apps/mobile/src` for `/pos/receipts` — no client reads the index. Only the legacy test (P1-1) breaks.
- **Null-FK TypeError on the non-nullable DTO** (`location_id`/`terminal_id`/`cashier_id` typed `string`): all three are NOT NULL in `create_pos_receipts_table.php:29,32,51`. Safe.
- **A cross-receipt aggregate** (Addendum A(c) rule 2 / OI-3): `git diff -U0 … | grep -iE '\bSUM\s*\(|reduce\(|totalsStrip'` — no matches. Rule holds.
- **Permission-map drift** (S-9 / preflight hard failure): re-ran `CACHE_STORE=array php artisan permissions:export-frontend-map`; `git status --porcelain` clean. Committed artefact is in sync.
- **Location-scope escape**: `[]` → `whereIn('location_id', [])` (deny) ✔; `null` + attacker-supplied `location_ids[]` is still bounded by `where('company_id', …)` ✔; intersection only ever narrows (`ReceiptController.php:74-84`). `LocationContext::getAllowedLocationIds` fails closed at `:194-207`.
- **Making the FE emit the 422 shape**: the toggle always sends `include_training=true` *with* `'TRAINING'`, and `fiscal_status` is whitelisted client-side before dispatch (`ReceiptListPage.tsx:64-79`). Cannot be forced from the URL.
- **Attributing the other two Feature failures to this diff**: `GoodsReceiptLedgerSchemaTest` and `AdvanceReversalGlShapeTest` sit in inventory-schema / treasury-GL, which no diffed file reaches — pre-existing.
- **Attributing the reported vitest failures to this diff**: `useAnalytics.tenantScope.test.tsx` fails on `locScope` key drift in files this diff never touches. Handback claim accurate.
- **Pint / ESLint on the new code**: Pint clean on every changed API path; `pnpm eslint` on the new FE files → **0 errors** (7 pre-existing warnings). Design-token and tenant-scoped-key rules pass.
- **The differing-currency assertion** (Addendum A(c) rule 1): present and real — `ReceiptIndexEnvelopeTest` sets company `EUR` / receipt `TND` and asserts `TND` + `12.345` (receipt scale 3, not company scale 2). FE renders `12,345 TND` from the row. ✔
- **New backend tests genuinely pass**: `40 tests, 202 assertions` OK across all nine new PG-shaped classes; new FE suites `64 passed`.

Nothing else in the wave-1 scope (S-1/S-2/S-3/S-6/S-7/S-13, S-11 index+options half, S-9/A-2, screen (a), A-1 + nav + OP-23, GATE-3, CL-1/2/5/6/7, i18n rename) showed a defect: the composite `compliance` key, the any-of route gate (`RequirePermission` already supports `permissions[]` — no component change needed), the per-panel gating, and the `fraud-settings.view`/`fraud-alerts.view` re-gate all match the backend keys at `Compliance/Presentation/routes.php:24,37`.

VERDICT: CHANGES-REQUIRED
