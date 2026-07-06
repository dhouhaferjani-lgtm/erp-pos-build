# Adversarial Code Review — Wave 6 (receive-dialog delivered-price UI)

- **Date:** 2026-07-05
- **Scope:** UNCOMMITTED working-tree diff in `apps/erp.procurement-v2` (branch `feat/procurement-wave3`, HEAD `b9866f053`), Tasks 17–19 of `2026-07-03-procurement-completeness-wave3-6-receipt-ledger-plan.md`, spec Gap 2 §2.9.
- **Files reviewed (13):** `ReceiveGoodsDialog.tsx` + test, `PurchaseOrderDetailPage.tsx` + tenantScope test, `GoodsReceiptListPage.tsx` + tenantScope test, `usePermissions.ts`, 6 locale JSONs.
- **Verification actually run:** the 3 touched Vitest files (19/19 pass), `pnpm typecheck` (clean), `eslint` on the 4 touched source files (0 errors, 97 warnings incl. 2 NEW — see W6-1), grep of the full diff for `parseFloat|Number(|toFixed(|parseInt` (zero hits), locale JSONs parsed with duplicate-key detection (none), backend contract read (`ReceiveGoodsRequest.php`, `PurchaseOrderController.php:713-720`, `RolesAndPermissionsSeeder.php`).

## Verdict: **SHIP** (minor follow-ups W6-1..W6-5; nothing payload-, permission-, or precision-breaking)

---

## Attack-point results

### 1. Payload discipline — PASS
- Prices sent ONLY for user-edited lines: `ReceiveGoodsDialog.tsx:196-201` adds `receivedUnitPrices[line.id]` only when `deliveredUnitPrice.trim() !== ''`, and only inside the `hasPaidQuantity` branch — so **quantities are structurally guaranteed present for every priced line** (backend `quantities => required_with:received_unit_prices`, `ReceiveGoodsRequest.php:27`, satisfied by construction).
- Strings end-to-end: `MoneyInput.tsx:86-94` passes the raw input string verbatim ("no parseFloat, no Number()"); dialog state is string; payload maps are `Record<string,string>`. Grep of the whole diff for float coercion: zero hits.
- Empty ⇒ omitted: `:199` skips empty; spread guards `:217-222` omit the `received_unit_prices` / `price_override_reason` / `free_quantities` / `batches` keys entirely when empty. Proven by the strict `toHaveBeenCalledWith` assertions in `PurchaseOrderDetailPage.tenantScope.test.tsx:356-360` and `:467-471` (payload is exactly `{quantities}`).
- Override reason sent only when at least one price is in the payload AND non-blank: `:219-221`. Backend accepts `nullable` (`ReceiveGoodsRequest.php:37`) — optional reason is contract-consistent.

### 2. Permission gating — PASS
- FE: `canEditReceiptPrice = hasPermission('goods-receipt.edit-price')` (`ReceiveGoodsDialog.tsx:106-107`); without it the cell renders a read-only PO price + helper text, no input exists (`:339-345`), so a non-holder physically cannot produce `received_unit_prices`. Backend independently `prohibited` without the permission (`ReceiveGoodsRequest.php:24,35`).
- `usePermissions.ts` change is **exactly one additive line** (`:19` `'goods-receipt.edit-price': ['admin','manager']`). `PERMISSIONS` is a keyed `as const` map; `hasPermission` does a keyed lookup (`:261-265`), so adding a key cannot change any other consumer's result; the `Permission` union only widens. Safe.
- FE role list matches backend grants exactly: seeder gives it to `manager` (`RolesAndPermissionsSeeder.php:428`) + `admin` (all); backend roles are admin/manager/cashier/viewer/technician/operator/accountant (`:413-644`) — there is no backend 'purchases' role, and the backend test asserts admin+manager yes / cashier+operator no (`ReceiveGoodsRequestTest.php:214-217`).
- Pre-existing caveat (NOT this diff): the hook is a hardcoded role map (TODO at `usePermissions.ts:1-4`), so a custom role granted the backend permission will see the read-only cell. Deny-only mismatch — safe direction.

