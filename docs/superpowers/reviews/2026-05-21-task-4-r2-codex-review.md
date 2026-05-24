# Phase 3 Task 4 R2 Codex Self-Adversarial Review

## Verdict

APPROVE.

Implementation commits reviewed:

- `b559aedca Phase 3.4.1: Add account charge credit rules`
- `b6505c316 Phase 3.4.2: Complete credit rule matrix`
- `f97b88c07 Phase 3.4.3: Harden charge credit edge cases`

R2 addresses the Opus `REQUEST-CHANGES` findings in `docs/superpowers/reviews/2026-05-21-task-4-opus-review.md`.

## R2 Change Summary

- Reject invalid authoring clocks (`input.now`) as `balance_snapshot_invalid`.
- Reject future `balance_updated_at` as `balance_snapshot_invalid`.
- Compute post-charge exposure from projected balances:
  - `projectedReceivableAfter = receivableBefore + chargeAmount`
  - `projectedNetAfter = max(projectedReceivableAfter - creditBefore, 0)`
  - `creditAvailableAfter = creditLimit - projectedNetAfter`
- Added regression coverage for future timestamps, invalid `now`, existing credit-balance offset, scale 2 money, and scale 0 money.

## Verification Evidence

Focused R2 checks:

- `cd apps/pos && pnpm test -- creditRulesEngine.test.ts`
  - 1 file passed, 18 tests passed.
- `cd apps/pos && pnpm typecheck`
  - Exited 0.
- `cd apps/pos && pnpm exec eslint src/lib/accountCharge/creditRulesEngine.ts src/lib/accountCharge/__tests__/creditRulesEngine.test.ts`
  - Exited 0.

Full R2 gate:

- `cd apps/api && APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/`
  - 1173 tests, 3999 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- `cd apps/api && APP_KEY=... ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G`
  - 1857 files, no errors.
- `cd apps/api && ./vendor/bin/pint --test app/Modules/POS app/Modules/Fiscal tests/Feature/POS tests/Feature/Fiscal tests/Unit/Fiscal`
  - PASS.
- `cd apps/pos && pnpm test`
  - 166 files passed, 1480 tests passed.
- `cd apps/pos && pnpm typecheck && pnpm lint`
  - Exited 0. Lint reported the existing 41 warnings, 0 errors.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh`
  - PASS.

## Opus Finding Closure

### Finding 1: Future Balance Timestamps Treated As Fresh

APPROVE.

The engine now rejects both invalid `now` and future `balance_updated_at` before stale-age calculation. The matrix covers both cases as `balance_snapshot_invalid`. This closes the fail-closed gap where a bad local mirror timestamp could bypass stale-balance enforcement.

### Finding 2: Existing Credit Balance Did Not Offset The New Charge

APPROVE.

The engine now computes exposure from projected balances. A customer with `0.000` receivable, `100.000` credit, `500.000` limit, and `550.000` charge is approved with `50.000` available after the charge. This matches the documented receivable-minus-credit net-balance semantics and avoids blocking legitimate charges for customers with advance credit.

## Standing Pattern Review

### Discriminated-Union Matrix Completeness

APPROVE.

Every exported rejection code remains covered:

- `customer_tenant_mismatch`
- `customer_company_mismatch`
- `customer_inactive`
- `charge_account_disabled`
- `charge_policy_missing`
- `balance_snapshot_missing`
- `balance_snapshot_invalid`
- `balance_snapshot_hard_stale`
- `customer_alias_ambiguous`
- `money_scale_invalid`
- `credit_limit_exceeded`

Positive coverage now includes TND scale 3, EUR-style scale 2, zero-decimal scale 0, exact minor-unit precision, and credit-balance offset behavior.

### Fail-Loud / Fail-Closed

APPROVE.

Missing policy, disabled charge account, missing/null credit limit, stale/missing/invalid/future balance snapshot, tenant/company mismatch, inactive customer, ambiguous pending alias, malformed money, and exceeded credit limit all reject before approval. The approval branch does not silently coerce missing policy.

### Money Precision

APPROVE.

All money decisions use scale-specific regex parsing into bigint minor units. No JS floating point arithmetic is used for money. The R2 tests cover scale 0, 2, and 3.

### Cross-Tenant / Scope

APPROVE.

The function remains pure and checks expected tenant/company before any approval. No DB or FK lookup was introduced.

### Dead Path

APPROVE-WITH-MINOR-EDIT.

This is still intentionally unwired until Task 5. Task 5 must call this engine before `FiscalEventEngine.append()`; if it does not, Task 5 should be blocked.

### Contract Drift

APPROVE.

Approved decisions still conform to `AccountChargeCreditDecision`. Rejection details remain local and do not enter canonical ACCOUNT_CHARGE payload bytes.

### D16 / Rule 13

APPROVE.

No Treasury, Accounting, B2B, Documents, or Laravel container dependency was added.

## Residual Risk

Task 5 must keep local balance snapshot math aligned with the R2 projected-exposure math, especially for customers with existing credit balances. The authoring tests should include the credit-offset case so the payload snapshot and decision agree.
