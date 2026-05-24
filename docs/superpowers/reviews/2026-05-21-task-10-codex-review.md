# Task 10 Codex Self-Adversarial Review — Treasury ACCOUNT_PAYMENT Bridge

Commit reviewed: `7e7883222` (`Phase 2.10.1: Add treasury account payment bridge`)

Verdict: **REQUEST-CHANGES**

## Scope Reviewed

- `TreasuryAccountPaymentBridge`
- `ProjectionInvariantViolationException`
- `TreasuryServiceProvider` projector registration
- `TreasuryAccountPaymentBridgeTest`

## Verification Evidence

- Focused red/green suite: `./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php` -> 12 tests / 49 assertions.
- PHPStan L8 over touched backend paths -> no errors.
- Pint touched backend paths -> pass after formatting.
- Full backend first run without `APP_KEY` exposed an environment precondition in existing POS signing-key tests.
- Full backend rerun with `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=` -> 1119 tests / 3762 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- POS `pnpm test` -> 162 files / 1445 tests.
- POS `pnpm typecheck` -> pass.
- POS `pnpm lint` -> 0 errors / 41 existing warnings.
- §14.3 chokepoint gate -> pass.
- `.PASS_2B_PENDING` sentinel -> absent.

## Findings

### BLOCKER-1 — Pending-customer cross-company alias is treated as retryable alias-missing

The bridge resolves pending customer UUIDs only by `(tenant_id, company_id, client_customer_uuid)`. If the same tenant already has that `client_customer_uuid` mapped in another company, the lookup returns null and the bridge throws `ProjectionDependencyMissingException`.

That is wrong for Phase 2:

- `pos_customer_aliases` has a tenant-level unique key on `(tenant_id, client_customer_uuid)`, so the alias cannot later appear for this company.
- Treating this as retryable causes wasted retries and eventual dead-letter instead of immediate hard operator-visible invariant failure.
- It weakens the cross-tenant/company FK safety posture from Phase 1 Task 21 R2 and Pass 2A.PHP.2 BLOCKER-1.

Required fix:

- Add a tenant-scope cross-company alias probe before throwing alias-missing.
- If found with a different `company_id`, throw `ProjectionInvariantViolationException` with a `customer_alias_cross_company` reason.
- Add a focused regression test.

## Standing-Pattern Checks

- Cross-tenant FK safety: **REQUEST-CHANGES** due BLOCKER-1. Synced customer, payment method, repository, and actor lookup are company-scoped.
- Fail-loud vs silent downgrade: **PASS** for missing method/repository/allocation; repository without `account_id` fails loud.
- Dead-path rebuild: **PASS** via service-provider tagged projector and provider registration test.
- Discriminated-union matrix: not applicable to this bridge.
- Contract drift: **PASS**, except BLOCKER-1 semantics gap against alias contract.
- Per-method `markTestSkipped`: no new skips.
- Skip-citation accuracy: no new skips.
- Constructor injection only: production bridge has no `app()` / `App::make()` / `resolve()`.
- D16 bounded-modules seam: POS-core projector still has no forbidden imports; Treasury operational imports remain inside the Treasury module bridge.
