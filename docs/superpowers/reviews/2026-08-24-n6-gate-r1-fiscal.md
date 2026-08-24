# N-6 / B-20 PHASE 1 — adversarial gate r1, FISCAL-POS lens

Lane `fix/campaign-n6-payment-advance`, worktree `.worktrees/n6-payment-advance`, HEAD `d1b88e0da`.
Diff reviewed: `git diff dev...HEAD` (50 files). Lens: the posting/seal path, the state machine, immutability, the print
marker. GL/advance/repair-command economics belong to the treasury lens and are NOT duplicated here (one cross-lens
pointer at the end).

**VERDICT: spec ✅ + quality CHANGES-REQUESTED**

All eight brief items are delivered and the core claims hold under execution (sealed tuple atomic, hash inputs
unchanged, T25e pre-seal, trigger carve-out satisfied on real PostgreSQL, refund reopen never fabricates a Posted
invoice). Three lane-introduced defects and one Critical standing defect that this lane makes deterministic are what
hold the gate.

---

## 0. What was executed (not asserted)

| Check | Result |
|---|---|
| PHPStan level 8, project config, on the 8 changed core files | `[OK] No errors` |
| `phpunit tests/Unit/Document/DocumentStatusMachineTest.php tests/Feature/Treasury/N6PaymentOnUnpostedInvoiceTest.php tests/PHPStan/DocumentStatusWriteOnlyViaStatusServiceTest.php` (sqlite) | `OK (29 tests, 69 assertions)` |
| Same + `tests/Feature/Document/RepairPaidNeverPostedDocumentsCommandTest.php` on **PostgreSQL 16** (throwaway `autoerp_test_n6fp`, 127.0.0.1:5433 — **dropped after**) | `OK (29 tests, 88 assertions)` |
| `php apps/api/tools/feature-lane-manifest-check.php` | `EXIT=0`, parked total **1164** = the declared `gated_ceiling` |
| Red-proof by MUTATION (3 tampers, throwaway `git worktree`, reverted, worktree removed) | all three bind — see §2 |
| Fiscal-chain probe (throwaway test, tamper worktree) | **BREAKS** — see F-1 |

Never ran the full suite; one test process at a time; no write of any kind to the lane worktree.

## 1. Item-by-item verification (fiscal lens)

**Adjacency as shipped vs the brief — MATCHES exactly.** `DocumentStatusMachine::allowedTargetsOf()`
(`apps/api/app/Modules/Document/Domain/Services/DocumentStatusMachine.php:65-91`): draft→{confirmed,cancelled};
confirmed→{draft,posted,cancelled} with `Paid` deliberately absent (`:76`); posted→{paid,cancelled}; paid→{posted};
cancelled terminal; received terminal. Self-loops refused (`:53`). `DocumentStatusMachineTest.php:29-40` pins the eight
edges as a table.

**Sealed tuple still atomic; hash inputs unchanged.** `DocumentPostingService::postWithFiscalChain()`
(`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:630-636`) writes
`status + fiscal_category + fiscal_status + fiscal_hash + previous_hash + chain_sequence` in ONE `update()`, because
`DocumentStatusService::transition()` merges `$extraAttributes` into the same statement
(`.../DocumentStatusService.php:81`) and refuses a `status` key in them (`:63-68`). Hash input is byte-identical to dev:
`document_number | posted_at | total | currency` (`DocumentPostingService.php:607-612`) — the diff for this method
changes only the update call.

**T25e stamp still precedes the seal.** `stampDeliveryPolicyDecision()` at `:133-139`, `postWithFiscalChain()` at `:143`.
The stamp lands while `OLD.fiscal_status` is still DRAFT, so it is clear of the SEALED-only trigger, and it is not in the
hash input.

