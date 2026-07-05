# P2P Wave 4 — Adversarial Code Review (receipt-first)

- **Scope:** uncommitted Wave 4 diff on `feat/p2p-entry-points` (worktree `apps/erp.p2p-flow`), `git diff HEAD`.
- **Against:** plan Rev 2 WAVE 4, spec §6, task log `docs/sessions/TASK-LOG-w4.md`.
- **Reviewer:** adversarial code review, read-only. No files modified, no git writes.
- **Verdict:** **NEEDS-FIX-ROUND** (light). No BLOCKER. Core saga / precision / exposure / authz are sound; fixes are FE idempotency-key stability + a lint/verification gap + two UX/scope MINORs.

---

## Verified correct (the hard parts hold)

- **Saga boundaries are genuinely two separate transactions.** t1 (`StandaloneReceiptService.php:60-87`) = key INSERT + auto-PO create + `PurchaseOrderService::confirm`; t2 (`:101-122`) = createDraft (+ post) in its own `DB::transaction`. No umbrella wraps them. `confirm()` opens its own nested transaction (savepoint) inside t1 — atomic. t1 failure rolls back the key INSERT (no orphan key); confirmed by the compensation test leaving zero receipts.
- **failClosedGrir wired.** `post($draft, $input->actorId, true)` (`:121`) → `GoodsReceiptService::post(…, bool $failClosedGrir = false)` (`GoodsReceiptService.php:195`). On GR-IR throw in t2 the whole t2 transaction rolls back (createDraft is nested in the same outer t2 closure), so no stock/GL/receipt residue; `it_cancels_the_auto_po_when_posting_fails` asserts `GoodsReceipt::count() === 0` + PO `Cancelled`.
- **Compensation is clean.** t2 `\Throwable` → PO forceFilled `Cancelled` + `cancelled_at/by/reason` (columns exist: `2025_12_24_133828_add_confirmation_tracking_to_documents.php`), then rethrow. PO confirm books no GL/stock, so a cancelled auto-PO leaves no residue.
- **Idempotency = INSERT-first, DB-enforced.** `DB::table('procurement_idempotency_keys')->insert(...)` is the FIRST statement in t1 (`:61`), not SELECT-then-INSERT. `UNIQUE (company_id, idempotency_key)` (`create_procurement_idempotency_keys` migration `:22`). Unique-violation → `existingResult()` returns the SAME PO+receipt; happy-path replay test asserts identical ids and counts of 1 / 1 / 1.
- **BL prices as accrual anchor.** Auto-PO line `unit_price` = `bcformatStrict($line->unitPrice, $scale)` (`:245`); `receivedUnitPrices[poLine->id]` = same BL price (`:296`) flows into `createDraft`'s `receivedUnitPrices`. Test asserts PO line `unit_price` and receipt `received_unit_price` both `'5.200'`. All money/qty via `bcformatStrict`, intermediates at `scale+4`, strings throughout — no float touches money/qty.
- **`confirm(?string $actorId = null)`.** Default-param bridge only at the controller edge (`PurchaseOrderController.php:665` passes `$user->id`); internal `confirmAndAllocateCosts` uses `$actorId ?? auth()->id()` — auth is not read when an actor is supplied. Unit test `test_confirm_accepts_explicit_actor_id` added.
- **Exposure matrix (spec §6.4).** Default exclude `whereJsonDoesntContainKey('payload->auto_generated')` + `include_auto_generated=1|true` opt-in (`PurchaseOrderController.php:231-234`). Exposure test pins BOTH branches (normal null-payload PO included by default, auto excluded; opt-in includes both + `is_auto_generated` flag). `DocumentData::is_auto_generated` is payload-derived, payload preserved at rest, added as a named arg (no positional callers of `new DocumentData(` exist — safe). Chain-reader test and RFQ-award non-interference test are real (arrange an RFQ group + unrelated auto-PO, assert award `source_document_id` is the RFQ, not tripped). `AgedPayablesAutoPoTest` is real, not tautological.
- **Line index-mapping is safe.** `receiptMaps` maps `$purchaseOrder->lines[$index]` ↔ `$input->lines[$index]`; `Document::lines()` is `->orderBy('line_number')` and lines are created 1..n in input order, with a hard drift throw if counts diverge.
- **Authz/tenancy.** Route `can:goods-receipt.create-standalone` (singular) — 403-without / 201-with tests both pass, proving the permission is seeded (W2). Policy `allowsReceiptFirst()` asserted SERVER-SIDE and fail-closed in the service (`:52-54`), pinned by `it_fails_closed_when_receipt_first_policy_is_disabled`. Supplier scoped by `tenant_id + company_id + type`; product by `tenant_id + company_id`; location by `company_id` (sufficient — a company belongs to one tenant).
- **FE precision.** `MoneyInput`/`QuantityInput` (string-emitting), `formatQuantity` is `Big`-based (`decimal.ts:206`, no `Number` round-trip), `tenantScopedKey` on all three option queries, route + sidebar gated on the permission, `t()` everywhere incl. ar/fr. FE test asserts strings preserved end-to-end (`qty:'3.1250'`, `unit_price:'7.250'`).

