# Task 09 Codex Self-Adversarial Review — Explicit Payment Allocation Command

Date: 2026-05-21  
Reviewer: Codex  
Implementation commit: `563cbb64e Phase 2.9.1: Add explicit allocation command`  
Verdict: APPROVE

## Scope Reviewed

- Added `ApplyPaymentAllocationCommand` DTO for fiscal replay / non-request allocation calls.
- Added `PaymentAllocationService::applyAllocationFromCommand()`.
- Kept `applyAllocation()` as the controller-compatible wrapper.
- Refactored allocation preview internals so command execution uses explicit tenant/company context.
- Removed `Auth::user()` from the allocation service; advance GL entries use an explicitly resolved actor when supplied.
- Added command-path tests for explicit tenant/company allocation, cross-company payment rejection, and no `Auth::user()` usage.

## Adversarial Checks

### Explicit replay context / no request-context dependency

Result: PASS.

- `applyAllocationFromCommand()` scopes `Payment` by `$command->tenantId` and `$command->companyId`.
- The preview helper now accepts tenant/company explicitly instead of reading `CompanyContext`.
- `Document` reads during allocation and manual preview include both `tenant_id` and `company_id`.
- Company lookup for tolerance-country resolution is tenant-scoped.
- During self-review I found that currency scale still flowed through the request-bound resolver with no currency argument; fixed before commit by passing `$payment->currency` to `scale()`.

### `Auth::user()` removal / actor handling

Result: PASS.

- No `Auth::user()` or `auth()->user()` call remains in `PaymentAllocationService`.
- The wrapper `applyAllocation()` may still read `Auth::id()` to preserve web API behavior and pass an explicit actor id into the command.
- The command path resolves the actor by tenant and id; missing/unresolvable actors produce no advance-user side effect rather than falling back to request auth.

### Cross-tenant / cross-company safety

Result: PASS.

- Cross-company payment ids are rejected by the scoped payment lookup.
- Open document queries and lock-for-update reads carry both tenant and company predicates.
- The new test `it_apply_allocation_from_command_rejects_cross_company_payment` locks the payment lookup behavior.

### Fail-loud vs silent downgrade

Result: PASS.

- A payment outside the explicit context raises `ModelNotFoundException`; it is not silently re-scoped or allocated.
- Document lookup inside the transaction still uses `findOrFail()` after tenant/company predicates.
- The command method does not infer tenant/company from current request state.

### Controller compatibility

Result: PASS.

- Existing `applyAllocation()` signature is unchanged.
- It delegates to `applyAllocationFromCommand()` with `CompanyContext` tenant/company and current user id.
- Existing allocation, tolerance, and Treasury event suites passed after the refactor.

### D16 / bounded modules

Result: PASS.

- This task stays inside Treasury application service/DTO code.
- It does not add Fiscal/POS dependencies into Treasury allocation internals.
- It prepares the later Treasury projector bridge to call Treasury through a command API rather than request/Auth state.

### Contract drift / R2-defect pattern

Result: PASS.

- The implementation matches the plan DTO shape, including `source` and optional manual allocations.
- `source` is stored on the command for future bridge traceability; no behavior is currently keyed on it.
- The replay-context scale issue was caught before commit and verified after the fix.

## Verification

- TDD red: command tests initially failed because `applyAllocationFromCommand()` did not exist.
- Focused command tests: PASS, `3` tests / `7` assertions.
- Full allocation service test file: PASS, `10` tests / `45` assertions.
- Broadened Treasury allocation/events suite: PASS, `94` tests / `291` assertions.
- Backend full Fiscal/POS plus allocation gate after final fix: PASS, `1119` tests / `3770` assertions / `107` skipped / `2` incomplete / `27` deprecations.
- Backend PHPStan L8 over Treasury/Fiscal/POS touched scope: PASS, `353` files.
- Focused PHPStan on DTO/service/test: PASS.
- Pint touched files: PASS.
- POS `pnpm test`: PASS, `162` files / `1445` tests.
- POS `pnpm typecheck`: PASS.
- POS `pnpm lint`: PASS with `41` pre-existing warnings, `0` errors.
- §14.3 chokepoint gate + `.PASS_2B_PENDING` absence: PASS.
- `git diff --check`: PASS.

## Verdict

APPROVE. The command API is explicit-context, scoped by tenant/company, free of `Auth::user()`, and preserves existing web allocation behavior.
