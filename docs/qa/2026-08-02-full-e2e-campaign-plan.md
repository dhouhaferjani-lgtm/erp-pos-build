# FULL E2E CAMPAIGN PLAN — web money/business flows — 2026-08-02

> **Status:** AUTHORED. This is a **dispatch source** for implementation agents, not an execution
> ledger. No case here has been run.
> **Baseline commit:** `dev @ f670d37bf` (treasury fix lane landed).
> **Supersedes for scoping purposes:** nothing. It **reconciles** and **extends**
> `docs/qa/2026-08-01-money-test-plan.md` (the T1/192-P0 plan) against
> `docs/sessions/MONEY-CAMPAIGN-RESULTS.md` (what actually ran) and the treasury behaviour that
> landed **after** most specs were authored.
> **Precision contract, priority scheme (P0/P1/P2), HAPPY/EDGE typing, evidence discipline, seeded
> fixtures, §Z POS scope, §Y last-gate:** unchanged — read `2026-08-01-money-test-plan.md` §0–§6.
> This document does not restate them.

## 0. What changed since the plan was authored

| # | Landed | Commits | Behaviour delta |
|---|---|---|---|
| D1 | Draft invoice totals include document-level taxes | `18e61a554` | Draft `tax_amount`/`total` now equal post-confirm for every **configured** rate. Stamp duty (`1.000` TND) present from Draft. |
| D2 | Edit form loads `document_date`; required-field errors surface; credit-note `reason` on the generic form | `4cfe0b0e2` | `/sales/*/:id/edit` is usable; `/sales/credit-notes/new` can succeed. |
| D3 | Line-based credit notes saveable (mode-aware zod resolver) | `33214df0c` | Line-Based mode POSTs. |
| D4 | W1b specs flipped to assert fixed behaviour | `391b45beb`, `72d26ea07` | `documents-*.spec.ts` are already post-fix. |
| D5 | **Partial refund** unwinds allocations pro-rata + reopens `balance_due` | `cae181d19` → narrowed by `1a61900d9` | `MTP-TRE-10` expected value is now `250.000`, not `0.000`. |
| D6 | **Reverse** atomically cancels a `Received` instrument (deadlock broken) | `f364c316f` → narrowed by `1a61900d9` | Only `reversePayment()`. Standalone `cancel()` unchanged (still fails closed). |
| D7 | **Refund / partial-refund FAIL CLOSED** on `Received`/`Deposited`/`Bounced` instruments | `1a61900d9` (review C1/C2/C3) | 422 — *not* the atomic-cancel path. This is a **narrower** contract than D6's first cut. |
| D8 | `withholding_rate` normalised at the HTTP boundary; **fraction 0–1**, number **or** string | `8cb66c1fd` (review C4/C5) | `0.0150` on gross `1000.000` ⇒ `withholding_amount 15.000`, `net 985.000`. Was a 500, then briefly 100× too small. |
| D9 | **Full refund** also reverts `DocumentStatus::Paid → Posted` and recomputes `balance_due` | `f670d37bf` (re-gate N1) | Landed **after** the spec flip — **no spec asserts it**. Aged receivables / re-allocation now see reopened invoices. |
| D10 | Reverse deletes the whole refund **lineage** (`original_payment_id` + `payment_type=refund`) | `1a61900d9` (review C6) | `balance_due` after reverse-following-partial can never exceed `total`. |

**Open tickets whose current-defective behaviour must be TRIPWIRED** (assert today's behaviour with
a `FINDING`/`TRIPWIRE` comment so the fix flips the test red and forces a deliberate update):

| T | Ticket | Tripwire target |
|---|---|---|
| **T-A** | `docs/superpowers/tickets/2026-08-02-confirm-zeroes-vat-unconfigured-rates.md` | `confirm()` silently zeroes VAT for a rate with no active `TaxConfiguration` (invoices, verified); quotes/orders may zero VAT **entirely** (code-read only). |
| **T-B** | `docs/superpowers/tickets/2026-08-02-credit-note-draft-stamp-and-scale4-totals.md` | Draft credit notes omit the `0.600` `STAMP_CREDIT_NOTE`; `CreditNoteService` formats at **scale 4** (`60.0000`) not currency scale. |
| **T-C** | `docs/superpowers/tickets/2026-08-02-treasury-fix-lane-minor-followups.md` (N4) | Reverse-after-partial-refund leaves an **orphan refund payment** (real movement + GL entry, no allocation). Also N2/N3, round-1 M3/M4. |
| **T-D** | **TopBar z-index — NOT YET TICKETED** (W1a defect 1) | `TopBar` user-menu and language dropdowns are `position:absolute` with no `z-index`, swallowed by `DashboardLayout`'s `<main className="relative">` (`DashboardLayout.tsx:57`). Sign-out and language switch unreachable by real mouse click. **Action for the orchestrator: file the ticket.** Tripwired today by `AUTH-09` + `MTP-I18N-04a`. |
| **T-E** | `2026-07-31-deptrac-36-edges.md` + treasury minors | Deptrac ratchet 98/FAIL on clean `dev`; `DeferredTenderGuardsTest::test_supplier_traite_keeps_cash_payment_shape_and_registers_outbound_instrument` red on `dev`. **Not e2e-testable; listed so no agent treats them as their own regression.** |

---

# A. SPEC RECONCILIATION LIST (highest priority)

17 files in `apps/web/e2e/money-campaign/`. **14 edits across 6 files.** Everything else is
verified current.

## A.0 Verification of the "already flipped" claim

**CONFIRMED.** `MTP-TRE-10`, `MTP-TRE-15` and `MTP-TRE-23` were flipped from defect-assertions to
fixed-behaviour assertions *inside the fix lane itself* (`git diff f364c316f~1..f670d37bf --
apps/web/e2e/money-campaign/` = 112 insertions / 74 deletions across the two treasury specs):

| Case | Old assertion | Current assertion | Matches landed code? |
|---|---|---|---|
| `MTP-TRE-10` | `balance_due === '0.000'` + "KNOWN FINDING" | `balance_due === '250.000'` + "FIXED (2026-08-02…)" comment | **YES** (D5) |
| `MTP-TRE-15` | `status === 500` + `createFromPayment` in body | `status === 201` + certificate `gross 1000.000` / `rate 0.0150` / `amount 15.000` / `net 985.000` | **YES** (D8) |
| `MTP-TRE-23` | one test, two-sided 422 deadlock repro | split into **23** (reason required), **23b** (reverse atomically cancels ⇒ `cancelled`), **23c** (standalone cancel still 422) | **YES** (D6) |

**No further edit is needed to those three assertions.** The gaps below are the *consequences* of
`f670d37bf` and `1a61900d9`, which landed after that flip.

## A.1 `treasury-payments.spec.ts` — 6 edits

