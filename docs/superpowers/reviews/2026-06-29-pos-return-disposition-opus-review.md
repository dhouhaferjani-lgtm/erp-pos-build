# POS Return Disposition — Adversarial Design Review (Opus)

> **Date:** 2026-06-29
> **Reviewer:** Opus adversarial design reviewer
> **Inputs:** `docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md`; prior Codex review `docs/superpowers/reviews/2026-06-29-pos-return-disposition-codex-review.md`
> **Bar:** "is this design ready to plan & implement", NOT "is the code already written". The spec is a DESIGN doc; proposing to add a column/enum/service is not a defect.
> All code claims cross-checked against the worktree at HEAD; file:line cited.

---

## Executive summary

The core design thesis is **sound and plannable**: money-refund ⟂ physical-disposition, the two-fact (`physical_receipt` × `resalable`) → derived `ReturnLineDisposition` model, two-movement scrap, the `restock_policy` resolution hierarchy, and the phasing are all defensible and match industry practice. **Phase 0 can become an implementation plan now**, with a small set of spec edits folded in.

However, Codex's review is dominated by **greenfield-misreading**: ~9 of its findings flag proposed infrastructure as "UNVERIFIABLE … BLOCKER" because the code doesn't exist yet — which is exactly what a design spec is for. Those are not defects. After stripping them out, a **small kernel of genuinely valuable findings survives** (WAC wording, the regulated `never` guard belonging in Phase 0, the unsigned-disposition fiscal claim), and I add **three findings Codex missed that are more serious than anything it raised** — chiefly that the codebase has **already chosen a refund fiscal model that directly contradicts spec §5**, and that the existing sale projection is a **live stock-decrement landmine for refunds**.

---

# PART 1 — Triage of the Codex review

### (a) Disposition model holes

