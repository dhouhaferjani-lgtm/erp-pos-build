# Task 06 Codex Self-Adversarial Review — Customer Search/Create/Attach UX

**Commit reviewed:** `dc1b5819b Phase 2.6.1: Add POS customer attach flow`  
**Reviewer:** Codex first-pass adversarial review  
**Verdict:** APPROVE

## Scope Reviewed

- `apps/pos/src/components/customers/CustomerAttachPanel.tsx`
- `apps/pos/src/components/customers/CustomerSearchInput.tsx`
- `apps/pos/src/components/customers/CustomerBalanceBadge.tsx`
- `apps/pos/src/components/customers/customerAttachUtils.ts`
- `apps/pos/src/stores/paymentStore.ts`
- `apps/pos/src/pages/HomePage.tsx`
- New component/store tests beside the changed code.

## Attack Vectors Checked

1. **Cross-tenant/company safety:** APPROVE.
   - `CustomerSearchInput` calls `searchCustomers()` with both `tenant_id` and `company_id`; repository tests already prove wrong-tenant/company rows are excluded.
   - `CustomerAttachPanel` re-checks selected row scope before writing to `paymentStore`, so a forged row from a future caller cannot attach silently.
   - Pending customer creation writes `pending_customer_outbox` with explicit `tenant_id` and `company_id`.

2. **Fail-loud vs silent downgrade:** APPROVE.
   - Missing tenant/company surfaces `Tenant and company are required to attach a customer.`
   - Missing name or missing phone/email blocks local pending creation.
   - Store-level `attachCustomer()` throws on missing tenant/company/id/name instead of accepting a corrupt checkout snapshot.

3. **Dead-path rebuild:** APPROVE.
   - `CustomerAttachPanel` is mounted in `HomePage`, above `TransactionCart`.
   - `CustomerSearchInput`, `CustomerBalanceBadge`, pending customer creation, and `paymentStore` attach/detach actions all have live callers.

4. **Discriminated-union / matrix completeness:** APPROVE for Task 6 surface.
   - Tests cover synced mirror attach, pending-create attach, stale/fresh balance display, missing tenant/company block, cross-company search exclusion, and detach-before-seal state clearing.
   - ACCOUNT_PAYMENT canonical discriminants are Task 7 scope and are not introduced in this commit.

5. **Contract drift vs docs:** APPROVE.
   - Task 6 plan required checkout-state attach, stale marker display, deterministic local pending ID, and outbox row; all are implemented.
   - This commit intentionally does not author ACCOUNT_PAYMENT or mutate SALE_RECEIPT payloads; those remain Task 7+ concerns.

6. **Per-method skip rule / skip citation accuracy:** APPROVE.
   - No skipped tests added.

7. **CLAUDE.md rule 13 / constructor injection only:** APPROVE.
   - No PHP service locator use added.
   - Targeted grep over touched TS/TSX files found no `app()`, `App::make`, or container `resolve()`.

8. **D16 bounded-module guard:** APPROVE.
   - POS UI uses local SQLite repositories only.
   - No Treasury, Accounting, Customer-module, or B2B operational dependency was introduced into Fiscal/POS backend projectors.

9. **R2-fix-defect pattern:** N/A.
   - This is R1 self-review. One pre-review hardening issue was fixed before the review file: `CustomerAttachPanel` now rejects selected rows whose tenant/company differs from the active checkout context.

## Verification Evidence

- `pnpm test -- CustomerBalanceBadge CustomerSearchInput CustomerAttachPanel paymentStore.customerAttach` — PASS, 11 tests before hardening; after hardening `CustomerAttachPanel CustomerSearchInput paymentStore.customerAttach` — PASS, 10 tests.
- `pnpm typecheck` — PASS.
- `pnpm lint` — PASS exit code 0; remaining 42 warnings are pre-existing outside the new customer files.
- Full POS suite after final amend: PASS, 161 files, 1423 tests.
- `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — PASS, 1101 tests, 3689 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/POS app/Modules/Fiscal` — PASS.
- `./vendor/bin/pint --test app/Modules/POS app/Modules/Fiscal` — PASS.
- `bash scripts/check-saleReceipt-chokepoints.sh` — PASS.
- `.PASS_2B_PENDING` sentinel check — PASS absent.
- `git diff --check` — PASS.

## Residual Risks

- Broad PHPStan over historical Fiscal/POS tests still fails on pre-existing test-suite typing debt; no PHP files were touched in Task 6, and app-module PHPStan is green.
- Literal UI strings were introduced for the new POS panel. This matches the narrow Task 6 implementation but should be localized when Phase 2 UX copy is finalized.
