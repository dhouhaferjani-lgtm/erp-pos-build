# POS Cash Rounding + Tender Tolerance — Spec Review Round 1 (vs Rev 1, commit 4383df560)

**Date:** 2026-07-27
**Lanes:** treasury-reviewer (Opus) — REJECT; fiscal-pos-reviewer (Opus) — REJECT; Codex (gpt session 019fa398-212b-7373-9308-f8e853ca69b8, 16m) — REJECT.
**Outcome:** Rev 2 required. Both lanes verified ~85-95% of Rev 1's citations as accurate and confirmed the change-netting defect is REAL and the GL arithmetic correct — the rejects are about design shape, not the fact base.

## Consolidated blocker list (drives Rev 2)

- **T1 (treasury BLOCKER):** change-netting throws `IdempotencyConflictException` on replay of already-projected over-tender receipts (`TreasuryMovementService.php:74-76,559-575`); pre-cutover replays would also double-post new entries. → Rev 2: gate netting + new GL entries on **event_version 3** (payload-derived cutover, replay-deterministic); v1/v2 replay byte-identical; historical drift ticketed with quantification report.
- **T2 (treasury BLOCKER):** zero-netted legs violate pgsql-only `CHECK (amount > 0)` on `repository_movements` (migration 2026_07_08_100100:53-57); invisible on SQLite. → suppress zero legs entirely (no Payment/GL/movement), document ordinal holes, PG-run regression tests.
- **T3 (treasury BLOCKER):** tolerance GL entry activates on server deploy alone (PIN under-tender receipts exist today) → brownfield missing 6580 ⇒ whole-receipt projection outage. → v3-gating (T1) + `hasAccountForPurpose` precheck degrade-with-durable-alert + self-guarding artisan purpose-backfill command IN SCOPE (model: `BackfillPayableInstrumentAccountsCommand`) as server-release gate; config flip decoupled from auto-deployed migration.
- **T4 (treasury BLOCKER) / F-M6:** canonical `tolerance_writeoff` writes break Z-report hash parity — device hardcodes `tolerance_summary` zero-shape (`zReportService.ts:323-337` TODO names this feature); server builds it live. → device Z tolerance + rounding aggregation IN SCOPE (prerequisite, not optional).
- **F-B1 (fiscal BLOCKER, most severe):** unbounded signed `cash_rounding_adjustment` turns the aggregate identity into a free parameter — revenue suppression signs clean (e.g. total=5.000, adj=-95.000 on a 100 TND sale, auto-booked to 6580). → static validator magnitude cap per scale + projection-time policy reconciliation (`|adj| ≤ D/2` and recompute check) with post-and-flag stance + explicit rejection test.
- **F-B2 (fiscal BLOCKER):** `pos_receipts` has no `shift_id`; projection has no shift lock pattern; `pos_shifts` aggregates unimplementable as written. → drop shift aggregates; derive per-shift rounding via time-window query (tolerance precedent).
- **F-B3 (fiscal BLOCKER) / T-10:** top-level key-set version branching touches `validatePayloadKeySet` (version-blind, public) + 4 call sites (`StrictCanonicalParser:226`, `ParseFailureResolutionService:312` — quarantine-repair path would REJECT corrected v3 payloads, `TerminalRegistrySnapshotService:312`, `VirtualAdminFiscalEventService:353`) + `BestEffortPayloadParser:79,96,153` reads the const directly. → signature `int $eventVersion = 1`, `payloadKeysFor()` accessor, named `SALE_RECEIPT_PAYLOAD_KEYS_V3` const, all six touchpoints enumerated.
- **F-B4 (fiscal BLOCKER):** pre-sign assert does NOT prove display-vs-payload consistency; `estimateCartTotal` vs `receiptService` compute exact_total at different intermediate scales — 1 ulp across a tie boundary flips the payable by a full coin. → single-source exact_total + displayed-rounded-total threaded into `createOfflineReceipt` and bccomp-asserted before `engine.append()`; convert `total: number` props to decimal strings (M10).
- **F-B5 (fiscal BLOCKER) / T-9:** `gross ≠ net + vat` breaks in device Z (`zReportService.ts:768-786`), server Z/X (`ReportGenerationService.php:953-971`), NF525 `GrandtotalService.php:139`. → per-aggregator design: gross = rounded payable + explicit `cash_rounding_summary` line so `gross = net + vat + rounding` holds; report_data schema bump; `ZReportHashService` normalization; drop the "no Z_REPORT change" non-goal.