---

## Findings

### MAJOR

**M1 — FE idempotency key is regenerated per submit, defeating the saga's retry-safety.**
`StandaloneReceiptPage.tsx:53-55` + `:114`: `idempotency_key: idempotencyKey()` calls `crypto.randomUUID()` **inside `mutationFn`, on every invocation**. Spec §6.3 states the key exists "to prevent double-submit duplicates." As wired, a user-visible error that leaves server state half-committed (e.g., t1 committed the PO then t2 failed and compensated, or a network blip after the server committed) re-enables the buttons; the user's retry generates a **new** key → a **new** saga → a **duplicate** auto-PO/receipt. The server DB-unique net only dedupes replays that reuse the *same* key — which this FE never produces (React-Query mutations don't auto-retry). Mitigation present but partial: buttons are `disabled` while `isPending`, which blocks the double-click vector only. Fix: mint the key **once per form-fill intent** (`useRef`/`useState`, regenerated only after a successful submit), so a genuine retry hits the idempotent path.

### MINOR

**M2 — `AgedPayablesService` change silently widens scope to all received POs.**
`AgedPayablesService.php:96`: `->whereIn('status', [Posted, Received])`. Needed because a posted standalone receipt transitions the auto-PO to `Received` (test pins this). But the report is PO-based, so this now also pulls in **every normal received-but-not-fully-invoiced PO**, not just auto-POs — a defensible accrual view, but a behavior change for the whole report with no test pinning the normal-received case, and a received PO whose SI is already posted/paid can linger if its `balance_due` isn't decremented (pre-existing PO-aged-payables design, not introduced here). Recommend a one-line note in the method docblock and, ideally, a normal-received-PO assertion.

**M3 — Receipt-first FE entry gated on permission only, not on `allow_receipt_first` policy.**
`Sidebar.tsx:177`, `routes/index.tsx:883-892`, `GoodsReceiptListPage.tsx` button all gate on `goods-receipt.create-standalone` alone. A permitted user in a company with receipt-first **disabled** sees the menu + page, fills the form, and gets a 422 on submit. Server is correctly fail-closed; this is a UX gap only. Consider hiding the entry when the loaded procurement policy has `allow_receipt_first === false`.

**M4 — Compensation leaves the idempotency row; same-key replay after a compensated failure is permanently rejected.**
`StandaloneReceiptService.php:124-134` cancels the PO but does not delete/flag the key row (po_id set, gr_id null). A subsequent same-key call hits `existingResult` → `:318-320` throws "already in progress" — conflating a dead/compensated key with a concurrently-running one. Benign given the fresh-key FE, but if M1 is fixed to a stable key this becomes load-bearing: either clear the row on compensation, or distinguish "compensated" from "in progress."

**M5 — New page uses pervasive hardcoded Tailwind colors; `pnpm lint` absent from W4 green evidence.**
`StandaloneReceiptPage.tsx` uses `text-gray-900/500`, `bg-gray-50`, `text-teal-700`, `border-teal-200`, `bg-teal-50`, `text-teal-900`, `hover:bg-red-50 hover:text-red-600` (buttons/inputs correctly use `tokens.*`). Rule 18 wants design tokens for new code. The task log shows `typecheck` green but never runs `pnpm lint` — the design-token ESLint rule and `audit-tanstack-keys` are lint-time; verify `pnpm lint` passes before merge.

**M6 — FormRequest lacks scoped `exists` rules.**
`CreateStandaloneReceiptRequest.php:22-30`: supplier/location/product validated as `uuid` only. A cross-tenant/cross-company id surfaces as `ModelNotFoundException` → framework 404 (fail-closed, no leakage — the service's scoped `findOrFail` is the real guard), but the error shape is a 404 rather than a clean 422. Optional: add company/tenant-scoped `Rule::exists` for a validation-shaped error.

---

## Out-of-scope-edit sweep

All edits map to §6 / §6.4 scope — none gratuitous:
- `Sidebar.tsx`, `routes/index.tsx`, `usePermissions.ts`, `GoodsReceiptListPage.tsx` (new-receipt button) — §6.1 entry points. OK.
- `AgedPayablesService.php` — §6.4 matrix (judged: M2). Justified by the `Received` auto-PO status.
- `DocumentData.php` — §6.4 serializer flag. OK.
- `PurchaseOrderService/Controller.php` — §6.3 explicit-actor confirm (plan C-M). OK.
- `GoodsReceiptService.php` — now also persists `external_*` to columns (was payload-only in W3); §6.2. OK, applies to all draft receipts consistently.

## Recommendation
Address **M1** (stable idempotency key) and run/confirm **M5** (`pnpm lint`) this round; M2/M3/M4/M6 are safe-to-defer polish but M4 becomes mandatory if M1's key is made stable. No correctness BLOCKER — the saga, precision, exposure, and authz contracts are met.
