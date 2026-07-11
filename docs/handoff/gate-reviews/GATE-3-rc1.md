# ADVERSARIAL GATE REVIEW — Treasury Phase 2, GATE 3 (Wave E, Tasks 16–18)

**Reviewer:** `claude-opus-4-8`

**Scope reviewed:** `git diff phase2-gate-2..phase2-gate-3-rc1` — POS bridges entering the fiscal perimeter (SALE_RECEIPT maturity legs, refund/void resolution, DEPOSIT_RECEIPT + ACCOUNT_PAYMENT sibling bridges), plus the shared `HandlesMaturityTenderLeg` helper, the `createPOSPaymentEntry`/allocation debit-swap seam, and `InstrumentLifecycleService::cancel` PosRevenue shape.
**Binding refs:** design spec Rev 2 (§6 lock order, §7 posting tables, §9 POS bridge, §20); plan Rev 2 (Tasks 16–18 Interfaces).

## Verified-clean sections

### 1. Lock order (spec §6 — instrument → document → GL → repository port) — CLEAN

- **Receipt bridge, fresh maturity leg:** instrument created first via `HandlesMaturityTenderLeg::handleMaturityLeg` → `InstrumentLifecycleService::receive` (`HandlesMaturityTenderLeg.php:73-93`), then `Payment::create`, then `createPOSPaymentEntry` + `postEntryNow` (`TreasuryReceiptBridge.php:582-652`), then movement skipped. Instrument precedes GL; no repository lock on the deferred leg.
- **Sibling bridges (deposit/account):** `handleMaturityLeg` (instrument) runs before `Payment::create` and before `applyAllocationFromCommand` (which locks documents + posts GL synchronously): `TreasuryDepositBridge.php:126-205`, `TreasuryAccountPaymentBridge.php:111-190`. Order instrument → documents → GL → (no port).
- **Refund cancel:** `InstrumentLifecycleService::cancel` `lockForUpdate`s the instrument (`:570`) before reading/updating the linked payment and before `postEntryNow` (`:598`); the no-cash branch never resolves or locks a repository. Single instrument lock means no AB/BA ordering exposure.

### 2. No movements without a JE, none outside the port — CLEAN

- Every deferred maturity leg sets `shouldRecordMovement = false` and returns before `movementService->record()` (`TreasuryReceiptBridge.php:679-681`, `TreasuryDepositBridge.php:207-209`, `TreasuryAccountPaymentBridge.php:193-195`).
- The only cash write remains `TreasuryMovementServiceInterface::record` with a linked `journalEntryId` (the null-JE guard at `TreasuryDepositBridge.php:227-234` is preserved). Post-cutover replay branches actively forbid a stray movement and throw if one exists (`TreasuryReceiptBridge.php:562-566`; sibling bridges `:137-141`). Guard key `fiscal_event:{event}:payment:0` matches the movement port's composed key (`MovementIntent.php:58-61`, `MovementSourceType::FiscalEvent='fiscal_event'`).

### 3. No afterCommit GL on the money path — CLEAN

- `postEntryNow` is used everywhere for maturity GL (synchronous, in-transaction). The allocation service now forces `PostingMode::SynchronousInTransaction` for all three JE sites whenever `cashAccountOverrideId` is set (`PaymentAllocationService.php:283-285, 316-318, 352-354`), so no deferred sibling leg posts via `DB::afterCommit`. `receive()`'s only `afterCommit` use is domain-event dispatch, not GL (`InstrumentLifecycleService.php:111-117`).

### 4. GL debit-swap = debit-only (spec §7 rule) — CLEAN

- `createPOSPaymentEntry` swaps only the `line_order:0` debit to `$cashAccountOverrideId`; credit (revenue) line untouched (`GeneralLedgerService.php:3049-3068`).
- Allocation swaps only `paymentMethodAccountId` (the debit) in `createPaymentReceivedJournalEntry` / `createCustomerAdvanceJournalEntry`; credit lines (AR / customer-advance liability) untouched (`GeneralLedgerService.php:1014-1037`, `:378-399`). Test pins the portfolio debit at `line_order 0` with correct scale (`PosBridgeInstrumentTest.php:189-198`).

### 5. Cancellation JE shape (spec §9 step 2: Dr ProductRevenue / Cr P-or-R) — CLEAN

- `createInstrumentCancellationEntry` with `CancellationShape::PosRevenue` books Dr ProductRevenue (`line_order 0`, `partner_id:null`) / Cr portfolio, nominal amount (`GeneralLedgerService.php:2767-2803`). This is the leg's entire GL effect; the standard `createPOSRefundReversalEntry` and movement-Out are both skipped when cancellation succeeds (`TreasuryReceiptBridge.php:439-458`). Net-zero revenue and absence of a `pos_receipt_refund` JE are pinned (`PosBridgeInstrumentRefundTest.php:191-203`).

### 6. Float on money — CLEAN