### 3. Variance chip — PASS
- Pure bc/Big string math: `variancePercent` (`ReceiveGoodsDialog.tsx:230-238`) = `bcmul(bcdiv(bcsub(delivered, ordered, 6), ordered, 6), '100', 1)`; sign check + '+' prefix via `bccomp` (`:240-247`). `formatQuantity` is `Big.toFixed` (`lib/decimal.ts:206-214`) — plain '.'-separator, no locale, so `bccomp(formatted,'0')` is sound.
- 5.000→5.200: 0.200/5.000=0.040000 ×100 @scale1 = `+4.0%` — asserted in `ReceiveGoodsDialog.test.tsx:94`. Negatives carry their own '-' (Big toFixed), '+' only when >0.
- "—" (`noVariance` key) when delivered empty, delivered == ordered, or ordered == 0 (div-by-zero guarded, `:232`; `bcdiv` would throw, `lib/decimal.ts:97-99`). Malformed input is safe: `safeBig` resolves garbage to 0 (`lib/decimal.ts:30-37`).
- Chip color: yellow when +, green when −, gray token when "—" (`:267-269`); classes are `tokens.badge.*` which all exist (`designTokens.ts:433-441`).

### 4. §2.9 layout — PASS
- Free-qty cell preserved verbatim (`:309-321`), incl. `freeOrderedSummary` helper (`:287-294`); batch number/expiry cells preserved (`:353-372`) with sub-grid span correctly updated 3→6 (`:354`) to match the widened `md:grid-cols-6` (`:273`).
- "PU commande" read-only, sourced from PO `line.unit_price` via `unitPrice()`/`decimalString` (`:226-228`, `:322-325`); read-only "PU livré" branch also shows the PO price (empty = PO price semantics). PO contractual price never mutated.

### 5. GRN toast at both call sites — PASS
- `PurchaseOrderDetailPage.tsx:152-171`: `api.post<ReceiveGoodsResponse>` → `response.data` = the `{data, meta}` envelope → `response.meta?.goods_receipt?.receipt_number` → `goodsReceivedWithReceipt` toast, falling back to the old key. Typed `ReceiveGoodsResponse` interface (`:53-60`).
- `GoodsReceiptListPage.tsx:149-160`: same pattern, `inventory:goodsReceipt.successMessageWithReceipt` + fallback. Typed interface (`:70-77`).
- No double-unwrap: they deliberately switched from `apiPost` (which strips `meta`) to raw `api.post` and read `response.data` once — this is exactly the documented pattern for meta-bearing envelopes. Matches the controller shape (`PurchaseOrderController.php:713-720`: top-level `data` + `meta.goods_receipt` from `GoodsReceiptData::fromModel`).
- Both toasts covered by new tests (`PurchaseOrderDetailPage.tenantScope.test.tsx:364-373`, `GoodsReceiptListPage.tenantScope.test.tsx:277-287`).

### 6. i18n — PASS
- All 6 new dialog keys (`orderedUnitPrice`, `deliveredUnitPrice`, `variance`, `noVariance`, `priceEditReadOnly`, `priceOverrideReason`) present under `purchaseOrders.receive` in **fr, en, AND ar** `sales.json`; `documents.messages.goodsReceivedWithReceipt` in all three `sales.json`; `goodsReceipt.successMessageWithReceipt` in all three `inventory.json` (programmatically verified, incl. duplicate-key scan — none). Bonus: `ar/inventory.json` also gained the previously-MISSING `goodsReceipt.successMessage`, fixing a latent ar fallback.
- No count-bearing strings → no plural forms needed.

### 7. a11y — REAL (for what was in scope)
- Dialog accessible name was ALREADY provided by `Modal` (`Modal.tsx:141-143`: `role="dialog"`, `aria-modal`, `aria-label={title}`) — nothing to fix, correctly not touched.
- New controls are genuinely labeled: MoneyInput gets a per-line `aria-label` (`ReceiveGoodsDialog.tsx:336`), reason Textarea labeled + `aria-label` (`:380-387`), both wrapped in `<label>`. Read-only cells are static text (no control to label). The read-only permission test locates elements via `getByLabelText` absence — labels are load-bearing in tests, not cosmetic.