## Major findings folded into Rev 2

- **T5/F-M5:** worked example fails seeded TN tolerance (0.5% × 9.950 = 0.0498 < 0.050) — sub-10-TND single-coin shortfalls stay PIN-gated; → tolerance floor tied to denomination proposed, escalated to §8 owner decision; example fixed.
- **T6/F-M15:** `getToleranceSettings` defaults **enabled** (0.5%/0.50 currency-blind) — resolver must fail CLOSED (explicit country row required); chain named (country-row-only in v1).
- **T7:** `MEAL_VOUCHER` seeded `is_physical=true, has_maturity=false` — matches the cash predicate. → new `payment_methods.is_cash_tender` boolean (seeded/backfilled for CASH codes), single rule shared device+server; voucherTenders union caveat (F-edge-3).
- **T8:** partial unique index on legacy-populated `pos_payment_tolerance` fails `tenants:migrate` on tenants with existing duplicates → bridge-authored entries use NEW source_types; indexes cover new types only; legacy/bridge disjointness asserted (fiscal_event_id null vs set).
- **T11:** TN backfill UPDATE-only silently no-ops when no TN row → upsert.
- **F-M7:** V1 builder is immutable by convention (`SaleReceiptV2Payload.ts:6-8`) → new `SaleReceiptV3Payload.ts` delegating to V2.
- **F-M8:** `FiscalPayloadKeyDrift.test.ts` regex/hardcoded-28 cannot survive branching → named const + rewritten gate (29 keys).
- **F-M9:** device `FiscalEventPayloadRegistry.ts:162-174` hardcodes SALE_RECEIPT → 2; missing this = 100% quarantine. PHP authoring bump is inert (no server path authors SALE_RECEIPT).
- **F-M11:** signed regex broken at scale 0; canonical zero is scale-dependent → `signedMoneyRegex($scale)` two-branch; zero via `bcformatStrict('0', $scale)`.
- **F-M12:** server Z expected-cash path is RETIRED for fiscal_schema_version ≥ 3 terminals (`assertServerReportAuthoringAllowed`) — change_due projection justified by `ReceiptPdfService.php:134-135` instead.
- **F-M13/T17:** loyalty earn base becomes rounded total (`PosCoreReceiptProjection.php:893`) → decision: earn on `total − adj` (rounding-neutral), pinned by test.
- **F-M14:** Treasury `payments.amount` (netted) vs `pos_receipt_payments.amount` (tendered) two-semantics rule documented; `ReceiptVoidService.php:246-268` audited + void-of-rounded-receipt test.
- **F-M16:** live refund flow pays out untenderable exact amounts for rounded sales day 1 → escalated to §8 owner decision with minimal v1 remedy.
- **T12-20/F-m17-21 minors:** citation corrections (decimal.ts:14; FiscalEventEngine.ts:1032/1397; Canonical/LineItemDTO; 7580 at :277-278; PaymentToleranceQueryService method lines; Shift.php:162-171 + hardcoded scale-3 warning), JournalCode explicit OD decision, `'string'` cast for denomination, fraud-settings auth description corrected (no terminal auth; sanctum+company-context), third `'CASH'` string match at `ReportGenerationService.php:513` noted as dependency, `CashTenderedModal.tsx` dead-code note.

## Codex lane — additional findings (beyond the Opus overlap)