**post() idempotency widening cannot re-seal or re-emit `InvoicePosted`.** `isSealedAndSettled()` (`:169-172`) is
`status === Paid && fiscal_hash !== null`, and it is evaluated at `:90` **before** `DB::transaction`, so a re-post
returns `$document->fresh()` without reaching `postWithFiscalChain()` or `dispatchPostedEvent()`. A `Paid` document with
NO seal is deliberately excluded, so the legacy dead end stays refused by the `! isConfirmed()` throw at `:95`.
Pinned by `N6PaymentOnUnpostedInvoiceTest::test_posting_clears_the_advance_only_once:154`. **Caveat: F-2.**

**Clear-at-posting is inside the transaction and moves no sealed bytes.** `:148-156`;
`clearAdvancesAllocatedToInvoice()` (`:190-233`) writes only `payment_allocations`; `settleIfFullyPrepaid()`
(`:245-273`) writes only `status`. Neither touches `document_number`, `total`, `currency`, the hash columns or
`fiscal_category`.

**Immutability trigger interaction — verified, and verified by execution.** `enforce_document_immutability()`
(`database/migrations/tenant/2025_12_11_054716_add_document_immutability_trigger.php:32-57`) lists the immutable
columns; `status` is NOT among them ("Allow operational status updates (e.g. posted -> paid)", `:30`), and the settle
write leaves `fiscal_status` untouched so the VOIDED-only branch at `:52-57` is not entered. `posted → paid` inside the
sealing transaction and `paid → posted` on reopen both pass. Confirmed empirically: the whole N-6 suite is green on
PostgreSQL, where this trigger and the new `chk_documents_status_enum` are live.

**Refund reopen never promotes a never-sealed document.** `DocumentStatusService::reopenFromPaid():123-141` returns the
document untouched unless `status === Paid` AND `wasNeverSealed()` is false; `PaymentRefundService.php:1975-1980` and
`OutboundInstrumentService.php:681-690` now write `balance_due` unconditionally and route only the STATUS half.
Tamper-proved (§2, tamper 3).

**Historical exemption cannot be satisfied by an ordinary invoice.** `wasNeverSealed():162` short-circuits on
`isHistorical()`. `documents.is_historical = true` is written at exactly two sites, both `create()` in
`ArApOpeningService.php:328` and `:418`, both with `fiscal_category = NON_FISCAL` / `fiscal_status = DRAFT` / status
`Posted` (grep over `app/` + `database/`; no request payload sets it). The repair command excludes them
(`RepairPaidNeverPostedDocumentsCommand.php:115-117`). **Caveat:** the predicate is `is_historical` ALONE, not the
(Posted + non-fiscal + no seal) tuple the brief describes — any future writer of that column silently widens the reopen
hole. A defensive `&& ! $document->fiscal_category->isFiscal()` would pin it. Non-blocking.

**Events (rule 8): clean.** `git diff dev...HEAD -- '*Events*' '*Event.php'` is empty. No event renamed, restructured or
deleted; no new event class; `InvoicePosted` / `InvoiceCancelled` dispatch sites unchanged.

**Rule 19 spot checks: clean.** `getScaleSafe((string) $invoice->currency, 3)` in the posting path
(`DocumentPostingService.php:203`, `:249`) and in the console command (`RepairPaidNeverPostedDocumentsCommand.php:171`)
— correct for contexts with no `CompanyContext`. No float touches money anywhere in the diff.

**PHPStan rule.** Registered at `apps/api/phpstan.neon:36`; fixture test
`tests/PHPStan/DocumentStatusWriteOnlyViaStatusServiceTest.php` exercises property-assign, `update([...])`,
`forceFill([...])`, an indirection through a local variable, and the Treasury-`Posted` case, plus a
registration-in-neon assertion. TRIAGE #27 gap genuinely closed for the shapes the real writers used. Boundaries in F-11.

