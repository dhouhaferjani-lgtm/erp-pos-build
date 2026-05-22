# Task 2 R2 Codex Self-Adversarial Review

Date: 2026-05-22
Branch: `feat/fiscal-phase-3-charge-to-account`
Reviewed commits: `1c5d952cc` + `3813ffe69`
Scope: R2 fixes for Phase 3 Task 2 Opus REQUEST-CHANGES.

## Verdict

APPROVE

## R2 Findings Addressed

Opus found five issues in `docs/superpowers/reviews/2026-05-21-task-2-opus-review.md`.

- Nullable `charge_terms`: fixed. Validator now allows null `payment_terms_days`, `due_date`, and `terms_label`, while requiring `due_date` when `payment_terms_days` is non-null. `AccountChargeTermsDTO` now exposes nullable typed fields. Added validator and reader coverage.
- `references` keyset and v1 reserved fields: fixed. Validator now expects `external_reference`, `related_sale_receipt_event_id`, and `server_customer_alias_id`; the latter two must be null in original v1 events. Added positive and negative coverage.
- Nested forbidden `payments`: fixed. Validator now recursively rejects any key named `payments` anywhere in `ACCOUNT_CHARGE`, including under `regime_extensions`. Added negative coverage.
- Sorted `credit_decision.warnings`: fixed. Validator rejects unsorted warning-code arrays. Added negative coverage.
- `grand_total_before_charge`: fixed. Validator now requires it to equal `totals.total`; discounted fixture updated and mismatch coverage added.

## Evidence

Focused R2 checks:

- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php --filter account_charge`
  - OK: 26 tests, 78 assertions.
- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Unit/Fiscal/CanonicalPayloadReaderTest.php --filter account_charge`
  - OK: 2 tests, 11 assertions.
- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Unit/Fiscal/StrictCanonicalParserTest.php --filter account_charge`
  - OK: 1 test, 5 assertions.

Full verification after R2:

- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/`
  - OK: 1171 tests, 3990 assertions, 107 skipped, 2 incomplete, 16 PHPUnit deprecations.
- `APP_KEY=base64:... ./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php tests/Unit/Fiscal/StrictCanonicalParserTest.php tests/Unit/Fiscal/CanonicalPayloadReaderTest.php`
  - OK: no errors.
- `./vendor/bin/pint --test app/Modules/Fiscal tests/Feature/Fiscal tests/Unit/Fiscal`
  - PASS.
- `cd apps/pos && pnpm test`
  - OK: 165 test files, 1459 tests.
- `cd apps/pos && pnpm typecheck && pnpm lint`
  - OK exit 0; lint reports 41 existing warnings and 0 errors.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh`
  - PASS.

## Standing Pattern Review

- Cross-tenant FK safety: still not applicable. R2 did not add DB reads, FK lookup, or projection code.
- Fail-loud: improved. R2 adds explicit forbidden-reference, forbidden-payments, warning-order, terms, and amount-mismatch failures instead of permitting ambiguous payloads.
- Dead-path rebuild: R2 adds live tests for every fixed branch.
- Discriminated/variant completeness: R2 closes the holes Opus found in nullable terms, full references, nested payments, warning ordering, and grand-total arithmetic.
- Contract drift: R2 re-aligns the validator and typed reader with spec §7-§8.
- D16 bounded modules: no Treasury/Customer/B2B/Accounting imports added.
- CLAUDE.md rule 13: no production `app()`, `App::make()`, or `resolve()` added.
- Skip hygiene: no new skips.

## Residual Risk

`AccountChargeTermsDTO::fromArray()` follows the existing nullable-field DTO pattern and treats a missing nullable key as null. The strict validator enforces exact nested keys before canonical reader use in ingest/resolve paths. If a future caller bypasses validation and constructs a reader view from a hand-built model, it can still accept missing nullable term keys as null, which matches current optional DTO behavior elsewhere but is worth keeping in mind for future stricter DTO work.
