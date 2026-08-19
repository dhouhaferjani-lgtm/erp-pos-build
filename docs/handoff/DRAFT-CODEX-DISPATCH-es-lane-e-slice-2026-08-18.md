# ⚠️ DRAFT — Codex A→Z dispatch: Event-Sourcing remediation, **Lane E, launch-critical slice** (2026-08-18)

> **THIS IS A DRAFT. DO NOT DISPATCH.**
> The parent orchestrator gates this brief (round-0 mechanical precheck + adversarial brief gate) before
> it becomes a `CODEX-DISPATCH-*` file. Until then: **no progress YAML exists**, `base_sha` is unpinned,
> and the three parent decisions in §10 are unresolved. A §11 YAML *skeleton* is supplied for the parent
> to instantiate at dispatch — it is a template, not a live wave file.

**Wave name (proposed):** `es-lane-e-slice`
**Programme:** Event-Sourcing remediation (`docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md`)
**Lane:** E — *Stock writers & batch documents* (theme T6)
**Slice:** two rows only. **ES-26** (document-less batch inter-location transfer) and the **POS half of
ES-28** (POS sale/refund stock writers emit no `StockMovementRecorded`).
**Reviewer lenses:** `inventory-costing` (every milestone), `fiscal-pos` (the projector milestone),
`general` (preflight + whole-branch gate).
**Harness:** `docs/handoff/SELF-REVIEW-HARNESS.md` — self-gating, three STOP conditions, handback only.

---

## 0. Read before starting (in this order)

| # | Document | Why |
|---|---|---|
| 1 | `docs/handoff/SELF-REVIEW-HARNESS.md` | The loop you run. Read it once, in full, before M0. |
| 2 | `docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md` — **§3** (lane structure + per-lane entry criteria), **§4** (non-negotiables), **§5** (verification contract), **§7** (what this session must NOT do), **§8** (open owner questions) | The method. §5 is the contract you ship against; §3's Lane E entry criteria bind you. |
| 3 | `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/00-CONSOLIDATED-REGISTER.md` rows **ES-26** (`:92`) and **ES-28** (`:94`); theme **T6** (`:192`) | The register is the contract. Do not re-derive severity. |
| 4 | `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/03-inventory-costing.md` — **C-1** (batch transfer), **G-1** (five silent stock writers), **OK-1** (the reference implementation you copy), **§6** fix ordering | The evidence behind the two rows, with file:line. |
| 5 | `docs/handoff/OWNER-QUESTIONS-es-remediation-2026-08-11.md` — **D-12** (= Handover Q6), **D-10** (= Handover Q3) | The two owner items that touch this slice. |
| 6 | `apps/erp/CLAUDE.md` rules **4, 6, 8, 9, 13, 17, 19, 20, 21** | Standing constraints, restated in §7 below. |
| 7 | `apps/api/app/Shared/Domain/Enums/StockMovementReferenceType.php` (whole file, esp. `:28-35`) | DPA S0's vocabulary and its *explicit* deferral of the POS writers to "their own lanes". **You are that lane.** |

**Do NOT touch** `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/` — it is the audit's record (handover §7).

---

## ⛔ HARD PREREQUISITES

### 1. LANE ENTRY CRITERIA — one is an owner ruling, and it is NOT yours to make

Handover §3, *Per-lane entry criteria*, **Lane E**:

> *"Owner ruling on ES-26 (does a batch inter-location transfer get its own document type, or route
> through `StockTransferService`? §8 Q6). ES-28's fix must land **before** the channel/ecommerce lane
> goes live, or the channel advertises pre-sale quantities. ES-60 must fix the row **at insert** …"*

- **D-12 (= Q6) must carry a RULING before this wave is dispatched.** The parent pins the ruling into
  the wave YAML (`owner_rulings.D_12`). **M0 verifies the ruling is present and non-null; if it is
  null, set `status: blocked_precondition` and STOP.** You may not choose the domain model.
- The brief's **M3 is written against Option B** (route through `StockTransferService`, retire the
  document-less path) because that is the parent's recommendation (§10 PD-1, with the evidence).
  **If the owner rules Option A (own document type), M3 must be re-briefed by the parent before
  dispatch — do not improvise Option A from this text.**
- **D-10 (= Q3, V1/V2 disposition) is NOT an entry criterion for this slice** and must not be
  pre-empted. See rider **R-6** for exactly what you emit and why it decides nothing.
- **ES-60 is OUT of scope** (see §2). Its entry criterion is quoted here only so you do not "helpfully"
  fix it — see rider **R-7** for the coupling it creates with M3.

### 2. PIN THE BASE SHA — execute, paste verbatim into the M0 report

```
git -C <repo> rev-parse --verify dev            # → BASE_SHA
git -C <repo> log -1 --format='%H %ci %s' dev   # → paste verbatim
git -C <repo> status --porcelain                # → MUST be empty
```

`BASE_SHA` goes in the YAML (`base_sha:`), in M0's report header, and in the session report.

**M0 base check** (three assertions; the pin commit itself advances `dev`, so strict equality can never
hold):

1. **`base_sha` is an ancestor of `HEAD`** — `git merge-base --is-ancestor <base_sha> HEAD`.
2. **The DPA seam exists at `base_sha`** — this slice *builds on* DPA S0 and DPA w3a; if either is
   absent the brief's premises are false:
   ```
   git show <base_sha>:apps/api/app/Shared/Domain/Enums/StockMovementReferenceType.php | grep -n "case Document = 'Document'"
   git show <base_sha>:apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php | grep -n "movementCostSnapshot"
   ```
   Both must hit. (S0 = commit `d2db56378` / `9b825b96a`; w3a T2 = `f097edfeb`; w3a T5 = `1cbb2e631`.)
3. **`git diff --stat <base_sha>..HEAD` touches ONLY administrative paths** —
   `docs/handoff/**`, `docs/superpowers/reviews/**`. Any other path ⇒ the base is **contaminated**:
   `blocked_precondition`, STOP, request a re-pin. **Do not edit the pins yourself.**

> **Parent fills at dispatch:** `BASE_SHA = <TBD — local dev tip at dispatch>`.
> The draft was researched at `96c9193ad7e44e17f233d591771da396e643a2d2`
> (*"ledger: G-1 CLOSED — gate ticket close-out header confirmed present"*). Every file:line in this
> brief is true at that commit and **must be re-derived at `BASE_SHA` in M0** (§4 R-1).

### 3. WORKTREE

Dedicated worktree **`.worktrees/es-lane-e-slice`**, branch **`codex/es-lane-e-slice`**, created from
`BASE_SHA` off **local `dev`**.

- Never commit in a shared `dev` worktree (CLAUDE rule 21).
- **Never `git stash`** — the stash stack is repo-global across worktrees.
- **The executor never merges and never pushes.** Not to `dev`, not to `origin`, not "just the branch".
  The deliverable is an unmerged local branch plus its evidence trail (§9). The orchestrator merges.

---

## 🎯 SCOPE — two rows, and exactly two

### IN SCOPE

| # | Row | Register | Severity | Trigger per register |
|---|---|---|---|---|
| **S-1** | **ES-26** — batch inter-location transfer mutates physical stock with **no `stock_movements` row, no `stock_levels` change at either location, no document, no event**; `reference` and `userId` are accepted and silently discarded. Post-condition: `SUM(inventory_batch_stock)` per location **permanently** ≠ `stock_levels.quantity` per location | `00-CONSOLIDATED-REGISTER.md:92`, detail `03-inventory-costing.md` **C-1** | **GAP-CRITICAL** · CONFIRMED | register says *"POST (single-location at launch) — **LAUNCH the moment a 2nd location exists**"*. **The parent has re-scoped this to LAUNCH-CRITICAL on the ground that the first tenant uses stock transfers daily** — see §10 **PD-2**, which must be confirmed, because the register's own trigger disagrees with the dispatch premise. |
| **S-2** | **ES-28, POS half** — POS stock writers create `stock_movements` rows but never emit `StockMovementRecorded`; the event's only production consumer is `Channel\...\DispatchStockChangeToChannels`, so **every POS sale, refund and void fails to sync channel stock** | `00-CONSOLIDATED-REGISTER.md:94`, detail `03-inventory-costing.md` **G-1** | **GAP** · CONFIRMED | register says *"POST (channels not enabled) — **hard blocker for the ecommerce launch commitment**"*; handover §3 interactions: *"ES-28 is a **hard prerequisite** of a correct channel launch."* |