- Amounts remain decimal strings end-to-end. `HandlesMaturityTenderLeg` validates `is_numeric` and compares with `bccomp(..., $scale)` after `CurrencyScale::bcformatStrict` (`:104-115`); scale comes from injected `CurrencyScaleResolverInterface::getScale($context->currency)` with explicit currency.

### 7. Complete-set / idempotency across the pre/post-cutover boundary — CLEAN

- Probe reads `journal_lines.line_order = 0` of the existing leg's linked JE. All three POS/payment JE builders place the cash/portfolio debit at `line_order 0`, so the probe is sound.
- `repo.gl_account_id` means pre-cutover: `isMaturityLeg=false`, no instrument minted, movement completes (`TreasuryReceiptBridge.php:527-531`; sibling `:118-120`). Portfolio account means post-cutover: instrument ensured, both back-references reconciled, movement forbidden (`:532-568`). Unrecognized debit throws (`:570-573`). Pinned by the pre-cutover, post-cutover recovery, full replay, and sibling replay tests.
- Fresh-vs-replay instrument creation is idempotent: unique-key SELECT-or-create with `UniqueConstraintViolationException` re-select after the nested savepoint rolls back (`HandlesMaturityTenderLeg.php:82-96`). Semantic mismatch throws (`:100-116`) and is test-pinned.

### 8. Refund matching safety — CLEAN

- Candidate set is scoped by `idempotency_key LIKE 'fiscal_event:{original}:instrument:%'` + kind + amount, from sealed `original_receipt_reference.fiscal_event_id` (`TreasuryReceiptBridge.php:751-762`). Exactly one Received candidate cancels; a single already-Cancelled match is an idempotent no-op; zero/ambiguous emits a durable alert then uses standard cash reversal (`:764-789`). Ambiguity and remitted/cleared outcomes are test-pinned.

### 9. No swallowed transient failures — CLEAN

- Only `UniqueConstraintViolationException` is caught for idempotency re-selection. GL/DB failures propagate; the cancellation-failure test asserts full rollback. Alert idempotency is check-before-insert keyed by refund event and leg index (`:797-830`), with one row across replay.

### 10. Fiscal perimeter immutability & CompanyContext — CLEAN

- No writes to `fiscal_events`, canonical bytes, or `pos_receipt_payments` occur in the diff. Sale and refund tests snapshot `pos_receipt_payments` before/after and assert byte-identical rows (`PosBridgeInstrumentTest.php:162-213`, `PosBridgeInstrumentRefundTest.php:154-210`). All three test classes clear `CompanyContext` before `apply()`.

### 11. Reconcile-#4 amount conservation — CLEAN

- POS sale leg: instrument amount equals payment amount and the single portfolio debit. Sibling bridges: full payment debits portfolio across invoice+order+excess JEs (`PaymentAllocationService.php:282-360`), each swapping only its debit; sum equals full amount and instrument amount.

### 12. GL null-actor widening — VERIFIED SAFE

- `createPaymentReceivedJournalEntry` / `createCustomerAdvanceJournalEntry` call `postEntryNow($entry, null, ...)` for synchronous worker paths (`GeneralLedgerService.php:1040-1044`, `:403-407`). `sealAndPersistEntry` sets `posted_by => $user?->id`, byte-identical to existing system-generated null-actor sealing. The null-actor worker path is exercised by the sibling traite test.

### 13. Deviation recording — CLEAN

- The Task-18 optional debit-override implementation seam is recorded in the progress file and matches the binding contract. No unrecorded deviations found.

## Findings

All findings are informational/LOW. None block the gate.

1. **[LOW / robustness] A maturity refund leg whose payload lacks `original_receipt_reference` hard-fails instead of taking the durable-alert path.** `TreasuryReceiptBridge.php:439-441` throws when `$originalEventId === null`. This is defensible because canonical REFUND/VOID receipts must carry the reference and structural absence is not a business outcome, but a malformed edge receipt would dead-letter rather than alert. Consider a post-gate coverage pin or alert routing. Not required for approval.
2. **[LOW / defense-in-depth erosion] The removed synchronous+null-actor guard weakens a shared GL primitive for future callers.** Verified safe for the current worker use, and no existing customer-direction caller passes synchronous+null. A future caller could now seal `posted_by=null` without a loud actor-resolution error. Consider a customer-direction call-site assertion or parameter-level contract. Informational.
3. **[INFO] Refund amount matching depends on numeric-column semantics.** `where('amount', $line->amount)` relies on `payment_instruments.amount` remaining numeric/decimal so `10.00` equals `10.000`. Correct today; no action required.

## Test-coverage assessment vs plan pinned assertions

Every Task 16/17/18 pinned assertion is present and substantive: cash+check split; complete/pre-cutover/post-cutover replay; semantic conflict; missing-account rollback; voucher/Other preservation; same-day refund cancellation and silent replay; active/ambiguous alert paths; GL failure rollback; sibling deposit/account portfolio posting including null-actor worker; and `pos_receipt_payments` byte identity. No material gap; only Finding 1's impossible-under-canonical-contract null-reference edge is unpinned.

VERDICT: APPROVE
