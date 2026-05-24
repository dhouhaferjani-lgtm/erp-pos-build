# Codex Adversarial Review - Task 27B Pass 2A.PHP.1

## Executive Summary

REQUEST-CHANGES. The core v5 closures are mostly implemented: the PHP SALE_RECEIPT key set is the 27-key sorted list, `invoice_subtype_code` is absent, the VAT partition algorithm uses BCMath with the required forensic prefixes, the discount-zero check uses `bccomp()`, F-01..F-15 are committed, and the authorized PHP.2 skips are clearly cited. I found no BLOCKER. I did find contract holes in the validator that would admit out-of-contract immutable SALE_RECEIPT payloads: unsupported `currency_scale` values, unconstrained UUID/date-time identity fields, and inconsistent `invoice_type_code=TRAINING` vs `training_flag`.

## Phase 1 Spec-Compliance Verification

1. CONFIRMED - `PAYLOAD_KEYS['SALE_RECEIPT']` is byte-equal to the 27-key synthesis list. v4/v5 inherit the sorted list at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md:56-64`; the implementation matches it at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:120-148`. `invoice_subtype_code` is absent, consistent with D7 dropping it at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:18-19`.

2. CONFIRMED - The partition algorithm matches v5 §6.C at byte-level behavior. v5 requires grouping by `(vat_rate, tax_category_code)`, `bcadd` sums, `bccomp` equality, duplicate rejection, set equality, and exact forensic prefixes at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:89-111`. The implementation groups by `$rate.'|'.$category` at `FiscalPayloadConstraintValidator.php:701-718`, rejects duplicates with `payload_partition_duplicate:rate=...:category=...` at `:720-733`, emits `payload_partition_mismatch:lines_set=...:breakdown_set=...` at `:736-745`, and compares net/VAT/gross with `bccomp(..., $scale)` plus the required mismatch prefixes at `:748-769`.

3. CONFIRMED - The discount-reason invariant uses BCMath numeric zero comparison, not literal string equality. v5 requires `bccomp(transaction_discount_amount, "0", $currency_scale) == 0` at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:41-44`. The validator narrows the amount and uses `bccomp($discountAmount, '0', $scale) === 0` at `FiscalPayloadConstraintValidator.php:254-271`.

4. CONFIRMED - The scale invariant covers the v5 §6.B field table. v5 enumerates top-level money, line money, quantity, VAT rate, VAT breakdown amounts/rate, payment amount/foreign amount, and voucher amount at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:51-69`. The validator checks top-level money at `FiscalPayloadConstraintValidator.php:249-252`, line money/quantity/vat_rate at `:507-517`, payments including foreign-currency amount at `:586-612`, VAT breakdown at `:638-641`, and vouchers at `:683-685`.

5. CONFIRMED - Total arithmetic cross-check matches v5 §6.D. v5 locks `bccomp(bcadd(subtotal, vat_total, $scale), bcadd(total, transaction_discount_amount, $scale), $scale) == 0` at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:116-132`. The validator computes `$lhs = bcadd($subtotalN, $vatTotalN, $scale)`, `$rhs = bcadd($totalN, $discountAmount, $scale)`, then `bccomp($lhs, $rhs, $scale)` at `FiscalPayloadConstraintValidator.php:274-284`.

6. CONFIRMED - Money regexes have no leading `^-?`. v5 forbids negative payload money at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:36-48`. `moneyRegex()` returns `/^(0|[1-9]\d*)$/D` for scale 0 and `/^(0|[1-9]\d*)\.\d{...}$/D` for scale > 0 at `FiscalPayloadConstraintValidator.php:1108-1126`.

