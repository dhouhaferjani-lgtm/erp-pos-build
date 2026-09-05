# Gate r2 — PR #214 `fix(documents): repair credit-note creation on both paths (F-STG-4)`

- **PR**: otospexsolutions/erp #214 · author `dhouhaferjani-lgtm` · base `dev`
- **Reviewed tree**: worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-214`, branch `gate/pr-214`, head **`cff7df47a`** = local dev `fa000edc3` + PR #214 (`0fb560f33`, `551f678e2`, merge `08067317f`) + 7 fix-round commits. `git status --porcelain` empty.
- **Answers**: fix round 1 handback `docs/superpowers/reviews/2026-09-05-dhouha-pr-214-fix-round-1-handback.md`, against gate r1 `docs/superpowers/reviews/2026-09-05-dhouha-pr-214-gate-r1.md`.
- **Reviewer**: stock↔GL interaction gate (adversarial, code-grounded). **No code modified. Nothing merged.**
- **PG lane**: private DB `autoerp_test_g214b` on 127.0.0.1:5433 — created, used, **dropped**.
- **Policy boundary excluded from this verdict** (owner ruling pending): whether Paid invoices may be credited at all and what consumes the resulting credit (r1 MAJOR-2 / MAJOR-3), and whether `issue_date` is honoured or removed (r1 IMPORTANT-4).

---

## VERDICT: spec ✅ (within the gate's scope) · quality **APPROVED — CONDITIONAL**

Every finding r1 raised inside this gate's scope is **verified fixed by execution, not by claim**. Both r1 BLOCKERs are green on the reviewed tree: `tsc --noEmit` exits 0 with no output (was 4 × TS4111), and `InvoiceSearchSelect.test.tsx` is 20/20 with the original `status=posted` assertion restored unchanged (was 1 failed | 16 passed). The five PHPUnit suites reproduce the handback's counts **exactly**, on SQLite **and** on PostgreSQL (8/46, 15/68, 10/36, 13/109, 11/26). PHPStan L8 on the four touched `app/` files: `[OK] No errors`. Pint: `{"result":"pass"}`. ESLint on the seven touched web files: **0 errors**.

The seam is now genuinely tested. `CreditNotePaidInvoiceSourceTest::test_posting_against_a_settled_invoice_with_a_blind_cache_allocates_zero_and_still_books_the_gl` (`apps/api/tests/Feature/Document/CreditNotePaidInvoiceSourceTest.php:412-506`) drives create → confirm → **post** through the real HTTP path and asserts **both sides**: the `credit_note_allocations` row clamped to 0 (`:449-454`), the invoice's computed outstanding still 0 (`:457-462`), and the journal entry from the real posting path with Cr 411 = 1200.000 carrying `partner_id`, Dr 701 = 1000.000, Dr 4457 = 200.000, balanced (`:466-505`). That is the "assert BOTH sides of the seam" standard, met.

**Why CONDITIONAL, not unconditional:** three residuals remain that are *not* the owner's policy question and are not fixed — one of them is operator-visible on the exact flow this PR ships (NEW-1: the picker lists invoices the create call will 422). None is a Critical/BLOCKER. My merge recommendation is below.

---

## r1 finding → r2 status

| r1 ID | Finding | Fix-round claim | r2 verdict (verified how) |
|---|---|---|---|
| **BLOCKER-1** | `pnpm typecheck` red, 4 × TS4111 | fixed, union type | ✅ **FIXED, executed.** `tsc --noEmit` → `TSC_EXIT=0`, 0 lines of output. Union declared at `creditNotePayload.ts:59-78`; source type is now `Pick<DocumentLine,…>` at `:32`. Rule 7 check below. |
| **BLOCKER-2** | picker test red | fixed, opt-in prop | ✅ **FIXED, executed.** `InvoiceSearchSelect.test.tsx` **20 passed (20)**. Original assertion restored; 3 new cases added. |
| **MAJOR-1** | clamp read the `balance_due` cache with `?? total` | fixed, clamps on `outstandingBalance()`, falsifying test | ✅ **FIXED.** `CreditNoteService.php:1324-1326`. Falsification confirmed by reading, not just by the handback's transcript (see §MAJOR-1 below). Green on SQLite and PG. |
| **MAJOR-2** | credit has no consumption/refund/aging path | ⛔ owner | ⏸️ **OUT OF SCOPE** by the stated policy boundary. Restated in §Owner questions with operator consequences. Unchanged in code. |
| **MAJOR-3** | 5 other Posted-only surfaces + competing predicates | ⛔ owner | ⏸️ **Policy half OUT OF SCOPE.** The **`fully_credited` half is NOT policy** and is now a live operator-visible defect → re-raised as **NEW-1**. |
| **MAJOR-4** | shared picker silently changed the return-note flow | fixed with BLOCKER-2 | ✅ **FIXED.** `git diff dev HEAD -- CreateReturnNotePage.tsx` is **empty**; the page passes no `sourceFilter` (`CreateReturnNotePage.tsx:442`), so it takes the `'payable'` default, which is byte-identical (`InvoiceSearchSelect.tsx:83`). |
| **MAJOR-5** | new backend test asserted almost nothing | fixed, 8 tests / 46 assertions | ✅ **FIXED, executed.** 8/46 on SQLite and PG. Data asserted, seam asserted, second-company asserted. Detail + one caveat in §MAJOR-5. |
| **MAJOR-6** | helper did not check the type | fixed, two layers | ✅ **FIXED.** `Document.php:590-594`. The deviation is **ACCEPTED** — reasoning in §MAJOR-6 deviation ruling. |
| **IMPORTANT-1** | `creditable` unvalidated magic string | fixed | ✅ **FIXED.** `InvoiceController.php:206-208` + `:222`. Fails closed (422). Second half (`fully_credited`) explicitly deferred → **NEW-1**. |
| **IMPORTANT-2** | 'all' mode had no empty-lines guard | fixed, translated | ✅ **FIXED.** `CreateCreditNotePage.tsx:271-274`; key in `en/sales.json:809` + `fr/sales.json:809`; `ar` verified below. |
| **IMPORTANT-3** | FE pinned a blank-price contract the backend rejects | fixed, shared guard | ✅ **FIXED for price** — `CreateCreditNotePage.tsx:285-291` reuses `findBlankPriceLineIds` + the shared key. **Sibling field not covered** → **NEW-2**. |
| **IMPORTANT-4** | `issue_date` silently discarded | ⚠️ reported | ⏸️ **OUT OF SCOPE** (owner). Independently re-verified: `issue_date` appears in **none** of the three rule sets at `CreditNoteController.php:137-183`. |
| **IMPORTANT-5** | `String()` over float-derived money | fixed | ✅ **FIXED on the wire path.** No `parseFloat`/`Number(`/`(float)`/`floatval`/`toFixed` on any **added** line of the whole diff (verified by grepping `git diff dev HEAD` for `^+`; the only three hits are the words inside comments). 10 `precision/no-parsefloat-on-money` warnings remain on `CreateCreditNotePage.tsx` — all display-only, enumerated in §Static gates. |
| **IMPORTANT-6** | surface says "return" but no goods disposition | ⛔ owner | ⏸️ **OUT OF SCOPE.** Stock side re-verified ABSENT and unchanged — §Stock side. |
| **IMPORTANT-7** | e2e mocks the thing under test | not actioned | ⏸️ Accepted as reported. The FE payload is now exercised against the real validator on the backend side. Stale comment → **NEW-4**. |
| **MINOR 1** | `CreditNoteSourceLine` restated `DocumentLine` | fixed | ✅ `creditNotePayload.ts:32-35` is a `Pick<>`. |
| **MINOR 2** | `AssertsApiValidation` imported, unused | fixed | ✅ used at `CreditNotePaidInvoiceSourceTest.php:317` and `:355`. |
| **MINOR 3** | `CreditNoteAllocationTest` pre-existing red | unchanged | ✅ **CONFIRMED PRE-EXISTING** by running it on the **unmodified main checkout** at `dev` `fa000edc3` — identical error. Transcripts below. |

---

## Claim-by-claim verification

### 1. BLOCKER-1 — typing (rule 7, rule 3)

**Rule-7 check: no generated DTO is being shadowed.** `grep -n -i "creditnote" packages/shared/types/generated.d.ts` returns exactly two lines — `CreditNoteReason` (`:862`) and `SupplierCreditNoteReason` (`:1990`). There is **no generated credit-note REQUEST DTO**, so the two wire shapes at `apps/web/src/features/documents/creditNotePayload.ts:59-78` are not a hand-rolled duplicate of anything generated. The lane's claim is **confirmed**.

The `Pick<DocumentLine, 'id'|'product_id'|'description'|'quantity'|'unit_price'|'tax_rate'>` at `creditNotePayload.ts:32-35` derives from `apps/web/src/features/documents/components/DocumentLineEditor.tsx:183-212`, the editor's own exported type that the page already passes in — a derivation, not a second surface. Correct direction of travel.

`tsc --noEmit` on the reviewed tree: **exit 0, zero output**.

*One rule-7 residual → NEW-3*: `creditNotePayload.ts:40` and `:83` type `reason` as bare `string`, while a generated union `CreditNoteReason` exists (`generated.d.ts:862`) and the page's own zod schema already narrows to exactly those six literals (`CreateCreditNotePage.tsx:40-47`). The shared type is wider than both.

### 2. BLOCKER-2 / MAJOR-4 — the shared picker

- Prop declared `sourceFilter?: 'payable' | 'creditable' | undefined` at `InvoiceSearchSelect.tsx:59`; destructured with the default at `:92`; resolved from a frozen data table at `:82-90`, read at `:94`.
- **Default `'payable'` is byte-identical to pre-PR**: `{ statusFilter: 'posted', additionalFilters: { has_balance: 'true' } }` (`:83`) vs the pre-PR literals `statusFilter: 'posted'` / `additionalFilters: { has_balance: 'true' }`. The conditional spread at `:102` sets the same key with the same value; `statusFilter` and `additionalFilters` are distinct keys so ordering cannot shadow. **Verified byte-identical.**
- **Return-note page unchanged**: `git diff dev HEAD -- apps/web/src/features/documents/CreateReturnNotePage.tsx` produces **no output**. It mounts the picker at `:442` with no `sourceFilter`.
- **Credit-note page opts in**: `CreateCreditNotePage.tsx:409` `sourceFilter="creditable"`.
- The only two production consumers are those two pages (`grep -rn "InvoiceSearchSelect" --include='*.tsx' src/`); the other three hits are test mocks.
- **No query-cache cross-contamination between the two modes**: `DocumentSearchSelect.tsx:105` builds `tenantScopedKey([config.queryKey, searchQuery, partnerId, config.additionalFilters])` — the two modes differ in `additionalFilters` (`{has_balance:'true'}` vs `{creditable:'1'}`), so the keys differ. (Latent, pre-existing, not triggered here: `config.statusFilter` is **not** in the key.)

Tests run **by file**:
- `InvoiceSearchSelect.test.tsx` → **20 passed (20)**.
- There is no `CreateCreditNotePage.test.tsx` / `CreateReturnNotePage.test.tsx` in the repo — confirmed. Those pages are covered by `CreateNotePages.quantityDisplay.test.tsx` and `ReturnCreditNotePages.tenantScope.test.tsx`, both run green. **Caveat, stated not hidden:** both of those files `vi.mock` `InvoiceSearchSelect` (`ReturnCreditNotePages.tenantScope.test.tsx:62`, `CreateNotePages.quantityDisplay.test.tsx:54`), so the return-note *contract* is pinned at the component level plus an empty page diff, not by a page-level test. Given the page diff is provably empty, that is sufficient.

### 3. MAJOR-1 — the clamp

`apps/api/app/Modules/Document/Application/Services/CreditNoteService.php:1324-1326`:
```php
$invoice->loadMissing(['allocations', 'creditsAgainstDocument']);
/** @var numeric-string $currentBalance */
$currentBalance = $invoice->outstandingBalance($scale);
```
used by the clamp at `:1340`. `allocateCreditNote()` has exactly one caller (`CreditNoteController.php:383-391`, at post), so **both** credit paths (`createCreditNote()` `:826`, `createLineBasedCreditNote()` `:952`) reach the allocation through this one site. **Both paths covered by one fix — confirmed.**

**Does `outstandingBalance()` read the same cache? Partly — and that is correct, not a hole.** `Document.php:869-899`: a **non-NULL** `balance_due` is returned as authoritative (`:871-873`); only the **NULL** case is recomputed as `total − Σpayment_allocations − Σcredit_note_allocations` (`:880-899`). The docblock (`:842-861`) states why: `ArApOpeningService` legitimately writes a partial `balance_due` on migrated historical documents with no allocation rows, and recomputing there would overstate the receivable. So the fix does **not** reintroduce the r1 hole — the r1 hole was the `?? $invoice->total` fallback on NULL, which is exactly the branch that now recomputes.

**Falsification, verified by reasoning against the code (no edits made):** with the pre-fix expression `(string) ($invoice->balance_due ?? $invoice->total ?? '0')` and the fixture at `CreditNotePaidInvoiceSourceTest.php:414-427` (Paid invoice, real `payment_allocations` row of 1200.000 at `:417-421`, cache blanked to NULL by raw DML at `:426` and asserted NULL at `:427`), `$currentBalance` would fall through to `total = '1200.000'`; `bccomp('1200.000','1200.000',3) === 0`, so the clamp at `:1340` takes `$creditNoteTotal` = 1200.000; the assertion at `:450-454` (`bccomp(amount,'0',3) === 0`) then fails with `1`. That is precisely the failure the handback recorded on both engines. **The test is falsifying by construction.**

I also checked that the blind-cache fixture is a *reachable* production state and not a straw man: every writer that reaches `Paid` goes through `DocumentStatusService::markPaid()` (`:434-436`; the only edge, per `:425`), and its callers either insert `payment_allocations` (so the trigger writes the cache) or pass an explicit `balance_due` (`MultiPaymentService.php:163,332`, `CloseInvoiceWithToleranceService.php:173`). `DocumentPostingService::settleIfFullyPrepaid()` (`:266-289`) even refuses to mark Paid without allocation rows (`:279-287`). So a Paid-with-NULL-cache invoice arises from the documented legacy/blind population (`AgedReceivablesService.php:199-207` — 165 invoices on the demo tenant), which is exactly what the fixture models. Good fixture.

**Both sides of the seam:** stock — nothing; GL — the allocation writes only the sub-ledger row (`CreditNoteService.php:1346-1351`), the GL is written once by `AccountingService::createCreditNoteGLEntries()` and is **not** clamped (it books the full Cr 411), which is correct: the customer is owed the money even when no invoice absorbs it. The test asserts exactly that divergence-free shape at `:449-505`. Note the zero allocation row also *heals* the blind cache — the trigger recomputes `balance_due = 1200 − 1200 − 0 = 0`, which `:457-462` confirms.

Green on SQLite and on PG (live triggers).

### 4. MAJOR-6 deviation — ruling: **ACCEPTABLE**

The lane put the type condition on the exists rule (`CreditNoteController.php:156-161`, `ScopedExists::tenantAndCompany(...)->where('type', DocumentType::Invoice->value)`) instead of a closure rule calling `isCreditableInvoiceSource()`.

**I rule this acceptable, and the reason is verified in the repo, not taken on trust.** A closure rule would swallow the STATUS refusal into Laravel's validation envelope and break the message contract pinned at `apps/api/tests/Feature/Document/CreditNoteIntegrationTest.php:311-318` (`error.code = VALIDATION_ERROR`, `error.message = 'Credit notes can only be created for posted or paid invoices'`) — I read that test; the pin is real. The chosen shape gets the required outcome declaratively, from the enum (rule 9 — no magic string: `DocumentType::Invoice->value`), while status refusals keep flowing through the service exception path. `ScopedExists::tenantAndCompany()` returns `Illuminate\Validation\Rules\Exists` (`app/Shared/Presentation/Validation/ScopedExists.php:27-36`), so `->where()` chains legitimately.

Drift risk is low because the domain helper enforces the same type condition inside the transaction (`Document.php:591-593`), which is the guard every non-HTTP caller passes (`CreditNoteService.php:826`, `:952`).

**Are there now FOUR creditability predicates?** I enumerated them by grep, and the honest answer is **six, and the fix round added none of the divergent ones**:

| # | Expression | Location | Rule expressed |
|---|---|---|---|
| 1 | `Document::isCreditableInvoiceSource()` | `Document.php:590-594` | type + (Posted∨Paid) |
| 2 | exists rule `->where('type', …)` | `CreditNoteController.php:159-160` | type only — **partial duplicate of #1's type half, added by this fix round** |
| 3 | `whereIn('status',[Posted,Paid])` | `InvoiceController.php:222` | status only, in SQL — **from the original PR head, not the fix round** |
| 4 | `RefundService::canCreditInvoice()` | `RefundService.php:1228-1243` | type + status + **not `fully_credited`** — pre-existing, **divergent** |
| 5 | inline in `RefundService::createFullCreditNote()` | `RefundService.php:882-894` | same as #4, restated — pre-existing |
| 6 | `InvoiceToCreditNoteConverter` | `…/Converters/InvoiceToCreditNoteConverter.php:69-77` | type + **`isPosted()` only** — pre-existing, **now divergent from #1** |

Plus the FE `canCreateCreditNote()` (`apps/web/src/types/creditNote.ts:214-233`). #2 is a benign duplication of a single enum comparison. #4 and #6 are the real one-surface debt, and #4 is now *operator-visible* → **NEW-1**. #6's divergence is the owner's MAJOR-3 question.

### 5. MAJOR-5 — the hardened test file

`apps/api/tests/Feature/Document/CreditNotePaidInvoiceSourceTest.php`, 8 tests / 46 assertions, executed green on both engines.

**Are the asserted accounts "right", and are they hardcoded?** The production path resolves accounts **by purpose, never by code**: `AccountingService::createCreditNoteGLEntries()` calls `findAccountByPurpose(…, SystemAccountPurpose::CustomerReceivable / ProductRevenue / ServiceRevenue / VatCollected)` (`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:764-778`). The test **matches that**: it looks the accounts up by `system_purpose` (`:472-480`) and asserts the legs against the resolved `account_id`s (`:483`, `:490`, `:495`). **No account-code literal appears in any assertion** — the codes at `:157-163` are fixture labels only. The country-seeded-settings rule is respected.

**Caveat, stated:** the fixture hand-seeds a five-account mini-chart (`seedAccounts()`, `:155-176`) on an **FR/EUR** company (`:88-98`), not the TN chart and not the real country-defaults seeder. That is fine for what the test asserts (purpose→leg mapping), but it does **not** prove any real country chart supplies those four purposes. Not a defect of this PR; recording it so nobody reads the test as chart coverage.

**Is the second-company case real?** Yes. `test_creditable_filter_is_scoped_to_the_current_company` (`:362-395`) creates a genuine second `Company` (`:100-110`) with its own `UserCompanyMembership` and its own seeded chart (`:124-131`), its own customer (`:142-147`), and asserts **both directions** — company A's list contains `mine` and not `theirs` (`:380-381`), then switches via the real mechanism, the `X-Company-Id` header (`:387`), and asserts B sees `theirs` and not `mine` (`:393-394`). That is rule-22 second-of-everything done properly, not a shell company.

The seam test's GL assertions are taken from the **real posting path** (`POST /credit-notes/{id}/confirm` then `/post`, `:440-445`), not from a fixture entry — which is what makes it count as seam coverage.

### 6. IMPORTANT-1/2/3/5

- **I-1**: `InvoiceController.php:206-208` `$request->validate(['creditable' => ['sometimes','boolean']])`, flag read via `$request->boolean('creditable')` at `:222`. Fails **closed** — `creditable=yeah` is a 422, pinned by `CreditNotePaidInvoiceSourceTest.php:348-356`. Wire-compat note: Laravel's `boolean` rule accepts only `true,false,0,1,'0','1'`, so **`creditable=true` now 422s**; `grep -rn "creditable"` across `apps/` and `packages/` shows no other sender (only the picker at `InvoiceSearchSelect.tsx:86`, which sends `'1'`) — the filter was introduced by this same PR, so there is no external consumer to break. Deploy note stands.
- **I-2**: `CreateCreditNotePage.tsx:271-274` refuses `lineMode === 'all' && lines.length === 0` with a translated message. Key present in `en/sales.json:809` and `fr/sales.json:809`. **`ar` wiring verified**: `src/lib/i18n.ts:144` imports `arSales`, and the `ar` resource block at `:291-293` spreads `...enSales` **before** `...arSales`, so the new key resolves to the English string in `ar` rather than rendering a raw key; independently, `fallbackLng: 'en'` at `:483`. The `ar` bundle carries **no** `creditNotes.form.*` keys at all (`creditNotes` has only `postingMarker`), so leaving `ar` alone is consistent with the existing state, not a new gap. **Claim verified.**
- **I-3**: `CreateCreditNotePage.tsx:285-291` calls the **shared** `findBlankPriceLineIds` (`linePayload.ts:164-176`) and raises the **shared** message key `sales:documents.errors.unitPriceRequired` — the same key `DocumentForm.tsx:712` uses (verified: the key exists in `en`, `fr` **and** `ar`). Offending lines are marked via `invalidLineIds` (`:649`). One surface, honoured. Wire-side belt at `creditNotePayload.ts:143`. Residual sibling field → **NEW-2**.
- **I-5**: no `parseFloat`/`Number(`/`(float)`/`floatval`/`toFixed` on any **added** line anywhere in the diff. `CreateCreditNotePage.tsx:145-147` now keeps the API's decimal strings. The 10 remaining `precision/no-parsefloat-on-money` warnings on that file are at `:158, :191, :210, :211, :212, :602, :617, :620 ×3` — all display/preview math, all pre-existing lines, none on the wire path. The three r1 named at `:136-138` are gone. **No float touches the GL/allocation seam** (rule 19 / dimension 10): green.

### 7. Regressions — full transcripts in §Verbatim below

All five PHPUnit suites match the handback counts **exactly**, on SQLite and PG. Vitest 63/63 and 97/97 reproduced by running the files. PHPStan/Pint/ESLint/tsc as stated. `CreditNoteAllocationTest::it_creates_credit_note_allocation_when_posting` confirmed **identically red on the unmodified main checkout at `dev` `fa000edc3`** — not this PR.

### 8. Stock side — verified ABSENT and unchanged

- `git diff dev HEAD --name-only | grep -i inventory` → **NONE**. No writer to `stock_movements`, `stock_levels` or `inventory_batch_stock` anywhere in the diff.
- `InvoicePosted` still has exactly two listeners (`app/Providers/EventServiceProvider.php:95-98`): `InvoicePostedListener` (GL) and `WriteDocumentVehicleContextForWorkOrderInvoice`. File not touched by this branch.
- The only Inventory files referencing credit notes are `SupplierGoodsReturnNoteService.php` / `SupplierGoodsReturnNoteLine.php` — supplier-side, unrelated to customer credit notes. Unchanged.
- **Document-per-action split intact**: money comes back on the `CreditNote`, goods come back on a separate `ReturnNote`. Correct.
- **`reason: 'return'` default is reported, not changed**: still `CreateCreditNotePage.tsx:108`; the only `reason` line in the page's diff is the removal of the old inline `reason: data.reason` assignment, replaced by the same field inside the builder (`creditNotePayload.ts:104`). **Claim verified.**

---

## New findings (this round)

### IMPORTANT

**[NEW-1] The `creditable` picker lists invoices that the API's own `/can-credit` endpoint calls non-creditable, and whose create call 422s.**
`apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:222` filters on status only. `RefundService::canCreditInvoice()` (`app/Modules/Document/Domain/Services/RefundService.php:1228-1243`) additionally refuses `payload['fully_credited'] === true`, and it is **exposed as a live endpoint** — `GET /invoices/{invoice}/can-credit` (`app/Modules/Document/Presentation/routes.php:240-242` → `RefundController::checkCreditable()`, `:376`). An already fully-credited invoice therefore appears in the new picker, and the create attempt fails on the headroom guard — `CreditNoteService.php:845-850` (amount path) and `:1096-1099` (line path), both `'Total credit notes would exceed invoice total'` — surfaced to the operator as an opaque toast (`CreateCreditNotePage.tsx:246-248` toasts `error.message`).
*Why it matters*: the flow this PR ships offers a choice that cannot be completed, and two API surfaces disagree about the same noun (rule 22, one surface per concept). r1 named this inside MAJOR-3/IMPORTANT-1; the fix round explicitly deferred it as "entangled with the owner ruling" — it is **not**: excluding fully-credited invoices is correct under either policy answer.
*Other side of the seam verified*: no GL or stock consequence — the create is refused before any document exists, so nothing is written on either side. This is a UX/one-surface defect, not a money defect.
*Fix*: exclude fully-credited invoices from the `creditable` filter (or, better, express the list through one predicate shared with `canCreditInvoice()`), and add a case to `test_creditable_filter_returns_posted_and_paid_but_not_draft_invoices`.

**[NEW-2] The standalone path's next 422 is `description`, and it has no client-side guard — the same class IMPORTANT-3 just closed for price.**
`CreditNoteController.php:179` — `lines.*.description` is `['required','string','max:500']`. `DocumentLineEditor::handleAddBlankLine()` creates a line with `description: ''` (`apps/web/src/features/documents/components/DocumentLineEditor.tsx:563`), and the builder forwards it verbatim (`creditNotePayload.ts:146`). An operator who prices a free-text line but leaves the designation empty gets `The lines.0.description field is required` as an opaque toast — precisely the "422 about a field the operator cannot see" that IMPORTANT-3 was raised to eliminate. (Product-picked lines are safe: `DocumentLineEditor.tsx:507` sets `description: product.name`.)
Pre-existing in origin, but **newly reachable**: before this PR the standalone path failed earlier, on `unit_price`, so nobody ever got this far.
*Other side*: no GL, no stock — the request is refused at validation.
*Fix*: extend the page's pre-submit guard to blank designations (same shape as `findBlankPriceLineIds`, same `invalidLineIds` marking), or make `description` nullable server-side and default it from the product.

### MINOR

**[NEW-3] Rule 7 — the new shared type widens `reason` to `string` where a generated union exists.**
`apps/web/src/features/documents/creditNotePayload.ts:40` and `:83` declare `reason: string`. `CreditNoteReason` is generated (`packages/shared/types/generated.d.ts:862`) and the page's zod schema already narrows to the same six literals (`CreateCreditNotePage.tsx:40-47`). Note this makes **three** FE expressions of one enum (generated, `src/types/creditNote.ts:15-24`, the zod list) — the first two are pre-existing; this file should consume the generated one rather than widen.
*Fix*: `import type { CreditNoteReason } from '@shared/types/generated'` and type the field with it.

**[NEW-4] Two definitions of "unpriced" now exist, and one stale comment.**
(a) `creditNotePayload.ts:143` strips on `!isBlank(line.unit_price)` while the page's guard `findBlankPriceLineIds` (`linePayload.ts:164-176`) *also* treats a total-mode line with a blank `line_total` as unpriced. The divergence is currently unreachable (the page refuses before the builder runs, `CreateCreditNotePage.tsx:285-291`), but the wire-side "belt" is a weaker predicate than the brace it backs. (b) `apps/web/e2e/credit-note-creation.spec.ts:10` still documents the filter as `creditable=true`; the picker sends `creditable=1` and `creditable=true` is now a 422. The spec does not assert the URL so it does not fail — the comment is simply wrong.
*Fix*: have the builder filter with `findBlankPriceLineIds`; correct the comment.

**[NEW-5] The type-constrained exists rule does not exclude soft-deleted documents.**
`CreditNoteController.php:159-160` — `Rule::exists` does not filter `deleted_at`, and `Document` uses `SoftDeletes` (`app/Modules/Document/Domain/Document.php:38,115`). A soft-deleted invoice passes the validator and then misses the service's soft-delete-scoped lookup, producing a 404/500 instead of a clean 422. Pre-existing shape, but this round rewrote exactly this rule and could have added `->whereNull('deleted_at')`.

**[NEW-6] Copy inaccuracy in creditable mode.** `InvoiceSearchSelect.tsx:107` — the empty state is `sales:invoices.noPostedInvoices` ("No posted invoices available"), which is wrong wording once the list also carries Paid invoices.

### OBSERVATION (pre-existing, outside the diff — recorded, not charged to this PR)

`AccountingService::createCreditNoteGLEntries()` creates the `journal_entries` row directly with `'status' => JournalEntryStatus::Posted` (`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:751-762`) rather than going through `GeneralLedgerService::postEntryAndDispatchPostedEvent()`, and `grep` finds **no** `JournalEntryPosted` dispatch in that file. Document GL has always been written this way (it has its own hash-chain handling at `:746-747` and its own pre-flight `assertDocumentGlIsPostable()` at `:206`), so this is architecture, not a regression — but it is the ES-10 shape (a "Posted" entry nobody announced), and this PR enlarges the population of documents that reach it. Not a finding against #214; worth a separate lane if the ES register is still open.

---

## Verbatim output (reviewed tree `cff7df47a`)

### PHPUnit — SQLite (`./vendor/bin/phpunit <path>`)
```
tests/Feature/Document/CreditNotePaidInvoiceSourceTest.php
........                                                            8 / 8 (100%)
OK (8 tests, 46 assertions)

tests/Feature/Document/CreditNoteIntegrationTest.php
...............                                                   15 / 15 (100%)
Tests: 15, Assertions: 68, PHPUnit Deprecations: 15.

tests/Unit/Document/CreditNoteServiceTest.php
..........                                                        10 / 10 (100%)
Tests: 10, Assertions: 36, PHPUnit Deprecations: 10.

tests/Feature/Document/CreditNoteMoneyLaneTest.php
.............                                                     13 / 13 (100%)
OK (13 tests, 109 assertions)

tests/Feature/Document/CreditNoteTenantIsolationTest.php
...........                                                       11 / 11 (100%)
OK (11 tests, 26 assertions)
```

### PHPUnit — PostgreSQL (`DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_g214b DB_CENTRAL_DATABASE=autoerp_test_g214b php artisan test -c phpunit-pgsql.xml <path>`)
```
   PASS  Tests\Feature\Document\CreditNotePaidInvoiceSourceTest
  ✓ amount based credit note can be created from a paid invoice         27.26s
  ✓ line based credit note can be created from a paid invoice            2.30s
  ✓ credit note still rejects a draft source invoice                     2.10s
  ✓ credit note rejects a non invoice source document                    1.93s
  ✓ creditable filter returns posted and paid but not draft invoices     2.49s
  ✓ creditable filter rejects a non boolean value                        1.82s
  ✓ creditable filter is scoped to the current company                   1.85s
  ✓ posting against a settled invoice with a blind cache allocates zero… 1.92s
  Tests:    8 passed (46 assertions)   Duration: 41.72s

  Tests\Feature\Document\CreditNoteIntegrationTest
  Tests:    15 passed (68 assertions)  Duration: 60.85s

  Tests\Unit\Document\CreditNoteServiceTest
  Tests:    10 passed (36 assertions)  Duration: 22.40s

  Tests\Feature\Document\CreditNoteMoneyLaneTest
  Tests:    13 passed (109 assertions) Duration: 39.22s

  Tests\Feature\Document\CreditNoteTenantIsolationTest
  Tests:    11 passed (26 assertions)  Duration: 135.45s
```

### Pre-existing red — same test, two trees
```
# gate/pr-214 (cff7df47a), SQLite
1) Tests\Feature\Document\CreditNoteAllocationTest::it_creates_credit_note_allocation_when_posting
DomainException: Document 01a07177-a115-7285-b378-9b2d2a425280 (credit_note, confirmed) has no
document_number: a number is allocated on the first transition out of Draft, and this operation
requires a numbered document.
  …/app/Modules/Document/Domain/Document.php:550
  …/app/Modules/Document/Domain/Services/DocumentPostingService.php:693
  …/tests/Feature/Document/CreditNoteAllocationTest.php:129
ERRORS! Tests: 1, Assertions: 0, Errors: 1, PHPUnit Deprecations: 10.

# MAIN CHECKOUT, unmodified dev fa000edc3 (/Users/houssamr/Projects/syneriva/apps/erp/apps/api)
  …/apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:110
  …/apps/api/tests/Feature/Document/CreditNoteAllocationTest.php:129
ERRORS! Tests: 1, Assertions: 0, Errors: 1, PHPUnit Deprecations: 10.
   → IDENTICAL. PRE-EXISTING. Not this PR.
```

### Vitest (`./node_modules/.bin/vitest run <files>`)
```
 ✓ src/components/molecules/pickers/InvoiceSearchSelect.test.tsx   (20 tests)
 ✓ src/features/documents/__tests__/creditNotePayload.test.ts       (4 tests)
 ✓ src/features/documents/__tests__/CreateNotePages.quantityDisplay.test.tsx  (2 tests)
 ✓ src/features/documents/__tests__/ReturnCreditNotePages.tenantScope.test.tsx (5 tests)
 ✓ src/features/documents/__tests__/DocumentForm.payload.test.ts   (22 tests)
 ✓ src/features/documents/__tests__/DocumentForm.blankUnitPrice.test.tsx (10 tests)
 Test Files  6 passed (6)
      Tests  63 passed (63)

 src/features/documents/components/__tests__  (10 files)
 Test Files  10 passed (10)
      Tests  97 passed (97)

 src/components/molecules/pickers/InvoiceSearchSelect.test.tsx alone
 Test Files  1 passed (1)
      Tests  20 passed (20)
```

### Static gates
```
$ ./node_modules/.bin/tsc --noEmit          TSC_EXIT=0   (0 lines of output)

$ ./vendor/bin/pint --test <5 touched PHP files>
{"result":"pass"}

$ ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G --no-progress \
    app/Modules/Document/Application/Services/CreditNoteService.php \
    app/Modules/Document/Domain/Document.php \
    app/Modules/Document/Presentation/Controllers/CreditNoteController.php \
    app/Modules/Document/Presentation/Controllers/InvoiceController.php
 [OK] No errors

$ ./node_modules/.bin/eslint <7 touched web files>
✖ 137 problems (0 errors, 137 warnings)
  CreateCreditNotePage.tsx: 0 errors / 102 warnings
    no-deprecated 73 (colorClasses quarantine) · no-unnecessary-template-expression 11
    precision/no-parsefloat-on-money 10 (:158 :191 :210 :211 :212 :602 :617 :620×3 — all
      display/preview math, all pre-existing lines) · prefer-nullish-coalescing 3
      no-floating-promises 2 · incompatible-library 1 · restrict-template-expressions 1
      no-misused-promises 1
  creditNotePayload.ts: 0/0 · creditNotePayload.test.ts: 0/0 · linePayload.ts: 0/1
  InvoiceSearchSelect.tsx: 0/6 · InvoiceSearchSelect.test.tsx: 0/26 · DocumentLineEditor.tsx: 0/2

$ grep '^+' <full diff> | grep -E 'parseFloat|Number\(|floatval|\(float\)|toFixed'
  → 3 hits, ALL inside comments (creditNotePayload.ts:96 "no parseFloat, no toFixed";
    CreateCreditNotePage.tsx comment; creditNotePayload.test.ts comment). No float on the seam.
```

---

## Owner questions (restated precisely, with the operator consequence of each option)

**Q1 — May a fully-PAID invoice be credited at all, and what consumes the resulting credit?** (r1 MAJOR-2)
Today, crediting a settled invoice books **Cr 411 for the full ex-stamp total with `partner_id`** (`AccountingService.php:764-800`) and allocates **0** against the invoice (`CreditNoteService.php:1340`, proven by the seam test). Verified consequences: the credit is **visible** on the partner GL balance (`PartnerBalanceService.php:40-57`, reads `journal_lines.partner_id`); **invisible** in AR aging (`AgedReceivablesService.php:172-181` admits credit notes only when `is_historical`, on the premise that ordinary ones arrive via `credit_note_allocations` — a zero row breaks that premise); **cannot be re-allocated** to another invoice (`allocateCreditNote()` has exactly one caller, `CreditNoteController.php:383-391`, and it only targets `source_document_id`); and **cannot be paid out in cash** (`DocumentAllocationClassifier.php:244-246` maps `CreditNote` to `AllocationRefusalReason::OutwardDocumentType`).
- **Option A — allow it as-is.** Operator consequence: the customer is genuinely owed money, the books are right, but the shop has **no supported way to give it back or use it**, and the credit does not appear in the receivables report the operator actually reads. Expect "where did my customer's 1200 go?" tickets.
- **Option B — allow it and finish the second half.** Ship the credit-consumption/refund document, declare the credit in AR aging, surface it on the partner page. Operator consequence: correct and complete; costs a lane.
- **Option C — scope the relaxation to invoices with remaining headroom.** Operator consequence: the pharmacy case ("customer returns a box against a cash-paid ticket") stays **unsupported** on this screen — the reason F-STG-4 was raised in the first place.

**Q2 — If Paid invoices are creditable, should the other five surfaces follow?** (r1 MAJOR-3)
Still Posted-only and unchanged: `InvoiceToCreditNoteConverter.php:74-77` (the document-conversion path), `DocumentActions.tsx:132`, `DocumentActionBar.tsx:163`, `InvoiceDetailPage.tsx:418,748,778`, and `apps/web/src/types/creditNote.ts:214-233`.
- **Option A — relax them too.** Operator consequence: the natural journey works — open the paid invoice, credit it. Requires touching the conversion path and three FE gates.
- **Option B — leave them.** Operator consequence: crediting a paid invoice is possible **only** via `/sales/credit-notes/create`; from the invoice's own page the button is simply absent, with no explanation. Discoverability is near zero, and training has to cover the split.

**Q3 — `issue_date`: honour it or remove the field?** (r1 IMPORTANT-4)
Re-verified this round: `issue_date` appears in **none** of the three rule sets at `CreditNoteController.php:137-183`, so `$request->validate()` drops it; both services hard-set `'document_date' => now()` (`CreditNoteService.php:872`, `:1004`). The page collects and zod-validates it (`CreateCreditNotePage.tsx:39`) and the builder sends it (`creditNotePayload.ts:104`).
- **Option A — honour it.** Operator consequence: back-dating a credit note becomes possible — which touches numbering sequence, fiscal period/lock checks and hash-chain ordering. Needs its own fiscal lane and its own gate; not a fix-round item.
- **Option B — remove the field.** Operator consequence: the form stops lying. A credit note is always dated today. Cheap, honest, and reversible if A is later wanted.
- **Doing neither** leaves a fiscal document whose operator-chosen date is silently discarded — a defect of its own class regardless of which way the ruling goes.

**Q4 — goods disposition on the credit-note surface.** (r1 IMPORTANT-6)
The page still defaults `reason: 'return'` (`CreateCreditNotePage.tsx:108`) while writing **no** stock movement (verified absent this round). For the pharmacy launch case the operator books the money and leaves the stock out, unless they separately create a `ReturnNote`.
- **Option A — add a "goods returned? → create return note" affordance** before promoting this flow (the guided cancel path already does this via `RefundService::cancelInvoice()` with `ReturnDecisionData`). Operator consequence: stock and money stay in step.
- **Option B — promote as-is.** Operator consequence: silent stock drift on every returned-goods credit note, discovered at the next count.

---

## Could not verify

- **The F-STG-4 finding record.** `grep -rn "F-STG-4"` across the repo still returns only this PR's own files and the review docs. The DEV-QA/staging registry is not in the repo, so the "owner-confirmed scope for Paid invoices" asserted in the PR body remains **unverifiable from code**. (This is why Q1 is an owner question and not a finding.)
- **Playwright e2e** (`apps/web/e2e/credit-note-creation.spec.ts`) — not executed; needs a running vite/API stack. It is a mocked-API spec (`:71-82` intercepts the POST it asserts), so it must not be counted as end-to-end proof either way.
- **Red-without-fix transcripts** in the handback were not re-executed (no code modification permitted in this gate). I verified the falsifications **by reading the pre-fix expressions against the fixtures** and they hold by construction — see §MAJOR-1. That is reasoning, not execution; the handback's transcripts are consistent with it.
- **Whether any real country chart supplies all four `SystemAccountPurpose` values** the credit-note GL needs — the new test hand-seeds them (§MAJOR-5 caveat).
- **Browser/manual pass on the repaired flow.** No human or scripted browser run of `/sales/credit-notes/create` was performed in this gate.

---

## Merge to local dev: **YES — conditional**

- **Conditional on the owner ruling for Q1/Q2/Q4?** Only for *promotion to staging*, not for merging to local dev. The PR does not change any existing behaviour that an owner ruling would have to bless first: the return-note flow is byte-identical, the `creditable` filter is additive and has no other consumer, and every existing credit-note test still passes on both engines. If Q1 lands on **Option C** (headroom-only), the code change to `Document::isCreditableInvoiceSource()` is one line plus the filter — cheaply reversible from a merged state.
- **Conditional on NEW-1 for the pharmacy launch.** NEW-1 is a one-line query change plus one test assertion and is correct under *every* answer to Q1. I would take it in a fix round 2 **before promotion**, not before merge.
- **Not conditional on** NEW-2..NEW-6 (residuals and cosmetics) or on the pre-existing `CreditNoteAllocationTest` red (proven identical on base `dev`).

**Recommended sequencing:** merge to local dev now → fix round 2 for **NEW-1** (+ NEW-2 if cheap) → owner rules Q1/Q2/Q3/Q4 → promote. Do **not** promote to staging while Q4 is open if the parapharmacy return journey is in the promotion scope.

## What to fix before promotion
Exclude fully-credited invoices from the `creditable` filter so the picker stops offering invoices the create call refuses (NEW-1), guard the blank `description` the same way the blank price is now guarded (NEW-2), and get the owner's ruling on Q1–Q4 — especially Q4, because a credit note defaulted to `reason: 'return'` that writes no stock movement is a silent inventory drift on the exact pharmacy journey this PR unblocks.
