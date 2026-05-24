# Task 5 R3 Opus Adversarial Re-Review — Account Charge Net Balance Contract

Commits reviewed:

- `a41e9ddf0 Phase 3.5.1: Author account charges on device`
- `cc6f8477f Phase 3.5.2: Harden account charge authoring`
- `8cb3da6b7 Phase 3.5.3: Align account charge net balance contract`

Prior reviews:

- `docs/superpowers/reviews/2026-05-21-task-5-opus-review.md`
- `docs/superpowers/reviews/2026-05-21-task-5-r2-opus-review.md`
- `docs/superpowers/reviews/2026-05-21-task-5-r3-codex-review.md`

Scope:

- Task 5 from `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`.
- Spec contract in `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`.
- R3 diff in PHP validator/tests, POS service/validator tests, and spec text.

## Findings

No BLOCKER, REQUEST-CHANGES, IMPORTANT, or MINOR findings found in the R3 state.

## R2 Blocker Closure

The R2 blocker is closed. The PHP validator now computes `projected_net_balance_after` using the same clamped contract as POS:

- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:983-990` computes `projected_receivable_balance_after - projected_credit_balance_after` and clamps negative results to zero.
- `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1727-1741` already uses the same `max(projectedReceivable - projectedCredit, 0)` rule.
- `apps/pos/src/lib/accountCharge/accountChargeService.ts:167-181` emits the same clamped snapshot during device authoring.
- `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:270` now documents the clamp explicitly.

The new fully credit-offset tests cover the exact failure mode from R2:

- PHP accepts receivable `0.000`, credit `100.000`, charge `50.000`, projected net `0.000` in `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:350-388`.
- POS authoring emits the same fully-offset values in `apps/pos/src/lib/accountCharge/__tests__/accountChargeService.test.ts:264-295`.
- POS append validation seals the same fully-offset payload in `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1568-1612`.

I did not find a new R3 drift between PHP, POS, spec text, or canonical parity. The canonical golden fixture remains unchanged, and `accountChargeCanonicalParity.test.ts` still passes against the locked bytes.

## Original Task 5 Findings Closure

- Recursive payments rejection: closed. PHP rejects nested `payments` via `rejectAccountChargePaymentsKeyRecursively()` and POS mirrors it before append, including `regime_extensions`.
- Split-sale reference rejection: closed. Both validators reject non-null `references.related_sale_receipt_event_id` for v1.
- Terms due-date rule: closed. POS validator rejects `payment_terms_days` without `due_date`; PHP has corresponding coverage.
- `limit_exceeded` rule: closed. Non-training `credit_decision.limit_exceeded=true` is rejected; training remains accepted by the PHP matrix.
- Arithmetic checks: closed. Amount, receivable, and projected-net checks exist on both sides, including the R3 clamped-net boundary.
- Inactive customer pass-through: closed. `AttachedCheckoutCustomer.is_active` is passed into the credit rules engine, and inactive customers fail before engine resolution.
- B2B classification: closed. Business customers author `b2b_facture_draft_requested`; non-business B2B classification is rejected by POS/PHP validators.
- Printable richness: closed for Task 5. The printable mapper now carries seller identity, terminal/shift, customer/account fields, VAT rows, credit data, staleness, and training marker from sealed payload data.

## Append Path And Regression Checks

Task 5 append path remains within the intended fiscal-event route:

- `authorAccountCharge()` rejects payment lines before resolving the fiscal engine.
- It appends `event_type='ACCOUNT_CHARGE'`, `source_event_class='account_charge'`, and `source_event_id=accountChargeUuid` through `FiscalEventEngine.append()`.
- I found no `receiptApi.createReceipt()`, POS payment API, `ReceiptPaymentService`, or `createPOSPaymentEntry()` dependency in the account-charge authoring path.
- Receipt and payment API references found by search are pre-existing neighboring tests/services, not new Task 5 account-charge regressions.

Cross-tenant/company and fail-loud behavior remain acceptable:

- `buildCreditDecision()` supplies selected customer tenant/company plus expected tenant/company to `evaluateAccountChargeCreditDecision()`.
- Rejected credit policy states, inactive customers, and selected-customer tenant/company mismatch fail before engine resolution/append.
- Payload contract violations fail inside POS append validation before event mutation.

D16 bounded-module discipline holds for Task 5:

- R3 did not add Treasury, Accounting, Document/B2B, or service-location dependencies.
- No production `app()`, `App::make()`, or container `resolve()` usage was introduced by the reviewed commits.
- No new skipped Task 5 test was added. The existing `describe.skip` fallback in `FiscalEventEngine.test.ts` is the pre-existing node-SQLite availability gate.

## Verification

Static review used targeted `git show`, `rg`, and line-level inspection of the reviewed commits, prior reviews, Task 5 plan/spec, PHP validator, POS validator, authoring service, printable mapper, and tests.

Focused commands run:

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit --filter 'account_charge_(accepts_fully_credit_offset_projected_net|rejects_amount_balance_mismatch|rejects_limit_exceeded_production_event|rejects_split_sale_reference)' tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` — passed, 3 tests, 8 assertions. The split-sale pattern did not match the actual test name.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit --filter 'account_charge_(rejects_nested_payments_key|rejects_reserved_related_sale_receipt_reference|accepts_fully_credit_offset_projected_net|accepts_training_limit_exceeded_but_rejects_production_limit_exceeded)' tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` — passed, 4 tests, 13 assertions.
- `pnpm test -- accountChargeService.test.ts accountChargePrintable.test.ts FiscalEventEngine.test.ts accountChargeCanonicalParity.test.ts` from `apps/pos` — passed, 4 files, 93 tests.

## Verdict

APPROVE
