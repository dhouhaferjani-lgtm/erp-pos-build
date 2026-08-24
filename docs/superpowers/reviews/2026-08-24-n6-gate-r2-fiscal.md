# N-6 / B-20 PHASE 1 — adversarial gate r2, FISCAL-POS lens (fix round)

Lane `fix/campaign-n6-payment-advance`, worktree `.worktrees/n6-payment-advance`, HEAD `862cd15d3`.
Fix-round diff reviewed: `git diff d1b88e0da..2b3fbed64` (lane-only, 43 files; `862cd15d3` is the `dev` merge on top).
r1 record: `2026-08-24-n6-gate-r1-fiscal.md` (F-1..F-11). Treasury r1: `2026-08-24-n6-gate-r1-treasury.md`.

**VERDICT: spec ✅ + quality ACCEPT-with-conditions**

All six re-gated findings (F-1..F-6) are genuinely fixed, and each fix is red-proved by MY OWN tampers in a throwaway
worktree, not taken from the handback. One NEW defect of the same class and severity as F-4 — introduced BY the F-4 fix —
is proven by execution and is a **blocking condition** (R2-F1). Four minors are non-blocking.

---

## 0. What was executed (not asserted)

| Check | Result |
|---|---|
| `php tools/deptrac-ratchet.php` (re-run by me) | **`PASS`**, TOTAL **183 / 183**; `ModuleDomain on ModuleApplication` **held at 54** (BLOCKER class at baseline) |
| `php tools/feature-lane-manifest-check.php` | **EXIT=0**, 1166 parked classes = declared `gated_ceiling` |
| PHPStan level 8 (project config) on the 10 changed core files | `[OK] No errors` |
| Lane suite (7 files) on **sqlite** | `OK, Tests: 71, Assertions: 206, Skipped: 1` (the pgsql-only CHECK-parity guard) |
| Lane suite (7 files) on **PostgreSQL 16** (throwaway `autoerp_test_n6f2`, 127.0.0.1:5433 — **dropped after**) | `OK (71 tests, 214 assertions)` |
| Chain/seal regression, sqlite: `ReturnNoteConfirmSealAndPeriodTest`, `ReturnNoteIntegrationTest`, `InvoiceDeliveryNoteConfirmationTest`, `ConversionChainVatIntegrityTest`, `DocumentPaymentStatusTransitionTest` | `OK (38 tests, 196 assertions)` |
| 4 tampers + 3 probes (throwaway `git worktree` in scratchpad, vendor APFS-cloned so it autoloads its OWN `app/`; worktree removed) | see §2 / §3 |

One test process at a time; never the full suite; **no write of any kind to the lane worktree** (verified clean at
`862cd15d3` afterwards).

## 1. Item-by-item verification

**F-1 — chain predecessor keyed on the seal. FIXED, and the two siblings with it.**
`DocumentPostingService.php:669-674`, `DeliveryNoteService.php:129-134`, `ReturnNoteService.php:591-620` now read
`where(company_id) → where(type) → whereNotNull('fiscal_hash') → orderByDesc(chain_sequence) → lockForUpdate()`, with no
lifecycle filter. `whereNotNull('fiscal_hash')` is necessary AND sufficient, and it also repairs a second, unreported
shape the old predicate produced: cancelling the newest sealed document made the NEXT one reuse its `chain_sequence`
(duplicate sequence, not just a NULL link). A VOIDED document keeps its hash, so the link stays in the chain — the
correct handling per the existing chain rule.
Pin `N6PaymentOnUnpostedInvoiceTest::test_the_fiscal_chain_continues_across_an_invoice_that_has_moved_on_to_paid:218-242`
asserts BOTH halves (`previous_hash === first.fiscal_hash` AND `chain_sequence === first+1`). **My tamper A** (reinstate
`->where('status', DocumentStatus::Posted)`) → `Failed asserting that null is identical to '54af60a0ae7a…'`. Binds.
No other document-chain writer remains: `chain_sequence` is written only at `DocumentPostingService:713`,
`ReturnNoteService:679`, `DeliveryNoteService` (same shape). `CorrectingEntryService:172-191` deliberately writes a
`SEALED` non-fiscal row with NULL `fiscal_hash`/`chain_sequence`, so it can never be picked as a predecessor — checked,
because the removed status filter widened the candidate set.
Census queries in the handback (a)/(b)/(c) are correct read-only `SELECT`s and touch nothing; (a) is the workhorse and
subsumes the duplicate-`previous_hash` fork shape.

