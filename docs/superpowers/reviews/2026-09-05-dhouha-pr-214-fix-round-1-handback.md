# Fix round 1 — PR #214 `fix(documents): repair credit-note creation on both paths (F-STG-4)`

- **Gate answered**: [`2026-09-05-dhouha-pr-214-gate-r1.md`](./2026-09-05-dhouha-pr-214-gate-r1.md) — VERDICT was `spec ❌ · quality CHANGES-REQUESTED`.
- **Worktree / branch**: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-214`, branch `gate/pr-214`, base merge `08067317f` (= local dev `fa000edc3` + PR #214 head `551f678e2`).
- **Fix-round head**: `8c87b4ca2` — 6 commits, one per finding group.
- **PG lane**: private DB `autoerp_test_f214` on `127.0.0.1:5433` (created, used, dropped).
- **Not merged.** Nothing was pushed.

## Numbering note

The fix brief numbered the findings globally (BLOCKER 1–2, then MAJOR 3–8). This
handback uses the **gate's own IDs** as canonical and maps them:

| Brief item | Gate ID | Status |
|---|---|---|
| BLOCKER 1 (TS4111) | BLOCKER-1 | ✅ fixed |
| BLOCKER 2 (picker test red) | BLOCKER-2 + MAJOR-4 | ✅ fixed |
| MAJOR 3 (clamp on cache) | MAJOR-1 | ✅ fixed, falsifying test |
| MAJOR 4 (credit has no consumption path) | MAJOR-2 | ⛔ OWNER RULING — untouched, verbatim below |
| MAJOR 5 (5 other Posted-only surfaces) | MAJOR-3 | ⛔ OWNER RULING — untouched, verbatim below |
| MAJOR 6 (shared picker) | MAJOR-4 | ✅ fixed with BLOCKER-2 |
| MAJOR 7 (weak backend test) | MAJOR-5 | ✅ fixed |
| MAJOR 8 (type check) | MAJOR-6 | ✅ fixed |
| IMPORTANT items | IMPORTANT-1/2/3/5 | ✅ fixed · IMPORTANT-4 reported · IMPORTANT-6/7 owner |
| MINOR items | MINOR 1 + 2 | ✅ fixed · MINOR 3 pre-existing red, unchanged |

---

## Per finding

### [BLOCKER-1] `pnpm typecheck` RED — 4 × TS4111 · ✅ FIXED — commit `eaae8ed39`

**Claim verified before acting.** `CreditNotePayload.lines` was
`Record<string, unknown>[]` at `apps/web/src/features/documents/creditNotePayload.ts:51`
(gate cited `:64`; the declaration is at `:51` in the merged tree — same field),
which forced `creditNotePayload.test.ts:84,85,102,103` to read `unit_price`
through an index signature.

**Change.** No generated DTO exists for the credit-note REQUEST body — the only
credit-note symbol in `packages/shared/types/generated.d.ts:862` is
`CreditNoteReason` (`grep -n -i creditnote packages/shared/types/generated.d.ts`
returns exactly two lines, that one and `SupplierCreditNoteReason:1990`), so
rule 7 has no generated type to derive from here. The two wire shapes are now
declared once, as a union, in
`apps/web/src/features/documents/creditNotePayload.ts:59-79`:

- `CreditNoteInvoiceLinePayload` — `line_id` + `quantity`, **no money** (the
  backend re-prices from the source line);
- `CreditNoteManualLinePayload` — `unit_price: string`, matching
  `CreditNoteController.php:166`'s `required|string|regex:/^\d+(\.\d{1,3})?$/`.

The SOURCE line type is now `Pick<DocumentLine, …>`
(`creditNotePayload.ts:32-35`) instead of a restatement — that also closes gate
MINOR 1. The test narrows the union with a `manualLine()` type guard
(`creditNotePayload.test.ts:38-43`) rather than indexing a bag of `unknown`.

**Proof.** `tsc --noEmit` → exit 0, no output (verbatim §Static gates).

### [BLOCKER-2] + [MAJOR-4] shared picker repurposed · ✅ FIXED — commit `10fc011a6`

**Claim verified.** `InvoiceSearchSelect` has two production consumers:
`CreateCreditNotePage.tsx:371` and `CreateReturnNotePage.tsx:442` (confirmed by
reading both). The PR dropped `statusFilter: 'posted'` + `has_balance` for all
of them (`InvoiceSearchSelect.tsx:74-79`) and left
`InvoiceSearchSelect.test.tsx:136-146` red.

**Change.** The sealed-invoice list is now **opt-in** via a `sourceFilter` prop
(`apps/web/src/components/molecules/pickers/InvoiceSearchSelect.tsx:47-59`),
resolved from a data table at `:82-90` and destructured at `:92`:

- `'payable'` (**default**) → `statusFilter: 'posted'` + `additionalFilters: { has_balance: 'true' }` —
  byte-identical to pre-PR behaviour, which is what the **return-note page keeps**
  (it passes no prop);
- `'creditable'` → `additionalFilters: { creditable: '1' }`, requested only by
  `CreateCreditNotePage.tsx:403-412`.

**Proof.** The original `filters invoices by posted status` assertion is
restored unchanged, and three cases were added
(`InvoiceSearchSelect.test.tsx:151-198`): the default still-owing contract
(asserting `creditable` is **absent**), the opt-in creditable contract
(asserting `status=posted` and `has_balance` are **absent**), and a fully PAID
invoice surfacing in creditable mode. 20/20 green (was 1 failed | 16 passed).

### [MAJOR-1] clamp read a cache with a `?? total` fallback · ✅ FIXED — commit `d7a456aeb`

**Claim verified.** `CreditNoteService.php:1303` (merged tree) was
`$currentBalance = (string) ($invoice->balance_due ?? $invoice->total ?? '0');`.
`Document::outstandingBalance()`'s own docblock
(`apps/api/app/Modules/Document/Domain/Document.php:826-860`) states the
blindness in prose and `AgedReceivablesService.php:199-207` quantifies it.

**Change.** `apps/api/app/Modules/Document/Application/Services/CreditNoteService.php:1303-1326`
now eager-loads `allocations` + `creditsAgainstDocument` and clamps on
`$invoice->outstandingBalance($scale)` — the ONE definition of "still open"
(rule 22) already used by `AgedReceivablesService.php:216`,
`AgedPayablesService.php:427`, `MultiPaymentService.php:65`,
`PaymentController.php:523`, `CloseInvoiceWithToleranceService.php:82` and
`DocumentPostingService.php:273`. Both credit paths
(`createCreditNote()` and `createLineBasedCreditNote()`) reach the allocation
through this single site — `grep -n "allocateCreditNote" app/` confirms one
caller, `CreditNoteController.php:383-391`.

**Falsifying test** —
`apps/api/tests/Feature/Document/CreditNotePaidInvoiceSourceTest.php:412-507`,
`test_posting_against_a_settled_invoice_with_a_blind_cache_allocates_zero_and_still_books_the_gl`.
Fixture = the documented blind-cache shape: a Paid invoice **settled through a
real `payment_allocations` row**, whose cached `balance_due` is then blanked
(`DB::table('documents')->update(['balance_due' => null])` — the triggers fire on
allocation DML only, verified in
`2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:37-70`, so the
blanking sticks on PostgreSQL too).

**Red-without-fix, verbatim.** Pre-fix clamp temporarily restored, test re-run,
source restored immediately after (`git status --short` clean):

```
=== SQLITE RED-WITHOUT-FIX (pre-fix clamp restored) ===
1) Tests\Feature\Document\CreditNotePaidInvoiceSourceTest::test_posting_against_a_settled_invoice_with_a_blind_cache_allocates_zero_and_still_books_the_gl
a settled invoice has no headroom: the credit must allocate 0, not the full total
Failed asserting that 1 is identical to 0.
FAILURES!  Tests: 1, Assertions: 5, Failures: 1.

