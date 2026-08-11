# FINDINGS — Shift variance, cash drawer & GL (2026-08-11)

**Purpose:** one consolidated, dependency-ordered handover for the **fixes session**. Everything here is already investigated — **do not re-investigate**. Every claim carries (a) the source document it came from and (b) the `file:line` that document cited.

**Source documents (the only inputs; all read-only research):**

| Tag | Document |
|---|---|
| **R14** | `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/14-research-pos-module-first-tenant-reporting.md` |
| **R15** | `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/15-research-pos-count-semantics-industry.md` |
| **R16** | `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/16-takings-formula-archaeology.md` |
| **E1** | `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/01-pos-fiscal.md` |
| **E2** | `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/02-treasury-gl.md` |
| **OD** | `docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md` |

**Sibling dossiers (this session, same fixes owner):** `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/00-CONSOLIDATED-REGISTER.md` + `docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md` (events-wide) and `docs/handoff/FINDINGS-other-problems-2026-08-11.md` (everything else). Packaging ruled at **OD:71**.

---

## 1. Binding rulings

These are the owner's rulings of record. They are **decided**; the fixes session implements them, it does not re-open them.

1. **Whole-drawer counting — CONFIRMED.**
   > "**A-9 CONFIRMED: WHOLE-DRAWER counting.** Takings-only formula is dead code born of a missing float join (see `16-takings-formula-archaeology.md`); bury it and correct the config/docblock comments that describe it as policy. Blind counting stays RULED ON everywhere." — **OD:70**

   Operationally (R15 §D, `15-…:203`): *"The cashier counts everything physically in the drawer. Variance = counted total − (opening float + net cash movements). Takings are derived by subtraction and displayed, never counted."* This is a **ratification** of what the device already computes, not a change of direction (R15 §D.1, `15-…:212`).

2. **Blind counting — ON everywhere.**
   > "**blind counting: RULED — ON everywhere** (not just first tenant)" — **OD:64**

   Reaffirmed at **OD:70** ("Blind counting stays RULED ON everywhere"). Note: *everywhere* = both verticals, overriding the current automotive-only vertical default (R16 §2.3, `CompanyFraudSettings.php:201`).

3. **Cash-count events must be emitted on v3.**
   > "**RULED: cash-count events must be emitted on v3.**" — **OD:64**

   The same bullet records the owner's lean that the blocker set be owned as one lane so a single owner holds *all* v3 fixes.

4. **Takings-only artefacts must be corrected, not merely left in place** — "bury it and correct the config/docblock comments that describe it as policy" (**OD:70**).

5. **Remediation packaging — three findings dossiers, folded into the EXISTING fixes session** (not a new dedicated session) — **OD:71**. This file is dossier (2).

**Not ruled (still owner-owed).** The fixes session must NOT invent answers to these; they are listed as decision gates in §3: float modelling shape (R15 E-2, `15-…:282`), whether DEPOSIT/PAYOUT post to the GL and how they are typed (R15 E-3, `15-…:283`), negative-till behaviour on an OUT variance (R15 E-4, `15-…:284`), **the `SAFE_DROP`-vs-`CASH_OUT` authorability decision (SV-16 — added at entry gate round 1; it was stated as required-before-acceptance in SV-16 but was missing from every decision list)**, Toast-style two-stage deposit reconciliation as a follow-on lane (R15 E-5, `15-…:285`), TND variance thresholds and alert severity (R15 E-8, `15-…:288`).

---

## 2. Complete problem inventory

Deduplicated across all five research documents. Severity: **P0** = blocks the GL flag or produces wrong money numbers today · **P1** = correctness/audit hole, not flag-blocking · **P2** = ergonomics/hygiene.

### 2.1 Register