**F-2 — in-transaction guard = outer probe. FIXED and DISCRIMINATING.**
`DocumentPostingService.php:123`: `if ($document->isPosted() || $this->isSealedAndSettled($document))`, identical to
the outer probe at `:91`. `isSealedAndSettled()` at `:181-184` is `Paid && fiscal_hash !== null`.
`test_a_stale_caller_cannot_seal_an_already_settled_invoice_twice:255-273` is a real deterministic replay (a
`newFromBuilder` replica pinned at `Confirmed`, never saved). **My tamper B** (revert to `isPosted()` only) →
`UniqueConstraintViolationException … journal_entries.entry_number` while inserting a SECOND `Document`-sourced entry for
the same invoice at `chain_sequence 4`. That is the exact double-seal the finding predicted; the test discriminates.

**F-3 / C-2 — `advance_cleared_at` now claimed from a POSTED entry. FIXED, null-actor path covered, imbalance still loud.**
`SalesOrderToInvoiceConverter.php:604-614` passes `PostingMode::SynchronousInTransaction`; the enclosing transaction is
real (`:169` → `DeliveryNoteBillingConcurrencyRetrier::run():34-46` wraps in `db->transaction`), so the
`LogicException` guard at `GeneralLedgerService.php:1788` cannot fire. `:685-695` re-reads the entry and stamps
`advance_cleared_at` only when `JournalEntryStatus::Posted`, recording `advance_journal_entry_id` either way; the catch
at `:615` returns before the stamp, so `$clearingEntry` is always defined at `:686`.
`GeneralLedgerService.php:1876-1882`: `SynchronousInTransaction` → `postEntryNow($entry, $user, …)` unconditionally,
i.e. null-actor safe; `postEntryNow():3586-3600` defers only the EVENT to `afterCommit`.
The catch narrowing (`:626-628`) re-throws `UnbalancedJournalEntryPostException`. **My tamper D** (delete the re-throw)
→ `it_pins_the_deferral_that_keeps_an_unbalanced_prepayment_post_loud` FAILS ("exception … is thrown" not satisfied).
Imbalance stays loud; nothing is swallowed.
`it_posts_the_prepayment_clearing_even_without_an_actor_and_only_then_marks_it_cleared:687-736` drives the REAL
`converterRegistry->convert()` with no `actor_user_id` and asserts `JournalEntryStatus::Posted` — the null-actor path
the gate named, not a simulation.

**F-4 / F-5 — marker branches on the fiscal state. FIXED for the case F-4 named; see R2-F1 for the mirror it opened.**
`posting_marker.blade.php:42-57`: `$isSealed = fiscal_hash !== null`; sealed ⇒ nothing; voided/cancelled ⇒ its own
message; otherwise the not-yet-posted marker. `PostingMarkerPrintTest` = 8 render tests incl. cancelled and the
"no seal/hash/chain/QR markup" assertion; the sealed fixtures carry `chain_sequence` so they are PG-legal under
`chk_fiscal_mandatory_core`, and the seal is applied in a second write so `trg_document_immutability` is satisfied — both
verified by the PG leg. **My tamper C** (restore the r1 lifecycle gate) →
`test_a_cancelled_invoice_says_cancelled_and_never_denies_its_seal` FAILS. The 8 tests genuinely pin.
Print surfaces: `templates/invoice.blade.php:6` + `templates/credit_note.blade.php:6` include it; the country-template
caveat is in the component docblock (`:17-21`); `CreditNoteDetail.tsx:64-101` (the only in-browser document print
surface) carries an equivalent marker with en/fr/ar keys.