7. CONFIRMED - Universal tax-number validation is semantically the v5 pattern. v4/v5 require `^[A-Za-z0-9 \-/.]{4,40}$` plus non-empty/no control/trim checks at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md:150-158`. PHP uses the delimiter-escaped equivalent `'/^[A-Za-z0-9 \-\/.]{4,40}$/D'` at `FiscalPayloadConstraintValidator.php:76-83` and applies trim/control-byte checks at `:1037-1082`.

8. CONFIRMED - `FiscalPayloadConstraintValidatorTest` covers all 12 v5 §6.E cases. The source list is at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:136-149`. Test mapping: case 1 `test_negative_1_duplicate_partition_row_is_rejected` at `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:115-124`; case 2 `test_negative_2_missing_partition_row_is_rejected` at `:126-140`; case 3 `test_negative_3_extra_partition_row_is_rejected` at `:142-153`; case 4 `test_negative_4_one_cent_drift_is_rejected` at `:155-174`; case 5 `test_negative_5_mixed_zero_percent_categories_are_accepted_when_partitioned_correctly` at `:176-183`; case 6 `test_negative_6_wrong_money_scale_is_rejected_with_scale_mismatch_prefix` at `:185-194`; case 7 `test_negative_7_total_arithmetic_mismatch_is_rejected` at `:196-208`; case 8 `test_negative_8_negative_transaction_discount_is_rejected` at `:210-218`; case 9 `test_negative_9_discount_zero_at_scale_2_with_reason_is_rejected` at `:220-229`; case 10 `test_negative_10_discount_zero_at_scale_2_with_null_reason_passes` at `:231-241`; case 11 `test_negative_11_discount_non_zero_with_null_reason_is_rejected` at `:243-254`; case 12 `test_negative_12_discount_zero_at_scale_0_tnd_with_null_reason_passes` at `:256-290`.

9. CONFIRMED - The 15 golden vectors match the v5/v3 matrix descriptions, and spot-checks are JCS-canonical. The matrix requires F-01 baseline through F-15 large receipt at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:344-368`. `GoldenFixtureBuilder::all()` returns F-01..F-14 with matching descriptions at `apps/api/tests/Helpers/Fiscal/GoldenFixtureBuilder.php:28-45`; F-15 is generated as 50 lines, 10 payments, 8 VAT rows at `apps/api/tests/Helpers/Fiscal/LargeReceiptFixtureGenerator.php:8-27`. F-01 `payload.json` is one line, sorted top-level keys beginning `business_date,buyer,cashier_id,...`, and uses exact money strings such as `"10.00"`/`"1.000"` at `apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-01-baseline-eur/payload.json:1`. F-15 is committed as `apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-15-large/payload.json:1`, and the test asserts generator output matches that committed byte string at `FiscalPayloadConstraintValidatorTest.php:513-521`.

10. CONFIRMED - `SALE_RECEIPT` still maps to `event_version` 1. The owner directive is explicit in v3 D4 and spec v7 at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:15` and `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:593`. `FiscalEventPayloadRegistry` maps `FiscalEventType::SALE_RECEIPT->value => [SaleReceiptPayload::class, 1]` at `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php:37-42`.

## Phase 2 New Defects

### BLOCKER

None found.

### P1

N-01 - `currency_scale` accepts out-of-contract values

Severity: P1

What's wrong: The canonical TypeScript contract says `currency_scale: number; // 0|2|3` at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:49-50`, and spec v7 repeats monetary fields use `currency_scale (0|2|3)` at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:577`. The validator accepts any integer `0..8` at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:221-226`, then builds a regex for that arbitrary scale at `:227` and `:1119-1126`. A payload with `currency_scale=8` and 8-decimal money would pass PHP validation while drifting from the locked TS/PHP contract and golden-vector domain.

Fix: Change the validator to allow only `[0, 2, 3]` for primary `currency_scale`, and add a negative test for `currency_scale=8`. If primary currency code/scale consistency is required, reuse or extend the existing scale lookup at `FiscalPayloadConstraintValidator.php:100-108`.

N-02 - Payload identity/time fields are only non-empty strings, not contract formats

Severity: P1

What's wrong: The v3 type locks UUID-like identity fields and a formatted device timestamp: `cashier_id` UUID, `event_time_device` ISO 8601 with milliseconds and timezone, `receipt_uuid`, `shift_id`, and `terminal_id` at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:46-52` and `:84-95`; spec v7 repeats those identity contracts at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:570-571`. The validator checks only non-empty strings for `cashier_id`, `cashier_name`, `event_time_device`, `receipt_uuid`, `shift_id`, and `terminal_id` at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:238-244`. It also checks `original_receipt_reference.fiscal_event_id` and `original_receipt_uuid` only as non-empty strings at `:471-474`. This admits immutable parsed payloads with invalid IDs or non-date timestamps.