### 8. Test quality — ADEQUATE, with gaps (W6-2)
- Read-only gating asserted for real: `mockHasPermission=false` → input absent by label, PO price shown, helper text shown (`ReceiveGoodsDialog.test.tsx:126-152`).
- Payload shape asserted strictly (`toHaveBeenCalledWith`, `:106-117`): prices only for the edited line, quantities for BOTH lines, reason string; reason-input appearance asserted before/after edit (`:87`, `:95-98`).
- Chip math: `+4.0%` asserted (`:94`).
- No existing assertion weakened: the two page tests' payload assertions were mechanically migrated `mockApiPost`→`mockAxiosPost` with identical expected payloads; the one substantive edit is the date assertion (see W6-7).

### 9. Out-of-scope edits — CLEAN
- `PurchaseOrderDetailPage.tsx` diff is ONLY: `ReceiveGoodsResponse` interface, `mutationFn` apiPost→api.post, and the GRN-aware toast (22 lines). Nothing else changed.
- `GoodsReceiptListPage.tsx` likewise only the mutation/toast + dead `apiPost` import removal.

---

## Findings

- **W6-1 (MINOR — rule 18):** New hardcoded Tailwind colors `text-gray-900` at `ReceiveGoodsDialog.tsx:324` and `:342` introduce 2 new ESLint `no-restricted-syntax` warnings. New code in touched files must use `textColors` from `lib/designTokens.ts`. One-line fix each.
- **W6-2 (MINOR — plan Task 18 test checklist partially unmet):** Missing tests the plan explicitly listed: (a) equal-price ⇒ "—" chip; (b) **bonus-cell regression** — `free_quantities` has ZERO FE test coverage repo-wide (grep: only the dialog source references it), so the preserved free-qty cells/payload are unprotected against regression; (c) the permission test never asserts `hasPermission` was called with `'goods-receipt.edit-price'` (the mock denies ALL keys). Code verified correct by inspection; the guardrails are what's missing.
- **W6-3 (MINOR — cosmetic):** "PU commande" / read-only "PU livré" render the raw `String(unit_price)` with no `formatCurrency` and no currency symbol (`:226-228`, `:324`, `:342`) — shows "5" if the API field arrives numeric (the `GoodsReceiptListPage` local interface even types `unit_price: number`, `:35` — pre-existing lie vs the string precision contract). Also the delivered payload may not preserve typed trailing zeros ('5.200' typed → `'5.2'` sent, evidenced by the test's own expectation `ReceiveGoodsDialog.test.tsx:112`) — numerically identical, passes the backend `\d{1,3}` regex, harmless.
- **W6-4 (MINOR — silent-drop edge):** Enter a delivered price, then zero that line's paid quantity → the price (and, if it was the only priced line, the typed override reason) is silently dropped at submit (`:196-201`, `:219-221`). Contract-correct (prices require quantities) but the user gets no feedback, and the reason textarea remains visible (`hasEditedUnitPrice` `:121-123` ignores quantity).
- **W6-5 (MINOR — cosmetic):** Sub-0.05% variances round to a green "0.0%"/"-0.0%" chip (percent computed at scale 1, `:237`) instead of "—". The exact price still reaches the backend, so costing/PPV are unaffected.
- **W6-6 (NOTE):** Spec §2.9's "Vide = PU commande" legend is conveyed to permission-holders only via `placeholder={orderedUnitPrice}` (`:335`); the explanatory helper text exists only in the read-only branch. Acceptable reading of the sketch.
- **W6-7 (NOTE — rode along):** `GoodsReceiptListPage.tenantScope.test.tsx:247` replaced the env-derived `toLocaleDateString()` date assertion with a hardcoded US-format regex. The file had to be rewritten anyway (apiPost→api.post mocks), and the old assertion was format-fragile (`toLocaleDateString()` → "6/28/2026" vs the component's 2-digit Intl output), but the new regex assumes en/US ordering — deterministic only while test i18n stays 'en'.
- **W6-8 (NOTE):** FE gate matches backend seeding exactly (admin+manager). The hardcoded-map limitation for custom roles is a pre-existing, documented hook TODO, not a Wave-6 regression.

## Suggested pre-commit fixes (cheap)
1. W6-1: swap the two `text-gray-900` for the token equivalent.
2. W6-2(a)+(c): two ~5-line test additions in `ReceiveGoodsDialog.test.tsx`.
(W6-2(b) free-qty regression test can ride the next purchases test touch; W6-4/5 are follow-up polish.)