**F-6 — type-aware `draft → posted`, and the four writers ROUTED. FIXED (the merge-blocking half).**
`DocumentStatusMachine::allowedTargetsOf():66-89` adds `Posted` to `Draft`'s targets only when
`postsDirectlyFromDraft($type)` (`:96-105`: SupplierInvoice / SupplierCreditNote / Expense / Income); with no type in
hand the edge stays forbidden — fail closed. `DocumentStatusService::transition():82` now passes `$document->type`.
All four writers routed with their own columns in the SAME statement: `SupplierInvoicePostingService.php:291-297`,
`SupplierCreditNotePostingService.php:373-375`, `ExpenseService.php:319-321`, `IncomeService.php:154-156`.
Regression measured by the author at `tests/Feature/{Procurement,Expense,Income}` = 351 green; I did not re-run it.
**The grep the brief asked me to confirm does NOT come back clean — see R2-F3.** The `draft→posted` half is complete;
the docblock's completeness CLAIM is not.

**Classifier relocation + waiver.** `DocumentAllocationClassifier` now lives at
`app/Modules/Treasury/Domain/Services/DocumentAllocationClassifier.php` (Domain), which removes the
`ModuleDomain on ModuleApplication` BLOCKER that `MultiPaymentService` created. Ratchet re-run by me: PASS 183/183,
BLOCKER class held at its baseline 54. **The waiver is LEGITIMATE, not a cover:** it is +1 in
`SharedContracts on ModuleDomain` for `CustomerAdvanceClearingInterface` typed on `Document` — the identical shape
already carried by `DocumentGlPreflightInterface`, `DocumentGlReversalInterface` and `DocumentGlCorrectionInterface` in
the same directory; the alternatives (scalar bag / Document importing `GeneralLedgerService`) are strictly worse, and the
clearing call site must stay inside `post()`'s transaction. It rides the existing burn-down ticket. It hides no new edge.
(It does not COVER the two docblock imports of R2-F4 either — deptrac cannot see those at all; measured, not assumed.)

**Sealed tuple + hash inputs + T25e + trigger (item 7).** Hash input unchanged and byte-identical to dev:
`document_number | posted_at->toDateString() | total | currency` (`DocumentPostingService.php:685-692`); the whole
`git diff dev...HEAD` on this method touches only the predecessor predicate. The seal tuple
(`status + fiscal_category + fiscal_status + fiscal_hash + previous_hash + chain_sequence`) still lands in ONE
`update()` via `DocumentStatusService::transition():91`. T25e `stampDeliveryPolicyDecision()` is at `:146-152`,
`postWithFiscalChain()` at `:155` — still PRE-seal, still outside the hash input. The whole lane suite is green on real
PostgreSQL where `trg_document_immutability`, `chk_fiscal_mandatory_core` and `chk_documents_status_enum` are live.

**Rule 8 (events).** `git diff d1b88e0da..2b3fbed64 -- '*Events*' '*Event.php'` is empty. `DocumentFullyPaid` is REUSED
at `DocumentPostingService.php:286-323` (ctor `:314`), never versioned — correct.

**Rule 19 spot checks.** `getScaleSafe($currencyCode, 3)` at `GeneralLedgerService.php:1798` replaces the bare no-arg
`$this->scale()` in all three ceiling comparisons — correct for a path now reachable from a console/queued post. No float
touches money in the fix-round diff.

**Manifest (item 8).** dev is at `542156abd` (`gated_ceiling` **1163**, Document **79**); the only two commits dev is
ahead by are docs-only (`git diff --name-only 862cd15d3...542156abd` = 2 `docs/` files). Lane declares Document **82** /
`gated_ceiling` **1166**; checker EXIT=0 at 1166. **The merge value for the parent is `gated_ceiling` 1166 / Document 82**
— valid as long as no other lane raises first.

## 2. Red-proof by mutation (mine, throwaway worktree, since removed)

| Tamper | Effect |
|---|---|
| A — reinstate `->where('status', Posted)` in the predecessor query | `test_the_fiscal_chain_continues_…` FAILS: `null` vs the sealed hash (genesis) |
| B — revert the in-transaction guard to `isPosted()` only | `test_a_stale_caller_cannot_seal_…` ERRORS on a SECOND `Document`-sourced journal entry for the same invoice |
| C — restore the r1 lifecycle marker gate | `test_a_cancelled_invoice_says_cancelled_and_never_denies_its_seal` FAILS |
| D — delete the `UnbalancedJournalEntryPostException` re-throw | `it_pins_the_deferral_that_keeps_an_unbalanced_prepayment_post_loud` FAILS |

