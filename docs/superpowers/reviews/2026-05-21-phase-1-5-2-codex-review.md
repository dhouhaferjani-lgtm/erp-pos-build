# Phase 1.5.2 Codex Self-Adversarial Review

**Reviewed commit:** `a6cdb9304` (`Phase 1.5.2: Enforce per-country fiscal tax numbers`)
**Reviewer:** Codex
**Verdict:** APPROVE

## Scope Reviewed

- PHP fiscal payload validator: `FiscalPayloadConstraintValidator`.
- TS fiscal engine mirror: `FiscalEventEngine`.
- Device payload assemblers for `SALE_RECEIPT` and `ACCOUNT_PAYMENT`.
- Updated fiscal/account-payment fixtures and docs.
- Phase 1.5.2 positive/negative fixture matrix for FR, TN, SA, DE, IT, FR buyer TVA, IT `buyer.codice_fiscale`, unknown-country fallback.

## Findings

No blocking or request-change findings remain.

## Adversarial Checks

- **PHP/TS mirror:** PASS. Both sides retain the universal baseline, then apply the same country-keyed patterns for FR/TN/SA/DE/IT. Both use `payload_tax_number_format_mismatch:field=<path>:country=<country>:value=<actual>` and `payload_buyer_codice_fiscale_format_mismatch:field=buyer.codice_fiscale:value=<actual>`.
- **TN normalization:** PASS after self-review fix. Initial implementation normalized only `SALE_RECEIPT`; adversarial scan found `ACCOUNT_PAYMENT` still emitted raw slash-form seller tax numbers. Fixed before commit in `accountPaymentService.ts` with a focused test.
- **Fail-loud vs silent downgrade:** PASS. Known countries reject mismatches with typed forensic prefixes. Unknown countries intentionally fall back only to the universal baseline per handover.
- **Dead-path rebuild:** PASS. The strict validators are in live parser/engine paths, and both device assemblers now emit compact TN canonical tax numbers.
- **Discriminated/event matrix:** PASS. SALE_RECEIPT and ACCOUNT_PAYMENT are covered on PHP and TS. ACCOUNT_PAYMENT customer tax-number fallback uses customer address country when present, otherwise seller country.
- **Contract drift:** PASS. Synthesis v5 §7 and roadmap v2 Phase 1.5 now describe the landed regex table and commit.
- **Cross-tenant FK safety:** N/A. No DB FK lookups or writes were introduced.
- **Constructor injection / rule 13:** PASS. No `app()`, `App::make`, or `resolve()` introduced.
- **Skip policy / citation accuracy:** PASS. No new skips. Existing skip count is unchanged; docs cite the Phase 1.5.2 branch status and implementation commit.

## Verification Evidence

- PHP focused: `FiscalPayloadConstraintValidatorTest.php --filter 'phase_1_5_2'` => 6 tests, 11 assertions.
- PHP full fiscal: `tests/Feature/Fiscal/ tests/Unit/Fiscal/` => 547 tests, 2064 assertions, 45 existing skips.
- PHP POS feature: `tests/Feature/POS/` => 583 tests, 1767 assertions, 62 existing skips, 2 existing incomplete, 16 deprecations.
- PHPStan L8: `app/Modules/Fiscal` plus touched fiscal tests/helpers => no errors.
- Pint: touched Fiscal app/tests/helpers => pass.
- POS focused: fiscal engine, receipt service, account payment service => 80 tests.
- POS full: `pnpm test` => 162 files, 1450 tests.
- POS typecheck: pass.
- POS lint: exit 0 with 41 pre-existing warnings in unrelated files.
- Chokepoints: `check-saleReceipt-chokepoints.sh` and `check-pass-2b-pending.sh` => pass.
- `git diff --check` => pass.

## R2 Self-Review After Opus REQUEST-CHANGES

**Reviewed follow-up commit:** `b94304005` (`Phase 1.5.2: Normalize account payment customer tax IDs`)
**Verdict:** APPROVE

### Opus Finding 1: ACCOUNT_PAYMENT customer TN slash form could seal verbatim

Accepted. `FiscalEventEngine` validation normalized only for matching, while canonical hashing uses the original object. `buildAccountPaymentPayload()` now builds the seller block first, uses its resolved `tax_jurisdiction_country_code`, and normalizes non-null `customer.tax_number` with the same TN slash removal before returning the payload. Added `accountPaymentService.test.ts` coverage for `7654321/B/M/000 -> 7654321BM000`.

Adversarial check: this is still fail-loud for invalid values because the engine validator remains in the append path; the assembler only canonicalizes the known TN separator form before hashing. No cross-tenant lookup was introduced.

### Opus Finding 2: synthesis v5 status contradicted landed §7

Accepted. The synthesis header and §16 now mark v5 as locked and note that Phase 1.5.2 amended §7 with the landed per-country tax-number table. `rg` verified the stale `DRAFT`, `pending Codex round-5`, `owner sign-off`, and `plan amendment` strings are gone from the synthesis doc.

### R2 Verification

- Focused POS tests: `pnpm test src/lib/offline/__tests__/accountPaymentService.test.ts src/lib/fiscal/__tests__/FiscalEventEngine.test.ts src/lib/offline/__tests__/receiptService.test.ts` => 3 files, 81 tests.
- POS typecheck: pass.
- POS lint: exit 0, same 41 unrelated warnings.
- Full POS tests after R2: `pnpm test` => 162 files, 1451 tests.
- Chokepoints: `check-saleReceipt-chokepoints.sh` + `check-pass-2b-pending.sh` => pass.
- `git diff --check` => pass.

### R2 Opus Re-Review

Opus re-review returned APPROVE. It noted one older synthesis v5 §11 sentence still describing tax numbers as universal-pattern-only; this was cleaned to point to the Phase 1.5.2 §7 country table before audit-trail commit.
