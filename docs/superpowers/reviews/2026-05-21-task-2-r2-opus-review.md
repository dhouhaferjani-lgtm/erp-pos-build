# Task 2 R2 Opus Second-Pass Adversarial Review

Date: 2026-05-22
Branch: `feat/fiscal-phase-3-charge-to-account`
Reviewed commits: `1c5d952cc` + R2 `3813ffe69` (`Phase 3.2.2: Align account charge validator contract`)
Scope: R2 fix for Phase 3 Task 2 PHP `ACCOUNT_CHARGE` parser/validator/canonical reader contract.

## Verdict

REQUEST-CHANGES

R2 fixes the prior REQUEST-CHANGES items for nullable `charge_terms`, the three-key `references` block, recursive forbidden `payments`, and `grand_total_before_charge`. It also adds live focused coverage for those paths.

One contract gap remains: `credit_decision.warnings` is now sorted, but it is still not enforced as a stable-code list. The validator and positive tests still accept display phrases with spaces as warning values, which does not match the spec wording.

## Findings

### REQUEST-CHANGES: `credit_decision.warnings` still accepts display strings instead of stable warning codes

Files/lines:

- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:802-810`
- `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:393-397`
- `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:539-542`
- Spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:223-233`

R2 added deterministic ordering by sorting a copy and comparing it to the input. That closes the canonical-byte ordering problem, but the list still accepts any non-empty string. Current positive fixtures prove the gap: `balance snapshot stale` and `training over limit` pass as warning values even though the spec says `warnings` is a sorted array of stable warning codes.

Smallest fix: validate every warning value as a code, not display text. Use the local convention the tests already imply, for example lower snake-case code strings such as `balance_snapshot_stale`, `mirror_stale`, and `training_over_limit`; reject values containing whitespace or other display punctuation with `payload_account_charge_credit_decision_invalid`. Update the two positive fixtures to code values and add a negative test for an otherwise sorted display phrase such as `['balance snapshot stale']`.

## Re-Check Matrix

- APPROVE: nullable `charge_terms` now matches the spec shape. `payment_terms_days`, `due_date`, and `terms_label` are nullable in `AccountChargeTermsDTO`; the validator allows all-null terms and requires `due_date` when `payment_terms_days` is non-null.
- APPROVE: `references` now matches the spec keyset: `related_sale_receipt_event_id`, `server_customer_alias_id`, and `external_reference`. The two reserved v1 fields fail loud when non-null.
- APPROVE: PHP `ACCOUNT_CHARGE` validation now recursively rejects any key named `payments`, including under `regime_extensions` and inside nested arrays.
- REQUEST-CHANGES: `credit_decision.warnings` ordering is enforced, but stable-code value shape is not.
- APPROVE: `grand_total_before_charge` is now tied to `totals.total` in `validateAccountChargeArithmetic()`.
- APPROVE: R2 coverage proves the fixed nullable terms, reference reservations, nested `payments`, warning ordering, grand-total mismatch, and nullable reader paths. Add one more test for stable warning-code value shape as part of the request-change above.
- APPROVE: no R2 regression found for cross-tenant FK safety, fail-loud posture, dead-path rebuild, contract drift on the fixed fields, D16 imports, service locator usage, or skip hygiene.

## Axis Notes

- Cross-tenant FK safety: R2 did not add database lookup, FK projection, or tenant/company query logic.
- Fail-loud: improved for reserved references, nested `payments`, nullable terms, sorted warnings, and grand-total arithmetic. The warning-code value shape still needs fail-loud validation.
- Dead-path rebuild: focused validator and reader tests execute the R2 branches.
- Contract drift: fixed for `charge_terms`, `references`, forbidden `payments`, and `grand_total_before_charge`; remaining drift is warning code value shape.
- D16 bounded-module guard: no Treasury, Accounting, Document, Partner, Customer, Contact, or B2B imports were found in the reviewed Fiscal files.
- Service locator: no production `app()`, `App::make()`, or `resolve()` usage was found; the lone `resolve(` hit is a docblock reference to `ParseFailureResolutionService::resolve()`.
- Skip hygiene: no new runtime skips were found in the touched focused test files.

## Evidence

Commands run or inspected:

- `git status --short`
- `git log --oneline --decorate -8`
- `git show --stat --oneline --decorate 3813ffe69`
- `git show --name-only --format=short 3813ffe69`
- `git diff --stat 1c5d952cc 3813ffe69`
- `git diff --find-renames 1c5d952cc 3813ffe69 -- apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeTermsDTO.php`
- `git diff --find-renames 1c5d952cc 3813ffe69 -- apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php`
- `git show --check --oneline 3813ffe69`
- `git diff --check 1c5d952cc 3813ffe69`
- `sed -n '1,260p' docs/superpowers/reviews/2026-05-21-task-2-opus-review.md`
- `sed -n '1,260p' docs/superpowers/reviews/2026-05-21-task-2-r2-codex-review.md`
- `sed -n '1,280p' docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`
- `sed -n '267,420p' docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '198,270p'`
- `nl -ba apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php | sed -n '620,680p'`
- `nl -ba apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php | sed -n '680,1015p'`
- `nl -ba apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php | sed -n '1000,1035p'`
- `nl -ba apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeTermsDTO.php | sed -n '1,120p'`
- `nl -ba apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php | sed -n '250,520p'`
- `nl -ba apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php | sed -n '520,760p'`
- `nl -ba apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php | sed -n '1440,1575p'`
- `nl -ba apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php | sed -n '1,180p'`
- `nl -ba apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php | sed -n '80,155p'`
- `nl -ba apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php | sed -n '170,250p'`
- `rg -n "stable warning|warnings|warning codes|warning_code|stable codes|stable-code" docs apps/api/app apps/api/tests apps/pos/src | head -200`
- `rg -n "ACCOUNT_CHARGE|credit_decision|charge_terms|server_customer_alias_id|related_sale_receipt_event_id|payments" apps/pos/src/lib/fiscal apps/api/app/Modules/Fiscal apps/api/tests/Unit/Fiscal apps/api/tests/Feature/Fiscal | head -300`
- `rg -n -F "use App\\Modules\\Treasury" ...reviewed Fiscal files...` (no hits)
- `rg -n -F "use App\\Modules\\Accounting" ...reviewed Fiscal files...` (no hits)
- `rg -n -F "use App\\Modules\\Document" ...reviewed Fiscal files...` (no hits)
- `rg -n "app\\(|App::make\\(|resolve\\(" ...reviewed Fiscal files...` (docblock-only hit)
- `rg -n "markTestSkipped|@group|@requires|skipped|skip" apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php --filter account_charge` (OK: 26 tests, 78 assertions)
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/CanonicalPayloadReaderTest.php --filter account_charge` (OK: 2 tests, 11 assertions)
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/StrictCanonicalParserTest.php --filter account_charge` (OK: 1 test, 5 assertions)
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 ...touched files...` (blocked by sandbox TCP-listen EPERM before analysis)
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --debug --level=8 ...touched files...` (OK: no errors)
- `./vendor/bin/pint --test app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeTermsDTO.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php tests/Unit/Fiscal/CanonicalPayloadReaderTest.php` (PASS)