=== PG RED-WITHOUT-FIX (autoerp_test_f214, live triggers) ===
  ⨯ posting against a settled invoice with a blind cache allocates zer… 48.54s
   FAILED  Tests\Feature\Document\CreditNotePaidInvoiceSourceTest > posting…
  a settled invoice has no headroom: the credit must allocate 0, not the full total
Failed asserting that 1 is identical to 0.
  Tests:    1 failed (5 assertions)
```

(`bccomp` returned 1, i.e. the allocation was the FULL `1200.000` against a
settled invoice — exactly the divergence the gate derived but could not execute.)

### [MAJOR-6] `isCreditableInvoiceSource()` did not check the type · ✅ FIXED — commit `4dca2503d`

**Claim verified.** `Document.php:579-587` matched on status only;
`CreditNoteController.php:141` was a type-blind
`ScopedExists::tenantAndCompany('documents', …)`.

**Change, two layers, one rule.**

- `apps/api/app/Modules/Document/Domain/Document.php:578-594` —
  `return $this->type === DocumentType::Invoice && in_array($this->status, [Posted, Paid], true);`
  This is the guard every non-HTTP caller passes through
  (`CreditNoteService.php:826` and `:952`).
- `apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php:144-161`
  puts the same type condition on the exists rule
  (`ScopedExists::tenantAndCompany('documents', …)->where('type', DocumentType::Invoice->value)`),
  so a non-invoice source is a plain **422 on `source_invoice_id`**.

**Deviation from the brief, deliberate.** The brief said "make the controller
validator *use it*". A closure rule calling `isCreditableInvoiceSource()` would
also swallow the STATUS refusal into Laravel's validation envelope and break the
contract `CreditNoteIntegrationTest.php:305-320` pins
(`error.code = VALIDATION_ERROR`, `error.message = 'Credit notes can only be
created for posted or paid invoices'`). Constraining the exists rule by type
gets the required outcome (non-invoice source → 422) declaratively, from the
enum, with no magic string, while status refusals keep flowing through the
service exception path. Flagging this so the next gate can overrule it.