### EXPLICITLY OUT OF SCOPE — note and continue, never absorb (CLAUDE rule 4)

- **All other Lane E rows**: ES-27, ES-59, ES-60, ES-61, ES-62, ES-63, ES-64, ES-65, ES-66, ES-67,
  ES-68, ES-84.
- **The fifth ES-28 writer** — `Procurement\...\SupplierCreditNotePostingService` bonus return
  (`app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:668`). It is a
  **procurement** writer, not a POS sale/refund writer. **Consequence, and you must state it in the
  handback: this slice does NOT close ES-28.** The register row stays OPEN with its POS half
  discharged and its procurement half outstanding. Do not write "ES-28 closed" anywhere.
- **Lanes C, D, F, G, H, I, A0, A1, B, X** — all rows.
- **Any fiscal event schema change.** Nothing in this slice may alter what hashes, what is sealed, or
  the `fiscal_events` table. No new fiscal event type, no payload reshaping, no canonical-bytes change.
- **ES-60's post-hoc `markMovementAsTransfer` UPDATE** (`StockTransferService.php:701-708`) — see
  rider **R-7**; you inherit it, you do not fix it.
- **Wave-3 (document-per-action) inventory/COGS tasks** — handover §3 interactions table: *"do not
  touch Wave-3's inventory/COGS tasks from Lane E — check the Wave-3 ledger (`progress.md`,
  authoritative) for overlap … before starting."* **M0 does that check and records the result.**
- **The rule-19 float-cast lines carried by ES-84** (`StockReservationService.php:267-283`, `:391-407`)
  — handover §3: *"Hand those lines to the precision lane … do not smuggle a precision change into an
  event commit."*
- **`ensureDefaultBatch`'s document-less write** (ES-27, `BatchStockService.php:61` / `:88-106`). You
  will read this method in M3; you do not change it.

---

## ✅ OWNER GATES — cite, do not re-ask, do not decide

| Item | Question | Status at draft | Your obligation |
|---|---|---|---|
| **D-12** (= Handover Q6) | ES-26: own document type, or route through `StockTransferService` and retire `BatchStockService::transferBatchStock`? | **OPEN** — `OWNER-QUESTIONS-es-remediation-2026-08-11.md:119-123`, explicitly *"(No recommendation given — present options only)"* | **Dispatch precondition.** M0 verifies the YAML carries the ruling. Null ⇒ `blocked_precondition`, STOP. |
| **D-10** (= Handover Q3) | Migrate the live consumers to V2, or retire the V2 dispatches? | **OPEN** — `:107-111`; orchestrator recommendation recorded (*migrate to V2*), owner has not ruled | **Do not pre-empt.** Rider **R-6** tells you exactly what to emit so that both dispositions stay open. If a review finding turns on D-10, that is STOP condition **B**. |
| **D-14** (= Handover Q10) | ES-64 GR-IR backfill intent | OPEN | Out of scope. Named only so you do not treat it as yours. |

Anything else that smells like an owner call — a new document type, a new UI surface, a permission
grant, a deploy choice, an accounting code — is **STOP condition B**. Name the exact item ID in
`blockers:`; do not paraphrase.

---

## 🧷 RIDERS — binding, read before M0

### R-1 🚨 LINE NUMBERS ARE EVIDENCE, NOT ADDRESSES — re-derive every one at `BASE_SHA`