- **C1 (High):** training receipts are NOT excluded from GL today — `TreasuryReceiptBridge` never checks `training_flag` (only loyalty guards it, `PosCoreReceiptProjection.php:878-880`). Rev 1's "excluded exactly as today" claim retracted; new entries training-guarded; wholesale fix ticketed as discovered defect.
- **C2 (Critical, extends F-B1):** signed adjustment must be verifiable against the policy that authored it, including stale-offline events → Rev 2 signs `cash_rounding_denomination` INSIDE the payload (validator binds `|adj| ≤ D/2`, `total ≡ 0 mod D`, static denom cap) + projection-time policy reconciliation flag. Full policy-revision pinning deferred (ticket).
- **C5 (High):** device can sign card-only over-tender; change-netting assumed "change only from cash" → new device invariant `change ≤ Σ cash legs` (reject pre-sign) + server alert path.
- **C6/C15 (High):** no historical correction path; projection retries ~21 min then dead-letter → v3-gating + forward-only stance + quantification-report ticket; two-phase deploy.
- **C10 (High):** no projection barrier before Z/shift close → signed device Z summary made authoritative; server never mutates closed-shift figures; no server shift aggregates.
- **C11/C12 (High):** local receipt mirror (`offline_receipts`), device print paths, server PDF template, NF525 export all consume `total` with no adjustment field → all brought into scope (§4.3/§4.5 Rev 2).
- **C13 (Medium):** consumer-semantics matrix added (drawer/Z/NF525 = rounded; revenue/loyalty = exact; VAT untouched; analytics rounded-in-v1 documented). Verified NOT total-dependent: voucher redemption, gift cards, QR token, FEC exporter.
- **C17 (Medium):** `docs/factory/WORKFLOW.md:217-220` contradicts the owner-confirmed push-to-dev auto-migrate contract → design made safe in both orders; doc reconciliation ticketed.

---

# Round 2 (vs Rev 2, commit 4f44d63d0)

**Lanes:** fiscal-pos (Opus) — REJECT-narrow ("Rev 2.1 spec patch, not a redesign"); treasury (Opus) — REJECT changes-requested. Both lanes verified ALL r1 blockers genuinely closed (T1/T2/T5/T7/T8/T10, B1/B2/B3, M6-M14, C1/C7) and the v3-gate architecture correct end-to-end.

## r2 criticals (all folded into Rev 2.1)

- **T-F1 (CRITICAL):** live pgsql `pos_receipts_totals CHECK (total = subtotal + tax_amount − discount_amount)` (2026_03_09_200000:41-42) — every rounded sale dead-letters; invisible on SQLite. → CHECK swapped to `+ COALESCE(cash_rounding_adjustment,0)` in migration B, PG-run test.
- **F1 (CRITICAL):** signing `cash_rounding_summary` into the Z payload needs Z_REPORT v2 (fixed 32-key `ZReportPayload::PAYLOAD_KEYS`); as written 100% Z quarantine. → tolerance_summary real values only (key already signed); rounding summary in `report_data` ONLY (unsigned, additive, NO schema_version bump — bump would break `refunds_amount` hash parity, T-F6); Z_REPORT v2 ticketed.
- **F2 (CRITICAL):** denomination `"0.0500"` (decimal 15,4) vs `moneyRegex(3)` ⇒ 100% TND quarantine. → normalization contract: resolver emits at company currency scale; round-trip validity else disabled.
- **F3 (CRITICAL):** V3-delegates-to-V2 cannot yield rounded total (V1 aggregate assert throws first). → normative build order: V2 with exactTotal → replace `total` → add fields → assertV3.
- **T-F3:** `is_cash_tender` never reaches the device (`formatMethod` hardcoded allowlist) — silent feature no-op. → wire-through + store/update + TS type explicit.
- **T-F4 + F11:** original `country_payment_settings` seed insert latently broken (no `id`, countries seeded post-migration) ⇒ essentially NO tenant has rows; UPDATE-only backfill no-ops; launch tenant would get everything disabled. → uuid-id upsert + `CountryPaymentSettingsSeeder` in provisioning + upserting `--verify` ops command.
- **T-F2/F8:** projection writes not v3-gated while GL was ⇒ read-model/ledger divergence + non-inert Phase 1. → one v3 gate, both layers.

## r2 importants (folded)

F4 unify `computeExactCartTotal` (EUR tie-flip); F5 bind order (bcmod-by-zero = uncaught `Error`); F6 half-bound at s+1 + scale-0 cap 10; F7 GrandtotalService real rationale (retired-path unreachability) + 2 pin tests; F9 device const-swap not version-threading + `payloadKeysFor` takes no chain context; F10 quick-cash method SELECTION predicate; T-F5 cash predicate unification via `is_cash_tender ⇒ code='CASH'` invariant; T-F7 disjointness = source_type literal (journal_entries has NO fiscal_event_id) + `source_id = receipt->id` + procurement-exemplar unscoped index; T-F8 refund remedy not implementable as one line (server-computed totals, CHECK, no-arg getScale, unbounded partial drift) → §8.2 honest options; T-F9 alert = audit_events precedent (TreasuryReceiptBridge:829-869) with named event types; T-F10 purpose backfill promote-existing + parent hard-fail; T-F12 commands tenant-DB-scoped via tenants:run; T-F13 company `payment_tolerance_enabled === false` force-disable honored; T-F14 per-shift auto-accept escalation guard; minors (F12-F14, T-F11/15/16/17/18) all folded incl. `ReceiptVoidService` no-GL pre-existing note and v3 key sortedness assertion.