**Proof.** `test_credit_note_rejects_a_non_invoice_source_document`
(`CreditNotePaidInvoiceSourceTest.php:292-321`) posts a **posted delivery note**
as the source and asserts the API validation error AND that
`$deliveryNote->isCreditableInvoiceSource()` is false AND that no credit note
row was created. Red-without-fix, verbatim (both layers reverted):

```
1) …::test_credit_note_rejects_a_non_invoice_source_document
Failed to find a validation error in the response for key: 'source_invoice_id'
Response does not have JSON validation errors.
```

### [MAJOR-5] the new backend test asserted almost nothing · ✅ FIXED — commit `2df9990ee`

**Claim verified.** The PR's file was 4 tests / 7 assertions; three were bare
`assertCreated()` / `assertUnprocessable()`, the Paid source was never confirmed
+ posted, `use AssertsApiValidation;` was imported and unused (gate MINOR 2).

**Change.** `apps/api/tests/Feature/Document/CreditNotePaidInvoiceSourceTest.php`
is now **8 tests / 46 assertions**:

1. `test_amount_based_credit_note_can_be_created_from_a_paid_invoice` — asserts
   the DATA (`type`, `status`, `source_document_id`, `partner_id`, `total`).
2. `test_line_based_credit_note_can_be_created_from_a_paid_invoice` — asserts
   source, line count, quantity, total.
3. `test_credit_note_still_rejects_a_draft_source_invoice` — asserts the error
   envelope AND that zero credit notes exist.
4. `test_credit_note_rejects_a_non_invoice_source_document` (MAJOR-6).
5. `test_creditable_filter_returns_posted_and_paid_but_not_draft_invoices`.
6. `test_creditable_filter_rejects_a_non_boolean_value` (IMPORTANT-1).
7. `test_creditable_filter_is_scoped_to_the_current_company` — **rule 22
   second-of-everything**: a second company + second customer, asserted both
   ways, switching via the real mechanism (`X-Company-Id`, read by
   `CompanyContextMiddleware.php:194`).
8. **The seam** (MAJOR-1 + MAJOR-5): create → confirm → post against a settled
   invoice, asserting
   (a) `credit_note_allocations.amount` clamps to `0.000`,
   (b) the invoice's computed outstanding stays `0.000`,
   (c) from the **REAL posting path** (not a fixture) one `journal_entries` row
   with `source_id = creditNote.id` whose legs are **Cr 411 `1200.000` carrying
   `partner_id`** / **Dr 701 `1000.000`** / **Dr 4457 `200.000`**, and that the
   entry balances — matching the GL shape the gate traced at
   `AccountingService.php:738-830`.

`AssertsApiValidation` is now genuinely used (`assertJsonValidationErrors()`,
the project-specific `{error:{errors}}` drop-in) — gate MINOR 2 closed.

### [IMPORTANT-1] `creditable` was an unvalidated magic string · ✅ FIXED — commit `2df9990ee`

**Claim verified.** `InvoiceController.php:208` was
`if ($request->query('creditable') === 'true')`.

**Change.** `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:200-224`
validates `'creditable' => ['sometimes', 'boolean']` and reads the flag through
`$request->boolean('creditable')`. The picker sends `creditable=1`. An
unparseable value is a 422 — **fail closed**, never a silent unfiltered list.

**Red-without-fix, verbatim** (old string comparison restored):

```
2) …::test_creditable_filter_returns_posted_and_paid_but_not_draft_invoices
Failed asserting that an array does not contain '01a07162-7c19-72bd-ad9d-a641f84cf0c2'.
3) …::test_creditable_filter_rejects_a_non_boolean_value
Failed to find a validation error in the response for key: 'creditable'
```

(#2 is the DRAFT invoice appearing in the list when `creditable=1` fell through
the `=== 'true'` comparison — the fail-open the gate described, executed.)

