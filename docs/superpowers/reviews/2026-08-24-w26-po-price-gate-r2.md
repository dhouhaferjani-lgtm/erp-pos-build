# W2-6 gate r2 — frontend-conventions lens (+ the new PHP authz redaction) — `fix/campaign-w26-po-price-default`

**Lane** W2-6 (P1) · **HEAD** `1a4e63bce` · **worktree** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w26-po-price`
**Fix round** red `62303f5e5` → fix `e71377702` → handback `2bc8cf76b` / `1a4e63bce`; dev merged at `d331c9a93` (base `813a19870`).
**Lane diff vs its dev base** `git diff --name-status 813a19870 HEAD` → **14 files** (5 web src + 3 locales + 2 web tests, 1 controller + 1 PHP test, ci.yml, manifest, handback). **No migration. No baseline file touched.**
**r1** `docs/superpowers/reviews/2026-08-24-w26-po-price-gate-r1.md` (REJECT: 1 blocker, 2 major, 5 minor).
**r2 tally** 2 MAJOR (1 merge-blocking) · 5 MINOR · 1 ledger item.
Every claim below was re-executed in this session or read at `file:line`. Nothing was taken from the handback.

## VERDICT: **APPROVE-WITH-FIXES** — conditions C1–C3 before merge

r1's BLOCKER is genuinely closed on the path it described: the two payloads are split, the submit payload passes a blank through untouched, `onSubmit` refuses it with an inline `role="alert"` + per-line flag on **every** document type, and the server 422 backstop is real (probed). Findings 2–7 are all closed, each with a test that I tamper-proved discriminates. The new PHP redaction is correct, on the right permission, and red-proved on PostgreSQL.

It does **not** merge as-is for one reason: **the guard is blind in TOTAL price-entry mode**, and one toggle click on a first-tenant purchase-order screen converts the lane's own protective blank into `0.000` with no warning — proven by execution below. Plus two claim-accuracy defects (handback residual 8 is false) and the manifest merge arithmetic, which is now stale against dev.

---

## Findings

### 1. MAJOR (must fix before merge) — the blank-price guard is blind in TOTAL entry mode: one toggle click rewrites `''` → `'0.000'` and silences the hint

`apps/web/src/features/documents/components/DocumentLineEditor.tsx:556-582` (`handleUpdateLine`), toggle at `:832-846`, cell at `:800-815`.

`handleUpdateLine` recomputes on `'price_entry_mode' in updates`, and the total branch does `updatedLine.unit_price = deriveUnitPrice(updatedLine, netTotal)` — `deriveUnitPrice` (`:345-372`) never returns blank; a blank net amount yields `'0.000'`. So an unpriced purchase line (the lane's step-3 EMPTY) becomes a **priced-at-zero** line the moment the operator presses the unit/total toggle, before typing anything.

Proven by execution (scratch probe rendered against the real component in a throwaway worktree, `hasModule → true` + `purchase_bonus_enabled: true`):

```
BEFORE TOGGLE: [{"p":"","m":"unit"}]
AFTER  TOGGLE: [{"p":"0.000","m":"total"}]
```

Consequences, all on the purchase screen this lane exists to fix:
- `findBlankPriceLineIds` (`apps/web/src/features/documents/linePayload.ts:153`) sees no blank → **submit proceeds**;
- `priceIsBlank` is false → the blank-price hint disappears → **no signal at all** (the r1-#3 affordance is gone in exactly this state);
- the TOTAL branch is a `DraftMoneyInput` that never receives `priceRefused` or `aria-describedby` (`:800-813` vs the unit branch at `:816-826`), so even a refusal could not be shown there.

Blast radius is **not** exotic: the toggle is gated on `purchaseBonusEnabled` (`:340-343` — `purchase_order` + `hasModule('PurchaseBonus')` + `purchase_bonus_enabled`), and `PurchaseBonus` is a **default module of the parapharmacy vertical** (`apps/api/config/verticals.php:344-361`) — the campaign tenant. This is not a regression introduced by the fix round (pre-lane, total-mode-with-cleared-amount also produced `0.000`), but the lane makes it materially more reachable by seeding blanks, and the code/handback assert a closure it does not have.

**Fix directive.** In `handleUpdateLine`, do not synthesise a price for a total-mode line whose net amount is blank — keep `unit_price` `''` (and `line_total` `''`) until the operator enters an amount; and extend `findBlankPriceLineIds` to refuse a total-mode line whose entered net amount is blank. Wire `error={isBlocked || priceRefused}` + the `aria-describedby` hint onto the `DraftMoneyInput` branch at `DocumentLineEditor.tsx:800-813`. Add the toggle case to `DocumentLineEditor.purchasePriceDefault.test.tsx`.

### 2. MAJOR — handback residual 8 is false: an autosaved `'0'` draft CAN still be submitted and confirmed; the guard cannot see it

`docs/superpowers/reviews/2026-08-24-w26-handback.md:336-338` claims the deliberate autosave coercion leaves a line that "can no longer be submitted or confirmed, only parked as a draft." Both halves are wrong:

- **Confirm.** `apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:155` posts `/purchase-orders/{id}/confirm`; `apps/api/app/Modules/Document/Domain/Services/PurchaseOrderService.php:47-68` checks only type and draft status — no zero-price/zero-total guard. A draft written by the autosave path is a real, listable, confirmable PO.
- **Submit.** Re-opening that draft loads the persisted price verbatim — `apps/web/src/features/documents/DocumentForm.tsx:363-374` (`unit_price: l.unit_price` → `'0.000'`). It is no longer blank, so `findBlankPriceLineIds` returns nothing and the form submits it.

Server-side probe (real Laravel validator, the exact `CreateDocumentRequest.php:124` rule array): `''` → `REJECTED: The lines.0.unit_price field is required.`; `null` → REJECTED; **`'0'` → ACCEPTED**; `'15.000'` → ACCEPTED. So the server backstop catches only the blank, never the coerced zero — which is precisely what the autosave writes.

**Fix directive.** Rewrite residual 8 to state what is true: the coercion converts "unpriced" into "priced at zero" at the persistence boundary, after which neither the client guard nor the server rule can distinguish it; and ledger a server-side refusal of a zero-priced/zero-total purchase-order `confirm()` (that is the only durable closure — an FE guard cannot cover the detail-page route).

### 3. MINOR (merge-blocking arithmetic) — the manifest raise is stale against current dev

`apps/api/tests/feature-lane-manifest.json` — lane sets `gated_ceiling` **1164 → 1165**, Product **57 → 58**. Current LOCAL `dev` (tip `faf822f8a`) is already at **`gated_ceiling` 1167**, Product **57** (N-6 raised Document 80→82 / gated 1164→1167 after this lane's dev merge). The union at merge is **`gated_ceiling` 1168, Product `classes` 58** — the JSON will conflict on the ceiling line and must not be resolved to 1165.

`git diff 813a19870 dev` over the lane's other files → only `apps/web/src/features/documents/components/CreditNoteDetail.tsx`; no other conflict expected.

**Fix directive.** Re-take the union at merge (`gated_ceiling: 1168`) and say so in the note, as the N-6 note does.

### 4. MINOR — `buildLinePayload` is still re-exported from the component file, defeating half the stated reason for the extraction

`apps/web/src/features/documents/DocumentForm.tsx:152` `export { buildLinePayload }` exists only so `DocumentForm.test.tsx:4` and `__tests__/DocumentForm.payload.test.ts:5` keep working; it is one of the file's two `react-refresh/only-export-components` warnings (`:141`, `:152`).

**Fix directive.** Point both suites at `../linePayload` and delete the re-export.

### 5. MINOR — the exhaustive map is over the hand-maintained union, not the generated one (rule 7)

`DocumentLineEditor.tsx:71-79` `IS_PURCHASE_DOCUMENT_TYPE: Record<DocumentType, boolean>` with `DocumentType` imported from `../DocumentListPage` (`DocumentListPage.tsx:30`, 7 members). The generated source of truth has 13 (`packages/shared/types/generated.d.ts:849`), including `supplier_invoice`, `supplier_credit_note`, `purchase_rfq`, `correcting_entry`. The exhaustiveness guarantee therefore holds only inside the local union — a new purchase type added to the generated union still falls through to the sale branch. Correctly filed as handback residual 6; the improvement over r1's `Set<string>` is real (`documentType` prop is now `DocumentType`, `DocumentForm.tsx:119` matches).

**Fix directive.** Ledger the union consolidation (`DocumentListPage.DocumentType` → generated `DocumentType`) as a follow-up; no in-lane change.

### 6. MINOR — duplicate alerts and no focus move on refusal

`DocumentForm.tsx:712-716` renders a form-level `role="alert"`, and each refused row renders its own `role="alert"` (`DocumentLineEditor.tsx:852-859`), so a screen reader hears N+1 messages; nothing focuses or scrolls to the first refused line on a long document.

**Fix directive.** Keep the form-level alert, drop `role="alert"` from the per-line hint (it is already `aria-describedby`-linked), and focus the first refused price input on block.

### 7. LEDGER — P2 residual re-confirmed and **reachable**, and it partly undercuts the fix in finding 2 of r1

`apps/api/app/Modules/Product/Presentation/Controllers/LineEntryController.php:74-157` (`bulkPricingContext`) returns `cost_wac`, `last_purchase_cost`, `suggested_price` and both margin percentages with no `pricing.view_cost_prices` check — only `abort(403)` when there is no user. Route middleware is `can:products.view` (`apps/api/app/Modules/Product/routes.php:49-51`). The FE calls it for every line as soon as a price cell is focused (`DocumentLineEditor.tsx:391-405`, `enabled: focusedPriceLineId !== null`) and **renders the figures on screen** (`:863-869` — `cost`, `lastBuy`, `margin`). No FE permission gate anywhere.

So on the very screen this lane just de-leaked, a `products.view`-only user still reads the product's perpetual WAC and landed last-purchase cost. **Severity for the ledger: MAJOR authz (P2 as filed is generous — the data is rendered, not merely returned).** Out of lane by scope; it needs the product decision the handback describes (gate it, or accept `products.view` as the bar).

Other residuals re-verified as still true and correctly filed: `AddQuickProductModal.tsx:224` `sale_price: parseFloat(...)` (rule 19), `CreateReturnNotePage.tsx:178,561` `Number(line.unit_price)`, `return-notes` double mount (`routes/index.tsx:807` sales + `:1268` inventory — OQ-12), no supplier-specific purchase price, `purchase_price = 0.000` treated as a real price. Adjacent rule-19 sites found while checking those citations, not previously filed: `CreateCreditNotePage.tsx:135-137` (`unit_price`/`tax_rate`/`line_total` all `parseFloat`ed on load) and `components/CreateCreditNoteForm.tsx:179,420`.

### 8. MINOR — "applies to every document type" is true of the `DocumentForm` mounts only; the second editor mount has no blank guard

`apps/web/src/features/documents/CreateCreditNotePage.tsx:628` mounts `DocumentLineEditor` with neither `documentType` nor `invalidLineIds`, and submits its own payload — so a price cleared there is not refused client-side (it falls back to the server rule, as before). Not a regression, but the handback's blanket wording (§Finding 1: "This applies to **every** document type") overstates the reach.

**Fix directive.** Narrow the wording to the `DocumentForm` create/edit path, or pass the same guard through the credit-note page.

---

## What I verified as CORRECT (re-executed, not accepted)

**r1 finding 1 (BLOCKER) — closed on its stated path.** `linePayload.ts:95-124` `buildLinePayload` passes `unit_price` through untouched; `:138-144` `buildAutoSaveLinePayload` is the only coercer and is called from exactly one site (`DocumentForm.tsx:257`, the `useDraftAutoSave` payload); `DocumentForm.tsx:454-465` refuses first and returns before any mutation; `:702-707` clears the stale flag on any edit; `:712-716` renders the `t()` alert. Grep over `apps/web/src` shows the only consumers of the three helpers are `DocumentForm.tsx` and the two payload suites — no third call site.

**Tamper proofs (all in a disposable `git worktree`; the lane worktree was never modified — `git status --porcelain` empty, HEAD `1a4e63bce`):**

| Tamper | Result |
|---|---|
| Delete the `findBlankPriceLineIds` refusal block from `onSubmit` | **2 failed** — "blocks a PURCHASE ORDER…", "blocks a SALES INVOICE…" |
| Restore `isBlank(...) ? '0' : …` inside `buildLinePayload` | **2 failed** — the two SUBMIT-payload assertions |
| Derive the hint from add-time provenance again (r1 #3 behaviour) | **2 failed** — "keeps warning after type-then-clear", "warns on a SALES line whose price the operator cleared" |
| Drop `withoutCostFields()` from `productPayload` + un-redact `cost_override` (PG) | **`Tests: 4, Assertions: 15, Failures: 3`** — `'10.000'`/`'10.000'`/`'8.000'` where `null` was required — byte-for-byte the handback's quoted RED |
| Un-redact **only** `cost_override` (PG) | **1 failed** — `'7.5000' is identical to null`; the variant assertion discriminates on its own |

**PHP authz redaction (r1 finding 2) — fixed at the server, correctly.** `LineEntryController.php:228-243`: `productPayload()` applies `ProductData::withoutCostFields()` (`ProductData.php:145-155` — nulls `purchase_price`, `cost_price`, `last_purchase_cost`, both margin overrides, `effective_margins`) when `! $user->can('pricing.view_cost_prices')`, on **all four** resolve branches (`:45,:54,:60,:65` → `:198`), plus `cost_override` on the variant (`:210`); `price_override` correctly survives (sale-side). `pricing.view_cost_prices` is the right permission — it is the same one `ProductController.php:152` and `:360` use, is seeded (`RolesAndPermissionsSeeder.php:90,631`; `PermissionSeeder.php:147,201`) and is the middleware on the pricing cost route (`Pricing/Presentation/routes.php:87`). **A holder still gets the fields** — `test_cost_price_holder_still_sees_costs_when_scanning_a_barcode` (admin role) passed in every run, including under both tampers. `useProductLineLookup.ts:10-22` now states the per-endpoint rule truthfully.

**PHP tests, executed by path — sqlite AND PostgreSQL 16:**
- sqlite: `ResolveLineEntryCodeCostRedactionTest` → **OK (4 tests, 23 assertions)**
- PG throwaway `autoerp_test_w26g` (127.0.0.1:5433, dropped after; 276 tables materialised, so PG really ran): `ResolveLineEntryCodeCostRedactionTest` + `ResolveLineEntryCodeTest` → **OK (8 tests, 42 assertions)**

**Frontend suites (default pool):** `DocumentForm.blankUnitPrice` + `DocumentLineEditor.purchasePriceDefault` → **20/20**. Broad `src/features/documents src/components/molecules/line-items src/features/purchases` → **66 files / 546 tests passed**, exactly the handback's figure.

**The `CancelInvoiceModal` flake is inherited/environmental, not the lane's.** My first broad run — with `pnpm lint` competing for CPU — produced **13 failures across 8 files**, all `Test timed out in 5000ms`; the same command with the machine idle is **66/66, 546/546**. The file is untouched by the lane (`git diff --name-status 813a19870 HEAD` has no `invoices/` entry), so it cannot be a lane regression; it is a load-sensitive-timeout class, worse than the handback disclosed (8 files, not 1) but the same cause.

**Gates re-run by me**

| Gate | Result |
|---|---|
| `pnpm --filter @autoerp/web typecheck` | **EXIT 0** |
| `pnpm --filter @autoerp/web lint` | **EXIT 0** — `6446 problems (0 errors, 6446 warnings)`; 8 rule/tool suites 160/160 (the i18n "failures" in the log are planted fixtures inside `audit-i18n-completeness.test.mjs`) |
| **Lint ratchet, measured per file** (lane files vs `git show dev:` copies linted side by side) | dev **17** (`DocumentForm` 13 + `DocumentLineEditor` 4 + `useProductLineLookup` 0) → lane **15** (`DocumentForm` 12 + `DocumentLineEditor` 2 + `linePayload` 1 + both new tests 0). **The handback's 17 → 15 is exact.** |
| **Mechanism audit of the improvement** | (a) `DocumentLineEditor` 4 → 2: the two `react-hooks/preserve-manual-memoization` at dev `:534` are gone because the deps were genuinely added (r1 established this; unchanged). (b) `DocumentForm` 13 → 12 **plus** `linePayload` 1: the moved warning is verbatim — same rule `@typescript-eslint/no-unsafe-type-assertion`, same expression `payload.free_quantity = line.free_quantity as string \| number` (dev `DocumentForm.tsx:243` → `linePayload.ts:118`). Diff grep for `eslint-disable` / `ts-ignore` / `ts-expect-error` / `phpstan-ignore` / `baseline` over `apps/web` + `apps/api` → **zero hits**. No alias table, no renamed-equivalent literal. **Not evasion.** |
| Baseline honesty | `apps/web/tools/audit-design-system-baseline.json`, `deptrac.baseline.json` — **not in the diff**. The only ratchet moved is the manifest, and it is a disclosed, justified +1 (see finding 3 for the arithmetic). |
| `php tools/feature-lane-manifest-check.php` (in-worktree) | **EXIT 0** — 1402 Feature classes / 74 groups; every `--filter` entry anchored and uniquely matched |
| `php tools/deptrac-ratchet.php` (in-worktree, with the PHP change) | **PASS — TOTAL 182/182, no boundary regression** |
| PHPStan level 8 on the changed controller + new test | **No errors** |
| `pint --test` on both PHP files | **pass** |
| `.github/workflows/ci.yml` | Single-line append of `ResolveLineEntryCodeCostRedactionTest` to the existing `backend-test-pgsql --filter`. **Minimal and justified** — the Product lane is parked behind `SELF_HOSTED_RUNNER_READY`, and the precedent (`CorrectingEntryEndpointTest`, `SupplierGoodsReturnNoteTest`, `TerminalClaimHardeningTest`) is real in the same allowlist. The manifest note records the removal condition. |
| i18n (rule 11) | `documents.errors.unitPriceRequired` and `lineItems.priceSource.{productPurchasePrice,none,required}` present at the **same paths** in all three locales (`en`/`fr`/`ar`) — parsed, not eyeballed; `{{amount}}` echo removed everywhere (r1 #5). Alert text is `t()`-driven; no hardcoded strings in the diff. |
| Tokens (rule 18) / owner UI rules | Touched lines use `textColors.error` / `.warning` / `.secondary` only; the hint stays in the existing 11px slot, no new band, no added accent — the amount echo removal reduces on-screen noise. No `hover:${token}` / `${token}/opacity` interpolation anywhere in the diff. |
| Money-as-string (rule 19) | No `parseFloat`/`Number` added; `unit_price` stays a string on both payloads; `MoneyInput` still the only editor. |

---

## Conditions before merge

- **C1 (finding 1)** — close the TOTAL-entry-mode hole and surface refusal on the `DraftMoneyInput`; add the toggle regression test.
- **C2 (finding 2)** — correct handback residual 8; ledger the server-side zero-price `confirm()` guard (the FE cannot close the detail-page route).
- **C3 (finding 3)** — resolve the manifest at merge to `gated_ceiling: 1168` / Product `classes: 58` and re-state the union in the note.
- Findings 4–6 and 8 are cheap and belong in the same touch; finding 7 goes to the ledger as MAJOR authz, untouched here.

Re-gate scope for r3: `DocumentLineEditor.tsx` (entry-mode branch), `linePayload.ts`, the two editor/payload suites, and the manifest arithmetic. The PHP half needs no re-gate — it is verified, red-proved and green on both engines.
