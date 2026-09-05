# Gate r1 — PR #214 `fix(documents): repair credit-note creation on both paths (F-STG-4)`

- **PR**: otospexsolutions/erp #214 · author `dhouhaferjani-lgtm` · base `dev` · head `551f678e2` (2 commits: `0fb560f33`, `551f678e2`)
- **Reviewed tree**: MERGED — worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-214`, branch `gate/pr-214`, merge commit `08067317f` = local dev `fa000edc3` + PR head
- **Reviewer**: stock↔GL interaction gate (adversarial, code-grounded). No code modified, nothing merged.
- **PG lane**: private DB `autoerp_test_g214` on 127.0.0.1:5433 (created, used, dropped)

## VERDICT: spec ❌ · quality **CHANGES-REQUESTED**

Two CI gates are RED on the merged tree and both are caused by this PR (`pnpm typecheck`, `InvoiceSearchSelect.test.tsx`) — the PR body claims both clean. Beyond that, the F-STG-4 requirement ("fully-paid invoices could not be credited at all") is only half-delivered: the standalone credit-note page now works, but crediting from the paid invoice's OWN page is still blocked in four other places, and the money the new path creates (a customer credit against a settled invoice) has no consumption path, no aging visibility and no test.

The GL side of the seam is correct in shape (AR credit + revenue/VAT debit, once, through the sealed posting path). The stock side is correctly ABSENT (credit notes are financial-only; goods come back on their own `ReturnNote`) — but the operator surface this PR makes live defaults to `reason: return` with no goods disposition, which is the pharmacy-launch case.

---

## Findings

### BLOCKER

**[BLOCKER-1] `pnpm typecheck` is RED on the merged tree — 4 errors, all in the PR's own new test file.**
`apps/web/src/features/documents/__tests__/creditNotePayload.test.ts:84,85,102,103` — `error TS4111: Property 'unit_price' comes from an index signature, so it must be accessed with ['unit_price']` (because `CreditNotePayload.lines` is typed `Record<string, unknown>[]`, `apps/web/src/features/documents/creditNotePayload.ts:64`).
Verified: merged tree = 4 errors; base `dev` (`fa000edc3`, main checkout) = **0 errors, exit 0**. `pnpm typecheck` is literally `tsc --noEmit` (`apps/web/package.json:21`), so CI fails.
*Why it matters*: a red type gate blocks promotion and contradicts the PR's "web typecheck … clean" claim.
*Fix*: type `lines` as a discriminated union (`{line_id: string; quantity: string|number}` | `{product_id?…; unit_price: string; …}`) instead of `Record<string, unknown>`, or index with `['unit_price']` in the test.

**[BLOCKER-2] Vitest RED on the merged tree — the shared picker's own test was not updated.**
`apps/web/src/components/molecules/pickers/InvoiceSearchSelect.test.tsx:136-146` ("filters invoices by posted status") expects `expect.stringContaining('status=posted')`; actual request is `"/invoices?per_page=10&creditable=true"` after `apps/web/src/components/molecules/pickers/InvoiceSearchSelect.tsx:74-79` dropped `statusFilter: 'posted'` + `has_balance`.
Verified: merged tree `1 failed | 16 passed (17)`; base `dev` `17 passed (17)`.
*Why it matters*: the PR's stated verification scope was "full `src/features/documents` suite (452)" — the picker lives in `src/components/molecules/pickers/`, so its own regression was outside the scope that was run.
*Fix*: update the assertion to the new contract (`creditable=true`) and add a case pinning that Paid invoices are surfaced.

### MAJOR

**[MAJOR-1] The allocation clamp reads a CACHE with a `?? total` fallback — for the newly-admitted Paid population that can allocate the full credit against an already-settled invoice.**
`apps/api/app/Modules/Document/Application/Services/CreditNoteService.php:1303`:
```php
$currentBalance = (string) ($invoice->balance_due ?? $invoice->total ?? '0');
…
$clampedAmount = bccomp($creditNoteTotal, $currentBalance, $scale) > 0 ? $currentBalance : $creditNoteTotal;
```
`balance_due` is a PostgreSQL trigger cache, written only by `payment_allocations` / `credit_note_allocations` DML (`apps/api/database/migrations/tenant/2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:37-62`). `AgedReceivablesService.php:199-207` records, in prose, that this cache **stays NULL forever** on documents nothing was ever allocated against ("165 invoices / 59 532.410 TND … on the demo tenant"). For any invoice that is `Paid` with a NULL (or stale non-zero) cache — settled by a route that wrote no `payment_allocations` row — the clamp silently falls back to `total`, so the credit note allocates in FULL against a settled invoice: `credit_note_allocations.amount = total`, the trigger recomputes `balance_due = total − payments − credits`, and the customer's credit is absorbed by a document that owed nothing, while the GL 411 credit remains. That is the D1/RULING B divergence class the clamp exists to prevent, re-entered through the door this PR opens.
Before the PR this branch was unreachable for `Paid` sources (the guard refused them); it is now the headline scenario.
*What I verified on the other side*: no stock is involved; GL is written once (below). The divergence is sub-ledger vs GL.
*Fix*: clamp on the COMPUTED outstanding (`Document::outstandingBalance()`, `apps/api/app/Modules/Document/Domain/Document.php:863`), not the cache — or prove, with a test, that no `Paid` invoice can carry a NULL/stale cache.

**[MAJOR-2] The relaxation's second half does not exist: the customer credit it creates cannot be consumed, refunded, or seen in AR aging.**
Traced the full consequence of crediting a `Paid` invoice:
- GL, exactly once: `CreditNoteController::post()` → `DocumentPostingService::post()` → `InvoicePosted` → `apps/api/app/Modules/Accounting/Listeners/InvoicePostedListener.php:26-32` → `AccountingService::createCreditNoteGLEntries()` (`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:738-830`): **Cr 411 ex-stamp** (with `partner_id`, `:791-800`), **Dr revenue per line**, **Dr VAT per rate**, self-balancing stamp pair. Correct and single.
- Allocation: `CreditNoteController.php:383-391` calls `allocateCreditNote()` once, at post, only against `source_document_id`. With `balance_due = 0` the clamp yields an allocation row of **amount 0** (`CreditNoteService.php:1316-1327`).
- Re-allocation to another invoice: **none** — `allocateCreditNote` has exactly one caller (grep, whole `app/`).
- Cash payout: **refused by design** — `apps/api/app/Modules/Treasury/Domain/Services/DocumentAllocationClassifier.php:244-246` maps `DocumentType::CreditNote` to `AllocationRefusalReason::OutwardDocumentType`.
- AR aging: **excluded** — `apps/api/app/Modules/Accounting/Application/Services/Reports/AgedReceivablesService.php:172-181` admits credit notes only when `is_historical`, on the stated ground that ordinary credit notes "reach this report through `credit_note_allocations`". A zero allocation breaks that premise, so the credit is invisible to the aging.
- Partner GL balance: **visible** (`PartnerBalanceService::getPartnerBalance()`, `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:40-57`, reads `journal_lines.partner_id`).
Net operator outcome: money owed to the customer, correct in the GL and on the party balance, invisible in AR aging, with no supported way to hand it back. This is the same shape as the owner's open question ("backend books an over-payment on a zero-balance document as a customer advance without refusal — intended?"): a value-bearing consequence with no refusal and no second document. The PR body asserts "crediting a settled invoice yields a customer credit/refund" — the *refund* half is not implemented.
*Fix (pick one, owner ruling)*: (a) scope the relaxation to invoices that still have headroom AND ship the credit-consumption/refund document, or (b) ship it as-is but declare the credit in the aging + surface it on the partner page, and add the seam test.

**[MAJOR-3] Guard relaxation is incomplete — five other surfaces still say Posted-only, including every route from the paid invoice itself.**
- `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/InvoiceToCreditNoteConverter.php:74-77` — `if (! $source->isPosted())` → the document-conversion path (registered in `DocumentServiceProvider.php:60`) still refuses a Paid invoice.
- `apps/web/src/features/documents/components/DocumentActions.tsx:132` and `apps/web/src/features/documents/components/DocumentActionBar.tsx:163` — `document.status === 'posted'` gates the "create credit note" action.
- `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:748,778` — the whole credit-notes tab is gated on `isPosted` (`:418`), so a Paid invoice shows neither existing credit notes nor the create form (`CreateCreditNoteForm`, `:886`).
- `apps/web/src/types/creditNote.ts:214-233` — `canCreateCreditNote()` refuses non-POSTED **and** `balance_due <= 0` (and does it with `parseFloat` on money, `:225`).
Result: F-STG-4's "fully-paid invoices could not be credited" is fixed only via `/sales/credit-notes/create`. The natural journey (open the paid invoice → credit it) is unchanged.
Also a one-surface violation: the predicate now has THREE expressions — the new `Document::isCreditableInvoiceSource()`, the inline `in_array([Posted, Paid])` in `RefundService.php:886,990`, and `RefundService::canCreditInvoice()` (`:1229-1243`, which ALSO refuses `fully_credited`). The new `creditable=true` filter therefore lists invoices that `canCreditInvoice()` calls non-creditable.

**[MAJOR-4] A SHARED picker was repurposed for one caller — the return-note flow silently changed.**
`InvoiceSearchSelect` has two production consumers: `apps/web/src/features/documents/CreateCreditNotePage.tsx:371` and `apps/web/src/features/documents/CreateReturnNotePage.tsx:442`. The filter swap (`InvoiceSearchSelect.tsx:74-79`) changes the return-note source list too: `has_balance=true` is dropped and Paid invoices appear. Nothing in the PR body, and no test, covers the return-note page. (The change is probably desirable for returns — a returned box does not care about the balance — but it must be declared and pinned, not inherited.)
*Fix*: make the filter a prop (`sourceFilter: 'creditable' | 'payable'`) or add an explicit return-note test.

**[MAJOR-5] The new backend test asserts almost nothing, and never crosses the seam it opens.**
`apps/api/tests/Feature/Document/CreditNotePaidInvoiceSourceTest.php` — 4 tests / **7 assertions**: three of them are bare `assertCreated()` / `assertUnprocessable()`. Nothing asserts the credit note's `total`, `source_document_id`, partner, or lines; and **the Paid source is never confirmed + posted**, so the entire consequence chain the relaxation opens (GL entry, clamp, allocation row, invoice `balance_due`) is untested — including MAJOR-1 and MAJOR-2 above. `CreditNoteMoneyLaneTest::test_over_credit_allocation_clamps_balance_due_at_zero` (`:477-528`) covers the clamp only for a POSTED invoice with a real balance.
Rule 22: no second-company case for the new `creditable` filter. (Scoping itself is sound — `HandlesDocuments::baseQuery()` at `apps/api/app/Modules/Document/Presentation/Controllers/Concerns/HandlesDocuments.php:44-48` uses `Document::forCompany()` — so this is a test gap, not a leak.)
Minor within: `use AssertsApiValidation;` is imported and never used.
*Fix*: add `paid invoice → create → confirm → post` asserting (a) `credit_note_allocations.amount === '0.000'`, (b) the invoice's `balance_due` still `0.000`, (c) one `journal_entries` row with `source_id = creditNote.id` whose 411 leg is a CREDIT of the ex-stamp total. Run it on the PG lane (the allocation trigger is a no-op on SQLite).

**[MAJOR-6] `isCreditableInvoiceSource()` does not check that the document IS an invoice, despite its name.**
`apps/api/app/Modules/Document/Domain/Document.php:579-587` only matches on status. `CreditNoteService::createCreditNote():818` / `createLineBasedCreditNote():944` load any `documents` row, and the request validator is `ScopedExists::tenantAndCompany('documents', …)` (`CreditNoteController.php:141`) — type-blind. A posted delivery note, return note or **supplier invoice** therefore passes the guard and mints a customer credit note draft (the direction check only bites later, at allocation: `DocumentAllocationStateGuard::assertDirectionMatchesPartner`). `RefundService.php:882-884` does check `type !== DocumentType::Invoice`. The hole is pre-existing (`isPosted()` had it), but the new helper's NAME now asserts a check it does not perform.
*Fix*: `return $this->type === DocumentType::Invoice && in_array($this->status, [Posted, Paid], true);`

### IMPORTANT

**[IMPORTANT-1] The `creditable` filter is an unvalidated magic string.**
`apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:208` — `if ($request->query('creditable') === 'true')`. No validation rule, no enum, no boolean cast: `creditable=1`, `creditable=TRUE`, `?creditable` all fail **open** to the unfiltered list (which includes drafts). CLAUDE.md rule 9's spirit and the repo's own `ScopedExists`/enum-validation habit both point the other way. It also does not exclude `fully_credited` invoices, so the picker offers invoices whose credit attempt 422s on the headroom guard (`CreditNoteService.php:845-850`).

**[IMPORTANT-2] 'all' mode has no empty-lines guard — the F-STG-4 symptom survives as a race.**
`apps/web/src/features/documents/CreateCreditNotePage.tsx:242-260` validates `selectedLineIds.size === 0` only for `partial`. `lines` is filled asynchronously by the `/invoices/{id}` query (`:112-154`), so submitting before it resolves posts `lines: []` → `lines … min:1` (`CreditNoteController.php:153`) → 422 again. Add `if (lineMode === 'all' && lines.length === 0) return` (or disable submit until the invoice detail is loaded).

**[IMPORTANT-3] The blank-line contract the new unit test pins is one the backend rejects.**
`apps/web/src/features/documents/creditNotePayload.ts:88` emits `unit_price: String(line.unit_price)` → `''` for a blank line, and `creditNotePayload.test.ts:96-104` asserts exactly that. But `lines.*.unit_price` is `required|string|regex` (`CreditNoteController.php:166`) and `lines.*.description` is `required` (`:164`) — an empty string fails `required`. So the standalone path still 422s for an unpriced line, only with a different message. The DocumentForm path has a client-side guard for precisely this (`findBlankPriceLineIds`, `apps/web/src/features/documents/linePayload.ts:155-170`); the credit-note page has none.
*Fix*: reuse `findBlankPriceLineIds` on the credit-note page and make the test assert the refusal, not the payload.

**[IMPORTANT-4] The operator-chosen `issue_date` is silently discarded.**
The page collects and validates it (`CreateCreditNotePage.tsx:38,402-419`) and `buildCreditNotePayload` sends it (`creditNotePayload.ts:76`), but neither branch of `CreditNoteController::store()` validates it and both services hard-set `'document_date' => now()` (`CreditNoteService.php:872` and `:1004`). On a fiscal document a silently-ignored date is a defect of its own class — either honour it or remove the field.

**[IMPORTANT-5] Rule 19: `String()` over float-derived money.**
`creditNotePayload.ts:88` stringifies values the page produced with `parseFloat` (`CreateCreditNotePage.tsx:136-138`: `unit_price: parseFloat(line.unit_price)`, same for `tax_rate`, `line_total`). Switching credit mode does not reset `lines` (`:309`, `:338`), so an invoice-loaded, float-derived line can be submitted through the customer branch. The parseFloat lines are pre-existing (ESLint reports them as `precision/no-parsefloat-on-money` **warnings**, `:136-138`, `:584`), but the PR now depends on them for wire values.
*Fix*: keep the API's decimal strings in the page's `lines` state (`quantity`, `unit_price`, `tax_rate` are already strings on the wire).

**[IMPORTANT-6] Stock side — verified ABSENT by design, but the surface says "return".**
Posting a credit note writes GL only: no writer under `apps/api/app/Modules/Inventory` references credit notes (only `SupplierGoodsReturnNoteService`/`Line`), and `InvoicePosted` has exactly two listeners (`apps/api/app/Providers/EventServiceProvider.php:95-97`) — GL + workshop vehicle context; the historical `PostCOGSOnInvoice` is gone (COGS is movement-driven). Goods coming back is a separate `ReturnNote` (`ReturnNoteService::receiveStockBack()`), which is the correct document-per-action split. **However** the page defaults `reason: 'return'` (`CreateCreditNotePage.tsx:104,433`) and this PR makes the whole-invoice 'all' path work for the first time, with no goods-disposition prompt and no link to a return note — unlike the guided cancel flow (`RefundService::cancelInvoice()` with `ReturnDecisionData`). For the pharmacy launch case (customer brings a box back against a cash-paid ticket) the operator will book the money and leave the stock out. Recommend an explicit "goods returned? → create return note" affordance before this flow is promoted.

**[IMPORTANT-7] The e2e spec mocks the thing under test.**
`apps/web/e2e/credit-note-creation.spec.ts:71-82` intercepts `POST /api/v1/credit-notes` and asserts the request body — the same contract the unit test already pins. Nothing exercises the FE payload against the real validator, which is exactly where F-STG-4 lived. Not harmful, but it must not be counted as end-to-end proof.

### MINOR

- `apps/web/src/features/documents/creditNotePayload.ts:18-25` — `CreditNoteSourceLine` restates a subset of `DocumentLine` (`DocumentLineEditor.tsx:196`); the page passes `DocumentLine[]` into it. A `Pick<DocumentLine, …>` would keep one surface.
- `apps/api/tests/Feature/Document/CreditNotePaidInvoiceSourceTest.php:24,42` — `AssertsApiValidation` imported/used but no assertion from it is called.
- Pre-existing red, NOT this PR: `CreditNoteAllocationTest::it_creates_credit_note_allocation_when_posting` errors identically on the merged tree and on base `dev` (`DomainException: … has no document_number`). Verified on both trees.

---

## Verbatim test output (merged tree `08067317f`)

### PHPUnit — SQLite (`./vendor/bin/phpunit <path>`)
```
tests/Feature/Document/CreditNotePaidInvoiceSourceTest.php
....                                                                4 / 4 (100%)
OK (4 tests, 7 assertions)

