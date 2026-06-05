# Opus Adversarial Review — Task 18 (Cross-language parity + Phase-2 wrap)

**Plan:** `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md` — Task 18
**Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md`
**Diff:** `/tmp/branch-tax-id-task-18.diff` (132 lines, single new file)
**Commit:** `1875dd5cb test(branch-tax-id): cross-language source-path and validator acceptance`
**Reviewer:** Opus (adversarial)
**Date:** 2026-06-05

---

## Scope of the diff

Test-only. One new file:
- `apps/api/tests/Feature/Fiscal/BranchSellerTaxNumberValidationTest.php` (+126)

Two test methods:
1. `test_sale_receipt_validator_accepts_branch_fr_seller_tax_number` — mutates the `F-01-baseline-eur` golden builder payload to an FR branch seller (`address.country_code=FR`, `tax_jurisdiction_country_code=FR`, `tax_number='73282932000074'`) and feeds it through `validatePayloadKeySet` + `validatePerEventConstraints` for `SALE_RECEIPT`.
2. `test_account_payment_validator_accepts_branch_tn_seller_tax_number` — hand-builds an `ACCOUNT_PAYMENT` payload with a TN branch seller (`tax_number='1234567/A/M/000'`) and runs the same two validator gates.

This matches plan Task 18 Step 2 ("a small feature test feeding a branch `seller.tax_number` through the validator — assert no exception"). Plan text references `validateSeller`; the test reaches it through the public `validatePerEventConstraints` gate, which is the correct public seam (`validateSeller` is private). No deviation of substance.

---

## Verification against real code

**Validator reachability — both event paths exercise the seller gate.**
`validateSeller()` is called at three sites: line 717 (`validateSaleReceiptPayload`), 918 (`validateAccountPaymentPayload`), 1092 (`validateAccountChargePayload`). The two tests therefore genuinely drive seller `tax_number` validation for both live event types. ✔

**FR pattern.** `TAX_NUMBER_PATTERNS['FR'] = /^([0-9]{9}|[0-9]{14})$/D`. Test value `73282932000074` is 14 digits → matches the SIRET branch. ✔

**TN pattern + normalization.** `TAX_NUMBER_PATTERNS['TN'] = /^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/D`. Test value `1234567/A/M/000` → `normalizeTaxNumberForCountry('TN')` strips `/` → `1234567AM000` → `7 digits + AM + 000` matches. ✔ Good adversarial choice: the slashed form exercises the TN normalization path rather than the pre-normalized form, so the test would catch a regression in slash stripping.

**Country source.** `validateSeller` keys country off `tax_jurisdiction_country_code` (line 1487–1492), and the FR test sets both `tax_jurisdiction_country_code` and `address.country_code` to FR — consistent, no internal mismatch. ✔

**ACCOUNT_PAYMENT key-set exactness (the real failure risk for a hand-built payload).** `validatePayloadKeySet` rejects any missing or extra top-level key. Expected key set `PAYLOAD_KEYS['ACCOUNT_PAYMENT']` (lines 264–285) is exactly the 20 keys the test payload supplies (`account_payment_uuid, business_date, cashier_id, cashier_name, currency_code, currency_scale, customer, event_time_device, local_balance_snapshot, notes, payment, receipt_type_code, references, regime_extensions, seller, shift_id, staleness, terminal_id, training_flag, treasury_allocation_policy`). No missing, no extras → `assertNull` holds. ✔ Nested values (TND scale-3 money strings `100.000`, `customer_sync_status='synced'`, `treasury_allocation_policy='FIFO'`, staleness reasons) all fall inside the validator's allowed enums/regexes.

**Validator instantiation.** `new FiscalPayloadConstraintValidator;` — the class has no constructor/dependencies (confirmed via method listing), so the no-arg construction in `setUp` is valid and does not violate the constructor-injection rule (this is a test, and the SUT is dependency-free).

---

## Phase-2 end-of-phase gate

- **Value-only, no fiscal payload schema/version bump (spec D6 / §7).** The diff touches no canonical DTO, no `PAYLOAD_KEYS`, no version constant. Across the Phase-2 device commits (15–18: TerminalResource location tax fields, device `Terminal.location` types, `paymentStore.ts` seller sourcing, this test) nothing mutates payload schema or a fiscal version constant. The large `main..branch` file count reflects base divergence (the whole fiscal engine lives on dev, not main), **not** Phase-2 drift. ✔
- **Golden fixtures intact.** No fixture file is in this diff; the SALE_RECEIPT test mutates an in-memory copy from `GoldenFixtureBuilder::all()['F-01-baseline-eur']`, leaving the on-disk golden vectors untouched. ✔
- **Both live seller paths source branch-over-company (Task 17).** Confirmed in `paymentStore.ts` (commit `d526d4edf`): `createReceiptLocalFirst` (SALE_RECEIPT, line ~549) and `createAccountPaymentLocalFirst` (ACCOUNT_PAYMENT, line ~661) both build `taxNumber: branchTaxNumber ?? companyField(company, 'taxId', 'tax_id')` via `branchTaxNumberFromTerminal(terminal)`. Branch wins; company is the fallback. ✔
- **ACCOUNT_CHARGE out-of-scope, documented.** Spec §8 (rev-2 M3) states ACCOUNT_CHARGE has no live production seller caller and is out of P0. Confirmed independently: `paymentStore.ts` has only two `*LocalFirst` seller builders (SALE_RECEIPT, ACCOUNT_PAYMENT) — no ACCOUNT_CHARGE device seller path to source. ✔

---

## Adversarial hunt

- **TDD violation:** None. Task 18 is a test/parity wrap over already-implemented Tasks 15–17; the additive tests are the deliverable and assert real behavior (`assertNull` on the key-set gate + throw-on-failure of the constraint gate).
- **`app()` / `mixed` / `any`:** No `app()`. No PHP `mixed` *type declarations*; the only `mixed` is a PHPDoc array-shape annotation (`@param array<string, mixed>`) on the local payload-bag helper, which matches the validator's own pervasive idiom for raw payload arrays — not a typed-`mixed` violation. No `any` (PHP file).
- **Missing i18n `t()` / hardcoded Tailwind colors:** N/A — backend PHP test, no frontend surface.
- **Fiscal-payload schema/version drift:** None (see gate above).
- **Branch-vs-company fallback bug:** None in scope; Task 17 fallback re-verified as branch-preferred with company fallback.

---

## Findings

| Severity | Location | Finding |
|---|---|---|
| NIT | `BranchSellerTaxNumberValidationTest.php:39,57` | `addToAssertionCount(1)` after the void `validatePerEventConstraints` call documents "no exception thrown" only implicitly. An `$this->expectNotToPerformAssertions()`-style intent or a one-line comment would read more clearly. The preceding `assertNull(...)` is a real assertion, so the test is not assertion-free. Cosmetic. |
| NIT | `BranchSellerTaxNumberValidationTest.php:60-63` | The FR test couples to the shared `F-01-baseline-eur` builder (which itself pairs a TN seller with EUR currency); a future change to that baseline could perturb this test. Acceptable — reusing the canonical builder is the intended pattern and keeps the payload valid end-to-end. |

No BLOCKER, MAJOR, or MINOR findings.

---

## Note on execution

Test execution was blocked by the session sandbox (could not run `php artisan test` / `phpunit`). Verification here is static but complete on the dimensions that determine pass/fail: exact top-level key-set match against `PAYLOAD_KEYS['ACCOUNT_PAYMENT']`, country-pattern match for both FR and TN (including TN slash normalization), and confirmation that both event paths route through `validateSeller`. The recorded commit `1875dd5cb` indicates the executor ran it green at commit time.

---

## Verdict

The diff is a correct, well-targeted Phase-2 wrap: it adds genuine validator-acceptance coverage for FR and TN branch-authored seller tax numbers across both live fiscal event types, introduces no payload schema/version drift, leaves golden fixtures intact, and correctly treats ACCOUNT_CHARGE as out-of-scope (no live seller path exists). Only two cosmetic NITs.

VERDICT: APPROVE
