# Adversarial plan review — P2P flexible entry points implementation plan

> Target plan: `docs/superpowers/plans/2026-07-05-p2p-entry-points-plan.md`
> Against spec: `docs/superpowers/specs/2026-07-05-p2p-entry-points-design.md`
> Prior design reviews: `docs/superpowers/reviews/2026-07-05-p2p-entry-points-understanding-{codex,claude}-review.md`
> Worktree: `apps/erp.p2p-flow` @ `feat/p2p-entry-points`. Method: every code claim
> verified against this worktree, file:line cited. Reviewer: Claude (Opus). 2026-07-05.
> This review complements the parallel Codex file/signature-verification pass — it targets
> TDD quality, wave/interface line-up, design-review coverage, Codex-executability, risk hotspots.

## Verdict: NEEDS-REVISION

Two BLOCKER-grade gaps will halt or silently corrupt Codex execution, plus 4 MAJORs and
several MINORs. The wave architecture and spec-coverage mapping are sound; the defects are
concrete and fixable in the plan text.

---

## BLOCKER findings

### BL1 — Draft `goods_receipt_lines` violate NOT NULL cost columns; plan never resolves it
**Tasks 3.2 (test setup) and 3.3 (`createDraft` impl).**
`goods_receipt_lines` has three NOT NULL columns with **no DB default**: `landed_unit_cost`
`decimal(19,6)`, `accrual_unit_cost` `decimal(15,6)`, `effective_unit_cost` `decimal(19,6)`
(`apps/api/database/migrations/tenant/2026_07_04_100000_create_goods_receipts_tables.php:45-47`),
plus `received_qty`, `po_line_id`, `product_id`, `tenant_id`, `company_id` (all NOT NULL,
no default). Per the design's own logic (claude design-review "attack surface 2" / M6),
these cost fields are **post-time artifacts that cannot exist on a Draft line**.

- Task 3.1's migration makes **only `receipt_number`** nullable (plan lines 288-298). It does
  NOT touch the three cost columns.
- Task 3.2's failing-test recipe (plan lines 310-322) says only "insert Draft receipt + line
  received_qty=5" + "lines with NULL movement ids". A literal `GoodsReceiptLine::create()`
  that omits `landed_unit_cost`/`accrual_unit_cost`/`effective_unit_cost` throws a NOT NULL
  violation → the test fails at INSERT (wrong reason), making the "no matchable window"
  assertion **vacuous**.
- Task 3.3's `createDraft` (plan lines 339-345) is described as "lines with movement_id NULL,
  NO stock/WAC/GL" — but it must still INSERT the row and therefore hit the same three NOT
  NULL columns. The plan gives no instruction on whether to (a) make them nullable or (b)
  write placeholder zeros.

**Correction:** Add to Task 3.1 (or a new W3 migration) making `landed_unit_cost`,
`accrual_unit_cost`, `effective_unit_cost` nullable (draft lines have no cost until post —
honesty-preferred), and state that `createDraft` writes them NULL and the Task-3.2 test
supplies `po_line_id`/`product_id`/`received_qty` from the seeded PO line. If zeros are
chosen instead of nullable, say so explicitly and note `post()` recomputes them. Without this
decision, two tasks are non-executable.

### BL2 — Task 1.2 GR-IR drift command is designed on the wrong journal-entry grain
**Task 1.2 (plan lines 151-181).** The plan says: "copy its EXACT source_type string + source
id semantics (**expected `goods_receipt` + receipt id**)" and "Command queries `goods_receipts
where status='posted'` left-join `journal_entries` on the source-type-scoped pair".

Reality: the GR-IR entry is keyed **per stock movement**, not per receipt —
`source_type = 'goods_receipt'`, `source_id = $movementId`
(`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1108-1109`, doc
comment `:1043-1046`; listener posts per `movementId`,
`apps/api/app/Modules/Accounting/Listeners/PostGrIrOnGoodsReceipt.php:36,46`). A receipt with N
lines produces up to 2N GR-IR rows (paid + free), each keyed by a `goods_receipt_lines.movement_id` /
`free_movement_id` — **never by `goods_receipts.id`**.

