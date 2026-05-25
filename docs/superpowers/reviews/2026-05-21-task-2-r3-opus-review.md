# Task 2 R3 Opus Second-Pass Adversarial Review

Date: 2026-05-22
Branch: `feat/fiscal-phase-3-charge-to-account`
Reviewed commits: `1c5d952cc` + R2 `3813ffe69` + R3 `5bebd24b3` (`Phase 3.2.3: Enforce account charge warning codes`)
Scope: R3 fix for the remaining Task 2 warning-code REQUEST-CHANGES, plus regression check against the prior approved R2 fixes and standing patterns.

## Verdict

APPROVE

Task 2 is cleared after R3.

R3 fixes the remaining warning-code contract gap. `credit_decision.warnings` is now validated as a sorted list of stable code-shaped strings, positive fixtures use lower-snake-case code values, and a focused negative test rejects display text. I found no new R3 regression in the previously approved nullable `charge_terms`, `references`, recursive `payments`, or `grand_total_before_charge` fixes.

## Findings

No findings.

## Re-Check Matrix

- APPROVE: `credit_decision.warnings` remains list-shaped and sorted by string comparison. The new per-item predicate runs before the existing sort check and rejects non-code strings at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:802-813`.
- APPROVE: positive fixtures now use code values: `balance_snapshot_stale` at `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:393-397` and `training_over_limit` at `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:546-555`.
- APPROVE: display text is covered by a negative test at `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:453-461`, asserting the `payload_account_charge_credit_decision_invalid` prefix for `balance snapshot stale`.
- APPROVE: the regex `^[a-z][a-z0-9_]*$` is compatible with the spec's "sorted array of stable warning codes" requirement at `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:223-233`. The spec does not define a closed warning-code enum or require uppercase/kebab/dotted code formats, and the local examples already use lower snake case.
- APPROVE: nullable `charge_terms` is still intact. `payment_terms_days`, `due_date`, and `terms_label` are nullable in the canonical DTO, and the validator only requires `due_date` when `payment_terms_days` is non-null.
- APPROVE: `references` is still the three-key v1 shape, with fail-loud rejection for non-null `related_sale_receipt_event_id` and `server_customer_alias_id`.
- APPROVE: recursive `payments` rejection is still active before nested validation and rejects any key named `payments` anywhere under the ACCOUNT_CHARGE payload.
- APPROVE: `grand_total_before_charge` is still compared to `totals.total` in account-charge arithmetic.
- APPROVE: no R3 standing-pattern regression found for cross-tenant FK safety, fail-loud posture, dead-path coverage, D16 imports, service locator usage, or skip hygiene.

## Axis Notes

- Cross-tenant FK safety: R3 adds only payload string validation and tests; it adds no tenant/company lookup, FK projection, or persistence behavior.
- Fail-loud: improved for warning display text via `payload_account_charge_credit_decision_invalid:warnings[...]`.
- Dead-path rebuild: the new negative test exercises the new validator branch.
- Contract drift: R3 aligns the remaining warning-code gap with the spec. It intentionally validates code shape, not a closed enum, which is appropriate because the spec does not enumerate allowed warning codes.
- D16 bounded-module guard: no Treasury, Accounting, Document, Partner, Customer, Contact, or B2B imports were found in the reviewed Fiscal files.
- Service locator: no production `app()`, `App::make()`, or service-locator `resolve()` usage was added; observed hits are existing comments/docblocks.
- Skip hygiene: no runtime skips were added in the focused reviewed tests.

## Evidence

Commands run or inspected:

- `git status --short`
- `git show --stat --oneline --decorate --no-renames 3813ffe69..5bebd24b3`
- `git diff --name-only --no-renames 3813ffe69..5bebd24b3`
- `sed -n '1,260p' docs/superpowers/reviews/2026-05-21-task-2-r2-opus-review.md`
- `sed -n '1,260p' docs/superpowers/reviews/2026-05-21-task-2-r3-codex-review.md`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '198,270p'`
- `nl -ba docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md | sed -n '267,420p'`
- `git diff --find-renames 3813ffe69..5bebd24b3 -- apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php`
- `nl -ba apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php | sed -n '760,835p'`
- `nl -ba apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php | sed -n '360,470p'`
- `nl -ba apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php | sed -n '520,565p'`
- `rg -n "warnings|warning codes|warning_code|balance_snapshot_stale|training_over_limit|mirror_stale|lower_snake|lower snake|stable warning" docs apps/api apps/pos packages | head -300`
- `nl -ba apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts | sed -n '90,125p'`
- `nl -ba apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts | sed -n '175,205p'`
- `nl -ba apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeCreditDecisionDTO.php | sed -n '1,80p'`
- `rg -n "warnings" apps/pos/src/lib apps/api/app/Modules/Fiscal apps/api/tests/Feature/Fiscal apps/api/tests/Unit/Fiscal | head -100`
- `git diff --check 3813ffe69..5bebd24b3`
- `git show --check --oneline 5bebd24b3`
- `nl -ba apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php | sed -n '500,770p'`
- `nl -ba apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php | sed -n '820,1035p'`
- `git diff --stat 1c5d952cc..5bebd24b3 -- apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeTermsDTO.php apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`
- `nl -ba apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeTermsDTO.php | sed -n '1,100p'`
- `rg -n -F "use App\\Modules\\Treasury" apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountCharge*.php apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`
- `rg -n -F "use App\\Modules\\Accounting" apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountCharge*.php apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`
- `rg -n "app\\(|App::make\\(|resolve\\(|markTestSkipped|@group|@requires|skipped|skip" apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountCharge*.php apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`
- `for module in Treasury Accounting Document Partner Customer Contact B2B; do rg -n -F "use App\\Modules\\$module" apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountCharge*.php apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php || true; done`
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php --filter account_charge` (OK: 27 tests, 81 assertions)
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` (blocked before analysis by sandbox TCP-listen EPERM)
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --debug --level=8 app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` (OK: no errors)
- `./vendor/bin/pint --test app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` (PASS)
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/CanonicalPayloadReaderTest.php --filter account_charge` (OK: 2 tests, 11 assertions)
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/StrictCanonicalParserTest.php --filter account_charge` (OK: 1 test, 5 assertions)
