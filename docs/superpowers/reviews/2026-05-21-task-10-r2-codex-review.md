# Task 10 R2 Codex Self-Adversarial Review — Treasury ACCOUNT_PAYMENT Bridge

Commits reviewed:

- `7e7883222` — implementation
- `e6b84d5f7` — cross-company alias R2 fix

Verdict: **APPROVE**

## R2 Fix Reviewed

R2 closes the Codex R1 BLOCKER:

- Pending-customer resolution first checks `(tenant_id, company_id, client_customer_uuid)`.
- If no same-company alias exists, it probes `(tenant_id, client_customer_uuid)`.
- A tenant-level alias owned by another company now throws `ProjectionInvariantViolationException` with `customer_alias_cross_company`, not retryable `ProjectionDependencyMissingException`.
- A new regression test covers that hard-fail path.

## Verification Evidence

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php` -> 13 tests / 51 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` -> 1120 tests / 3764 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/Domain/Exceptions/ProjectionInvariantViolationException.php app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php app/Modules/Treasury/Providers/TreasuryServiceProvider.php tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php` -> no errors.
- `./vendor/bin/pint --test ...touched files...` -> pass.
- `pnpm test` in `apps/pos` -> 162 files / 1445 tests.
- `pnpm typecheck` in `apps/pos` -> pass.
- `pnpm lint` in `apps/pos` -> 0 errors / 41 existing warnings.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh` -> pass.
- `.PASS_2B_PENDING` -> absent.

## Standing-Pattern Checks

- Cross-tenant/company FK safety: **PASS**. Customer, pending alias, payment method, repository, and actor reads carry tenant/company predicates; cross-company alias now hard-fails.
- Fail-loud vs silent downgrade: **PASS**. Missing customer/method/repository/account_id and allocation failure throw; allocation failure rolls back the Payment row.
- Dead-path rebuild: **PASS**. Bridge is registered in `TreasuryServiceProvider`; test asserts provider tag includes it.
- Idempotency: **PASS**. Retry short-circuits matching Payment rows and does not re-run allocation; conflicts throw. Existing `Advance` type after allocation is accepted.
- D16 bounded-modules guard: **PASS**. POS-core ACCOUNT_PAYMENT projector remains free of Treasury/Accounting/Partner/Customer/B2B imports and container helper calls; Treasury operational dependencies are isolated in the gated Treasury bridge.
- Constructor injection only: **PASS** for production code; no `app()` / `App::make()` / `resolve()`.
- Discriminated-union matrix: not applicable.
- Per-method skips / skip-citation accuracy: no new skips.

Residual risk: the bridge depends on `PaymentAllocationService` for downstream allocation idempotency after the Payment row exists. The bridge itself avoids duplicate allocation on projector retry by returning before invoking the allocator when the linked Payment already exists.
