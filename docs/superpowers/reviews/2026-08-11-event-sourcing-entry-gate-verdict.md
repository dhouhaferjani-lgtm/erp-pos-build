# Entry-gate verdict of record — event-sourcing remediation program (round 1)

**Gate:** entry gate, **round 1** · **Run:** 2026-08-11 by an independent Codex reviewer (adversarial, HIGH effort)
**Repository / branch:** `apps/erp` / `dev` · **HEAD reviewed:** `0c00cf526`
**Verdict:** **CHANGES-REQUIRED** (8 findings: 1 Critical, 5 High, 2 minor)

**Provenance note.** The reviewer's own file write was **blocked by its filesystem sandbox** (read-only, approval escalation disabled), so no file was produced by the run itself. The verdict text below was recovered **verbatim** from the Codex job record
(`~/.claude/plugins/data/codex-openai-codex/state/erp-f67121c996f348e8/jobs/task-msp0mhdn-tt1g0n.json`, `result.rawOutput`)
and persisted here by the orchestrator. Nothing below has been edited, reordered or summarised.

**Disposition:** all 8 findings were re-verified against code by the orchestrator and applied on 2026-08-11 — see
`docs/handoff/ES-REGISTER-CORRECTIONS-2026-08-11.md` (register addendum) and the corrected
`HANDOVER-event-sourcing-remediation-2026-08-11.md`, `FINDINGS-shift-variance-gl-2026-08-11.md`,
`FINDINGS-other-problems-2026-08-11.md`, `OWNER-QUESTIONS-es-remediation-2026-08-11.md`.

---

# Event-Sourcing Remediation Entry-Gate Verdict

**Artifacts:** four requested remediation artifacts plus backing sections 01–05  
**Repository / branch:** `/Users/houssamr/Projects/syneriva/apps/erp` / `dev`  
**HEAD reviewed:** `0c00cf526cb631e446e5a9bcbb936c7dec4f02a0`  
**Verdict:** **CHANGES REQUIRED before remediation dispatch**

The complete verdict could not be written to the requested path because the session’s filesystem sandbox is read-only and approval escalation is disabled. The write was rejected; the target file remains absent and no files were modified.

## P0 citation check

| ID | Status | Evidence |
|---|---|---|
| ES-07 | **DRIFTED** | Projected receipts are excluded and zero rows pass (`apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:293-318`). However, projected Z reports invoke a fiscal-event verifier (`VerifyPosChainCommand.php:372-398`) that walks and rehashes `z_session` chains (`apps/api/app/Modules/POS/Domain/Services/ZReportHashService.php:210-216`, `:251-293`). The claim that every fiscal-era row is excluded is false. |
| ES-06 | **CONFIRMED** | A single resolver writes supplied payload and marks the sealed row verified (`apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:120-179`); validation checks schema, not equality with immutable canonical bytes (`:291-350`). |
| ES-08 | **CONFIRMED** | The command is single-terminal/context (`apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:83-100`) and selects/checks only canonical bytes and chain hashes (`:363-433`). |
| ES-01 | **CONFIRMED** | The receipt projector writes receipt state and downstream projections without a receipt lifecycle event (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:218-240`, `:448-477`); NF525 TICKET creation depends on `ReceiptCreated` (`apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:660-684`). |
| ES-05 | **CONFIRMED** | The v3 session projector writes only session/shift projections (`apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:88-120`). Cutover device paths return after fiscal authoring (`apps/pos/src/api/cashDrawerApi.ts:28-40`, `:53-65`), while Z calculation still reads offline drawer operations (`apps/pos/src/lib/offline/zReportService.ts:218-224`, `:256-288`). |
| ES-02 | **CONFIRMED** | Session close saves the shift and Z projection saves the report without `CashCountRecorded` (`ZSessionLifecycleProjection.php:197-277`; `ZReportProjection.php:54-100`). Fraud handling exists (`apps/api/app/Modules/Compliance/Listeners/OpenFraudAlertForShiftVariance.php:22-69`) and Treasury defaults disabled (`apps/api/config/treasury.php:16-29`). |
| ES-10 | **CONFIRMED** | `AccountingService` creates entries already Posted and hand-rolls chain state (`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:386-409`), then emits only `JournalEntryCreated` (`:491-516`). The standard path emits `JournalEntryPosted` and applies advisory-lock/closed-period controls (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2874-2886`, `:3480-3558`). |
| ES-09 | **CONFIRMED** | Both server authors resolve the head using tenant+terminal only (`apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:443-464`; `apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:372-388`). The normal ingestor also scopes company/context (`apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:172-181`). |
| ES-03 | **CONFIRMED** | The session projector directly opens/closes shifts without lifecycle events (`ZSessionLifecycleProjection.php:88-120`, `:136-183`, `:197-277`); audit rows require `ShiftOpened`/`ShiftClosed` (`DomainEventSubscriber.php:732-775`). |
| ES-04 | **CONFIRMED** | The v3 Z projector writes only `ZReport` (`ZReportProjection.php:54-100`). Legacy authoring is blocked for v3 (`apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:84-89`), while the legacy transaction emits grand-total and Z-report events (`:341-379`). |

