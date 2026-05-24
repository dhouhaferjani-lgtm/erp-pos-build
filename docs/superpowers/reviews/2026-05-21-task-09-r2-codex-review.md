# Task 09 R2 Codex Self-Adversarial Review — Company-Scoped Command Actor

Date: 2026-05-21  
Reviewer: Codex  
R2 implementation commit: `6ef02b08b Phase 2.9.2: Scope allocation command actor by company`  
Prior implementation commit: `563cbb64e Phase 2.9.1: Add explicit allocation command`  
Prior Opus review: `docs/superpowers/reviews/2026-05-21-task-09-opus-review.md`  
Verdict: APPROVE

## R2 Trigger

Opus found a P2 issue: `resolveCommandActor()` was tenant-scoped but not company-membership-scoped. In a multi-company tenant, a same-tenant user without membership in the command company could be accepted as the actor for customer-advance GL writes.

## Fix Reviewed

- `PaymentAllocationService::resolveCommandActor()` now:
  - returns null when `actorUserId` is absent,
  - loads the user by explicit command tenant and id,
  - requires an active `user_company_memberships` row for `$command->companyId`,
  - returns null instead of falling back to request auth when membership is absent.
- `PaymentAllocationServiceTest::it_resolves_command_actor_only_when_user_has_active_company_membership()` pins the same-tenant/different-company case and the active-membership success case.

## Adversarial Checks

### Cross-tenant / cross-company safety

Result: PASS.

- User lookup remains tenant-scoped.
- Company membership check is explicit on `user_id`, `company_id`, and `status = active`.
- A same-tenant actor with membership in another company resolves to null.

### Fail-loud vs silent downgrade

Result: PASS.

- The command boundary does not invent a replacement actor.
- For actorless commands, existing behavior remains explicit null actor. That is acceptable for fiscal replay because the bridge can pass null when sealed operator resolution fails.

### Hidden request context

Result: PASS.

- R2 did not introduce `Auth::user()`, `app()`, `App::make()`, `resolve()`, or `CompanyContext` use into `applyAllocationFromCommand()`.

### R2 regression risk

Result: PASS.

- Existing no-actor command path still passes.
- Existing web wrapper still passes actor id explicitly, but the command will now ignore it unless the authenticated user has an active membership in the current company.
- The R2 test uses real `UserCompanyMembership` rows rather than stubbing the check.

### Residual Opus P3 retry/idempotency note

Result: ACCEPTED FOR TASK 10.

- `PaymentAllocationService` preserves current controller semantics and is not itself idempotent by payment/document.
- Task 10 must keep bridge retry idempotency at the projector/payment boundary before invoking allocation, as already required by the Task 10 plan.

## Verification

- Focused R2 command tests: PASS, `4` tests / `10` assertions.
- Backend full Fiscal/POS plus allocation gate after R2: PASS, `1120` tests / `3773` assertions / `107` skipped / `2` incomplete / `28` deprecations.
- Backend PHPStan L8 over Treasury/Fiscal/POS touched scope: PASS, `353` files.
- Focused PHPStan on DTO/service/test: PASS.
- Pint touched files: PASS.
- POS `pnpm test`: PASS, `162` files / `1445` tests.
- POS `pnpm typecheck`: PASS.
- POS `pnpm lint`: PASS with `41` pre-existing warnings, `0` errors.
- §14.3 chokepoint gate + `.PASS_2B_PENDING` absence: PASS.

## Verdict

APPROVE. The R2 fix closes Opus's actor company-membership finding without adding new request-context or cross-company leakage.