Fix: Add strict helpers for UUID and payload date-time format, then apply them to `cashier_id`, `receipt_uuid`, `shift_id`, `terminal_id`, `original_receipt_reference.fiscal_event_id`, and `original_receipt_reference.original_receipt_uuid`. Add negative tests for a non-UUID `receipt_uuid` and malformed `event_time_device`.

N-03 - `TRAINING` invoice type is not tied to `training_flag`

Severity: P1

What's wrong: The migration documents `training_flag` as a denormalized boolean derived from `invoice_type_code == 'TRAINING'`, and reporting queries filter on `training_flag = FALSE` at `apps/api/database/migrations/2026_05_20_120000_add_invoice_type_code_and_training_flag_to_pos_receipts.php:22-32`. The validator checks only that `invoice_type_code` is in the enum and `training_flag` is a bool at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:234-237`; it does not reject contradictory payloads such as `invoice_type_code='TRAINING'` with `training_flag=false` or `invoice_type_code='SALE'` with `training_flag=true`. F-11 sets both consistently at `apps/api/tests/Helpers/Fiscal/GoldenFixtureBuilder.php:284-301`, but there is no invariant.

Fix: Enforce `($payload['invoice_type_code'] === 'TRAINING') === $payload['training_flag']` in `validateSaleReceiptPayload()` and add both mismatch-direction negative tests. This protects the reporting column semantics before PHP.2's projector writes the new fields.

### P2

N-04 - F-15 generator uses a float hop while claiming strict decimal generation

Severity: P2

What's wrong: The F-15 generator states it enforces BCMath partition totals, total arithmetic, and exact scale at `apps/api/tests/Helpers/Fiscal/LargeReceiptFixtureGenerator.php:13-22`, but it derives `$unitPrice` with `(string) ($unitPriceMinor / 100)` before feeding the result to `bcadd()` at `:96-98`. The current deterministic values are small enough to produce the committed fixture accepted by the validator, and `FiscalPayloadConstraintValidatorTest` proves the committed output byte-for-byte at `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:493-521`. Still, the helper's implementation contradicts its "BCMath/strict typing throughout" premise and can become fragile if the fixture formula changes.

Fix: Build unit prices from integer minor units without floating division, for example `bcdiv((string) $unitPriceMinor, '100', $currencyScale)` or string formatting from integer cents.

### P3

N-05 - Negative discount test prefix does not mirror the §6.E wording

Severity: P3

What's wrong: v5 §6.E case 8 says a negative `transaction_discount_amount` should expect `payload_money_format_mismatch` at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:145`, while the validator and test use `payload_money_scale_mismatch` for all regex failures at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:905-912` and `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:210-218`. This is not a runtime correctness issue because v5 §6.B also says any scale check failure uses `payload_money_scale_mismatch` at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:77`, but the test no longer matches one sentence in the negative-case list.

Fix: Either amend the doc's case 8 expected prefix to `payload_money_scale_mismatch` or split money format vs scale failures in code. I would amend the doc; the implementation's single regex-failure prefix is consistent with §6.B.

## Phase 2 Other Axes Checked

11. Dead-path rebuild: `CanonicalPayloadReader` currently has no production caller by design; its own doc says consumers migrate in Pass 2A.PHP.2 at `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php:27-33`. It constructs all canonical DTOs at `:61-98`, and tests exercise both DTO factories and `forSaleReceipt()` at `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:559-665`. No defect for PHP.1 foundation scope.

12. D16 Treasury omission edge cases: the guard targets `PosCoreReceiptProjection.php` only at `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionD16Test.php:41-43`, covers Customer/Contact/B2B/Accounting module imports, Shared\Contracts imports, and container helpers at `:53-66`, and documents the owner-blessed direct Treasury omission at `:25-39`. There are no projector traits under `apps/api/app/Modules/POS/Application/Projections`; the single projection file's class declaration has no trait use at `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:84-107`. No defect under the provided scope clarification.

