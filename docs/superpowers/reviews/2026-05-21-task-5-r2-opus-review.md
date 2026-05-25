# Task 5 R2 Opus Adversarial Re-Review — Device ACCOUNT_CHARGE Authoring

Commits reviewed:

- `a41e9ddf0 Phase 3.5.1: Author account charges on device`
- `cc6f8477f Phase 3.5.2: Harden account charge authoring`

Prior reviews:

- Original Opus REQUEST-CHANGES: `docs/superpowers/reviews/2026-05-21-task-5-opus-review.md`
- R2 Codex self-review: `docs/superpowers/reviews/2026-05-21-task-5-r2-codex-review.md`

## Findings

### REQUEST-CHANGES: PHP ACCOUNT_CHARGE validator still rejects valid Task 4 R2 credit-offset payloads

The POS Task 5 authoring path and POS fiscal validator now use the Task 4 R2 standing balance rule: projected net balance is clamped at zero when projected credit exceeds projected receivable. That is visible in `apps/pos/src/lib/accountCharge/accountChargeService.ts:170-173` and `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1735-1737`.

The server validator still computes the expected value as raw subtraction at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:983-987`:

- `projected_net_balance_after expected = projected_receivable_balance_after - projected_credit_balance_after`
- no `max(..., 0)` clamp

Because the same server validator also requires money fields to be non-negative, no payload can satisfy this branch when an existing customer credit fully offsets receivable plus the new charge. Example: receivable `0.000`, credit `100.000`, charge `50.000`. The POS correctly emits projected receivable `50.000`, projected credit `100.000`, projected net `0.000`; PHP expects `-50.000` and rejects the event.

This blocks Task 5 clearance, not because the R2 POS code is wrong, but because the full Task 5 state still violates the required POS/PHP contract parity. It creates a local-seal/server-ingest failure for a valid account-charge case and breaks the fail-loud-before-append expectation.

Required fixes/tests:

- Update `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php` so `validateAccountChargeArithmetic()` computes `projected_net_balance_after = max(projected_receivable_balance_after - projected_credit_balance_after, 0)`.
- Update `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md` line-item invariant text to state the same clamp explicitly.
- Add PHP validator/parser coverage for a fully credit-offset `ACCOUNT_CHARGE` where projected credit exceeds projected receivable and projected net is `0.000`.
- Add the same fully offset case to POS authoring/validator tests; the existing POS offset test still has positive net (`550.000 - 100.000 = 450.000`) and does not catch this boundary.

## Original Findings Closure

The R2 POS patch closes the original Opus findings:

- Recursive `payments` rejection now walks nested objects and arrays, including `regime_extensions`, before append.
- Non-null `references.related_sale_receipt_event_id` is rejected for v1.
- `charge_terms.payment_terms_days` now requires a due date.
- Non-training `credit_decision.limit_exceeded=true` is rejected.
- Amount and balance arithmetic checks were added in the POS validator.
- `AttachedCheckoutCustomer.is_active` now passes through to the credit rules engine instead of being hardcoded active.
- Business customers now emit `invoice_classification='b2b_facture_draft_requested'`; non-business customers stay B2C, and POS validation rejects B2B classification for non-business customers.
- Printable data is materially richer and now carries sealed seller, terminal, shift, customer, VAT, credit, staleness, and training fields.

## R2 New-Defect Risk

No new blocking defect found in the R2 shared customer typing change:

- `AttachedCheckoutCustomer` was updated with `is_active`, and the direct customer attach and pending-create paths populate it.
- Pending-created customers are not accidentally authorized for account charges: they are active, but `charge_account_enabled=false`, `charge_policy_version=null`, `credit_limit=null`, and `balance_updated_at=null`, so the credit rules fail closed before append.
- Account-payment compatibility looks intact; the account-payment path treats `AttachedCheckoutCustomer` structurally and the R2 fixtures now include the new required field.
- Customer attach still checks tenant/company before storing the selected customer.

## Task 5 Plan Checks

Passing after R2:

- `authorAccountCharge()` requires a selected customer and rejects payment lines before resolving the fiscal engine.
- Credit rules run before `FiscalEventEngine.append()`.
- The payload builder emits the locked `ACCOUNT_CHARGE` top-level key set.
- Append uses `event_type='ACCOUNT_CHARGE'`, `source_event_class='account_charge'`, `source_event_id=accountChargeUuid`, and the built payload.
- I found no `receiptApi.createReceipt()` call or POS payment API call in the account-charge authoring service.
- Cross-tenant/company safety is enforced through the selected-customer scope checks in the credit rules engine before append.
- D16 bounded-module discipline holds for Task 5 POS code; no Treasury/Accounting/B2B server dependency was added to authoring.
- No production `app()`, `App::make()`, or `resolve()` use was introduced by these commits.
- No class-level skip was introduced by R2.

## Verification

Static adversarial review with targeted `git show`, `git diff`, `rg`, and line-level inspection of the POS authoring service, POS validator, PHP validator, payload types, plan/spec, and prior reviews. I did not rerun the POS or API test suites for this review file.

## Verdict

REQUEST-CHANGES

Fix the projected-net contract drift in the PHP validator/docs and add the fully credit-offset parity tests. After that, the R2 POS fixes are otherwise acceptable for Task 5.