**NOT fixed, reported:** the gate's second half of IMPORTANT-1 — the filter does
not exclude `fully_credited` invoices, so the picker still offers invoices whose
credit attempt 422s on the headroom guard (`CreditNoteService.php:845-850`).
That is entangled with gate MAJOR-3 (three competing creditability predicates)
and is left for the owner ruling below.

### [IMPORTANT-2] 'all' mode had no empty-lines guard · ✅ FIXED — commit `8c87b4ca2`

**Claim verified.** `CreateCreditNotePage.tsx:242-260` validated
`selectedLineIds.size === 0` only for `'partial'`, and `lines` is filled
asynchronously by the `/invoices/{id}` query (`:112-154`).

**Change.** `apps/web/src/features/documents/CreateCreditNotePage.tsx:265-274` —
`if (lineMode === 'all' && lines.length === 0)` refuses with a **translated**
message, new key `sales:creditNotes.form.invoiceLinesNotLoaded` added to
`apps/web/src/locales/en/sales.json:809` and
`apps/web/src/locales/fr/sales.json:809` (the `ar` bundle carries none of the
sibling `creditNotes.form.*` keys, so it was left alone — no parity gate exists
for them).

### [IMPORTANT-3] blank-line contract the FE pinned is one the backend rejects · ✅ FIXED — commits `eaae8ed39` + `8c87b4ca2`

**Claim verified.** `creditNotePayload.ts:88` emitted `unit_price: String(line.unit_price)`
→ `''`, and the old test #4 asserted exactly that; `CreditNoteController.php:164-166`
requires `description` and `unit_price`.

**Change — reconciled to the REAL contract, which is a refusal, in two places:**

- **Page (operator-visible):** `CreateCreditNotePage.tsx:281-291` reuses the
  SHARED `findBlankPriceLineIds` guard (`linePayload.ts:155-170`) and the SHARED
  message key `sales:documents.errors.unitPriceRequired`, i.e. exactly what
  `DocumentForm.tsx:452-463` + `:712` do — one surface per concept, not a second
  message. The offending lines are marked in the editor via `invalidLineIds`
  (`CreateCreditNotePage.tsx:649`).
- **Wire (belt to that brace):** `creditNotePayload.ts:132-148` **strips**
  unpriced lines instead of serialising `''`.

**Test #4 updated to the real contract** —
`creditNotePayload.test.ts:110-146` (`it(…refuses an unpriced line…)` at `:119`): asserts `findBlankPriceLineIds` flags the
line AND that the builder emits only the priced one. (`isBlank` in
`linePayload.ts:33` was exported so the builder shares the predicate rather than
growing a second definition of "unpriced".)

### [IMPORTANT-5] rule 19 — `String()` over float-derived money · ✅ FIXED — commit `8c87b4ca2`

**Change.** `CreateCreditNotePage.tsx:134-149` no longer `parseFloat`s the
invoice lines: `unit_price`, `tax_rate` and `line_total` keep the API's decimal
strings. `creditNotePayload.ts:97-99` / `:142-148` emits through `toDecimalString()`, which
returns a string unchanged and never round-trips through a float.

**Residual, reported (pre-existing, NOT introduced here):** the partial-line
preview still does float display math —
`CreateCreditNotePage.tsx:206-213` (`calculateTotal`), `:617`, `:620`
(`Number(unit_price)`, `Number(creditQty)`, `Number(tax_rate)`). ESLint reports
these as `precision/no-parsefloat-on-money` **warnings** (11 across the touched
set, 0 errors). They are display-only and outside the F-STG-4 wire path; the
three the gate named at `:136-138` are gone. Migrating the preview to
`lib/decimal` is a clean follow-up lane, deliberately not taken here to keep the
fix round scoped.

### [IMPORTANT-4] the operator's `issue_date` is silently discarded · ⚠️ REPORTED, NOT FIXED

The brief said "pass the operator's `issue_date` through instead of dropping it
— **only if the request already accepts it; otherwise report**."

**It does not accept it.** `CreditNoteController::store()` builds `$rules` at
`:137-182` for all three modes and **`issue_date` appears in none of them**;
`$validated = $request->validate($rules)` therefore drops it, and both services
hard-set `'document_date' => now()` (`CreditNoteService.php:872` and `:1004`).
The page collects and zod-validates it (`CreateCreditNotePage.tsx:39`,
`:441-458`) and `buildCreditNotePayload` sends it (`creditNotePayload.ts:111`).

Honouring it is a **fiscal-document dating change** (numbering sequence,
period/lock checks, hash-chain ordering) and is out of scope for a gate fix
round. **Owner decision needed: honour it, or remove the field from the form.**