## 3. Probes (mine)

| Probe | Result |
|---|---|
| Cancelled invoice with NO seal → render the marker | prints *"This document **was posted and sealed**… Its fiscal seal remains in the hash chain"* — **R2-F1** |
| `Posted` + `is_historical` opening invoice → render the marker | prints *"Not yet posted… This document has not been posted to the accounts"* — **R2-F2** |
| deptrac `analyse` grepped for `DocumentStatusMachine` / `DocumentAllocationClassifier` | **no output** — docblock-only `use` imports are invisible to the configured emitters — **R2-F4** |

---

## FINDINGS

### R2-F1 [IMPORTANT — BLOCKING] The cancelled marker asserts a seal the document may not have (F-4 mirrored)
`apps/api/resources/views/documents/components/posting_marker.blade.php:43-51` +
`apps/api/lang/en/documents.php:21` / `apps/api/lang/fr/documents.php:21`

```php
$isVoided = $document->fiscal_status === FiscalStatus::Voided
    || $document->status === DocumentStatus::Cancelled;   // ← seal-agnostic
```
`cancelled_detail` is *"This document **was posted and sealed**, and has since been cancelled. **Its fiscal seal remains
in the hash chain**…"*. That branch is entered for a CANCELLED document with `fiscal_hash = NULL`.

**Reachable on a live production endpoint, not hypothetically:**
`RefundController::cancelInvoice():56` → `RefundService::cancelInvoice():68` → `:79`
`cancelInvoiceWithoutDecision():128`, whose `:139-146` writes `status = Cancelled` directly on a **Draft/Confirmed,
never-sealed** invoice (the `Posted` arm at `:130` delegates to `DocumentPostingService::cancel()`; the `Paid` arm at
`:134` throws). Same shape at `:289` and, for credit notes, `:864`.

