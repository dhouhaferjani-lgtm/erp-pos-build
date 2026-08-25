# W2-6 gate r1 — frontend-conventions lens — `fix/campaign-w26-po-price-default`

**Lane** W2-6 (P1) · **HEAD** `923e8d4d0` · **worktree** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w26-po-price`
**Diff** `git diff dev...HEAD` → 8 files (7 × `apps/web/`, 1 doc). **Zero PHP.**
**Reviewer** adversarial frontend-conventions gate. Nothing below is taken from the handback; every claim was re-executed or read at `file:line`.

## VERDICT: **REJECT**

The core fix is right and well-evidenced: the purchase default is genuinely `products.purchase_price` → EMPTY, never `sale_price`; the red-proof reproduces; the source census is honest; `cost_price` / `last_purchase_cost` are correctly excluded. **But the lane's own escape hatch — `buildLinePayload`'s `'' → '0'` — is applied to the FINAL SUBMIT path, not just the draft autosave.** That (a) lets the new EMPTY purchase default persist as a confirmed PO line at `0.000` with no guard anywhere downstream, and (b) silently regresses **sales invoices**, where clearing a unit price used to 422 and now bills zero. Finding 1 must be fixed before merge; 2 and 3 should land with it.

---

## Findings

### 1. BLOCKER — `'' → '0'` is applied on final submit, not only on autosave; an unpriced line is persisted as `0.000`, and clearing any price on ANY document type now silently bills zero

`apps/web/src/features/documents/DocumentForm.tsx:236`

```ts
unit_price: isBlank(line.unit_price) ? '0' : line.unit_price,
```

`buildLinePayload` is consumed by **both** paths, not just the draft:
- autosave draft — `DocumentForm.tsx:354`
- **`onSubmit` create/update — `DocumentForm.tsx:561`**

The handback justifies the coercion entirely with the autosave-422 argument (§4). That argument does not reach `:561`, and it is also weaker than stated: the autosave endpoint already accepts a null price — `apps/api/app/Modules/Document/Presentation/Requests/AutoSaveDraftRequest.php:217` `['nullable','numeric','regex:…']`.

**Consequence (a) — the new EMPTY default becomes a real 0.000 purchase.** Nothing downstream stops it:
- `apps/api/app/Modules/Document/Domain/Services/PurchaseOrderService.php:46-68` — `confirm()` checks only type and draft status. No zero-price / zero-total guard.
- Goods receipt posts `Dr 37` at the PO line price → 0.000, and pollutes WAC downward.
- Three-way match flags it only as an **ADVISORY** `price_variance`, which posts under the default enforcement: `apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:254-258` (`throw` only when `MatchEnforcement::Block`) and `apps/api/database/migrations/tenant/2026_06_25_100000_create_procurement_policies_table.php:36` `->default('warn')`.

So the brief's question — "would the 3-way match later accept it?" — answers **yes, under the shipped default**, with only an advisory flag. The lane trades a *visible* 64%-high price for an *invisible* 100%-low one on the same operator path.

**Consequence (b) — cross-document-type regression, not acknowledged anywhere in the lane.** `MoneyInput` emits `''` when the operator clears the cell:
- `apps/web/src/components/atoms/MoneyInput/MoneyInput.tsx:36-37` — `if (inputValue === '') { onChange('') }`
- wired at `apps/web/src/features/documents/components/DocumentLineEditor.tsx:785-796` (`onChange={(value) => handleUpdateLine(line.id, { unit_price: value })}`)

Pre-lane, that `''` reached the server and was rejected — `CreateDocumentRequest.php:124` `['required','numeric','min:0','regex:/^\d+(\.\d{1,3})?$/']`, `UpdateDocumentRequest.php:102` `['required_with:lines', …]`. Post-lane it is silently rewritten to `'0'`. An operator who clears a line price on a **sales invoice** now issues a zero-priced invoice line instead of getting a validation error. That blast radius is outside the lane's stated scope and outside its tests.

**Fix directive.** Split the two payloads: keep the blank normalisation (preferably `''`→`null`, which `AutoSaveDraftRequest.php:217` already accepts) on the autosave call at `DocumentForm.tsx:354` only; at `DocumentForm.tsx:561` pass `line.unit_price` through unchanged and **block submit** with an inline `FormField` error on the offending line ("enter the unit price") per `docs/conventions/06-FORMS.md`, so an unpriced purchase line cannot be saved or confirmed.

---

### 2. MAJOR — the new docblock asserts a redaction that the endpoint it documents does not perform

`apps/web/src/components/molecules/line-items/useProductLineLookup.ts:11-16`

> "Redacted to null by the API for callers lacking `pricing.view_cost_prices` (`ProductData::withoutCostFields`)…"

That hook calls exactly one endpoint — `/line-entry/resolve-code` (`useProductLineLookup.ts:83`) — and that endpoint **does not redact**:

- `apps/api/app/Modules/Product/Presentation/Controllers/LineEntryController.php:44-56` — returns `ProductData::fromModel($product, …)` verbatim, four times, with no `withoutCostFields()` call.
- Only the list endpoint redacts: `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:148-163` (`$redactCost = ! $user->can('pricing.view_cost_prices')`).

The two entry-bar paths therefore behave differently: typed search → `/products` (redacted, `LineItemEntryBar.tsx:73`); barcode scan → `/line-entry/resolve-code` (un-redacted, `LineItemEntryBar.tsx:65,170`). Handback §3's conclusion — "a purchaser without that permission lands on step 3 (EMPTY)" — holds only for the search path.

Worse, the lane makes the pre-existing leak **user-visible**: on the scan path the un-redacted `purchase_price` both pre-fills the cell and is printed by the new hint, `DocumentLineEditor.tsx:755-757` → "From purchase price 15.000".

**Fix directive.** Correct the docblock to say redaction is endpoint-dependent and that `/line-entry/resolve-code` currently does NOT redact; state that the FE treats an absent value as "operator types it" and that the scan path may therefore still expose cost. Ledger the server fix (see finding 8).

---

### 3. MAJOR — the "no purchase price on file" warning is dropped by the very edit it is meant to survive

`apps/web/src/features/documents/components/DocumentLineEditor.tsx:536-542` drops the provenance entry on **any** `unit_price` update, and `:752-757` renders the warning only from that add-time provenance state.

So: operator adds an unpriced product → sees the amber "No purchase price on file — enter the supplier price" → clicks in, types a digit, deletes it (or clears and tabs away) → the warning is gone, the field is blank, and finding 1's coercion turns it into `0.000` on save with no signal at all. The one affordance protecting the EMPTY design disappears exactly in the state it exists to flag.

**Fix directive.** Render the warning from current line state — `isPurchaseDocument && isBlankMoney(line.unit_price)` — instead of from `priceSourceByLineId`; keep provenance state only for the informational "came from purchase price" variant.

---

### 4. MINOR — the purchase discriminator is an untyped string set, not the canonical document-type union

`apps/web/src/features/documents/components/DocumentLineEditor.tsx:69`
```ts
const PURCHASE_DOCUMENT_TYPES: ReadonlySet<string> = new Set(['purchase_order'])
```
with the prop declared `documentType?: string` (`:225`). Two competing unions already exist: the hand-maintained FE one, `apps/web/src/features/documents/DocumentListPage.tsx:31` (7 members), and the generated one, `packages/shared/types/generated.d.ts:849` (13 members, rule 7 source of truth). Neither constrains the new set, so adding or renaming a purchase document type silently falls through to the **sale-price** branch — the exact defect this lane fixes.

Mitigating: the file already uses the same bare literal at `:321` (`documentType === 'purchase_order'`), so this is consistency with local precedent, not new drift.

**Fix directive.** Narrow the prop to `DocumentType` and declare `ReadonlySet<DocumentType>` so an unhandled purchase type is a compile error.

---

### 5. MINOR — the hint overstates an unenforced rule and duplicates a number already on screen (owner "one main element" / signal-overload rule)

`DocumentLineEditor.tsx:753-757` + `apps/web/src/locales/{en,fr,ar}/sales.json` → `lineItems.priceSource.*`

- `none`: "…— enter the supplier price" presents a requirement the system does not enforce (finding 1). Once submit is blocked, the copy becomes true; until then it is a guarantee the UI cannot keep.
- `productPurchasePrice`: "From purchase price **15.000**" prints the same figure that is already rendered in the adjacent `MoneyInput`, adding an amber/gray 11px band under every seeded purchase line. Per `docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md`, added accents that carry no new information compete with the screen's primary element.

**Fix directive.** Drop the `{{amount}}` echo from `productPurchasePrice` (or drop that variant entirely) and keep only the blank-price warning.

---

### 6. MINOR — money rendered unformatted, and the hint is not associated with its input

`DocumentLineEditor.tsx:756` interpolates `decimalValue(line.unit_price)` — a raw scale-3 string, no currency, no locale — while the component's own formatter `formatAmount` sits at `:619` and is used at `:763, :893, :974, :1009, :1054-1062, :1083`. Consistent with the adjacent legacy pricing hint (`:829-833` prints `pricingItem.cost_wac` raw), so it is not a regression, but new code should use `formatAmount`. Separately the hint is a bare `<div>` with no `aria-describedby` link to the `MoneyInput` at `:785`, so screen readers do not tie it to the field.

**Fix directive.** Use `formatAmount(...)` for the interpolated value and give the input an `aria-describedby` pointing at the hint node.

---

### 7. MINOR — the new payload branch ships untested

`DocumentForm.tsx:236` is production money-payload logic with no coverage. The two existing suites that exercise `buildLinePayload` were not extended: `apps/web/src/features/documents/DocumentForm.test.tsx:195` and `apps/web/src/features/documents/__tests__/DocumentForm.payload.test.ts:23`. The new test file covers only the editor default, not the wire value.

**Fix directive.** After finding 1 is split, add cases asserting the autosave payload accepts a blank price and the submit path refuses one.

---

### 8. MINOR — residuals confirmed for the ledger (out of lane, do not fix here)

1. **P2 authz leak — CONFIRMED.** `apps/api/app/Modules/Product/Presentation/Controllers/LineEntryController.php:44-56` returns full `ProductData` (`purchase_price`, `cost_price` — `ProductData.php:31-32,82-83`) with no `withoutCostFields()`, while `ProductController.php:148-163` redacts. Any authenticated user who can scan a barcode reads cost data without `pricing.view_cost_prices`. Pre-existing; **severity P2 (MAJOR authz), raised in practical impact by this lane** because the scanned cost is now both pre-filled and printed on screen (finding 2).
2. **Rule 19 — CONFIRMED.** `apps/web/src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx:224` `sale_price: parseFloat(data.sale_price)`, with the interface already typing money as a float at `:43` `sale_price: number`. Live path.
3. **Rule 19, additional (not in the handback).** `apps/web/src/features/documents/CreateReturnNotePage.tsx:178` and `:561` — `Number(line.unit_price)` on money.
4. **OQ-12 observation (not a lane defect).** `return-notes` is mounted twice — `apps/web/src/routes/index.tsx:807` under `sales` and `:1268` under `inventory` — both resolving to the same `CreateReturnNotePage` / `ReturnNoteDetailPage`. Customer-return vs supplier-return separation is not expressed at the route level. Pre-existing; flag for the owner ledger, not for this lane.

---

## What I verified as CORRECT (re-executed, not accepted)

- **Purchase default & precedence.** `resolveLineUnitPriceDefault` (`DocumentLineEditor.tsx:110-129`) returns `purchase_price` → `''`, never `sale_price`, for purchase docs; sale docs unchanged. `cost_price` (perpetual WAC) and `last_purchase_cost` (landed) correctly excluded — the docblock's reasoning matches `WeightedAverageCostService.php:291-294` and the matcher's basis at `SupplierInvoiceMatcher.php:547-569`.
- **Coverage of PO create AND edit.** One editor, one `DocumentForm` (`DocumentForm.tsx:784`), `documentTypeToApiEndpoint.purchase_order = '/purchase-orders'` (`:101`). The only other mount is `CreateCreditNotePage.tsx:628` (a sale doc). `apps/web/src/components/documents/DocumentLineEditor.tsx:2` is a re-export shim.
- **No other purchase surface seeds a sale price.** RFQ create defaults `'0.000'` (`QuoteRequestCreatePage.tsx:70`); RFQ→PO award carries the quoted price (`PurchaseQuoteRequestAwardService.php:136`); `StandaloneReceiptPage.tsx:177` formats an existing line price; `CreateReturnNotePage.tsx:275` copies the source line. `grep sale_price apps/web/src/features/purchases` → nothing.
- **Money stays a string on the price path.** No `parseFloat`/`Number` added anywhere in the diff. Blank values flow through `decimalValue` → `'0'` inside the bcmath helpers (`:131-160`), so `calculateLineTotal(1, '', '7.00')` yields `'0.000'`, never `NaN`.
- **Redaction type-safety (rule 3).** `purchase_price?: string | number | null` matches `ProductData.php:31` `?string`; `isBlankMoney` (`:105-107`) guards null/undefined/blank; no `any`, no unsafe assertion. The quick-create path (`DocumentLineEditor.tsx:1168-1176`) omits `purchase_price` entirely → EMPTY on a PO, which is correct.
- **i18n (rule 11).** `lineItems.priceSource.{productPurchasePrice,none}` present and correctly nested in **all three** locales (`en`, `fr`, `ar` — the only locale dirs). No hardcoded strings. RTL-safe logical property `text-end`.
- **Tokens (rule 18).** Touched lines use `textColors.warning` / `textColors.secondary` only. `audit:design-system` green; **the baseline file is not in the diff** — no `--write-baseline` absorption.

## Gates — re-run by me

| Gate | Result |
|---|---|
| `pnpm --filter @autoerp/web typecheck` | **EXIT 0** |
| `pnpm --filter @autoerp/web lint` | **EXIT 0** — `6446 problems (0 errors, 6446 warnings)`; the 8 rule/tool suites pass 160/160 (the alarming i18n text in the log is planted fixtures inside `audit-i18n-completeness.test.mjs`, not real gaps) |
| ESLint per-file vs the merged dev base `7ce702576` | Verified directly by linting `git show 7ce702576:` copies of both files: **DocumentLineEditor 4 → 2**, DocumentForm **13 → 13**, `useProductLineLookup` 0 → 0, new test 0. dev total = 6446 + 2 = **6448** — the claimed baseline checks out arithmetically |
| **Mechanism audit of the 4→2 improvement** | The two dropped warnings were both `react-hooks/preserve-manual-memoization` at the same site (`__devcopy_DLE.tsx:534:69`, the `lineColumns` useMemo). They disappear because the lane genuinely **added the two missing deps** (`isPurchaseDocument`, `priceSourceByLineId`). Diff grep for `eslint-disable` / `@ts-ignore` / `@ts-expect-error` / `biome-ignore` → **zero hits**. No alias tables, no renamed-equivalent literals. **Not evasion.** |
| `vitest run …DocumentLineEditor.purchasePriceDefault.test.tsx` | **6 passed (6)** |
| **Red-proof** (forced the resolver's sale branch on) | **5 failed / 1 passed** — identical to the handback's quoted RED block, including the sale-doc guard staying green |
| **Discrimination proof** (forced the purchase branch always-on) | **1 failed / 5 passed** — only *"still defaults a sale document to the sale price"* fails, so the sale-doc regression test really discriminates |
| Worktree after both tampers | `git status --porcelain` empty; `DocumentLineEditor.tsx` byte-identical to the pre-review copy. **Lane unmodified.** |
| `vitest run src/features/documents src/components/molecules/line-items` (default pool) | **53 files / 423 tests passed** |
| `vitest run src/features/purchases` (default pool) | **12 files / 109 tests passed** — 65/532 total, exactly the handback's claim, now verified rather than accepted |
| `tools/feature-lane-manifest-check.php` / deptrac ratchet | **N/A by construction, verified:** `git diff --name-only 7ce702576 HEAD -- apps/api` → **0 files**. The PHP tree is byte-identical to the merged dev base, so neither tool can move. (Cannot execute in this worktree: no `apps/api/vendor`.) |
| Live check on `http://localhost:5173` | **NOT PERFORMED — and not claimed.** The running Vite server does not serve this worktree: `curl http://localhost:5173/src/features/documents/components/DocumentLineEditor.tsx \| grep -c resolveLineUnitPriceDefault` → **0**. Any observation there would describe dev, not the lane |

## Must change before merge

1. **Finding 1 (BLOCKER)** — split autosave vs submit; block submit on a blank unit price with an inline `FormField` error. Add the payload tests from finding 7.
2. **Finding 2 (MAJOR)** — correct the false redaction docblock at `useProductLineLookup.ts:11-16`.
3. **Finding 3 (MAJOR)** — derive the blank-price warning from current line state, not add-time provenance.
4. Findings 4–6 are cheap and land in the same touch; 8 goes to the ledger untouched.

Re-gate required after the fix round (r2), scoped to `DocumentForm.tsx`, `DocumentLineEditor.tsx`, both payload suites and the new editor suite.
