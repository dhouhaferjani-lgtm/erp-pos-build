# Task 06 Opus-Equivalent Second-Pass Review — Customer Search/Create/Attach UX

**Commit reviewed:** `dc1b5819b Phase 2.6.1: Add POS customer attach flow`  
**Codex self-review read:** `docs/superpowers/reviews/2026-05-21-task-06-codex-review.md`  
**Reviewer:** Codex acting as Opus-equivalent second-pass adversarial reviewer  
**Verdict:** REQUEST-CHANGES

## Scope Reviewed

- `apps/pos/src/components/customers/CustomerAttachPanel.tsx`
- `apps/pos/src/components/customers/CustomerSearchInput.tsx`
- `apps/pos/src/components/customers/CustomerBalanceBadge.tsx`
- `apps/pos/src/components/customers/customerAttachUtils.ts`
- `apps/pos/src/stores/paymentStore.ts`
- `apps/pos/src/pages/HomePage.tsx`
- Task 6 tests under `apps/pos/src/components/customers/` and `apps/pos/src/stores/__tests__/paymentStore.customerAttach.test.ts`
- Plan Task 6 section and Phase 2 spec customer mirror/search/attach requirements.

## Findings

### REQUEST-CHANGES 1 — Balance badge omits required visible net due and `balance_updated_at`

**Files / lines:**

- `docs/superpowers/specs/2026-05-21-pos-customer-accounts-phase2-spec-v1.md:107-110`
- `apps/pos/src/components/customers/CustomerBalanceBadge.tsx:18-36`
- `apps/pos/src/components/customers/CustomerBalanceBadge.test.tsx:11-40`

The Phase 2 spec requires the checkout attach surface to show “balance summary: receivable, credit, net due, `balance_updated_at`, stale marker.” The implemented `CustomerBalanceBadge` renders receivable as `Due`, credit, and Fresh/Stale only. It passes `balanceUpdatedAt` only into the HTML `title` attribute on the freshness pill, so the timestamp is not visibly rendered and is not reliably available on the Tauri/touch POS surface. It also never computes or displays net due.

**Repro / impact:** Render `CustomerBalanceBadge` with `receivableBalance="42.500"`, `creditBalance="3.250"`, `balanceUpdatedAt="2026-05-21T08:00:00.000Z"`, and `stale={false}`. The cashier sees `Due TND 42.500`, `Credit TND 3.250`, and `Fresh`; there is no visible `39.250` net-due figure and no visible balance timestamp. That is contract drift from the Task 6 UX requirement and weakens the operator’s ability to verify the balance snapshot before Task 7 seals it into `ACCOUNT_PAYMENT`.

**Expected fix:** Render a visible net due value and a visible balance-updated timestamp in `CustomerBalanceBadge`, then extend `CustomerBalanceBadge.test.tsx` and/or `CustomerAttachPanel.test.tsx` to assert both fields. Keep the existing stale marker.

## Minor Risks / Non-Blocking Notes

### P3 — Search results can momentarily show stale rows after a non-null scope change

**Files / lines:**

- `apps/pos/src/components/customers/CustomerSearchInput.tsx:27-62`
- `apps/pos/src/components/customers/CustomerAttachPanel.tsx:51-61`

`CustomerSearchInput` does not clear existing `results` when `tenantId` or `companyId` changes while a non-empty query is present. If the active company changes from one non-null company to another, the previous company’s results remain visible until the new async search resolves. The attach handler rejects the stale row because it rechecks tenant/company before writing checkout state, so I am not treating this as a data-safety blocker. It is still a POS UI risk: the cashier can briefly see and click an out-of-scope customer, then receive a scope error. Clearing results/loading/error at the start of the scope-dependent effect would remove the stale display window.

## Axes Checked

- **Cross-tenant/company safety:** Search calls `searchCustomers()` with tenant and company scope, and the repository filters both. `CustomerAttachPanel` revalidates row scope before `attachCustomer()`. Pending create writes explicit tenant/company. Checkout state stores tenant/company with the selected snapshot, but active-scope changes should keep the stale-result note above in mind for the R2 fix.
- **Fail-loud vs silent downgrade:** Missing tenant/company, missing customer name, missing contact key, and store-level missing identifiers fail visibly or throw. The cross-company attach pre-review hardening rejects before state mutation.
- **Dead-path rebuild:** `CustomerAttachPanel` is mounted in `HomePage`, and the new search, badge, create, attach, and detach paths have live callers.
- **Task 6 test matrix:** Synced attach, pending local create, missing scope, cross-company search exclusion, detach-before-seal, store attach/detach/reset, and stale badge state are covered. The matrix should add visible net-due and visible timestamp assertions with the required fix.
- **Contract drift:** No `ACCOUNT_PAYMENT` authoring was introduced, and no `SALE_RECEIPT` payload mutation is present. The drift is limited to the Task 6 visible balance summary fields above.
- **D16 bounded-modules guard:** No Treasury/Accounting/B2B hard dependency was introduced. The POS UI uses local mirror/outbox repositories only.
- **CLAUDE.md rule 13:** No PHP service locator additions; touched files are TS/TSX only. Grep over touched files found no `app()`, `App::make`, or `resolve()` additions.
- **Per-method skip rule / skip-citation accuracy:** No skipped tests were added.
- **R2 defect pattern:** The pre-review cross-company guard is directionally correct and has a test for search exclusion, but the R2 should also avoid introducing any balance-display arithmetic/formatting drift.

## Verdict

REQUEST-CHANGES. The implementation is structurally sound for scoping and live wiring, but the customer balance summary is missing two explicit Task 6/spec fields that the cashier needs before account-payment authoring begins.