**Proven by execution** (probe, throwaway worktree):
```
PROBE cancelled-unsealed: status=cancelled fiscal_status=DRAFT hash=NULL
<div class="posting-marker posting-marker--void">
    <strong>Cancelled — this document has been voided</strong>
    <span>This document was posted and sealed, and has since been cancelled. Its fiscal seal remains in the hash chain; …</span>
```
This is exactly F-4 with the sign flipped: a false fiscal statement, in writing, on the one output an auditor or a
customer reads — this time claiming a chain entry that does not exist. None of the 8 new render tests covers
cancelled-and-unsealed. The lane's own FRONTEND sibling got the wording right
(`apps/web/src/locales/en/sales.json` `creditNotes.postingMarker.cancelledDetail`: *"This credit note has been cancelled
and must not be used as a claim."* — no seal claim), which makes the blade the outlier.

**Fix (3 lines + 1 test):** branch on BOTH facts.
`$isVoided && $isSealed` ⇒ the current `cancelled_*` strings; `$isVoided && ! $isSealed` ⇒ a cancelled message that makes
no seal claim (reuse the FE wording, e.g. `cancelled_unsealed_detail`); add a 9th `PostingMarkerPrintTest` case
(`status = Cancelled`, `fiscal_hash = null`) asserting the absence of any seal claim.

### R2-F2 [MINOR] A historical opening-balance invoice is printed as "not posted to the accounts"
`posting_marker.blade.php:52` vs `apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:316`
Opening AR/AP invoices are created `status = Posted`, `fiscal_category = NON_FISCAL`, `fiscal_status = DRAFT`, NO hash —
real migrated production data, and the exact rows `DocumentStatusService::wasNeverSealed():172-174` exempts. Printed,
they read *"Not yet posted — no fiscal seal. This document has not been posted to the accounts."* The seal half is true;
the "has not been posted" half is false for a `Posted` row. Proven by probe.
**Fix:** suppress or reword for `$document->isHistorical()` (or `fiscal_category === FiscalCategory::NonFiscal`) — one
`@elseif` arm, and it also future-proofs the component against the next non-fiscal fiscal-typed row.

### R2-F3 [MINOR] The new "what is and is not routed" docblock still over-claims
`apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:12-21` names only `CorrectingEntryService` and
`create([... 'status' => …])` BIRTH states as unrouted, "named so nobody has to re-derive it". Live non-birth
transitions that still write `status` directly (grep over `app/`, contexts read, births excluded):

| Site | Edge |
|---|---|
| `Document/Domain/Services/RefundService.php:140`, `:289`, `:864` | `→ Cancelled` on an unposted invoice / credit note (this is R2-F1's producer) |
| `Document/Domain/Services/DeliveryNoteService.php:180` | `→ Confirmed` **plus the SEAL columns** (`fiscal_hash`, `previous_hash`, `chain_sequence`) |
| `Document/Domain/Services/ReturnNoteService.php:674` | `→ Confirmed` **plus the SEAL columns** |
| `Document/Domain/Services/SalesOrderService.php:96`, `:177`; `PurchaseOrderService.php:80` | `→ Confirmed` |
| `Presentation/Controllers/InvoiceController.php:614`, `QuoteController.php:536`, `CreditNoteController.php:279` | `→ Confirmed` |

F-6's merge-blocking half (type-aware edges + the four `draft→posted` writers) IS delivered. What is not true is the
brief's "grep proves NO remaining direct status write in `app/`". The PHPStan rule does not catch these (it scopes to
`Paid` anywhere and `Posted` under `App\Modules\Treasury\`), and the DB CHECK is a value backstop only.
**Fix:** amend the docblock to name the categories above (or route them in the Phase-2 lane) — no behaviour change owed
in this lane. The DN/RN seal writes deserve an explicit line, because this lane just edited both methods.

### R2-F4 [MINOR] Rule 6 / F-9 reintroduced in two new Domain classes — and deptrac cannot see it
- `apps/api/app/Modules/Document/Domain/Services/DocumentStatusMachine.php:10-11` — a Document **Domain** class
  `use`-imports `App\Modules\Procurement\Application\SupplierInvoicePostingService` and
  `…\SupplierCreditNotePostingService`, solely to satisfy `{@see}` at `:93-95`.
- `apps/api/app/Modules/Treasury/Domain/Services/DocumentAllocationClassifier.php:10` — the just-relocated Domain class
  `use`-imports `App\Modules\Treasury\Application\Services\DocumentAllocationStateGuard`, solely for `{@see}` at `:47`.

Both are Domain→Application, the BLOCKER category. **Measured, not assumed:** `deptrac analyse` reports NEITHER (the
configured emitters do not read docblock-only imports), so `PASS 183/183` is honest about what it measures but is NOT
evidence that these edges are absent. This is precisely the F-9 pattern the lane removed from `DocumentStatusService`.
**Fix:** FQCN inline in the docblock, drop the three imports (the same edit F-9 already got).

### R2-F5 [MINOR] A non-balance failure in `postEntryNow` still leaves a committed Draft clearing entry, unrecorded
`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1840-1882` creates the entry inside an inner
`DB::transaction` and calls `postEntryNow()` **after** it returns. A throw from `postEntryNow` that is NOT
`UnbalancedJournalEntryPostException` (e.g. a `RuntimeException` from chain/currency resolution) is caught at
`SalesOrderToInvoiceConverter.php:615` and downgraded to a payload note — and the catch `return`s at `:669` BEFORE the
stamp, so `advance_journal_entry_id` is never recorded. The entry itself is already durable in the outer conversion
transaction. Narrow, but it is the same orphan-Draft class F-3 just closed, minus the marker that would let anyone find
it. **Fix:** record `advance_journal_entry_id` in the catch arm, or re-throw everything `postEntryNow` raises.

### Residuals I confirm the parent still owns
- **R-9** pre-existing forked chains are NOT repaired by this lane. Census queries verified correct and read-only.
- **R-10** `DeliveryNoteService` / `ReturnNoteService` F-1 fixes carry NO new pin. The edit is byte-identical to the
  invoice one and the existing DN/RN seal suites are green, but the cancelled-note continuation is unproved.
- **R-12** `auth()` in `DocumentPostingService.php:229-237` — kept, advisory, named. Accepted as scoped.
- **R-13** (treasury) multi-payment reclass attribution — not re-adjudicated here.

---

## What must change before merge
**R2-F1** — the cancelled marker must not claim a seal a never-sealed document does not have (blade branch on
`$isVoided && $isSealed`, a second string, and a 9th render test). R2-F2..R2-F5 are author's discretion; none blocks.