**P0 result:** 9 CONFIRMED, 0 REFUTED, 1 DRIFTED.

## Sampled P1/P2 rows

| Row | Result | Evidence |
|---|---|---|
| ES-23 / 02 | CONFIRMED | `system_purpose` is directly reassigned/removed (`ChartOfAccountsService.php:102-131`), with controllers simply invoking those methods (`AccountPurposeController.php:84-135`). |
| ES-25 / 02 | CONFIRMED | The three events are emitted (`AccountingOpeningService.php:345-364`; `AccountController.php:103-134`, `:147-191`) but absent from the application listener map (`apps/api/app/Providers/EventServiceProvider.php:68-160`). |
| ES-53 / 02 | CONFIRMED | Expense post, settle and reverse mutate durable state (`ExpenseService.php:309-341`, `:609-675`, `:818-853`) without service-level event dispatch. |
| ES-26 / 03 | CONFIRMED | Transfer discards `reference`/`userId` and writes only batch movements/stocks (`BatchStockService.php:258-296`, `:304-341`). |
| ES-28 / 03 | CONFIRMED | POS directly updates stock and inserts movements without `StockMovementRecorded` (`PosCoreReceiptProjection.php:1848-1887`, `:2189-2226`). |
| ES-60 / 03 | CONFIRMED | Transfer movement is rewritten after `issue()` (`StockTransferService.php:527-551`, `:701-707`) while the event was scheduled from the original snapshot/type (`StockAdjustmentService.php:156-198`). |
| ES-84 / 03 | CONFIRMED, wording drift | Four float casts exist, but two target `reserved_quantity` and two `reserved` (`StockReservationService.php:267-283`, `:391-407`). Precision-lane ownership is correct under `CLAUDE.md:71-76`. |
| ES-29 / 04 | CONFIRMED | Supplier invoice posting performs GR/IR then directly saves Posted status (`SupplierInvoicePostingService.php:276-293`). |
| ES-31 / 04 | CONFIRMED | DNs are loaded without locking, checked via mutable payload, then marked invoiced later (`DeliveryNoteToInvoiceConverter.php:251-332`). |
| ES-69 / 04 | CONFIRMED | Invoice, credit-note and quote confirmation directly update status (`InvoiceController.php:583-609`; `CreditNoteController.php:268-282`; `QuoteController.php:525-539`). |
| ES-71 / 04 | CONFIRMED | Per-line `GoodsReceived` is buffered (`GoodsReceiptService.php:581-601`, `:630-649`), but PO header Received status is a direct update (`:723-734`). |
| ES-34 / 05 | PARTIALLY CONFIRMED | Billing invoice creation/cancellation is silent (`InvoiceService.php:55-109`, `:145-205`, `:253-272`); the broader subscription/payment claim still needs its scoped sweep. |
| ES-76 / 05 | PARTIALLY CONFIRMED / DRIFTED | Uom has silent create/update/deactivate paths (`UomController.php:121-142`, `:178-194`, `:247-249`), but the register says “~13” while listing 14 modules (`00-CONSOLIDATED-REGISTER.md:147`). |
| ES-88 / 05 | PARTIALLY CONFIRMED | The three sampled events exist/emit (`PointsExpired.php:9-45`; `VoucherLookupRateLimiter.php:120-141`; `VoucherLookupService.php:215-238`, `:295-308`) but are absent from the listener map (`EventServiceProvider.php:68-160`). |

## Findings