| # | Case | Edit | Why |
|---|---|---|---|
| A1.1 | `MTP-TRE-09` | ADD `expect(invAAfter.data.status).toBe('posted')` and same for `invB`. Currently only `balance_due` is asserted. | D9 / review **N1**. `refundPayment()` now calls `recomputeDocumentBalances()`, reverting `Paid → Posted`. Untested. |
| A1.2 | `MTP-TRE-10` | ADD `expect(invCAfter.data.status).toBe('posted')` alongside the existing `balance_due === '250.000'`. | D9 — `unwindAllocationsProRata()` reverts status too; only the balance is asserted. |
| A1.3 | `MTP-TRE-11` | ADD status assertion on the reversed invoice (`posted`) next to the existing `balance_due` restore. | D9 — same recompute path (`PaymentRefundService.php:666`). |
| A1.4 | **NEW `MTP-TRE-78`** | Refund and partial-refund of a payment whose instrument is `received` must **both 422** (fail closed). Reference `1a61900d9`, review C1/C2/C3. | **D7 — currently ZERO web coverage.** Only backend `DeferredTenderGuardsTest.php:238-252,288-303` covers it. This is a money-leak guard (phantom repository outflow) and belongs in the web gate. |
| A1.5 | **NEW `MTP-TRE-79`** | Post `withholding_rate` as a JSON **number** (`0.0150`, unquoted) → 201 + identical certificate values to `MTP-TRE-15`. Also assert the 4 domain edges `5.0E-5`, `0.00015`, `1.5`, `-0.1` all 422. | D8 / review C4. The existing case sends a **string** only; the number payload is the exact shape that produced the round-1 `TypeError`. |
| A1.6 | **NEW `MTP-TRE-80` (TRIPWIRE T-C/N4)** | Partial-refund a payment, then reverse it. Assert `balance_due` restores to the pre-payment value AND assert the orphan refund child still exists (`payment_type=refund`, `status=completed`, zero allocations) with a `TRIPWIRE (N4)` comment. | Ticket `2026-08-02-treasury-fix-lane-minor-followups.md` N4. Flips when N4 is fixed. |

## A.2 `treasury-instruments.spec.ts` — 2 edits

| # | Case | Edit | Why |
|---|---|---|---|
| A2.1 | `MTP-TRE-23b` | ADD, after the `cancelled` assertion: the **payment** is `reversed` (already present) **and** the linked document's `status` is `posted` with `balance_due` restored. | D9 + D6 interaction — the atomic path's document effect is unasserted. |
| A2.2 | **NEW `MTP-TRE-81`** | `GET /payments/{id}/can-refund` returns `false` for a `received`-instrument payment, **and** the UI's Reverse button is present/enabled while Refund/Partial-Refund are hidden (`PaymentDetailPage.tsx:392,400,404-410`). | Review **I3** disposition: `canRefund` now matches refund behaviour, but Reverse is ungated — this asymmetry is deliberate and must be pinned so a future "tidy-up" doesn't hide Reverse. |

## A.3 `documents-credit-notes.spec.ts` — 3 edits

| # | Case | Edit | Why |
|---|---|---|---|
| A3.1 | `MTP-DOC-16` | ADD a `TRIPWIRE (T-B, finding 3)` comment on `expect(cn1.data.total).toBe('100.000')`: an amount-based **draft** credit note currently carries **no** `0.600` `STAMP_CREDIT_NOTE`. When T-B lands this becomes `100.600` and MTP-DOC-18's remaining-amount arithmetic shifts. | Ticket T-B explicitly names MTP-DOC-16/18 as the cases the fix must update. |
| A3.2 | `MTP-DOC-20`, `MTP-DOC-23` | Replace the `toBeCloseTo(95.2, 3)` / `toBeCloseTo(71.4, 3)` numeric comparisons with a **string** assertion of the current literal (`'95.2000'` / `'71.4000'` — confirm the exact rendering on first run) plus a `TRIPWIRE (T-B, finding 4)` comment. | The `toBeCloseTo` masks the scale-4 defect entirely — the fix would be invisible. A tripwire must be *falsifiable*. |
| A3.3 | `MTP-DOC-17` | Keep the `268.750` finding assertion; RE-WORD the comment: this is **designed behaviour under review**, not a defect that the W1b lane fixed. Cross-reference `MTP-DOC-19` (below). | Avoids a future agent "fixing" a passing tripwire. |
| A3.4 | **NEW `MTP-DOC-19`** (was BLOCKED — unresolved helper) | Confirm → post the credit note, then assert the source invoice's `balance_due` drops by the credit amount and status moves off `paid`/back to `posted` as applicable. Use the existing `confirmAndPostCreditNote` helper in `w1b-support.ts` (currently unused by any passing assertion — repair it). | Results ledger: BLOCKED on an unreliable helper, not on product behaviour. Now unblockable; it is the post-flow half of MTP-DOC-17. |

## A.4 `documents-tax.spec.ts` — 1 edit

| # | Case | Edit | Why |
|---|---|---|---|
| A4.1 | **NEW `MTP-TAX-13` (TRIPWIRE T-A)** | Create an invoice line with an explicit `tax_rate` that has **no active `TaxConfiguration`** for TN (e.g. `13.00`), record Draft `tax_amount`, then `confirm()` and assert the VAT is **silently zeroed** (total SHRINKS). `FINDING` comment citing `TaxCalculationService.php:104-157` + `InvoiceController::confirm()`. | Ticket T-A finding 1 is *verified by code + existing PHPUnit fixtures* but has **no e2e tripwire**. Money-affecting, pre-launch triage. |

## A.5 `documents-lifecycle.spec.ts` — 2 edits

| # | Case | Edit | Why |
|---|---|---|---|
| A5.1 | **NEW `MTP-DOC-06`** (was BLOCKED — time budget) | Quote → Sales Order → Invoice conversion chain: totals must be **byte-identical** at every hop. Additionally assert the **quote's** `tax_amount` after `confirm()` — `TRIPWIRE (T-A finding 2)`: if it is `0.000` on a 19% line, T-A finding 2 is CONFIRMED live and must be escalated. | Two birds: the plan's blocked conversion case, and the only cheap live verification of T-A finding 2 (currently code-read-only, severity unset). |
| A5.2 | **NEW `MTP-DOC-09`** (was BLOCKED — no UI path) | Drive the `RecordPaymentModal` multi-step per-line confirm to reach `Paid`; then attempt `Cancelled` and record that **no UI action exists** anywhere on the invoice detail page (assert absence, and that the API route either exists-and-403s or does not exist). | Ledger: "no UI path reached … time budget". `Paid` is reachable; `Cancelled` needs an explicit recorded verdict, not a blank. |

## A.6 `permissions.spec.ts` — 1 edit + 6 unblocks