tests/Feature/Document/CreditNoteIntegrationTest.php
...............                                                   15 / 15 (100%)
OK, but there were issues!
Tests: 15, Assertions: 68, PHPUnit Deprecations: 15.

tests/Unit/Document/CreditNoteServiceTest.php
..........                                                        10 / 10 (100%)
Tests: 10, Assertions: 36, PHPUnit Deprecations: 10.

tests/Feature/Document/CreditNoteMoneyLaneTest.php
.............                                                     13 / 13 (100%)
OK (13 tests, 109 assertions)

tests/Feature/Document/CreditNoteTenantIsolationTest.php + CreditNoteAllocationTest.php
1) Tests\Feature\Document\CreditNoteAllocationTest::it_creates_credit_note_allocation_when_posting
DomainException: Document … (credit_note, confirmed) has no document_number: a number is
allocated on the first transition out of Draft, and this operation requires a numbered document.
ERRORS! Tests: 21, Assertions: 54, Errors: 1, PHPUnit Deprecations: 10.
   → SAME error on base dev fa000edc3 (main checkout): Tests: 1, Errors: 1. PRE-EXISTING.
```

### PHPUnit — PostgreSQL (`DB_DATABASE=autoerp_test_g214 php artisan test -c phpunit-pgsql.xml <path>`)
```
PASS  Tests\Feature\Document\CreditNotePaidInvoiceSourceTest
  ✓ amount based credit note can be created from a paid invoice        21.29s
  ✓ line based credit note can be created from a paid invoice           1.73s
  ✓ credit note still rejects a draft source invoice                    1.86s
  ✓ creditable filter returns posted and paid but not draft invoices    2.32s