1. **Critical — ES-07 materially overstates the blind spot.** The receipt defect is real, but the Z-report branch does inspect fiscal-era events (`VerifyPosChainCommand.php:372-398`; `ZReportHashService.php:251-293`), contradicting the register/handover wording (`00-CONSOLIDATED-REGISTER.md:68`; `HANDOVER-event-sourcing-remediation-2026-08-11.md:119`).

   **Correction:** narrow ES-07 to projected receipts and the missing receipt↔fiscal-event mirror cross-check.

2. **High — lane ownership is not an exact partition.** Section 3 calls eight labels “Seven lanes” and double-assigns ES-11 while omitting ES-18, ES-31, ES-32, ES-35–40, ES-45–46, ES-56–57, ES-69–70 and ES-74 (`HANDOVER-event-sourcing-remediation-2026-08-11.md:44-57`). Several omitted rows are LAUNCH-relevant (`00-CONSOLIDATED-REGISTER.md:202-210`).

   **Correction:** provide an exact 88-row, exactly-once partition. External execution ownership must still have an explicit tracking lane.

3. **High — universal V1/V2/V3 is not executable.** The method requires all three for every fix (`HANDOVER-event-sourcing-remediation-2026-08-11.md:93-101`), although verifier fixes such as ES-07 and event-retirement fixes such as ES-77 cannot legitimately emit a new event and run a consumer (`00-CONSOLIDATED-REGISTER.md:68`, `:153`). Dossier 2 already uses `—` for inapplicable columns (`FINDINGS-shift-variance-gl-2026-08-11.md:416-429`).

   A0’s tampered fixtures are constructible using existing command/resolution test infrastructure (`VerifyEventChainCommandTest.php:146-157`, `:472-515`; `ParseFailureResumeTest.php:156-177`, `:680-690`).

   **Correction:** make V1/V2/V3 conditional by fix class and give verifier/retirement fixes their own red-green contracts.

4. **High — the loyalty “would double-earn” OK-BY-DESIGN rationale is false.** Both paths use identical `pos_receipt`/receipt-ID source identity (`PosCoreReceiptProjection.php:1487-1515`; `EarnPointsOnReceiptCompleted.php:97-112`). Duplicate earns are rejected and swallowed (`EarningProcessingService.php:53-70`; `SaleEarningService.php:67-85`; `EarnPointsOnReceiptCompleted.php:104-121`) and protected by a unique index (`2026_07_06_100000_add_source_columns_to_loyalty_transactions.php:84-93`).

   **Correction:** base Q7 on compliance-event semantics, payload and transaction boundary—not double credit.

   The POS Treasury bridge OK-BY-DESIGN claim is confirmed: it projects hash-chained fiscal events into payments/movements with fiscal source identity (`TreasuryReceiptBridge.php:46-67`, `:1331-1359`, `:1400-1438`; `TreasuryDepositBridge.php:176-209`, `:245-266`).

