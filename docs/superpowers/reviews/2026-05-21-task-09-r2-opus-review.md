# Task 09 R2 Opus-Style Second-Pass Review - Company-Scoped Allocation Actor

Date: 2026-05-21  
Reviewer: Opus-style adversarial pass via Codex  
R2 implementation commit: `6ef02b08b Phase 2.9.2: Scope allocation command actor by company`  
Original Task 09 implementation commit: `563cbb64e Phase 2.9.1: Add explicit allocation command`  
Prior Opus review: `docs/superpowers/reviews/2026-05-21-task-09-opus-review.md`  
R2 Codex self-review: `docs/superpowers/reviews/2026-05-21-task-09-r2-codex-review.md`  
Verdict: APPROVE

## Findings

No blockers found. No R2-introduced P1/P2 issues found.

## Axis Review

### 1. Active company membership is required

PASS. `PaymentAllocationService::resolveCommandActor()` still returns `null` for absent `actorUserId`, then loads the user by explicit command tenant and id before doing any membership check (`apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:371-384`). R2 adds the required `user_company_memberships` existence check on the resolved `user_id`, command `companyId`, and `MembershipStatus::Active` (`PaymentAllocationService.php:386-390`), returning the actor only when that row exists (`PaymentAllocationService.php:392`).

The enum predicate is safe: `MembershipStatus::Active` is a backed enum with value `active` (`apps/api/app/Modules/Company/Domain/Enums/MembershipStatus.php:7-12`), and the membership model casts `status` to that enum (`apps/api/app/Modules/Company/Domain/UserCompanyMembership.php:68-77`). The table stores `status` as a string with default `active` (`apps/api/database/migrations/2025_11_30_106000_create_user_company_memberships_table.php:46-48`).

### 2. No request-context fallback in command path

PASS. `applyAllocationFromCommand()` receives tenant/company/payment/actor through `ApplyPaymentAllocationCommand` and scopes the payment lookup to command tenant/company before use (`PaymentAllocationService.php:138-154`). The R2 actor resolver does not call `Auth::user()`, `Auth::id()`, `CompanyContext`, `app()`, `resolve()`, or `App::make()` (`PaymentAllocationService.php:371-392`).

`Auth::id()` remains only in the legacy web wrapper that builds an explicit command (`PaymentAllocationService.php:112-130`). That is outside the fiscal command path and does not reintroduce the prior fallback.

### 3. No silent substitution or new tenant/company leak

PASS. If the requested actor does not exist in the command tenant or lacks active membership in the command company, R2 returns `null`; it does not substitute the authenticated user, a company-context user, or any other actor (`PaymentAllocationService.php:371-392`). The downstream customer-advance GL paths still require `$actor instanceof User` before passing an actor into `createCustomerAdvanceJournalEntry()` (`PaymentAllocationService.php:280-290`, `PaymentAllocationService.php:307-318`), so the failed membership case degrades to the existing no-actor behavior rather than cross-company attribution.

The command path also keeps the payment anchor tenant/company-scoped before actor resolution (`PaymentAllocationService.php:140-154`), so a mismatched command company cannot reach allocation work through a different payment.

### 4. Test quality

PASS. The new test is not just a source-level assertion. It creates a real user, a real same-tenant other company, and a real active membership for only the other company; the command for the target company resolves to `null` (`apps/api/tests/Unit/Treasury/PaymentAllocationServiceTest.php:351-380`). It then inserts the target-company active membership and verifies the same command actor resolves to the expected `User` instance (`PaymentAllocationServiceTest.php:382-400`).

Residual test gap: the test invokes the private resolver via reflection (`PaymentAllocationServiceTest.php:421-427`) rather than driving the sales-order/excess-advance GL branch end-to-end. For this R2 scope, that is acceptable because the prior finding was specifically the resolver boundary, and the test uses real persistence rather than stubs or source scanning.

### 5. Retry/idempotency remains Task 10 scope

PASS. The unresolved retry/idempotency note remains correctly scoped outside Task 09. Prior Opus called it a P3 Task 10 hazard, not a Task 09 blocker (`docs/superpowers/reviews/2026-05-21-task-09-opus-review.md:24-28`). The R2 Codex self-review preserves that framing and explicitly assigns bridge retry idempotency to Task 10 (`docs/superpowers/reviews/2026-05-21-task-09-r2-codex-review.md:54-59`). R2 did not expand or weaken allocation idempotency semantics.

### 6. R2-introduced defects

PASS. I did not find PHPStan or typing regressions in the touched service/test files. The enum query binding is supported by Laravel's query builder, and the membership table/model shape matches the new predicate. No new service-locator pattern was introduced into the command path. The only `app()` usage in the touched test file is pre-existing test setup/service resolution (`PaymentAllocationServiceTest.php:48`, `PaymentAllocationServiceTest.php:100`).

## Verification

- `./vendor/bin/phpunit tests/Unit/Treasury/PaymentAllocationServiceTest.php --filter it_resolves_command_actor_only_when_user_has_active_company_membership` - PASS, 1 test / 3 assertions, with 11 PHPUnit deprecations.
- `./vendor/bin/phpunit tests/Unit/Treasury/PaymentAllocationServiceTest.php` - PASS, 11 tests / 48 assertions, with 11 PHPUnit deprecations.
- `./vendor/bin/phpstan analyse app/Modules/Treasury/Application/Services/PaymentAllocationService.php tests/Unit/Treasury/PaymentAllocationServiceTest.php --memory-limit=1G` - PASS, no errors.

## Residual Risks / Test Gaps

- No end-to-end test exercises `applyAllocationFromCommand()` through the customer-advance GL creation branch with a same-tenant non-member actor id. The resolver test covers the security boundary directly, but the GL branch remains indirectly covered by code review.
- The allocation command is still not independently idempotent against duplicate payment/document allocations. That remains a Task 10 bridge/projector retry concern, not a Task 09/R2 blocker.

## Verdict

APPROVE. R2 closes the prior P2 actor company-membership finding without adding request-context fallback, cross-company actor substitution, enum persistence risk, or a hidden service-locator dependency in the command path.