| # | Case | Edit | Why |
|---|---|---|---|
| A6.1 | **NEW `MTP-PERM-15`** | A principal holding `payments.reverse` but **not** `instruments.cancel` successfully reverses a payment and thereby cancels its `received` instrument. Assert this **succeeds** with a `RULING` comment citing `PaymentRefundService.php:670-681`. | Review **I6**: documented privilege widening with *no permission-matrix test*. Must be pinned as intentional, or it will be "fixed" by accident. |
| A6.2 | `MTP-PERM-08` | UNBLOCK. Reuse the bank-statement fixture built by `e2e/smoke/treasury-phase5b-reconciliation.smoke.ts` (parser profile + upload + statement) so a **real** statement id exists; the route-model-binding 404 that made this a false pass disappears. | Ledger reason was "no `bank_statements` row exists". The fixture already exists elsewhere in the repo. |
| A6.3 | `MTP-PERM-09/10/11` | UNBLOCK. `RolesAndPermissionsSeeder.php` **already defines** `accountant` (`:717`), `viewer` (`:620`), `technician` (`:658`), `operator` (`:683`). Only the *user accounts* are missing from `DemoPharmacySeeder`. Add 4 users, drop the `test.skip`s. | Ledger reason was "credentials not provisioned" — a fixture gap, not a product gap. |
| A6.4 | `MTP-PERM-13` | UNBLOCK. The document-authoring schema is now established (`w1b-support.ts` `addProductLines` + `createInvoice`). Author a line discount > `cashier.max_discount_percent = 10.00` as `cashier@pharmabio.tn`; enforcement point `DiscountPolicyDocumentValidator`. | Ledger reason was "schema not established within budget". |
| A6.5 | `MTP-PERM-12` | REMAINS BLOCKED for a scoped agent. Requires a tenant-wide permission reseed **without** `permission:cache-reset` on shared infra. **Orchestrator-run, isolated, no sibling agents.** See §C. | Tenant-blind Spatie cache (`project_spatie_permission_cache_tenant_blind`). |
| A6.6 | `MTP-PERM-14` | REMAINS BLOCKED unless the campaign tenant set is widened to `cafe-tunis`. See §C. | |

## A.7 `i18n-fr.spec.ts` — 1 edit