**Manifest.** dev is at `827162e34` (Document 79, `gated_ceiling` 1163; every commit since the lane's `dev` merge is
docs-only — LEDGER/OWNER-DECISIONS/reviews). Lane declares Document **80** / `gated_ceiling` **1164**; checker EXIT=0
and reports 1164 parked classes. **The number for the parent at merge is 1164**, valid as long as no other lane raises
first.

## 2. Red-proof by mutation (stronger than the handback's revert; done in a throwaway worktree, since removed)

| Tamper | Effect |
|---|---|
| `DocumentStatusMachine` Confirmed row += `Paid` | 2 failures: `DocumentStatusMachineTest::test_confirmed_to_paid_is_impossible`, `N6PaymentOnUnpostedInvoiceTest::test_the_status_service_refuses_confirmed_to_paid` |
| `DocumentAllocationClassifier` Invoice+Confirmed ⇒ `ReceivableClearing` | 4 failures across `N6PaymentOnUnpostedInvoiceTest` — and the payment endpoint fails CLOSED with `DOCUMENT_TRANSITION_REFUSED` (defence in depth confirmed: even a mis-classification cannot produce an unpostable invoice) |
| `DocumentStatusService::wasNeverSealed()` ⇒ `false` | 1 failure (`…refuses_to_reopen_a_never_posted_invoice_to_posted`, actual = `Posted`) + 2 errors in the repair-command suite |

All three bind. The tests are real behaviour tests (real HTTP `POST /api/v1/payments`, real GL account balances,
`RefreshDatabase`), not shape assertions.

---

## FINDINGS

### F-1 [CRITICAL] Fiscal chain FORKS around a `Paid` invoice — proven by execution
`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:590-596`
```php
$previousDoc = Document::where('company_id', $document->company_id)
    ->where('type', $document->type)
    ->where('status', DocumentStatus::Posted)     // ← lifecycle, not seal
    ->whereNotNull('fiscal_hash')
    ->orderByDesc('chain_sequence')->lockForUpdate()->first();
```
The chain predecessor is selected by LIFECYCLE status. A sealed invoice that has left `Posted` for `Paid` is invisible,
so the next invoice is treated as GENESIS: `previous_hash = NULL`, `chain_sequence` restarts at 1. There is no unique
index on `(company_id, type, chain_sequence)` (verified over `database/migrations/tenant/*`) and no document-chain
verifier anywhere in `app/` (`previous_hash` appears only in `DocumentPostingService`, `DeliveryNoteService`,
`ReturnNoteService`, `Document`), so the fork is silent. `.claude/context/compliance.md:6-8` requires an unbroken
SHA-256 chain per document type.