M0 produces a citation sweep: for every `file:line` this brief or the register cites for the two
in-scope rows, record `old:line → new:line` + **the symbol it names** + a **semantic anchor** (e.g.
*"the second `recordBatchMovement()` call, the one with the positive quantity, inside
`transferBatchStock`'s `DB::transaction` closure"*). **M0 FAILS if any in-scope citation is
unresolved.** A citation that moved is fine; a citation that no longer describes what the brief says it
describes is a **premise failure** — report it, do not silently adapt.

### R-2 🚨 THE FOUR POS SITES ARE NOT INTERCHANGEABLE — one is probably UNREACHABLE

The register's G-1 table lists five writers. Four are POS. They differ in *reachability*, and the
difference decides what a green test proves:

| # | Writer | file:line (at `96c9193ad`) | Reachability at `BASE_SHA` | Milestone |
|---|---|---|---|---|
| **W-1** | `PosCoreReceiptProjection::decrementStock` — v3 device-authored SALE | method `:1791`; `stock_levels` write `:1856-1857`; ledger `:1861-1887` | **LIVE.** The v3 sale rail. This is the first tenant's only new-sale path. | **M2** |
| **W-2** | `PosCoreReceiptProjection::restockStock` — v3 REFUND/VOID restock | method `:2151`; `stock_levels` write `:2199-2200`; ledger `:2204-2227` | **LIVE** (gated behind the launch programme's E1 refund-enable block — the *code path* is live, the *feature* is gated). | **M2** |
| **W-3** | `ReceiptReturnService::restoreStock` — server-side return | method `:1255`; ledger `:1286` | **LIVE.** `POST /pos/receipts/{id}/return` is **routed**, not retired — `app/Modules/POS/routes.php:210`, and `ReceiptController.php:507-509` records it as the surviving `(c)` carve-out. | **M1** |
| **W-4** | `ReceiptCreationService::decrementStock` — legacy server-authored sale | method `:886`; ledger `:959` | **PROBABLY UNREACHABLE.** `POST /api/v1/pos/receipts` is a **410 `NEW_SALE_AUTHORING_RETIRED`** route-level closure (`app/Modules/POS/routes.php:167-175`), `POST /pos/receipts/{id}/payments` likewise (`:211-224`), and the order→receipt path via `OrderToReceiptService.php:68` sits behind a further 410 (`routes_orders.php:33-46`). `ReceiptController::store` is preserved but **unrouted** (`ReceiptController.php:497-514`). | **M1 — but see below** |

**M0 owes a reachability proof for W-4**, not a guess: enumerate every production caller of
`ReceiptCreationService::createReceipt` and show, for each, whether an authenticated HTTP request can
reach it at `BASE_SHA`. Record the verdict in M0's artifact.

- **If W-4 is genuinely unreachable:** still emit (the writer must not become a silent trap if the
  route is ever un-retired), but say so plainly — its V1 test drives the **service** directly and
  **that fact is disclosed in the test's own comment and in the handback**. A service-level test is
  *not* a "real production entry point" under handover §5's V1, and pretending otherwise is exactly
  the dishonesty Lane A0 exists to stop.
- **If M0 finds W-4 IS reachable** by some path this brief missed: that is a premise correction —
  record it, and drive V1 through the real entry point.

### R-3 🚨 THE PROJECTOR IS QUEUED AND REDELIVERED — emission goes INSIDE the idempotency guard

`PosCoreReceiptProjection` is a fiscal projector replayed by Horizon. Its durable idempotency anchor is
the `pos_receipts.fiscal_event_id` guard (`:89-102` docblock; fast-path probe `:220-221`; the
`ON CONFLICT` upserts at `:497`, `:505`).

Handover §3 states the pattern for Lane A1 and it binds you identically: emission goes **inside** the
projector's existing `fiscal_event_id` idempotency guard and **inside** its `DB::transaction` —
*"never before the guard (Horizon redelivery would double-emit)"*.

Concretely, for W-1/W-2:

- Register the emission in `DB::afterCommit(...)` from **inside** the transaction that wrote the
  movement — the same shape `StockAdjustmentService.php:161-199` already uses (`DB::afterCommit`
  closure over pre-captured snapshots, so a re-read cannot resurrect mutated state).
- **V1 must cover redelivery: call `apply()` twice with the same fiscal event and assert exactly ONE
  emission per movement.** Handover §5: *"`Event::fake()` assertions on a service called directly do
  not satisfy V1 for a projector fix; the redelivery path must be covered."*
- One event **per movement row**, not per receipt. A three-line sale emits three.

### R-4 🚨 BUILD ON DPA S0's ENUM — do not mint a new string vocabulary

`StockMovementReferenceType` (`app/Shared/Domain/Enums/StockMovementReferenceType.php`) is DPA S0's
canonical vocabulary for `stock_movements.reference_type`. Its docblock (`:28-35`) says, verbatim:

> *"Cases are added by the lane that adopts the seam, one at a time. Deliberately NOT enumerated here:
> the pre-existing writers that bypass StockAdjustmentService entirely (POS projections/services,
> StockTransferService's post-hoc UPDATE, OpeningBalancePostingService). Those are recorded at the
> program level and belong to their own lanes."*

**You are that lane for the POS writers.** Therefore:

- Add the cases the POS writers already write **with their existing backing values byte-for-byte**, in
  the same spirit as `PosReceiptReturnScrap` (`:69-73`: *"the string the pre-DPA raw write-off already
  wrote … preserved verbatim so historical rows and this seam share one vocabulary and no existing
  reader has to be migrated"*):
  - `PosReceipt = 'pos_receipt'` — written today at `PosCoreReceiptProjection.php:1881`, `:2220`,
    `ReceiptCreationService.php:972`.
  - `PosReceiptReturn = 'pos_receipt_return'` — written today at `ReceiptReturnService.php:1299`.
- **The persisted byte must not change.** Ship a byte-identity regression test in the same commit,
  modelled on `ExitMovementClassificationTest` (the w3a T2 precedent, commit `f097edfeb`): read the raw
  column and assert the literal is unchanged. `StockMovementReferenceType`'s `Document` case exists
  precisely because `LinkedCostApplicationService` and `BackfillGoodsReceiptsCommand` query the literal.
- Type the new call sites `?StockMovementReferenceType`, not `?string` (CLAUDE rule 9).
- **Do NOT re-type `recordPurchase` / `recordCostAdjustment`** — w3a T2 left those raw *by explicit
  plan instruction*; the S0 follow-up lane owns them.

### R-5 🚨 THE COST SNAPSHOT ALREADY EXISTS — use it, do not recompute

DPA w3a **T5** (`1cbb2e631`) added `movementCostSnapshot()` to the projector; W-1/W-2 already persist
`unit_cost` / `total_cost` from it (`:1878-1879`, `:2217-2218`), with the deliberate note that
`avg_cost_before` / `avg_cost_after` stay NULL because *"the POS path does not re-average (claiming it
did would be the V8 mistake)"*.

- The event's `unitCost` / `totalCost` are **the same snapshot values**, read from the persisted
  movement. Do not call WAC, do not re-average, do not invent a cost.
- W-3 (`ReceiptReturnService::restoreStock`) writes **no** cost (`:1340` docblock: *"explicitly no unit
  cost, no WAC involvement"*). Its event carries `'0.00'` for both, matching the existing convention at
  `StockAdjustmentService.php:172-173` (`(string) ($movement->unit_cost ?? '0.00')`). **Do not add cost
  to W-3's movement** — that is a costing change, not an event fix, and it is out of scope.

### R-6 🚨 D-10 IS NOT YOURS — emit the SAME pair the existing writers emit, and decide nothing

Every existing emitter dual-dispatches V1 + V2 (`StockAdjustmentService.php:163` + `:182`;
`WeightedAverageCostService.php:886` + `:906`; `OpeningBalancePostingService.php:195` + `:210`;
`ResetOpeningBalanceService.php:213` + `:228`). `StockMovementRecordedV2`'s own docblock (`:10-20`)
states the dual-dispatch contract.

**Your rule: emit exactly the same pair, in the same order, from every site you touch.**

- This is **additive** (CLAUDE rule 8 / handover §4.1). You create **no** new event class, rename
  nothing, reshape nothing.
- It **pre-empts nothing**: if the owner rules "migrate consumers to V2", your sites already carry a
  correct `variantId` (the POS writers are variant-aware — `variant_id` is persisted at
  `PosCoreReceiptProjection.php:1866`, `:2209`; `ReceiptCreationService.php:964`;
  `ReceiptReturnService.php:1291`). If the owner rules "retire the V2 dispatches", your sites are
  retired by the same sweep as the other nine, with no special case.
- **Do not** add a V2 consumer. **Do not** migrate `DispatchStockChangeToChannels` to V2. Both are
  D-10's call and Lane F's work.
- If a reviewer argues your V2 dispatch "grows the dead-event surface" (it does — from ~9 sites to
  ~13): that is a **true observation about a decision you were forbidden to make**. Record it, cite
  D-10, and let the parent rule. It is not a fix round.

### R-7 🚨 M3 INHERITS ES-60 — disclose it, do not fix it

If D-12 rules **Option B**, the batch transfer routes through `StockTransferService`, which carries
**ES-60**: `markMovementAsTransfer` rewrites the movement row *after* the `DB::afterCommit` event was
registered, so the emitted payload says `movementType: 'receipt'` while the persisted row says
`transfer_in` (`StockTransferService.php:701-708`; `StockAdjustmentService.php:165`, `:184`;
`03-inventory-costing.md` **G-3**).

- **You do not fix ES-60.** Handover §3 gives it a specific contract (fix at insert, not by another
  post-hoc UPDATE) and it is a separate row in a lane slice you were not given.
- **You must disclose it**, in the M3 commit message, in the handback, and in a ticket: *"routing the
  batch transfer through `StockTransferService` moves it onto a path with a known event/row
  disagreement (ES-60). Strictly better than the prior state (no document, no event at all), and
  strictly not closed."*
- Ship a test that **pins the inherited defect as it is today** so ES-60's future fix has a red to
  invert (the w3a T1 precedent: characterise first, invert later). Do not assert the *correct*
  behaviour and mark it skipped.

### R-8 🚨 "NO EVENT" AND "NO DOCUMENT" ARE TWO DEFECTS — the document-per-action one is the load-bearing half

ES-26 is a **document-per-action** violation first and an event gap second. CLAUDE's standing principle
(MEMORY, `feedback_document_per_action_principle.md`) — *every stock/GL/cash/fiscal mutation needs its
OWN justifying document* — is **BINDING here**.

- A fix that emits `StockMovementRecorded` from `transferBatchStock` while leaving it document-less is
  **NOT a fix**. It converts a silent violation into an audited one. **Refuse that shape.**
- The physical relocation must, after M3, be justified by a **document** (a `StockTransfer` under
  Option B), which is what produces the aggregate `stock_movements` rows, the `stock_levels` change at
  **both** locations, and the events — as a consequence of the document existing, not as a bolt-on.
- The desync post-condition (`SUM(inventory_batch_stock)` per location ≠ `stock_levels.quantity` per
  location) is the **observable** proof. M4's detector is how you show it is gone. See R-9.

### R-9 🚨 THE DESYNCED PROJECTIONS NEED A STATED POSITION — this brief takes one

Handover's Data/projection-repair class (§5) applies to *repairs*. This slice's position, which the
reviewer will test you against:

| Population | Position | Rationale |
|---|---|---|
| **Batch-transfer desync already in the data** (`inventory_batch_stock` vs `stock_levels` divergence created by past `transferBatchStock` calls) | **DETECT NOW, REPAIR LATER.** M4 ships a **read-only** reconciliation command that reports divergence per `(product, location)` with a non-zero exit on any finding. **No corrective write.** | Repair requires deciding *which side is truth* — the lot table or the aggregate — and that is a data-loss-bearing owner call, not an executor call. **§10 PD-3.** The detector is what makes the question answerable with real numbers instead of speculation. |
| **Stale channel stock from ES-28** | **FORWARD-ONLY. No backfill, no replay.** | The register records channels as **not enabled** (`00-CONSOLIDATED-REGISTER.md:94`: *"POST (channels not enabled)"*), so there is no stale channel population to repair. `StockMovementRecorded` is **not stored** anywhere — there is no event store to replay from; a "replay" would be a fabrication. The channel lane's own **initial full stock sync** is what establishes correctness at enable time. **State this in the handback as an obligation handed to the channel lane**, not as something you did. |
| **`inventory_batch_movements` rows orphaned by the old path** (`movement_id` NULL where it is normally the FK to the aggregate ledger) | **DETECT ONLY** — the same M4 command reports the count. No write. | Same reason. Rewriting append-only lot-movement rows to attach a synthesised `movement_id` would be inventing history. |

**Nothing in this slice may write a corrective row into a historical population.** If you believe one
is needed, that is STOP condition **B**.

### R-10 🚨 THE ROUTE HAS NO FRONTEND CALLER — verify this before you rely on it

At `96c9193ad`, `POST /api/v1/batches/{uuid}/transfer` (`app/Modules/BatchExpiry/Presentation/routes.php:30`
→ `BatchController::transfer` `:364-399`) appears to have **no caller in `apps/web`, `apps/pos` or
`apps/mobile`**, and **no API test** drives it. `apps/web` already ships a full stock-transfers feature
with batch allocations (`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx`, and
`StockTransferDetailPage.batchAllocations.test.tsx`).

**M0 must re-verify this negative at `BASE_SHA` and record the exact commands and their output.** An
empty grep is not proof until the syntax is verified (MEMORY: *"Grep false negatives … Empty grep ≠
proven absence"*). Run at minimum:

```
grep -rn "batches/" apps/web/src apps/pos/src apps/mobile --include='*.ts' --include='*.tsx' | grep -i transfer
grep -rn "transferBatchStock" apps/api/tests apps/api/app --include='*.php'
grep -rn "batches/{uuid}/transfer\|/transfer" apps/api/app/Modules/BatchExpiry --include='*.php'
```

**Why it matters:** if the route is uncalled, retiring it (Option B) has near-zero UI cost and D-12's
*"UI consequence"* is nearly nil. **If M0 finds a caller, that is a premise correction with a real
scope consequence — report it in M0's register and let the gate rule before M3 starts.**

---

## 🏗 MILESTONE STRUCTURE

Sequential. Each milestone is one or more commits, gated by the harness before the next begins.

```
M0  preflight — artifacts, proofs, citation sweep; NO production code    [general, inventory-costing]
 │
 └─► M1  ES-28(a) — the two SERVICE writers + the S0 enum cases          [inventory-costing]
      │            (W-3 ReceiptReturnService, W-4 ReceiptCreationService)
      │
      └─► M2  ES-28(b) — the two PROJECTOR writers, redelivery-proof     [inventory-costing, fiscal-pos]
           │            (W-1 sale, W-2 refund/void restock)
           │
           └─► M3  ES-26 — the batch transfer gets its justifying document [inventory-costing]
                │            + the document-less path REFUSES
                │
                └─► M4  reconciliation detector + WHOLE-BRANCH GATE       [general, inventory-costing, fiscal-pos]
```

---

### M0 — Preflight (artifacts, proofs and fixtures only; **no production code**)

**Deliverable:** one committed artifact, `docs/handoff/reviews/es-lane-e-slice/M0-preflight.md`.

1. **The base check** — HARD PREREQUISITES §2, all three assertions, output pasted.
2. **D-12 ruling present** in the wave YAML (`owner_rulings.D_12`), quoted verbatim into the artifact.
   Null ⇒ `blocked_precondition`, STOP.
3. **Citation freshness sweep** (R-1) for every in-scope `file:line` in this brief, the register rows
   ES-26/ES-28, and `03-inventory-costing.md` C-1/G-1. **`unresolved = 0` or M0 FAILS.**
4. **W-4 reachability proof** (R-2) — the caller enumeration and the verdict.
5. **The R-10 negative** — the exact grep commands and their output, verbatim.
6. **Wave-3 overlap check** (handover §3 interactions): read
   `.superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/progress.md` (authoritative)
   and record whether anything in flight touches `BatchStockService`, `StockTransferService`,
   `PosCoreReceiptProjection`'s stock methods, or `StockMovementReferenceType`. A live overlap is a
   **STOP condition C** (architecture contradiction), not something to merge around.
7. **The consumer inventory for `StockMovementRecorded`** — prove, with grep, that
   `Channel\...\DispatchStockChangeToChannels` registered at `ChannelServiceProvider.php:29` is the
   **only** registered listener at `BASE_SHA`. This is the fact that makes M1/M2 low-blast-radius; if a
   second consumer exists (an audit subscriber, a GL reactor), the blast radius of turning on POS
   emission is materially larger and **the gate must rule before M1**.
8. **Baseline the regression set** — list every test path the slice will touch, and record the
   pre-existing red set at `BASE_SHA` by running them (BY PATH) **before** any change. A test that was
   already red is not yours; a test that was already red and that you cite as evidence is dishonest.

**Evidence that is easy to fake, and the antidote:** a prose *"I checked, there is no FE caller"*.
**Antidote:** the artifact carries the commands and their raw output, and the reviewer re-runs two
of its claims at random.

---

### M1 — ES-28(a): the two service-level POS stock writers emit, and the S0 vocabulary grows

**Targets:** W-3 `ReceiptReturnService::restoreStock` (`:1255`, ledger `:1286`) and W-4
`ReceiptCreationService::decrementStock` (`:886`, ledger `:959`).

1. **Add the two enum cases** per **R-4**, with docblocks in the style of the existing cases (say
   *which* writer authors them and *why* the backing value is the legacy literal), plus the
   **byte-identity regression test**.
2. **Emit `StockMovementRecorded` + `StockMovementRecordedV2`** from both writers, per **R-6**:
   - inside `DB::afterCommit`, over **pre-captured snapshots** (the `StockAdjustmentService.php:157-199`
     shape), never re-reading a possibly-mutated model in the closure;
   - `movementType` = the **persisted** `movement_type` value, read from the row you just wrote —
     **never a hardcoded literal.** (`StockAdjustmentService.php:165` hardcodes `'receipt'` and that is
     precisely the ES-60 defect; do not reproduce it.)
   - `newStockLevel` = the `quantityAfter` you persisted, at scale 4, as a **string**;
   - `referenceType` = the new enum case's `->value`; `referenceId` = the receipt id;
   - `quantity` = the persisted movement quantity, **as a string** (R-5 and rule 19 — no floats).
3. **Type the reference at these call sites** to `StockMovementReferenceType` (rule 9).

**Verification contract — EMISSION class (handover §5): V1 + V2 + V3, all three.**

| | What M1 ships |
|---|---|
| **V1** | Real production entry point. **W-3:** `POST /api/v1/pos/receipts/{id}/return` (routed — `routes.php:212`) drives a real return and asserts **one** V1 + **one** V2 per movement row, with the exact payload, registered in `afterCommit` (assert nothing fires if the transaction rolls back). **W-4:** per **R-2** — if unreachable, the service-level test **and its disclosure comment**. |
| **V2** | The **real registered listener** runs: `DispatchStockChangeToChannels` (registered at `ChannelServiceProvider.php:29`) handles the emitted V1, and with an active `Channel` + published `ChannelProductMapping` it queues `DispatchStockChangeToChannelJob` carrying **the post-movement `newStockLevel`**. Also assert the **5-second `Cache::add` debounce** (`DispatchStockChangeToChannels.php:41-43`) — two movements on the same `(channel, product, location)` inside the window queue **one** job while the cached `:latest_stock_level` advances to the newer value. That debounce is the thing that keeps a busy till from queueing a job per line. |
| **V3** | Durable consequence in the DB: the `stock_movements` row carries the **new enum-backed `reference_type` byte** and the correct `reference_id`; `stock_levels.quantity` matches `newStockLevel`; the queued job is on a **Horizon-covered queue** (`HorizonQueueCoverageTest` — if you introduce any new `onQueue('x')`, `apps/api/config/horizon.php` `defaults.*.queue` gets the matching entry in the same commit; CLAUDE rule 20). |

**Red-first:** each assertion above must be demonstrated **failing** on the pre-change tree, in the
commit message (`RED BEFORE: …`), and each fix commit gets a **revert-replay** (revert, prove the
covering test goes red, restore).

**Reviewer asks:** Are the events registered inside `afterCommit` and never fired on rollback? Is
`movementType` read from the row rather than hardcoded? Is the persisted `reference_type` byte
unchanged? Is the V2 consumer test driving the **registered** listener rather than instantiating it?

---

### M2 — ES-28(b): the two projector writers, proven idempotent under Horizon redelivery

**Targets:** W-1 `PosCoreReceiptProjection::decrementStock` (`:1791`, ledger `:1861-1887`) and W-2
`::restockStock` (`:2151`, ledger `:2204-2227`).

Same emission shape as M1, **plus R-3's redelivery contract**, which is what makes this its own
milestone.

1. Emission registered in `DB::afterCommit` **from inside** the projector's existing transaction and
   **inside** the `fiscal_event_id` idempotency guard — never before it.
2. `unitCost` / `totalCost` from the **already-persisted** T5 snapshot (**R-5**), read off the movement
   row. `avg_cost_*` stay NULL and are **not** in the event.
3. `variantId` on the V2 dispatch from the persisted `variant_id` (`:1866`, `:2209`).
4. `referenceType` = `StockMovementReferenceType::PosReceipt->value` for **both** — the byte both sites
   already write (`:1881`, `:2220`). **Do not "improve" W-2's reference type to something return-ish**;
   the persisted byte is `pos_receipt` today and R-4 forbids changing it.

**Verification contract — EMISSION class: V1 + V2 + V3.**

- **V1, and this is the milestone's whole point:** drive `apply()` with a real v3 `SALE_RECEIPT`
  envelope, then **call `apply()` again with the same fiscal event**, and assert **exactly one** V1 and
  **one** V2 per movement row across both invocations. Repeat for the REFUND/VOID envelope on W-2.
  A single-invocation test does **not** satisfy V1 here.
- **Rule 20 test contract (non-negotiable):** `app(CompanyContext::class)->clear()` **before**
  `apply()` — binding context in `setUp` masks the worker reality — and pass **explicit currency** to
  any scale resolution (`getScale($currency)` / `getScaleSafe($currency, 3)`; a bare no-arg
  `getScale()` throws in a queued/console context).
- **V2:** as M1, driven from the projector path.
- **V3:** the `stock_movements` row, `stock_levels.quantity`, and — the assertion that catches the real
  bug — **replaying `apply()` produces no second movement row and no second queued job.**
- A **negative control**: a line whose product has no `stock_levels` row at the required grain
  (the existing "absent variant grain" behaviour, `:2140-2150` docblock) still writes nothing **and now
  emits nothing** — you did not turn a silent skip into an event.

**Reviewer asks (fiscal-pos lens):** Did anything change about what hashes, what is sealed, or the
`fiscal_events` table? *(The answer must be **no** — §2.)* Is the emission strictly inside the guard
and the transaction? Does double-`apply()` emit once? Is `CompanyContext` cleared before `apply()`?

---

### M3 — ES-26: the batch transfer gets its justifying document, and the document-less path refuses

**Written against D-12 Option B** (route through `StockTransferService`; retire
`BatchStockService::transferBatchStock`). **If the owner ruled Option A, STOP — this milestone needs
re-briefing (HARD PREREQUISITES §1).**

**Why Option B is drafted (evidence the parent used, for your context — not a licence to re-decide):**
`StockTransferService` already models exactly this operation, lot-level:
`InitiateTransferData` carries `batchAllocations` (`StockTransferService.php:173-181` writes
`StockTransferLineBatchAllocation` rows); `autoAllocateBatchesFefo` fills them earliest-expiry-first
(`:127-131`, `resolveFefoAllocations` `:783-820`); `complete()` and the in-transit legs move lot stock
(`:312-338`, `:408-434`, `:523-551`); it creates a numbered `StockTransfer` **document** (`:137-155`),
holds an idempotency key (`:105-116`), and emits `StockTransferInitiated` / `Completed` / `Cancelled`.

**The work:**

1. **Route the batch transfer through the document lane.** `BatchController::transfer` (`:364-399`)
   builds an `InitiateTransferData` for the single lot (one line, one batch allocation) and calls
   `StockTransferService`. The lot's product/variant, the source and destination locations, the
   quantity, the `reference` string and the `userId` — **all four of which the old path silently
   discarded** (`BatchStockService.php:264-265`, dropped by the closure at `:267`) — become real fields
   on the document.
2. **`BatchStockService::transferBatchStock` (`:258-296`) is retired.** Follow the house tombstone
   convention already used for `POST /pos/receipts/{id}/void` (`app/Modules/POS/routes.php:202-209`):
   retire the **behaviour**, keep the **surface** honest. Decide explicitly, and record the reasoning in
   the commit, between: (a) delete the method and re-point the controller; or (b) make it **throw** a
   typed refusal naming the replacement. Whichever you choose, **there must be a test that the
   document-less relocation is no longer performable.**
3. **Preserve the source-availability refusal.** The old path threw `\DomainException` on insufficient
   lot stock (`:274-278`) and the controller mapped it to a `422 TRANSFER_FAILED` (`:385-392`). The new
   path must refuse the same input — via `assertBatchAllocationsCanIssue` (`:710-730`) /
   `assertSourceAvailability` (`:197`) — and the HTTP contract (**422 + a typed code**) must not silently
   become a 500. Test the refusal.
4. **i18n:** any new user-facing string gets **en + fr** keys (CLAUDE rule 11). No untranslated server
   strings.

**Verification contract — this row spans TWO classes; ship both halves, and say so in the register:**

| Half | Class | What it ships |
|---|---|---|
| **The document + emission** | **EMISSION (V1 + V2 + V3)** | **V1:** `POST /api/v1/batches/{uuid}/transfer` (the real entry point) now produces a `StockTransfer` document and the aggregate `stock_movements` rows, and emits `StockMovementRecorded` (+V2) for **both legs**, once each. **V2:** the registered channel listener runs on both legs. **V3:** a `StockTransfer` row with a transfer number and the `userId`; `stock_levels.quantity` **changed at BOTH locations** (the old path changed neither — C-1); `inventory_batch_stock` moved; the lot-movement rows now carry a non-null `movement_id` FK to the aggregate ledger. |
| **The retirement** | **GUARD / REFUSAL** | A **red test that drives the previously-accepted document-less input** and asserts it is now refused (or unreachable), **plus a state assertion that the refused attempt persisted nothing** — no `inventory_batch_movements` row, no `inventory_batch_stock` change. The **green half — a legitimate transfer still succeeds end-to-end — is in the same diff.** |

**The invariant test that proves the row is actually fixed:** after a transfer of quantity Q of lot L
from location A to location B,
`SUM(inventory_batch_stock.quantity) WHERE location = X` **equals**
`stock_levels.quantity WHERE location = X` for **both** X ∈ {A, B}.
Assert it **red on the old path** (characterised at `BASE_SHA`, in this commit's message) and **green on
the new one**. That equality *is* C-1's post-condition, inverted.

**Also owed here:** rider **R-7**'s ES-60 disclosure + the characterisation test pinning the inherited
event/row disagreement.

**Reviewer asks:** Is there a **document** justifying the physical relocation, or did you just add an
event to a document-less mutation (**R-8** — that is an automatic P1)? Do **both** locations' aggregate
projections move? Is the old path genuinely unperformable? Did the 422 contract survive? Was ES-60
disclosed rather than quietly fixed or quietly ignored?

---

### M4 — Reconciliation detector + the WHOLE-BRANCH GATE

1. **The detector** (per **R-9**) — a **read-only** artisan command reporting, per company:
   - `(product, location)` pairs where `SUM(inventory_batch_stock.quantity)` ≠ `stock_levels.quantity`,
     with both values and the delta;
   - the count of `inventory_batch_movements` rows with a NULL `movement_id`;
   - a **non-zero exit** when anything is found, so it is usable as a check.
   **No writes. No `--fix`. No `--repair`.** Not a flag, not a `--dry-run` inverse, nothing. If you
   think one is needed: STOP condition **B**, cite **PD-3**.
   Tenant-scoped and unattended-safe (`origin/dev` auto-deploys staging and runs `tenants:migrate` —
   CLAUDE rule 21 / handover §4.5). The command needs **no** migration; if you believe it does, justify
   it and make it self-guarding.
   Tests: **red** on a fixture carrying a seeded divergence (assert the fixture actually diverges, or a
   later red is red for the wrong reason), **green** on a clean tenant, and **green on a tenant whose
   only transfers went through the new M3 path** — that last one is the regression guard that says M3
   worked.
2. **The whole-branch gate** — re-run every lens over the integrated branch (`<base_sha>..HEAD`) and the
   full accumulated evidence. Per the harness, this is the last gate and it is not a formality.
3. **The handback statement** (goes in the session report **and** the M4 register):
   - **ES-26:** closed / not closed, with the invariant test named.
   - **ES-28:** **PARTIALLY** discharged — POS half done, `SupplierCreditNotePostingService` half
     outstanding, **row stays OPEN**. Say it in those words.
   - **The channel lane's inherited obligation** — an initial full stock sync at enable time (R-9);
     forward-only emission does not repair a pre-enable population.
   - **The ES-60 inheritance** (R-7) and its ticket.
   - **The historical desync population** the detector found, with real numbers, handed to **PD-3**.
   - **Deploy obligations** — explicitly state them, including *"none"* where that is the truth. State
     whether any permission, seeder, `permission:cache-reset`, migration or device version is owed.

---

## 📋 PER-MILESTONE EVIDENCE CONTRACT

| Milestone | Code evidence | Artifact evidence | Easy to fake → antidote |
|---|---|---|---|
| **M0** | none (no production code) | base check pasted; D-12 ruling quoted; citation sweep with **unresolved = 0**; W-4 reachability verdict; the R-10 greps with raw output; Wave-3 overlap verdict; the single-consumer proof; the pre-existing red baseline | *"I checked, nothing calls it."* → the artifact carries commands **and** output; the reviewer re-runs two claims of its choosing. |
| **M1** | red-first per assertion; revert-replay per fix commit; byte-identity test on `reference_type`; V1+V2+V3; pint + phpstan L8 on touched files | the W-4 disclosure (service-level V1 and why) | One emission test standing in for four sites. → **one V1 per writer**, each naming its own entry point; the debounce assertion is separate from the queueing assertion. |
| **M2** | double-`apply()` → **one** emission; rollback → **zero**; negative control on the absent grain; `CompanyContext` cleared before `apply()` | the emission's position relative to the guard, quoted with file:line | A single-invocation "it emits" test. → the redelivery assertion is **inside the same test** as the first invocation, over the same fiscal event id. |
| **M3** | the two-location `SUM(batch) == stock_levels` invariant, **red on the old path, green on the new**; the refusal test + its nothing-persisted assertion; the legitimate-transfer green half in the same diff; the 422 contract preserved | the ES-60 disclosure + ticket; the retirement decision (delete vs typed refusal) with reasoning | Emitting an event from the still-document-less path and calling ES-26 closed. → the reviewer checks for a `StockTransfer` **row**; no document = **P1, automatic**. |
| **M4** | detector red on a seeded divergence (**fixture self-asserted**), green clean, green post-M3 | the whole-branch register; the handback statement with **ES-28 marked PARTIAL** | A detector that reports zero because its query is wrong. → the fixture asserts its own divergence before the command runs. |

---

## 📏 HOUSE RULES (binding, every milestone)

- **Events are immutable forever (CLAUDE rule 8; handover §4.1).** This slice adds **no new event
  class**. Every gap is closed by a **new emission** of an existing class, or new wiring. If you
  believe a new class is needed, that is a scope signal → **report, do not mint it**. Rule 8 binds the
  **class**, not the dispatch site.
- **Document-per-action is BINDING (R-8).** Every stock mutation needs its own justifying document. An
  event is not a document.
- **TDD red-first per task.** Every fix commit gets a **revert-replay**: revert it, prove the covering
  test goes red, restore. `RED BEFORE: …` in the commit message, with the actual failure text.
- **Tests BY PATH only. The full PHPUnit suite is FORBIDDEN — it crashes the machine** (handover §4.3;
  MEMORY `feedback_no_full_test_suite.md`). The declared regression set must include **every suite
  directory the diff touches**, and no more. Ask before anything broader.
- **A real PostgreSQL run before ANY green claim.** Local PG on 5432 (Docker 5433 is broken). `[PG]`
  tests skip **loudly** on other drivers. This slice is PG-sensitive: `stock_movements.reference_id` is
  a `uuid` column on PostgreSQL and untyped TEXT on SQLite — a SQLite-only green means nothing here
  (this exact difference produced 10 pre-existing reds in the w3a T2 run; see `f097edfeb`'s message).
- **Rule 17:** valid UUIDs for every FK in tests and fixtures; check the actual schema for required
  columns before writing one.
- **Rule 19 — money and quantity precision.** No float touches money or quantity. Quantities at scale
  4 via `QuantityScale`; currency via `CurrencyScale::bcformatStrict($value, $scaleResolver->getScale($currency))`.
  Constructor-inject `CurrencyScaleResolverInterface`. In queued/console/projection contexts pass the
  **entity currency explicitly**. Event payload numerics are **strings**.
- **Rule 20 — POS cross-layer contracts.** Queued jobs and fiscal projections run with **no
  `CompanyContext`**: clear it before `apply()` in tests, pass explicit currency in production. Any new
  `onQueue('x')` needs a matching `apps/api/config/horizon.php` entry (`HorizonQueueCoverageTest`).
- **Rule 13 — constructor injection only**, `private readonly`, **never the `app()` helper** in
  production code (the documented test idiom `app(CompanyContext::class)->clear()` is fine).
- **Rule 9 — enums for type columns.** `reference_type` is typed via `StockMovementReferenceType`;
  no new magic strings.
- **Rule 6 — module boundaries.** Cross-module only via `Shared/Contracts/`, events, or a module's
  public service class. `StockMovementReferenceType` lives in `Shared\Domain\Enums` **precisely** so
  POS and Procurement can name a reference type without importing an Inventory model — do not
  reintroduce an FQCN reference (`StockTransfer::class`) at any site you touch.
- **Rule 11 — i18n.** Every user-facing string, **en + fr**. Console output is operator-facing: follow
  the existing commands' convention rather than inventing one.
- **Rule 4 — no scope creep.** Fix exactly the two rows. Note adjacent findings in the handback and
  continue.
- **Do not "fix" the OK-BY-DESIGN items** (handover §4.7) — including `PosCoreReceiptProjection`
  calling loyalty directly instead of emitting `ReceiptCompleted`, and the POS Treasury bridges not
  emitting `PaymentRecorded`.
- **Nothing may write to `fiscal_events` outside a test fixture, and nothing may change what hashes.**
- **Migrations idempotent, self-guarding, unattended-safe.** This slice should need **none**; if one
  appears, justify it.
- `pint` + `phpstan` level 8 clean on every touched file; `deptrac` must not regress its baseline.
- Dedicated worktree; **never `git stash`**; **never merge**; **never push**.

---

## 🚦 REVIEWER GATES

Handover §4.2 assigns Lane E to `inventory-costing-reviewer`. M2 additionally carries `fiscal-pos`
because it edits a fiscal projector. M0 and M4 carry `general` for the cross-cutting claims.

| Milestone | Lenses | What the gate is really asking |
|---|---|---|
| **M0** | `general`, `inventory-costing` | Is `unresolved = 0` real, or asserted? Is the W-4 reachability verdict evidenced by a caller enumeration? Are the R-10 greps run with verified syntax, not eyeballed? Is `DispatchStockChangeToChannels` truly the only consumer? |
| **M1** | `inventory-costing` | Is the persisted `reference_type` byte **unchanged**? Is `movementType` read from the row and not hardcoded (the ES-60 anti-pattern)? Is emission in `afterCommit` and silent on rollback? Is the V2 test driving the **registered** listener? Are all numerics strings (rule 19)? |
| **M2** | `inventory-costing`, `fiscal-pos` | Is emission strictly **inside** the `fiscal_event_id` guard and the transaction? Does double-`apply()` emit **once**? Is `CompanyContext` cleared before `apply()`? Did **anything** about sealed bytes, hashing or `fiscal_events` change *(the answer must be no)*? Is the T5 cost snapshot reused rather than recomputed? |
| **M3** | `inventory-costing` | **Is there a justifying document?** (No document ⇒ **P1**, no exceptions — R-8.) Do **both** locations' `stock_levels` move? Is the two-location invariant red-on-old / green-on-new? Is the document-less path genuinely unperformable, with a nothing-persisted assertion? Was ES-60 **disclosed** (R-7)? Did the 422 refusal contract survive? |
| **M4** | `general`, `inventory-costing`, `fiscal-pos` | Does the detector's fixture assert its own divergence? Is the command genuinely read-only? Does the handback say **ES-28 PARTIAL** in those words? Are the channel-lane obligation and the deploy obligations stated (including "none")? |

Per the harness: every **P1** must be closed or explicitly ruled by an owner gate before the milestone
passes; **P2** close-before-merge; **P3** may ship with a ticket recorded in the tree. A review with no
parseable `VERDICT:` line is a **tool error → CHANGES-REQUIRED**, never a pass. `max_fix_rounds: 5`;
exceeding it is STOP condition **A**.

---

## 🧾 COMMIT SERIES — placeholder, filled by the executor

One row per commit, in order. The parent reads this table before merging.

| # | Milestone | Type | Subject (conventional-commit) | SHA | RED-BEFORE evidence | Revert-replay done |
|---|---|---|---|---|---|---|
| 1 | M0 | `docs` | `docs(es-lane-e): M0 preflight — base pin, citation sweep, reachability proofs` | `<TBD>` | n/a (no production code) | n/a |
| 2 | M1 | `feat` | `feat(es-lane-e): S0 enum cases for the POS reference vocabulary` | `<TBD>` | `<TBD>` | `<TBD>` |
| 3 | M1 | `feat` | `feat(es-lane-e): ES-28(a) — the two service POS stock writers emit StockMovementRecorded` | `<TBD>` | `<TBD>` | `<TBD>` |
| 4 | M2 | `feat` | `feat(es-lane-e): ES-28(b) — projector emission inside the fiscal_event_id guard` | `<TBD>` | `<TBD>` | `<TBD>` |
| 5 | M3 | `feat` | `feat(es-lane-e): ES-26 — batch transfer routes through its justifying StockTransfer document` | `<TBD>` | `<TBD>` | `<TBD>` |
| 6 | M3 | `refactor` | `refactor(es-lane-e): retire the document-less transferBatchStock path` | `<TBD>` | `<TBD>` | `<TBD>` |
| 7 | M4 | `feat` | `feat(es-lane-e): read-only batch/aggregate stock reconciliation detector` | `<TBD>` | `<TBD>` | `<TBD>` |
| … | | | *(fix-round commits appended here, each naming the register finding it closes)* | | | |

Every commit message ends with the standard trailer:
`Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

## 📦 DELIVERABLE — handback only

**One branch, NOT merged, NOT pushed.** The orchestrator merges to local `dev` after reading the
whole-branch register and clearing the remaining gates.

1. **`codex/es-lane-e-slice`** in worktree **`.worktrees/es-lane-e-slice`**, created from `BASE_SHA` off
   local `dev`.
2. **`docs/handoff/progress/es-lane-e-slice.progress.yaml`** complete — every milestone `status`,
   `commit`, `verdict`, `last_verdict`, `fix_rounds`; wave `status` and `blockers` reflecting reality.
3. **Review records** under `docs/handoff/reviews/es-lane-e-slice/` — `M<n>-round<r>.md` per milestone
   per round, **plus `M0-preflight.md`** (the M0 artifact).
4. **A session report** at `docs/sessions/codex-es-lane-e-slice-report.md`: per milestone — files
   touched, tests + commands + **actual output**, decisions taken, deviations with rationale, concerns;
   plus the **M4 handback statement** in full (ES-26 verdict; **ES-28 PARTIAL**; the channel-lane
   obligation; the ES-60 inheritance; the detector's real numbers for PD-3; deploy obligations,
   including "none"). *(`docs/sessions/` is gitignored — the correct home for the ephemeral report per
   CLAUDE rule 15; the durable evidence is the YAML + the registers, which are tracked.)*
5. **Tickets** for everything noted-and-not-fixed, recorded in the tree: ES-28's procurement half, the
   ES-60 inheritance, the historical desync population, any adjacent finding you hit.

---

## ❓ OPEN AT DISPATCH — parent decisions

These are the parent orchestrator's to resolve **before** this draft becomes a dispatch file. They are
listed here so the gate reviewer can see exactly what is unresolved.

### PD-1 — **D-12 must be ruled, and the parent should carry a recommendation** *(owner ruling; parent to escalate)*

`OWNER-QUESTIONS-es-remediation-2026-08-11.md:119-123` records D-12 with *"(No recommendation given —
present options only, per instruction.)"* — the only item on the sheet with no orchestrator
recommendation. Lane E cannot start without a ruling.

**The parent's recommendation, on the evidence gathered for this draft: Option B — route through
`StockTransferService`, retire `BatchStockService::transferBatchStock`.** Because:

1. **The capability already exists, lot-level.** `StockTransferService` models batch allocations end to
   end (`:173-181`, `:312-338`, `:408-434`, `:523-551`), with FEFO auto-allocation (`:783-820`) and an
   idempotency key (`:105-116`). Option A would build a second, thinner transfer document beside it.
2. **The "UI consequence" that made D-12 a domain-model question appears to be nearly nil.** The
   document-less route has **no caller** in `apps/web`, `apps/pos` or `apps/mobile`, and no API test —
   while `apps/web` already ships a stock-transfers feature *with batch-allocation UI*
   (`CreateStockTransferPage.tsx`, `StockTransferDetailPage.batchAllocations.test.tsx`). **M0 re-verifies
   this negative (R-10); if it is wrong, the recommendation weakens and the gate should re-rule.**
3. **Option A creates a second physical-relocation document type** — a second stock-transfer surface,
   a second numbering series, a second reporting lane, a second thing every future inventory change
   must remember. That is the cost the document-per-action principle is meant to *avoid*.

**If the owner rules Option A, M3 must be re-briefed by the parent** — this text does not describe it.

### PD-2 — **Confirm the launch-criticality premise; the register disagrees with it** *(parent to confirm, may need the owner)*

The dispatch premise is *"the client uses stock transfers daily"*. The register's own trigger for ES-26
is **"POST (single-location at launch) — LAUNCH the moment a 2nd location exists"**
(`00-CONSOLIDATED-REGISTER.md:92`), and ES-26 sits in the register's *"Second location / transfers"*
conditional bucket (`:218`), **not** in the P0 or P1 lists.

Two sub-questions, and the answers change the slice:

- **(a) Does the first tenant have a second location at launch?** If not, ES-26 is unreachable at
  launch and this slice's urgency rests on a premise the audit contradicts.
- **(b) Which transfer surface does the client actually use daily** — the **aggregate** lane
  (`StockTransferService`, which is already documented and evented) or the **lot-level** lane
  (`POST /api/v1/batches/{uuid}/transfer`, the broken one)? Given R-10's finding that the lot-level
  route has no frontend caller, *"uses stock transfers daily"* most likely describes the **aggregate**
  lane — which is **not broken**. If so, **ES-26 is a latent API-surface hazard rather than a live
  daily-use defect**, and M3's priority relative to M1/M2 should be reconsidered.

This does not remove ES-26 from the slice — a GAP-CRITICAL document-less mutation path deserves
closing regardless — but the parent should not carry an unexamined urgency claim into an owner
conversation.

### PD-3 — **Historical desync: detect-only now, repair deferred** *(parent to accept; the repair itself is owner-gated)*

The brief's position (**R-9**): M4 ships a **read-only** detector; **no corrective write** lands in
this slice. Repairing the divergence means deciding whether `inventory_batch_stock` or
`stock_levels` is truth for an affected `(product, location)` — a data-loss-bearing call that needs
real numbers first, and those numbers do not exist until the detector runs.

**Parent decides:** accept detect-only, or expand the slice to include a repair (which would then need
its own owner ruling on the truth side, a before/after population assertion per handover §5's
Data/projection-repair class, and an append-only-compliance check).

**Recommendation: accept detect-only.** Run the detector on the first tenant, bring the actual numbers
to the owner, then scope a repair as its own gated item.

### PD-4 — **The ES-60 inheritance is accepted, disclosed and ticketed** *(parent to confirm)*

Option B routes the batch transfer onto `StockTransferService`, which carries ES-60 (event payload says
`movementType: 'receipt'`, persisted row says `transfer_in` — `StockTransferService.php:701-708`).
**Rider R-7 accepts this**: strictly better than no document and no event at all, disclosed in the
commit and the handback, pinned by a characterisation test, ticketed for ES-60's own slice.

**The alternative** — pulling ES-60 into scope — would add a fifth milestone and widen the diff into
`StockAdjustmentService`'s `receive()`/`issue()` signatures, which is a different blast radius.
**Recommendation: accept the inheritance.** Parent to confirm, since it means shipping a known-imperfect
path knowingly.

### PD-5 — **The V2 dual-dispatch at four new sites** *(parent to confirm; D-10 stays untouched either way)*

**R-6** has the executor emit V1 + V2 from every new site, matching all nine existing emitters. This is
additive and pre-empts neither D-10 disposition — but it does grow the dead-`StockMovementRecordedV2`
dispatch surface from ~9 sites to ~13 while D-10 is unruled.

**Recommendation: emit both.** The asymmetric alternative (V1 only at the new sites) would leave the
POS writers variant-blind if the owner later rules *"migrate consumers to V2"* — which is the
orchestrator's own standing recommendation on D-10 (`:111`) — and would need a second pass over the
same four sites. Parent to confirm, and to note that this is **not** a licence for the executor to
touch consumers.

---

## 📁 §11 — PROGRESS YAML SKELETON *(template; the parent instantiates it at dispatch)*

> **Not a live file.** Do **not** create `docs/handoff/progress/es-lane-e-slice.progress.yaml` from this
> draft. The parent writes it — with the real `base_sha` and the real D-12 ruling — as part of
> dispatching. Every `null` below is a parent write, not an executor write; the executor **never edits
> the pins or the rulings**, only the per-milestone `status` / `commit` / `verdict` / `last_verdict` /
> `fix_rounds` and the wave `status` / `blockers`.

```yaml
wave: es-lane-e-slice
brief: docs/handoff/CODEX-DISPATCH-es-lane-e-slice-2026-08-18.md   # renamed from DRAFT- at dispatch
harness: docs/handoff/SELF-REVIEW-HARNESS.md
review_register: docs/handoff/reviews/es-lane-e-slice/
reviewer_model: opus
max_fix_rounds: 5

# ── PINS (parent writes at dispatch; executor NEVER edits) ────────────────────
# base_sha = local dev tip at dispatch. M0 verifies, mechanically:
#   1. git merge-base --is-ancestor <base_sha> HEAD
#   2. the DPA seam exists AT base_sha (brief HARD PREREQUISITES §2.2):
#        git show <base_sha>:apps/api/app/Shared/Domain/Enums/StockMovementReferenceType.php \
#          | grep -n "case Document = 'Document'"
#        git show <base_sha>:apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php \
#          | grep -n "movementCostSnapshot"
#   3. git diff --stat <base_sha>..HEAD touches ONLY docs/handoff/** and docs/superpowers/reviews/**
#   Any failure ⇒ status: blocked_precondition, STOP. Do NOT edit the pins.
base_sha: null

# ── OWNER RULINGS (parent writes; null ⇒ M0 blocks) ───────────────────────────
owner_rulings:
  # D-12 (= Handover Q6) — ES-26 batch inter-location transfer.
  # MUST be "option_b_route_through_stock_transfer_service" for this brief as written.
  # "option_a_own_document_type" ⇒ M3 needs a parent re-brief BEFORE dispatch.
  D_12: null
  D_12_quote: null          # the ruling verbatim, for the M0 artifact

owner_gates:
  # Anything here is STOP condition B. Name the exact ID in blockers:, do not paraphrase.
  - D-10   # V1/V2 disposition — brief rider R-6 keeps both dispositions open; do NOT pre-empt
  - D-12   # if the ruling is absent or ambiguous at M0
  - PD-3   # any corrective WRITE to a historical population (the M4 detector is read-only)

scope_rows:
  in_scope:  [ES-26, "ES-28 (POS half only — 4 writers)"]
  out_of_scope:
    - "ES-28 procurement half (SupplierCreditNotePostingService) — ES-28 stays OPEN after this wave"
    - [ES-27, ES-59, ES-60, ES-61, ES-62, ES-63, ES-64, ES-65, ES-66, ES-67, ES-68, ES-84]
    - "all of lanes A0/A1/B/C/D/F/G/H/I/X"
    - "any fiscal event schema change"

status: not_dispatched          # → in_progress | blocked_precondition | blocked_review
                                #   | blocked_owner | blocked_architecture | complete
branch: codex/es-lane-e-slice
worktree: .worktrees/es-lane-e-slice
blockers: []

milestones:
  - id: M0
    name: "Preflight — artifacts, proofs, citation sweep (no production code)"
    review_lenses: [general, inventory-costing]
    owner_gate: D-12          # blocks if owner_rulings.D_12 is null
    status: pending
    commit: null
    verdict: null
    last_verdict: null
    fix_rounds: 0

  - id: M1
    name: "ES-28(a) — the two service POS stock writers emit; S0 enum cases added"
    review_lenses: [inventory-costing]
    owner_gate: null
    status: pending
    commit: null
    verdict: null
    last_verdict: null
    fix_rounds: 0

  - id: M2
    name: "ES-28(b) — projector emission inside the fiscal_event_id guard, redelivery-proof"
    review_lenses: [inventory-costing, fiscal-pos]
    owner_gate: null
    status: pending
    commit: null
    verdict: null
    last_verdict: null
    fix_rounds: 0

  - id: M3
    name: "ES-26 — batch transfer gets its justifying document; document-less path refuses"
    review_lenses: [inventory-costing]
    owner_gate: null          # D-12 already ruled at M0; a NEW document-model question ⇒ STOP B
    status: pending
    commit: null
    verdict: null
    last_verdict: null
    fix_rounds: 0

  - id: M4
    name: "Read-only reconciliation detector + WHOLE-BRANCH gate + handback statement"
    review_lenses: [general, inventory-costing, fiscal-pos]
    owner_gate: null
    status: pending
    commit: null
    verdict: null
    last_verdict: null
    fix_rounds: 0

# ── NO CLOCK ──────────────────────────────────────────────────────────────────
# The environment has no wall clock. Never call `date`. Use `git rev-parse --short HEAD`
# as the `updated:` marker (harness, "No clock").
updated: null
```

---

**End of DRAFT.** Gate this file (round-0 mechanical precheck per
`docs/superpowers/SPEC-GATE-ROUND0-MECHANICAL-PRECHECK.md`, then the adversarial brief gate) and
resolve **PD-1 … PD-5** before renaming it to `CODEX-DISPATCH-es-lane-e-slice-2026-08-18.md` and
instantiating the §11 YAML.
