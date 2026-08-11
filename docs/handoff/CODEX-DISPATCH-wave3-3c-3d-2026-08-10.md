# Codex A→Z dispatch — DPA Wave-3 sub-waves 3C + 3D (2026-08-10)

**Model/effort (owner directive):** Codex SOL 5.6, HIGH effort. Workhorse mode: implement end-to-end,
TDD red-first.

> ## ⚙️ EXECUTION MODE — SELF-REVIEWING WAVE (read this before anything else)
> This wave runs under **`docs/handoff/SELF-REVIEW-HARNESS.md`**. You do NOT hand back to a human
> between milestones. At the end of every milestone you run the adversarial review YOURSELF by
> invoking Opus through the CLI bridge `scripts/adversarial-review.sh` (it calls
> `claude -p --model opus`), read its register, and loop scoped fix rounds until ACCEPT — then move on.
> - **Track state in `docs/handoff/progress/wave3-3c-3d.progress.yaml`** — read it first, update it after
>   every milestone (status, commit SHA, verdict path, fix_rounds). It is the resume point if you crash.
> - Wherever a milestone below says "hand back", "the orchestrator runs", or "dual gate" — that is now
>   the harness's self-review loop (the milestone's `review_lenses` in the YAML are the lenses to apply).
> - **STOP and escalate only at the three harness STOP conditions:** fix rounds exhausted, an owner gate
>   (here: **V-7 treasury approval of the T20 shrinkage/gain codes is a MERGE CONDITION** — block M4 on it),
>   or an architecture contradiction. Set the YAML `status` + `blockers` and end your run.
> - Per-milestone registers go to `docs/handoff/reviews/wave3-3c-3d/`. Branch NOT merged, NOT pushed.

**Revision 3 (2026-08-10).** Draft 1 → Revision 2 folded the four code-gate re-reviews. **Revision 3
folds the adversarial review** (`review-3c3d-handover-2026-08-10.md`, VERDICT: REJECT — repairable by
text revision; 13 findings) **and the two orchestrator rulings that closed F4.** All 12 of Revision 2's
VERIFY-AT-DISPATCH items are now disposed. **The only open item is the base SHA.**

**Authoritative plan:** `.superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/plan-wave3.md`
— **Revision 4, gate CLOSED BY ADJUDICATION at round 4.** The plan is the spec. This brief pins entry
conditions, milestone shape, the riders that postdate the plan, and the evidence contract.

**Authoritative ledger:** `progress.md`, same directory — `[S2] 216`, `[S2] 221`, `[S2] 223`, and the
whole `[S3]` block.

**Authoritative gates** (read the **RE-REVIEW** sections): `gate-w3ab-code-review-{inv,fiscal}.md`,
`gate-w3e-code-review-{inv,fiscal}.md`. **Authoritative review of this brief:**
`review-3c3d-handover-2026-08-10.md`.

**Sub-waves in scope:** **3C** (T11, T11c, T11e, T12, T13, T14, T15, T15a, T16, T16b, T16c, T16d,
T16e, T17, T18, T19, T19b + the detector checks routed from 3E) and **3D** (T20, T20b, T21, T22).

---

## ⛔ HARD PREREQUISITES

1. **`integrate/wave3` is MERGED to local `dev`.**
   - ✅ 3A/3B (`feat/dpa-wave3-foundations`) merged to `dev` at **`6974231d0`** (two fix rounds; inv
     PASS FR1, fiscal PASS FR2 at `44b049c00`).
   - 🔄 **3E integration in flight on `integrate/wave3`**, carrying three non-optional items that are
     **not** 3C's to do:
     1. the 3E merge (`feat/dpa-wave3-3e`, both halves PASS after two fix rounds);
     2. **the P2-3 HARD GATE, executed** — `DeliveryComplianceGate::hasPhysicalLines()` rewritten onto
        `PhysicalLinePredicate::forLine($line, $tenantId, $companyId)`, `PhysicalLinePredicateTest`
        extended to drive the gate. *"Both branches green does NOT protect against this"*
        (w3ab fiscal RE-REVIEW 2, CONDITIONS §1);
     3. **the batch-table trace extension** — `product_batches`, `inventory_batch_stock`,
        `inventory_batch_movements` into `RECEIPT_ROW_LOCK_TABLES`, trace driven once with `batchData`
        (CONDITIONS §2, NEW-2-residual).
   - **Do not dispatch against `feat/dpa-wave3-foundations` or a partially integrated tree.**

2. **PIN THE BASE SHA — a mechanical step, not a phrase.** Execute and paste into the dispatch message:

   ```
   git -C <repo> rev-parse --verify dev            # → BASE_SHA
   git -C <repo> log -1 --format='%H %ci %s' dev   # → paste verbatim
   git -C <repo> merge-base --is-ancestor 6974231d0 dev && echo "foundations: IN"
   git -C <repo> status --porcelain                 # → MUST be empty
   ```
   `BASE_SHA` is quoted in the dispatch, in M0's citation inventory header, and in the report file.
   **M0's first action is to verify `HEAD` still equals `BASE_SHA`.**

**Already satisfied — do not re-litigate:** advrev merged (`a67511fc9`), so the
`GeneralLedgerService.php` / `FranceChartOfAccountsSeeder.php` collision is discharged by ordering;
3C's plan-level preconditions (T2, T3, T5, T5b on branch; T10a's CONFIRMED/REJECTED line; T9) met by
the foundations merge; the three 3E artifacts 3C consumes arrive with the integration merge.

---

## ✅ OWNER GATES — ALL OPEN (cite; do not re-ask)

`progress.md` `[S3] 225`, owner ruling direct, 2026-08-10:

| Gate | Ruling | Effect |
|---|---|---|
| **OQ-13** POS COGS date basis | **CLOSED: DEVICE-DATE basis.** Plan default ratified; the posting-date-both-legs lane is NOT opened. | 3C's merge gate satisfied owner-side. `entryDate = $receipt->posted_at` (D-8/T16); assert `inventory_exit.entry_date === pos_receipt.entry_date`. |
| **OQ-2** shrinkage/gain codes | **CLOSED: seed best-known codes NOW**, C1 precedent; expert edits later via the defaults dashboard. | T20 unblocked — see **the V-7 ruling** below for the mechanism. |
| **OQ-12** `CostOfGoodsSold` → 603 (TN **and** FR) | **CLOSED: ACCEPT WITH RECORDED CAVEAT.** Expert rules on liasse presentation before any first filing. | T20 merge unblocked. Do not "fix" the 603 mapping; cross-reference `FranceChartOfAccountsSeeder.php:275-279` and register H-5. |