A join `journal_entries.source_id = goods_receipts.id` matches **zero rows for every receipt**,
so the command would flag every posted receipt as drift → `test_clean_tenant_exits_zero`
(plan line 170) fails, and the "fix" ships an always-red command. The parenthetical "expected
… receipt id" actively misleads the query and the test ("delete the receipt's journal entry
rows", line 167, presumes receipt-level rows).

**Correction:** Drift join must go `goods_receipts` → `goods_receipt_lines` (its `movement_id`
AND `free_movement_id`) LEFT JOIN `journal_entries` on `source_type='goods_receipt' AND
source_id = movement_id`; a receipt is "drifted" if any of its lines' movement ids lack an
entry. Delete the "expected … receipt id" guess and instruct the implementer to read
`GeneralLedgerService::createGoodsReceiptGrIrEntry` first and derive the **grain** (movement),
not just the string.

---

## MAJOR findings

### M1 — Invoice-first auto-PO cannot be tagged `invoice_first`; the §7.4 approval gate silently no-ops
**Tasks 4.2, 5.1, 5.4.** `StandaloneReceiptService::execute` hardcodes
`payload->auto_generated = ['source'=>'standalone_receipt', …]` (plan lines 406-408), and its
DTO `StandaloneReceiptData` (plan lines 396-400) has **no `source`/`flow` field**. Task 5.1
says the invoice-first delivered path "**reuses `StandaloneReceiptService::execute`**" (plan
line 462), which would stamp `'standalone_receipt'`. But Task 5.4's approval gate fires only
"when the SI's chain includes an **`invoice_first`** auto-PO" (plan line 495), and spec §7.2
requires `{source:'invoice_first'}` (spec line 234). There is no existing detector to lean on
(`rg` finds no `invoice_first`/`auto_generated` reads in `SupplierInvoicePostingService`).
Result: invoice-first auto-POs carry `source='standalone_receipt'`, the gate never matches,
and `invoice_first_requires_approval` is unenforced — a control the spec treats as
second-person separation of duties.

**Correction:** Add a `source` (or `flow` enum) field to `StandaloneReceiptData` defaulting to
`'standalone_receipt'`; Task 5.1 passes `'invoice_first'`; Task 5.4 keys the gate on it. State
the exact payload path the gate queries.

### M2 — Idempotency is a pre-check with no DB uniqueness → double-submit race
**Task 4.2 (plan lines 411-413).** "idempotency: `payload->auto_generated.idempotency_key`
unique pre-check → returns existing result instead of duplicating." A SELECT-then-INSERT with
no unique constraint is a classic TOCTOU: two concurrent identical submits both pass the
pre-check and both create an auto-PO + receipt (defeating the compensation model too). Spec
§6.3 asserts the key "prevents double-submit duplicates" but neither spec nor plan defines a
DB-level guard.

**Correction:** Back the idempotency key with a unique index (e.g. a dedicated
`procurement_idempotency_keys(company_id, key)` unique table, or a partial unique index on the
extracted key), catch the unique-violation and return the existing result. A JSONB-path
pre-check alone is racy — add a red test that concurrently/sequentially replays the same key
and asserts a single PO/receipt (the plan's "duplicate idempotency key → single PO/receipt"
test, line 419, currently passes even with the racy design because it is single-threaded).

### M3 — Task 7.2 cancel consolidation is a dangerous refactor with under-scoped pins
**Task 7.2 (plan lines 567-575).** The task reroutes "the 3 scattered cancel implementations …
through the service", but the only pins are "paid invoice cancel → 422; unpaid posted invoice
cancel → Cancelled+Voided". The three real sites diverge in behavior:
`RefundService::cancelInvoice` blocks Posted **and** Paid, `SalesOrderService::cancel` blocks
Posted only (`apps/api/app/Modules/Document/Domain/Services/SalesOrderService.php:203,220`,
which also calls `cancelAndReleaseStock`→`releaseBySource` `:237`), and
`DocumentPostingService::cancel` is the fiscal-void path with **no `actorId` param and no
unpaid guard today** (`.../DocumentPostingService.php:100-140`). Rerouting SO cancel through a
consolidated `cancel()` risks dropping the reservation-release side effect; rerouting refund
cancel risks changing the Paid-block semantics. Neither is pinned.

