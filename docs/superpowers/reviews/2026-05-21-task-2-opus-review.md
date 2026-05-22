# Task 2 Opus Second-Pass Adversarial Review

Date: 2026-05-22
Branch: `feat/fiscal-phase-3-charge-to-account`
Reviewed commit: `1c5d952cc` (`Phase 3.2.1: Validate account charge canonical payloads`)
Scope: PHP `ACCOUNT_CHARGE` parser/validator/canonical reader only, files changed by the commit.

## Verdict

REQUEST-CHANGES

The implementation has live parser/reader/validator coverage and stays inside the Fiscal bounded module, but it drifts from the locked `ACCOUNT_CHARGE` contract in nullable charge terms, the reserved references block, and forbidden nested `payments` keys.

## Findings

### REQUEST-CHANGES: `charge_terms` rejects spec-valid no-terms charges

Files/lines:

- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:753-763`
- `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeTermsDTO.php:10-25`
- Spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:237-239`

The spec defines `charge_terms.payment_terms_days` as nullable, `due_date` as nullable and required only when `payment_terms_days` is non-null, and `terms_label` as nullable. The validator and typed reader currently require all three to be present and non-null (`due_date` must be ISO date, `payment_terms_days` must be int, `terms_label` must be non-empty string). That rejects a valid customer with no configured payment terms, which is explicitly allowed by the contract and mirror model.

Smallest fix: make `AccountChargeTermsDTO` fields nullable where the spec says nullable; in `validateAccountChargeTerms()`, allow all three null, require `due_date` only when `payment_terms_days !== null`, require `payment_terms_days` to be non-negative int only when non-null, and allow `terms_label` null or non-empty string. Add a focused validator and reader test for `['payment_terms_days' => null, 'due_date' => null, 'terms_label' => null]`.

### REQUEST-CHANGES: `references` keyset and null invariants do not match v1

Files/lines:

- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:854-864`
- Test fixture currently accepts the wrong two-key shape at `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:433-436`
- Spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:248-252`

The spec locks three keys in `references`: `related_sale_receipt_event_id`, `server_customer_alias_id`, and `external_reference`. It also says `related_sale_receipt_event_id` is null in Phase 3 and `server_customer_alias_id` is null in v1 original events. The validator only accepts two keys, rejects a valid `server_customer_alias_id => null` as an extra key, and accepts non-null `related_sale_receipt_event_id`.

Smallest fix: change the expected reference keys to include `server_customer_alias_id`, allow `external_reference` null/non-empty string, and fail loud when either reserved v1 field is non-null. Update the populated references test to include all three keys with the two reserved fields null, then add negative tests for non-null `server_customer_alias_id` and non-null `related_sale_receipt_event_id`.

### REQUEST-CHANGES: forbidden `payments` is only rejected at top level

Files/lines:

- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:289-290`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:699-700`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1926-1932`
- Spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:262`

The spec says no `payments` key anywhere in an `ACCOUNT_CHARGE` payload. The current keyset check rejects top-level `payments`, and exact nested object validators reject most known-object extras, but `regime_extensions` is only checked as an associative object. A payload with `regime_extensions: {'payments': [...]}` would pass the current validator, silently carrying the forbidden payment concept inside an account charge.

Smallest fix: add a recursive `ACCOUNT_CHARGE` guard that rejects any associative key named `payments` before nested validation completes, or at least reject `regime_extensions.payments` explicitly. Add a negative test that places `payments` under `regime_extensions` and expects `payload_account_charge_payments_forbidden`.

### MINOR: warning ordering is not enforced even though it affects canonical bytes

Files/lines:

- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:790-798`
- Spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:232`

`credit_decision.warnings` is required to be a sorted array of stable warning codes, but the validator only checks list shape and non-empty string values. Because arrays are not key-sorted by canonical JSON object sorting, accepting unsorted warning arrays permits semantically identical warning sets to seal to different bytes.

Smallest fix: compare the list with a sorted copy using byte/string order and reject unsorted input with an explicit `payload_account_charge_credit_decision_invalid` message. Add one negative test.

### MINOR: `grand_total_before_charge` is not tied to `total`

Files/lines:

- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:804-811`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:928-945`
- Spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:215`

The spec says `grand_total_before_charge` is the same value as `total` in Phase 3. The validator checks money shape and amount-charged/charge-amount equality, but a mismatched `grand_total_before_charge` would pass. This can confuse printable/export consumers that treat it as a sale total.

Smallest fix: in `validateAccountChargeArithmetic()`, require `totals.grand_total_before_charge === totals.total` at currency scale and add a negative test.

## Axis Notes

- Contract drift: `AccountChargePayload::PAYLOAD_KEYS` is reused by the validator, which is good, but nested `charge_terms` and `references` drift from the spec.
- Fail-loud vs silent downgrade: `receipt_type_code` is fixed, top-level `payments` is rejected, and there is no fallback to `SALE_RECEIPT` or `ACCOUNT_PAYMENT`; nested forbidden payments still need closure.
- Dead-path rebuild: code has live callers via focused strict parser, validator, and canonical reader tests.
- Variant matrix: the named Task 2 variants are present, but fixtures miss nullable charge terms, full three-key references, unsorted warnings, and `grand_total_before_charge` mismatch.
- Cross-tenant FK safety: no database lookup or projection code was introduced in this task.
- D16 bounded-module guard: no Treasury/Customer/B2B/Accounting hard imports were added in the changed Fiscal files.
- CLAUDE.md rule 13: no production service-locator call was found; the only `resolve(` grep hit was a pre-existing-style doc-comment reference to `ParseFailureResolutionService::resolve()`.
- Skip hygiene: no new `markTestSkipped()` calls were added; the only skip hit I saw is an existing inline comment in the validator test.
- Arithmetic/regulatory: subtotal/VAT/discount/amount charged and VAT partition are covered; `grand_total_before_charge` and warning order need tighter validation.
- Test realism: focused green tests pass, but current fixtures encode the implementation's stricter/wrong shapes, so they would not catch the valid-null terms or full references contract failures.

## Evidence

Commands run or inspected:

- `git status --short`
- `git show --stat --oneline --decorate --find-renames 1c5d952cc`
- `git show --name-only --format=fuller 1c5d952cc`
- `sed -n '1,260p' CLAUDE.md`
- `sed -n '1,620p' docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`
- `sed -n '160,420p' docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`
- `sed -n '1,260p' docs/superpowers/reviews/2026-05-21-task-2-codex-review.md`
- `nl -ba apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php | sed -n '620,1040p'`
- `nl -ba apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php | sed -n '1,240p'`
- `nl -ba apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php | sed -n '1,260p'`
- `rg -n 'use App\\\\Modules\\\\(Treasury|Accounting|Document|Partner|Customer|Contact|B2B)|app\\(|App::make\\(|resolve\\(' apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php`
- `rg -n "markTestSkipped|@group|@requires|skipped|skip" apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php --filter account_charge` (OK: 19 tests, 58 assertions)
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/StrictCanonicalParserTest.php --filter account_charge` (OK: 1 test, 5 assertions)
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/CanonicalPayloadReaderTest.php --filter account_charge` (OK: 1 test, 8 assertions)
