# Phase 3 Stage B Implementation Plan R3 Codex Review

Reviewed artifact: `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`

Prior Opus R2 review: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-r2-opus-review.md`

Locked spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`

Verdict: APPROVE

## R3 Fix Verification

Opus R2 found that Task 8 still made Partner balance refresh optional with "if current patterns support it", while the locked spec requires Partner balance refresh/reconciliation for Treasury-active B2C account charges.

The plan now makes that outcome mandatory in the GL path:

- Task 7 adds `test_pos_charge_refreshes_partner_receivable_balance()`.
- Task 7 requires `GeneralLedgerService::createPOSChargeEntry()` to call `PartnerBalanceService::refreshPartnerBalance($companyId, $partnerId)` through its existing constructor dependency after persisting the journal entry.
- Task 7 states refresh failure must fail `createPOSChargeEntry()` and bubble through the Treasury bridge projection.
- Task 8 adds `test_bridge_bubbles_partner_balance_refresh_failure()`.
- Task 8 says the bridge relies on `GeneralLedgerService::createPOSChargeEntry()` to refresh the cached Partner balance before projection success, and must not catch or downgrade refresh failures.

This aligns the plan with the locked spec outcome: for Treasury-active B2C, printable projection, AR posting, and Partner balance refresh/reconciliation all complete, or the projection fails loud and can be retried.

## Additional R3 Hygiene

The file map no longer uses the vague "where needed" phrase for checkout integration. It now names the checkout state and customer attach paths that Task 5 may touch, and limits `accountPaymentService.ts` changes to shared customer snapshot helper extraction if Task 5 needs it.

## Standing Pattern Review

- Cross-tenant FK safety: mandatory balance refresh uses the existing tenant/company-scoped `PartnerBalanceService::refreshPartnerBalance()` path, and the bridge remains scoped by `tenant_id`, `company_id`, and customer identity.
- Fail-loud over silent downgrade: balance refresh is no longer best-effort; refresh failure fails the GL call and bridge projection.
- Dead-path rebuild: new tests exercise the mandatory refresh behavior in both the GL service and bridge projection paths.
- Contract drift: Treasury-active full-flow behavior now matches the spec acceptance criterion that Partner balance refresh/reconciliation runs.
- Constructor injection: the plan requires the existing `GeneralLedgerService` constructor dependency, not service-location helpers.
- D16 bounded modules: no new POS-core dependency was introduced by the R3 fix; refresh remains inside Accounting/Treasury bridge paths.

## Verification Commands

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.phase-3`:

```bash
rg -n "Partner balance|balance refresh|receivable|PartnerBalance|Partner.*balance|Treasury-active|FIFO|allocation|ACCOUNT_CHARGE" docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md
rg -n "PartnerBalanceUpdated|balance.*partner|partner.*balance|refresh.*balance|receivable_balance|CustomerReceivable|createPOS.*Entry|source_type.*pos" apps/api/app/Modules apps/api/tests -g '*.php'
rg -n "current patterns support|if current patterns|PartnerBalanceService|refreshPartnerBalance|partner receivable|balance refresh|test_pos_charge_refreshes|test_bridge_bubbles" docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-r2-opus-review.md
rg -n "TBD|TODO|placeholder|\\.\\.\\.|similar to|appropriate|Use the actual|if .* before implementation|maybe|where needed|current patterns support|class-level|markTestSkipped" docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md
rg -n "PartnerBalanceService::refreshPartnerBalance|test_pos_charge_refreshes_partner_receivable_balance|test_bridge_bubbles_partner_balance_refresh_failure|not catch and downgrade|mandatory, not best-effort|CustomerAttachPanel|accountPaymentService" docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md
sed -n '792,932p' docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md
```

The placeholder/optional wording scan returned no matches. No runtime test suite was run because this is a docs-only plan review.
