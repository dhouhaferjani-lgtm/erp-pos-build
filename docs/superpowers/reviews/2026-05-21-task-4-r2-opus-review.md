# Opus R2 Adversarial Review — Phase 3 Task 4 Account Charge Credit Rules Engine

## Verdict

APPROVE.

Reviewed commits:

- `b559aedca Phase 3.4.1: Add account charge credit rules`
- `b6505c316 Phase 3.4.2: Complete credit rule matrix`
- `f97b88c07 Phase 3.4.3: Harden charge credit edge cases`

Task 4 is cleared after R2. The R2 patch closes both original `REQUEST-CHANGES` findings without introducing a new blocking fail-open path in the pure POS credit rules engine.

## Findings

No blocking or important findings.

## Original Finding Closure

### 1. Future balance timestamps and invalid authoring clocks

APPROVE.

The engine now rejects invalid `input.now` before any other rule evaluation at [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:78), rejects malformed `balance_updated_at` at [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:106), and rejects future balance snapshots before stale-age math at [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:111).

The regression matrix covers both R2 cases:

- Future `balance_updated_at` rejects as `balance_snapshot_invalid`: [creditRulesEngine.test.ts](../../../apps/pos/src/lib/accountCharge/__tests__/creditRulesEngine.test.ts:40).
- Invalid `now` rejects as `balance_snapshot_invalid`: [creditRulesEngine.test.ts](../../../apps/pos/src/lib/accountCharge/__tests__/creditRulesEngine.test.ts:41).

This closes the original fail-open where a future mirror timestamp produced a negative age and bypassed hard-stale rejection.

### 2. Existing credit balance offsets the new charge

APPROVE.

R2 now computes exposure from projected balances:

- `netBefore = max(receivableBefore - creditBefore, 0)`: [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:139).
- `projectedReceivableAfter = receivableBefore + chargeAmount`: [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:141).
- `projectedNetAfter = max(projectedReceivableAfter - creditBefore, 0)`: [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:142).
- `creditAvailableAfter = creditLimit - projectedNetAfter`: [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:145).

That matches the Phase 3 rule that credit-limit enforcement is based on projected net balance after charge: [phase3 spec](../specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:120), [phase3 spec](../specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:269), and the existing Partner customer balance semantics of receivable minus credit: [Partner.php](../../../apps/api/app/Modules/Partner/Domain/Partner.php:260).

The regression at [creditRulesEngine.test.ts](../../../apps/pos/src/lib/accountCharge/__tests__/creditRulesEngine.test.ts:103) proves the original counterexample is now approved: `0.000` receivable, `100.000` credit, `500.000` limit, and `550.000` new charge yields `50.000` available after the charge.

## Review Axes

Discriminated union coverage: APPROVE. Every exported `AccountChargeRejectionCode` is covered in the focused matrix at [creditRulesEngine.test.ts](../../../apps/pos/src/lib/accountCharge/__tests__/creditRulesEngine.test.ts:31): tenant mismatch, company mismatch, inactive customer, disabled charge account, missing policy, missing/invalid/future/hard-stale balance snapshot, ambiguous alias, malformed money scale, and credit-limit exceeded.

Money precision: APPROVE. Money parsing is regex-to-`bigint` minor units at [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:49), formatting is `bigint`-based at [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:61), and the engine does not use JS floating-point money math. R2 adds scale 2 coverage at [creditRulesEngine.test.ts](../../../apps/pos/src/lib/accountCharge/__tests__/creditRulesEngine.test.ts:121) and scale 0 coverage at [creditRulesEngine.test.ts](../../../apps/pos/src/lib/accountCharge/__tests__/creditRulesEngine.test.ts:140). Negative and malformed decimal strings fail the same anchored regex path as the existing malformed-money case.

Fail-closed paths: APPROVE. Invalid/future timestamps reject before stale-age math, hard-stale still rejects only when age exceeds the configured threshold, malformed money rejects before approval, existing credit over receivable no longer overstates exposure, and credit-limit excess still rejects at [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:147). The hard-stale boundary remains consistent with the spec wording "exceeds" because the comparison is `>` at [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:115).

Tenant/company scope: APPROVE. The pure engine checks expected tenant and company before approval at [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:82), and R2 introduced no DB lookup, FK shortcut, or cross-scope query.

D16 dependency guard: APPROVE. The Task 4/R2 diff touches only `apps/pos/src/lib/accountCharge/*` and imports only the ACCOUNT_CHARGE payload type at [creditRulesEngine.ts](../../../apps/pos/src/lib/accountCharge/creditRulesEngine.ts:1). It does not import Treasury, Accounting, B2B, Documents, or Laravel operational services.

Rule 13: APPROVE. R2 is TypeScript-only POS code and adds no Laravel container resolution, `app()`, `App::make()`, or `resolve()` usage.

Task 4 dead-path status: APPROVE. This remains an intentionally unwired pure engine as assigned by Task 4: [phase3 plan](../plans/2026-05-21-pos-charge-to-account-phase3.md:525). Task 5 is responsible for calling it before `FiscalEventEngine.append()` and for strict ACCOUNT_CHARGE payload rejection, including `payments` and non-string money fields: [phase3 plan](../plans/2026-05-21-pos-charge-to-account-phase3.md:647), [phase3 plan](../plans/2026-05-21-pos-charge-to-account-phase3.md:656). Missing live wiring should be reviewed as a Task 5 blocker, not a Task 4 defect.

Payments block / canonical payload scope: APPROVE for Task 4. The Phase 3 spec intentionally forbids an ACCOUNT_CHARGE payments block: [phase3 spec](../specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:166). Backend validation already rejects `payments` recursively at [FiscalPayloadConstraintValidator.php](../../../apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1006). Task 4's pure decision result does not author payload bytes, so no Rule 13 or D16 coupling is introduced here.

## R2 Self-Review Evidence Check

The R2 self-review accurately describes the code changes and the focused POS verification I reran:

- `cd apps/pos && pnpm test -- creditRulesEngine.test.ts` — 1 file passed, 18 tests passed.
- `cd apps/pos && pnpm typecheck` — exited 0.
- `cd apps/pos && pnpm exec eslint src/lib/accountCharge/creditRulesEngine.ts src/lib/accountCharge/__tests__/creditRulesEngine.test.ts` — exited 0.

I did not rerun the self-review's full API/POS gate in this pass. The focused evidence is plausible and matches the R2 diff; nothing in the reviewed commits contradicts the reported broader gate.

## Residual Task 5 Watchpoints

Task 5 must keep authoring payload math aligned with this R2 engine, especially for customers with existing credit balances. The ACCOUNT_CHARGE payload builder should include a credit-offset case so `local_balance_snapshot.projected_*` values and `credit_decision.credit_available_*` explain the same decision.

Task 5 must also wire this engine before `FiscalEventEngine.append()` and continue rejecting any `payments` key or non-string money in the strict payload path. Those are not Task 4 blockers.
