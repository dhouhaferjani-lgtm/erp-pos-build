# Cash-rounding server Phase 1 — final verification

- **Branch / tip:** `feat/pos-cash-rounding` @ `64acb47e2`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.cash-rounding`
- **Branch base:** `534611b43` (plans commit); merge-base with `origin/dev` = `1201ba37da129f66846320188f591e2573599655` (identical touched-file set from either base)
- **Run date:** 2026-07-28
- **PG test DB:** `autoerp_cash_rounding_test` on `127.0.0.1:5433` (created by Task 1, still in place)
- **Runner env (every PG batch):**

```bash
cd apps/api && DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 \
  DB_DATABASE=autoerp_cash_rounding_test DB_CENTRAL_DATABASE=autoerp_cash_rounding_test \
  DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
  ./vendor/bin/phpunit -c phpunit-pgsql.xml <paths>
```

> **Deviation from the plan (deliberate, controller-directed):** the plan's
> `./scripts/preflight.sh` bullet was NOT run — the standing owner rule is
> *tests BY PATH only* (a full suite crashes the machine). The plan's own
> enumerated by-path batches were run instead, plus `pint --test` and
> `phpstan` (which are what preflight would have contributed for the backend)
> and `pnpm typecheck` for the web app.

---

## Summary

| Category | Result |
|---|---|
| Targeted + regression suites (PG, 3 batches) | **PASS** — 433 tests / 1404 assertions green; 1 failure, **pre-existing** (see below) |
| Golden-byte suites (SQLite) | **PASS** — 127 tests / 659 assertions |
| `pint --test` (61 touched PHP files) | **PASS** |
| `phpstan analyse` (32 touched `app/` files, level 8, live-DB env) | **PASS** — no errors. Also clean on the full configured `app/` path set |
| `apps/web pnpm typecheck` | **PASS** |
| DB-backed E2E smoke (PG, spec §6 figures) | **PASS** — 2 scenarios / 25 assertions |
| **NEW failures attributable to this branch** | **NONE** |

---

## 1. Suites by path

### Batch A — T1–T5 (PG)

```
$ phpunit -c phpunit-pgsql.xml \
    tests/Feature/Treasury/PaymentMethodCashTenderTest.php \
    tests/Feature/Treasury/CountryPaymentSettingsCashRoundingTest.php \
    tests/Feature/Tenant/TenantReferenceDataSeedingTest.php \
    tests/Feature/POS/PosPaymentPolicyEndpointTest.php \
    tests/Feature/POS/ConfigureCashRoundingCommandTest.php \
    tests/Feature/Accounting/BackfillTolerancePurposesCommandTest.php \
    tests/Architecture/ConsoleCommandTenantContextTest.php

................................................................. 65 / 88 ( 73%)
......................F                                           88 / 88 (100%)

Time: 00:45.866, Memory: 163.00 MB

1) Tests\Architecture\ConsoleCommandTenantContextTest::test_every_concrete_artisan_command_is_tenant_classified
Found concrete Artisan command class(es) with no tenant-context classification:
  - App\Console\Commands\ScanPercentScaleDrift
  - App\Console\Commands\ExportFrontendPermissionsMap
  - App\Console\Commands\ConfigureMethodRepositoryRoutingCommand
  - App\Console\Commands\BackfillPayableInstrumentAccountsCommand
  - App\Modules\Product\Presentation\Console\RunEnrichmentCommand
  - App\Modules\Treasury\Presentation\Console\BackfillLocationAttributionCommand
  - App\Modules\Company\Presentation\Console\BackfillMembershipsCommand

Tests: 88, Assertions: 294, Failures: 1.
```

**Classified as PRE-EXISTING, not a new regression.** Evidence:

```
$ for c in <the 7 flagged commands> tests/Architecture/ConsoleCommandTenantContextTest.php; do
    git diff --quiet 1201ba37d HEAD -- apps/api/$c && echo "UNCHANGED $c" || echo "CHANGED $c"
  done
UNCHANGED app/Console/Commands/ScanPercentScaleDrift.php
UNCHANGED app/Console/Commands/ExportFrontendPermissionsMap.php
UNCHANGED app/Console/Commands/ConfigureMethodRepositoryRoutingCommand.php
UNCHANGED app/Console/Commands/BackfillPayableInstrumentAccountsCommand.php
UNCHANGED app/Modules/Product/Presentation/Console/RunEnrichmentCommand.php
UNCHANGED app/Modules/Treasury/Presentation/Console/BackfillLocationAttributionCommand.php
UNCHANGED app/Modules/Company/Presentation/Console/BackfillMembershipsCommand.php
UNCHANGED tests/Architecture/ConsoleCommandTenantContextTest.php
```

Every flagged command AND the assertion itself are byte-identical to the
`origin/dev` merge-base — they arrive from the treasury-phase5 / multiloc
lanes. **Both of this branch's new commands** (`ConfigureCashRoundingCommand`,
`BackfillTolerancePurposesCommand`) are correctly classified and do NOT appear
in the failure list. This is one of the ledger's noted "5 Architecture-suite
failures".

### Batch B — fiscal v3 + projection (PG)

```
$ phpunit -c phpunit-pgsql.xml \
    tests/Feature/Fiscal/SaleReceiptV3PayloadConstraintTest.php \
    tests/Unit/Fiscal/SaleReceiptV3KeySetTest.php \
    tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php \
    tests/Feature/Fiscal/ParseFailureResumeTest.php \
    tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php \
    tests/Feature/POS/PosReceiptsCashRoundingCheckTest.php \
    tests/Feature/POS/PosCoreReceiptProjectionCashRoundingTest.php \
    tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php