### [IMPORTANT-6] stock side absent by design, but the surface says "return" · ⛔ OWNER — untouched

Left exactly as the gate wrote it; see the verbatim block below.

### [IMPORTANT-7] the e2e spec mocks the thing under test · ⚠️ NOT ACTIONED

`apps/web/e2e/credit-note-creation.spec.ts:71-82` still intercepts
`POST /api/v1/credit-notes` and asserts the request body. The gate itself says
"not harmful, but it must not be counted as end-to-end proof". The FE payload is
now exercised against the REAL validator on the backend side instead
(`CreditNotePaidInvoiceSourceTest` posts the same shapes through
`CreditNoteController::store()`), so the gap the gate named is covered by a real
test rather than by rewriting the Playwright harness. **Playwright was not
executed** in this fix round (needs a running vite/e2e stack).

### MINOR

- **MINOR 1** (`CreditNoteSourceLine` restates `DocumentLine`) — ✅ fixed, now
  `Pick<DocumentLine, …>` (`creditNotePayload.ts:32-35`).
- **MINOR 2** (`AssertsApiValidation` imported, never used) — ✅ fixed, used by
  two tests.
- **MINOR 3** (`CreditNoteAllocationTest::it_creates_credit_note_allocation_when_posting`)
  — **still red, PRE-EXISTING, not this PR.** Same
  `DomainException: … (credit_note, confirmed) has no document_number` on both
  engines; the gate verified it errors identically on base dev `fa000edc3`. It
  fails at `Document.php:550` (the numbering precondition) via
  `DocumentPostingService.php:693` — neither file is touched by this branch
  (`git diff --stat dev HEAD`).

---

## OWNER FOLLOW-UPS — untouched by this fix round (verbatim from gate r1)

Per the fix brief's policy boundary: whether fully-PAID invoices may be credited
at all, and where the resulting customer credit lives, is an **owner ruling
pending**. The PR's current behaviour (Posted OR Paid creditable) is unchanged
in scope; the relaxation was **not** extended to the five other surfaces, and no
refund / credit-balance path was built.

> **[MAJOR-2] The relaxation's second half does not exist: the customer credit it creates cannot be consumed, refunded, or seen in AR aging.**
> Traced the full consequence of crediting a `Paid` invoice:
> - GL, exactly once: `CreditNoteController::post()` → `DocumentPostingService::post()` → `InvoicePosted` → `apps/api/app/Modules/Accounting/Listeners/InvoicePostedListener.php:26-32` → `AccountingService::createCreditNoteGLEntries()` (`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:738-830`): **Cr 411 ex-stamp** (with `partner_id`, `:791-800`), **Dr revenue per line**, **Dr VAT per rate**, self-balancing stamp pair. Correct and single.
> - Allocation: `CreditNoteController.php:383-391` calls `allocateCreditNote()` once, at post, only against `source_document_id`. With `balance_due = 0` the clamp yields an allocation row of **amount 0** (`CreditNoteService.php:1316-1327`).
> - Re-allocation to another invoice: **none** — `allocateCreditNote` has exactly one caller (grep, whole `app/`).
> - Cash payout: **refused by design** — `apps/api/app/Modules/Treasury/Domain/Services/DocumentAllocationClassifier.php:244-246` maps `DocumentType::CreditNote` to `AllocationRefusalReason::OutwardDocumentType`.
> - AR aging: **excluded** — `apps/api/app/Modules/Accounting/Application/Services/Reports/AgedReceivablesService.php:172-181` admits credit notes only when `is_historical`, on the stated ground that ordinary credit notes "reach this report through `credit_note_allocations`". A zero allocation breaks that premise, so the credit is invisible to the aging.
> - Partner GL balance: **visible** (`PartnerBalanceService::getPartnerBalance()`, `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:40-57`, reads `journal_lines.partner_id`).
> Net operator outcome: money owed to the customer, correct in the GL and on the party balance, invisible in AR aging, with no supported way to hand it back. This is the same shape as the owner's open question ("backend books an over-payment on a zero-balance document as a customer advance without refusal — intended?"): a value-bearing consequence with no refusal and no second document. The PR body asserts "crediting a settled invoice yields a customer credit/refund" — the *refund* half is not implemented.
> *Fix (pick one, owner ruling)*: (a) scope the relaxation to invoices that still have headroom AND ship the credit-consumption/refund document, or (b) ship it as-is but declare the credit in the aging + surface it on the partner page, and add the seam test.

