# Opus Second-Pass Review — Phase 3 Task 4 Account Charge Credit Rules Engine

## Verdict

REQUEST-CHANGES.

Reviewed commits:

- `b559aedca Phase 3.4.1: Add account charge credit rules`
- `b6505c316 Phase 3.4.2: Complete credit rule matrix`

Task 4 is close, but it is not cleared yet. The pure engine and discriminated-union shape are broadly right, and the current focused test suite passes. Two edge cases need fixes before this becomes the policy gate that Task 5 wires before `FiscalEventEngine.append()`.

## Findings

### 1. Future `balance_updated_at` snapshots are treated as fresh and can bypass hard-stale rejection

Severity: Important.

Evidence:

- [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:102) parses `balance_updated_at`.
- [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:107) rejects only when `minutesBetween(now, balanceUpdatedAt) > hard_stale_after_minutes`.
- [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:71) computes a negative age for future timestamps.
- [creditRulesEngine.test.ts](../../../apps/pos/src/lib/accountCharge/__tests__/creditRulesEngine.test.ts:31) has stale, missing, and malformed timestamp cases, but no future timestamp case.

Why this matters:

A balance mirror timestamp in the future produces a negative age, so the engine proceeds toward approval. That is not fail-closed for freshness. A stale local balance can be made to look non-stale by a bad or tampered future `balance_updated_at`, and Task 5 will rely on this function as the pre-append blocker.

Required fix:

- After parsing `balance_updated_at`, reject `balanceUpdatedAt.getTime() > input.now.getTime()` as `balance_snapshot_invalid` or another typed rejection code.
- Add a test like `balance_updated_at: '2026-05-21T12:01:00.000Z'` with `now: 2026-05-21T12:00:00.000Z`.
- Consider rejecting invalid `input.now` as `balance_snapshot_invalid` as well, because `NaN > threshold` also falls through today.

### 2. Credit-limit math overstates exposure when existing customer credit exceeds receivables

Severity: Important.

Evidence:

- [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:130) clamps `netBefore` to zero.
- [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:133) then adds the full charge amount to that clamped net.
- Phase 3 spec says the projected receivable becomes `receivable_balance_before + charge_amount`, and projected net is projected receivable minus projected credit: [spec](../specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:269).
- Partner balance semantics define customer net as receivable minus credit: [Partner.php](../../../apps/api/app/Modules/Partner/Domain/Partner.php:260).

Why this matters:

If a customer has `receivable_balance=0.000`, `credit_balance=100.000`, `credit_limit=500.000`, and `charge_amount=550.000`, the spec-equivalent projected net is `450.000`, so the charge is within limit. The current engine computes `netBefore=0.000`, then `projectedNetAfter=550.000`, and rejects as `credit_limit_exceeded`. That is fail-closed, but it is not the documented credit rule and will incorrectly block legitimate charges for customers with advance/credit balances.

Required fix:

- Compute projected exposure from the projected balances, not from clamped pre-charge exposure:
  - `projectedReceivableAfter = receivableBefore + chargeAmount`
  - `projectedNetAfter = max(projectedReceivableAfter - creditBefore, 0n)`
  - `creditAvailableBefore = creditLimit - max(receivableBefore - creditBefore, 0n)`
  - `creditAvailableAfter = creditLimit - projectedNetAfter`
- Add a regression test where credit balance offsets part or all of the new charge.

## Axes Review

Discriminated union: Pass with one caveat. Every currently exported `AccountChargeRejectionCode` is covered in the test matrix, and the approved path plus precision case are present.

Money precision: Implementation uses bigint minor units, not JS floating-point money math. Scale-specific parsing exists for scale `0`, `2`, and `3`, but tests only exercise scale `3`; add scale `0` and `2` cases while touching the suite for the findings above.

Fail-loud / fail-closed: Pass for null policy, disabled charge account, null credit limit, missing/malformed/stale balance, tenant/company mismatch, inactive customer, ambiguous alias, malformed money, and exceeded limit. Future timestamps are the fail-closed gap.

Cross-tenant safety: Pass. Tenant and company checks happen before policy, money, and approval, and no DB/FK lookup was introduced.

Dead path: Acceptable for Task 4. The function is intentionally pure and unwired until Task 5. Task 5 must call it before any `ACCOUNT_CHARGE` append; missing live wiring should be a Task 5 blocker.

Contract drift: Approved decisions match `AccountChargeCreditDecision`; rejections stay outside the canonical payload. The balance-math issue can still cause authoring/decision drift once Task 5 builds `local_balance_snapshot`.

D16 bounded modules: Pass. The new POS file imports only the account-charge payload type and does not pull Treasury, Accounting, B2B, or Documents dependencies.

CLAUDE rule 13: Pass for the reviewed commits. No production `app()`, `App::make()`, or Laravel `resolve()` usage was added.

Payment-line rejection: Not implemented in Task 4's pure function. Given the current task split, this is acceptable only if Task 5's authoring path rejects any `payments`/payment line before append.

## Codex Self-Review Check

The self-review is accurate on the narrow matrix and scoped architecture, but it missed the future timestamp and credit-balance-offset cases.

Verification I ran:

- `cd apps/pos && pnpm test -- creditRulesEngine.test.ts` — 1 file passed, 13 tests passed.
- `cd apps/pos && pnpm typecheck` — passed.
- `cd apps/pos && pnpm exec eslint src/lib/accountCharge/creditRulesEngine.ts src/lib/accountCharge/__tests__/creditRulesEngine.test.ts` — passed.
- `cd apps/api && APP_KEY=... ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php` — normal parallel mode could not bind `127.0.0.1:0` in this sandbox (`EPERM`); retrying with `--debug` single-process mode completed with no errors.

I did not independently reproduce the self-review's full 1857-file PHPStan run or full PHP/POS suite in this sandbox, but the targeted checks above do not contradict the stated evidence.

## Required Follow-Up

Fix the two findings, add the regression tests, and rerun the focused POS suite plus POS typecheck/lint. After that, Task 4 can be cleared and Task 5 can treat this engine as the pre-append policy gate.