Not gating this dispatch: OQ-15 (blocks FR *launch*), OQ-16, OQ-17, OQ-7 (T22 is a ticket by ruling).

### 🔨 ORCHESTRATOR RULINGS closing adversarial-review F4

**V-7 — shrinkage/gain account codes.** Consistent with OQ-2. **T20 PROPOSES** the TN / FR / Generic ×
shrinkage / gain map **with per-seeder accounting rationale**; **treasury-reviewer APPROVAL IS REQUIRED
before T20 merges**; the expert edits later via the defaults dashboard. **Neither the orchestrator nor
the reviewer invents codes** — the proposal originates in the implementation, is justified in the
seeder docblock, and is approved or rejected at the M4 gate.

**V-10 — per-line location / FEFO silent degrade: FIX BEFORE M2, NARROWLY.** Rationale: post-T25b/c
this factory is the **required** path for every standalone goods invoice; the launch vertical
(parapharmacy) is batch-tracked by default; silent location/batch divergence is a books/stock-integrity
defect **on the mandated path**, not an acceptable-risk ticket. **Scope (an M1 task, red-first):**
- **(a)** `copyLine` carries the source line's `location_id`. The issuer already resolves
  `line.location ?? document.location` — **the factory must stop flattening it.**
- **(b)** FEFO allocation failure becomes **LOUD on the guided path** — a typed refusal with a machine
  reason (no-silent-behaviour doctrine), **not** a silently unbatched line. Operator remedy: confirm
  delivery manually with an explicit batch choice.
- **The wider redesign stays in the P2-7 ticket.** Do not expand.

---

## 🚚 ROUTED IN FROM 3E

`progress.md` `[S3] 223`, both re-affirmed by the 3E re-reviews:

1. **T23's detector checks D-a / D-b / D-e / D-g MOVE TO 3C** — they consume `InventoryGlSourceTypes::ALL`,
   the `inventory_gl_cutover_at` watermark, `return_cost_basis`, and the seam. D-a without the
   watermark false-fires on **every** historical movement (the final gate's F-4). 3E shipped the
   command class, schedule, exit contract and tenant iteration; **3C adds the checks to the same
   class** (`CheckCogsCoverageCommand`), per D-26 / §2.3.
2. **T25c's guided endpoint = D-28 candidate C-5**, not C-3 (**C-3 is
   `DeliveryNoteController::confirm`**). 3C registers it and gives it a `flushIfOutermost()` tail —
   the **first live test of D-28's register-independent method guard**.

---

## 🧷 RIDERS — 15 binding items

### R-1 🚨 POS REFUND COST BASIS — RULING REQUIRED BEFORE T16 MERGES
*(w3ab inv **F-6** P1-for-3C; re-affirmed in the inv RE-REVIEW carried list §3.)*
`PosCoreReceiptProjection` → `movementCostSnapshot` values a POS refund restock at the **LIVE**
`products.cost_price` at projection time, not the original sale's cost. Sale at 10, WAC → 12, refund
restocks at 12 against 10 relieved ⇒ **under T16 the reversal ≠ the original charge, leaving an
unreconciled inventory residual on every refund-after-purchase.** T16 never names a return basis; the
RN lane gets `ReturnCostBasisResolver` (D-24/T15a), the POS lane gets nothing.
- **Orchestrator recommendation (final say at the 3C gate):** option (a) — resolve refund `unit_cost`
  from the **original sale's `stock_movements.unit_cost`** at the same receipt + product grain,
  **symmetric with the RN lane**.
- **Option (b), only if explicitly taken:** record live-WAC as deliberate + pin with a cost-moved test.
- Deliver the chosen option, its rationale, and a test that goes red under the other.
- D-24's `POSSale` arm was **deliberately deleted** (Rev 3, inv N-6): `confirmedDeliveryNotesFor()` is
  a DN-document resolver, POS exits carry `reference_type = 'pos_receipt'`. Option (a) is a **new,
  POS-local** resolution — **do not restore the deleted arm.**

### R-2 🚨 THE D-28 REGISTER HAS BEEN WRONG TWICE — the register-independent guard is MANDATORY
- **C-3 = `DeliveryNoteController::confirm`.** **V-4 RULING ADOPTED: register the controller closure
  as the flush point.** It is the narrower remedy — it preserves the shipped `lockForUpdate`
  idempotency read, and the controller (`:290-331`, semantic anchor: the method's own `DB::transaction`
  closure) already owns the outer transaction. **Do not** drop the controller's transaction and migrate
  the lock into the service. T14's test **must drive `POST /delivery-notes/{id}/confirm`** — a
  service-level test passes while production posts nothing.
- **C-5 = T25c's guided endpoint.** 3C registers it and wires its flush tail.
- **Guard 4 (structural leak test) is NOT optional** — a test-only terminating hook asserting
  `$buffer->isEmpty()` at every request/job boundary. The only guard that does not depend on the
  register being right. The `afterCommit` alarm is a loudness upgrade, not a safety net: *"an alarm
  that fires on every delivery-note confirmation in production is an outage, not a safety net."*
- **Re-derive the register from the caller sweep at branch time** (D-28 item 1) — M0's deliverable.
  Program lesson, now at **four** occurrences (GR / scrap / DN-controller+voucher /
  `RefundService:481-484`): a claim justified by *reading* rather than *enumerating* has been wrong
  every time. `failClosedGrir` was believed dead because a caller passes it **positionally** — an
  identifier grep cannot see positional arguments. **Enumerate; never read.**