> **[MAJOR-3] Guard relaxation is incomplete — five other surfaces still say Posted-only, including every route from the paid invoice itself.**
> - `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/InvoiceToCreditNoteConverter.php:74-77` — `if (! $source->isPosted())` → the document-conversion path (registered in `DocumentServiceProvider.php:60`) still refuses a Paid invoice.
> - `apps/web/src/features/documents/components/DocumentActions.tsx:132` and `apps/web/src/features/documents/components/DocumentActionBar.tsx:163` — `document.status === 'posted'` gates the "create credit note" action.
> - `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:748,778` — the whole credit-notes tab is gated on `isPosted` (`:418`), so a Paid invoice shows neither existing credit notes nor the create form (`CreateCreditNoteForm`, `:886`).
> - `apps/web/src/types/creditNote.ts:214-233` — `canCreateCreditNote()` refuses non-POSTED **and** `balance_due <= 0` (and does it with `parseFloat` on money, `:225`).
> Result: F-STG-4's "fully-paid invoices could not be credited" is fixed only via `/sales/credit-notes/create`. The natural journey (open the paid invoice → credit it) is unchanged.
> Also a one-surface violation: the predicate now has THREE expressions — the new `Document::isCreditableInvoiceSource()`, the inline `in_array([Posted, Paid])` in `RefundService.php:886,990`, and `RefundService::canCreditInvoice()` (`:1229-1243`, which ALSO refuses `fully_credited`). The new `creditable=true` filter therefore lists invoices that `canCreditInvoice()` calls non-creditable.

> **[IMPORTANT-6] Stock side — verified ABSENT by design, but the surface says "return".**
> Posting a credit note writes GL only: no writer under `apps/api/app/Modules/Inventory` references credit notes (only `SupplierGoodsReturnNoteService`/`Line`), and `InvoicePosted` has exactly two listeners (`apps/api/app/Providers/EventServiceProvider.php:95-97`) — GL + workshop vehicle context; the historical `PostCOGSOnInvoice` is gone (COGS is movement-driven). Goods coming back is a separate `ReturnNote` (`ReturnNoteService::receiveStockBack()`), which is the correct document-per-action split. **However** the page defaults `reason: 'return'` (`CreateCreditNotePage.tsx:104,433`) and this PR makes the whole-invoice 'all' path work for the first time, with no goods-disposition prompt and no link to a return note — unlike the guided cancel flow (`RefundService::cancelInvoice()` with `ReturnDecisionData`). For the pharmacy launch case (customer brings a box back against a cash-paid ticket) the operator will book the money and leave the stock out. Recommend an explicit "goods returned? → create return note" affordance before this flow is promoted.

Plus, added by this round: **IMPORTANT-4** above — the operator's `issue_date`
is dropped because the request never accepted it. Owner decision: honour it (a
fiscal-dating change) or remove the field.

---

## Verification — verbatim

### PHPUnit — SQLite (`./vendor/bin/phpunit <path>`)

```
### tests/Feature/Document/CreditNotePaidInvoiceSourceTest.php
........                                                            8 / 8 (100%)
OK (8 tests, 46 assertions)

### tests/Feature/Document/CreditNoteIntegrationTest.php
...............                                                   15 / 15 (100%)
OK, but there were issues!
Tests: 15, Assertions: 68, PHPUnit Deprecations: 15.

### tests/Unit/Document/CreditNoteServiceTest.php
..........                                                        10 / 10 (100%)
OK, but there were issues!
Tests: 10, Assertions: 36, PHPUnit Deprecations: 10.

### tests/Feature/Document/CreditNoteMoneyLaneTest.php
.............                                                     13 / 13 (100%)
OK (13 tests, 109 assertions)

### tests/Feature/Document/CreditNoteTenantIsolationTest.php
...........                                                       11 / 11 (100%)
OK (11 tests, 26 assertions)

### tests/Feature/Document/CreditNoteAllocationTest.php
There was 1 error:
1) Tests\Feature\Document\CreditNoteAllocationTest::it_creates_credit_note_allocation_when_posting
DomainException: Document 01a07168-2bf1-71cc-be0f-4ebb08c1313e (credit_note, confirmed) has no document_number: a number is allocated on the first transition out of Draft, and this operation requires a numbered document.
ERRORS!  Tests: 10, Assertions: 28, Errors: 1, PHPUnit Deprecations: 10.
   → PRE-EXISTING (gate r1 MINOR 3 verified the identical error on base dev fa000edc3).
```

### PHPUnit — PostgreSQL (`DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_f214 DB_CENTRAL_DATABASE=autoerp_test_f214 php artisan test -c phpunit-pgsql.xml <path>`)