OK (240 tests, 791 assertions)   [01:08.891]
```

### Batch C — bridge + Z report + T12 (PG)

```
$ phpunit -c phpunit-pgsql.xml \
    tests/Feature/Treasury/TreasuryReceiptBridgeNettingTest.php \
    tests/Feature/Treasury/TreasuryReceiptBridgeRoundingGlTest.php \
    tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php \
    tests/Feature/POS/ZReportCashRoundingSummaryTest.php \
    tests/Feature/Fiscal/ZReportProjectionTest.php \
    tests/Feature/POS/GenerateZReportToleranceSummaryTest.php \
    tests/Feature/POS/GenerateZReportEndToEndTest.php \
    tests/Feature/POS/CashRoundingPrintAndReportsTest.php \
    tests/Feature/POS/ServerReportAuthoringUnreachabilityTest.php

OK (105 tests, 319 assertions)   [01:07.097]
```

### Batch D — golden-byte suites (SQLite, default `phpunit.xml`)

```
$ ./vendor/bin/phpunit \
    tests/Unit/Fiscal/CanonicalGoldenVectorPhpTest.php \
    tests/Unit/Fiscal/SaleReceiptV2GoldenParityTest.php \
    tests/Unit/Fiscal/StrictCanonicalParserTest.php \
    tests/Unit/Fiscal/BestEffortPayloadParserTest.php \
    tests/Unit/Fiscal/SaleReceiptV3KeySetTest.php

OK (127 tests, 659 assertions)   [00:08.159]
```

**Totals:** 560 tests, 2063 assertions, 1 failure (pre-existing Architecture ratchet).

### Pre-existing failures NOT re-triggered in this run

The ledger's other known-red items were outside the enumerated batches and were
not run here: `PosCoreReceiptProjectionLoyaltyEarnTest` (3 PG errors,
`'prod-default'` uuid), the remaining Architecture-suite failures, the 21
errors in `ZReportHashServiceTest` / `ZReportPdfTest` /
`ZReportGrandTotalsPopulatedTest`, and the deptrac ratchet (61→97, red on `dev`
already). Nothing in this run contradicts that baseline.

---

## 2. Statics

```
$ git diff --name-only 534611b43..HEAD -- '*.php' | wc -l
61

$ ./vendor/bin/pint --test <61 touched files>
{"result":"pass"}
```

```
$ git diff --name-only 534611b43..HEAD -- 'apps/api/app/*.php'   # 32 files
$ ./vendor/bin/phpstan analyse --no-progress --memory-limit=2G <32 files>
Note: Using configuration file .../phpstan.neon.
 [OK] No errors

$ ./vendor/bin/phpstan analyse --no-progress --memory-limit=2G      # full configured app/ paths
 [OK] No errors
```

`phpstan.neon` is level 8 with `checkModelProperties: true`, so the run needs a
live DB. It used `.env` (`APP_ENV=local`, `autoerp` @ `127.0.0.1:5433`), and
that database really does carry the new columns — so the model-property checks
were meaningful, not vacuously skipped:

```
$ psql -h 127.0.0.1 -p 5433 -U autoerp -d autoerp -tAc "<information_schema query>"
country_payment_settings.cash_rounding_denomination
country_payment_settings.cash_rounding_enabled
payment_methods.is_cash_tender
pos_receipts.cash_rounding_adjustment
pos_receipts.cash_rounding_denomination
```

---

## 3. Frontend typecheck

```
$ cd apps/web && pnpm typecheck
> @autoerp/web@0.1.0 typecheck
> tsc --noEmit
(no output — clean)
```

`node_modules` was present (installed during Task 1).

---

## 4. DB-backed E2E smoke (PostgreSQL)

No committed smoke test exists for the full ingest→projection→GL→Z path, so a
**throwaway** PHPUnit file was written to the session scratchpad and run by
absolute path. It is **NOT committed** and lives only at
`/private/tmp/claude-501/.../scratchpad/CashRoundingE2ESmokeTest.php`.

It drives the real production path: `OutboxIngestor::ingest()` on a
device-shaped v3 envelope → the projection rows the ingestor seeds
(`pos_core_receipt`, `treasury_receipt_bridge`) driven through
`ApplyFiscalEventProjectionJob::handle()` → `ZReportProjection::apply()`.
Fixture is Tunisian (TND scale 3, `cash_rounding_denomination` 0.0500,
`payment_tolerance_percentage` 0.5% / max 0.100, `is_cash_tender` CASH).

```
$ phpunit -c phpunit-pgsql.xml /private/tmp/.../CashRoundingE2ESmokeTest.php