5. **High — Lane B has two uncaptured design gates.** Dossier 2 says emit from the v3 “projectors” (`FINDINGS-shift-variance-gl-2026-08-11.md:98-111`, `:341-345`) but verifies only SESSION_CLOSE (`:416-423`). A real close always authors both SESSION_CLOSE and Z_REPORT (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:664-711`), so per-event idempotency cannot prevent both events producing one logical cash-count event.

   SV-16 also states the SAFE_DROP-versus-CASH_OUT decision is required before acceptance (`FINDINGS-shift-variance-gl-2026-08-11.md:278-282`, `:328-332`) but it is absent from the owner-owed list, Stage 0 and handover questions (`:20-43`, `:310-318`; `HANDOVER-event-sourcing-remediation-2026-08-11.md:140-153`).

   **Correction:** select one authoritative cash-count producer, add cross-event deduplication acceptance, and promote SV-16 to an explicit decision gate.

6. **High — dossier 3 violates its “not owned elsewhere” boundary.** OP-03 is assigned to launch E1 by the handover (`FINDINGS-other-problems-2026-08-11.md:18-24`; `HANDOVER-event-sourcing-remediation-2026-08-11.md:78`). OP-17 explicitly duplicates ES-60, and OP-18 explicitly duplicates ES-19 (`FINDINGS-other-problems-2026-08-11.md:39-52`; `00-CONSOLIDATED-REGISTER.md:85`, `:131`). Therefore the “20 items outside” summary is false (`FINDINGS-other-problems-2026-08-11.md:62-64`).

   **Correction:** remove or mark these as navigation-only and recompute item/status counts.

7. **Minor — ES-84’s four-site description is inaccurate.** It is two `reserved_quantity` plus two `reserved` casts, not four `reserved` calls (`StockReservationService.php:267-283`, `:391-407`).

8. **Minor — ES-76’s explicit list contains 14 modules, not approximately 13** (`00-CONSOLIDATED-REGISTER.md:147`; `HANDOVER-event-sourcing-remediation-2026-08-11.md:57`).

## Artifact fitness

| Artifact | Fitness |
|---|---|
| `HANDOVER-event-sourcing-remediation-2026-08-11.md` | **UNFIT** — Critical P0 drift, invalid lane partition, non-executable universal verification contract and false loyalty rationale. |
| `00-CONSOLIDATED-REGISTER.md` | **FIT-WITH-CORRECTIONS** — aggregate counts reconcile and most checked rows survive, but ES-07 and ES-76 require correction and section-05 partials must retain verification gates. |
| `FINDINGS-shift-variance-gl-2026-08-11.md` | **UNFIT** — unsafe as sequencing authority until the authoritative v3 producer and SV-16 decisions are explicit and acceptance tests ingest the real two-event close. |
| `FINDINGS-other-problems-2026-08-11.md` | **UNFIT** — its defining ownership boundary and 20-item summary are contradicted by at least OP-03, OP-17 and OP-18. |

ENTRY GATE: CHANGES-REQUIRED

---

## Round 2 (2026-08-11, at fd9a199e8)

*Provenance: scoped re-review of the round-1 corrections by an independent Codex reviewer, verdict returned in-band (no file written by the run); persisted here verbatim by the orchestrator.*

**HEAD:** fd9a199e83843dd6c4ccf755a0834b6033839dde · **Round 2**, scoped re-review of the eight Round-1 findings plus fresh-eye diff pass · Read-only.

Round-1 findings: (1) ES-07 narrowing APPLIED-DEFECTIVE — detailed correction accurate, but handover mission line still says both commands "cannot fail on the fiscal era" unqualified (HANDOVER:24). (2) Partition APPLIED-DEFECTIVE — 88 exactly-once verified (A0 9/A1 12/B 3/C 12/D 8/E 14/F 12/G 3/H 7/I 3/X 5, recounted clean; orphans thematically correct), sole defect = ES-48 wrongly tied to Q3's V1/V2 disposition (HANDOVER:58,165) while Q3/D-10 text covers ES-75/ES-28 and omits ES-48. (3) Conditional V1/V2/V3 APPLIED-DEFECTIVE — classes present + A0 red-run intact, but four classes don't cover every register fix shape: ES-31 locking/DB constraint, ES-42 route authorization, ES-43 signature implementation, ES-60 post-emission ledger rewrite, ES-83 stale projection column have no applicable contract. (4) Loyalty rationale / D-1 APPLIED-FAITHFUL. (5) Lane B APPLIED-DEFECTIVE — producer choice/dedup + SV-16 Stage 0.5 + renumbering all good; handover Q11 not option-complete vs D-17 (omits option 3 "CASH_OUT now, SAFE_DROP post-launch follow-on"). (6) Dossier 3 APPLIED-FAITHFUL (A6+B8+C1+D2=17). (7) ES-84 APPLIED-FAITHFUL. (8) ES-76 APPLIED-FAITHFUL.

Fresh-eye: [Low] D-17 overstates SAFE_DROP/CASH_CORRECTION as existing "only" in the authoring type union + engine allow-set — they also appear in the payload registry and engine validation branches; the narrower "no production caller" claim holds. [Low] Owner-question ordering: Q11 inserted before Q10.

ENTRY GATE ROUND 2: CHANGES-REQUIRED

---

## Round 3 (2026-08-11, at 7569bd957)

*Provenance: scoped final verification of the six round-2 edits by an independent Codex reviewer, verdict returned in-band (no file written by the run); persisted here verbatim by the orchestrator.*

Six-edit check: (1) mission narrowing APPLIED-FAITHFUL; (2) ES-48 re-pointing APPLIED-DEFECTIVE solely because the cited register does not exist in the commit — git show 7569bd957:docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/00-CONSOLIDATED-REGISTER.md fails (docs/sessions/ is gitignored; the program contract is not version-controlled); (3) verification classes APPLIED-DEFECTIVE — ES-43's contract contradictory (populate stored signature_* columns vs no fiscal_events rewrite; columns DB-frozen by 2026_05_14_100002_create_fiscal_events_immutability.php:70) and ES-80 (write-only operator_approvals retirement, ManagerPinController.php:117, OperatorApproval.php:25) fits neither class; ES-09 guard-fix and ES-67 data-repair assignments defensible; (4) Q11↔D-17 APPLIED-FAITHFUL; (5) D-17 wording APPLIED-FAITHFUL; (6) Q1–Q11 ordering APPLIED-FAITHFUL. Fresh-eye: none beyond rows 2-3. Fitness: handover FIT-WITH-CORRECTIONS; owner sheet, SV dossier, dossier 3 (committed state) FIT; register addendum FIT-WITH-CORRECTIONS; 00-CONSOLIDATED-REGISTER.md UNFIT as commit-scoped program input (absent from the tree). ENTRY GATE ROUND 3: CHANGES-REQUIRED.

**Disposition (orchestrator, 2026-08-11).** All three residuals applied in the commit that carries this entry, each re-verified against code first:

| # | Residual | Fix |
|---|---|---|
| 2 | Contract not version-controlled | Tracked verbatim snapshot `docs/handoff/ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md` (source SHA-256 `04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540`, backing-section hashes in its header). It is now the **contract-of-record**; the `docs/sessions/` original stays the audit's working record and is cited as provenance only. Citations updated in handover §2 / the ES-48 paragraph / the register addendum, with the `N + 50` line-offset mapping recorded. `docs/sessions/` untouched. |
| 3a | ES-43 contract contradictory | Re-classed to the **emission-class** shape and stated **FORWARD-ONLY**: `signature_*` populated at INSERT for new events only, sealed rows never touched — they are DB-frozen (`2026_05_14_100002_create_fiscal_events_immutability.php:71`, `:80`, `:96-100`, re-verified). V1-analog = signature present+valid on a new row; V3-analog = the verifier fails an absent/invalid signature; plus a no-pre-existing-row-changed assertion. Whole row stays gated on Q5 / D-11. |
| 3b | ES-80 fits no class | Removed from the Lane-F dead-event-retirement mapping (it retires a **table**, not an event class) and added to §5's straggler footnote with the per-milestone contract-approval requirement. "Write-only" re-verified: sole writer `ManagerPinController.php:117`; zero readers — `OperatorApproval.php:25-56` declares only table/fillable/casts, and no other `app/`, route, test, POS or web reference reads `operator_approvals`. |

---

## Round 4 (2026-08-11, at 542fc90b8)

*Provenance: terminal scoped check by an independent Codex reviewer, verdict returned in-band.*

Register snapshot APPLIED-DEFECTIVE (sole defect: the published verification command hashes the untracked source, not the committed body — SNAPSHOT.md:25-29; the committed 232-line body itself hashes to the declared digest and contains 88 unique sequential IDs, line-mapping verified); ES-43 APPLIED-FAITHFUL (forward-only, migration freeze confirmed at :80,:96-100,:108-110); ES-80 APPLIED-FAITHFUL (write-only confirmed, Lane F=12, partition 88/88 exactly-once via automated expansion). Fresh-eye: [Medium] the snapshot verify command is not commit-scoped. Fitness: snapshot FIT-WITH-CORRECTION; handover, addendum, owner sheet, verdict record all FIT. ENTRY GATE ROUND 4: CHANGES-REQUIRED.

---

## Gate closure (2026-08-11, orchestrator disposition)

The round-4 sole residual (verify-command scoping) was closed by **this commit** — the commit that carries this entry (the exact SHA is inherently unable to be embedded as literal content within itself; it is recorded in the orchestrator's completion report and `git log`) — with the corrected command EXECUTED and its output recorded below (paste both command outputs verbatim). Convergence: round 1 = 8 findings, round 2 = 6 residuals, round 3 = 3 residuals, round 4 = 1 mechanical residual, closed with executed evidence. Orchestrator declares the ENTRY GATE CLOSED — artifacts graduate to program input. A fifth reviewer round was deliberately not run for a single self-verifying command-line edit; this disposition records that call for the audit trail.

**Primary (commit-scoped) command output** (`git show HEAD:docs/handoff/ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md | tail -n +63 | shasum -a 256`, run against the pre-amend commit `0704b3c25` — content-identical to this commit for the snapshot file, since only this verdict record changed in the amend):

```
04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540  -
```

**Working-file check output** (`tail -n +63 docs/handoff/ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md | shasum -a 256`):

```
04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540  -
```