Probe (throwaway test, sqlite, tamper worktree), invoice A prepaid → posted (the lane's new path):
```
PROBE A: status=paid   seq=1 hash=48f7df23f6a5 prev=
PROBE B: status=posted seq=1 hash=5703bbda686f prev=      ← B must have chained onto A
FAILED asserting that null is identical to '48f7df23f6a5…'
```
Second probe, invoice A posted THEN paid (the pre-lane path, unchanged on dev): identical result
(`PROBE2 B: seq=1 prev=`).

**Pre-existing** — the query is byte-identical on dev (`git show dev:…DocumentPostingService.php` line 465) and the
post-then-pay probe fails the same way. **But this lane makes it deterministic for its own flow:**
`settleIfFullyPrepaid()` (`:245-273`) moves a fully-prepaid invoice to `Paid` INSIDE the posting transaction, so such an
invoice is never in the chain-visible set for a single instant — every prepaid invoice this lane enables forks the chain
for the next one.

**Fix (one line + one test):** predicate on the seal, not the lifecycle — delete `->where('status', DocumentStatus::Posted)`
(the query already carries `whereNotNull('fiscal_hash')`), or `->whereIn('status', [Posted, Paid, Cancelled])` if a
cancelled/voided link must stay in the chain (it must). Add a pin: post prepaid A → post B → assert
`B.previous_hash === A.fiscal_hash` and distinct `chain_sequence`. **Check the two siblings in the same round:**
`DeliveryNoteService.php:106-112` (`status = Confirmed`) and `ReturnNoteService.php:595-603`.
If the parent rules this out of Phase-1 scope, it must be filed as a P0 blocker lane WITH this evidence, not deferred
silently — the lane ships the mechanism that triggers it.

### F-2 [IMPORTANT] The in-transaction re-post guard is now stale — a race can double-seal
`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:90` vs `:111`
The outer idempotency probe was widened to `isPosted() || isSealedAndSettled()`, but the in-transaction double-check
("another request may have posted it") still asks only `$document->isPosted()`:
```php
$document->refresh();
if ($document->isPosted()) { return $document->fresh(['lines']); }
```
`isPosted()` is `status === Posted` (`Document.php:529-532`). A second concurrent `post()` that passed the outer probe
before the first committed now refreshes, sees `Paid`, and proceeds into `postWithFiscalChain()` → a SECOND seal, a
second `chain_sequence`, a second `InvoicePosted`, a second GL entry on the same invoice. Before this lane the same race
was caught, because a posted invoice stayed at `Posted`. (Code-read; not raced empirically.)
**Fix:** `if ($document->isPosted() || $this->isSealedAndSettled($document))` at `:111`.

### F-3 [IMPORTANT] `advance_cleared_at` is stamped on a clearing entry that may never post — 419 stranded, silently
`apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php:634-644`
The converter stamps `booked_as_advance = true, advance_cleared_at = now()` on the strength of
`clearCustomerAdvanceToReceivable()` returning without throwing. Two ways that is not evidence of a posted clearing:
1. It is called with the DEFAULT `PostingMode::AfterCommit` (`:579-588`) and `$actorUserId` is genuinely nullable
   (`:152-153` `$options['actor_user_id'] ?? null` → `:262` → signature `:529`). In that branch
   `GeneralLedgerService.php:1868-1877` posts **only** `if ($user !== null)` — otherwise the entry is created and left
   **DRAFT forever**. The lane's own new comment names this trap (`GeneralLedgerService.php:1779-1786`).
2. Even with an actor, the file states three lines above the stamp (`:594-600`) that a balance refusal is raised AFTER
   this frame returns, because the post is deferred through `DB::afterCommit`, so it cannot reach the `catch`.
Either way the marker says "cleared" while 419 was never discharged — and `clearAdvancesAllocatedToInvoice()`
(`DocumentPostingService.php:192-197`, `whereNull('advance_cleared_at')`) will then skip it at posting. The lane's own
safety net is disarmed exactly in the documented failure mode: 419 credited forever, 411 never discharged, no error.
**Fix:** stamp `advance_cleared_at` only from evidence the entry is POSTED — pass
`PostingMode::SynchronousInTransaction` here (the converter already runs inside a transaction, so the new guard at
`GeneralLedgerService.php:1789` is satisfied) and stamp on the returned entry; or stamp only
`advance_journal_entry_id` and let the posting path resolve cleared-ness from that entry's status.

### F-4 [IMPORTANT] The print marker makes a FALSE fiscal statement on a cancelled/voided document
`apps/api/resources/views/documents/components/posting_marker.blade.php:24-38`
The gate is `$isFiscalType && ! in_array($document->status, [Posted, Paid])`. A CANCELLED invoice keeps its
`fiscal_hash` and carries `fiscal_status = VOIDED` (`DocumentPostingService.php:369-371` — the cancel path sets
`fiscal_status` to `Voided` and never clears the hash). Its printout therefore now reads
*"Not yet posted — no fiscal seal … carries no fiscal seal and no hash-chain entry, and is not a definitive fiscal
invoice"* about a document that WAS posted, sealed and chained. That is a false statement on a printed fiscal document,
and it is the one output an auditor or a customer reads.
**Fix:** gate on the seal, not the lifecycle — `$document->fiscal_hash === null` (or `fiscal_status === FiscalStatus::Draft`).
That also makes the component correct for every future status without another edit.

### F-5 [IMPORTANT] Item 6 has NO test, and two print surfaces are not covered
- `grep -rn "posting_marker" apps/api/tests` → **nothing**. The one owner-ruled, fiscally-visible output of this lane is
  unpinned, which is precisely why F-4 shipped. Add a render test over draft / confirmed / posted / paid / **cancelled**,
  asserting the marker text and the absence of any seal block.
- `apps/web/src/features/documents/components/CreditNoteDetail.tsx:53` calls `window.print()` on the React detail view,
  not the blade — an unposted credit note printed from there carries no marker. (It is the only in-browser document
  print surface: `grep -rn "window.print" apps/web/src apps/pos/src` returns this, the Z-report page and the remittance
  page.) Either mark it or record it as a named residual.
- `DocumentPdfService::resolveTemplate():120-133` prefers `documents.country.{code}.{type}` over
  `documents.templates.{type}`. No country template exists today (`ls resources/views/documents/` → components /
  layouts / templates only), so coverage is complete NOW; the first country template silently loses the marker. Worth
  one line in the component docblock.

### F-6 [IMPORTANT] "Single write path" is partial, and the map contradicts four live writers
Routed through `DocumentStatusService`: `DocumentPostingService` (seal `:630`, non-fiscal post `:145`, cancel `:372`/`:376`,
sales-order cancel `:411`, three reverts `:488`/`:517`/`:574`), `PaymentAllocationService:289`,
`PaymentController:1059/1715/1830/1912`, `MultiPaymentService:160/328`, `CloseInvoiceWithToleranceService:161`,
`PaymentRefundService:1978`, `OutboundInstrumentService:689`. The seven brief writers + the posting service: **done**.

Status of the six services I was asked to adjudicate:
| Site | Verdict |
|---|---|
| `Procurement/Application/SupplierInvoicePostingService.php:290` `$supplierInvoice->status = Posted` (Draft→Posted) | **HOLE** — unrouted, uncaught by the rule (Procurement, value `Posted`), and the edge is **not in the map** |
| `Procurement/Application/SupplierCreditNotePostingService.php:372` (Draft→Posted) | **HOLE**, edge not in the map |
| `Expense/Application/Services/ExpenseService.php:318` (Draft→Posted) | **HOLE**, edge not in the map. (`:846` is `create()` at Posted — birth state, legitimately exempt) |
| `Income/Application/Services/IncomeService.php:153` (Draft→Posted) | **HOLE**, edge not in the map |
| `Document/Application/Services/CorrectingEntryService.php:115` (Draft→Confirmed) and `:173` (Confirmed→Posted) | **HOLES**, but both edges ARE legal in the map |
| `Document/Application/Services/ArApOpeningService.php:315` `create([... 'status' => Posted])` | **Correctly exempt** — birth state, and it is exactly the row `wasNeverSealed()` exempts |

The rule's scope (Paid anywhere; Posted inside `App\Modules\Treasury\` only) is stated honestly in the handback. The
defect is the CLAIM: `DocumentStatusService`'s docblock says "The single write path for document lifecycle-status
changes" and `DocumentStatusMachine` presents itself as the document lifecycle, while forbidding `draft → posted` —
an edge four live production writers execute daily. A later lane that routes them per the brief will 422 in production.
**Fix (docs + model, no behaviour change needed now):** name the four exempt writers in both docblocks, and either add a
typed `draft → posted` edge for the supplier/expense/income types or make the machine type-aware.

### F-7 [MINOR] Classifier docblock contradicts the classifier
`apps/api/app/Modules/Treasury/Application/Services/DocumentAllocationClassifier.php:25-29` states
"THE RULE … anything else → 422 `DOCUMENT_NOT_ALLOCATABLE`", but `:84` defaults to `ReceivableClearing`. The mitigating
note is 55 lines away (`:82-83`). A reader who trusts the rule block will mis-model the AP/PO fall-through the handback
books as R-1. Fold the legacy fall-through INTO the rule block.

### F-8 [MINOR] `markPaid()` is not idempotent, and three call sites carry no status precondition
Self-loops are refused by design (`DocumentStatusMachine:53`), so `markPaid()` on an already-`Paid` document throws
`DocumentTransitionException` → 422 on a money request that used to be a silent no-op re-write.
`MultiPaymentService:160` calls it with no status test; `PaymentController:1059` and `PaymentAllocationService:289` are
protected only by balance arithmetic (a further allocation drives the balance negative, so the `=== 0` test fails).
I found no reachable path today, but it is a latent refusal on the payment endpoints.
**Fix:** add `&& $document->status !== DocumentStatus::Paid` at the three sites. Do NOT make the service silently
idempotent — the refusal is the guard.

### F-9 [MINOR] A production domain class imports a PHPStan rule
`apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:10`
`use App\PHPStan\Rules\DocumentStatusWriteOnlyViaStatusService;` exists only to satisfy a `{@see}`. It couples the
domain to the static-analysis tree. Use the FQCN inline in the docblock and drop the import.

### F-10 [MINOR] The status CHECK is frozen at migration time
`database/migrations/tenant/2026_08_24_100100_add_status_check_constraint_to_documents.php:106-112` derives the value
list from `DocumentStatus::cases()` when the migration RUNS. A case added later leaves already-migrated tenants with the
old CHECK and rejects the new value at INSERT, per tenant, at runtime. State the follow-up obligation in the docblock
("a new enum case needs its own widening migration"). Also consider `ADD CONSTRAINT … NOT VALID` + `VALIDATE
CONSTRAINT` to avoid a full-table ACCESS EXCLUSIVE scan on a large tenant's `documents`.

### F-11 [MINOR] Two stated-but-unpinned boundaries in the PHPStan rule
`apps/api/app/PHPStan/Rules/DocumentStatusWriteOnlyViaStatusService.php:44-46` advertises four write forms; the fixture
set covers property-assign, `update()`, `forceFill()` and via-variable — **`fill()` has no fixture**. And
`isDocument():206-209` requires the receiver's type to be EXACTLY `App\Modules\Document\Domain\Document`, so a
nullable/union receiver or a builder write (`Document::query()->…->update(['status' => …])`) is not seen. No such write
exists in `app/` today (grep-verified: the only `DB::table('documents')` uses are reads), so this is future-proofing —
but the DB CHECK is a value-domain backstop only, never an edge backstop.

### Cross-lens pointer (treasury owns it, not re-adjudicated here)
`RepairPaidNeverPostedDocumentsCommand.php:277` attributes the whole reclass entry to `$verdict['paymentIds'][0]` while
`$verdict['amount']` (`:197-201`) sums allocations across ALL payments on the invoice. Idempotence still holds
(`:213-217` uses `whereIn(source_id, $paymentIds)`), but the attribution is wrong for a multi-payment invoice.

---

## What must change before merge
1. **F-3** — stop stamping `advance_cleared_at` on an unposted (possibly DRAFT-forever) clearing entry.
2. **F-2** — widen the in-transaction guard at `DocumentPostingService.php:111` to `isSealedAndSettled()` too.
3. **F-4 + F-5** — gate the print marker on the SEAL, not the lifecycle, and add the render test (incl. the cancelled case).
4. **F-1** — fix the chain-predecessor predicate in this lane (one line + one pin), or file it as a P0 blocker lane with
   the probe evidence above and an explicit owner acknowledgement. It must not be merged silently.
5. F-6/F-7 docblock truth-in-advertising; F-8..F-11 at the author's discretion in the same round.

Re-gate needed on the fix round: F-1..F-4 only (the rest are docs/latent).