```
   PASS  Tests\Feature\Document\CreditNotePaidInvoiceSourceTest
  ✓ amount based credit note can be created from a paid invoice         17.79s
  ✓ line based credit note can be created from a paid invoice            2.25s
  ✓ credit note still rejects a draft source invoice                     2.11s
  ✓ credit note rejects a non invoice source document                    2.08s
  ✓ creditable filter returns posted and paid but not draft invoices     2.50s
  ✓ creditable filter rejects a non boolean value                        3.13s
  ✓ creditable filter is scoped to the current company                   2.30s
  ✓ posting against a settled invoice with a blind cache allocates zero… 2.48s
  Tests:    8 passed (46 assertions)   Duration: 34.68s

  Tests\Feature\Document\CreditNoteIntegrationTest
  Tests:    15 passed (68 assertions)  Duration: 54.94s

  Tests\Unit\Document\CreditNoteServiceTest
  Tests:    10 passed (36 assertions)  Duration: 22.20s

  Tests\Feature\Document\CreditNoteMoneyLaneTest
  Tests:    13 passed (109 assertions) Duration: 43.61s

  Tests\Feature\Document\CreditNoteTenantIsolationTest
  Tests:    11 passed (26 assertions)  Duration: 56.64s

  Tests\Feature\Document\CreditNoteAllocationTest
  ⨯ it creates credit note allocation when posting                      14.35s
   FAILED  … DomainException  Document … (credit_note, confirmed) has no document_number
  Tests:    1 failed, 9 passed (28 assertions)  Duration: 27.33s
   → SAME pre-existing failure as on SQLite; not this branch.
```

DB dropped at the end (see §Cleanup).

### Vitest (`./node_modules/.bin/vitest run <file>`)

```
 ✓ src/features/documents/__tests__/DocumentForm.payload.test.ts (22 tests)
 ✓ src/features/documents/__tests__/creditNotePayload.test.ts (4 tests)
 ✓ src/features/documents/__tests__/CreateNotePages.quantityDisplay.test.tsx (2 tests)
 ✓ src/components/molecules/pickers/InvoiceSearchSelect.test.tsx (20 tests)
 ✓ src/features/documents/__tests__/DocumentForm.blankUnitPrice.test.tsx (10 tests)
 ✓ src/features/documents/__tests__/ReturnCreditNotePages.tenantScope.test.tsx (5 tests)
 Test Files  6 passed (6)
      Tests  63 passed (63)

 ✓ src/features/documents/components/__tests__/DocumentActionBarCancel.test.tsx (8 tests)
 ✓ src/features/documents/components/__tests__/DocumentActionBar.test.tsx (2 tests)
 ✓ src/features/documents/components/__tests__/DocumentLines.test.tsx (3 tests)
 ✓ src/features/documents/components/__tests__/RelatedDocumentsTab.test.tsx (1 test)
 ✓ src/features/documents/components/__tests__/NotesCell.test.tsx (11 tests)
 ✓ src/features/documents/components/__tests__/DocumentLineEditor.quantityStep.test.tsx (3 tests)
 ✓ src/features/documents/components/__tests__/DesignationCell.test.tsx (15 tests)
 ✓ src/features/documents/components/__tests__/DocumentLineEditor.purchasePriceDefault.test.tsx (17 tests)
 ✓ src/features/documents/components/__tests__/DocumentComponents.tenantScope.test.tsx (3 tests)
 ✓ src/features/documents/components/__tests__/DocumentLineEditor.test.tsx (34 tests)
 Test Files  10 passed (10)
      Tests  97 passed (97)
```

Note: there is no `CreateCreditNotePage.test.tsx` / `CreateReturnNotePage.test.tsx`
in the repo; those pages are covered by `CreateNotePages.quantityDisplay` and
`ReturnCreditNotePages.tenantScope`, both run above.

### Static gates

