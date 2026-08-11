# ES register — corrections addendum (entry gate round 1, 2026-08-11)

**Why this file exists.** `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/00-CONSOLIDATED-REGISTER.md` is the **immutable record of the audit** — it is not edited after the fact (handover §7: *"Touch `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/` — it is the audit's record"* is forbidden). The entry-gate review of 2026-08-11 nonetheless produced **row-level corrections** to that register. They are recorded here.

**The program reads the register and this addendum together.** Where the two disagree, **this addendum wins** for the rows listed below; the register remains authoritative for everything else (row set, severities, evidence pointers, dedup ledger, launch relevance).

**Provenance.** Entry gate round 1 = **CHANGES-REQUIRED**, independent Codex reviewer, HEAD `0c00cf526`. Verdict of record: [`docs/superpowers/reviews/2026-08-11-event-sourcing-entry-gate-verdict.md`](../superpowers/reviews/2026-08-11-event-sourcing-entry-gate-verdict.md). Every correction below was **re-verified against code by the orchestrator** before being written down.

---

## 1. Row-level corrections

### ES-07 — scope NARROWED (register §2.1 row 7, and §6 "Reading notes" note 2)

**Register wording (now overstated):** *"`pos:verify-chains` reports 'All chains verified successfully' having read zero fiscal-era rows."*

**Corrected claim.** Two of the three sub-claims stand; the "zero fiscal-era rows" framing does not.

| Sub-claim | Verdict | Evidence (re-verified) |
|---|---|---|
| Projected **receipts** are excluded, and a terminal with only projected receipts returns `is_valid: true` over a count of 0 | **STANDS** | `VerifyPosChainCommand.php:293-318` (`whereNull('fiscal_event_id')`, `$count === 0 → is_valid true`), same carve-out in `findReceiptChainBreak()` `:337-346` |
| No cross-check of `pos_receipts.fiscal_hash` ↔ `fiscal_events.current_hash` | **STANDS** | no such comparison anywhere in `VerifyPosChainCommand.php` or `ReceiptHashService::verifyTerminalChain()` |
| *"Reads **zero** fiscal-era rows"* / *"excludes every projected row"* | **FALSE — withdrawn** | the **Z-report branch counts ALL `pos_z_reports` rows including projected ones** (`VerifyPosChainCommand.php:372-398`, no `fiscal_event_id` filter) and delegates to `ZReportHashService::verifyZReportChain()` `:210-216`, whose `verifyFiscalEventsArm()` `:251-293` **walks the `z_session` / `training_z_session` `fiscal_events` chains and re-hashes `canonical_bytes` against `current_hash` and `previous_hash`, seeded from `terminal.genesis_seed`** |

**Net effect on the program.** ES-07 remains a **P0** and Lane A0 still heads the program — a v3 tenant's *receipt* chain is genuinely unverified, and the receipt↔fiscal-event mirror is genuinely unchecked. But **the A0 fix is narrower than the register implies**: the Z-report arm is already fiscal-era-aware and must not be rewritten as if it were blind. Any A0 red-run fixture must tamper a **receipt**-side row (or the missing mirror) to fail, not a `z_session` chain — that one already fails correctly today.

### ES-76 — count corrected: **14 listed modules**, not "~13"

Register §2.3 row ES-76 and §4 theme T8 both say *"~13 modules"* while the same row **lists 14**: Uom, Menu, Media, Admin, Contact, Coupon, Progression, Promotion, PlatformIntegration, Service, SupportAccess, DocumentIngestion, Notification, Income (`00-CONSOLIDATED-REGISTER.md:147`). Read as **"14 listed modules, unverified"**. Status is unchanged (SUSPECTED — needs a real mutation sweep, not a directory count).

### ES-84 — wording corrected (the float-cast half)

Register §2.4 row ES-84 says *"`->decrement('reserved', (float) $reservation->quantity)` at four sites"*. The four sites are **not** four `reserved` calls: they are **two casts on `reserved_quantity` (BatchStock) + two on `reserved` (StockLevel)** — `StockReservationService.php:267-283` (release path) and `:391-407` (expiry path). The rule-19 defect is identical in both; only the column differs. Precision-lane ownership is unchanged.

