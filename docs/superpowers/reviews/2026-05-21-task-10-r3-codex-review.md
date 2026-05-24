# Task 10 R3 Codex Self-Adversarial Review - Treasury ACCOUNT_PAYMENT Bridge

Commits reviewed:

- `7e7883222` - initial Treasury ACCOUNT_PAYMENT bridge
- `e6b84d5f7` - R2 cross-company customer alias hard-fail
- `b3bf64752` - R3 idempotency and repository account hardening

Verdict: **APPROVE**

## R3 Fix Reviewed

R3 closes both Opus second-pass P1 findings:

- `existingPaymentForEvent()` now queries by `fiscal_event_id` alone, so a non-POS payment row linked to the immutable fiscal event cannot be ignored.
- `assertExistingPaymentMatches()` now includes `origin = pos` in the idempotency comparison.
- A seeded `origin = web_admin` payment for the same fiscal event now throws `idempotency_conflict`, creates no second `Payment`, and does not call allocation.
- `resolveRepository()` now verifies the repository `account_id` exists as an active `accounts` row in the same `(tenant_id, company_id)` before GL-posting allocation can run.
- A repository whose `account_id` points to another company now throws `payment_repository_account_not_found`, creates no `Payment`, and does not call allocation.

## Verification Evidence

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php` -> 15 tests / 59 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` -> 1122 tests / 3772 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/Domain/Exceptions/ProjectionInvariantViolationException.php app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php app/Modules/Treasury/Providers/TreasuryServiceProvider.php tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php` -> no errors.
- `./vendor/bin/pint --test ...touched files...` -> pass.
- `pnpm test` in `apps/pos` -> 162 files / 1445 tests.
- `pnpm typecheck` in `apps/pos` -> pass.
- `pnpm lint` in `apps/pos` -> 0 errors / 41 existing warnings.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh` -> pass.
- `.PASS_2B_PENDING` -> absent.

## Standing-Pattern Checks

- Cross-tenant/company FK safety: **PASS**. Customer alias, synced customer, payment method, payment repository, repository account, and actor lookups are scoped to the fiscal event tenant/company. The new repository account check closes the GL account cross-company path.
- Fail-loud vs silent downgrade: **PASS**. Missing or mis-scoped customer/method/repository/account and idempotency conflicts throw typed projection exceptions; allocation failure rolls back the Payment row.
- Dead-path rebuild: **PASS**. Bridge is registered through `TreasuryServiceProvider`, tagged as a fiscal projector, and covered by provider registration tests.
- Idempotency: **PASS**. Any existing row with the same `fiscal_event_id` is considered. Matching POS rows short-circuit before allocation; non-POS or mismatched rows hard-fail.
- D16 bounded-modules guard: **PASS**. Treasury and Accounting dependencies remain isolated to the Treasury bridge. POS-core ACCOUNT_PAYMENT projection remains independent of Treasury activation.
- Constructor injection only: **PASS**. Production code uses constructor injection and model queries; no `app()`, `App::make()`, or `resolve()` helper calls.
- Discriminated-union matrix: not applicable to this bridge.
- Contract drift: **PASS**. The bridge behavior now matches the Task 10 plan language that existing fiscal-event payments must not double-allocate and conflicts must fail loud.
- Per-method skips / skip-citation accuracy: **PASS**. No new skips were introduced.

Residual risk: the account existence guard verifies the GL target before invoking `PaymentAllocationService`; deeper journal-line invariants still belong to Accounting/Treasury services and remain covered by existing allocation tests.