| # | Case | Edit | Why |
|---|---|---|---|
| A7.1 | `MTP-I18N-05` | UNBLOCK. A negative money figure is now authorable **without the device**: a partial refund writes a negative-amount `Payment` row (D5) and reverse-after-partial leaves an orphan negative payment (T-C/N4). Render it under `?lang=fr` and assert the sign renders as `-1 234,000` (or the locale's minus form), never `(1 234,000)` mixed with a bare `-`. | Ledger reason was "no negative `documents.total` exists anywhere in this tenant" — true for documents, no longer true for payments. |
| A7.2 | `MTP-I18N-04b` | Keep the PASS + the recorded note. **Orchestrator ruling needed:** the plan's literal wording ("separators switch with UI language") is wrong for this app — TND always formats fr-TN via `currencyMeta.ts`. Amend the plan text or accept the note permanently. | Ledger flagged it for the plan author; unresolved. |

## A.8 Files verified CURRENT — no edit required

`auth-session.spec.ts` · `documents-totals.spec.ts` · `documents-discounts.spec.ts` ·
`empty-states.spec.ts` · `purchasing-matching.spec.ts` · `helpers.ts` · `w1b-support.ts` ·
`w2b-support.ts` · `treasury-support.ts`.

- `documents-totals.spec.ts` already asserts the **post-D1** values (`43.750` / `268.750` at Draft
  *and* post-confirm, with "confirming must NOT move the numbers").
- `purchasing-matching.spec.ts` is post-`b967dc133` (all three spec-side bugs fixed, PUR-11
  `exception`-not-`quantity_variance` correction applied).
- `helpers.ts` `logout()` deliberately routes around T-D via `POST /auth/logout` — **do not
  "fix" it** until T-D lands.

---

# B. COVERAGE MAP — every end-to-end flow, grounded in the real route surface

Enumerated from `apps/web/src/routes/index.tsx` (63 feature dirs, ~200 routes).
**112 flows.** Legend: **C** = covered by an existing case · **G** = GAP (no coverage, new case IDs
proposed) · **B** = BLOCKED-BY (infra/fixture debt, see §C).
New case IDs continue each surface's existing range; new surface codes are introduced where the
2026-08-01 plan had none.

## B.1 Sales documents — `/sales/*`, `/inventory/{delivery,return}-notes` (16 flows)

| # | Flow | Route(s) | Covered by | GAP | BLOCKED-BY |
|---|---|---|---|---|---|
| 1 | Quote create → confirm (totals, VAT) | `/sales/quotes*` | — | **G** `MTP-DOC-25..27` (+ T-A tripwire, see A5.1) | — |
| 2 | Quote → Sales Order conversion | `/sales/orders*` | — | **G** `MTP-DOC-06` (A5.1) | — |
| 3 | Order → Invoice conversion; chain byte-identity | `/sales/invoices*` | — | **G** `MTP-DOC-06` (A5.1) | — |
| 4 | Invoice create → Draft totals (2-line 19%) | `/sales/invoices/new` | **C** DOC-01/02 | — | — |
| 5 | Invoice truncation vector + mixed-rate decomposition | " | **C** DOC-03/04/05 | — | — |
| 6 | Invoice edit (Draft & Confirmed) recompute | `/sales/invoices/:id/edit` | **C** DOC-07/10 | — | — |
| 7 | Posted-invoice immutability | " | **C** DOC-08 | — | — |
| 8 | Invoice → Paid / Cancelled lifecycle | `/sales/invoices/:id` | — | **G** `MTP-DOC-09` (A5.2) | — |
| 9 | Line money/qty validation ceilings (3dp / 4dp / negative / zero) | " | **C** DOC-11/12/13/14 | — | — |
| 10 | Fractional-unit qty display precision (`units.decimal_places`) | " | — | **G** `MTP-DOC-15` + `MTP-UOM-01..04` | needs a 3-dp-unit product fixture |
| 11 | Credit note — amount-based, over-credit guard | inline modal | **C** DOC-16/18 (+ T-B tripwire A3.1) | — | — |
| 12 | Credit note — line-based | " | **C** DOC-20 (+ T-B tripwire A3.2) | — | — |
| 13 | Credit note — standalone `/new` and `/create` | `/sales/credit-notes/{new,create}` | **C** DOC-22/23/23b | — | — |
| 14 | Credit note confirm → **post** → invoice balance allocation | " | **C**(partial) DOC-17 | **G** `MTP-DOC-19` (A3.4) | — |
| 15 | Credit note partner mismatch refusal | " | **C** DOC-24 | — | — |
| 16 | **Return notes** (sales + inventory), delivery notes, DN consolidation | `/sales/return-notes*`, `/inventory/delivery-notes*` | — | **G** `MTP-RET-01..08` (new surface `RET`) | — |
| — | `stamp_duty_amount` not exposed by `DocumentData`/FE type | — | — | — | **B** DOC-21 — unverifiable from web; needs DB access or a DTO field |

**Edge cases owed on this surface:** currency scale-3 truncation at every write boundary (covered
DOC-03); qty scale-4 ceiling (DOC-12); permission denial on `sales.create` / `invoices.update`
(**G** `MTP-PERM-16..18`); i18n money render on document detail (**G** `MTP-I18N-09`); empty
document list (**C**-adjacent, `documents.spec.ts`); **double-submit of Confirm/Post** (**G**
`MTP-IDEM-01..03`, new surface `IDEM`); **concurrent edit of the same invoice** (**G** CONC-01,
never run).

## B.2 Purchasing — `/purchases/*` (11 flows)

| # | Flow | Route(s) | Covered by | GAP | BLOCKED-BY |
|---|---|---|---|---|---|
| 17 | Supplier create/detail | `/purchases/suppliers*` | implicit in `w2b-support.ts` | **G** `MTP-PUR-28` (supplier payable balance render) | — |
| 18 | RFQ → quote-request comparison → PO | `/purchases/quote-requests*` | — | **G** `MTP-RFQ-01..06` (new surface `RFQ`) | — |
| 19 | PO create + totals (qty10 @ 12.500, 19%) | `/purchases/orders*` | **C** PUR-01 | — | — |
| 20 | Goods receipt (linked) → stock posted | `/purchases/receipts` | **C** PUR-02 | — | — |
| 21 | Goods receipt **standalone** | `/purchases/receipts/new` | — | **G** `MTP-PUR-29..30` | `goods-receipt.create-standalone` perm |
| 22 | Supplier invoice + 3-way matching (7 variance vectors) | `/purchases/supplier-invoices*` | **C** PUR-03..08 | — | — |
| 23 | Match enforcement `block` vs `warn`; hard-block over-clear | " | **C** PUR-09/10/11 | — | — |
| 24 | Bonus / free-goods lines | " | — | **G** PUR-14/15/16 (authored, never run) | — |
| 25 | Additional costs → landed-cost allocation | `/purchases/orders/:id` | partial (`e2e/purchasing/additional-costs.spec.ts`) | **G** PUR-17..22 (money assertions) | — |
| 26 | Receipt price override + validation ceilings | " | — | **G** PUR-23..27 | — |
| 27 | **Scan/OCR ingestion → supplier invoice** | `/purchases/scans*` | — | **G** `MTP-SCN-01..06` (new surface `SCN`) | needs a scan fixture file; ties to `project_scan_vat_configuration` |
| — | Unlinked-line `exception` branch | — | — | — | **B** PUR-12 — unreachable through the documented API |
| — | Cross-company receipt reference | — | — | — | **B** PUR-13 — single-company tenant |

## B.3 Inventory / stock valuation — `/inventory/*`, `/channels/*` (13 flows)

| # | Flow | Route(s) | Covered by | GAP | BLOCKED-BY |
|---|---|---|---|---|---|
| 28 | WAC recompute on receipt (up-cost / down-cost) | `/inventory/products/:id` | — | **G** INV-01/02/03 (authored, never run) | — |
| 29 | WAC guard when `newCompanyQty ≤ 0` | " | — | **G** INV-04/05 | — |
| 30 | Negative-stock / over-consumption refusal | " | — | **G** INV-06/07 | — |
| 31 | Stock movements ledger + entry/exit notes | `/inventory/movements`, `/inventory/entry-exit-notes` | — | **G** INV-08 + `MTP-INV-25..27` | — |
| 32 | Stock transfer carrying `transfer_cost` | `/inventory/stock-transfers*` | — | **G** INV-09 | — |
| 33 | Batches / expiry write-off valuation | `/inventory/batches*`, `/inventory/expiry-write-off` | — | **G** INV-21/22 | `ModuleGuard(BatchExpiry)` must be on |
| 34 | Inventory counting → discrepancy report → apply | `/inventory/counting/*` | — | **G** INV-16..20 | — |
| 35 | Opening balances — INVENTORY wizard (idempotency, lock) | `/settings/opening-balances/INVENTORY` | — | **G** INV-10..15 | — |
| 36 | Opening balances — **other types** (AR / AP / GL) | `/settings/opening-balances/:type` | — | **G** `MTP-OPB-01..06` (new surface `OPB`) | enumerate the real `:type` values first |
| 37 | Stock-by-location + location-scoped valuation | `/inventory/stock-by-location` | — | **G** INV-23/24, MLC-07 | — |
| 38 | Replenishment queue → capture → PO | `/inventory/replenishment*` | — | **G** `MTP-REP-01..04` (new surface `REP`) | — |
| 39 | **Channels / e-commerce order → sales document** | `/channels/*`, `/ecommerce/orders` | — | **G** `MTP-CHN-01..05` (new surface `CHN`) | needs a channel fixture; likely out of launch gate (P2) |
| 40 | Bulk import of money-bearing data (prices, opening stock) | `/settings/import*` | — | **G** `MTP-IMP-01..05` (new surface `IMP`) | needs CSV fixtures |

## B.4 Products / pricing / promotions (9 flows)

| # | Flow | Route(s) | Covered by | GAP | BLOCKED-BY |
|---|---|---|---|---|---|
| 41 | Price list CRUD + items + partner assignment | `/pricing/price-lists*` | — | **G** PRC-01/02 | — |
| 42 | Price resolution precedence (assigned vs default vs variant) | quote form | — | **G** PRC-03/04/05/07/08 | — |
| 43 | Quantity breaks (`min_quantity`/`max_quantity`) | " | — | **G** PRC-06 | — |
| 44 | Margin indicators green/yellow/orange/red | doc line editor | partial (`e2e/sales/margin-warnings.spec.ts`, `e2e/products/pricing-card.spec.ts`) | **G** PRC-12..16, PRC-18 (exact-money assertions) | — |
| 45 | Below-cost block for unauthorized principal | " | **C** PERM-07 + `margin-warnings.spec.ts` | **G** PRC-17 | — |
| 46 | Cost-price / stock-value visibility gating | `/inventory/products/:id` | **C** PERM-06 | **G** PRC-09/10/11, INV-01 | — |
| 47 | **Promotions** admin → effect on a document/receipt | `/pos/promotions*` | — | **G** DSC-07..12 | **B** promotions admin setup never attempted |
| 48 | **Coupons** admin → redemption money leg | `/pos/coupons*` | — | **G** DSC-13..16 | **B** as above |
| 49 | **Vouchers** issue → redeem money leg | `/pos/vouchers*` | — | **G** DSC-17/18 + `MTP-VCH-01..03` | **B** as above; voucher issue may need device |
| — | `discount_amount` (absolute) line discount | — | — | — | **B** DSC-02/03/04 — **no UI path exists**; needs an API-layer case or a UI affordance |

## B.5 Treasury — `/treasury/*`, `/expenses/*`, `/income/*` (22 flows)

| # | Flow | Route(s) | Covered by | GAP | BLOCKED-BY |
|---|---|---|---|---|---|
| 50 | Payment create + multi-document allocation | `/treasury/payments*` | **C** TRE-01/03/04 | — | — |
| 51 | Over-allocation & partner-mismatch refusals | " | **C** TRE-02/05 | — | — |
| 52 | Amount validation (min 0.01, 3dp, negative) | " | **C** TRE-07/08 | — | — |
| 53 | **Full refund** → balances + **status revert** | " | **C** TRE-09 | **G** status assertion (A1.1) | — |
| 54 | **Partial refund** → pro-rata unwind + status revert + Σ cap | " | **C** TRE-10 | **G** status assertion (A1.2) | — |
| 55 | **Reverse** (distinct terminal status, lineage delete) | " | **C** TRE-11 | **G** status (A1.3) + `MTP-TRE-80` orphan tripwire (A1.6) | — |
| 56 | **Refund/partial fail closed on uncleared instrument** | " | — | **G** `MTP-TRE-78` (A1.4) | — |
| 57 | Refund/reverse permission denials | " | **C** TRE-12, PERM-01/02/03 | **G** `MTP-PERM-15` privilege widening (A6.1) | — |
| 58 | `payments.void` / refund-prepayment | " | **C** TRE-13/14 | — | — |
| 59 | Withholding on payment → certificate values | " | **C** TRE-15/16 | **G** `MTP-TRE-79` number payload (A1.5) | — |
| 60 | Instrument receive (cheque / effet, maturity) | `/treasury/instruments*` | **C** TRE-17/18 | — | — |
| 61 | Remit → clear (fee + VAT, bank delta) | " | **C** TRE-19 | — | — |
| 62 | Bounce + 3 routings | " | **C** TRE-20/21 | — | — |
| 63 | Custody transfer | " | **C** TRE-22 | — | — |
| 64 | Instrument cancel — atomic vs standalone | " | **C** TRE-23/23b/23c | **G** doc-status assertion (A2.1) + `MTP-TRE-81` canRefund/UI (A2.2) | — |
| 65 | Terminal-state / dormant-status / fee-ceiling / perms | " | **C** TRE-24/25/26/27 | — | — |
| 66 | **Remittances (bordereaux)** — build, totals, remit, mixed-kind refusal | `/treasury/remittances*` | — | **G** TRE-28..35 | — |
| 67 | **Bank statement import** — profile, upload, dedupe, formats | `/treasury/statements` | partial (`smoke/treasury-phase5b-reconciliation.smoke.ts`) | **G** TRE-36..40 | fixture reuse, §C-2 |
| 68 | **Reconciliation workspace** — suggestions, tiers, create-from-line, ignore, complete, reopen | `/treasury/statements/:id` | partial (same smoke) | **G** TRE-41..49 | §C-2 |
| 69 | Repositories — transfer, adjustment (reason codes), movements | `/treasury/repositories*` | partial (`smoke/treasury-spine.smoke.ts`) | **G** TRE-50..59 | — |
| 70 | Payment methods + GL routing | `/treasury/payment-methods` | — | **G** PMT-01..05 | card routing needs `treasury:configure-method-routing` |
| 71 | **Expenses** — create/post/pay/reverse, instrument pay, double-pay idempotency, validation | `/expenses/*` | **C**(perm only) PERM-05 | **G** TRE-60..75 | — |
| 72 | **Income** — post + pay | `/income/*` | — | **G** TRE-76/77 | — |
| — | AP-side allocation (supplier invoice) | — | — | — | **B** TRE-06 `test.fixme` — needs a full PO→receipt→supplier-invoice chain; **unblockable now** (W2a built exactly that in `w2b-support.ts`) |
| 73 | Withholding certificates list/detail + sales withholding tracking | `/treasury/withholding-certificates*`, `/treasury/sales-withholding-tracking` | — | **G** WHT-01..05 | — |
| 74 | **Partner deposits** | `/sales/customers/:id` (deposit recording) | — | **G** `MTP-DEP-01..03` (new surface `DEP`) | — |

## B.6 Finance / GL — `/finance/*` (11 flows)

| # | Flow | Route(s) | Covered by | GAP | BLOCKED-BY |
|---|---|---|---|---|---|
| 75 | Journal entry create / balance guard / validation ceilings | `/finance/journal-entries*` | **C**(perm only) PERM-04 | **G** GL-01..07 | — |
| 76 | Trial balance (+ period, + i18n) | `/finance/trial-balance` | **C** I18N-01/02/07 | **G** GL-08/09/16 | — |
| 77 | Aged receivables — **now includes reopened invoices** | `/finance/aged-receivables` | **C**(empty) EMPTY-06 | **G** GL-10/12/13/14 + **NEW `MTP-GL-26`**: after a full refund (D9) the reopened invoice **appears** in aged AR | — |
| 78 | Aged payables | `/finance/aged-payables` | — | **G** GL-11 | — |
| 79 | Balance sheet / P&L / ledger / CoA | `/finance/{balance-sheet,profit-loss,ledger,chart-of-accounts}` | — | **G** GL-15/17/18/19/20 | — |
| 80 | Finance route permission denials | all `/finance/*` | **C** PERM-04 | **G** GL-21/22/23 | — |
| 81 | Cross-tenant / cross-company finance isolation | " | — | **G** GL-24/25, ISO-03/06 | **B** no Tenant B credentials |
| 82 | Cash movements report (+ empty/future range) | `/finance/cash-movements` | **C** EMPTY-04/08 | **G** MLC-08 | — |
| 83 | Treasury overview / finance hub | `/finance/overview`, `/finance` | partial (`smoke/treasury-spine.smoke.ts` step 6) | **G** `MTP-GL-27..28` | — |
| 84 | **VAT periods + VAT report** | `/finance/vat-periods`, `/finance/vat-report/:id` | — | **G** TAX-07/09/10/11/12 | **B** shared-tenant safety — VAT period is a global fiscal object |
| 85 | Tax status / rate / stamp config changes propagating to documents | `/settings/tax` | **C** CFG-01(partial via seeded data) | **G** CFG-02..09, TAX-02/03/06 | **B** shared-tenant safety — country-scoped global reference data |

## B.7 POS back-office (web-reachable) — `/pos/*`, `/reports` (13 flows)

> All of these need **device-authored** data (§0.4 of the plan: no seeder creates `pos_shifts` /
> `pos_receipts`; web POS mutation is demo-tenant-gated). They are **web-testable but
> device-blocked**.

| # | Flow | Route(s) | Covered by | GAP | BLOCKED-BY |
|---|---|---|---|---|---|
| 86 | Z-report list / detail / VAT & payment breakdown / identity | `/pos/z-reports*` | **C**(empty only) EMPTY-02 | **G** ZRP-01..13, 16..18 | **B** device fixtures (§Z) |
| 87 | Z chain-verify + PDF | " | — | **G** ZRP-14/15 | **B** device |
| 88 | Shift history / shifts dashboard / X report | `/pos/shift-history`, `/pos/shifts` | **C**(empty only) EMPTY-03 | **G** SHF-01..10 | **B** device |
| 89 | Expected cash / counted / variance | `/pos/shifts` | — | **G** CASH-01..12 | **B** device + known ticket (blind expected-cash) |
| 90 | POS analytics — receipt_type-aware aggregates | `/pos/analytics` | — | **G** AGG-01..09 | **B** device (mixed legacy/v4 window) |
| 91 | Owner reports dashboard tiles + aggregate consumers | `/reports` | **C**(empty only) EMPTY-01 | **G** AGG-10..17, OWN-01..07 | **B** device |
| 92 | POS refund policy configuration | `/settings/pos-refund-policies` | — | **G** RFP-01..12 | — (web-only; **unblockable now**) |
| 93 | **Fraud settings (M2/M3 ceilings)** | `/settings/compliance/fraud-settings` | — | **G** `MTP-FRD-01..06` (new surface `FRD`) | web-editable; device enforcement is §Z |
| 94 | Refund observation / dead-letters / compensations (API) | API only | — | **G** RFD-01..18 | **B** device + v4 enablement state |
| 95 | v4 refund enablement CLI (2-phase) | console | — | **G** RFD-19..23 | **B** ops command, not web |
| 96 | Web-POS demo-only gate (403) | `/pos/*` mutations | — | **G** GATE-01..06 | — (**unblockable now**) |
| 97 | Training-mode money isolation | " | — | **G** TRN-01..04 | **B** device + open ticket (untrained-gated treasury legs) |
| 98 | Loyalty programs / members / adjust / redeem money legs | `/pos/loyalty/*` | — | **G** LOY-01..16 | **B** partially device (earn on sale); admin adjust is web-only |
| 99 | Terminals management; compliance export; quarantine resolution | `/pos/terminals`, `/settings/compliance/*` | — | **G** `MTP-CMP-01..05` (new surface `CMP`) | — |

## B.8 Settings & cross-cutting (11 flows)

| # | Flow | Route(s) | Covered by | GAP | BLOCKED-BY |
|---|---|---|---|---|---|
| 100 | Auth / session / logout / reload persistence | `/login` | **C** AUTH-01..08 | — | — |
| 101 | TopBar user menu + language switcher | all pages | **C**(tripwire) AUTH-09, I18N-04a | — | **T-D open, unticketed** |
| 102 | Permission-denied money mutations | all | **C** PERM-01..07 | **G** PERM-08..15 (A6) | see §C |
| 103 | Cross-tenant isolation | all | — | **G** ISO-01..07 | **B** no Tenant B credentials |
| 104 | Cross-company switching | all | — | **G** GL-25, ISO-06, CFG-08 | **B** single-company tenant |
| 105 | Multi-location money scoping | `/reports`, `/finance/*`, `/inventory/*` | — | **G** MLC-01..08 | 4 POS shops exist in `DemoPharmacySeeder` — **unblockable** except the POS-data half |
| 106 | i18n money rendering (fr / ar / NBSP / MoneyInput) | all | **C** I18N-01/02/04b/06/07/08 | **G** I18N-05 (A7.1), `MTP-I18N-09..11` | **B** I18N-03 (EUR company not seeded) |
| 107 | Empty / zero states across reports | all reports | **C** EMPTY-01..08 | **G** `MTP-EMPTY-09..12` (remittances, instruments, statements-detail, VAT periods) | — |
| 108 | **Concurrency / stale edits** | doc + payment + expense | — | **G** CONC-01..06 | needs 2 browser contexts; harness pattern absent |
| 109 | **Idempotency / double-submit** | invoice confirm/post, expense pay, payment create, credit-note post | partial (TRE-63 authored) | **G** `MTP-IDEM-01..08` (new surface `IDEM`) | — |
| 110 | Units of measure decimal_places → display precision | `/settings/units` + every qty cell | — | **G** `MTP-UOM-01..04` | needs a 3-dp-unit product |
| 111 | Company money settings / setup checklist | `/settings/company`, `/settings/setup` | — | **G** `MTP-CFG-13..16` | `/settings/setup` has **no `RequirePermission`** — verify intentional |
| 112 | **Central admin billing** (super-admin) | `/admin/billing/*` | — | **G** `MTP-ADM-01..06` (new surface `ADM`) | **B** no super-admin credential; central DB, separate auth |

## B.9 Summary counts

| Metric | Count |
|---|---:|
| Flows enumerated | **112** |
| Flows with at least partial existing coverage | 30 |
| Flows that are pure GAPs (no coverage at all) | **74** |
| Flows blocked by infra/fixture debt (subset of the above) | 34 |
| Existing money-campaign case IDs already dispositioned | 134 (54 §B + 40 W1a + 27 W2b + 13 W2a) |
| Plan cases authored but **never executed** | 293 of 427 |
| New case IDs proposed here | ~118 across 14 new/extended surfaces |
| Spec files needing edits | **6 of 17** |
| Discrete spec edits | **14** (5 assertion additions, 9 new/unblocked cases) |

---

# C. COVERAGE DEBT BURN-DOWN

Every BLOCKED reason from `MONEY-CAMPAIGN-RESULTS.md`, with the infrastructure that unblocks it.
Effort: **S** ≤ 2h · **M** ≤ 1 day · **L** > 1 day.

| # | BLOCKED reason (cases) | Unblocking infrastructure | Effort | Notes |
|---|---|---|---|---|
| C-1 | **Shared-tenant safety** — TAX-02/03/06/07/09..12, CFG-08/09 (10 cases). Flipping `tax_status`, deactivating a **country-scoped** `STAMP_TAX_INVOICE`, changing the shared 19% rate, or closing a VAT period corrupts sibling agents' data. | **A dedicated isolated tenant** (`money-campaign-tax-tn`) seeded from `DemoPharmacySeeder`, used by **one agent at a time**, never concurrently with any other leg. Tax configs are `country_code`-scoped, so this tenant must be the **only** TN tenant live during the leg — or the leg runs on its own database. | **M** | The cleanest form is a second local API instance + DB. Cheaper form: schedule the TAX leg as a **solo wave** (see §E, W-X). |
| C-2 | **No `bank_statements` row** — PERM-08, TRE-36..49 (15 cases). Route-model binding resolves before the `can:` middleware, so a fabricated UUID 404s = false pass. | **Reuse `e2e/smoke/treasury-phase5b-reconciliation.smoke.ts`** — it already creates the parser profile, uploads a real statement, and builds Tier 1/3/4 candidates. Extract its setup into `money-campaign/statement-support.ts`. | **S** | Highest value-per-hour item in this table. |
| C-3 | **Roles not provisioned** — PERM-09/10/11 (3 cases). | Add 4 users to `DemoPharmacySeeder`: `accountant@`, `viewer@`, `technician@`, `operator@pharmabio.tn`. The **roles already exist** in `RolesAndPermissionsSeeder.php` (`:717`, `:620`, `:658`, `:683`). | **S** | Pure seeder edit. |
| C-4 | **No second tenant / company** — ISO-01..07, GL-24/25, PUR-13, CFG-08, MLC cross-company, LOY-15 (≈16 cases). | `TwoTenantIsolationDemoSeeder` (`demo-tenant-a` / `demo-tenant-b`) already exists and requires `TENANCY_DB_PER_TENANT=true`. Provision **credentials for both** to the campaign, and seed a *little* money data into Tenant B (currently bare by design). | **M** | Also unblocks the `MTP-ISO-05` cross-tenant replay case, which is a P0 security gate. |
| C-5 | **Promotions / coupons / vouchers never attempted** — DSC-07..18 (12 cases). | Admin setup fixture: create one promotion, one coupon, one voucher via `/pos/promotions`, `/pos/coupons`, `/pos/vouchers` (or their APIs) in a `promotions-support.ts`. Redemption money legs may need a POS receipt ⇒ partly device-dependent. | **M** | Split: admin-config assertions are web-only (**S**); redemption money legs are §Z-dependent. |
| C-6 | **No UI path for `discount_amount`** — DSC-02/03/04 (3 cases). | Either (a) drive the API layer directly with `apiRequest` (the W2b/W2a house pattern) to author absolute-amount line discounts, or (b) file a UX ticket for the missing control. **(a) is the test fix; (b) is the product question.** | **S** (a) | The plan's "percent-vs-amount precedence" is a real backend rule that must be covered regardless of UI. |
| C-7 | **Permission-cache reseed on shared infra** — PERM-12 (1 case, P0). | **Orchestrator-run, isolated.** Requires a tenant-wide `RolesAndPermissionsSeeder` run *without* `permission:cache-reset`, then observing the tenant-blind Spatie cache. Must be the only activity on the stack. | **S** but **exclusive** | See `project_spatie_permission_cache_tenant_blind`. |
| C-8 | **`cafe-tunis` credentials not provisioned** — PERM-14 (1 case). | Provide `barista@cafe-tunis.tn` / `owner@cafe-tunis.tn` and run `CoffeeShopSeeder`. Also unblocks the **7% reduced-rate** VAT vectors and the deterministic GL fixtures (`300.000` / `1200.000` outstanding). | **S** | Second-cheapest high-value item. |
| C-9 | **`demo-garage` (EUR/FR) not seeded** — I18N-03, plus all multi-currency and 2-dp-scale cases including review minor **M4**. | Run the default `DatabaseSeeder` (`demo-garage`, FR/EUR + TN/TND, two companies). Faker prices — useless for exact figures, correct for **currency-scale** and **cross-company** cases. | **S** | Only way to exercise a scale-**2** currency; M4 (PHP recompute clobbering the PG trigger for a 2-dp currency in a 3-dp column) is invisible on TND. |
| C-10 | **No negative money figure** — I18N-05 (1 case). | Now authorable without the device: a partial refund writes a negative `Payment` (D5); reverse-after-partial leaves an orphan negative payment (T-C/N4). | **S** | See A7.1. |
| C-11 | **`stamp_duty_amount` not exposed by the web surface** — DOC-21 (1 case). | Add the field to `DocumentData` + regenerate types (`php artisan typescript:transform`), **or** accept a DB-read verdict and mark the case out-of-web-scope. **Needs an orchestrator ruling.** | **S** (DTO) | Currently the 0.600-vs-1.000 stamp distinction is unverifiable from the browser. |
| C-12 | **AP-side allocation** — TRE-06 (`test.fixme`). | `w2b-support.ts` now builds a full PO → receipt → supplier-invoice chain (W2a). Wire it into the treasury spec's setup and drop the `fixme`. Note this tenant's `ProcurementPolicyResolver` refuses **invoice-first** supplier invoices. | **S** | |
| C-13 | **No concurrency harness** — CONC-01..06 (6 cases, 4 P0). | A two-`browser.newContext()` helper in `helpers.ts` + a request-replay helper for simultaneous POSTs. | **M** | Nothing in the repo does this today. |
| C-14 | **No idempotency harness** — TRE-63 + new `IDEM` surface. | Replay the identical request (same `Idempotency-Key` / `refund_request_id` where supported; bare replay where not) and assert exactly one money effect. `refund_request_id` idempotency is already proven at the service level (review "Idempotency is intact"). | **S** | |
| C-15 | **No device data** — ZRP/SHF/CASH/AGG/OWN/TRN/RFD/LOY-earn (≈100 cases). | **§Z POS desktop campaign** must run first or concurrently. Minimum handover per the plan §4: 1 closed shift with sales, 1 v4 refund, 1 legacy refund, 1 cash-rounded receipt. | **L** | Not web-fixable. See §D. |
| C-16 | **`ModuleGuard`-gated verticals off** — batches/expiry (INV-21/22), menu, composite items, loyalty, tables. | Verify `config/verticals.php` module state for the campaign tenant and enable the modules under test; otherwise record the **gate refusal** as the expected result (per `docs/architecture/vertical-module-gating.md`). | **S** | Both-layer gating means a missing module is a *legitimate* 403/redirect, not a failure. |
| C-17 | **No super-admin credential** — `MTP-ADM-*` (`/admin/billing/*`). | Provide `{{SUPERADMIN_EMAIL}}` (central `super_admins` table, separate `RequireAdminAuth`). | **S** | Plan §1.2 already reserves the row; it was never filled. |
| C-18 | **Scan/OCR and import fixtures** — `MTP-SCN-*`, `MTP-IMP-*`. | A sample scan (PDF/JPEG) and CSV fixtures under `apps/web/e2e/fixtures/`. Ties into `project_scan_vat_configuration`. | **M** | |

---

# D. OUT-OF-WEB-SCOPE REGISTER

Flows that **cannot** be exercised by web Playwright. Named explicitly so the Codex-Desktop and
mobile handovers own them and nothing falls between the two campaigns.

| # | Flow | Why not web-testable | Owner |
|---|---|---|---|
| O-1 | POS sale authoring (offline-first cart, tender, cash rounding at the drawer) | Tauri 2 native shell + local SQLite; `EnsureWebPosDemoTenant` returns 403 for any non-demo tenant | **Codex-Desktop** (plan §Z.2, §Z.5) |
| O-2 | v4 refund authoring (PIN, approval, disposition, payout) | Device-authored fiscal chain; refusal logic lives in `refundCheckoutStore.ts` `beginV4()` | **Codex-Desktop** (§Z.3) |
| O-3 | M2/M3 offline ceilings (count/value/window, online-required threshold, server-verified PIN) | Enforced pre-PIN on the device against `company_fraud_settings_cache` | **Codex-Desktop** (§Z, POSC-25a) — the **web** side (`/settings/compliance/fraud-settings`) is `MTP-FRD-*` and stays here |
| O-4 | Z / X / EOD close **authoring**, offline close, multi-shift | Device-signed `Z_REPORT` / `X_REPORT` events | **Codex-Desktop** (§Z.4, §Z.7) — the web **views** (`MTP-ZRP-*`, `MTP-SHF-*`) stay here and consume O-4's output |
| O-5 | Expected-cash / drawer count / variance at close | `pos_cash_drawer_operations` written only on device | **Codex-Desktop** (§Z.4) — web `MTP-CASH-*` asserts the projection |
| O-6 | Training-mode sessions | Device session flag | **Codex-Desktop** (§Z.6) — web `MTP-TRN-*` asserts isolation of the resulting rows |
| O-7 | Device SQLite TEXT-timestamp boundaries (`toSqliteUtc()`), device-authored `fiscal_shift_id` merge | Device-local storage contract (CLAUDE.md rule 20) | **Codex-Desktop** |
| O-8 | Offline queue, sync, resilience (§Z.9) | Native networking + local queue | **Codex-Desktop** |
| O-9 | Loyalty **earn** on a device sale | Requires a synced POS receipt | **Codex-Desktop** — web owns program config, member admin adjust, and the ledger read (`MTP-LOY-01/07/08/09/13/14/16`) |
| O-10 | Barcode scanner / peripheral / receipt printer paths | Hardware | **Codex-Desktop** |
| O-11 | **erp-mobile** (React Native + Expo) — any money surface it exposes | Separate runtime | **Mobile handover** — must enumerate its own surface; **not covered by any current plan** |
| O-12 | Ops CLI: `fiscal:enable-v4-refund-authoring`, `treasury:configure-method-routing`, `pos:configure-cash-rounding`, `permission:cache-reset` | Console commands | **Ops runbook** (`MTP-RFD-19..23`, `MTP-CFG-12`, `MTP-PMT-03` preconditions) |
| O-13 | Chain verification (§Y) — the three real verifiers | Server/console verifiers, not a browser flow | **§Y last-gate**, orchestrator |
| O-14 | Deptrac ratchet + red `DeferredTenderGuardsTest` (T-E) | CI/static analysis and PHPUnit | **dev-hygiene lane**, not this campaign |

---

# E. RECOMMENDED EXECUTION WAVES

## E.1 Hard constraints (learned the hard way in W1a/W1b/W2a/W2b)

1. **One local backend process.** `apps/api` visibly degrades under concurrent load — requests that
   were instant at 1 worker timed out at 3. **`--workers=1`, always.**
2. **`--project=chromium` and scoped file paths only.** Never run the whole `e2e/` suite (it
   includes unrelated legacy specs and a `webServer` boot).
3. **Never run the full PHPUnit suite** (crashes the laptop — standing owner rule).
4. **Sequential legs.** Two campaign agents on the same tenant caused observable interference in
   W1a (a sibling's `W1b-Customer` appeared mid-session). **One leg at a time**, except where the
   table below marks a leg as fixture-isolated.
5. **Kill orphaned chromium/vitest workers between legs** (`ps aux | grep 'chromium\|playwright
   test'`); hung worker pools survive parent kill.
6. **Tenant-mutating legs are exclusive** (W-X below).

## E.2 Waves

| Wave | Contents | Cases (approx) | Prereqs | Parallel? | Est. runtime |
|---|---|---:|---|---|---|
| **W-0** | Fixture build-out: C-2 statement support, C-3 role users, C-8 cafe-tunis, C-9 demo-garage, C-13 concurrency helper, C-14 idempotency helper, C-16 module-state check. Record §1.5 parameters. | — | — | n/a (dev work) | ~1 day |
| **W-1** | **§A spec reconciliation** — all 14 edits. Re-run the 6 touched files. | 14 edits, ~45 existing cases re-run | W-0 (C-2 for A6.2) | no | ~35 min |
| **W-2** | Config & pricing: `CFG-01..07,12..16` · `PMT-01..05` · `PRC-01..18` · `UOM-01..04` | ~45 | W-0 | no | ~40 min |
| **W-3** | Documents: `DOC-06,09,15,19,25..27` · `RET-01..08` · `TAX-01,08,13` · `DSC-01..06` | ~30 | W-1 | no | ~60 min (UI-heavy) |
| **W-4** | Purchasing & inventory: `PUR-14..30` · `RFQ-01..06` · `SCN-01..06` · `INV-01..27` · `OPB-01..06` · `REP-01..04` · `IMP-01..05` | ~80 | W-2 (pricing) | no | ~90 min |
| **W-5a** | Treasury payments/instruments **re-run + new**: `TRE-06,78..81` · `PERM-15` · `WHT-01..05` | ~15 | W-1, C-12 | no | ~20 min (API-driven) |
| **W-5b** | Treasury remittances / statements / reconciliation / repositories: `TRE-28..59` | ~32 | C-2 | no | ~45 min |
| **W-5c** | Expenses & income: `TRE-60..77` · `DEP-01..03` | ~21 | — | no | ~30 min |
| **W-6** | Finance/GL: `GL-01..23,26..28` | ~30 | W-3, W-4, W-5 (needs moved money) | no | ~35 min |
| **W-7** | Cross-cutting: `PERM-08..14,16..18` · `MLC-01..08` · `I18N-05,09..11` · `EMPTY-09..12` · `CONC-01..06` · `IDEM-01..08` | ~45 | W-0 (C-13/C-14), all above | no | ~50 min |
| **W-X** | **EXCLUSIVE / DESTRUCTIVE leg** — `TAX-02,03,06,07,09..12` · `CFG-08,09` · `PERM-12` · `RFP-01..12` · `FRD-01..06` · `GATE-01..06` · `CMP-01..05`. Mutates country-scoped tax config, VAT periods, tenant policies, and the permission cache. | ~45 | dedicated tenant (C-1) or solo stack; **no other agent running** | **never** | ~60 min |
| **W-8** | Isolation: `ISO-01..07` · `GL-24/25` · `PUR-13` · `LOY-15` | ~16 | C-4 (Tenant B) | no | ~25 min |
| **W-9** | **Fiscal core — device-dependent**: `ZRP-01..18` · `SHF-01..10` · `CASH-01..12` · `AGG-01..17` · `OWN-01..07` · `TRN-01..04` · `RFD-01..27` · `LOY-02..06,10..12` | ~100 | **§Z device campaign handover** (C-15) | concurrent with W-2..W-5 *authoring*, not execution | ~120 min |
| **W-10** | Central admin billing: `ADM-01..06` · Channels: `CHN-01..05` | ~11 | C-17 | no | ~15 min |
| **W-11** | **§Y last-gate fiscal re-run.** Must be the last thing that happens. | — | everything | no | per §Y |

**Total estimated execution runtime (W-1..W-10, excluding W-0 dev work and W-9's device
dependency): ~8 hours of wall clock at `--workers=1`,** spread over sequential legs. W-9 adds ~2h
once device data lands.

**Ordering rationale.** Cheap→expensive and dependency-first, matching the original plan's W0–W8
logic but re-cut around what actually blocks: fixtures first (W-0) because 6 of the 18 debt items
are seeder/helper edits that unblock ~50 cases; spec reconciliation second (W-1) because a stale
assertion invalidates every later verdict on that surface; the exclusive destructive leg (W-X)
pulled **out** of the ordinary sequence entirely because shared-tenant safety was the single
largest BLOCKED category (10 cases) and it is a scheduling problem, not a test problem.

---

# F. OPEN QUESTIONS NEEDING AN ORCHESTRATOR RULING

| # | Question | Why it blocks |
|---|---|---|
| F-1 | **T-D (TopBar z-index) has no ticket.** File one, or record an explicit risk acceptance? | Two specs currently assert the defect. Without a ticket the tripwires have no owner and will read as flaky. |
| F-2 | **`MTP-I18N-04b` plan wording.** Amend the plan (money formats currency-locale-bound, not UI-locale-bound) or keep the note permanently? | The literal plan text is falsified; leaving it invites a future agent to re-file it as a defect. |
| F-3 | **C-11 / DOC-21.** Expose `stamp_duty_amount` on `DocumentData` (+ `typescript:transform`), or declare the 0.600-vs-1.000 stamp distinction out-of-web-scope? | It is currently unverifiable from any browser surface. |
| F-4 | **C-1 isolation model.** Dedicated tenant + second DB, or accept W-X as a solo, stack-exclusive leg? | Determines whether 10 TAX/CFG cases can ever run in parallel with anything else. |
| F-5 | **C-6 / DSC-02..04.** Is the absent `discount_amount` UI control a product gap (file a UX ticket) or is the API-only path the intended surface? | Changes whether these are 3 test cases or 3 test cases **plus** a product ticket. |
| F-6 | **`/settings/setup`** renders with `SuspenseWrapper` only — **no `RequirePermission`/`ModuleGuard`**. Intentional? | Potential authorization gap on a settings route; would become a `PERM` case + a ticket. |
| F-7 | **Scope of the launch gate.** The original T1 = 192 P0. This document adds ~118 cases across 14 surfaces the plan never covered (`RET`, `RFQ`, `SCN`, `OPB`, `REP`, `CHN`, `IMP`, `UOM`, `FRD`, `CMP`, `ADM`, `DEP`, `IDEM`, `VCH`). **Which of the new cases are P0 / launch-gating?** | Without this ruling "run everything" has no completion criterion. Recommendation: `IDEM`, `UOM`, `FRD`, `RET` P0-eligible; `CHN`, `ADM`, `SCN`, `IMP` P2/post-launch. |
| F-8 | **erp-mobile (O-11)** has no campaign at all. Who owns enumerating its money surface? | Named in §D but unassigned. |
| F-9 | **T-E dev-hygiene** (deptrac 98/FAIL, red `DeferredTenderGuardsTest`) masks regressions in every future gate. Re-baseline now or after the campaign? | Every treasury gate currently has to hand-prove "+0 edges". |