| ID | Problem | Severity | Source |
|---|---|---|---|
| **SV-1** | Takings-only formula is dead code that became the stated pre-enable gate | P2 (doc debt, high misdirection cost) | R15 §A.2/§A.7, R16 Part 1 |
| **SV-2** | v3 shift close emits no `CashCountRecorded` → no fraud alert, no GL entry | **P0** | E1 GC-1, R15 §A.8, R16 §2.2 |
| **SV-3** | Opening float, DEPOSIT (safe drop), PAYOUT are unbooked in Treasury/GL | **P0** | E2 F-1/F-2, R15 §A.4, R16 §2.2 |
| **SV-4** | `recordOpening` / `recordClosing` / `recordSale` dispatch **no event at all** | **P0** (blocks SV-3's fix) | E2 F-2, E1 G-1, R16 §2.2 |
| **SV-5** | v3 CASH_IN/CASH_OUT write no `pos_cash_drawer_operations` row; device Z omits them | **P0** | E1 GC-2 |
| **SV-6** | `PostShiftCashVarianceAdjustment` gated off by default; nothing reaches the GL | **P0** (the flag itself) | E2 F-11, R15 §D.2 |
| **SV-7** | `ShiftManagementService` legacy branch writes opening-float-only `expected_cash` on v3 shifts; NF525 inherits it | **P0** | R15 §A.7/§D.3-7 |
| **SV-8** | `pos_shifts` has three competing writers; `closeShift()` has no version guard | P1 | E1 P-1 |
| **SV-9** | Blind count is default-OFF and vertical-conditional; "ON everywhere" is a 6-touch-point change + data migration | P1 | R16 §2.3 |
| **SV-10** | Blind-mode leak audit not done (threshold/severity surfaces may reveal magnitude pre-commit) | P1 | R15 §E-6 |
| **SV-11** | Count screen never tells the cashier **what** to count | P1 (highest value / lowest risk) | R15 §A.6/§D.4 |
| **SV-12** | Variance source is not disambiguated — rounding vs shrinkage indistinguishable | P1 | R14 §A-8/§1.4, R15 §E-9 |
| **SV-13** | `CashDrawerController::deposit`/`payout` carry no v3 gate — a competing server rail | P1 | E1 G-2 |
| **SV-14** | Legacy close fabricates `actual_cash = expected_cash` → zero variance in NF525 | P1 | R15 §E-7 |
| **SV-15** | v3 persists no `pos_z_report_counts` rows — no per-tender/denomination drill-down | P1 | E1 GC-6 |
| **SV-16** | `SAFE_DROP` / `CASH_CORRECTION` have projector arms but **no device caller** | P1 | E1 G-11/D-4 |
| **SV-17** | G-2/G-3/G-4 policy gates: company setting, backfill command, `default_repository_id` seeding | P1 (flag-blocking) | R15 §D.3-4/5/6, R16 §2.3 |
| **SV-18** | `insufficient_repository_balance` refusal will fire on genuine shortfalls while the float is unbooked | P1 | R15 §A.4-3 |

---

### SV-1 — Bury the takings-only formula and correct the artefacts that cite it as policy

**What's broken.** `ReportGenerationService::buildExpectedPerMethod()` computes expected cash as *takings only* — `Σ pos_receipt_payments.amount − Σ change_due` per method, with **no `pos_shifts.opening_cash` and no `pos_cash_drawer_operations` term at all** (R16 §1.1, `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:514-583`; terms enumerated at `:528`, `:531-556`, `:576-581`). Twenty lines up in the *same method*, `ReportGenerationService.php:219` sets the report's own `expected_cash` from the **whole-drawer** `CashDrawerService::calculateExpectedCash()` (`apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php:387-413`). Two formulas in one function, disagreeing by exactly the float, with nothing reconciling them (R16 §1.1).

**It has no production caller.** The branch runs only when `cash_counts` is non-null (`ReportGenerationService.php:233-241`), and `cash_counts` is `nullable` in the request (`GenerateZReportRequest.php:61-68`). Both clients send `terminal_id` only — web `apps/web/src/features/pos/api/shiftApi.ts:68-70`, device `apps/pos/src/api/reportApi.ts:246` (itself `@deprecated`) (R15 §A.2; R16 §1.4-1). It is **doubly** unreachable since the v3 chokepoint: `generateZReport()` throws `ServerFiscalAuthoringRetiredException` for `fiscal_schema_version >= 3` (`ReportGenerationService.php:84-89`, guarded by `ServerReportAuthoringUnreachabilityTest` / `ZReportServerAuthoringChokepointTest`) (R16 §1.4-2). Its only exercisers are PHPUnit tests (R16 §1.4-1).

**Why it matters.** On 2026-08-08 a reviewer read this dead function, correctly observed it omits the float, and wrote the "whole-drawer would post the float to 658/758 on every close" premise into **three artefacts** — commits `e81c465d1` and `dbe381829` (R16 §1.5):

| Artefact | Cited at |
|---|---|
| `apps/api/config/treasury.php:19-25` | R15 §A.7, R16 §1.5 |
| `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:44-52` (docblock) | R15 §A.7 |
| `docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md` §G-1 (`:53-58`) | R15 §A.7, R16 §1.5 |

**The gate is real; its stated reason is not.** The float-pollution risk is genuine but originates in **SV-3** (the float is unbooked in Treasury), not in this formula (R16 §1.5, `16-…:101`).

**Fix (R16 §1.6 recommended disposition, ratified by OD:70).** (a) Delete `buildExpectedPerMethod()` with its branch, **or** annotate `@deprecated` at `ReportGenerationService.php:492-513` with "*this is takings-only and has no client; production is whole-drawer via the device*". (b) Rewrite the three artefacts above to state the real gate (float + drawer ops unbooked). Note the 2026-05-12 plan already proposed deletion (`docs/superpowers/plans/2026-05-12-pos-go-live-plan-v1.md:52`) and was overruled on the wrong grounds by v2 (`…-plan-v2.md:7`) — it was fixed instead of deleted, which is how it survived (R16 §1.4-3). **This is the fourth reader to reach it; the annotation exists to stop a fifth.**

**Historical note worth preserving in the code comment** (R16 §1.3): the omission traces to a single parenthesis in `apps/api/tests/Feature/POS/CashCountToleranceVarianceRegressionTest.php:79-81` — *"opening cash is shift-level, not per-tender"* — an accurate statement about the **schema** and a false one about the **business meaning**. The defect is a missing join between a shift-level term and a per-method table, not a wrong doctrine (R16 §1.4).

---

### SV-2 — v3 emits no `CashCountRecorded` (RULED: must be emitted)

**What's broken.** On every `fiscal_schema_version >= 3` terminal, shift close raises **no** `CashCountRecorded` — so **no fraud alert and no GL variance journal entry** (E1 **GC-1**, CONFIRMED).

- `ZSessionLifecycleProjection::projectPosShiftClose()` writes `pos_shifts.actual_cash` + `variance` + `variance_severity` + `manager_override_by` straight from the `SESSION_CLOSE` payload and calls `$shift->save()` — **no `event(...)` anywhere in the file**: `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:197-277` (esp. `:251-276`).
- `ZReportProjection::apply()` likewise writes `report_data.variance` from `payload['cash_count']` with no event: `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:54-101`, `:131-165` (R15 §A.8 cites `:158-161`).
- Both legacy emitters are hard-409'd for v3: `ReportGenerationService.php:415` behind `:84-89`; `ZReportSyncController.php:548` (via `dispatchCashCountRecorded()` `:507`, called `:269`) behind `:75-85`. Sibling shift-close paths same at `SyncController.php:53-63` and `ShiftController.php:124-134` (E1 GC-1; R16 §2.2).
- Two orphaned consumers: `apps/api/app/Modules/Compliance/Listeners/OpenFraudAlertForShiftVariance.php:22-24` (wired at `ComplianceServiceProvider.php:88`) and `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:152` (wired at `TreasuryServiceProvider.php:184`) (E1 GC-1, R16 §2.2).

**The data is already present and parsed** — only the emission is missing: `ZSessionLifecycleProjection.php:230-249` reads `expected_cash` / `counted_cash` / `variance_severity` / `manager_approval`; `ZReportProjection.php:139-160` reads `cash_count.expected_cash` / `counted_cash` / `variance_amount` (E1 GC-1).

**Consequence stated bluntly** (R15 §A.8, `15-…:107`): *"If every terminal at the first tenant is cut over to `fiscal_schema_version >= 3`, flipping `shift_variance_gl_enabled` does literally nothing."*

**Fix shape already written** — `docs/superpowers/tickets/2026-08-08-g3-v3-terminals-no-cashcount-producer.md:25-40` (includes a rule-8-compliant `CashCountRecordedV2` fallback), sequencing constraint at `:46-48` (R16 §2.1). The E1 fix pattern (E1 §5, `01-…:219`): *emit the corresponding POS domain event from inside each projector's `DB::transaction`, after the projection write, **inside** the existing idempotency guard* (`ZSessionEvent::where('fiscal_event_id')->exists()`, `ZReport::where('fiscal_event_id')->exists()`) — never before it, or Horizon redelivery double-emits.

**⚠️ Producer-choice constraint — ONE authoritative producer must be selected** *(added at entry gate round 1).* This dossier says "emit from the v3 **projectors**" (plural) and then verifies only the `SESSION_CLOSE` path. But **a real v3 close authors BOTH events in one operation**: `appendZSessionCloseAndZReport()` appends `SESSION_CLOSE` and then `Z_REPORT` back-to-back (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:664-711`, verified — the `Z_REPORT` append at `:698-711` carries `reference_event_id: sessionCloseEvent.id`). Both events carry the cash-count data, and each lands in a **different** projector with a **different** `fiscal_event_id`:

- `ZSessionLifecycleProjection` reads `expected_cash` / `counted_cash` / `variance_severity` (`:230-249`), guarded by `ZSessionEvent::where('fiscal_event_id')->exists()`;
- `ZReportProjection` reads `cash_count.expected_cash` / `counted_cash` / `variance_amount` (`:139-160`), guarded by `ZReport::where('fiscal_event_id')->exists()`.

**Per-event idempotency therefore cannot prevent a double emission** — each projector is idempotent *for its own* event while both emit one `CashCountRecorded` for the same logical count, giving two fraud alerts and (once the flag is on) **two `repository_adjustments` documents and two 6580/7580 journal entries** for one shift. `journal_entries(source_type, source_id)` has no uniqueness constraint (§4.4), so the DB will not catch it.

**Required:** pick **one** authoritative cash-count producer — `SESSION_CLOSE` (`ZSessionLifecycleProjection`) or `Z_REPORT` (`ZReportProjection`) — state the choice in the fix, and make the other projector explicitly **not** emit (with a comment naming this constraint). The fallback if either event can arrive alone is a **cross-event dedup keyed on `pos_shifts.id`** (an `exists()` guard on the shift's already-emitted count), not a per-`fiscal_event_id` guard. See §5.2 acceptance outcome 4.

**Ownership check (do not redo).** R16 Part 2 proved the document-per-action session does **not** own this: **NOT-PLANNED**, ticket exists with a fix shape but no owner (R16 §2.1). See §4.1.

---

### SV-3 — Float / DEPOSIT / PAYOUT are unbooked (the B1 blocker)

**What's broken.** Physical cash moves and neither the cash repository balance nor the GL knows (E2 **F-1**, GAP-CRITICAL, CONFIRMED — *"this is the owner's seed, verified"*).

- Emitter: `CashDrawerService::dispatchOperationEvent()` at `apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php:465-482`, called from `recordDeposit` (`:252`, dispatch `:268`) and `recordPayout` (`:289`, dispatch `:305`) (E2 F-1; R16 §2.2 cites the same sites as `:272`, `:309`, `:365` and the event class `apps/api/app/Modules/POS/Domain/Events/CashDrawerOperationRecorded.php:15`, name `'cash_drawer.operation_recorded'` `:32`).
- **Sole consumer:** `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:1138` → `handleCashDrawerOperationRecorded` (`:805-823`) → one `audit_events` row. **Nothing else** — no Treasury or Accounting listener exists anywhere (E2 F-1; R15 §A.4; R16 §2.2).
- **And the audit consumer is not a guarantee:** `persistEvent()` (`DomainEventSubscriber.php:1020-1062`) **swallows every throwable** except under impersonation (E2 §1, "Audit-consumer definition").
- No `TreasuryMovementService::record()` call, no `RepositoryMovement` row, no `JournalEntry`. The only other readers of `CashDrawerOperation` are read-only: `Nf525DataProvider.php:255` (export) and `CashDrawerController.php:67`/`:136` (idempotency lookup) (E2 F-1).
- Treasury has **no float / imprest / change-fund concept at all** — `grep -i "float|imprest|fonds de caisse"` over `app/Modules/Treasury/` returns only type comments and one `is_float()` guard (R15 §A.4).
- The cash-register `PaymentRepository` balance **is not the physical drawer** — it accumulates cash-sale legs via `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:167`, and knows nothing of the float or of drawer deposits/payouts (R15 §A.4-3).

**Consequence** (E2 F-1): *"the shift reconciles while the ledger silently diverges by the full drawer-op volume"* — the textbook document-per-action violation: a cash mutation with **neither** a justifying document **nor** a booking event.

**Under whole-drawer this becomes load-bearing** (R15 §D.1-iv, §D.3-1): expected includes the float while the repository balance does not, so till book-balance and physical contents differ by the float on **every** shift; and with the float unbooked it would land in 6580/7580 on the first close.

**Account-level contamination risk** (R16 §2.2): `PostShiftCashVarianceAdjustment` routes through `RepositoryAdjustmentService.php:80-81` → `SystemAccountPurpose::PaymentToleranceExpense` / `…Income` → **6580 / 7580** (`database/seeders/TunisiaChartOfAccountsSeeder.php:278,322`; `FranceChartOfAccountsSeeder.php:335,392`; `GenericChartOfAccountsSeeder.php:182,212`) — *the same accounts the per-receipt cash-rounding/tolerance lane writes to at high volume*. An unbooked float would not merely add noise, it would **contaminate the account pair the tolerance lane reconciles against**.

**The fix machinery already exists** (R16 §2.2): Treasury Phase ③ shipped `RepositoryTransferService` + `createRepositoryTransferJournalEntry` (direct Dr destination / Cr source, no 58 transit). *"B1 is a wiring job, not a modelling one."* Lightspeed X-Series maps exactly this as a **Cash float** GL account in its Xero integration (R15 §B.1, §D.3-1).

**Owner decisions still owed before implementation:** R15 **E-2** (`15-…:282`) float as Safe→Till transfer vs dedicated imprest entity; R15 **E-3** (`15-…:283`) whether DEPOSIT/PAYOUT post to the GL and how typed (Toast/Lightspeed type these precisely *because* the typing drives bank-vs-petty-cash accounting).

---

### SV-4 — `recordOpening` / `recordClosing` / `recordSale` dispatch nothing

**What's broken.** `dispatchOperationEvent` is called only from `recordDeposit` (`:268`), `recordPayout` (`:305`) and `recordRefund` (`:366`). `recordOpening` (`CashDrawerService.php:201-213`), `recordClosing` (`:225-237`) and `recordSale` (`:324-341`) create the `pos_cash_drawer_operations` row and return — **no event, not even audit** (E2 **F-2**, GAP-CRITICAL; E1 **G-1** cites `:206-214`, `:230-238`, `:330-338` vs `:272`, `:309`, `:365`).

**The docblock is wrong.** `CashDrawerService.php:460-464` claims OPENING/CLOSING/SALE are *"covered by their own domain events"*. True for SALE (fiscal `SALE_RECEIPT`) and CLOSING; **false for OPENING** (R16 §2.2). And `ShiftOpened`/`ShiftClosed` carry shift state, not the drawer-operation row — and the opening float itself is never booked into a cash repository (E2 F-2).

**Why it is worse than SV-3** (E2 F-2, verbatim): *"**no event of any kind**, so there is no hook a fix session could attach a booking listener to."* The float half of B1 needs a **producer as well as a consumer** (R16 §2.2).

**Additional v3 aggravation** (E1 G-1): on v2 the gap is masked by `ShiftOpened`/`ShiftClosed`/`ReceiptCompleted` covering the same moments — but all three are themselves dead on v3 (E1 GC-3, GC-4), so *the masking no longer holds*.

---

### SV-5 — v3 drawer movements never reach `pos_cash_drawer_operations` (server **and** device)

**What's broken** (E1 **GC-2**, CONFIRMED on both sides).

- **Server:** `ZSessionLifecycleProjection` handles `CASH_IN` / `CASH_OUT` / `SAFE_DROP` / `OPENING_FLOAT` / `CASH_CORRECTION` by writing **only** a `z_session_events` row (`ZSessionLifecycleProjection.php:88-120`, esp. `:93-109`); it never touches `pos_cash_drawer_operations`. So `CashDrawerService::calculateExpectedCash()` — which sums that table (`CashDrawerService.php:387-395`) — is **blind to every v3 drawer movement**, and the Tier-2 audit row is absent. The codebase half-acknowledges this in a comment at `ReportGenerationService.php:498-507` and in ticket `docs/superpowers/tickets/2026-07-31-cashdrawer-v3-expected-cash-blind.md`.
- **Device (mirror defect):** `apps/pos/src/api/cashDrawerApi.ts:37-38`, `:62-63` early-return on `isCutoverTerminal()` so `offline_cash_drawer_ops` is never written, while `apps/pos/src/lib/offline/zReportService.ts:223`, `:261-266`, `:280` still derives `expected_cash` exclusively from that table. **The signed v3 Z-report's `expected_cash` omits every `CASH_IN`/`CASH_OUT`.**

**Why this is P0 for this lane.** The number `PostShiftCashVarianceAdjustment` would book comes from the device's whole-drawer figure via the sync path (R15 §A.3: `ZReportSyncController.php:288-312` archives the device's expected/actual/variance verbatim; `pos_shifts.variance` derived at `:253`, `:276`). If that figure omits mid-shift cash-in/out on v3, **the GL entry is wrong by the drawer-movement volume** — booking a fabricated variance rather than a real one. SV-5 must be closed before SV-2's emission can be trusted.

---

### SV-6 — The only booking consumer is disabled by default

`TreasuryServiceProvider.php:183` registers `PostShiftCashVarianceAdjustment` on `POS\Domain\Events\CashCountRecorded`, but the handler short-circuits on `config('treasury.shift_variance_gl_enabled') !== true` (`apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:156`), and `apps/api/config/treasury.php:29` defaults it to `false` via `TREASURY_SHIFT_VARIANCE_GL_ENABLED` (E2 **F-11**).

E2 F-11's own framing: *"Combined with F-1/F-2 this means **no POS cash discrepancy of any kind currently reaches the GL**. … the fix session for F-1/F-2 must decide this flag's fate in the same breath (it is the sibling of the drawer-op gap, not an independent one)."*

Compliance framing (R15 §C.3): today `pos_shifts.variance` is stamped and a `fraud_alerts` row is opened, **and nothing is booked** — *"a recorded discrepancy with no ledger counterpart… The accountant will find it at month-end reconciliation."*

---

### SV-7 — Legacy branch stamps opening-float-only `expected_cash` on v3 shifts

`ShiftManagementService.php:168-182` persists `expected_cash` from `calculateExpectedCash()` on any close that did **not** go through the cash-count path. Because no v3 path writes `pos_cash_drawer_operations` (SV-5), this yields **opening-float-only** expected on v3 shifts (R15 §A.7 row 4; ticket `docs/superpowers/tickets/2026-07-31-cashdrawer-v3-expected-cash-blind.md`).

On that path `pos_shifts.variance` is nonsense — **and `EspecesAttendues` in the NF525 export inherits it** (R15 §D.3-7, §C.2: `apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php:417`).

---

### SV-8 — `pos_shifts` has three competing authorities; one variance definition is required

`pos_shifts` is simultaneously (a) a projection of `SESSION_OPEN`/`SESSION_CLOSE` (`ZSessionLifecycleProjection.php:183`, `:276`), (b) written directly by `ZReportSyncController::applyShiftFields()` from an HTTP payload (`ZReportSyncController.php:420-479`, esp. `:477`), (c) written by `ReportGenerationService::updateShiftWithCashCount()` (`:706`), and (d) written by `ShiftManagementService::closeShift()` (`:139-185`). The v2 writers are 409-gated at the **controller** level, not the service level — `closeShift()` itself has **no `fiscal_schema_version` check**, so any future caller reaches it unguarded (E1 **P-1**).

**Hard sequencing constraint already written down** (R16 §2.3): `docs/superpowers/tickets/2026-08-08-g3-legacy-closeshift-no-gl-leg.md:51-53` — *"do not wire this branch and the v3 branch independently: `pos_shifts.variance` must end up with ONE definition across all three close paths."*

---

### SV-9 — Blind counting ON everywhere: 6 touch points + a data migration

`require_blind_cash_count` is a **DB column + code default**; there is **no `config/` entry** (R16 §2.3). Current default: **`false`** (R15 §A.5, `apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php:58`, `:179`).

| Layer | Location (R16 §2.3) |
|---|---|
| Column | `database/migrations/tenant/2026_04_25_000004_add_cash_variance_settings_to_company_fraud_settings.php:19` — `boolean(...)->default(false)` |
| One-off seed for existing companies | `database/migrations/tenant/2026_04_25_100001_seed_company_fraud_settings_for_existing_companies.php:40` |
| Model default / `getDefaults()` | `CompanyFraudSettings.php:58`, `:179`; fillable `:120`, cast `:144` |
| **Vertical override** | `CompanyFraudSettings.php:201` — `$defaults['require_blind_cash_count'] = $isAutomotive;` (docblock `:190-193`: Otospex ON, IziPOS OFF) |
| Resolver (no-row fallback) | `apps/api/app/Modules/POS/Application/Services/FraudSettingsResolver.php:30`, persisted row `:45` |
| Admin API | `FraudSettingsController.php:35`, `:120`; DTO `CompanyFraudSettingsData.php:44,75,105` |
| Web toggle | `apps/web/src/features/compliance/components/CashDrawerControlsSection.tsx:13,52,68,76,90,99,146,291,297`; page state `FraudSettingsPage.tsx:55`, submit `:396`; i18n `locales/{en,fr}/compliance.json:109` |
| Device | SQLite cache `apps/pos/src/lib/db/migrations.ts:518` (`DEFAULT 0`); `companyFraudSettingsCacheRepository.ts`; behaviour `CashReconciliationSection.tsx:84,87,229,260`; reveal gate `organisms/CashCountTable.tsx:64-65`; leak defence `EndOfDayPreviewModal.tsx:242-244` |

**The six touch points** (R16 §2.3): (1) `CompanyFraudSettings.php:58` + `:179` defaults; (2) `:201` `defaultsForVertical()` becomes dead or inverted — **and `tests/Unit/Compliance/CompanyFraudSettingsVerticalDefaultsTest.php:53` (asserts `false` for non-automotive) goes red by design**; (3) a **data migration** for rows already persisted `false` (the 2026-04-25 seed ran once); (4) optionally the column default at `…000004:19`; (5) the FE `false` initial/fallback at `FraudSettingsPage.tsx:55`, `:396`, **which would silently re-disable on a form round-trip for a row-less company**; (6) the device cache `DEFAULT 0` governs pre-first-sync behaviour only.

⚠️ **Do not conflate with inventory blind counting** (R16 §2.3): the only prior blind ruling in memory (`project_live_counting_completion_lane.md:40`, B5) concerns `inventory_countings` on the mobile app — different setting, directionally consistent.

---

### SV-10 — Blind-mode leak audit (not done)

The obvious leak is defended: `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:239-262` deliberately suppresses the legacy expected-cash card when the cash-count section is active, with an explicit SECURITY comment (*"would DEFEAT the blind cash count"*) (R15 §A.5, §E-6).

**Not audited:** Toast additionally suppresses its over/short **threshold warning** under blind mode *"as it contains cash balance information"* (R15 §B.3-1). AutoERP shows severity/reason prompts derived from the variance — `CashReconciliationSection.tsx:137`, `:165-180` (per-tender variance + signed-sum severity) — and **none of them have been checked for magnitude disclosure pre-commit** (R15 §E-6, `15-…:286`).

---

### SV-11 — Count-screen copy (the exact strings)

**What's broken** (R15 §A.6): the screen says Expected / Actual / Variance (FR: *Attendu / Compté / Écart*, `apps/pos/src/locales/{en,fr}/pos.json` `cash_count.*`) and **nothing on the screen tells the cashier *what* to count.** Whole-drawer is implied only by juxtaposition with `pos.header.shiftOpening` and the non-blind EOD card (`EndOfDayPreviewModal.tsx:252-260`).

**Fix — four additions, priority order (R15 §D.4).**

1. **A one-line instruction above the count, always visible, blind or not.** R15 calls this *"the single highest-value change in this document"*:
   - **EN:** *"Count **all** the cash in the drawer, including the opening float of {{amount}}."*
   - **FR (the exact line):** *"Comptez **tout** l'argent présent dans le tiroir, **y compris le fonds de caisse** de {{amount}}."*
2. **Float-disclosure line on the expected figure**, near-verbatim from Lightspeed X-Series: *"Expected includes the opening float."* / *"Le montant attendu inclut le fonds de caisse."* Under blind mode this renders **after** Commit Counts, alongside the reveal.
3. **Name the zero case and decompose the reveal.** Replace a bare `0.000` with **"No difference"** / *"Aucun écart"* (Toast's wording — the only vendor that names it). Post-commit summary uses the Lightspeed X-Series split so takings stay legible without the cashier subtracting:

   | line | EN | FR |
   |---|---|---|
   | 1 | Opening float | Fonds de caisse |
   | 2 | Cash sales (net of change) | Ventes en espèces (net rendu monnaie) |
   | 3 | Paid in / paid out | Entrées / sorties d'espèces |
   | 4 | **Expected in drawer** | **Attendu en caisse** |
   | 5 | **Counted** | **Compté** |
   | 6 | **Over / Short / No difference** | **Excédent / Manquant / Aucun écart** |

   Every input to line 4 already exists on the device (`apps/pos/src/lib/offline/endOfDayPreview.ts` computes each term) — **this is presentation only**.
4. **Wording:** keep **"Écart"** over "Différence"; the register should match a figure that triggers a mandatory written reason (R15 §D.4-4, §B.4).

Line 2 of that table is where SV-12's rounding decomposition belongs (R15 §D.4-3).

---

### SV-12 — Variance source: rounding vs shrinkage

**What's broken** (R14 §A-8, `14-…:265`): *"Nothing tells the operator whether cash rounding, not shrinkage, moved the variance"* — `pos_receipts.cash_rounding_adjustment` exists per receipt (cited via `12-research…:108`) **but is aggregated nowhere the operator can see**. Marked **SUSPECTED — needs a new aggregate**; the open question is restated at R14 §7-6 (`14-…:314`).

**Why it is briefing-critical** (R14 §1.4, `14-…:61-63`): the phase-2 checklist is explicit that the moment a terminal is on the rounding build — *cut over or not, rounding enabled or not* — **expected-cash and shift-variance figures move, and Treasury's `payments.amount` changes from tendered to retained on over-tendered cash sales** (`docs/handoff/cash-rounding-phase2-deploy-checklist.md:240`). Neither `/pos/shift-history` nor the owner reconciliation table (`apps/web/src/features/owner-dashboard/components/CashRegisterReconciliationTable.tsx:19-23`, `CashRegisterReportService.php:49-56`) shows the rounding component (R14 §2, `14-…:82`).

**Where it lands** (R15 §E-9): under whole-drawer the reveal has room for it — line 2 of the SV-11 table. R14 §6(b) B-3 argues the **shift-detail page** (not the receipts list) is the right home for cash-variance context, and lists four endpoints with zero web consumers that would serve it (`GET /pos/shifts/{id}`, `/{id}/receipts`, `/{id}/tolerance-receipts`, `/pos/cash-drawer/{shiftId}/operations`).

---

### SV-13 — `CashDrawerController::deposit`/`payout` have no v3 gate

Unlike `ShiftController`, `SyncController`, `ZReportSyncController` and `ReportGenerationService`, `CashDrawerController::deposit`/`payout` carry **no `fiscal_schema_version` check** (`apps/api/app/Modules/POS/Presentation/Controllers/CashDrawerController.php:40-102`, `:109-171`; routes `POS/routes.php:98-99`) — a web/admin caller can create a server-authored drawer row against a device-authoritative shift (E1 **G-2**).

Partially mitigated: `CashDrawerService::assertCashDrawerApproval()` demands a matching device-authored `OPERATOR_APPROVAL_GRANTED` fiscal event with a hashed target (`CashDrawerService.php:83-171`), so the write is chain-anchored (E1 G-2, O-3) — **but the resulting row is invisible to the device's own expected-cash arithmetic and competes with the `CASH_IN`/`CASH_OUT` rail.** Any booking listener added in SV-3 must not double-book across the two rails.

---

### SV-14 — Legacy close fabricates a zero variance

`terminalStore.closeShift` sends `actual_cash = preview.expected_cash` (variance 0 by construction — `apps/pos/src/components/Header.tsx:402-403`) while the real counted figure travels separately in the Z payload. On a legacy terminal whose Z sync lands late or never, `pos_shifts` carries a **fabricated zero variance**, which feeds `EspecesReelles` / `Ecart` in the NF525 export (R15 §E-7, §C.2). **v3 is unaffected** (REST close retired; `ZSessionLifecycleProjection` stamps the real pair). Action per R15: confirm scope, then ticket.

---

### SV-15 — v3 persists no `pos_z_report_counts` rows

Both legacy paths called `ZReportCountRepository::createMany()` (`ReportGenerationService.php:356`, `ZReportSyncController.php:238-241`); `ZReportProjection` does not reference the repository at all (`ZReportProjection.php:107-165`; repo `apps/api/app/Modules/POS/Infrastructure/Repositories/ZReportCountRepository.php:30`). The per-denomination / per-tender breakdown survives only as unindexed JSON inside `report_data.cash_count` / `canonical_z_report`, so **every variance drill-down and `CashCountResource` sees nothing for v3 shifts** (E1 **GC-6**).

Direct interaction with SV-12: a per-tender variance decomposition cannot be built from an empty table.

---

### SV-16 — `SAFE_DROP` and `CASH_CORRECTION` are unauthorable

Declared `PROJECTED` in `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventCoveragePolicy.php:56-57` with live projector arms (`ZSessionLifecycleProjection.php:32-33`), but the device has **no caller** for either — only the type union (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:91-92`) and the engine allow-set (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:241-242`) mention them (E1 **G-11**, **D-4**). *"Safe-drop, a standard NF525 cash-control operation, is unimplementable end-to-end."*

**Load-bearing for the §5 verification contract**, which requires a synthetic shift containing a safe drop. Today safe drops travel as `CASH_OUT` (device `apps/pos/src/api/cashDrawerApi.ts:177` → `zSessionAuthoring.ts:645`, E1 §2.2) — which is itself unprojected into `pos_cash_drawer_operations` (SV-5).

**This is a DECISION GATE, not just a fix** *(promoted at entry gate round 1 — it was stated here as required-before-acceptance but appeared in neither §1's owner-owed list nor §3 Stage 0).* It is now **§1 "Not ruled"** and **§3 Stage 0.5**, and is owner sheet item **D-17**. It blocks **§5.2 step 3 / acceptance outcome 3** (there is no authorable safe-drop event until it is answered) and **Stage 3 step 10** (the DEPOSIT/PAYOUT typing decision, D-3/E-3, must cover whichever event type wins).

---

### SV-17 — The remaining policy gates (G-2 / G-3 / G-4)

Recorded in `docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md`, restated at R15 §D.3-4/5/6 and R16 §2.3:

| Gate | Requirement | Evidence |
|---|---|---|
| **G-2** | Move the semantics decision out of `config/treasury.php` into a **company setting**, keeping the config flag as the global emergency off-switch. Whole-drawer is the *default*, not a universal truth (Lightspeed K-Series ships the analogous per-drawer three-state policy). | R15 §D.3-4; ticket `:60-68`; `progress.md:116` lists it as a pre-enable gate |
| **G-3** | Ship the **backfill command** for the disabled window, or get explicit sign-off that the window is written off with a recorded start date. **No such command exists** — `grep shift_variance_gl_enabled app/ config/ tests/` returns only the config entry, the provider comment and tests. | R15 §D.3-5; `docs/superpowers/tickets/2026-08-08-g3-kill-switch-window-no-backfill.md`; R16 §2.3 |
| **G-4** | Seed `default_repository_id` on **every** physical payment method. | R15 §D.3-6 |

**Sequencing constraint** (R16 §2.3, from `…-g3-v3-terminals-no-cashcount-producer.md:46-48`): **SV-2 is explicitly sequenced behind G-3** — a backfill command that only understands the legacy shape would need extending again once the v3 producer lands.

---

### SV-18 — `insufficient_repository_balance` will refuse genuine shortfalls

`PostShiftCashVarianceAdjustment.php:162-175` refuses to book when the cash-register repository balance is insufficient. Because the repository balance is not the physical drawer (SV-3), this fires on genuine shortfalls against a small till (R15 §A.4-3, §D.3-1). R15 **E-4** (`15-…:284`) asks whether an OUT variance that would take a till negative should refuse (today) or record-and-alert — and notes this becomes **much less likely** once the float is booked, so **resolve E-2 first, then re-ask**.

---

## 3. Dependency-ordered fix sequence

> **Rule of the lane** (R16 §2.3): *"Wiring B2 with the flag on before B1 books the float posts the float to 6580/7580 on every v3 close — the exact failure the kill switch exists to prevent."*

### Stage 0 — Answer / rule before writing code

| Step | Item | Owner | Why it gates |
|---|---|---|---|
| 0.1 | **Does any tenant / the first tenant run a `fiscal_schema_version < 3` terminal?** (R15 **E-1**, `15-…:281`) — *"a query, not a ruling"* | engineering | *"This can reorder the whole lane."* If the fleet is all-v3, the flag posts nothing until SV-2 lands, and every legacy-path fix (SV-14) is dead weight |
| 0.2 | **E-2** — float as Safe→Till transfer document vs dedicated imprest entity (R15 `:282`) | owner + treasury design | Determines SV-3's document shape |
| 0.3 | **E-3** — do DEPOSIT/PAYOUT post to the GL, and how are they typed (bank vs petty cash)? (R15 `:283`) | owner | Determines SV-3's second half |
| 0.4 | **E-4** — negative-till OUT variance: refuse or record-and-alert? (R15 `:284`) — **re-ask after 0.2** | owner | SV-18 |
| **0.5** | **SV-16 — `SAFE_DROP` vs `CASH_OUT`.** Do we implement a device `SAFE_DROP` caller (and project the live `SAFE_DROP` arm), or formally rule that safe drops travel as `CASH_OUT` and retire the unreachable projector arm? *(Promoted to a decision gate at entry gate round 1: SV-16 already states it is required before acceptance, but it appeared in no decision list.)* | owner + fiscal design | **Blocks §5.2 acceptance** — step 3 of the end-to-end test cannot be authored until the event type is chosen — and blocks Stage 3's reconciliation (a safe drop is the DEPOSIT/PAYOUT rail's canonical case, so D-3/E-3's typing decision has to cover whichever type wins) |
| 0.6 | **E-8** — TND thresholds (soft `1.0000` / hard `20.0000`, `CompanyFraudSettings.php:54-57`) and `cash_variance_email_severity` (`'none'`) (R15 `:288`, §D.3-10) | owner | Alert volume changes on deploy regardless of the flag |

### Stage 1 — Ships independently, in parallel, blocks nothing

No dependency on the GL flag; do not let them wait for it (R16 §2.3: the copy half is *"the highest-value, lowest-risk item in the whole register and blocks nothing"*).

1. **SV-11 — count-screen copy** (en/fr/ar in parallel per the standing direction, OD:76). Presentation only; all inputs already exist on the device.
2. **SV-9 + SV-10 — blind count ON everywhere + leak audit.** Six touch points + data migration; `CompanyFraudSettingsVerticalDefaultsTest.php:53` goes red **by design** and is rewritten. Reviewer profile differs from the GL work: `frontend-conventions-reviewer` + `tenancy-authz-reviewer` (R16 §2.3).
3. **SV-1 — takings-formula burial + artefact correction.** Pure deletion/annotation + doc edits. Do this **early** so nobody in the lane re-derives the dead premise mid-flight.

### Stage 2 — Make the v3 numbers true (prerequisite to any posting)

4. **SV-5 — v3 drawer movements must materialise.** Server: project `CASH_IN`/`CASH_OUT`/`SAFE_DROP`/`OPENING_FLOAT` into `pos_cash_drawer_operations` (or give `calculateExpectedCash` a v3-aware source). Device: reconcile the `cashDrawerApi.ts:37-38`/`:62-63` early-return with the `zReportService.ts:223,261-266,280` derivation so the **signed** Z's `expected_cash` includes cash-in/out.
5. **SV-7 — kill the opening-float-only `expected_cash`** at `ShiftManagementService.php:168-182`, honouring SV-8's *one definition across all three close paths* constraint (`…-g3-legacy-closeshift-no-gl-leg.md:51-53`).
6. **SV-16** — **execute** the Stage-0.5 decision gate: implement a device `SAFE_DROP` caller, or formally document that safe drops travel as `CASH_OUT` and retire the unreachable projector arm. Required before the §5 end-to-end test can be honest (its step 3 has no authorable event otherwise).

### Stage 3 — B1: book the money (the hard prerequisite)

7. **SV-4 first** — give `recordOpening` (and `recordClosing`) a producer. Without it there is *"no hook a fix session could attach a booking listener to"* (E2 F-2). Rule 8 applies: new event, never a rename.
8. **G-4 (SV-17)** — seed `default_repository_id` on every physical payment method; the booking listener resolves through it.
9. **SV-3 — book the float** in the Stage-0.2 shape (Safe → Cash-register transfer via the existing `RepositoryTransferService` + `createRepositoryTransferJournalEntry`, R16 §2.2).
10. **SV-3 — book DEPOSIT / PAYOUT** per Stage-0.3. Reconcile with **SV-13** so the ungated server rail and the device `CASH_IN`/`CASH_OUT` rail cannot double-book.

### Stage 4 — B2: emit on v3

11. **G-3 (SV-17) — backfill command** for the disabled window. **SV-2 is explicitly sequenced behind it** (`…-g3-v3-terminals-no-cashcount-producer.md:46-48`).
12. **SV-2 — emit `CashCountRecorded` on the v3 close**, inside the transaction, inside the existing `fiscal_event_id` idempotency guard (E1 §5). Fix shape at `…-g3-v3-terminals-no-cashcount-producer.md:25-40`, incl. the rule-8 `CashCountRecordedV2` fallback.
    **First, select the single authoritative producer** (`SESSION_CLOSE` **or** `Z_REPORT`) per the producer-choice constraint in §2/SV-2: a real v3 close authors **both** events (`zSessionAuthoring.ts:664-711`), so emitting from "the v3 projectors" plural double-books the variance. The non-chosen projector must carry an explicit non-emission comment, and the acceptance test asserts **exactly one** `CashCountRecorded` for a two-event close (§5.2 outcome 4).
13. **SV-15** — persist `pos_z_report_counts` on v3 (or an equivalent queryable projection) so variance drill-downs exist for the shifts that will now post.

### Stage 5 — Policy relocation, then the flag

14. **G-2 (SV-17)** — relocate the semantics decision to a company setting; keep `config/treasury.php` as the global emergency off-switch.
15. **SV-12** — surface the rounding component (Stage-1 copy already reserves line 2 for it; SV-15 supplies the data).
16. **SV-6 — flip `treasury.shift_variance_gl_enabled`.**

### The flip condition, stated explicitly

> **`treasury.shift_variance_gl_enabled` may ONLY be flipped to `true` after ALL of the following are shipped and verified:**
>
> 1. **SV-3 + SV-4** — the opening float **and** DEPOSIT/PAYOUT are booked into Treasury (movement + GL). *Without this the float lands in 6580/7580 on the first close and contaminates the account pair the cash-rounding lane reconciles against* (R15 §D.1-iv, §D.3-1; R16 §2.2).
> 2. **SV-5** — v3 drawer movements are represented, so the variance being booked is not fabricated by an incomplete expected basis (E1 GC-2).
> 3. **SV-2** — v3 terminals emit `CashCountRecorded`. *On an all-v3 fleet the flag is otherwise **inert*** (R15 §A.8; R16 §2.2). **Unless Stage 0.1 proves the fleet is not all-v3, this is absolute.**
> 4. **SV-7 + SV-8** — one variance definition across all three close paths; no opening-float-only `expected_cash`.
> 5. **G-2, G-3, G-4** (SV-17) — company setting in place, backfill command shipped (or the disabled window written off with a recorded start date), `default_repository_id` seeded on every physical payment method.
> 6. **Stage 0.4 (E-4)** answered — otherwise `insufficient_repository_balance` behaviour on a genuine shortfall is undefined policy (SV-18).
>
> **Stage-1 items (SV-1, SV-9, SV-10, SV-11) are NOT preconditions for the flip and must not be used to delay it — nor may the flip be used to delay them.** They ship first precisely because they are independent.

---

## 4. Interactions

### 4.1 The document-per-action session does **not** own this work — verified

R16 Part 2 checked this on the owner's instruction (**OD:64**) and the answer is unambiguous:

- **Wave-3 excludes G3 enablement by name:** `.superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/plan-wave3.md:3646`, §5 *Out of scope (explicit)*, item 5 — *"**5. G3 enablement.** Separate pre-enable gate; the owner's whole-drawer ruling redefined its expected basis."*
- **Wave-3's 42 tasks (sub-waves 3A–3G at `plan-wave3.md:2417,2545,2632,3054,3155,3423,3483`) are all inventory / COGS / valuation / invoice-delivery / supplier-return / GR-IR.** Negative search over the whole 4,362-line plan: `CashDrawerOperationRecorded` 0, `CashCountRecorded` 0, `shift_variance_gl_enabled` 0, `require_blind_cash_count` 0, `6580` 0, `7580` 0, `cash drawer` 0, `opening float` 0, `DEPOSIT` 0, `PAYOUT` 0, `blind count` 0 — the single `G3` hit is the exclusion (R16 §2).
- **The DPA session already scoped B1 and stopped:** `progress.md:151` — *"(1) A1 G3: **WHOLE-DRAWER counting confirmed** … pre-enable work now **DEFINED**: expected basis must include opening float + deposits − payouts (currently receipt payments only) + company-setting relocation."*; `progress.md:116` — *"Task G3: complete … lane ships DISABLED …; **pre-enable gates: owner count-semantics ruling + company-setting relocation + backfill command + v3-terminal wiring**."* Nothing in the S2–S4 dispatch stream (`progress.md:224-241`) touches cash drawers.
- **Per-blocker verdict (R16 §2.1): B1 NOT-PLANNED** (scoped in prose, zero task ID) · **B2 NOT-PLANNED** (ticket with a fix shape, no owner) · **B3 NOT-PLANNED** (owner ruling, no ticket file at all).

**Implication:** treat this dossier as the lane's only backlog. Do not wait on Wave-3, and do not assume any of it is already in flight.

### 4.2 Cash-rounding tolerance lane

**No double count, and whole-drawer does not change that** (R15 §D.2, `15-…:223`): every 658/758 tolerance writer books Dr 658 / Cr ProductRevenue and touches **no cash account**, while both expected-cash bases are **tendered**-based — so a written-off shortfall is already netted out of "expected" and an honest count balances (`PostShiftCashVarianceAdjustment.php:66-90`). The belt-and-braces guard `hasUnattributableToleranceForShift` stays as a fail-safe for legacy shapes. Under whole-drawer the tendered basis is unchanged; **only the float term is added, and the float is tolerance-free by construction.**

**But the accounts are shared** (R16 §2.2): the variance adjustment routes to the *same* 6580/7580 pair the per-receipt rounding/tolerance lane writes to at high volume. That is exactly why SV-3 is a hard precondition — an unbooked float would contaminate the pair the tolerance lane reconciles against.

**Operator-visibility interaction** (R14 §1.4): once a terminal is on the rounding build — *cut over or not, rounding enabled or not* — expected-cash and shift-variance figures move, and `payments.amount` changes from tendered to retained on over-tendered cash sales (`cash-rounding-phase2-deploy-checklist.md:240`). SV-12 exists so the first post-rollout EOD does not read as an unexplained regression.

**Refund-side residual** (R14 E1-3): the refund-rounding delta (`D/2` = 0.025 TND at TND `D=0.050`) must be explicable on screen — *"the cashier has nothing on the avoir to point at"* (`cash-rounding-phase2-deploy-checklist.md:218-221`). That item belongs to the refund-enable block (E1), not to this lane, but shares SV-11's surface.

### 4.3 Fiscal / NF525 — already on a whole-drawer basis

The export is **structurally** whole-drawer already (R15 §C.2): `apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php:398-421` writes `SoldeOuverture` ← `pos_shifts.opening_cash` (`:406`), `EspecesAttendues` ← `expected_cash` (`:417`), `EspecesReelles` ← `actual_cash` (`:418`), `Ecart` ← `variance` (`:419`), mapped via `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:1330-1343`.

*"Under takings-only semantics `EspecesReelles` would have to mean 'cash present minus the float', which is not what the element says and not what an inspector or an expert-comptable would read it as."* On v3 these three columns are stamped truthfully from the device payload (`ZSessionLifecycleProjection.php:230-266`), with `variance` recomputed at scale 4 to satisfy the `pos_shifts_variance_calc` CHECK.

**Consequence:** the whole-drawer ruling requires **zero** change to the export — but SV-7 and SV-14 both feed garbage into it today and are therefore fiscal-export correctness fixes, not just internal hygiene.

Nothing in `.claude/context/compliance.md` binds the counting choice (R15 §C.1) and no Tunisian fiscal mandate applies (R15 §C.4) — the binding constraint is the tenant's chart of accounts: TN `6580 Écart de règlement (charges)` / `7580 Écart de règlement (produits)` (`database/seeders/TunisiaChartOfAccountsSeeder.php:278, 322`).

### 4.4 GL posting shape (unchanged by the ruling — do not redesign)

Per R15 §D.2:

- **Account:** `SystemAccountPurpose::PaymentToleranceExpense` / `PaymentToleranceIncome` (`apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:48-49`, resolved at `GeneralLedgerService.php:1201-1240`) → TN 6580 / 7580. **Keep the existing reuse; do not mint a new purpose.**
- **Direction:** unchanged from `PostShiftCashVarianceAdjustment.php:305-309` — OVER (counted > expected) → `MovementDirection::In`, Dr cash / Cr 7580; SHORT → `Out`, Dr 6580 / Cr cash. ⚠️ *Do not "align" to Toast's sign convention — Toast's own doc contradicts itself* (R15 §B.5).
- **Granularity:** **per shift**, one **derived-UUID `repository_adjustments` document per `pos_shifts.id`** — as already built. Industry prescribes no cadence (R15 §B.5), but per-shift is the only granularity at which the variance is attributable to a named cashier, *"which is the whole point of the account (a detective control for cash-handling proficiency)"*.
- **Idempotence warning** (E2 F-20): `journal_entries(source_type, source_id)` carries **no uniqueness constraint** (`AccountingService.php:700-706`) — any event-driven posting path must not assume the DB will dedupe.

---

## 5. Verification contract

### 5.1 Per fix — event emitted · consumer ran · GL/projection state

| Fix | Event assertion | Consumer assertion | GL / projection assertion |
|---|---|---|---|
| **SV-2** (v3 `CashCountRecorded`) | Ingest the **real two-event close** — `SESSION_CLOSE` **and** `Z_REPORT`, as `zSessionAuthoring.ts:664-711` authors them — through the real projector paths and assert **`assertDispatchedTimes(CashCountRecorded::class, 1)`**: one logical count, one event, regardless of which projector owns it. **A single-event `SESSION_CLOSE`-only test does not satisfy this** — it cannot see the cross-event double-emission. **Plus** `assertDispatchedTimes(…, 1)` on Horizon redelivery of the same `fiscal_event_id` (E1 §5) — per-event idempotency **and** cross-event dedup are two separate assertions | `OpenFraudAlertForShiftVariance` opened a `fraud_alerts` row with the expected severity; `PostShiftCashVarianceAdjustment` ran (or refused with a named reason) | `pos_shifts.{expected_cash, actual_cash, variance}` match the device payload; `variance` satisfies the `pos_shifts_variance_calc` CHECK at scale 4 |
| **SV-4** (opening/closing producer) | new event dispatched from `recordOpening` / `recordClosing` (`CashDrawerService.php:201-213`, `:225-237`) | booking listener invoked | — |
| **SV-3** (float booking) | — | Treasury booking listener produced a `RepositoryMovement` | Safe→Cash-register transfer JE exists; **cash-register repository balance now includes the float**, so `insufficient_repository_balance` no longer fires on a small-till shortfall (SV-18) |
| **SV-3** (DEPOSIT/PAYOUT) | `CashDrawerOperationRecorded` dispatched (already true) | **a Treasury/Accounting consumer now exists** — assert it ran, and assert it is not the audit subscriber (whose `persistEvent()` swallows throwables, E2 §1) | one movement + one JE per drawer op; **no double-book** across the `CashDrawerController` rail and the device `CASH_IN`/`CASH_OUT` rail (SV-13) |
| **SV-5** (v3 drawer ops) | — | — | ingesting `CASH_IN`/`CASH_OUT` creates `pos_cash_drawer_operations` rows; `calculateExpectedCash()` reflects them; device `zReportService` `expected_cash` includes them |
| **SV-7 / SV-8** (one variance definition) | — | — | all three close paths produce **identical** `pos_shifts.variance` for the same inputs (the `…-g3-legacy-closeshift-no-gl-leg.md:51-53` constraint as an executable test) |
| **SV-9** (blind everywhere) | — | — | a fresh company in **both** verticals resolves `require_blind_cash_count = true`; a pre-existing row persisted `false` is migrated; a FE form round-trip on a row-less company does **not** re-disable it (`FraudSettingsPage.tsx:55`, `:396`) |
| **SV-10** (leak audit) | — | — | under blind mode, no surface — including severity/threshold prompts (`CashReconciliationSection.tsx:137`, `:165-180`) — renders expected or variance **magnitude** before Commit Counts |
| **SV-11** (copy) | — | — | rendered-HTML assertions (CLAUDE.md rule 17), en+fr+ar keys present, no hardcoded strings; the float amount interpolates |
| **SV-12** (rounding) | — | — | the shift reveal shows a rounding component that reconciles: `variance = rounding_component + unexplained_component` |
| **SV-15** (`pos_z_report_counts`) | — | — | a v3 Z produces per-tender count rows; `CashCountResource` returns them |
| **SV-1** (burial) | — | — | the annotation/deletion lands **and** `config/treasury.php:19-25`, `PostShiftCashVarianceAdjustment.php:44-52`, `…-g3-shift-variance-gl-deploy-notes.md` §G-1 no longer assert the takings-only premise |

**Two standing test-environment rules for this lane** (CLAUDE.md rule 20): queued jobs and fiscal projections run with **no `CompanyContext`** — pass an explicit currency to scale resolution, and projection tests must `app(CompanyContext::class)->clear()` before `apply()`; binding context in `setUp` masks the worker reality. Any new `onQueue('x')` needs a matching `apps/api/config/horizon.php` entry (`HorizonQueueCoverageTest`).

### 5.2 End-to-end acceptance test (the gate for flipping the flag)

**One synthetic v3 shift exercising every leg of the whole-drawer formula.**

Setup: TN company, TND (scale 3), one `fiscal_schema_version >= 3` terminal, `default_repository_id` seeded on the cash payment method (G-4), `require_blind_cash_count = true`, `shift_variance_gl_enabled = true`.

| # | Step | Fiscal event |
|---|---|---|
| 1 | Open the shift with an **opening float** | `SESSION_OPEN` + `OPENING_FLOAT` |
| 2 | Ring a **cash sale** with change given (exercises `change_due`) | `SALE_RECEIPT` |
| 3 | Perform a mid-shift **safe drop** | `SAFE_DROP` (or `CASH_OUT`, per the SV-16 decision) |
| 4 | Close with a **short count** (above soft, below hard threshold) | `SESSION_CLOSE` **+** `Z_REPORT` — **both**, exactly as `appendZSessionCloseAndZReport()` authors them (`zSessionAuthoring.ts:664-711`). Ingesting only one of the two invalidates outcome 4 |

**Required outcomes — all must hold:**

1. **Expected basis is whole-drawer:** `pos_shifts.expected_cash` = `opening_float + net cash sales − safe drop`. It is **not** takings-only (float present), **not** float-only (sales present), **not** drawer-blind (the safe drop is subtracted) — this single number simultaneously proves SV-3, SV-5 and SV-7.
2. **Float booked:** a Safe → Cash-register transfer movement + JE exists for step 1; the cash-register repository balance rose by the float.
3. **Safe drop booked:** a Treasury movement + JE exists for step 3; the repository balance fell by it. Exactly one booking (no double-book across rails — SV-13).
4. **`CashCountRecorded` emitted exactly ONCE for the whole close** — and step 4 must ingest the **real two-event close** (`SESSION_CLOSE` *and* `Z_REPORT`, both authored by `appendZSessionCloseAndZReport()`, `zSessionAuthoring.ts:664-711`), not `SESSION_CLOSE` alone. Assert **one** event across **both** projectors (cross-event dedup — the chosen authoritative producer emits, the other does not), **and** idempotence under Horizon redelivery of the same `fiscal_event_id` (per-event guard). Corollary assertions: exactly **one** `fraud_alerts` row and exactly **one** `repository_adjustments` document + one 6580/7580 journal entry for the shift.
5. **Fraud alert opened** by `OpenFraudAlertForShiftVariance` with the severity the thresholds dictate (not `isZero()`-short-circuited).
6. **The 6580 entry exists and is correct:** one `repository_adjustments` document with a **derived UUID from `pos_shifts.id`**; SHORT → `MovementDirection::Out`, **Dr 6580 `Écart de règlement (charges)` / Cr the cash repository account**, amount = `|counted − expected|` at currency scale; **no `insufficient_repository_balance` refusal** (because the float is now booked — SV-18).
7. **No double count with the rounding lane:** if the same shift also carries a rounding/tolerance write-off, total 6580 debits = variance + tolerance with **no overlap**; `hasUnattributableToleranceForShift` does not trip (R15 §D.2).
8. **NF525 export truthful:** `SoldeOuverture` / `EspecesAttendues` / `EspecesReelles` / `Ecart` for this shift match steps 1–4 exactly (`Nf525XmlBuilder.php:406,417,418,419`).
9. **Blind-mode integrity:** at step 4, before Commit Counts, no surface exposed expected or variance magnitude (SV-10).
10. **Mirror case — an OVER count** produces the symmetric entry: `MovementDirection::In`, Dr cash / Cr 7580.
11. **Zero-variance case** produces **no** journal entry and **no** fraud alert (the `isZero()` short-circuit is correct behaviour, not a bug).

**If any of 1–8 fails, the flag stays off.**

---

## Appendix — cross-reference index

| This dossier | R14 | R15 | R16 | E1 | E2 |
|---|---|---|---|---|---|
| SV-1 | — | §A.2, §A.7 | Part 1 (§1.1–§1.6) | — | — |
| SV-2 | A-9 | §A.8, §D.3-3 | §2.1 B2, §2.2 | **GC-1** | (F-11 sibling) |
| SV-3 | — | §A.4, §D.3-1/2 | §2.1 B1, §2.2 | — | **F-1** |
| SV-4 | — | §A.4 | §2.2 | G-1 | **F-2** |
| SV-5 | — | — | — | **GC-2** | — |
| SV-6 | A-9 | §D.2, §C.3 | — | — | **F-11** |
| SV-7 | — | §A.7, §D.3-7 | §2.3 | — | — |
| SV-8 | — | — | §2.3 | **P-1** | — |
| SV-9 | — | §A.5, §D.3-9 | §2.1 B3, §2.3 | — | — |
| SV-10 | — | §E-6 | §2.3 | — | — |
| SV-11 | — | §A.6, §D.4 | §2.3 | — | — |
| SV-12 | **§A-8**, §1.4, §6(b) B-3 | §E-9 | — | — | — |
| SV-13 | — | — | — | **G-2** | — |
| SV-14 | — | §E-7 | — | — | — |
| SV-15 | — | — | — | **GC-6** | — |
| SV-16 | — | — | — | **G-11 / D-4** | — |
| SV-17 | — | §D.3-4/5/6 | §2.3 | — | — |
| SV-18 | — | §A.4-3, §E-4 | — | — | — |

**Tickets referenced throughout (all pre-existing):** `docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md` · `…/2026-08-08-g3-v3-terminals-no-cashcount-producer.md` · `…/2026-08-08-g3-kill-switch-window-no-backfill.md` · `…/2026-08-08-g3-legacy-closeshift-no-gl-leg.md` · `…/2026-07-31-cashdrawer-v3-expected-cash-blind.md`