SMOKE  integrity_exception_class=NULL reason=NULL parse=parsed
SMOKE  fiscal_event id=75392ff6-be23-4c4d-bf53-335526c4a460 version=3 integrity=verified parse=parsed
SMOKE  payload.total=10.000 subtotal=9.997 cash_rounding_adjustment=0.003 cash_rounding_denomination=0.050
SMOKE  seeded projection rows: pos_core_receipt, treasury_receipt_bridge
SMOKE  projection pos_core_receipt -> applied
SMOKE  projection treasury_receipt_bridge -> applied
SMOKE  pos_receipts: total=10.000 subtotal=9.997 tax=0.000 discount=0.000 adj='0.003' denom='0.0500' change_due='0.000' tolerance_writeoff='0.000'
SMOKE  journal entries for receipt d45f0115-5c8c-485f-934d-dd1413035cdd: 2
SMOKE    [pos_receipt] 53 Dr 10.000 / Cr 0.000 | 707 Dr 0.000 / Cr 10.000
SMOKE    [pos_cash_rounding] 707 Dr 0.003 / Cr 0.000 | 7580 Dr 0.000 / Cr 0.003
SMOKE  pos_tolerance_bridge entry absent (exact tender, shortfall = 0) — expected
SMOKE  Z cash_rounding_summary = {"total_adjustment":"0.003","receipt_count":1}
SMOKE  SMOKE PASS — 9.997 exact → 10.000 signed / +0.003 / 0.050

SMOKE2 pos_receipts: total=10.000 adj='0.003' change_due='0.000' tolerance_writeoff='0.020'
SMOKE2 journal entries: 3
SMOKE2   [pos_receipt] 53 Dr 9.980 / Cr 0.000 | 707 Dr 0.000 / Cr 9.980
SMOKE2   [pos_cash_rounding] 707 Dr 0.003 / Cr 0.000 | 7580 Dr 0.000 / Cr 0.003
SMOKE2   [pos_tolerance_bridge] 6580 Dr 0.020 / Cr 0.000 | 707 Dr 0.000 / Cr 0.020
SMOKE2 SMOKE2 PASS — three journal entries end-to-end

OK (2 tests, 25 assertions)   [00:16.556]
```

### Reading the smoke

- **Ingest** — the v3 envelope parses cleanly through `StrictCanonicalParser`
  (`parse=parsed`, `integrity=verified`), i.e. the 30-key v3 payload contract
  and the `total = subtotal + vat − discount + adjustment` relaxation both hold
  at the real ingestion boundary, not just in unit fixtures.
- **Projection columns** — `cash_rounding_adjustment='0.003'`,
  `cash_rounding_denomination='0.0500'`, `change_due='0.000'`,
  `tolerance_writeoff='0.000'` (zeros, not NULL — the v3 discriminator).
- **GL, exact-tender case (plan's headline figures)** — only **two** entries
  post: `pos_receipt` + `pos_cash_rounding`. There is deliberately **no**
  `pos_tolerance_bridge` entry, because a 10.000 tender against a 10.000 signed
  total leaves zero shortfall. The plan's wording ("the three journal entries")
  does not hold for these particular figures; the third entry only exists when
  the receipt is under-tendered. That is correct behaviour, not a gap.
- **GL, three-entry case (SMOKE2)** — the same rounded receipt tendered at
  9.980 produces all three entries. Net effect on revenue account 707:
  `9.980 − 0.003 + 0.020 = 9.997` — exactly the pre-rounding sale value, with
  cash (53) carrying only what was actually collected. That is the spec §4.6
  invariant, reproduced end-to-end rather than at the unit level. (The same
  invariant with the spec's own −0.023 figures is pinned by
  `TreasuryReceiptBridgeRoundingGlTest::test_worked_example_posts_all_three_entries_and_balances`,
  green in batch C.)
- **Z report** — `cash_rounding_summary = {"total_adjustment":"0.003","receipt_count":1}`,
  server-derived over the Z window.

Three fixture-only corrections were needed while building the smoke (all my
payload authoring errors, none product defects, each surfaced as an explicit
parse-failure reason from `FiscalPayloadConstraintValidator`):
`seller.tax_number` must match the TN pattern `^[0-9]{7,8}[A-Z]{2}[0-9]{3}$`;
v2+ line items require `variant_id`/`variant_name`/`variant_sku`;
`line_items[].quantity` is validated at the payload `currency_scale` (3), not 4.

---

## 5. Verdict

All four verification categories PASS. The single test failure encountered
(`ConsoleCommandTenantContextTest`) is pre-existing on `origin/dev` and is
caused by seven commands from other lanes, none of them touched by this branch.
No new failure is attributable to cash-rounding server Phase 1.