**Rev 2.1 outcome:** all r2 criticals/importants addressed in the same spec file. r3 = targeted verification of the critical fixes.

---

# Round 3 (targeted verification vs Rev 2.1, commit dd2992088)

**Lanes:** fiscal-pos (Opus) — spec ✅, APPROVE-WITH-FIXES (F1/F2/F3/F5/F6/F9/F10 verified resolved); treasury (Opus) — spec ✅ design sound, CHANGES-REQUESTED (F-1/F-2 verified closed, F-3/F-4 partially). All remaining findings = spec-text edits, no redesign. **All folded into Rev 2.2.**

## r3 findings → Rev 2.2 resolutions

- **Fiscal CRITICAL:** the r2 F7 foreclosure ("device build stamps `fiscal_schema_version >= 3`") was unwritable — the column is server-assigned (cutover service) and SALE_RECEIPT authoring isn't gated on it; a schema-2 terminal on the new build would poison `GrandtotalService` `net_sales`. → §4.1 gains the device-side rounding gate `terminal.fiscal_schema_version === 3` (fail-closed, in the snapshot builder); pin tests replaced; Phase-2 checklist requires full terminal cutover before `--enable`.
- **Treasury CRITICAL (T-F3 residual):** `is_cash_tender` wire-through stopped at the server; 4 device sites (types/payment.ts, PaymentMethodRow, rowToMethod, upsertPaymentMethods lockstep) unlisted — as specified the POS could not take cash at all. → enumerated in §4.2.
- **Treasury CRITICAL (T-F4 residual):** seeder was hooked into the demo `DatabaseSeeder`, not the real provisioning path. → `TenantInitializationService::seedReferenceData()` + `ProductionSeeder`.
- Fiscal importants: `cash_rounding_summary` derived server-side in `ZReportProjection::legacyReportData()` (projection REBUILDS report_data; legacy sync 409-retired for cutover terminals — device report_data never reaches the server there); dead ingress-regex claim dropped; device TS hash mirror (`apps/pos/src/lib/fiscal/zReportHashService.ts:86-95`) named; denomination string-fidelity pinned at every hop (DTO string / TS string / SQLite TEXT / signed-value regex assert); policy reconciliation via `bccomp` + no-CompanyContext note; `--verify` cash-method assertion moved to Phase 1.
- Treasury importants: pgsql-guard on the CHECK swap (unguarded breaks every SQLite suite); TN row values pinned + Phase-1 B2B-tolerance-tightening (0.50→0.100) made owner-visible; **new `pos_tolerance_enabled` column** decouples the POS kill-switch from B2B; cash invariant exact `code = 'CASH'` (case-sensitive — device Z match is case-sensitive); minors (inline v3-gate restatement in netting, audit aggregate keys per precedent, decimal(12,3), citation fixes).
- r3-verified clean: CHECK swap semantics incl. load-bearing COALESCE; single v3 gate both layers; source_type disjointness + unscoped index exemplar; audit-events mechanism; tenants:run scoping; V3 build order vs actual builders; bind order/`s+1` half-bound; Z value-level validation absent (real tolerance values trip nothing); `bcformatStrict` truncate-only. No new quarantine/money-loss-class defects found in either lane.

**Round-3 outcome: Rev 2.2 is review-stable.** Remaining gate = owner §8 decisions.

## Full lane verdicts

The complete lane reviews (all findings, code evidence, and fix prescriptions) are preserved in the session transcript of 2026-07-27 and materially reproduced above; Rev 2 (same spec file, header updated) addresses every BLOCKER and MAJOR. Re-review of Rev 2 by both lanes is required before the owner gate.
