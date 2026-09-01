# Wave-2 PO scenario matrix — adversarial spec gate **r4** (final, narrow), inventory / WAC / batch / receipt lens

**Under review:** `docs/superpowers/audits/2026-09-01-wave2-po-flow/02-scenario-matrix.md` (rev 4, 154 scenarios / 21 classes) + `01-research.md` (rev 4) + `02-gate-r3-response.md`.
**Answering:** `…/reviews/2026-09-01-wave2-po-spec-gate-r3-costing.md` (G3-C1-01 BLOCKER + 02-05 MAJOR + 06-10 MINOR) and `…-gate-r3-stock-gl.md` (ACCEPT-WITH-CONDITIONS, G3-S1-01..06).
**Worktree read:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/L-po-flow` @ `01c41c7c5` (dev `62964e5cc` + the r4 spec commit). Read-only. No suites, no servers.
**Method:** disposition by disposition, each re-opened against the file it cites and read in this worktree; then one closing sweep (item 5) restricted to artefacts a first run would touch — routes, permissions, error codes, columns, fixtures. Nothing below is asserted from memory.

---

## VERDICT

**ACCEPT-WITH-CONDITIONS** — the BLOCKER and all nine other costing findings, and all six stock↔GL findings, are **genuinely applied and verified against code**. Six residues remain; every one is a literal one-line edit, none changes a derived figure, none needs a re-gate.

The r4 round is clean. The BLOCKER fix is real, not reworded: I opened `UpdateProcurementPolicyRequest.php` and confirmed the exact rule set the matrix now encodes, and I confirmed that **no `PUT /procurement-policies` body in the document carries a `preset` key** — the six remaining `preset:null` strings in the file are all prose warning against it. The four bodies each carry exactly the eight `required_without:preset` fields, at the right scales, with values byte-identical to `defaultForVertical` for the two restore rows.

Two of the six conditions are the item-5 class the round was asked to find: a row that would **measure nothing and report green on the first run** for a harness reason (`W2-EDGE-11`'s missing `expiry_date`; `W2-LOT-10`'s "half the received amount" against a PO whose quantity no fixture states).

---

## Disposition audit — my r3 costing findings

| r3 finding | Author verdict | My verification (r4) |
|---|---|---|
| **G3-C1-01** [BLOCKER] every policy PUT sends `preset:null` | FIXED | **CONFIRMED-FIXED.** No body carries the key. `W2-PRICE-6` (`02:325`) sends the eight raw fields verbatim; `W2-PRICE-7` (`:326`) and `W2-IFIRST-6` (`:399`) say "the same eight raw fields (**no `preset` key**)"; the IFIRST class header (`:390`) sends the eight; run plan step 7 (`:548`) states the rule globally. Re-verified the mechanism byte-for-byte: `'preset' => ['sometimes','required', Rule::enum(ProcurementPreset::class)]` (`UpdateProcurementPolicyRequest.php:26`), the **eight** `required_without:preset` rules (`:27-46` — `bill_control_mode`, `match_mode`, `match_enforcement`, `variance_tolerance_percent`, `variance_tolerance_max_amount`, `allow_receipt_first`, `allow_invoice_first`, `invoice_first_requires_approval`), the mixing guard `if ($this->has('preset'))` (`:59-76`). Payload values pass every rule: `match_enforcement` ∈ {`warn`,`block`} (`:29`), `variance_tolerance_percent:'2.00'` vs `decimal:0,2` (`:35`), `variance_tolerance_max_amount:'1.000'` vs `decimal:0,3` (`:42`). Controller raw branch confirmed: `isset($validated['preset'])` at `ProcurementPolicyController.php:39`, `'preset' => null` at **`:42`**, `forceFill(...)->save()` `:41-52`; route `PUT /procurement-policies` at `Procurement/Presentation/routes.php:36-38`; `authorize()` needs `settings.update`, which exists (`RolesAndPermissionsSeeder.php:528`) and admin holds. **The restore rows now succeed**, so S-1 genuinely returns `match_enforcement` to `warn` and `W2-LAND-3`/`W2-LAND-7` are reachable |
| **G3-C1-02** [MAJOR] reuse list licenses three mutating rows | FIXED | **CONFIRMED-FIXED.** List header re-worded to "assert no absolute figure **AND write no stock, WAC or AP state**" (`02:111`), with the writer→everyone-else rationale at `:113`. `W2-OVER-5(b)` → new `PO-OV5` / `P-OVER-5a`+`P-OVER-5b` (`:105`, `:214`), used nowhere else (3 mentions, all its own). `W2-SEC-5` → `PO-X6`'s residual (`:426`); arithmetic re-derived: `124.950 − 20.000 − 100.000 = 4.950` ✔, positive, so `PaymentController.php:1049-1064` (verified: `bccomp($allocationAmount,$currentBalance,…) > 0` → 422 `SUPPLIER_PAYMENT_EXCEEDS_PAYABLE`) does not refuse it. `W2-IDEM-7` → `PO-M` (`:414`). `W2-MATCH-5` removed from the list ✔. **Verified the load-bearing new claim** that IDEM-7 survives MATCH-5 having consumed PO-M's capacity: `CreateSupplierInvoiceService::create` has exactly **one** throw — invoice-first disabled (`:66`) — the number is minted unconditionally at `:70`, and the matcher only assigns `match_status` at `:220`. Creation is unconditional ✔. Residue → **G4-03** |
| **G3-C1-03** [MAJOR] `W2-EDGE-11` unfixtured / 200 on an untracked line | FIXED | **CONFIRMED-FIXED, with one gap.** The row now runs on `PO-T11` / `P-LOT-11` (`02:530`), states the `isset($batchData[$line->id]) && ($product->requires_batch_tracking ?? false)` conjunction inline — verified verbatim at `GoodsReceiptService.php:557` — and adds "no `goods_receipts` row and no `stock_movements` row survive the 500". Column confirmed `varchar(100)` (`2026_01_05_150000_create_product_batches_table.php:25`); request ceiling `max:255` (`ReceiveGoodsRequest.php:32`). **But the payload as written 422s before it reaches the DB** → **G4-01** |
| **G3-C1-04** [MAJOR] `W2-IDEM-6`'s first payment unstated | FIXED | **CONFIRMED-FIXED.** Arm 1 = `20.000`, leaving `104.950`, with the `X ≤ 24.950` derivation and the false-defect consequence stated in the row (`02:413`). Idempotency-key path verified (`PaymentController.php:358-366` early return, `:1372-1386` unique-violation read-back) |
| **G3-C1-05** [MAJOR] `W2-IFIRST-4`'s comparison impossible at the fixture price | FIXED | **CONFIRMED-FIXED.** The row states its own line `P-IFIRST-2 × 10.0000 @ 10.500`, 19 %, and its own legs `105.000 / 19.950 / 124.950` (`02:397`); the fixture price is changed to `10.500` (`:100`). Legs re-derived: `10 × 10.500 = 105.000`, 19 % ⇒ `19.950`, total `124.950` — identical to `W2-PART-6` (`:198`), so the order-independence comparison is now leg-for-leg. Approval gate re-verified: `assertInvoiceFirstApproval` at `SupplierInvoicePostingService.php:675` (matrix cites `:675-700` ✔ — my own r3 `:673-698` was the drifted one) |
| **G3-C1-06** [MINOR] LOT-10(a) duplicates LOT-6 | FIXED | **CONFIRMED-FIXED** (merged option): `W2-LOT-6` stops at the receipt (`02:238`) and cross-references LOT-10; `W2-LOT-10` is the only consuming call on the tuple and carries both evidence sets (`:242`). Residue → **G4-02** |
| **G3-C1-07** [MINOR] LOT-10b header unpinned | FIXED | **CONFIRMED-FIXED** — `02:243` pins `location_id = MAIN`, `partner_id = SUP-A`, no `batch_id`, with the reason |
| **G3-C1-08** [MINOR] rollback citation is a comment | FIXED | **CONFIRMED-FIXED** — mechanism cited as `DeliveryNoteService.php:95`, contract kept at `:376-379` (`02:242`) |
| **G3-C1-09** [MINOR] ledger completeness / S-3 residue | FIXED | **CONFIRMED-FIXED.** (a) `02:65` now adds "nor any principal-scoped state: no row narrows a membership's `allowed_location_ids`", and marks `W2-LOC-4`'s 403 as code truth off the pin — verified the pin exists and is the right one: `GoodsReceiptDestinationTest.php:183` is `test_out_of_scope_destination_is_rejected_by_receive_endpoint`, and `:206-212` is the `allowed_location_ids` narrowing + `assertForbidden()`. `LOCATION_FORBIDDEN` is a real code (`PurchaseOrderController.php:804`, `GoodsReceiptController.php:119`). (b) S-3 states the `preset` residue and its inertness (`02:60`); `ProcurementPolicyResolver::forCompany` confirmed at `:24`. Citation nit → **G4-06** |
| **G3-C1-10** [MINOR] two fixture-name drifts | FIXED | **CONFIRMED-FIXED.** `PO-c2L6` no longer exists anywhere except in the corrections list that records the rename (`01:58`); `PO-G2` is used in both documents (`01:301`, `02:88`, `02:340`). `PO-IF2` is annotated orchestrator-minted at `02:100`. Residue → **G4-05** |

## Disposition audit — the r3 stock↔GL conditions

| r3 condition | Status | Verification |
|---|---|---|
| **G3-S1-01** `01:354` contradicts `:352` | **APPLIED** | §6.7's closing scope note is rewritten (`01-research.md`, §6.7 final paragraph): LOT-9/LOT-10 assert the lot ledger, the aggregate **and the EXISTENCE of the exit journal entry**; only the COGS **amount** and the document lifecycle stay recorded-not-asserted. The paragraph names the r3 contradiction explicitly rather than quietly deleting it |
| **G3-S1-02** exit-GL assertion has no key | **APPLIED** | `W2-LOT-9` (`02:241`) now carries `SELECT 1 FROM journal_entries WHERE source_type='inventory_exit' AND source_id = <issue movement id> AND status='posted'`, **empty result FAILS the row**. Every literal verified: `'inventory_exit'` is in `InventoryGlSourceTypes::ALL` ✔; `uniq_je_source_inventory_movement` is `CREATE UNIQUE INDEX … ON journal_entries (source_type, source_id) WHERE source_type IN (…)` (`2026_08_11_000100_…`) ✔; `postSynchronously: true` at `InventoryGlPostingService.php:206` ✔ → `postEntryNow` at `GeneralLedgerService.php:5291` ✔; the two legitimate nulls are unmapped accounts (`InventoryGlPostingService.php:161-169`, logs + returns null) ✔ and non-positive amount (`:171-179`) ✔ |
| **G3-S1-03** F-W2-03's remedy is a no-op | **APPLIED** | F-W2-03 (`01:246`) and correction 19 (`01:56`) now carry it. Re-read the guard verbatim: `if (JournalEntry::where('source_type','goods_receipt')->where('source_id',$movementId)->exists()) { return null; }` — **`GeneralLedgerService.php:2052-2054`**, status-blind ✔; the status-aware shape is `if ($postSynchronously && $existing->status !== JournalEntryStatus::Posted) { $this->postEntryNow(...); }` at **`:5224-5229`** ✔. Severity stays P0 ✔ |
| **G3-S1-04** wrong "silent `continue`" rationale | **APPLIED** | Corrected in both places (`01` §6.7 item 1 and `02:241`): `confirm()` refuses a header-less delivery note outright (`DeliveryNoteService.php:89-93` → 422 `INVALID_STATUS_TRANSITION`), and the `continue` at `:309-313` is unreachable from this endpoint. The pin is unchanged |
| **G3-S1-05** LOT-6 / LOT-10 same gesture | **APPLIED (both halves)** | LOT-6 reduced to receipt facts; LOT-10 given a distinct shape (half the received quantity, non-trivial `Shortfall:`). Reachability re-derived: aggregate `Q − Q/2 ≥ 0` passes `recordSale`'s guard, the FEFO-visible ledger is empty ⇒ shortfall `Q/2` ⇒ 422 `INVALID_STATUS_TRANSITION` ✔. Residue → **G4-02** |
| **G3-S1-06** two citation nits | **APPLIED** | `:2070-2072` is the zero-amount suppression (`$amount` computed at `:2068`; `:2069` blank) — corrected in WDIL-4 arm (b) (`02:494`), now agreeing with W2-EDGE-7 ✔. W2-HP-5's oracle is `:2102`, the `'source_id' => $movementId` write inside the `JournalEntry::create([…])` spanning `:2093-2103` — verified verbatim, including `'status' => JournalEntryStatus::Draft` at `:2099` ✔ |

**Counts re-derived:** 8+6+5+8+5+4+14+6+6+7+7+11+5+4+6+6+8+7+14+5+12 = **154** across **21** classes ✔, each class header matching its row count. Fixture table = **39** body rows, not the 38 the response claims (`P-OVER-5a`/`P-OVER-5b` share one row; the miscount is in `02-gate-r3-response.md` only, not in the spec).

---

## Findings — all conditions, each a literal one-line edit

### G4-01 [MINOR — condition] `W2-EDGE-11`'s payload 422s at validation before the 500 arm is reachable

*Row:* `02:530`. The row states the batch-tracked requirement inline (good) but names only the **150-character `batch_number`**. `batches.*.expiry_date` is `['required_with:batches.*', 'date']` with **no `nullable`** (`ReceiveGoodsRequest.php:33` — this is `W2-LOT-11`'s own finding, `02:244`), so a batch entry carrying only `batch_number` fails validation and the `QueryException` → `\RuntimeException` → 500 arm (`PurchaseOrderController.php:859-865`) is never reached. The row would record a 422 and report F-W2-30 as not reproducible — the same *measures-nothing* failure mode G3-C1-03 existed to close, one field further down.

**Edit — append to the Action cell of `W2-EDGE-11` (`02:530`):**
`The batch entry must also carry a valid `expiry_date` (e.g. `2027-12-31`) and the line must carry its `quantities` entry — `batches.*.expiry_date` is `required_with:batches.*` with no `nullable` (`ReceiveGoodsRequest.php:33`, W2-LOT-11's finding), so a batch payload without it 422s at validation and the 500 arm is never reached.`

### G4-02 [MINOR — condition] `W2-LOT-10`'s new "half the received amount" has no stated quantity

*Rows:* `W2-LOT-6` (`02:238`) — "`PO-T6`: `P-LOT-6`, confirmed" states **no line quantity**, and none appears in the fixture table (`:78`) or in `01:301`. `W2-LOT-10` (`:242`) now consumes "**half the received amount**". The r4 reshape is correct in mechanism but indeterminate in arithmetic, and "half" of an odd quantity on a 0-dp `pc` unit is an avoidable coin-flip.

**Edit — `W2-LOT-6`'s Precondition cell (`02:238`):** replace `` `PO-T6`: `P-LOT-6`, confirmed `` with
`` `PO-T6`: `P-LOT-6 × 6.0000 @ 10.500`, 19 %, confirmed ``
**and in `W2-LOT-10`'s Action cell (`02:242`)** replace "consume a **partial** quantity — **half the received amount**" with
`consume **`3.0000`** — half of the `6.0000` received — so the message reads `Shortfall: 3.0000` and is distinguishable from a whole-quantity refusal`

### G4-03 [MINOR — condition] `W2-IDEM-7`'s precondition contradicts `W2-MATCH-5` inside the same document

*Rows:* `W2-IDEM-7` (`02:414`) says PO-M is one "`W2-MATCH-*` leaves … with drafts only, **never a posted invoice**". `W2-MATCH-5` (`02:383`) says of the same PO: "the **first** post succeeds". The r4 clause added later in the same cell ("running after `W2-MATCH-5` has consumed `PO-M`'s receipt capacity does not weaken the assertion") is the accurate one and I verified it against code (`CreateSupplierInvoiceService.php:66` is the only throw; number minted at `:70`; matcher only sets `match_status` at `:220`). The stale parenthetical survives from r3 and will make a runner treat MATCH-5's posted invoice as a fixture defect.

**Edit — `W2-IDEM-7`'s Precondition cell (`02:414`):** replace `` **[r4 named fixture …]** ``'s lead-in phrase `` `W2-MATCH-*` leaves it with drafts only, never a posted invoice `` with
`` `W2-MATCH-5` posts one invoice against it, so its uninvoiced receipt capacity is already consumed at IDEM time — which does not weaken this row, because creation is unconditional ``

### G4-04 [MINOR — condition] two new cross-class hand-offs are not in the load-bearing ordering list, and `02:543` is now false as written

*Rows:* run plan constraints (i)–(iv) at `02:539-542` and the sentence at `:543` ("Every other class is order-independent **because each owns its own products and POs**"). r4 deliberately (and correctly, per my own r3 fix) gave `W2-SEC-5` **`PO-X6`'s residual** — owned by `IDEM` — and `W2-IDEM-7` **`PO-M`** — owned by `MATCH`. The stated run order satisfies both (`MATCH → IFIRST → IDEM → SEC`), so **no first-run failure**; but two ordering constraints are now load-bearing and undeclared, and the justification sentence is untrue for two classes.

**Edit — add after constraint (iv) (`02:542`):**
`(v) **`W2-SEC-5` pays `PO-X6`'s residual `balance_due 4.950`**, so `IDEM` must run before `SEC`; (vi) **`W2-IDEM-7` creates two invoices on `PO-M`**, so `MATCH` must run before `IDEM`. Both hold in the order above.`
**and amend `02:543`** to `Every other class is order-independent because each owns its own products and POs — **except the two deliberate hand-offs named in (v) and (vi)**, where a later class consumes an earlier class's PO by design …`

### G4-05 [MINOR — condition] `01-research.md:301`'s PO enumeration omits the r4-new `PO-OV5`

`PO-OV5` appears 3× in the matrix and **0×** in `01-research.md`, whose §6.2 enumeration is the document that claims to list every PO by class — the same drift class as G3-C1-10(a). (Same shape, smaller: the fixture table's "Used by (once)" for `P-LOT-11` still names only the LOT class, while `W2-EDGE-11` now also uses `PO-T11`.)

**Edit — `01-research.md:301`:** replace `` `PO-G` (OVER, UNDER-1/2) `` with
`` `PO-G` (OVER-1/2/4/5a, UNDER-1/2) · `PO-OV5` (OVER-5b, its own 2-line PO) ``
**and `02:78`'s "Used by" cell:** append `` ; `PO-T11` is also the fixture for `W2-EDGE-11` (a 500 that rolls back — no stock, no WAC) ``

### G4-06 [MINOR — condition] one citation nit in S-3

`02:60` cites `ProcurementPolicy.php:124` for "`firstOrCreateForCompany` seeded `standard`". Read in this worktree: `:119` is the method, `:124` is `['company_id' => $company->id],` (the `firstOrCreate` attributes array) and the preset seeding is `'preset' => $defaultPolicy->preset?->value` at **`:127`**. (The drift is mine — r3 wrote `:124` and the author adopted it.) The rest of S-3 is exact: `defaultForVertical` spans **`:99-117`** and its eight values are `received / three_way / warn / 2.00 / 1.000 / false / false / true`, byte-identical to the restored bodies ✔.

**Edit — `02:60`:** replace `` (`ProcurementPolicy.php:124`) `` with `` (`ProcurementPolicy.php:127`) ``

---

## Item-5 sweep — artefacts a first run would touch (all new/changed references)

| Artefact referenced in r4 | Verified in this worktree | Verdict |
|---|---|---|
| `PUT /procurement-policies` | `Procurement/Presentation/routes.php:36-38` | exists |
| `settings.update` (request `authorize()`) | `RolesAndPermissionsSeeder.php:528`; admin holds all | exists |
| eight raw policy fields + scales | `UpdateProcurementPolicyRequest.php:26`, `:27-46`, `:59-76` | exact |
| `ProcurementPolicyController` raw branch, `'preset' => null` | `:33` method, `:39` `isset`, `:42` | exact |
| `defaultForVertical` values | `ProcurementPolicy.php:99-117` | exact |
| `SUPPLIER_PAYMENT_EXCEEDS_PAYABLE` + locked-row guard | `PaymentController.php:~1050-1064` (`bccomp(...) > 0`) | exists |
| `Idempotency-Key` on `POST /payments` | `PaymentController.php:358-366`, `:1372-1386` | exists |
| unconditional SI creation / number mint | `CreateSupplierInvoiceService.php:66` (only throw), `:70`, `:220` | exact |
| `LOCATION_FORBIDDEN` (403) | `PurchaseOrderController.php:804`, `GoodsReceiptController.php:119` | exists |
| `GoodsReceiptDestinationTest.php:183`, `:206-212` | test name + `allowed_location_ids` narrowing + `assertForbidden()` | exact |
| `assertInvoiceFirstApproval` | `SupplierInvoicePostingService.php:675`, permission check `:692` | exact |
| `'inventory_exit'` + `uniq_je_source_inventory_movement` | `InventoryGlSourceTypes::ALL`; `2026_08_11_000100_…` partial unique | exists |
| `InventoryGlPostingService.php:161-169` / `:171-179` / `:206` | unmapped-accounts null · non-positive null · `postSynchronously: true` | exact |
| `GeneralLedgerService.php:2052-2054` / `:2068` / `:2070-2072` / `:2093-2103` / `:2099` / `:2102` / `:5224-5229` / `:5291` | all read verbatim | exact |
| `GoodsReceiptService.php:557` conjunction | `isset($batchData[$line->id]) && ($product->requires_batch_tracking ?? false)` | exact |
| `product_batches.batch_number varchar(100)` | `2026_01_05_150000_…:25` | exact |
| `ReceiveGoodsRequest.php:27/28/32/33/36` | `required_with:received_unit_prices` · qty regex admits `-` · `max:255` · `required_with` + `date`, **no `nullable`** · price regex forbids `-`, 3 dp | exact — and `:33` is **G4-01** |
| `PO-OV5` / `P-OVER-5a` / `P-OVER-5b` | new, used only by `W2-OVER-5(b)` | clean — but missing from `01:301` (**G4-05**) |
| `PO-T11` / `P-LOT-11` at EDGE time | both `W2-LOT-11` arms are 422 at validation ⇒ still `Confirmed`, full remainder | producible |
| `PO-X6` residual `4.950` for `W2-SEC-5` | `124.950 − 20.000 − 100.000`; `IDEM` runs before `SEC` | producible — ordering undeclared (**G4-04**) |
| `PO-M` for `W2-IDEM-7` | creation unconditional after MATCH-5's post | producible — stale parenthetical (**G4-03**) |
| `PO-T6` quantity for `W2-LOT-10`'s "half" | **not stated anywhere** | **G4-02** |

No row in r4 references a route, permission, error code, column or table that does not exist. No new float/precision drift: every money and quantity figure in the changed rows is a string, compared via `assertMoneyEqual`, with the 6-dp `allocated_costs` resolution retained unchanged.

---

## What to fix before merge

Six one-line edits — `W2-EDGE-11`'s `expiry_date` (G4-01) and `PO-T6`'s quantity (G4-02) are the two that would otherwise make a row measure nothing on the first run; the other four are the IDEM-7 contradiction, the two undeclared ordering hand-offs, the `PO-OV5` enumeration and the `:124`→`:127` citation. Apply them in place and the matrix is dispatch-ready — **no re-gate**.
