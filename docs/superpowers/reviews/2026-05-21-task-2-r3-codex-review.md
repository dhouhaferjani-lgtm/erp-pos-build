# Task 2 R3 Codex Self-Adversarial Review

Date: 2026-05-22
Branch: `feat/fiscal-phase-3-charge-to-account`
Reviewed commits: `1c5d952cc` + `3813ffe69` + `5bebd24b3`
Scope: R3 fix for the remaining Task 2 warning-code REQUEST-CHANGES.

## Verdict

APPROVE

## R3 Fix

Opus R2 found that `credit_decision.warnings` was sorted but still accepted display strings. R3 now validates each warning as a stable lower-snake-case code via `^[a-z][a-z0-9_]*$`, before the existing sorted-order check.

Positive fixtures now use `balance_snapshot_stale` and `training_over_limit`. A new negative test rejects the display string `balance snapshot stale` with the `payload_account_charge_credit_decision_invalid` prefix.

## Evidence

Focused R3 checks:

- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php --filter account_charge`
  - OK: 27 tests, 81 assertions.
- `APP_KEY=base64:... ./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php`
  - OK: no errors.
- `./vendor/bin/pint --test app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php`
  - PASS.

Full verification after R3:

- `APP_KEY=base64:... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/`
  - OK: 1172 tests, 3993 assertions, 107 skipped, 2 incomplete, 16 PHPUnit deprecations.
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

- Cross-tenant FK safety: no FK lookup or projection code added.
- Fail-loud: improved. Display warning text now fails with an explicit ACCOUNT_CHARGE credit-decision prefix.
- Dead-path rebuild: new negative test exercises the warning-code validation.
- Contract drift: R3 closes the remaining spec drift for sorted stable warning codes.
- D16 bounded modules: no new imports outside Fiscal.
- Constructor injection / service locator: no `app()`, `App::make()`, or `resolve()` added.
- Skip hygiene: no new skips.
- R2/R3 defect pattern: R3 is deliberately narrow and reverified with full gates.

## Residual Risk

The regex validates stable code shape, not a closed enum of allowed warning codes. The spec does not define a closed warning-code list; it only requires sorted stable codes. A closed enum can be introduced later when the POS authoring policy defines all warning sources.
