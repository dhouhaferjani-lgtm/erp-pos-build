# Wave-2 PO spec — gate r2 response (rev 2 → rev 3)

**Session:** L · **Date:** 2026-09-01 · **Base commit:** `62964e5cc`
**Reviews answered:** `…-gate-r2-stock-gl.md` (**ACCEPT-WITH-CONDITIONS**, G2-S1-01..10) and `…-gate-r2-costing.md` (**CHANGES-REQUIRED, narrow**, G2-C1-01 BLOCKER + 02-05 MAJOR + 06-14 MINOR).
**Documents revised:** [`01-research.md`](./01-research.md) and [`02-scenario-matrix.md`](./02-scenario-matrix.md), both now rev 3. The r1 round is [`02-gate-r1-response.md`](./02-gate-r1-response.md).

## Summary

| | r1 | r2 | r3 |
|---|---|---|---|
| Scenarios | 130 / 21 classes | 151 / 21 classes | **154 / 21 classes** |
| Findings | 28 | 36 | **36** (no new defects; F-W2-03's mechanism widened) |
| Owner questions | 12 | 10 | **10** |
| Settings ledger | — | — | **6 rows (S-1..S-6)** |

**Every finding from both reviews is FIXED.** No numbered r2 finding is rebutted. **Both r1 rebuttals were re-verified and UPHELD by the r2 reviewers and are retained unchanged** — the `CreateDocumentRequest.php` renumbering (stock-GL: *"the author is right and my predecessor was wrong"*) and the W2-WDIL-3 netting arithmetic (costing: *"my r1 worry was wrong"*; stock-GL re-derived it independently and confirmed `inventoryPlug = 0`, 408 nets to zero).

**One cross-reviewer conflict had to be resolved rather than accepted** — see "Reviewer conflict" below.

**Structural changes in r3:**
1. **THE SETTINGS RULE + a 6-row Settings ledger** — the r2 BLOCKER. Every company-wide setting mutation now names the row that restores it, and two new restore rows (`W2-PRICE-7`, `W2-IFIRST-6`) exist because `match_enforcement` and `allow_invoice_first` persist across classes.
2. **Every `PUT /procurement-policies` carries the complete raw body** — every raw field is `required_without:preset`, so both r2 calls would have 422'd.
3. **THE COSTING RULE is now enforced end-to-end** — 11 new fixture SKUs and their POs for the rows that posted receipts or asserted absolute figures without naming one, plus an explicit list of the rows that assert no absolute figure and may therefore reuse anything.
4. **The GR-IR detectors are hardened twice over** — `je.status = 'posted'` on every arm, and the `movement_id IS NOT NULL` narrowing split out into its own "received but never moved" arm.

---

## Inventory / WAC / batch lens — G2-C1-01..14

| Finding | Verdict | Where in r3 |
|---|---|---|
| **G2-C1-01** [BLOCKER] `W2-PRICE-6` leaves `match_enforcement = 'block'` set company-wide; `W2-LAND-3`/`-7` then 422 instead of posting | **FIXED** | New **THE SETTINGS RULE** section + a **6-row Settings ledger** in `02`, and `01` §Corrections item 16. **`W2-PRICE-7 (restore)`** added, returning `warn` with a complete raw body, with the reason spelled out in the row itself. The run plan's load-bearing-ordering list grows from two constraints to four, (iii) being exactly this one. `W2-LAND-7` now also states `match_status = price_variance` so the dependency on `warn` is legible at the row. |
| **G2-C1-02** [MAJOR] both `PUT /procurement-policies` payloads are partial and will 422 | **FIXED** | Both call sites now carry the **complete raw body**, written out in full: `W2-PRICE-6` (and its restore twin) and the `W2-IFIRST` class header, each citing `UpdateProcurementPolicyRequest.php:26-46` + `ProcurementPolicyController.php:33-53`. Added to the run plan as its own numbered step. |
| **G2-C1-03** [MAJOR] `W2-LOT-10` cannot reach the lot guard; the aggregate guard fires first | **FIXED** | **`W2-LOT-10` reshaped** onto the `P-LOT-6` tuple (aggregate backed, FEFO-visible lot ledger empty because its only lot is expired), asserting **422 `INVALID_STATUS_TRANSITION`** with the exact `InsufficientBatchStockException` message, no negative `inventory_batch_stock` row, and `stock_levels` unchanged after rollback. The aggregate arm is **kept and labelled** as new **`W2-LOT-10b`** → **422 `INSUFFICIENT_STOCK`** via `WeightedAverageCostService.php:457-467` and the deliberately-first catch arm at `DeliveryNoteController.php:581`. Keeping both is what makes the pair informative: one gesture, two refusals, depending on which ledger is short. |
| **G2-C1-04** [MAJOR] ~9 rows post receipts or assert absolute figures against unnamed fixtures | **FIXED** | 11 new SKUs with their own POs: `P-IDEM-2b`/`PO-X2`, `P-IDEM-6`/`PO-X6`, `P-IFIRST-1`/`PO-IF1`, `P-IFIRST-2`, `P-PRICE-6`/`PO-P6`, `P-MATCH-2`/`PO-M2`, `P-REV-5`/`PO-R5`, `P-EDGE-2`/`PO-E2`, and **`P-c2-HP1/2/3`/`PO-c2A` + `P-c2-L9`/`PO-c2L9` seeded in company 2 by an extended W2-SETUP-5**. Rows that genuinely assert no absolute cost or stock figure are now listed **explicitly** under the fixture table, so the omission is deliberate rather than silent. `01` §6.2's PO enumeration is completed (it omitted `PO-U`, `PO-U2`, `PO-V`, `PO-DR1`, `PO-DR2`). |
| **G2-C1-05** [MAJOR] `W2-IDEM-6`'s fixture was consumed by `W2-PART-8` | **FIXED** | `W2-IDEM-6` gets **its own chain** — `PO-X6` / `P-IDEM-6 × 10.0000 @ 10.500`, received, invoiced, posted and left **unpaid** — and the row says in terms that r2 copied `124.950` from `PO-C`'s invoice, which `W2-PART-8` zeroes eight classes earlier. |
| **G2-C1-06** [MINOR] `W2-LOT-8`'s r2 re-citation inverts the 422/500 arms | **FIXED** | Verified independently: `PurchaseOrderController.php:857` is the `\DomainException` catch, `:858` the 422; `:859` the `\RuntimeException` catch, `:860-865` the 500. Corrected in **W2-LOT-8**, **W2-EDGE-11** and the **Tolerances** note, each carrying the correction inline because the determinism argument rests on which arm is which. Recorded in `01` §Corrections item 14 — r1 said `:855-856`/`:857-864` and r2 adopted it verbatim, so this was wrong in **both** prior revisions. |
| **G2-C1-07** [MINOR] three inherited citation drifts | **FIXED** | (a) `W2-LAND-8` → **`ReceiptBatchCostAllocator.php:55-56`** (`:57-58` are the array pushes). (b) `W2-OVER-5` → **`GoodsReceiptService.php:193`**, the `createDraft` throw the API path actually reaches, not `:720` in `processReceiptLines` (identical text, wrong oracle). (c) `W2-LOC-5` → `inventory_batch_stock` columns **`:21-22`**, unique `unique_batch_per_location` at **`:36`**. All three re-verified and recorded in `01` §Corrections item 15. |
| **G2-C1-08** [MINOR] `W2-LOT-6`'s FEFO probe says "draws nothing"; it is a whole-confirm refusal | **FIXED** | Restated as a determinate outcome: **422 `INVALID_STATUS_TRANSITION`**, the exact shortfall message, **zero lots drawn, `stock_levels` unchanged** because the confirm rolls back (`DeliveryNoteService.php:376-379`). This applies the same principle the author accepted for W2-LOT-8 in r2. |
| **G2-C1-09** [MINOR] `W2-LAND-7` hedges on a statically excluded outcome and omits `match_status` | **FIXED** | The hedge sentence is **deleted**, with the reason stated at the row: `consumeReceiptLines` refuses on exactly two conditions (NULL `accrual_unit_cost` `:488-495`, over-clear `:505-514`) and PO-E has neither. **`match_status = price_variance`** added. |
| **G2-C1-10** [MINOR] fixture-table drift | **FIXED** | `P-LAND-4`'s row no longer claims LAND-10 (which acts on `PO-D`/`P-LAND-1a/b` — noted at both ends). `P-OVER-1`'s row now names `PO-U2`'s goods line. `PO-H`'s reuse by `W2-OVER-3` is kept and **declared as an exception**, with the reason it is safe (a 422 that rolls the whole receipt back). `01` §6.2's PO enumeration completed. |
| **G2-C1-11** [MINOR] `W2-LOT-9/10` do not pin the delivery note's location | **FIXED** | Same fix as G2-S1-04 — see below. |
| **G2-C1-12** [MINOR] `W2-EDGE-12` needs a sales invoice no class creates | **FIXED** | The sales-invoice arm is **dropped** and replaced with the **W2-LOT-9 delivery note** — the only non-purchase document in the run — with the reason stated (a nonexistent id would 404, and the 404 is not the finding). |
| **G2-C1-13** [MINOR] company 2 is `non_registered` for the whole run | **FIXED** | Settings ledger **S-4** records it as a deliberate, unrestored mutation, names every later c2 row it touches (SEC-1/2/7, LAND-6/9, MATCH-6), states that **no c2 row may be read as asserting recoverable-VAT behaviour**, and records why `W2-SEC-7`'s `20.000000` still holds (`reallocateCosts` passes `'0'` for the non-recoverable term at `LandedCostService.php:265`, restoring `landed = line_total / qty`). Also constraint (i) of the run plan's ordering list. |
| **G2-C1-14** [MINOR] `W2-VAT-3` re-uses `PO-A` ambiguously | **FIXED** | Restated as **"read back `PO-A`"**, with the explicit note that creating a second PO on `P-HP-1..3` would be a second receipt on those SKUs and would violate THE COSTING RULE. |

---

## Stock↔GL lens — G2-S1-01..10

| Finding | Verdict | Where in r3 |
|---|---|---|
| **G2-S1-01** [MAJOR] both GR-IR queries pass on a created-but-never-posted entry | **FIXED** | **`AND je.status = 'posted'`** added to the `W2-HP-5` join and to every arm of `W2-WDIL-4`, each with the mechanism inline as a SQL comment: the entry is committed as `JournalEntryStatus::Draft` (`GeneralLedgerService.php:2099`, returned `:2127`) and posted **outside** that transaction (`:2134`), so a throw there leaves a committed Draft that the swallowing listener never reports. Verified independently, including `JournalEntryStatus::Posted = 'posted'` (`:10`). **F-W2-03's own text is widened** to name this mechanism — the failure mode is not only "no entry at all". |
| **G2-S1-02** [MAJOR] the `movement_id IS NOT NULL` guard blinds the universal detector | **FIXED** | `W2-WDIL-4` now has **three arms**: (a) paid movement with no posted entry, (b) free leg — **movement must exist and an entry must be absent**, by design, because a free unit's cost is `'0'` and `createGoodsReceiptGrIrEntry` returns null at amount ≤ 0 (`:2069-2072`) — stated explicitly as the reviewer asked, and (c) **received but never moved**, the F-W2-34 shape, with the note that only W2-UNDER-4's known `P-UNDER-4` line may appear there. `goods_receipt_lines.movement_id` is uuid **nullable** (`2026_07_04_100000_…:48`), and both `movement_id` and `free_movement_id` exist — re-verified. |
| **G2-S1-03** [MAJOR] `allocated_costs` is stated at the wrong scale; the justification cites a docblock | **FIXED — and it overturns the other reviewer's r1 finding; see "Reviewer conflict"** | All four figures restored to 6 dp: `0.000000` (W2-HP-2), `20.000000`/`10.000000` (W2-LAND-1), `30.000000` (W2-LAND-4, W2-LAND-8). Each now states both facts — the share is **computed** at the currency scale, the **column is `decimal(19,6)`** with cast `decimal:6`, so Postgres pads — and cites `2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php:60-63` + `DocumentLine.php:156` + the real pin **`LandedCostBcmathTest.php:330-331`** (`assertSame('20.000000', …)`), not `:298-300`, which is that test's docblock. Compared with `assertMoneyEqual`, never string equality — stated at the rows and in `01` §Corrections item 11. |
| **G2-S1-04** [MAJOR] the FEFO leg does not pin the three fixture facts | **FIXED** | `W2-LOT-9`'s action now pins **`location_id = MAIN`**, **`partner_id = SUP-A`**, and **no `batch_id` on the line**, with the oracle for each: the silent `continue` on a null location (`DeliveryNoteService.php:309-313`), FEFO as the **`elseif`** (`:380-388`), and `partner_id` required (`CreateDocumentRequest.php:66-70`). `01` §6.7 gains a numbered "three fixture facts" block making the same point for every FEFO row, including that omitting the location makes the row fail for a fixture reason that reads exactly like a FEFO defect. |
| **G2-S1-05** [MAJOR] W2-LOT-6 and W2-LOT-10 leave a code-determined outcome undetermined | **FIXED** | Both now assert **422 + rollback** with the exact message, citing `InsufficientBatchStockException extends \DomainException` (`:17`, message `:28`), the throw at `FEFOInventoryService.php:341`, and the `\DomainException` arm at `DeliveryNoteController.php:595-596`. Same principle as r1's G1-S1-14. |
| **G2-S1-06** [MAJOR] the run's only stock EXIT has its GL consequence unasserted | **FIXED** | `W2-LOT-9`'s Expected gains: **record the `journal_entries` the confirm produces and assert at least one entry exists for the issue movement** — its amount and lifecycle stay recorded-not-asserted. `01` §6.7's scope note is narrowed by exactly that clause, citing `recordSale` (`:316-323`), the negative-quantity `Issue` movement (`WeightedAverageCostService.php:470-489` — which is also what makes W2-WDIL-1(a)'s Σ-vs-delta identity hold once the FEFO leg runs) and the `MovementGlContext(kind: Exit)` enqueue (`:399-401`). The reviewer's framing is adopted verbatim: wave 2's own P0 is "stock moved, GL swallowed", and this is the one exception the seam cannot afford. |
| **G2-S1-07** [MINOR] W2-LAND-7 keeps a two-outcome hedge | **FIXED** | Same fix as G2-C1-09 — the hedge is deleted with the two-condition reason stated. Both reviewers raised it independently. |
| **G2-S1-08** [MINOR] three assertions that cannot fail because the DB enforces them | **FIXED** | W2-WDIL-1(c), W2-WDIL-2 and W2-IDEM-5 each carry a **"DB-enforced — a green result is not evidence of application-level idempotency"** clause naming the index (`goods_receipt_lines_movement_id_unique` / `…_free_movement_id_unique`, `uniq_je_source_procurement`) and pointing at the informative assertion instead. W2-WDIL-2 also records the reviewer's corollary: that index does **not** cover `source_type='goods_receipt'`, which is why W2-IDEM-2's two GR-IR entries are genuinely reachable. |
| **G2-S1-09** [MINOR] residual citation drift | **FIXED** | The receive-path arms (`:857-858` / `:859-865`, see G2-C1-06 — both reviewers caught it); **`POST /roles` is `Identity/routes.php:60`** (r2's `:66-68` is `POST /users`), added to W2-SETUP-7 together with the reviewer's bonus verification that the role is assignable (`AssignableRole.php:47-51`, `CreateUserRequest.php:50` has no seeded-name allowlist); and the membership block is **`UserController.php:241-249`** with `'company_id' => $companyId` at `:243`. |
| **G2-S1-10** [MINOR] the batch-invariant query is variant-blind | **FIXED** | The query gains **`AND sl.variant_id IS NULL`** and **`AND pb.variant_id IS NULL`**, plus a comment explaining that `stock_levels` is variant-grain (`stock_levels_non_variant` / `stock_levels_with_variant`, `2026_06_02_100005_…:52-57`), that the variant-grain form pairs `ibs → pb.variant_id` against `sl.variant_id`, and that no r3 fixture needs it (only `P-LOT-8` has a variant and it never receives — W2-LOT-8 is a 422). |

---

## Reviewer conflict resolved (not a rebuttal)

The two reviewers **disagree** on `document_lines.allocated_costs`:

- **Costing r1 G1-C1-16** said the column is currency scale (3) and asked for `20.000` / `10.000`; its r2 disposition audit re-confirmed that as "CONFIRMED-FIXED".
- **Stock-GL r2 G2-S1-03** says the column is `decimal(19,6)` with cast `decimal:6` and asks for `20.000000`.

**Resolved in favour of stock-GL, because both halves are true and only one of them is what a test reads back.** Verified in this worktree: `allocatePositiveShares` **computes** the share at `$this->scale()` (TND ⇒ 3), *and* the column is `decimal(19,6)` (`2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php:60-63`, applied by the `ALTER COLUMN … TYPE` at `:76-83`) with the Eloquent cast `'allocated_costs' => 'decimal:6'` (`DocumentLine.php:156`), so Postgres pads. The decisive evidence is the pin itself: `LandedCostBcmathTest.php:330-331` asserts `'20.000000'`; r1 quoted `:298-300`, which is that test's **docblock**. Under the matrix's own tolerance ("6-dp costs compare as exact strings read from Postgres") the 3-dp figure would have produced a phantom defect in the highest-value money class. Both facts are now stated together at every affected row, and every `allocated_costs` comparison is routed through `assertMoneyEqual`.

## Rebuttals

**None in r3** — every numbered r2 finding is fixed. The two **r1** rebuttals were re-examined by the r2 reviewers and **UPHELD**, and are retained unchanged:

| r1 finding | r3 status |
|---|---|
| **G1-S1-16** (in part) — the `CreateDocumentRequest.php` renumbering is off by one | **UPHELD.** Stock-GL r2: *"the author is right and my predecessor was wrong"*, verified verbatim: `:119` `description`, `:120` `quantity`, `:124` `unit_price`, `:125` `line_total`, `:126` `price_entry_mode`. Costing r2 independently confirmed the same. W2-TOT-5 and W2-EDGE-3 stand as written. |
| **G1-C1-12** sub-claim — that W2-WDIL-3 would "trip over" LAND-4's `115.000` vs `100.000` | **UPHELD.** Costing r2: *"my r1 worry was wrong"*; stock-GL r2 re-derived it independently (`drKnown = 134.000`, `plug = −15.000`, `priceDelta = −15.000`, **`inventoryPlug = 0`**). **408 nets to zero.** r3 adds the reviewer's one refinement: W2-WDIL-3 now calls the offset a **phantom PPV income** — the P&L is credited for freight that was actually incurred. |

## Findings register — r3 delta

**No new defects.** The r2 reviews surfaced no product defect the register lacked; their findings were spec defects. One finding is **widened**:

- **F-W2-03 (P0)** — its mechanism now names the committed-Draft path: `createGoodsReceiptGrIrEntry` commits the entry as `Draft` inside an inner transaction (`GeneralLedgerService.php:2099`/`:2127`) and posts it outside (`:2134`), so the swallowed throw can leave a **committed Draft**, not only "no entry at all" — and any detector that does not filter `je.status = 'posted'` reports green on it. Its scenario pointer becomes "three orphan queries, all `status='posted'`".

Register unchanged at **36 findings — 2 P0 · 8 P1 · 16 P2 · 10 P3**. Owner questions unchanged at **10** (Q-1..Q-6, Q-8, Q-9, Q-11, Q-12).

## Settings ledger — 6 rows

| # | Setting | Mutated by | Restored by |
|---|---|---|---|
| S-1 | `procurement_policies.match_enforcement` | W2-PRICE-6 → `block` | **W2-PRICE-7 (restore)** → `warn` |
| S-2 | `procurement_policies.allow_invoice_first` | W2-IFIRST-2 → `true` | **W2-IFIRST-6 (restore)** → `false` |
| S-3 | `procurement_policies` row materialised | W2-PRICE-6 / W2-IFIRST-2 | n/a — the row persists by design; S-1/S-2 restore its values |
| S-4 | `companies.tax_status` (company 2) | W2-SETUP-8 → `non_registered` | **not restored — deliberate**, scoped to c2, with the consequence for every later c2 row named |
| S-5 | role `wave2-receiver` | W2-SETUP-7 | not restored — additive |
| S-6 | cashier + receiver users | W2-SETUP-6/7 | not restored — additive, company 1 memberships only |