Tests: 4 passed (7 assertions)  Duration: 27.27s

PASS  Tests\Feature\Document\CreditNoteIntegrationTest
Tests: 15 passed (68 assertions)  Duration: 55.81s

PASS  Tests\Unit\Document\CreditNoteServiceTest
Tests: 10 passed (36 assertions)  Duration: 109.55s

PASS  Tests\Feature\Document\CreditNoteMoneyLaneTest
  ✓ over credit allocation clamps balance due at zero                   2.67s
Tests: 13 passed (109 assertions)  Duration: 45.67s

PASS  Tests\Feature\Document\CreditNoteTenantIsolationTest
Tests: 11 passed (26 assertions)  Duration: 73.56s
```

### Vitest (`./node_modules/.bin/vitest run <file>`)
```
src/features/documents/__tests__/creditNotePayload.test.ts          4 passed (4)
DocumentLineEditor.test.tsx + .purchasePriceDefault + .quantityStep  54 passed (54)
DocumentForm.blankUnitPrice + DocumentForm.payload
  + CreateNotePages.quantityDisplay + ReturnCreditNotePages.tenantScope   39 passed (39)

src/components/molecules/pickers/InvoiceSearchSelect.test.tsx      1 failed | 16 passed (17)
  × InvoiceSearchSelect > filters invoices by posted status
    → expected "spy" to be called with arguments: [ StringContaining "status=posted" ]
      Received: 1st spy call: "/invoices?per_page=10&creditable=true"
  → base dev fa000edc3: 17 passed (17). REGRESSION CAUSED BY THIS PR.