13. Per-method skip citation accuracy: the five affected files have the claimed 1/5/6/5/19 skip distribution. Representative skips cite PHP.2 migration of SALE_RECEIPT helpers in `FiscalEventIngestionEndpointTest.php:119-125`, `OutboxIngestorTest.php:171-177`, `ParseFailureResumeTest.php:155-161`, and `FiscalEventPayloadRegistryTest.php:93-99`; Strict parser skips cite migration of `validSaleReceiptEnvelope()` / `envelopeWithRawPayload()` at `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php:39-45`. The skipped bodies still show old 10-key helper payloads, for example `FiscalEventPayloadRegistryTest.php:100-115`, so the citations are accurate and implementable.

14. Cross-language drift gate compatibility: the PHP key order matches the v5/v3 TS definition as noted in Phase 1 item 1. I did not find `invoice_subtype_code` in PHP `PAYLOAD_KEYS`; the dropped-field decision is at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:18-19`.

15. F-15 generator output integrity: the generator builds 50 lines and 8 partition groups at `apps/api/tests/Helpers/Fiscal/LargeReceiptFixtureGenerator.php:75-154`, computes totals and payments with BCMath at `:156-168`, and the test validates both acceptance and committed fixture bytes at `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:493-521`. Apart from N-04's float hop, output integrity is confirmed.

16. DTO array-key vs property-name consistency: snake_case payload keys are mapped to camelCase DTO properties in `SaleReceiptPayload::fromArray()` at `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php:95-129`; nested DTOs map `product_id`, `tax_category_code`, `unit_price`, and `vat_rate` at `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/LineItemDTO.php:46-60`, payment keys at `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/PaymentDTO.php:41-48`, and seller keys at `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/SellerDTO.php:46-51`. Confirmed.

17. Migration safety: the migration adds constant-default, not-null-by-default Laravel columns at `apps/api/database/migrations/2026_05_20_120000_add_invoice_type_code_and_training_flag_to_pos_receipts.php:41-46`. The local/staging compose files use PostgreSQL 16 via Timescale images at `docker-compose.yml:1-4` and `docker-compose.staging.yml:55-60`, where constant defaults are metadata-only rather than a full table rewrite. Confirmed for this repo's declared PostgreSQL target.

18. `VoucherRedemptionDTO`: correctly scoped. The canonical contract includes `vouchers_redeemed` at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:107-110`; the reader imports and constructs `VoucherRedemptionDTO` at `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php:14` and `:84-87`; the DTO maps only `{redeemed_amount, voucher_code}` at `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/VoucherRedemptionDTO.php:9-31`.

19. Test isolation: confirmed. `FiscalPayloadConstraintValidatorTest` extends `Tests\TestCase`, does not use `RefreshDatabase`, and explicitly documents no DB hit at `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:22-38`.

20. F-01 JCS canonicality: confirmed by spot-check. `payload.json` is a single line with no insignificant whitespace, sorted top-level keys, exact scale money strings, no leading sign, and nested sorted objects at `apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-01-baseline-eur/payload.json:1`. The encoder sorts recursively and uses `json_encode` without pretty printing at `apps/api/tests/Helpers/Fiscal/GoldenFixtureBuilder.php:498-538`.

## Phase 3 Forward-Compatibility Notes

21. PaymentMethodResolver preview: PHP.1's reader exposes `payments(): list<PaymentDTO>` at `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/SaleReceiptCanonicalView.php:51-55`, and `PaymentDTO` exposes `methodCode` but deliberately excludes `payment_method_id` at `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/PaymentDTO.php:21-24` and `:27-34`. That is compatible with the planned PHP.2 `App\Shared\Contracts\Fiscal\PaymentMethodResolver` invocation site: projector code can iterate `$view->payments()` and resolve each `$payment->methodCode`.

22. PHP.2 un-skip plan: implementable from the citations. The skip messages consistently name the stale helper categories to migrate and point to v5 §8. The five files are not class-skipped; each skipped method keeps its body, which gives PHP.2 enough context to replace old payload helpers without reverse-engineering hidden state. Representative examples are `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php:171-181`, `apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php:155-165`, and `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php:39-54`.

## Verdict

The PHP.1 foundation is close and the main v5 closure work is solid, but accepting out-of-contract currency scales, non-UUID/non-date identity fields, and contradictory training flags at the validator boundary is too much for approval. These are tightly scoped validator/test fixes and do not require re-litigating the PHP.2 deferrals.

VERDICT: REQUEST-CHANGES