---

## 2. Rows the gate re-verified but did NOT change

The gate sampled beyond the P0 set. These rows survived unchanged and should not be re-litigated: **ES-06, ES-08, ES-01, ES-05, ES-02, ES-10, ES-09, ES-03, ES-04** (all P0 except ES-07 → **9 CONFIRMED, 0 REFUTED, 1 DRIFTED**), plus **ES-23, ES-25, ES-53, ES-26, ES-28, ES-60, ES-29, ES-31, ES-69, ES-71**.

The gate also **independently re-confirmed** one OK-BY-DESIGN entry from register §3: the **POS Treasury bridges** not emitting `PaymentRecorded` is correct by design — they project hash-chained fiscal events into payments/movements under fiscal source identity (`TreasuryReceiptBridge.php:46-67`, `:1331-1359`, `:1400-1438`; `TreasuryDepositBridge.php:176-209`, `:245-266`). **Do not "fix" it.**

## 3. An OK-BY-DESIGN *rationale* that was REFUTED (the row itself stands)

Register §3 lists *"`PosCoreReceiptProjection` calling loyalty directly instead of emitting `ReceiptCompleted` (**would double-earn**)"*.

**The design is fine. The stated reason is false.** Re-verified: both earn paths use **identical source identity** (`sourceType: 'pos_receipt'`, `sourceId` = the receipt id) — `PosCoreReceiptProjection.php:1487-1515` and `EarnPointsOnReceiptCompleted.php:97-112`. `EarningProcessingService::earnPoints()` performs a **global `findBySourceDocument()` pre-check and throws `"Points already earned for …"`** (`:53-70`), which both callers catch and swallow by message (`SaleEarningService.php:67-85`; `EarnPointsOnReceiptCompleted.php:113-121`). Behind that sits a **partial unique index** `loyalty_txn_earn_source_unique ON loyalty_transactions (enrollment_id, source_type, source_id) WHERE transaction_type = 'earn' AND source_type IS NOT NULL` (`2026_07_06_100000_add_source_columns_to_loyalty_transactions.php:84-93`).

**Read the entry as:** *projector-calls-loyalty-directly is OK-BY-DESIGN, **guarded by source-identity dedup + a unique index***. Consequence for the program: **double-earn does not exclude emitting a receipt lifecycle event from the projector.** The ES-01 emission-shape question (owner sheet **D-1**, handover **§8 Q7**) is therefore about **compliance-event semantics, payload and transaction boundary** — not about protecting loyalty. Corrected in both artefacts.

## 4. Rows that stay PARTIALLY CONFIRMED pending their own scoped sweeps

The gate could not close these on sampling; their SUSPECTED status in register §2.2/§2.3/§2.4 is **retained deliberately** and must not be upgraded without the sweep:

| Row | What is confirmed | What is still owed |
|---|---|---|
| **ES-34** (Billing) | Billing invoice create/cancel are silent (`InvoiceService.php:55-109`, `:145-205`, `:253-272`) | the broader **subscription / payment** claim — needs its scoped sweep (gated on owner question D-9: is Billing live for tenant #1?) |
| **ES-76** (14 modules) | Uom has silent create/update/deactivate paths (`UomController.php:121-142`, `:178-194`, `:247-249`) | the other **13 modules** — a real **mutation sweep**, not a directory count (handover Lane G entry criterion) |
| **ES-88** (Loyalty/Voucher dead events) | the three sampled events exist and are emitted (`PointsExpired.php:9-45`; `VoucherLookupRateLimiter.php:120-141`; `VoucherLookupService.php:215-238`, `:295-308`) and are **absent from the listener map** (`EventServiceProvider.php:68-160`) | the V1-predecessor half of the row |

## 5. What did NOT change

Register aggregate counts reconcile and are unchanged: **88 rows · P0 10 / P1 24 / P2 42 / P3 12 · 49 LAUNCH / 39 POST-LAUNCH**. The gate's artefact verdict on the register was **FIT-WITH-CORRECTIONS** — i.e. the corrections above, and nothing else.