```

### Static gates
```
pint --test (4 touched PHP files)                 {"result":"pass"}
phpstan (CreditNoteService, Document, InvoiceController)   [OK] No errors
eslint (5 touched web files)                      0 errors, 113 warnings (all pre-existing lines:
                                                  colorClasses deprecation, Number()/parseFloat on
                                                  CreateCreditNotePage:136-138,584)
tsc --noEmit (merged tree)                        4 errors — all creditNotePayload.test.ts (see BLOCKER-1)
tsc --noEmit (base dev fa000edc3)                 0 errors, exit 0
```

## Merge with Phase A T6 (`DocumentLineEditor.tsx`)
Verified the auto-merge kept BOTH behaviours: the local-dev pricing debounce (`useDebouncedValue(pricingContextLines, 250)` at `:388`, the debounced key/`enabled`/body at `:389-412`, and the deliberate no-`keepPreviousData` comment) survives intact, and the PR's only change is the blank-line default at `:562-570` (`unit_price: 0` → `''`). The claim "Total-mode logic untouched" holds — `git diff dev HEAD` for this file is 6 added / 1 removed lines.
The `''` default is consistent with the W2-6 lane already on dev: `isBlankMoney` (`:70`), `moneyInputValue` (`:56`), `decimalValue('') → '0'` (`:46-49`), and the DocumentForm split (`linePayload.ts:96-122` submit vs `:125-151` autosave coercion) already treat a blank price as a first-class state. All 54 editor tests pass. Note `src/components/documents/DocumentLineEditor.tsx` is a 2-line re-export shim of the same component, so the credit-note page (`CreateCreditNotePage.tsx:22`) does get the change.

## Deploy notes
- No migrations, no seeders, no queue/Horizon changes, no route changes, no PHP DTO changes (so no `typescript:transform` needed).
- `GET /invoices?creditable=true` is additive and backward compatible; existing `status` / `has_balance` filters are untouched server-side.
- FE behaviour change affects TWO pages (credit note **and** return note) because the picker is shared — see MAJOR-4.
- Nothing here touches stock, POS, or fiscal numbering; the credit-note hash chain and numbering path are unchanged (source-invoice status is not read anywhere in `DocumentPostingService`).

## Could not verify
- **The F-STG-4 finding record itself.** `grep -rn "F-STG-4"` across `docs/` and the whole repo returns nothing; the DEV-QA/staging registry is not in the repo. The only statement of the requirement is the PR body, including the "owner-confirmed scope" for Paid invoices — I could not verify that ruling from any file.
- **Red-without-fix (TDD) claim** for `CreditNotePaidInvoiceSourceTest`: falsifiable by construction (with `isPosted()` the two positive cases throw → 422, and without the filter the draft would appear), but I did not execute it against the pre-fix code (no code modification allowed in this gate).
- **Empirical proof of the zero-amount allocation row and the NULL-cache branch of MAJOR-1**: derived from `CreditNoteService.php:1303-1327` + the PG trigger, not executed — no existing test posts a credit note against a Paid invoice, and I did not add one.
- Playwright e2e was not executed (mocked-API harness, requires a running vite/e2e stack).

## What to fix before merge
Green the two red gates (BLOCKER-1 typecheck, BLOCKER-2 picker test), clamp on the computed outstanding instead of the `balance_due` cache (MAJOR-1) with a paid-invoice create→confirm→post seam test asserting the allocation row AND the journal entry (MAJOR-5), and either finish the relaxation on the invoice-detail surfaces or narrow it — with an owner ruling on where the resulting customer credit is meant to live (MAJOR-2/3).
