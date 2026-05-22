# Task 2 Codex Self-Adversarial Review

Date: 2026-05-21
Branch: `feat/fiscal-phase-3-charge-to-account`
Reviewed commit: `1c5d952cc` (`Phase 3.2.1: Validate account charge canonical payloads`)
Scope: Phase 3 Task 2, PHP parser/validator/canonical reader for `ACCOUNT_CHARGE`.

## Verdict

APPROVE

## Evidence

TDD red run before implementation:

- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php --filter account_charge`
  - Failed as expected: 19 `event_type_unimplemented:ACCOUNT_CHARGE` failures.
- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Unit/Fiscal/StrictCanonicalParserTest.php --filter account_charge`
  - Failed as expected: strict parser reported unimplemented `ACCOUNT_CHARGE`.
- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Unit/Fiscal/CanonicalPayloadReaderTest.php --filter account_charge`
  - Failed as expected: `CanonicalPayloadReader::forAccountCharge()` was undefined.

Green verification after implementation:

- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php --filter account_charge`
  - OK: 19 tests, 58 assertions.
- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Unit/Fiscal/StrictCanonicalParserTest.php --filter account_charge`
  - OK: 1 test, 5 assertions.
- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Unit/Fiscal/CanonicalPayloadReaderTest.php --filter account_charge`
  - OK: 1 test, 8 assertions.
- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/`
  - OK: 1163 tests, 3967 assertions, 107 skipped, 2 incomplete, 16 PHPUnit deprecations.
- `APP_KEY=base64:... ./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php tests/Unit/Fiscal/StrictCanonicalParserTest.php tests/Unit/Fiscal/CanonicalPayloadReaderTest.php`
  - OK: no errors.
- `./vendor/bin/pint --test app/Modules/Fiscal tests/Feature/Fiscal tests/Unit/Fiscal`
  - PASS after formatting `CanonicalPayloadReader.php`.
- `cd apps/pos && pnpm test`
  - OK: 165 test files, 1459 tests.
- `cd apps/pos && pnpm typecheck && pnpm lint`
  - OK: typecheck and lint exited 0; lint retained 41 warnings, 0 errors.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh`
  - PASS: manifest receiver-type validator and §14.3 chokepoint gate passed.

## Attack Vectors Reviewed

### Contract Drift

`ACCOUNT_CHARGE` now uses the shared `AccountChargePayload::PAYLOAD_KEYS` registry in `FiscalPayloadConstraintValidator::PAYLOAD_KEYS`, so the validator keyset is tied to the Task 1 DTO contract. The strict parser accepts an `ACCOUNT_CHARGE` envelope through the existing registry path, and the new reader uses `AccountChargePayload::fromArray()`.

Risk reviewed: `StrictCanonicalParser.php` itself was not edited. That is intentional because Task 1 already registered `ACCOUNT_CHARGE` in `FiscalEventPayloadRegistry`, and Task 2's live parser test proves the parser path now accepts the event type through that registry.

### Fail-Loud Behavior

The validator throws `RuntimeException` with explicit, searchable prefixes for invalid nested objects, forbidden `payments`, malformed money/date/UUID fields, VAT partition mismatch, arithmetic mismatch, invalid staleness, limit-exceeded production charges, and B2B classification mismatch. There is no fallback to sale receipt semantics and no silent downgrade.

### Discriminated-Union / Variant Completeness

Round-1 tests cover the required ACCOUNT_CHARGE variants:

- synced and pending customer mirrors
- stale and fresh mirror snapshots
- credit limit present and absent
- transaction and line discounts present and absent
- nullable and populated buyer/references
- nullable non-collected subtype
- production versus training `limit_exceeded`
- B2B buyer tax-number acceptance and non-business classification rejection

### Cross-Tenant FK Safety

No database FK lookup or projection is introduced in this task. The new code validates payload shape and builds canonical read DTOs only. Cross-tenant FK scoping remains a later projection concern.

### Dead-Path Rebuild

The implementation has live callers through:

- `StrictCanonicalParserTest::test_strict_parser_accepts_account_charge_canonical_envelope()`
- `CanonicalPayloadReaderTest::test_for_account_charge_returns_typed_view()`
- validator focused ACCOUNT_CHARGE tests

The parser path is not dead code: it exercises the existing strict parse service with `FiscalEventType::ACCOUNT_CHARGE`.

### D16 / Bounded Modules

No Treasury, Customer, B2B, Accounting, or service-locator imports were added. New production files stay inside Fiscal DTO/services. This preserves the Phase 3 D16 seam for later projector/bridge tasks.

### Constructor Injection / Service Locator Guard

No production `app()`, `App::make`, or `resolve()` usage was added. The only `resolve` grep hits are existing method names in parse-failure services and controller code.

### Skip Hygiene

No new `markTestSkipped()` calls were added. The existing skip-reference grep hit is a pre-existing inline comment in `FiscalPayloadConstraintValidatorTest.php`.

### R2 Defect Pattern

No R2 fix was needed before this review. If Opus finds a blocker/request-change, the follow-up commit will get a fresh Codex R2 self-review rather than reusing this approval.

## Residual Risks

The new `AccountChargeView` intentionally keeps line items and VAT breakdown as raw validated arrays instead of reusing sale-receipt DTOs because the Phase 3 line item shape includes `line_uuid` and permits a nullable/string `tax_category_code`. This is documented by the constructor types and covered by the reader test, but a future printable/export task may still introduce a dedicated line-item DTO if it needs typed access to those nested rows.
