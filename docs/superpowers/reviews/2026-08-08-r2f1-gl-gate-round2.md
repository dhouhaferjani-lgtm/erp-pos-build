# ROUND-2 RE-GATE — R2-F1 `fix/r2f1-cancel-period-refusal` — GL/accounting axis

**Verdict: APPROVE-WITH-FIXES — one Important (I-5) before merge.** Read-only honoured (no mutation; round-1 stray probe absorbed as commit `681a99785`'s pinned cases).

## Round-1 closures — ALL VERIFIED
- **I-1 CLOSED:** guard docblock now states the truth in the right direction ("a REVCAN entry can seal into a CLOSED fiscal period today") with exact citations; ticket `2026-08-07-cancel-reversal-bypasses-closed-fiscal-period.md` exists, assigned R2-F2, cross-references the l2-gl-gate followups.
- **I-2 CLOSED:** Carbon binding at `EloquentVatPeriodRepository.php:17-30` (precedent form); 3 boundary tests pass; red evidence corroborated by the reviewer's own round-1 probe (incl. the end-day lexicographic-accident asymmetry).
- **I-3 CLOSED, right boundary argument:** `RefundService::cancellationBlockReason()` (`:283-325`) delegates via the non-throwing `DocumentPeriodLockInterface::cancellationRefusalCode()`; both entry points share one private `lockedPeriodFor()` (`:127-137`) so read/write cannot diverge; Document never names Taxation's enum/exception — contract-only dependency. Behaviour-preserving on pre-existing arms. `canCancelCreditNote` confirmed absent; `reason_code` additive (no FE consumer).
- **I-4 CLOSED:** "DO NOT FLIP BEFORE F2's AP MIRROR IS MERGED" block at `:161-168`, citing `AccountingService:833`.
- **m-1 CLOSED** (correct citation + explicit warn-off of the dead decoy). **m-3 CLOSED-WITH-RESIDUAL** (Pint `fully_qualified_strict_types` re-imports docblock FQCNs; documented in residuals; not fighting the fixer).

## Deptrac re-run (read-only)
Branch 105 occurrences = dev 102 + exactly 3 records for the contract (Document ×2 — once per method signature now that `cancellationRefusalCode()` exists — + exception ×1). Distinct delta = 2 edges, structurally identical to the gate-approved GlPreflight/GlReversal precedents. Named-edge acceptable, unchanged.

## Tests/static: 3 files by path OK 41/119 (1 skip) · Pint pass · PHPStan clean.

## NEW FINDING
### I-5 (Important) — the narrowing orphans `DocumentType::Income`, which DOES post GL
`VatPeriodCancellationGuard.php:143-152` + `CancelRefusedOnNonOpenVatPeriodTest.php:498`. The exclusion premise "post no journal entry" is FALSE for Income: `GeneralLedgerService::createFromIncome()` declared `:4056`, stamps `entry_date => payment_date ?? document_date` at `:4106`; `IncomeService::post()` calls it synchronously in-transaction (`:161`), sets Posted (`:152`), then moves treasury cash (`:170-188`). The guard's stated criterion is disjunctive ("ledger OR declaration") — the declaration half holds (`EloquentVatDataRepository:42` excludes income), the journal-entry half does not. Not exploitable today (no Income cancel route; destroy() Draft-only) but it is exactly the trigger the docblock anticipates, and the pinned provider case ("income posts no GL and no tax detail") would actively DEFEND the hole: when an Income-cancel lane lands, a FILED-period Income withdraws with no refusal AND no GL reversal (`reverseDocumentGl` null for non-Invoice/CreditNote), leaving the class-7 revenue credit and cash-in debit standing forever.
Everything else in the narrowing verified correct: the only five `createFrom*` GL entry points are invoice `:130`, credit note `:214`, expense `:3912`, income `:4056`, GR-IR `:1921` — Quote/SO/DN/RN/PO/PQR have none.
**Fix:** move Income into the locked set (rename constant if it now overclaims), flip the pinned case to refusal, correct the prose at `:82`/`:146`. If ruled otherwise, the exclusion must stand on a recorded ruling with the `createFromIncome:4056` evidence — not a false premise. **ORCHESTRATOR RULING: fix adopted — Income into the locked set (round 3).**
