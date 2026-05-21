# Task 09 Opus-Style Second-Pass Review — Explicit Payment Allocation Command

Date: 2026-05-21  
Reviewer: Opus-style adversarial pass via Codex  
Implementation commit: `563cbb64e Phase 2.9.1: Add explicit allocation command`  
Codex self-review: `docs/superpowers/reviews/2026-05-21-task-09-codex-review.md`  
Verdict: APPROVE-WITH-MINOR-EDITS

## Scope Reviewed

- `apps/api/app/Modules/Treasury/Application/DTOs/ApplyPaymentAllocationCommand.php`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php`
- `apps/api/tests/Unit/Treasury/PaymentAllocationServiceTest.php`
- `docs/superpowers/reviews/2026-05-21-task-09-codex-review.md`

## Findings

### P2 — Actor resolution is tenant-scoped, but not company-membership-scoped

`PaymentAllocationService::resolveCommandActor()` resolves by `users.tenant_id` and `users.id` only (`apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:369-378`). In a tenant with multiple companies, a same-tenant user who is not a member of `$command->companyId` will still be accepted as the command actor. That actor is then passed into customer-advance journal creation for sales-order allocations and excess advances (`PaymentAllocationService.php:279-287`, `PaymentAllocationService.php:305-316`).

The immediate Task 09 command path no longer falls back to `Auth::user()`, so this is not a blocker. However, Task 10's bridge instructions say the cashier/operator should be resolved by tenant/company scope where possible. If the bridge accidentally passes a same-tenant stale user id, the command boundary will not catch it. Recommended minor edit: either make `resolveCommandActor()` require an active `user_company_memberships` row for `$command->companyId`, or document the command's trust boundary and add a Task 10 bridge test that proves the bridge never passes a non-member actor.

### P3 — Retry/idempotency remains a Task 10 hazard

`applyAllocationFromCommand()` creates new `PaymentAllocation` rows for every preview allocation without checking for existing allocations on the same payment/document (`PaymentAllocationService.php:161-176`). That preserves current controller behavior, but the Phase 2 spec's bridge section says allocation retry must not duplicate allocations. If the Task 10 bridge is retried after a payment was already allocated, a blind second call to this command can double-allocate unless the bridge detects and skips an already-projected payment before invoking the command.

This is not a Task 09 blocker because the plan for Task 09 only required the explicit command DTO/refactor. It should be pinned in Task 10 with an idempotency test around `ACCOUNT_PAYMENT` projection retry.

## Axis Review

### 1. Hidden request context

PASS for the new command path. `applyAllocationFromCommand()` receives tenant/company/payment/method/actor/source through `ApplyPaymentAllocationCommand` (`ApplyPaymentAllocationCommand.php:14-22`) and does not call `CompanyContext`, `Auth::user()`, `app()`, `App::make()`, or `resolve()`.

`CurrencyScaleResolver` remains request-context-capable when called without a currency (`CurrencyScaleResolver.php:35-45`), but the touched command path now passes `$payment->currency` into every scale use inside `PaymentAllocationService` (`PaymentAllocationService.php:218`, `PaymentAllocationService.php:253-260`, `PaymentAllocationService.php:279`, `PaymentAllocationService.php:312`). I do not see a remaining hidden `CompanyContext` dependency in the command path.

### 2. Tenant/company scoping

PASS with one schema caveat. Payment lookup is scoped by command tenant and company before `findOrFail()` (`PaymentAllocationService.php:138-142`). Preview reads use explicit tenant/company (`PaymentAllocationService.php:144-151`). Open-document reads include tenant, company, and partner (`PaymentAllocationService.php:393-411`). Manual allocation document reads include tenant and company (`PaymentAllocationService.php:551-560`). Transactional document locks include tenant and company (`PaymentAllocationService.php:164-168`), and the later GL classification document read is also tenant/company-scoped (`PaymentAllocationService.php:246-249`).

`PaymentAllocation` itself has no tenant/company columns in the current schema (`2025_11_30_120000_create_treasury_tables.php:171-180`) and model fillable (`PaymentAllocation.php:32-37`), so allocation write scoping is indirect through the already-scoped payment/document ids (`PaymentAllocationService.php:171-176`). That matches the current table design, but it means there is no row-local tenant/company assertion on the allocation record.

### 3. Actor behavior

PASS for removing request auth from the command path. `Auth::id()` remains only in the backward-compatible web wrapper (`PaymentAllocationService.php:115-128`), which is allowed by the Task 09 brief. The command method resolves only the explicit `actorUserId` and returns null when absent (`PaymentAllocationService.php:369-378`); it does not fall back to request auth.

The company-membership weakness is the only actor concern, documented above.

### 4. Controller compatibility

PASS. Existing `applyAllocation(string $paymentId, AllocationMethod $allocationMethod, ?array $manualAllocations = null)` still exists (`PaymentAllocationService.php:110-129`) and delegates to the new command method with tenant/company from `CompanyContext` and current `Auth::id()`.

### 5. Fail-loud behavior

PASS for the reviewed Task 09 cases. Cross-company payment ids fail at the scoped payment lookup (`PaymentAllocationService.php:138-142`). Cross-company document ids in manual allocations or preview-produced allocations fail via scoped `findOrFail()` (`PaymentAllocationService.php:164-168`, `PaymentAllocationService.php:557-560`). Company lookup for tolerance-country resolution is tenant-scoped (`PaymentAllocationService.php:447-449`).

### 6. Test coverage and Task 10 residual gaps

The new tests cover command allocation with explicit tenant/company and no actor (`PaymentAllocationServiceTest.php:262-291`), cross-company payment rejection (`PaymentAllocationServiceTest.php:293-335`), and a static guard against `Auth::user(` (`PaymentAllocationServiceTest.php:337-344`).

Residual gaps to carry forward:

- No test runs `applyAllocationFromCommand()` with `CompanyContext` unset or set to a different company, so the no-hidden-context property is mostly code-reviewed rather than regression-pinned.
- No same-tenant/different-company actor membership test.
- No manual allocation cross-company document test.
- No command retry/idempotency test for duplicate allocation prevention.
- No Task 10-shaped excess/customer-advance test proving behavior when `actorUserId` is null versus resolved.

## Verdict

No blockers found. The implementation satisfies the core Task 09 requirement: fiscal replay has an explicit `ApplyPaymentAllocationCommand` path that scopes the payment and document reads by command tenant/company and does not depend on `Auth::user()` or request `CompanyContext`. I recommend minor follow-up before or during Task 10 for company-scoped actor resolution and replay idempotency coverage.