**Correction:** Add regression pins for the SO-cancel path (reservations released after cancel)
and the refund/invoice-cancel path before rerouting; specify whether `DocumentPostingService::cancel`
gains an `actorId` param (it currently has none) and how the SO/refund callers pass reason/actor.

### M4 — JSONB-path query portability handled in W4.3 only; three other tasks query JSONB unguarded
**Tasks 4.2, 5.4, 7.1.** Task 4.3 correctly carries an explicit SQLite-JSON-path
verification instruction (plan lines 433-436). But the same portability hazard recurs
unflagged in: Task 4.2 idempotency lookup on `payload->auto_generated.idempotency_key`; Task
5.4 "chain includes an `invoice_first` auto-PO" detection; and Task 7.1 PO-revert guard
"payload `source_document_ids` containment" (plan line 562). Under SQLite these `->` /
containment operators behave differently from pgsql; an unguarded query can pass tests on
SQLite and break on pgsql (or vice-versa).

**Correction:** Propagate W4.3's "verify the JSON-path approach works on BOTH engines, cite
it, `like '%…%'` FORBIDDEN" instruction into Tasks 4.2, 5.4, 7.1.

---

## MINOR findings

### m1 — Task 2.2 test calls a non-existent resolver method
Plan line 218 pseudocode: `$this->resolver->resolve($tenantId, $companyId)`. The actual API is
`ProcurementPolicyResolver::forCompany(string $companyId)` (`.../ProcurementPolicyResolver.php:24`)
— there is no `resolve()`. A verbatim Codex transcription red-fails for the wrong reason.
**Fix:** use `forCompany($companyId)`; the synthesized default lives in
`ProcurementPolicy::defaultForVertical` (`:40`) — that is the source to amend for fail-closed.

### m2 — Permission naming diverges from existing convention (design-review m1 not carried over)
Task 2.4 (plan lines 252-256) introduces `goods-receipts.create-standalone` (plural) and a new
`supplier-invoices.*` namespace. Existing seeder uses **singular** `goods-receipt.edit-price`
and the `invoices.*` namespace (`database/seeders/RolesAndPermissionsSeeder.php:121,131,430`);
there is no `supplier-invoices.*` permission anywhere. The claude design review's finding m1
("rename to `invoices.*` convention") is unaddressed and compounded (goods-receipt→goods-receipts).
**Fix:** align to `goods-receipt.create-standalone` / `invoices.create-pending` /
`invoices.link-receipts` / `invoices.approve-invoice-first`, or justify the new namespaces.

### m3 — SO-revert does not address dangling immutable confirm events (design-review M5 partial)
Task 7.1 releases SO reservations (good) but says nothing about the `SalesOrderConfirmed` /
`SalesOrderConfirmedV2` audit events emitted at confirm (`SalesOrderService.php`). Per CLAUDE.md
rule 8 (events immutable), a revert should emit a compensating `SalesOrderReverted`, not leave
a dangling confirm. **Fix:** specify the compensating event (or explicitly accept the dangling
confirm and document why).

