# DPA remediation sizing — GL/treasury half (V1-V5, G2, G3) — 2026-08-08

Read-only sizing assessment by dispatched agent; all citations verified-by-open. Companion:
`2026-08-08-dpa-fix-sizing-stock-pos.md`. Register: `2026-08-08-document-per-action-violation-sweep.md`.

## V1 — delete `test:e2e-gl-posting` — **S (~1h)** · fiscal-pos gate
Delete the file. Zero call sites (repo-wide grep); Laravel auto-discovery means removal is
automatic; the generic architecture scan (`ConsoleCommandTenantContextTest.php:110`) keeps
passing. Its net-to-zero assertion is covered by ordinary feature tests on
`createFromInvoice/createFromCreditNote`. Docblock already admits it's unrunnable
post-db-per-tenant. No schema. Flag (don't fix) sibling `TestTaxRecoverability.php` — same genre.

## V2 — opening-balance import → batch-documented path — **M (1–1.5d)** · fiscal gate
The identical refactor already exists for AR/AP: `PartiesBalancesPhase::runSide()`
(`Import/Services/PartiesBalancesPhase.php:78-148`) — batch keyed to import job,
addImportRows → validateBatch → postBatch. Port to a new `AccountingBalancesPhase` calling
`AccountingOpeningService` (`:51`,`:193`): produces `source_type='opening_balance'`,
`source_id=$batch->id`, `is_historical=true`, OBE plug. Wire into `finalizeImport()` match
(`ImportService.php:454-458`); neuter `importOpeningBalance()` (`:595-621`); remove
`createOpeningBalanceEntry` from `AccountingService` + `Shared/Contracts/AccountingServiceInterface.php:33`
(single implementor, single consumer). NO schema — batches + idempotency index exist
(`2025_12_11_100000`, `2026_07_03_200001`). Tests to rewrite: `ImportTypesTest.php:414-449`
(passes), `:451+` (unknown account becomes INVALID row not job failure — rewrite).
RISKS: postBatch refuses non-Draft/zero-valid (→ per-row warnings not 500); entry_date
changes `now()` → `cutover_date` — accountant-visible; needs cutover input (fallback:
fiscal-year start per `PartiesBalancesPhase::defaultBalanceDate()`). Consider redirecting to
richer `OpeningBalanceBatchController::import()` route instead of duplicating.

## V3 — `repository_adjustments` document — **M (1–1.5d)** · treasury gate
Create the row FIRST inside the existing `DB::transaction` (`RepositoryAdjustmentController.php:92`),
id = the UUID that already flows to JE + movement. Columns: id, tenant/company,
payment_repository_id, direction, amount(15,3), currency, reason_code
(`MovementReasonCode`), reason_text, journal_entry_id, movement_id, created_by. Immutability
trigger per `repository_movements` house pattern if gate wants. NEW tenant migration + model.
Blast: sole caller; tests additive; FE optional additive (`AdjustBalanceDialog.tsx` et al.).
RISKS: (1) `MovementSourceType::Adjustment` is OVERLOADED — `AcquirerFeeService.php:46,140`
uses it with bank-statement-line ids → no FK possible; either accept polymorphism or mint
`MovementSourceType::AcquirerFee` (gate call). (2) preserve the 658/758 seeded-purpose
precheck (`:79-89`). (3) `record()` idempotent replay (`wasIdempotentHit` `:146`) must not
insert a second adjustment row — key on same discriminator.

## V4 — payment reversal document — **L (3–4d)** · treasury gate — LONGEST POLE
Template is in the same file: `refundPayment()` (`PaymentRefundService.php:154-210`) —
negative child Payment + mirrored negative allocations + recompute + GL. Fix: new
`PaymentType::Reversal` (string column, no DDL; 5 exhaustive match blocks need deliberate
answers `PaymentType.php:29-78`), mirror lineage allocations as negative rows, NEVER delete
(`:686`), original untouched beyond status stamp. NEW partial unique index for reversal
idempotency (refund's index is refund-scoped, `2026_05_03_000004:65,74`).
⚠️ CRITICAL BRANCH: instrument-backed → the instrument cancellation entry IS the JE (adding
another double-credits AR — the C2 defect documented at `:132-141`, 30-line comment
`:705-733`); cash/no-instrument → reversal Payment carries genuine JE + cash movement OUT.
Preserve `DeferredTenderGuardsTest.php:410-443` (no cash movement on instrument reverse).
Rewrite `PaymentRefundTest.php:293-330` (C6 pins deletion; replace with net-to-zero).
Sum-based consumers are negative-row-safe (balance_due trigger, Document.php:682, etc.);
EXCEPTIONS to decide: `DepositAllocationSummaryService.php:44` (settled-amount semantics),
refund-total readers filter `payment_type='refund'` (Reversal invisible — probably right,
must be decided). Lock order Payment→Document→GL-advisory preserved. Add reversal id to
`PaymentReversed` payload.

## V5 — manual-JE correction linkage — **M–L (2–3d opt (a); ~1d opt (b))** · fiscal-pos gate — BLOCKED on F4 ruling
Ticket `2026-08-06-l2-correcting-entry-escape-hatch.md` records the un-chosen options:
(a) manual entry declares its correction target (request field + FE document picker +
widen `reverseDocumentGl()` predicate `AccountingService.php:730-737`);
(b) privileged force on cancel. `documents.source_document_id` gives NOTHING here —
`journal_entries` needs either new nullable `corrected_document_id` (cleaner) or reuse
`source_type='Document'` (safe: all 4 unique indexes are narrowly scoped, nothing covers
'Document'/'manual'). Also stamp `journal_code` in `store()` (currently absent;
`JournalCode::fromSourceType` has no 'manual' case → Misc/OD is correct).
RISK: widened predicate ⇒ correcting entry reversed along with the document — right or
double-reversal depending on ruling; pin by test FIRST. Link immutable after posting.
FE: JournalEntryForm + detail/list + i18n×3 — real task under (a).

## G2 — counting GL — **L (3d+)** · inventory-costing gate — BLOCKED on c1-bis
Perpetual answer: post Dr shrinkage / Cr Inventory (or reverse) per applied counting item,
`source_type='inventory_counting'`, `source_id=$counting->id`, in the LISTENER
(`ApplyStockAdjustmentsOnCountingCompleted.php:188,246`) — NOT inside `adjust()` (generic
writer, V7 also calls it). Idiom: `BatchWriteOffService.php:106-133` →
`createInventoryWriteOffEntry` (`GeneralLedgerService.php:4343-4390`), purpose-resolved
accounts (seeded), log-warn-don't-block. Needs NEW `SystemAccountPurpose` shrinkage/gain
cases ×3 country seeders + backfill (pattern: `BackfillTolerancePurposesCommand`) — or
reuse CostOfGoodsSold (cheaper, weaker; gate call). Valuation: `adjust()` doesn't persist
unit_cost → amount from WAC service at post time. GL post must sit INSIDE the same
transaction as the replay marker (`:243-262`) or retry double-posts.
Periodic answer: G2 largely evaporates. DO NOT PRE-BUILD.

## G3 — shift-close variance GL — **M (1.5–2d after V3)** · fiscal-pos + treasury gates
The entry already exists and is DOCUMENTED for this purpose:
`createRepositoryAdjustmentJournalEntry` (`GeneralLedgerService.php:1099-1180`) — 658/758
by purpose, all three country charts seeded + backfill command. Fix = listener on
`CashCountRecorded` (fires from `ReportGenerationService.php:415` AND offline
`ZReportSyncController.php:451` — must be idempotent per shift; movement-port idempotency
key `shift:{id}`), posting via the V3 adjustment document (`reason_code=count_variance`).
HIDDEN COST: no shift→repository link exists (`PaymentRepository` has location_id only;
POS resolves via `PaymentMethod::default_repository_id` + `orderBy('id')->first()` fallback
`TreasuryReceiptBridge.php:1444-1472`) — deterministic drawer-repository resolution is the
real work, may need a column. Listener lives TREASURY-side (POS has zero movement-port
usages; `TreasuryBalanceWritePortTest` polices).
⚠️ DOUBLE-COUNT RISK: per-receipt tolerance already posts to the same 658/758
(`createPOSPaymentToleranceEntry` `:3309`, writeoff `:3752`, rounding `:3558`) — variance
basis must be actual−expected where expected already nets those; check
`CashDrawerService::calculateExpectedCash()` first. Offline replay: `allowWhileFrozen`
stays false (server-computed).

## Dispatch plan (this half)
```
Wave 0 (now, parallel): V1(S) · V2(M) · V3(M) · V4(L, start day 1) · V5 spec-ready, code after F4 ruling
Wave 1 (after V3):      G3(M)
Wave 2 (after c1-bis):  G2(L)
```
V3 ∥ V4 parallel code, serialized treasury gate. V2's Shared/Contracts touch conflicts with
nothing. Total ≈ 13–17 dev-days + 6 gate passes (fiscal-pos ×3, treasury ×3,
inventory-costing ×1); wall-clock ≈ 2.5–3 weeks at two lanes.
Owner blockers: (1) c1-bis (gates G2, reshapes G1); (2) F4 (a) vs (b) (gates V5).