**Codex: "`ReturnLineDisposition`/`physical_receipt`/`resalable` UNVERIFIABLE → BLOCKER"**
**Verdict: INVALID / GREENFIELD-MISREADING.** Confirmed these symbols do not exist (grep for `ReturnLineDisposition`/`restock_policy` over `apps/api/app` + `database` returns nothing). But the spec *proposes* building them (§2.1, Phase 0 §6). The live contract is indeed quantity-only today — `processReturn` doc-types input as `array{line_id, quantity}` (`ReceiptReturnService.php:93`) and `validateReturnQuantities` reads only those two keys (`:1026-1029`). That is the *starting point the spec changes*, not a defect. **Severity downgrade: not a blocker.** The *one kernel worth keeping*: the spec should state the **illegal-combination validation** (`physical_receipt=false ⇒ resalable MUST be null`; `NOT_RECEIVED ⇒ no batch restitution`). Fold as a spec edit (see Part 3 #8).

**Codex: "QUARANTINE state-transition not constrained → IMPORTANT"**
**Verdict: INVALID for the scope under review.** Quarantine/reclassification is explicitly Phase 2 (spec §2.1 table, §6). Demanding a transition table now is scope-creep against a deferred phase. Legitimate only as a Phase-2 note.

**Codex: "`REFUND_RECEIPT` not in `IMPLEMENTED_EVENT_TYPES` / offline lookup → IMPORTANT"**
**Verdict: PARTIAL.** Factually correct — `FiscalEventType::isImplemented()` omits `SALE_VOID/REFUND_RECEIPT/PARTIAL_REFUND` (`FiscalEventType.php:45-72`; the three cases are declared at `:29-32`), and the device registry likewise omits them. But this is Phase 3 work the spec defers. Codex **missed the more important fact** (see Part 2, P2-1): refunds are *already* modeled and server-live via a different path. Keep only the benign ask: Phase 0/1 must add tests asserting no code authors those event types yet.

### (b) Two-movement scrap inventory approach

**Codex: "Live path writes a single Receipt movement, no WriteOff leg → BLOCKER"**
**Verdict: INVALID / GREENFIELD-MISREADING.** Correct description of current code — `restoreStock` creates exactly one `MovementType::Receipt` / `MovementReason::POSReturn` row (`ReceiptReturnService.php:1168-1186`). The spec *proposes* adding the second `Adjustment`/`WriteOff` leg (§2.2). Describing the second leg as missing-today is describing the work. Not a defect; the "atomic, two-movement, idempotent" requirement is good plan-input.

**Codex: "WAC-correctness claim unsupported; `restoreStock` bypasses `WeightedAverageCostService::recordReturn` → BLOCKER"**
**Verdict: VALID KERNEL, but severity-inflated AND Codex's prescribed fix is WRONG.** Confirmed: `restoreStock` writes **no** cost-ledger fields (`unit_cost/total_cost/avg_cost_before/avg_cost_after`) — `:1168-1186` sets only quantity columns, though those cost columns exist and cast `decimal:6` (`StockMovement.php:71-74,98-101`). The WAC path `recordReturn` (`WeightedAverageCostService.php:467-576`) — which blends at original cost and updates `products.cost_price` — is **never called by the POS return service**. So the spec's "WAC-correct" wording (§1 line 34) is unsupported in *this* codebase.
**BUT:** (1) the **current `RESTOCK` path is already quantity-only** — this is pre-existing, default-preserving behavior, not a regression the spec introduces; (2) for a **net-zero SCRAP**, calling `recordReturn` would actively *corrupt* WAC — it would blend the returned unit into the average, and the write-off leg would then have to un-blend it; quantity-only net-zero correctly leaves the remaining-inventory WAC **unchanged**, with the loss booked downstream in GL — which the spec already says (§2.2 line 66, §2.2 line 69). **Correct resolution: a spec WORDING fix** ("Phase 0 movements are quantity-only, consistent with the existing return path; valuation/shrinkage loss is deferred to the GL phase; do NOT integrate `recordReturn`") — **not** Codex's "must wire WAC." This is a must-fix *edit*, not a code blocker.

**Codex: "Batch restore updates `inventory_batch_stock` directly, no batch movement row → IMPORTANT"**
**Verdict: PARTIAL — and Codex missed the real bug.** Correct that `restoreBatchAllocations` mutates `inventory_batch_stock.quantity` directly with no batch-level movement row (`:1249-1276`). The audit-row point is minor. The *real* defect Codex only grazed is the **SCRAP batch inflation** — elevated to Part 2 P2-3.

### (c) `restock_policy` hierarchy

**Codex: all three "enum / columns / `ReservationSettings.default_restock_policy` UNVERIFIABLE" → IMPORTANT/BLOCKER**
**Verdict: INVALID / GREENFIELD-MISREADING for the existence claims.** Confirmed none exist: no `RestockPolicy` enum; `products`/`categories` migrations have no `restock_policy`; `ReservationSettings` has no restock field (grep returns nothing). All are spec proposals (§3, Phase 0 §6). `categories.parent_id` *does* exist as a self-referencing FK (`create_categories_table.php:17`, `nullOnDelete`), so the proposed parent-walk is feasible. **Not defects.** The *one kernel worth keeping*: the spec must define **fail-closed behavior when the policy is unresolved** (Codex's "must not silently restock regulated goods" is right) — fold as a spec edit. Note the resolver is **built-but-not-enforcing** in Phase 0 by design (§3 last paragraph), so the "additive" claim holds *for generic tenants* — but see P1(e)/Part 2 for the regulated case.

### (d) NF525 compliance

**Codex: "`REFUND_RECEIPT/SALE_VOID/PARTIAL_REFUND` reserved-not-implemented" → MINOR/IMPORTANT**
**Verdict: VALID, MINOR.** Confirmed (`FiscalEventType.php:29-32` declared, omitted from `isImplemented()`). The spec already says exactly this (§0 line 20). Benign.

**Codex: "Legacy/v3 hash binds no disposition; §7 'does not weaken any fiscal invariant' unsupportable → BLOCKER/IMPORTANT"**
**Verdict: VALID — this is one of Codex's two genuinely valuable findings.** Confirmed: the legacy hash input is `receipt_number/posted_at/total/currency/vat_breakdown_hash/payment_methods_hash` only (`ReceiptHashService.php:84-93`); the V3 audit block binds only `authorized_by_user_id/override_reason/out_of_window/policy_trigger/refund_request_id` — **no disposition, no physical facts, no stock-movement ids**. So a DB mutation of disposition changes inventory semantics **without changing the fiscal hash**. The spec's §7 phrasing ("does not weaken any fiscal invariant") is technically true only because disposition is **outside the signed perimeter entirely** — which the spec should *say*, not gloss. **Correct resolution: downgrade/clarify the claim** — disposition is an operational inventory fact (*tenue de caisse*), not *règlement* data, and is intentionally **not** in the signed chain in Phase 0. Must-fix edit (Part 3 #5).

**Codex: "`REFUND_RECEIPT` DTO ambiguous; `SaleReceiptPayloadInput` can encode REFUND but has no disposition → BLOCKER for Phase 3"**
**Verdict: VALID, and the most consequential thing Codex *half*-saw.** Confirmed `SaleReceiptPayloadInput.invoice_type_code` accepts `'REFUND' | 'VOID'` (`FiscalEventEngine.ts:406`) and `OriginalReceiptReferenceInput` (`:366-374`) carries only `fiscal_event_id/original_business_date/original_receipt_uuid/refund_reason` — **no line subset, no per-line disposition**. Codex flagged the ambiguity but did not realize the codebase has **already decided** the model (Part 2 P2-1) — making spec §5 not merely "underspecified" but **contradictory**. Elevated.

### (e) Phasing safety

**Codex: "Phase 0 additive only if callers trusted; caller could pick RESTOCK for medicines → BLOCKER for regulated rollout"**
**Verdict: VALID — Codex's second genuinely valuable finding, and exactly the prompt's concern.** The spec defers the `never ⇒ no RESTOCK` guard to Phase 1 (§3 line 89, §6 line 125) while shipping disposition *acceptance* (incl. `RESTOCK`) in Phase 0. The motivating context is the **parapharmacy launch** (§0) — i.e. a regulated tenant where dispensed medicines are destroy-only. Shipping Phase 0 there with a caller-selectable `RESTOCK` and no backend guard is a correctness/compliance hole. **The `never ⇒ no RESTOCK` backend guard must move into Phase 0.** Must-fix (Part 3 #1).

**Codex: "Phase 1 depends on resolver/columns that don't exist → IMPORTANT"**
**Verdict: INVALID as stated** (those are built in Phase 0 by the same spec). The dependency ordering is internally consistent.

**Codex: "Phase 3: `SALE_RECEIPT` w/ `invoice_type_code='REFUND'` through `PosCoreReceiptProjection` always decrements → BLOCKER for Phase 3 cutover"**
**Verdict: VALID and UNDERSTATED.** Confirmed and elevated to Part 2 P2-2 — the path is **already server-live**, not a future Phase-3 risk.

### (f) Fiscal-event migration plan

**Codex: "No sibling reversal projection exists → BLOCKER"**
**Verdict: PARTIAL / mis-diagnosed.** `PosCoreReceiptProjection.handlesEventType` returns true only for `SALE_RECEIPT` (`:138-141`), so there is no *separate* projector — Codex is literally right. But the existing projector **already handles REFUND/VOID** (it resolves `original_receipt_reference`, maps to `ReceiptType::Return` at `:241`, fail-closed-gates an unresolvable original at `:231-235`). The real defect is **not** "missing projector" — it's "the existing handler decrements stock for refunds and has no disposition hook" (P2-2). The fix may be a disposition branch *inside* the handler or a sibling; Codex's framing picks one option and mislabels the current state.

**Codex: "No legacy coexistence/migration plan → IMPORTANT"**
**Verdict: VALID, reasonable.** Confirmed dual-arm chain verification: canonical-bytes rows vs legacy `fiscal_event_id IS NULL` rows (`ReceiptHashService.php:31-37`). Existing `ReceiptReturnService` receipts stay legacy rows. A coexistence note is a fair ask for Phase 3/4.

**Codex: "`OriginalReceiptReferenceInput` has no line subset / disposition → BLOCKER"**
**Verdict: VALID for Phase 3.** Confirmed (`:366-374`). Partial returns + per-line disposition cannot be expressed in the current canonical payload. Fair Phase-3 ask.

**Codex's bottom line ("Can ship as-is? NO"):** the *conclusion* is defensible (Phase 0 needs edits before a plan), but it is reached largely **for the wrong reasons** — treating greenfield as defects and inflating severities. The design is plannable.

---

# PART 2 — Findings Codex MISSED (attacking the design itself)

### P2-1 — **The refund fiscal MODEL is already decided in code, and spec §5 contradicts it** (most important)

Spec §5 (line 107) proposes building **separate `REFUND_RECEIPT` / `SALE_VOID` device event types**. But the codebase has **already committed to the opposite model**: a refund/void is a **`SALE_RECEIPT` fiscal event carrying `invoice_type_code='REFUND'|'VOID'` + `original_receipt_reference`**:
- `FiscalPayloadConstraintValidator::INVOICE_TYPE_CODES = ['SALE','REFUND','VOID','TRAINING']` (`:186`); the validator enforces `invoice_type_code ∈ {REFUND,VOID} ⇒ original_receipt_reference required` (`:1663-1698`).
- The validator states the decision explicitly: *"Refunds are modeled via `invoice_type_code='REFUND'` + `original_receipt_reference`, NOT negative amounts."* (`:2609-2610`).
- The device payload already encodes it (`SaleReceiptPayloadInput.invoice_type_code: 'SALE'|'REFUND'|'VOID'|'TRAINING'`, `FiscalEventEngine.ts:406`; `OriginalReceiptReferenceInput` `:366-374`).
- The server projection already maps REFUND/VOID+original to `ReceiptType::Return` (`PosCoreReceiptProjection.php:241`, `resolveReceiptType` `:438-445`) and fail-closed-gates an unresolvable original (`:231-235`).

So the `REFUND_RECEIPT`/`SALE_VOID` enum cases are **vestigial** — the implemented engineering reality is the `invoice_type_code` axis on `SALE_RECEIPT`. As written, spec §5 would introduce a **second, competing refund model**. **The spec must reconcile this**: adopt the existing `invoice_type_code='REFUND'/'VOID'` model (recommended — it is built, validated, and projected) and **drop or re-scope** the separate-event-type proposal, OR explicitly justify migrating away from a model already in the fiscal validator. Per-line disposition + line-subset should then be added as new keys on the canonical refund payload / `original_receipt_reference`, not on a new event type.

### P2-2 — **Live latent hazard: the sale projection decrements stock for refunds, with no disposition hook**

`PosCoreReceiptProjection::apply()` calls `decrementStockForLines()` **unconditionally** at `:324` for *every* `SALE_RECEIPT` event — including ones it has just mapped to `ReceiptType::Return` for `invoice_type_code='REFUND'|'VOID'` (`:241`). `decrementStockForLines` (`:918-948`) → `decrementStock` (`:959+`) has **no branch on receipt type or invoice_type_code** — it always subtracts. Because the REFUND/VOID canonical path is **already accepted server-side** (validator + projection), a device-authored refund through this path would **decrement** stock (compounding the loss) instead of restoring it, and there is **no disposition seam anywhere in this path**. Phase 0's work is entirely on the *legacy* `ReceiptReturnService` and does not touch this. **Recommendation:** add a guard + regression test (Phase 0 or an immediate fast-follow, since the path is server-live) asserting the projection does **not** decrement for `invoice_type_code ∈ {REFUND,VOID}`; the disposition-gated stock branch (or sibling projector) becomes the Phase 3 deliverable.

### P2-3 — **SCRAP leaves `inventory_batch_stock` inflated (net-zero only at the aggregate)**

Spec §2.2 defines SCRAP as `+qty` receive then `−qty` write-off (net-zero on sellable `stock_levels`), and §2.2 line 69 says "all movements carry lot/batch via the existing batch-allocation restore." But `restoreBatchAllocations` (`:1213-1278`) **adds** quantity back into `inventory_batch_stock` and there is **no compensating batch decrement on the write-off leg** (the write-off leg is an aggregate `Adjustment` with no batch dimension; `StockMovement` has no batch column). Result for SCRAP: aggregate `stock_levels` nets to zero, but `inventory_batch_stock` is **inflated** by the returned qty → phantom batch stock that FEFO can later "sell." **The spec must specify** that SCRAP **skips** batch restitution (goods are scrapped, never re-enter a sellable batch) **or** posts a compensating batch decrement. Equivalently, both `restoreStock` *and* `restoreBatchAllocations` must be disposition-branched (see P2-6).

### P2-4 — **`SALE_VOID` stock effect is self-contradictory in the spec**

§1 (line 31) says void = "goods never left → **no stock effect**." §5 (line 110) says `SALE_VOID` → "**full restock** of all lines." Under device-SoT the *sale* already decremented stock at projection time (`decrementStockForLines`), so a void of a signed sale must **re-increment** to undo that decrement — i.e. §5's "full restock" is correct and §1's "no stock effect" is misleading. Reconcile: a void of an *unsealed/abandoned* cart has no stock effect (no decrement happened), but a void of a *signed* sale must reverse the sale's decrement.

### P2-5 — **`NOT_RECEIVED` must skip BOTH stock paths, not just `restoreStock`**

The current loop (`:396-431`) unconditionally calls both `restoreStock` *and* `restoreBatchAllocations` per line. The spec's `NOT_RECEIVED` = "zero stock movements" must explicitly branch **both** calls off (Codex mentioned only `restoreStock`). Concrete Phase-0 plan input.

### P2-6 — **Disposition × the existing cumulative "already-returned" tracking (mixed dispositions)**

`calculateAlreadyReturnedQuantities` (`:1069-1109`) tracks returned quantity per original line, **disposition-agnostic**. A line sold qty 3 can legally be returned 1×`RESTOCK` then 2×`SCRAP`. Quantity tracking still works, but the design must confirm disposition is stored on the **new return-receipt line** (each return event), not assumed uniform per original line — otherwise mixed dispositions across sequential partial returns cannot be represented. §2.1 says "each return line records two facts," which is compatible, but the spec should state explicitly that disposition is per-return-line and that the cumulative cap (`:1042-1052`) remains disposition-independent.

### P2-7 — **`RESTOCK` receive cost basis is "current `cost_price`", not "original cost"**

§2.2 line 62 says RESTOCK restores "at original cost." But `restoreStock` writes no cost ledger; stock value is implicitly `products.cost_price × qty` at *current* cost_price, which may have drifted since the sale. Pre-existing and harmless for Phase 0 (quantity-only), but the "at original cost" wording is inaccurate for this path and should be corrected to avoid implying a WAC re-blend that doesn't happen.

### P2-8 — **Idempotency of the new stock legs is actually fine for Phase 0** (Codex over-asked)

Codex demanded proof that idempotency "covers the new stock movement legs." For the legacy path it already does: the entire return runs in one `DB::transaction` keyed on `refund_request_id` with a lock + unique-index backstop (`:204-233`, `:457-494`); a replay returns the existing receipt without re-running stock. So the Phase-0 two-leg SCRAP inherits receipt-level idempotency for free. For Phase 3, the disposition stock branch must live **inside** the existing `INSERT … ON CONFLICT … DO NOTHING` guard keyed on `fiscal_event_id` (`:334-364`). Worth a one-line note, not a blocker.

---

# PART 3 — Verdict

### (a) Is the overall design sound?
**Yes, in its core thesis.** Money ⟂ disposition, the two-fact→derived-disposition model, two-movement scrap, the `restock_policy` resolver hierarchy, and the phasing are well-grounded and match Square/Shopify/Dynamics/Odoo practice. The design is **plannable**. It has **one genuine design contradiction** (P2-1: the refund fiscal-event model) and **several over-reaching prose claims** (WAC-correctness, "does not weaken any fiscal invariant", void "no stock effect") that must be corrected before they mislead an implementer.

### (b) Is the Phase 0 first slice safe to turn into a plan now?
**Yes — additive and safe for generic-retail tenants as written, but NOT safe for the regulated (parapharmacy/pharmacy) tenants that motivate it** unless the `never ⇒ no RESTOCK` guard is pulled into Phase 0. With the corrections below folded in, Phase 0 (disposition enum + threading + two-movement SCRAP + `NOT_RECEIVED` no-op + resolver-built-not-enforcing **+ the `never` guard**) is a clean, additive, default-preserving slice ready for a TDD plan. Phase 0 deliberately does **not** touch the fiscal hash or the projection path, which is the right call.

### (c) Minimal spec edits required before proceeding

1. **Move the `never ⇒ no RESTOCK` backend guard into Phase 0** (regulated-goods safety; the launch is parapharmacy). The rest of resolver *enforcement* may stay deferred, but the hard `never` rejection ships with disposition acceptance. (P1(e))
2. **Reconcile the refund fiscal model:** adopt the existing `SALE_RECEIPT` + `invoice_type_code='REFUND'/'VOID'` + `original_receipt_reference` model (already in the validator & projection); **drop or explicitly re-scope §5's separate `REFUND_RECEIPT`/`SALE_VOID` device event types.** Add per-line disposition + refunded-line-subset as new canonical keys (Phase 3). (P2-1, P1(d))
3. **Fix the SCRAP batch-stock inconsistency:** specify SCRAP **skips** batch restitution (or posts a compensating batch decrement) so `inventory_batch_stock` does not inflate; specify `NOT_RECEIVED` skips **both** `restoreStock` and `restoreBatchAllocations`. (P2-3, P2-5)
4. **Downgrade the cost claim:** Phase 0 movements are **quantity-only**, consistent with the existing return path; valuation/shrinkage loss is deferred to the GL phase; do **not** integrate `WeightedAverageCostService::recordReturn` (it would corrupt WAC on net-zero scrap). Fix the "restoring at original cost" wording. (P1(b), P2-7)
5. **Downgrade the NF525 claim (§7):** state disposition is an operational inventory fact (*tenue de caisse*), **not** in the signed *règlement* perimeter in Phase 0; a disposition DB mutation does not and need not change the fiscal hash. Do not imply it is signed or strengthened. (P1(d))
6. **Reconcile the `SALE_VOID` stock-effect contradiction** between §1 ("no stock effect") and §5 ("full restock"): a void of a *signed* sale must re-increment to reverse the sale's projection decrement. (P2-4)
7. **Add the Phase 3 projection hazard + test** (and consider an interim Phase-0/fast-follow guard, since the path is server-live): `PosCoreReceiptProjection` must **not** decrement stock for `invoice_type_code ∈ {REFUND,VOID}`; disposition-gated restock/scrap/no-op must replace the unconditional decrement, inside the `fiscal_event_id` idempotency guard. (P2-2, P2-8)
8. **Specify the disposition input contract:** illegal-combination validation (`physical_receipt=false ⇒ resalable=null`; `NOT_RECEIVED ⇒ no batch restitution`), disposition stored **per return-receipt line**, and a **fail-closed** default when `restock_policy` is unresolved (never silently `RESTOCK` for regulated goods). (P1(a), P1(c), P2-6)

With edits 1–8 folded in, the spec is ready to become a Phase 0 implementation plan.
