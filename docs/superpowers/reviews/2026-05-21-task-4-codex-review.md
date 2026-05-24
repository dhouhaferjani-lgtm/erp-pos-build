# Phase 3 Task 4 Codex Self-Adversarial Review

## Verdict

APPROVE.

Implementation commits reviewed:

- `b559aedca Phase 3.4.1: Add account charge credit rules`
- `b6505c316 Phase 3.4.2: Complete credit rule matrix`

Task 4 adds a pure POS-side `evaluateAccountChargeCreditDecision()` function and a rule-matrix test suite for account-charge credit eligibility. It does not wire the rules engine into authoring yet; Task 5 owns the live caller.

## Verification Evidence

TDD red:

- `cd apps/pos && pnpm test -- creditRulesEngine.test.ts`
  - Failed because `../creditRulesEngine` did not exist.

Focused green:

- `cd apps/pos && pnpm test -- creditRulesEngine.test.ts`
  - 1 file passed, 13 tests passed after the matrix follow-up.

Full pre-commit gate:

- `cd apps/api && APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/`
  - 1173 tests, 3999 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- `cd apps/api && APP_KEY=... ./vendor/bin/phpstan analyse --level=8`
  - Default configured 512M run exhausted PHPStan memory. Re-run with `--memory-limit=1G` completed with no errors across 1857 files.
- `cd apps/api && ./vendor/bin/pint --test app/Modules/POS app/Modules/Fiscal tests/Feature/POS tests/Feature/Fiscal tests/Unit/Fiscal`
  - PASS.
- `cd apps/pos && pnpm test`
  - 166 files passed, 1474 tests passed.
- `cd apps/pos && pnpm typecheck && pnpm lint`
  - Exited 0. Lint reported the existing 41 warnings, 0 errors.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh`
  - PASS.

## Standing Pattern Review

### Discriminated-Union Matrix Completeness

APPROVE.

The test matrix covers every rejection code currently exported by `AccountChargeRejectionCode`:

- `customer_tenant_mismatch`
- `customer_company_mismatch`
- `customer_inactive`
- `charge_account_disabled`
- `charge_policy_missing`
- `credit_limit_exceeded`
- `balance_snapshot_missing`
- `balance_snapshot_invalid`
- `balance_snapshot_hard_stale`
- `customer_alias_ambiguous`
- `money_scale_invalid`

It also covers the approved path and a precision-sensitive `0.100 + 0.200 = 0.300` case that would expose JS floating point drift.

### Fail-Loud / Fail-Closed

APPROVE.

The engine rejects before approval on:

- tenant/company mismatch;
- inactive customer;
- disabled charge-account policy;
- missing policy version;
- missing or hard-stale balance snapshot;
- ambiguous pending-customer alias;
- null credit limit;
- invalid money scale;
- exceeded credit limit.

There is no silent fallback to enabled policy. This preserves the Task 3 R2 fail-closed posture.

### Money Precision

APPROVE.

Money parsing uses exact bigint minor units with a scale-specific regex. The rules engine does not use JavaScript floating point for money. Approval values are formatted back to the configured currency scale. Incorrect scale, including `119.00` under TND scale 3, is rejected as `money_scale_invalid`.

### Cross-Tenant / Customer Scope

APPROVE.

No database lookup or FK resolution was introduced. The pure function still checks input `tenant_id` and `company_id` against expected tenant/company before any credit decision, so Task 5 can fail fast on a mismatched selected customer.

### Dead-Path Rebuild

APPROVE-WITH-MINOR-EDIT.

Task 4 intentionally creates the pure rules engine before Task 5 wires it into account-charge authoring. This is a short-lived planned dead path, not an abandoned path. Task 5 must import and call this function before `FiscalEventEngine.append()`; the Task 5 review should treat missing live wiring as a blocker.

### Contract Drift

APPROVE.

The approved decision shape conforms to `AccountChargeCreditDecision` from the locked `AccountChargePayload` type: `decision: 'approved'`, `policy_version`, available-before/after fields, stale-policy action, warnings, and limit flag. Rejections remain outside the canonical payload as a local decision union and do not drift canonical bytes.

### D16 Bounded Modules

APPROVE.

The new POS file imports only the account-charge payload type. No Treasury, Accounting, B2B, Documents, or server module dependency was introduced.

### Rule 13

APPROVE.

No Laravel production code was touched and no `app()`, `App::make`, or `resolve()` usage was added.

### Skip Hygiene

APPROVE.

No skips were added.

## Residual Risk

Task 5 must wire this function into ACCOUNT_CHARGE authoring and must preserve the fail-closed behavior before append. It should also decide whether `balance_snapshot_invalid` needs a dedicated test in the larger authoring matrix or whether Task 4 should receive a small R2 coverage follow-up if Opus requests it.