### R-3 ✅ RESOLVED — the advisory-terminal invariant is TRUE; carry the corrected wording
*(w3ab fiscal **P2-2**, CLOSED **structurally** in FR1; superseded inv F-2's wording-only fix.)*
`flushPendingGlPostings` moved to after the PO update ⇒ **the invariant is now TRUE for
`GoodsReceiptService::post()`.**
- **T11c's I-1 formulation encodes the corrected claim** (loop-terminal → transaction-terminal).
- **Semantic consequence, one docblock line owed** (inv RE-REVIEW §5, N-3): `GoodsReceived` now fires
  with **PO status = `Received`**. T11c pairs 4/5 must account for it.
- **Residual, deliberately open:** the standalone path still writes its idempotency key after the
  advisory (fiscal **P3-1**, upgraded to a pinned fact). This is **D-28's C-4, EXEMPT by decision** —
  GR posts by deferred event, not the buffer, and `procurement_idempotency_keys` is not an
  inventory-class resource, so I-1 holds. Record the exemption; do not "fix" it.

### R-4 D-19 REGISTER — the complete enumeration *(adversarial F6: Revision 2 listed only 2 of 4)*
**18 candidates — 10 adopted · 3 deliberate non-adoptions · 4 assigned to 3C · 1 off-branch.**

**The four assigned to 3C, all enumerated:**

| # | Site | Disposition |
|---|---|---|
| 1 | `SalesOrderToInvoiceConverter:334` | **pure adoption — DO IT.** No judgement required. |
| 2 | `SalesOrderToInvoiceConverter:273-284` | **classifier — register as such**, do not adopt the predicate into it. |
| 3 | `SalesOrderToDeliveryNoteConverter:566` | **one-line adoption — just do it** (w3ab inv F-4). |
| 4 | `PostCOGSOnInvoice:132-141` | **D-19's own site (i) — SELF-CLOSING: T17 deletes the file.** Record as *retired-by-cutover*, not adopted, so the count reconciles. |

**The three deliberate non-adoptions — the twin pair is kept SEPARATE from the four above:**
- **The twin pair (2 rows), V-11 ruling: SHIP NEITHER + RECORD THE CONTAINMENT ARGUMENT.**
  `SalesOrderToDeliveryNoteConverter copyLinesForFullDelivery` (`:257-266`) and
  `SalesOrderToInvoiceConverter:525-540`. They must be fixed **together or not at all**; the ruling is
  **neither**. The register must record **why shipping neither is SAFE, not merely consistent** — the
  relation-form vs scoped-form asymmetry (w3ab fiscal CONDITIONS §3). Shipping one alone breaks the
  containment.
- `DeliveredQuantityResolver:399-402` — rationale **VERIFIED line by line** by the fiscal re-review
  (key-gated consumption, no cross-product drain, mutable-flag + fail-closed direction). Do not adopt;
  do not re-argue.

✅ **CLOSED, do not re-open:** `PhysicalLinePredicate::physical(Builder)` scoping (inv F-5, FR1);
`DeliveredQuantityResolver` product_id-only keying (fiscal NEW-1 P1 / inv N-1 P2, closed at the root in
FR2 — predicate adopted at `:104` and `:201`).

### R-5 SET-BASED SQL PHYSICAL PREDICATES need a documented counterpart
*(w3e inv RE-REVIEW 1 riders.)* `InvoicedBeforeDeliveryScanner:84` and `UndeliveredGoodsLineScanner:92`
build physical-line filters as **`whereIn` subqueries** and **cannot use `PhysicalLinePredicate`**.
Without a documented SQL counterpart pinned to the PHP predicate they drift silently — and 3C's new
detector checks are built on exactly these scanners. **Deliverable:** one documented SQL form; a test
asserting PHP-predicate and SQL-predicate agree on a constructed mixed set; a comment on each naming
the other.

### R-6 NEW-3 — predicate coherence trades product_id-immunity for **mutable `is_physical`** exposure
*(w3ab fiscal RE-REVIEW 2, NEW-3.)* `is_physical` is a **mutable catalogue flag**: an edit after
delivery flips `goods_issued` false ⇒ `not_applicable` becomes acceptable for units that shipped.
**The architectural fix is 3C's movement-keyed reading** — COGS and the return basis key on
`stock_movements` rows (immutable history), never on a live product flag. State it in the seam's
docblock; enter it on the D-19 register. 3C needs no new code for it; 3C must **not reintroduce**
flag-keyed reasoning in the detector checks.

### R-7 LOCK ORDER — enter the guided composite's sequence into D-9.3′
*(w3e inv RE-REVIEW 1 + 2 riders.)* The 3E fix round's re-check **adds no further lock**. Verified
sequence:

> **invoice row (self) → DN chain head → `stock_levels` → invoice chain head**

Three obligations, all **mandatory**:
1. **Enter this sequence into D-9.3′'s resource table** — the first composite whose full order is known
   and written down.
2. **V-12 RULING: RECORD the C-1 hazard; do NOT realign `confirmDeliveriesAndPost` inside the cutover
   commit.** The cycle is **constructible but unreachable today** and the failure mode is `40P01`, not
   corruption. Realigning a shipped composite inside the atomic cutover trades a latent, non-corrupting
   hazard for live risk in the highest-stakes commit of the wave. Record it against a future lane.
3. **Note that the company-wide GL advisory 3C adds SPANS this frame.** The composite frame is longer
   than any single writer's, so D-9.3′'s hold-duration argument must be restated against **this** frame
   — T10a measured a shorter one.

### R-8 ✅ CLOSED ON THE INTEGRATED BASE — WAC float cast is GONE; only the QuantityScale follow-up remains
*(adversarial **F7**: Revision 2's "entry condition" was **stale**.)* The `(float)` cast at
`DeliveryNoteService` → `WeightedAverageCostService` (w3e inv **P2-8**, w3e fiscal **N-1**) **no longer
exists on the integrated tree.** Do not re-fix it; do not schedule it as an entry condition. **What
remains** is the standing follow-up (w3ab inv RE-REVIEW §6, fiscal **P3-2**): quantities routed through
`CurrencyScale::bcformatStrict` rather than `QuantityScale` in `DeliveryNoteService` /
`ReturnNoteService` — inert today, truncate-vs-half-up divergence **above 4 dp**, and therefore a
**trigger condition: it must close before any >4 dp product unit ships.** T14/T15 edit those
neighbourhoods; record the state, do not expand scope. Companion, FE-side P3: `parseFloat` at
`DeliveryConfirmationModal:74` — pre-existing, now the guided surface.

### R-9 V-10 — per-line `location_id` + FEFO loud refusal → **an M1 task** (ruling above)
The narrow fix only: `copyLine` carries `location_id`; FEFO failure becomes a typed loud refusal on the
guided path. The wider redesign stays in the **P2-7 ticket**. Red-first.

### R-10 D-f's arms: **POS + GOODS-RECEIPT in 3C; WO DEFERRED/STUBBED**
*(w3e fiscal **F-2** / inv **P2-4** routed the arms; adversarial **F2** corrected the WO half.)*
D-f shipped **DN-only** (`UndeliveredGoodsLineScanner:100` pins `reference_type='Document'`; POS writes
`'pos_receipt'`). 3C implements **the POS and goods-receipt arms**.
🚨 **The WO arm is DEFERRED and STUBBED, tied to the workshop-parts ticket.** Implementing it now
**contradicts the F-1 exemption ruling and would fire on every historical WO invoice** — the WO module
has **no stock-issuance lane at all**, so every WO line is a line-with-no-movement *by construction*.
The stub: a named, tested no-op whose docblock cites
**`docs/superpowers/tickets/2026-08-10-workshop-parts-goods-lane-gap.md`**, plus **a test asserting
the WO population is NOT reported.** See R-12 for both Workshop ticket paths.

### R-11 N-2 — PRE-DEPLOY DATA PROBE (pre-T4 non-physical deliveries)
*(w3ab inv RE-REVIEW carried §4.)* Run and record the count:

> `stock_movements ⋈ document_lines ⋈ products` where `reason = 'delivery'` **AND**
> `products.is_physical = false`

Pre-T4, stock could be issued against non-physical lines, and **those units have no return path**.
**Count 0 self-closes the item.** Non-zero ⇒ a **T19 release-note remediation section** — it must not
surface after the cutover, when 3C's seam is already relieving inventory for lines the return lane
cannot reverse.

### R-12 THE TWO WORKSHOP TICKETS — paths, and one that DOES NOT EXIST YET
*(w3e fiscal **F-1** ruling + RE-REVIEW closing line; w3e inv RE-REVIEW 1 **N-1**.)*
- **WO totals ticket — EXISTS:**
  **`docs/superpowers/tickets/2026-08-07-wo-quote-tax-inclusive-line-total.md`**
  (CRITICAL, pre-existing: `DocumentGenerationAdapter.php:199` persists a tax-**inclusive**
  `line_total` where the column is by contract net-before-tax, so conversions invoice VAT on VAT).
  Visible to 3C scheduling because it is **the same adapter** the F-1 exemption touches, and it
  corrupts the very line amounts 3C's aggregate assertions read.
- **Workshop-parts goods-lane ticket — EXISTS:**
  **`docs/superpowers/tickets/2026-08-10-workshop-parts-goods-lane-gap.md`**
  *(HIGH — fiscal + inventory + GL; pre-existing, made visible and load-bearing by 3E; raised by the 3E
  fiscal gate F-1 under ORCHESTRATOR RULING S3(b), "recorded, TESTED exemption + ticket").* **This is
  the ticket R-10's WO stub cites.**
  ⚠️ **It is committed on `feat/dpa-wave3-3e` and present on `integrate/wave3`, but it is NOT on `dev`
  yet — it lands with the integration merge. Verify its presence at SHA-pin time, as part of M0.**
  *(Methodological note, and the fifth occurrence of this brief's own R-2 lesson: an earlier revision
  recorded this ticket as unfiled because the search ran against the **working tree** rather than
  against the refs. A `grep` over one checkout is not an existence proof for a multi-branch program —
  use `git show <ref>:<path>` across every live ref. Enumerate; never read.)*
- **Do not regress 3E's exemption ladder:** FR2 threaded `posting_context` +
  `delivery_requirement_exempted` through `InvoicedBeforeDeliveryScanner::scan()` into T24's response
  and UI, with a **three-way default ladder including `'unknown'` for pre-FR1 stamps**, three UI states
  en+fr. T24's claim is now *"empty **except recorded exemptions**"* — not "empty".

### R-13 ✅ SHIPPED — fiscal N-2's boundary-coverage tests landed in FR2
*(adversarial **F7**.)* WO context vs `DraftDeliveryNotes` / `IncompleteDelivery` verdicts is covered.
Nothing owed in M3. Informational residuals from the same round: N-3 (stale docblock at
`WorkOrderInvoiceDeliveryExemptionTest:40-44` should point at DEVIATION 6), N-4 (the 3E report
mislabels F-10/F-11 as pre-existing).

### R-14 F-7 — 3C must **NOT** add a product lock inside the projection
The snapshot-after-save **unlocked** product read is the **correct** lock-order call (advisory →
`stock_level` → product); POS never re-averages (`avg_cost_*` pinned NULL). **Adding a product lock
re-creates the ABBA that D-9 exists to remove.**

### R-15 F-8 — round HALF-UP once at the GL boundary
`total_cost`'s `bcmul` truncates (≤ 1e-6/line, byte-identical to `StockAdjustmentService`). **3C's COGS
aggregation rounds HALF-UP once at the GL boundary** — D-4's arithmetic, T11's pinned regression:
`3 × 1.6666666` at scale 3 posts **`5.000`, not `4.999`**. Do **not** align to the truncating row value.

### ✅ Closed since draft 1 — verify in passing, do not re-open
`PosCoreReceiptProjection` zero-cost soft-delete (fiscal **P2-1**, `withTrashed()` + both branches
tested, rated *Critical-closed*; D-b still covers the residual population) · predicate scoping (inv
F-5) · resolver product_id keying (fiscal NEW-1) · the standalone-path GR test (inv F-3, closed via a
two-sided sensitivity pair through a shared `sampleAdvisory()` — **rated stronger than revert-replay;
T11c should reuse that instrument shape**).

---

## 🏗 MILESTONE STRUCTURE

**3D is STRICTLY AFTER 3C.** Revision 2 proposed running M4 in parallel; adversarial **F1** proved that
contradicts plan §4.1, which draws 3D wholly downstream of the seam. **The parallelization is
withdrawn.** Everything is sequential.

```
M0  preflight — artifacts only, no production code                  [dual-gate scoped review]
 │
 └─► M1  3C seam infrastructure + T15a + T16c audit + V-10 fix       [dual gate]
      │
      └─► M2  🚨 THE CUTOVER COMMIT — the plan's exact atomic list    [dual gate]
           │
           └─► M3  3C tail — detectors, D-f POS+GR, C-5, R-5, T18/19/19b   [dual gate]
                │
                └─► M4  3D purposes — T20, T20b               [inv + treasury gate]
                     │
                     └─► M5  3D listener — T21, T22           [inv + treasury (+fiscal, see trigger)]
```

### M0 — Preflight (artifacts only)
1. **Verify `HEAD == BASE_SHA`**, the tree is clean, and **the two Workshop tickets are present on the
   pinned base** — `2026-08-07-wo-quote-tax-inclusive-line-total.md` and
   `2026-08-10-workshop-parts-goods-lane-gap.md` (the latter arrives with the `integrate/wave3` merge;
   R-10's stub cites it, so its absence means the base is not the integrated tree).
2. **🚨 THE MECHANICAL CITATION INVENTORY** *(adversarial F8 — a prose "re-derivation table" is not
   completeness)*. Four steps, in order, with the output committed:
   - **EXTRACT** — machine-extract every `file:line` citation from this brief **and** from
     plan-wave3.md §§0–4 into a list. No hand-picking.
   - **COUNT** — record `N_extracted`. This number goes in the report.
   - **MAP** — for each citation produce a row: `old:line → new:line`, **the symbol it names**
     (method/class/statement), **and a semantic assertion** ("the last statement inside `apply()`'s
     `DB::transaction` closure"). A row without a symbol and a semantic assertion is **unresolved**.
   - **VALIDATE** — **M0 FAILS if `unresolved > 0`.** Not "flagged": fails.
   - **Replace stale literals with semantic anchors** everywhere the implementation will reference
     them. Line numbers are evidence, not addresses.
   - **RE-RUN THE INVENTORY IMMEDIATELY BEFORE M2** and record the second `unresolved = 0`. The
     cutover commit is the one place a stale address is unrecoverable.
   - **Two live examples already known to have moved** — use them to validate the method:
     - the POS flush point: **`:457` → after `:476`, closure now ends `:477`**;
     - **C-1's transaction (`InvoiceController::confirmDeliveriesAndPost`) is now `:803`.**
3. **The D-28 caller sweep** — every writer in D-9.2′'s table × call sites × transaction depth. Output
   the register: C-1, C-2 (`RefundService::cancelInvoice`, an **arrow fn that must become a block
   body**), **C-3** (controller closure, per V-4), C-4 (**EXEMPT**, R-3), **C-5** (T25c).
4. **T11c's fixture set, DERIVED FROM THAT SWEEP** — not from the plan's lanes.
5. **The POS refund cost-basis proposal (R-1)** with the distinguishing test.
6. **The R-11 pre-deploy probe**, with its integer count.
7. **The D-19 register (R-4)** — 18 rows, all four assigned enumerated, the twin pair separate with the
   containment argument recorded, NEW-3 (R-6) entered.

### M1 — 3C seam infrastructure (+ T15a, T16c, the V-10 fix)
**T11** (buffer + service + `MovementGlContext` + the six-rung ladder, §2.0/§2.1 **exactly**, D-4's
arithmetic verbatim, `absoluteDeltaForRow()` on `StockMovement`), **T11e** (PHPStan: writers may only
enqueue; `postFor*` callable **only** from `flushIfOutermost()`), **T12**, **T13**, **T11c**,
**T15a** *(moved here — F5/V-3: it is a pure resolver with its own tests, and pre-cutover placement
lets T15's basis be proven before the atomic commit)*, **T16c** *(moved here — F5/V-5: an audit, not a
code change)*, and **the V-10 narrow fix** (R-9).

- **ONE NAME EVERYWHERE: `flushIfOutermost()`.** There is no `flush()` in the API.
- Buffer bound **`scoped()`**, never a singleton.
- **The leak-alarm test needs a NAMED MECHANISM.** `DB::afterCommit` never fires under
  `RefreshDatabase` (§0b.9). Use T1's `connectionsToTransact(): array { return []; }`, or any
  equivalent you **prove**. **Acceptance: the test must FAIL when the alarm registration is removed.**
- **T11c is TEN pairs.** *(V-1 — recorded for the plan's benefit: the plan's prose still says "all five
  pairs" while both its tables list ten; ten is correct, the prose was never updated when Rev 3 added
  6–8 and Rev 4 added 9–10.)* Acceptance, red-before/green-after: pairs **4 & 5** (T5b, landed —
  re-prove on the merged tree), **7 & 8** (T16d), **9 & 10** (T16e), **6** green once D-28 is wired
  *and* proven to post every DN's entries in ONE flush. T11c must also **prove** the carried
  cannot-verify: what PostgreSQL does with a transaction-scoped advisory acquired inside a subtransaction
  that later ABORTS. Its I-1 formulation encodes R-3's corrected invariant, and its GR pairs account for
  `GoodsReceived` firing with PO status `Received`.
- **If any pair cannot be made green, D-9's architecture is wrong and 3C STOPS.** Report; do not work
  around.
- **T16c's negative branch is INAPPLICABLE** *(F5/V-5, evidence: `applyScrapPair`)* — the symmetric
  restore/write-off pair in `ReceiptReturnService`'s interactive path **is live**. The audit records
  that finding; the "if it does not write a pair, record the finding" branch is dead and is marked so.
  **Consequence for M2:** the interactive pair therefore **gets D-23's treatment and must be buffered
  under T16d** — carried into the cutover commit, not left inline.
- `[PG]` throughout; skip loudly, never silently. Use the trace instrument (not `pg_locks`, which
  false-passes under `RefreshDatabase`); reuse the two-sided sensitivity-pair shape from closed F-3.

### M2 — 🚨 THE CUTOVER COMMIT — the plan's exact atomic list
**T14 + T15 + T16 + T16b + T16d + T16e + T17, in ONE commit.** *(F5: restored to the plan's list —
T15a and T16c moved to M1 and are NOT members.)* Nothing between them may be a separately deployable
state. **T16e is a hard prerequisite of T16** within the commit: until the voucher redemption moves, the
advisory is taken before the stock loop and T16's flush placement is meaningless.

**T16d's scope now includes the interactive pair** (from T16c's M1 finding) alongside the projection's
scrap pair — both under D-23/D-23′, both buffered, atomic with each other.

**T16e's PROOF OBLIGATION is non-waivable.** The remedy is a **statement reorder**, not buffering —
buffering the voucher entry would drag the Voucher module into the seam, need a fifth `MovementGlKind`
for a non-movement entry, and change a shipped GL path's mechanism, for a site whose only problem is
*ordering*. **PROVE, not assert**, that nothing in `applyStockMovementForLines` depends on
`redeemVouchers`' writes and nothing in `redeemVouchers` depends on the stock legs (`redeemVouchers`
consumes `$receiptId / $event / $view / $receiptType`, writes `voucher_ledger` + GL;
`applyStockMovementForLines` consumes `$receiptId / $event / $terminal / $view / $receiptType` —
**neither reads the other's output**). Pin with a test. Characterise **byte-level**: the shipped voucher
suite green with byte-identical `voucher_ledger` rows and GL entries, **`entry_date` unchanged.**

**The R3-2 line-number record, BOTH values, as Revision 4 recorded them:** the fiscal reviewer's
replacement citations were **WRONG** — **`:438` is `writePayments`, not `redeemVouchers`**, and the
closure **ends `:458`, not `:459`**. The inventory reviewer's NEW-3 had it right; Revision 4 uses
`:457` and records both. A flush outside the closure runs **in autocommit**, where the advisory
degrades to a no-op (duplicate `chain_sequence`) and a post-commit throw leaves the entry Draft forever
— the failure V10 documents at `ReturnScrapWriteOffService.php:209-213`. **All three of these numbers
are now stale** (M0's example 1) — carry the *semantics*, re-derive the *addresses*.

**D-28's composition contract is what makes M2 correct.** `flushIfOutermost()` posts only at
`transactionLevel() === 1`; sound **only if a depth-1 frame actually calls it**. Silent zero COGS is a
worse failure than the deadlock this design removes. Four guards: the alarm (never posts), D-a as ledger
backstop, a test per composite, and **the register-independent structural leak test**.

**I-1 as the frame obligation:** *before wiring a flush into any root frame, enumerate **every**
collaborator that frame invokes and confirm none reaches `GeneralLedgerService` before the frame's last
inventory lock. A writer's own lane being clean proves nothing.* Every violation so far — GR's listener,
V10's scrap service, the POS voucher collaborator — was found by looking **outside** the writer.
**R-7's composite sequence is now part of that table.**

**T14 registers the controller closure as C-3** (V-4 ruling, R-2) — no alternative to choose.

### M3 — 3C tail
T18 (rework the COGS test surface; delete `createCOGSEntry`; **move, never lose**, the
no-`CompanyContext` chain-sequence coverage onto `createInventoryMovementEntry`), T19 (deploy
precondition script + release note with all seven required additions incl. 4a/4b/4c, the
`reverseDocumentGl` advisory ticket, and **R-11's remediation section if the probe returned non-zero**),
T19b (`accounting:reverse-inventory-movement-entries` — written, **never run** in the normal path; its
existence is what makes D-13's forward-only posture honest), the **routed detector checks
D-a / D-b / D-e / D-g** per D-26 / §2.3, **D-f's POS + GR arms** (WO stubbed, R-10), **C-5's
registration + flush tail**, and **R-5's documented SQL counterpart**. Preserve R-12's exemption ladder.

#### D-f arm contracts *(adversarial F9 — derived from D-c's scanner shape + D-26/§2.3)*

**Shape, both arms** — line-level anti-join, with D-c's set-based-first-pass discipline (T23's Risk
note): population = lines on **physical + stock-tracked** products, on a source **above the
`inventory_gl_cutover_at` watermark**; anti-join key = `stock_movements (reference_type, reference_id,
product_id)`; **fires when no movement row exists at all.**

| Arm | Source lines | `reference_type` / `reference_id` | Status |
|---|---|---|---|
| `delivery_note` | DN lines | `'Document'` / document id | **SHIPPED in 3E** — unchanged |
| `pos` | POS receipt lines | **`'pos_receipt'`** / receipt id (the literal the projection writes at both create sites) | **3C implements** |
| `goods_receipt` | GR lines | 🚨 **NOT ASSUMED** — M0's inventory must establish the literal GR movements actually carry, as a semantic anchor. **M3 fails if it cannot be established**; do not guess it from the DN arm. | **3C implements** |
| `work_order` | — | — | **DEFERRED/STUB** (R-10) + a test asserting the WO population is **not** reported |

**Output contract** — extends §2.3's: `Log::warning` + structured context + non-zero exit so
`->onFailure(...)` fires. `check` widens from `a|b|c|d` to **`a|b|c|d|e|f|g`**. D-f rows additionally
carry **`arm` ∈ `pos|goods_receipt|delivery_note`**, `line_id`, `product_id`, the source id
(`document_id`/`receipt_id`), `document_number`, `document_date`, `age_days`. **`amount` is `null` for
D-f — there is no movement, therefore no cost. Emit `null`, never `0`**, or D-f rows become
indistinguishable from D-b's zero-cost population.
**Grace window: D-f's POS arm carries NO grace window.** The plan assigns the 2h grace to **D-a
ONLY** (D-26, `plan-wave3.md:2291-2299`; T23 tests, `:3175-3185` — "D-a respects the 2h grace
window"). D-f fires for a qualifying POS line on a physical, stock-tracked product with **no
`stock_movements` row at all** — period, no age gate. Do not add a grace window to D-f "because POS
syncs late": the watermark, not a grace window, is D-f's cutover protection, and a missing movement is
a missing movement whatever its age. This is not an M3 decision; it is the plan, transmitted literally.

#### 🔎 Derived obligation for the reviewers to confirm — D-e vs the adjustment population
D-e keys on **`requiresGLEntry()`-bearing movements with no entry**. Per F3 (below), adjustment reasons
**are** `requiresGLEntry() = true` and are **deliberately never posted** (D-20). **D-e must therefore
carry the same `reference_type = 'stock_adjustment'` exclusion D-b carries**, or it reports every
adjustment as a missing entry and D-20's decision reads as a permanent detector fire. This is a
*completion* of F3's prescription, derived here rather than stated in the register — **flagged for
explicit reviewer confirmation at the M3 gate.**

### M4 — 3D purposes (after M3)
T20 (new purposes ×3 seeders + `BackfillInventoryShrinkagePurposesCommand`, copied from
`BackfillTolerancePurposesCommand`: purpose-first/code-second precedence, `Schema` fail-closed guards,
the pinned `SUMMARY_TOKEN_PREFIX` printed at the end because `tenants:run` swallows exit codes) and
T20b.
- **The V-7 ruling governs the codes:** T20 **proposes** the TN/FR/Generic × shrinkage/gain map with
  per-seeder accounting rationale in the docblock; **treasury-reviewer approval is required before T20
  merges.**
- **Rebase onto SEEDS and extend — the only live branch.** Do **NOT** re-add `CostOfGoodsSold`,
  `GeneralExpense` or `CustomerAdvance`: shipped. Wave 3 adds **only** the new shrinkage/gain purposes.
  Add a regression guard asserting the three SEEDS-owned purposes are present and untouched.
- **T20b is expected GREEN — and a green test is therefore NOT evidence** (see the evidence table).
  The skip-with-reason fallback is **STRUCK**; never green-by-exemption.
- **SEEDS' own lessons:** a backfill migration's `catch (Throwable)` inside `tenants:migrate` hits
  SQLSTATE **25P02** on PG (aborted transaction ⇒ connection unusable ⇒ the bookkeeping INSERT fails
  anyway); the proven remedy is a **connection-bound savepoint wrapper**. A deploy token at `Log::info`
  passes **vacuously** under production `LOG_LEVEL=warning` — emit at warning level with a distinct
  token.

### M5 — 3D listener
T21 and T22 (**a ticket, by ruling; not code**).
- **The call site is `postCountCorrection`, dispatched from the replay path, which passes NO `unitCost`
  at all.** `postCountOpening` writes `MovementType::Opening` / `MovementReason::OpeningBalance` —
  **outside the seam**. Threading the cost into the legacy site only makes the replay path post
  `amount = 0` → rung 5 → **silent zero-value shrinkage on the path that handles counting replays**.
  Both counting paths are NULL-cost today; there is no asymmetry.
- **The flush needs a ROOT FRAME that does not exist.** `handle()` opens **no** transaction; the
  `DB::transaction` is **per item**. "After the loop" is depth 0 (never posts; D-10 forbids autocommit
  posting); flushing inside the loop takes the advisory before item N+1's locks ⇒ **I-1 violated**.
  **RULING, not a choice: wrap the item loop in ONE `DB::transaction` in `handle()`, enqueue per item,
  flush at that transaction's tail.**
- **State the consequence and pin it:** per-item boundaries become savepoints, so an item-N failure no
  longer commits items 1..N-1. The listener is `ShouldQueue` with `$tries = 3`; the retry re-runs the
  whole counting, already idempotent via the `replay_audit` marker and the existing-movement probes.
  **Pin: a counting whose item 3 throws leaves ZERO movements and ZERO entries, and the retry produces
  exactly one of each.**
- **The replay-path test MUST run against a movement created by `postCountCorrection`** — asserting on
  an `OpeningBalance` movement is green-by-vacuum.
- 🔁 **`adjustByDelta` and D-20 — Revision 2's V-9 was WRONG** *(adversarial F3)*. Revision 2 asked to
  confirm the adjustment movement keeps `requiresGLEntry() = false`. **It does not: adjustment reasons
  are `requiresGLEntry() = TRUE` in the live enum, per D-20.** The no-GL outcome is **a wiring
  decision, not an enum property.** Therefore: **thread `unitCost`** onto the adjustment movement (the
  D-20 half-fix that un-blinds D-b), **exclude the adjustment path from the buffer** (that is what
  enforces "no GL leg"), and **preserve D-b's `reference_type='stock_adjustment'` exclusion.** Do not
  "fix" the enum. See the M3 derived obligation for D-e's matching exclusion.

---

## 📋 PER-MILESTONE EVIDENCE CONTRACT *(adversarial F10)*

Non-code milestones need **honest evidence forms** — "tests pass" is not available to them, and a green
test is not evidence when green was the prior state.

| Milestone | Code evidence | Artifact / non-code evidence | The evidence that is easy to fake, and its antidote |
|---|---|---|---|
| **M0** | none (no production code) | `BASE_SHA` verified == `HEAD`; citation inventory: `N_extracted`, `N_mapped`, **`unresolved = 0`** (M0 **FAILS** otherwise); D-28 sweep as a writer × call-site × depth table; D-19 register, 18 rows, 4 assigned enumerated; R-11 probe SQL **and its integer count** | A prose "I checked the line numbers" claim. **Antidote:** the reviewers independently re-run the sweep for **two writers of their choosing** and confirm identical depths. |
| **M1** | red-first + revert-replay per task; PG-by-path counts; phpstan/pint | T16c audit: the `applyScrapPair` evidence and the explicit **"negative branch inapplicable"** record | An alarm test that cannot detect the alarm's absence. **Antidote:** it must **FAIL when the alarm registration is removed**. T11c pairs: the `40P01` counts, red-before **and** green-after, per pair. |
| **M2** | ONE commit; byte-identity characterisation for T16d/T16e (`voucher_ledger` rows, GL entries, `entry_date`) | the citation inventory **re-run**, second `unresolved = 0`, dated immediately before the commit | A green suite proving only the happy path. **Antidote:** T16's **contained-failure** assertion — force the flush to fail once per mechanism and assert **zero** entries for that line. |
| **M3** | each detector check fires on a constructed positive **and stays silent on a constructed negative** | T19 release note: the seven sections present, incl. R-11's remediation section if the count > 0; T19b's tests green though the command is never run | A watermark that silently reports everything. **Antidote:** a **pre-watermark** movement with no entry must be **silent** — the assertion that makes the rollback signal usable. Plus: WO population **not** reported. |
| **M4** | backfill idempotent (run twice, second run changes nothing) | the proposed code map with per-seeder accounting rationale; **treasury-reviewer approval recorded** | 🚨 **T20b is expected green, so green proves nothing.** **Antidote — both required:** (i) **green baseline** on the integrated base, recorded; (ii) **mutation proof** — remove one purpose from one seeder, show T20b goes **RED**, restore, show green. |
| **M5** | T21 on **both** paths; `entry.amount == row.unit_cost × abs(Δqty)` **exactly**, on both | T22 is a **ticket file**, not code — the deliverable is the file, citing both source files | A replay test that asserts on an `OpeningBalance` movement. **Antidote:** the replay case must be driven through **`postCountCorrection`**. Plus the item-3-throws pin: zero movements, zero entries, retry yields exactly one of each. |

---

## 📏 HOUSE RULES (binding, every milestone)

- **TDD red-first per task**; **revert-replay** every fix commit (revert, prove the covering test goes
  red, restore).
- **Tests BY PATH only.** The full PHPUnit suite is **forbidden** — it crashes the machine. The declared
  regression set must include **every suite directory the diff touches** (standing rule adopted after
  the advrev I-A blind spot shipped a red `tests/Unit` case).
- **Never `git stash`** — the stash stack is repo-global, shared across worktrees.
- **Rule 19 money discipline.** `CurrencyScale::bcformatStrict` / `QuantityScale`; explicit currency in
  queued/console/projection contexts (`getScale($currency)` / `getScaleSafe($currency, 3)` — a bare
  no-arg `getScale()` throws there); no floats near money or quantity. See R-8, R-15.
- **A real PostgreSQL run before ANY green claim.** Local PG on 5432 (Docker 5433 is broken).
  `[PG]` tests skip **loudly** on other drivers. Known artifact: SQLite renders decimal casts as
  `'1000'` where PG renders `'1000.000'` — shape tests are PG-asserting by design.
- **Migrations/backfills idempotent + unattended-safe.** `origin/dev` auto-deploys and runs
  `tenants:migrate`. See M4's 25P02 note.
- **en + fr i18n** for every user-facing string — including V-10's new typed FEFO refusal.
- **Strict types**; constructor injection with `private readonly`; no `app()` helper, except the
  documented test idiom (`app(CompanyContext::class)->clear()`).
- **Rule 8** events immutable forever. **Rule 6** module boundaries via `Shared/Contracts/`, events, or
  a module's public service class.
- **Sealed bytes:** nothing in 3C/3D changes what hashes. The invoice fiscal hash does **not** include
  `payload` (§0b.10) — which is what makes `return_cost_basis[]` a legal write.
- **`pint` + `phpstan` level 8 clean on touched files**; `deptrac` must not regress baseline 111.
- Dedicated `git worktree`. Do not commit to a shared `dev` worktree. Do not push.

---

## 🚦 REVIEWER GATES

**Dual gate at EVERY milestone.** No milestone merges on one half.

| Milestone | Reviewers |
|---|---|
| M0 | **inventory-costing + fiscal-pos** — is the sweep exhaustive? is `unresolved = 0` real? is the D-19 register right? is the R-1 option defensible? |
| M1 | **inventory-costing + fiscal-pos** |
| M2 | **inventory-costing + fiscal-pos** — the fiscal half owns T16/T16d/T16e byte-identity and the sealed-bytes surface |
| M3 | **inventory-costing + fiscal-pos** — plus the D-e exclusion confirmation flagged above |
| M4 | **inventory-costing + treasury-reviewer** — **treasury approval of the code map is a merge condition (V-7 ruling)** |
| M5 | **inventory-costing + treasury-reviewer**, **+ fiscal-pos iff the mechanical trigger fires** |

**The M5 fiscal-pos trigger, mechanically** *(adversarial F9 — Revision 2's "if it touches a sealed
path" was a judgement call)*. Run and record:

```
git diff --name-only <M5_base>..<M5_head>
```
**fiscal-pos-reviewer is REQUIRED on M5 iff that set intersects any of:**
(a) `app/Modules/POS/**` · (b) any file named `GeneralLedgerService.php` · (c) any file whose diff
hunks reference `fiscal_events`, `chain_sequence`, `sealAndPersistEntry`, `postEntryNow`, or `hash` ·
(d) `database/migrations/**`.
Otherwise M5 gates on inventory-costing + treasury only. **The check is executed and its output pasted
into the handback — not judged.**

**R-1's POS refund cost-basis ruling is decided AT THE 3C GATE.** The orchestrator recommendation
stands until then. **Do not merge T16 with the question open.**

Gate loop follows the DPA program: fix rounds ≤ 5, scoped re-reviews, verdicts to a **file** (never
inline).

---

## 📦 DELIVERABLE

**Two branches, NOT merged, NOT pushed. The ancestry is a two-step rule, not two branches off one
base** — 3D is created only after gated 3C is on `dev`, so it inherits the seam:
1. **`codex/dpa-wave3-3c`** — created from **`BASE_SHA`**. Carries M0 → M1 → M2 → M3.
2. Gate 3C, then merge `codex/dpa-wave3-3c` to local `dev`. **Record a new `3D_BASE_SHA`**
   (`git rev-parse --verify dev` after that merge).
3. **`codex/dpa-wave3-3d`** — created from **`3D_BASE_SHA`**. Carries M4 → M5.
   (M5's T21 reads M1's seam, which is now on `dev` under `3D_BASE_SHA`; M4's purposes are its own.)

**M5 branch choice, and why (adversarial F9 required one):** **M4 and M5 share ONE 3D branch.** With F1
removing the parallelization, 3D is wholly sequential, so splitting buys no concurrency. T21 needs
**both** T20's purposes and M1's seam, so a split would create a cross-branch dependency for nothing.
And M4/M5 share the treasury-reviewer gate lane — one branch keeps one lane, one rebase, one merge
record. The only argument for splitting was parallelism, and F1 removed it.

Report file: `docs/sessions/codex-dpa-wave3-3c-3d-report.md`, per task: files touched, tests + commands
+ **actual output**, decisions taken, deviations with rationale, concerns. The orchestrator merges only
after every gate passes.

---

## ❓ OPEN AT DISPATCH — one item

**The base SHA.** Everything else is disposed: all 12 of Revision 2's VERIFY items carry
evidence-backed dispositions from `review-3c3d-handover-2026-08-10.md` (V-1 ten pairs · V-2 resolved ·
V-3 T15a→M1 · V-4 register the controller closure · V-5 negative branch inapplicable · V-6 D-e/D-f
boundary drawn, POS+GR now, WO stubbed · V-7 ruling · V-8 no parallel M4 · V-9 per F3 · V-10 ruling ·
V-11 ship neither twin · V-12 record, do not realign).

**One orchestrator action owed, not Codex's:** confirm the **treasury-reviewer** is available for the
M4 gate, since V-7 makes that approval a **merge condition** rather than a review opinion.

*(Both Workshop tickets exist and are cited in R-12. The workshop-parts goods-lane ticket arrives on
`dev` with the `integrate/wave3` merge — M0 verifies its presence at SHA-pin time.)*
