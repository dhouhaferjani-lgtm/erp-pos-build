# CODEX HANDOVER — Demo-fix Round 2, Chunk 2 (4 findings)

> Hand this whole file to Codex desktop. Work the findings ONE AT A TIME, TDD, with a mandatory
> Opus review after each. Chunk 1 (import / documents-500 / payments-KPI / payment-status / tax /
> discount) is already committed on this branch — do NOT touch those areas.

## Environment (same as Chunk 1)
- **Worktree/branch:** `/Users/houssamr/Projects/syneriva/apps/erp.demo-fixes` on
  `fix/demo-visual-round2` (off origin/dev; already holds the 6 chunk-1 commits). Work here only,
  do not push.
- Backend `apps/api` (Laravel 12, PHP 8.2 strict, hexagonal, sqlite `:memory:` tests) + frontend
  `apps/web` (React 19 / TS strict / TanStack Query 5). vendor + node_modules + .env are set up.

## Global rules — IDENTICAL to Chunk 1
Read the "Global rules" + "Opus self-review gate" sections of
`docs/handoff/CODEX-demo-visual-round2-chunk1.md` and apply them verbatim: TDD (red first), **tests
BY PATH only — NEVER the full suite**, strict typing, constructor-injection-only, money/qty
precision (no float/`parseFloat` on money — use `MoneyInput`/`QuantityInput` + `formatCurrency`),
`apiGet`/`apiPost` already unwrap `response.data.data` (paginated `{data,meta}` → use `api.get` +
return `response.data`), all text via `t()`, design tokens for colors, per-finding quality gates
(new + existing tests by path green, PHPStan L8, Pint, `tsc --noEmit`, `pnpm lint`), one commit per
finding (Conventional Commits, no push), scope discipline.

## Opus self-review gate — MANDATORY after each finding
Same command as Chunk 1:
```bash
git diff > /tmp/finding-N.diff
claude -p --model claude-opus-4-8 "You are an adversarial code reviewer for a Laravel 12 + React
ERP. Review this diff for finding N: <one-line desc>. Verify against the codebase: (1) root cause
fixed not symptom; (2) no float-on-money / strict-typing / app()-DI / hardcoded-i18n violation;
(3) the new test genuinely FAILS without the fix; (4) no scope creep. Cite file:line. Verdict:
SHIP or DO-NOT-SHIP + blocking items. Diff:\n$(cat /tmp/finding-N.diff)"
```
Address every DO-NOT-SHIP item, re-review until SHIP, before the next finding.

---

## Finding 1 — 🔴 Bank Reconciliation page crashes ("Query data cannot be undefined") (frontend)
`/treasury/reconciliation` errors on load. Two React-Query errors — *"Query data cannot be
undefined"* for the `payment-repositories` and `reconciliations` query fns — even though the APIs
return 200 (`GET /bank-reconciliations` → `{"data":[]}`, `/payment-repositories` → 200). The FE
query function **double-unwraps an empty/paginated envelope and returns `undefined`** (a TanStack v5
queryFn may never return `undefined`). **Seam:** `apps/web/src/features/treasury/api/reconciliation.ts`
+ `apps/web/src/features/treasury/hooks/useReconciliation.ts` (and the repositories query it uses —
`apps/web/src/features/pos/api/paymentRepositoryApi.ts` or the treasury repo api). Component:
`apps/web/src/features/treasury/BankReconciliationPage.tsx` (test `BankReconciliationPage.test.tsx`).
**Fix:** return the correct unwrap for a paginated `{data,meta}` (use `api.get` → `response.data`, or
return `response.data.data ?? []` — never `undefined`). **Test:** query fns return `[]` (not
`undefined`) for an empty `{data:[]}` response; page renders without the error boundary.

## Finding 2 — 🟡 Goods Receipts list shows "Invalid Date" for PO rows (frontend)
`/purchases/receipts` renders "Invalid Date" on the pending-PO rows. **Seam:**
`apps/web/src/features/purchases/GoodsReceiptListPage.tsx` — the date cell parses/formats a field
that is null/differently-shaped for PO-sourced rows (wrong field name or a non-ISO value fed to
`new Date()`/the formatter). Identify the actual date field on those rows and format defensively
(guard invalid/empty → render "—" or the correct source date). **Test:** a PO-sourced receipt row
renders a valid formatted date (or a clean placeholder), never "Invalid Date".

## Finding 3 — 🟡/⬜ Goods Receipt: no partial-qty / batch-lot-expiry capture (frontend + backend)
"Receive Goods" opens only a confirm-all dialog ("This will create stock movements to receive the
goods… Continue?") — no partial-quantity receiving and no batch/lot/expiry capture, even for
batch-tracked pharma items. **Backend already supports partial receive** (`GoodsReceiptService::
receiveGoods()` takes a per-line `quantities` map and creates lots when `requires_batch_tracking`) —
so this is mostly a **frontend dialog** + wiring. **Seam:** the Receive-Goods action in
`apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx` /
`apps/web/src/features/documents/components/DocumentActions.tsx` (and/or `GoodsReceiptListPage.tsx`).
**Fix:** replace the confirm-all with a dialog listing PO lines with editable receive-quantity
(default = remaining, capped at ordered−received) and, for `requires_batch_tracking` products,
lot number + expiry date inputs; submit the per-line quantities (+ batch data) to the existing
receive endpoint. Confirm the backend request shape it expects (quantities map / batch payload).
**Test:** partial receive (qty < ordered) posts the right quantities and leaves the PO partially
received; a batch-tracked line requires+submits lot+expiry. Keep money/qty as strings (no
`parseFloat`); use `QuantityInput`.

## Finding 4 — 🟡 No smart-payment allocation UI on `/treasury/payments/new` (frontend)
The Record-Payment form is flat (Amount/Method/Repository/Partner/Date/Ref/Notes) — selecting a
partner with an open invoice shows no open-invoice allocation, no partial/full allocation, no
split-tender, no advance/overpayment. **The engine + pieces already exist**:
`apps/web/src/features/treasury/components/PaymentAllocationForm.tsx`, the smart-payment hooks
(`features/treasury/hooks/…smartPayment…`), and backend `SmartPaymentController::getOpenInvoices()`
+ `PaymentAllocationService`. **Seam:** `apps/web/src/features/treasury/PaymentForm.tsx` (the
`/treasury/payments/new` route) — it isn't wiring the allocation form. **Fix:** when a partner with
open invoices is selected, surface the open-invoice allocation list (partial/full), and split-tender
if `SplitPaymentForm.tsx` covers it — reuse the existing components/hooks, don't rebuild the engine.
Scope to wiring + the allocation UX; do not change allocation/GL backend logic. **Test:** selecting
a partner with an open invoice renders allocatable invoices and a partial allocation submits through
the existing hook. Amounts as strings.

---

## Final report (to orchestrator)
Per finding: root cause (1 line), files changed, exact test commands + pass output, Opus verdict
(SHIP), commit SHA (do not push). Flag out-of-scope observations. If a finding is a non-bug or
blocked, say so. After Chunk 2 the orchestrator verifies + merges; Chunk 3 (i18n / currency
formatting) follows.