```
$ ./vendor/bin/pint --test <5 touched PHP files>
{"result":"pass"}

$ ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G \
    app/Modules/Document/Application/Services/CreditNoteService.php \
    app/Modules/Document/Domain/Document.php \
    app/Modules/Document/Presentation/Controllers/CreditNoteController.php \
    app/Modules/Document/Presentation/Controllers/InvoiceController.php
 [OK] No errors
   (phpstan.neon `paths: app/` — tests/ is out of analysis scope, same as gate r1.)

$ ./node_modules/.bin/tsc --noEmit
(no output)  TSC_EXIT=0

$ ./node_modules/.bin/eslint <6 touched web files>
src/components/molecules/pickers/InvoiceSearchSelect.test.tsx errors=0 warnings=26
src/components/molecules/pickers/InvoiceSearchSelect.tsx       errors=0 warnings=6
src/features/documents/CreateCreditNotePage.tsx                errors=0 warnings=102
src/features/documents/__tests__/creditNotePayload.test.ts     errors=0 warnings=0
src/features/documents/creditNotePayload.ts                    errors=0 warnings=0
src/features/documents/linePayload.ts                          errors=0 warnings=1
✖ 135 problems (0 errors, 135 warnings)
  top rules: 73 no-deprecated (colorClasses quarantine), 20 unbound-method,
             13 no-unnecessary-template-expression, 11 precision/no-parsefloat-on-money
  → all pre-existing classes; the three parseFloat-on-money warnings the gate
    named at CreateCreditNotePage:136-138 are GONE.

$ npx react-doctor --base dev --blocking warning
  → every finding is in a file this branch does not touch
    (DeliveryNoteDetailPage, ReturnNoteDetailPage, OpeningBalanceWizardPage,
     MemberFormPage, SalesTrendChart, UserSelector, NodeTree,
     CheckoutSuccessDialog, POSPage, InventorySettings, PaymentDetailPage,
     VehicleDetailPage, tools/audit-listing-census.mjs).
    The commit hook's "staged regressions" line is repo-wide noise, not this diff.
```

### `git diff --stat dev HEAD`

```
 .../Application/Services/CreditNoteService.php     |  33 +-
 apps/api/app/Modules/Document/Domain/Document.php  |  18 +
 .../Controllers/CreditNoteController.php           |  17 +-
 .../Presentation/Controllers/InvoiceController.php |  22 +
 .../Feature/Document/CreditNoteIntegrationTest.php |   2 +-
 .../Document/CreditNotePaidInvoiceSourceTest.php   | 507 +++++++++++++++++++++
 .../tests/Unit/Document/CreditNoteServiceTest.php  |   2 +-
 apps/web/e2e/credit-note-creation.spec.ts          | 107 +++++
 .../molecules/pickers/InvoiceSearchSelect.test.tsx |  56 +++
 .../molecules/pickers/InvoiceSearchSelect.tsx      |  34 +-
 .../features/documents/CreateCreditNotePage.tsx    |  80 ++--
 .../documents/__tests__/creditNotePayload.test.ts  | 145 ++++++
 .../documents/components/DocumentLineEditor.tsx    |   7 +-
 .../src/features/documents/creditNotePayload.ts    | 154 +++++++
 apps/web/src/features/documents/linePayload.ts     |   5 +-
 apps/web/src/locales/en/sales.json                 |   1 +
 apps/web/src/locales/fr/sales.json                 |   1 +
 17 files changed, 1147 insertions(+), 44 deletions(-)
```

## Commits (all on `gate/pr-214`, path-scoped)

```
8c87b4ca2  fix(documents): credit-note page — refuse empty/unpriced submits, keep money as decimal strings (gate r1 IMPORTANT-2/3/5)
2df9990ee  fix(documents): validate creditable as a boolean + harden the paid-source test (gate r1 IMPORTANT-1, MAJOR-5, rule 22)
4dca2503d  fix(documents): isCreditableInvoiceSource() must require type=Invoice (gate r1 MAJOR-6)
d7a456aeb  fix(documents): clamp the credit-note allocation on the COMPUTED outstanding, not the balance_due cache (gate r1 MAJOR-1)
10fc011a6  fix(documents): make the creditable invoice filter opt-in on the shared picker (gate r1 BLOCKER-2 / MAJOR-4)
eaae8ed39  fix(documents): type the credit-note request lines instead of Record<string, unknown> (gate r1 BLOCKER-1)
```

## Deploy notes (delta on gate r1's)

- Still no migrations, seeders, queue/Horizon or route changes, and no PHP DTO
  changes (so no `typescript:transform`).
- **Wire change since the PR head:** the credit-note picker now sends
  `creditable=1` (boolean-validated) instead of `creditable=true`. Both the
  filter and the picker changed in the same commit set; nothing else in the repo
  or in staging sends `creditable` (`grep -rn "creditable" apps/`), so this is
  self-contained. `creditable=true` no longer validates.
- **Behaviour restored for the return-note page** — it is back on
  `status=posted&has_balance=true`, exactly as before PR #214.
- A non-invoice `source_invoice_id` is now a 422 instead of reaching the service.
- An unparseable `creditable` value on `GET /invoices` is now a 422.

## Cleanup

`autoerp_test_f214` dropped. Working tree clean; the two temporary
pre-fix-restore experiments were reverted from a scratchpad copy and verified
with `git status --short` (empty) before committing anything.

## Still red

Exactly one, and it is **not** this branch:
`CreditNoteAllocationTest::it_creates_credit_note_allocation_when_posting`
(`DomainException … has no document_number`), on SQLite and on PG, identical to
base dev per gate r1 MINOR 3. Output above.