### m4 — Spec §6.4 promises an RFQ-award-guard pin that no plan task creates
Spec line 210 / §6.4: "RFQ award guard unaffected (auto-POs never RFQ-sourced; **test pins**)."
No W4 task adds this pin (Task 4.3's test list, plan lines 429-432, omits it). Benign in
practice (auto-POs have null `source_document_id`) but the promised guardrail is missing.
**Fix:** add the pin to Task 4.3 (auto-PO does not trip `RFQ_GROUP_ALREADY_AWARDED`).

### m5 — Task 3.1 SQLite partial-index caveat is technically wrong (harmless)
Plan line 293: "SQLite ignores WHERE — note caveat." SQLite **does** support partial indexes
(≥3.8.0), and independently treats NULLs as distinct in UNIQUE indexes — so two NULL-numbered
drafts coexist on **both** engines for the right reason. The caveat's rationale is inaccurate;
the test conclusion is fine. **Fix:** correct the caveat wording (the risk is not "SQLite
ignores WHERE").

### m6 — "locate the aged payables test file" resolves nowhere obvious
Task 4.3 (plan line 432) says locate the aged payables test file; `rg -l` finds none under
`tests/Feature/Accounting`. A Codex worker with zero context may not find it. **Fix:** name the
file path or the search glob explicitly.

### m7 — Task 3.2 interface block omits its hard dependency on Task 3.1
Task 3.2's model-created Draft needs `receipt_number=NULL`, which only exists after Task 3.1's
migration. Within-wave ordering happens to satisfy it, but the "Consumes" block (plan lines
308-314) should declare the 3.1 dependency (and, per BL1, the nullable-cost-columns dependency).

---

## Design-review coverage matrix (every prior BLOCKER/MAJOR/MINOR → plan task)

| Prior finding | Mapped task | Status |
|---|---|---|
| Codex BLOCKER: GR-IR not atomic/fail-closed | 4.2 `failClosedGrir=true` + 1.2 drift | Covered (but see BL2 for 1.2 grain) |
| Codex BLOCKER: Confirmed PO editable | 1.1 guard | Covered |
| Codex BLOCKER: parked Draft SI unsupported | 5.2 + 5.3 | Covered |
| Codex MAJOR: BL identity columns | 4.1 | Covered |
| Codex MAJOR: auto-PO hiding not local | 4.3 + spec §6.4 matrix | Covered (RFQ-award pin missing, m4) |
| Codex MAJOR: policy fail-open | 2.1 + 2.2 | Covered (m1 method-name) |
| Codex MAJOR: draft lifecycle split | 3.3 | Covered (but BL1 blocks it) |
| Codex MINOR: system actor | 4.2 explicit-actor param on `confirm()` | Covered |
| Claude B1: matcher status filter | 3.2 | Covered (verified :151/:414 + planner :30) |
| Claude B2: rematch doesn't relink | 5.3 link-receipts endpoint | Covered |
| Claude M1: attempts:3 retry / umbrella txn | architecture "never one umbrella txn"; 4.2 saga | Covered |
| Claude M2/R3: serializer drops flag + consumers | 4.3 `is_auto_generated` + exclusion | Covered (m4) |
| Claude M3: policy fail-open | 2.2 | Covered |
| Claude M4: revert awarded PO stuck | 7.1 `PO_FROM_RFQ_AWARD` | Covered |
| Claude M5: SO revert reservations + events | 7.1 (reservations) | **Partial** (m3 events) |
| Claude M6: draft must not write matcher qty | 3.3 + 3.2 | Covered (BL1 blocks) |
| Claude M7: StandaloneReceiptService placement | file map Procurement/Application + deptrac ratchet | Covered (deferred to ratchet) |
| Claude M8: receipt_number NOT NULL + unique | 3.1 | Covered (BL1: cost cols missed) |
| Claude M9: migration backfill fail-closed | 2.1 | Covered |
| Claude m1: permission naming convention | — | **NOT covered** (m2) |
| Claude m2: i18n `purchases` namespace | 2.5/4.4/5.5 | Covered |
| Claude m3: FormRequest money regex | Global Constraints | Covered |
| Claude m4: cancel not unified (3 impls) | 7.2 | **Partial** (M3 pins) |
| Claude R1: auto-PO confirm side effects | — | Benign, unaddressed (no listeners; landed-cost alloc is desired) |

---

## TDD-quality notes (focus 1)
- **Strong pins:** Task 3.3 reuses the existing Wave-3 ledger-test assertions to pin the
  `receiveGoods` refactor end-state (plan line 356) — the right way to fence a dangerous split.
  Task 1.1 (three cases) and Task 2.3 (preset preservation) are genuine fail-first behavior pins.
- **Vacuous risk:** Task 3.2's matcher pin is behavior-correct (returns 5.0000 today, must
  become 0.0000) BUT is blocked by BL1 — it fails at INSERT, not at the assertion, until the
  NOT NULL columns are resolved.
- **Under-pinned refactor:** Task 7.2 (M3) and the idempotency test (M2) pass vacuously against
  their stated designs.

## Risk-hotspot notes (focus 5)
- **Umbrella transaction:** no task wraps t1+t2 in one transaction — the plan consistently
  states "sequential transactions with compensation" (architecture note + 4.2). Clean.
- **Idempotency race:** present (M2).
- **Partial-unique SQLite:** benign for the draft-number case (m5), but the *idempotency*
  unique index (M2 fix) must itself be pgsql-partial-safe — specify it.
- **JSONB portability:** inconsistent (M4).
