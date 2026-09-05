# Adversarial merge gate r2 — PR #212 `fix(documents): enforce due_date >= issue_date on create & update (DEV-QA-008/057)`

| | |
|---|---|
| **PR** | otospexsolutions/erp #212 — author `dhouhaferjani-lgtm`, base `dev` |
| **Reviewed tree** | `gate/pr-212` head `99466f3c7e33c889d9846a89be586ff1d7286be4` = local dev at gate time `fa000edc3` + PR merge `0ba98436f` + 6 fix-round commits |
| **Diff reviewed** | `git diff fa000edc3 HEAD` — 19 files, +1113/−68 (the 20th file in `--stat` is the fix-round handback itself) |
| **Worktree** | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-212` |
| **Gate date** | 2026-09-05 |
| **r1 record** | [`2026-09-05-dhouha-pr-212-gate-r1.md`](2026-09-05-dhouha-pr-212-gate-r1.md) — CHANGES REQUIRED |
| **Fix round answered** | [`2026-09-05-dhouha-pr-212-fix-round-1-handback.md`](2026-09-05-dhouha-pr-212-fix-round-1-handback.md) |
| **PG leg** | private throwaway DB `autoerp_test_g212b` on 127.0.0.1:5433 — created, used, **dropped** |
| **Reviewer actions** | read-only. Three transient path-scoped `git checkout` round-trips (backend + FE falsifiability) and two temporary probe test files, all restored/removed. Final `git status --porcelain` empty (printed after every round-trip below). No merge, no push. |

---

## VERDICT: **CHANGES REQUIRED**

**Merge to local dev: NO.**

Nine of the ten r1 findings are genuinely fixed and every one of them was re-verified
by re-running, not by reading the handback. The fix-round engineering is good: the rule
object is well designed, the tests are real and falsifiable (6 red before the fix, 2/4 red
on the FE), the CI red is gone, the manifest raise is house-style, and the merge into the
moved local `dev` is conflict-free.

It is blocked by **one** finding, **N-1 (HIGH)**: the fix-round handback's "known residual,
deliberately accepted" rests on a premise that is **factually false**, and I reproduced the
consequence end-to-end. A quote can still be **confirmed and numbered** carrying
`due_date` a month before its `document_date`, through the app's own shipped UI path. That
is the exact defect DEV-QA-008/057 exists to close, so the PR cannot be merged claiming to
close it while the path is open. The fix is small (≈5 lines in one file + one test); r3
should be quick.

---

## 1. r1 finding → status

| Finding | r1 severity | Lane claim | **Gate r2 verdict** | Evidence |
|---|---|---|---|---|
| **F1** manifest ceiling + no live CI lane | BLOCKER (CI red) | FIXED | **FIXED — verified** | `feature-lane-manifest-check.php` exits 0 (§4.1); `grep -c DocumentDueDateGuardTest .github/workflows/ci.yml` = 1, inside the anchored alternation, `…|StockTransferIdempotencyCollisionPostgresTest|DocumentDueDateGuardTest)::/` — regex well-formed and the checker's own "every `--filter` entry is anchored and uniquely matched" assertion passes |
| **F2** partial `PATCH` bypasses the guard | BLOCKER | FIXED | **FIXED — verified** | `DueDateNotBeforeDocumentDate.php:48-127` + `UpdateDocumentRequest.php:71-79, 206-260`; r1 exploit now 422 (§4.4 tests 10-13); falsifiability 6 red (§4.6) |
| **F3** auto-save persists `due_date < document_date` | BLOCKER | FIXED | **PARTIALLY FIXED — see N-1** | the payload-carries-a-date and stored-draft arms are closed and pinned; the NEW-draft-with-no-`document_date` arm is open and reachable from the UI (§4.7) |
| **F4** `replace($this->except(...))` promotes query params | MEDIUM | FIXED (removed, not narrowed) | **FIXED — deviation accepted** | §2.3 |
| **F5** test-coverage gaps (5 items) | MEDIUM | FIXED (all 5) | **FIXED — verified** | 18 tests incl. equality, `valid_until`, partial PATCH, second company via `X-Company-Id`, new FE unit test; all five items present (§2.5) |
| **F6** PHPStan docblock on `lines()` | LOW | FIXED | **FIXED — verified** | `@return list<array<string, string>>` at `DocumentDueDateGuardTest.php:150-152`; PHPStan L8 `[OK]` on the test file (§4.2) |
| **F7** dead `issue_date` rules + 9 controller normalisers | LOW | FIXED (deleted) | **FIXED — verified** | §2.4 — all nine sites re-checked per site; `grep issue_date app/` leaves only the supplier-invoice path + 2 docblocks |
| **F8** missing `ar` key | INFO, not a gap | no action | **CORRECT — verified** | §2.6 |
| **F9** AR/AP opening import | INFO, out of scope | not fixed, owner owed | **correctly deferred** | `ArApOpeningService.php` untouched in the diff |
| **F10** DEV-QA registry absent | INFO | not verifiable | **still not verifiable** | §6 |

---

## 2. Claim-by-claim verification

### 2.1 F2 — the rule object and the stored-value fallback

`apps/api/app/Modules/Document/Presentation/Rules/DueDateNotBeforeDocumentDate.php` (new, 127 lines).

Semantics, read line by line and confirmed against the runs:

- **`>=` on plain `Y-m-d`** — `:110-119` `toDay()` normalises both sides through
  `strtotime()` → `date('Y-m-d', …)`, and `:73` compares with `<`. Day granularity, so a
  time component on either side cannot decide the outcome. **Equality passes** — pinned by
  `test_quote_create_accepts_a_due_date_equal_to_the_issue_date` and
  `test_quote_partial_update_accepts_a_due_date_equal_to_the_stored_document_date`, both
  green on sqlite and PG.
- **Null-safe** — `:57-59` returns early on a non-string or blank value under test
  (`nullable`'s job); `:63-65` returns early when no comparand is known; `:69-71` returns
  early on an unparseable value under test (`date`'s job). One error per problem.
- **Comparand precedence** — `:84-99`: submitted wins, stored is the fallback, and a
  *present but unparseable* submitted value returns `null` rather than falling through to
  the stored value. That is the right call (it would otherwise compare against a date the
  client never sent) and preserves r1's `[PROBE E]` precedence.
- **Applied to `due_date` AND `valid_until`, on Create AND Update** — `CreateDocumentRequest.php:99-100`,
  `UpdateDocumentRequest.php:101-102` (one shared stateless instance per request, `:69-78`
  / `:71-79`). Also on auto-save (`AutoSaveDraftRequest.php:203-210`) — `due_date` only
  there, correctly, since `AutoSaveDraftRequest` declares no `valid_until`.
- **Message keys, not magic strings** — `:121-126` selects
  `documents.dates.valid_until_before_document_date` vs
  `documents.dates.due_date_before_document_date` (rule 9 respected).

**`UpdateDocumentRequest::storedDocumentDate()` (`:206-260`)** — memoised via
`$storedDocumentDateResolved`/`$resolvedStoredDocumentDate` (`:30-32`), one query per
request. It scans `$this->route()->parameters()`, skips non-strings and non-UUIDs
(`Str::isUuid()`, correct — `documents.id` is a PG `uuid` and a non-UUID in a `where`
raises 22P02, not an empty result), and looks the row up as
`Document::query()->where('company_id', $this->companyContext->requireCompanyId())->find($parameter)`.

**Is the scoping identical to the controller's own lookup?** Almost — and the difference is
benign but real:

| | controller | request |
|---|---|---|
| company | `HandlesDocuments::baseQuery()` → `Document::forCompany($companyId)` → `where('company_id', $companyId)` (`Document.php:722-725`) | `where('company_id', requireCompanyId())` — **identical** |
| type | `->ofType(DocumentType::Quote)` etc. | **absent** |
| tenant | none (db-per-tenant) | none — consistent |

So there is **no cross-company read** (verified: `Document::query()` carries no global
scope — `grep addGlobalScope app/Modules/Document/Domain/Document.php` → 0 hits, so the
explicit `company_id` predicate is the whole boundary) and **no 500 on a foreign or missing
id** (the `Str::isUuid()` guard plus `find()` returning null). A missing/foreign id
resolves to `null`, the rule stays silent, and the controller's own `find()` answers 404 —
**acceptable**, and the right ordering.

The one deviation is the missing `ofType`: see **N-3 (INFO)**.

Route coverage: the five `PATCH` routes that bind `UpdateDocumentRequest` each declare
exactly one plain string parameter — `routes.php:107` `/quotes/{quote}`, `:140`
`/orders/{order}`, `:177` `/invoices/{invoice}`, `:291`
`/purchase-orders/{purchaseOrder}`, `:357` `/return-notes/{returnNote}`. There is **no**
delivery-note update route (verified by `grep -nE "Route::(patch|put)"`), so the
single-parameter scan is total. `ReturnNoteController::update(string $id, UpdateDocumentRequest $request)`
(`:239`) has its arguments in the reverse order but Laravel resolves by type — covered.

**The r1 exploit is closed.** `test_quote_partial_update_rejects_a_due_date_before_the_stored_document_date`
and its `valid_until` / purchase-order / second-company siblings are 422 on both engines,
and 200 (i.e. red) against the pre-fix requests (§4.6).

### 2.2 F3 — auto-save

`AutoSaveDraftRequest::storedDraftDocumentDate()` (`:275-296`) scopes
`where('tenant_id', …)->where('company_id', …)->find($draftId)` behind a `Str::isUuid()`
guard. That is **exactly** the scoping `DraftPersistenceService::saveDraft()` uses
(`DraftPersistenceService.php:104-110` — `where('tenant_id')->where('company_id')->lockForUpdate()->find($draftId)`);
the request correctly omits `lockForUpdate()`, which belongs to the service's transaction,
not to a read-only validation probe. A foreign `draft_id` resolves to null on both sides.
**Verified identical.**

"Silent when no comparand" is real and deliberate, and
`test_auto_save_accepts_a_payload_that_carries_no_dates` pins it.

**FE 422 handling (Phase A T14) — verified by reading the hook and running its tests.**
`apps/web/src/hooks/useDraftAutoSave.ts:269-279`: the catch arm sets `autosaveFailed`,
sets `lastError`, clears `autosavePending` only when no newer body is queued, calls
`request.onError?.()` and logs. It does **not** touch `draftIdRef` / `setDraftId`, and it
does **not** call the form's `reset()` — `DocumentForm`'s `handleAutoSaveSuccess`
(`DocumentForm.tsx:265-270`) is the only `reset()` caller and lives on the success path.
Form state is preserved and `shouldWarn` picks the failure up. **The handback's claim is
correct.** (`DocumentForm.test.tsx`, `.blankUnitPrice`, `.payload`, `.tenantScope`,
`.dueDateGuard` = 96/96 green, §4.5.)

**The residual is NOT caught at submit time — see N-1.**

### 2.3 F4 — `replace()` removed rather than narrowed

The deviation holds up, with one caveat worth stating.

- **The mapping path still works.** `prepareForValidation()` (`CreateDocumentRequest.php:194-211`,
  `UpdateDocumentRequest.php:178-187`) still does
  `if ($this->has('issue_date') && ! $this->has('document_date')) { $this->merge(['document_date' => $this->input('issue_date')]); }`.
  `Request::merge()` writes into `getInputSource()`, so `validationData()` (= `all()`) sees
  `document_date`, the rule reads it via `$this->input('document_date')` at
  `CreateDocumentRequest.php:69` / `UpdateDocumentRequest.php:76`, and it lands in
  `validated()` under the declared `document_date` rule. Proven by the create/update suites
  (§4.3-4.4): 154 sqlite / 109 PG green, all of which post the real FE `issue_date` shape.
- **`issue_date` cannot leak.** It is declared in **no** rule on either request
  (`grep -n "'issue_date'" CreateDocumentRequest.php UpdateDocumentRequest.php` → only
  comments and the `prepareForValidation` guard). `Validator::validated()` returns only
  rule-declared keys, so a query-string `?issue_date=` cannot reach `Document::create()` /
  `update()`. This is *stronger* than the narrowing r1 asked for, and the request body bag
  is no longer mutated at all, which is what F4 objected to. **Accepted.**
- **`validationData()` stale keys:** `all()` still contains `issue_date` (and any query
  parameter). Nothing consumes it — no rule references it, no controller reads it (§2.4),
  and no `withValidator()` closure touches it (`UpdateDocumentRequest.php:262+` handles
  lines/discounts only). **No stale-key consumer.**
- **Caveat (pre-existing, unchanged, not a finding):** because Laravel's `all()` merges the
  query bag, `?document_date=…` on a JSON `POST`/`PATCH` is still a validatable input and
  still reaches `validated()`. That was equally true before this PR (r1 established it and
  found no exploit); removing `replace()` neither creates nor worsens it.
- **`required_without` → `required` on create** (`CreateDocumentRequest.php:99`): equivalent.
  With neither key sent, the old pair 422'd on both keys and the new rule 422s on
  `document_date`; with only `issue_date` sent, `prepareForValidation()` guarantees
  `document_date` is present. With `document_date` explicitly null plus a valid
  `issue_date`, both the old and the new shape 422 (the old on `date` against null, since
  `validatePresent` is true for an explicit null and `date` is not implicit). No FE path
  sends that: `DocumentForm.tsx:472-484` spreads the form values, so the submit carries
  `issue_date` and never `document_date`.
- **Behaviour change worth one line, already stated by the lane:** a *malformed* `issue_date`
  now 422s on the `document_date` key. `DocumentForm.tsx` never maps server field errors
  onto RHF fields (no `setError` anywhere in the file), so nothing regresses visually.

### 2.4 F7 — the nine deleted normalisers

Each of the nine sites re-checked in the diff against the method it lived in. All nine sat
inside a method typed on `CreateDocumentRequest` or `UpdateDocumentRequest`, i.e. reading a
`validated()` array that can no longer contain `issue_date`:

| Controller | store | update |
|---|---|---|
| `QuoteController.php` | `store(CreateDocumentRequest)` ✔ | `update(UpdateDocumentRequest, string $quote)` ✔ |
| `InvoiceController.php` | ✔ | ✔ |
| `SalesOrderController.php` | ✔ | ✔ |
| `PurchaseOrderController.php` | ✔ | ✔ |
| `DeliveryNoteController.php` | ✔ | *(no update route exists)* |

`grep -rn "issue_date" --include='*.php' apps/api/app/` after deletion (verbatim, paths
trimmed):

```
Accounting/…/AgedPayablesService.php:301        # docblock
Accounting/…/AgedReceivablesService.php:271     # docblock
Document/…/UpdateDocumentRequest.php:97,180,185,186     # comments + the alias merge
Document/…/CreateDocumentRequest.php:68,92,98,196,203,207,209,210   # comments + the alias merge
Document/…/Rules/DueDateNotBeforeDocumentDate.php:28    # comment
Procurement/…/CreateSupplierInvoiceService.php:130
Procurement/…/CreateSupplierInvoiceRequest.php:109,110  # 'after_or_equal:issue_date' — its own, already correct
Procurement/…/SupplierInvoiceController.php:441,485,542
DocumentIngestion/…/SupplierInvoiceCommitter.php:74
```

**Every remaining hit is the supplier-invoice path** (a different request class with its own
already-correct guard) or a docblock. Confirmed as r1 predicted.

`apps/web/src` side: `issue_date` survives as the `DocumentForm` **form-field name**
(`DocumentForm.tsx:49, 220, 237, 337, 607-615`), the supplier-invoice feature's own API
shape (`features/purchases/supplier-invoices/*`, which posts to the guarded procurement
endpoint), `GoodsReceiptListPage.tsx:745` (display), and `CreateCreditNotePage.tsx:213`
(→ `CreditNoteController::store`, whose inline rules accept no `due_date`, so unaffected).
**No API client path sends `document_date` through a different request class on these
routes.**

### 2.5 F5/F6 — tests

`DocumentDueDateGuardTest.php` holds **18** test methods (grep-counted). All five r1 gaps
are answered:

1. equality boundary — `test_quote_create_accepts_a_due_date_equal_to_the_issue_date` (`:259`),
   `test_quote_partial_update_accepts_a_due_date_equal_to_the_stored_document_date` (`:304`);
2. partial `PATCH` — `:289`, `:314`, `:324`;
3. `valid_until` — `:274` (create), `:314` (partial update);
4. **second company** — `test_the_partial_update_guard_applies_in_a_second_company` (`:336-398`)
   creates a real second `Company` + `Partner` + `Document`, drives the endpoint with
   `->withHeader('X-Company-Id', $secondCompany->id)`, and asserts the persisted
   `due_date` is unchanged. CLAUDE.md rule 22 satisfied for this lane's write path;
5. FE unit test — `DocumentForm.dueDateGuard.test.tsx`, 4 tests, **falsifiable: 2 red** with
   the RHF guard reverted (§4.7, verbatim).

F6's docblock is `@return list<array<string, string>>` (`:150-152`) and PHPStan L8 is `[OK]`
on the test file.

### 2.6 Hygiene (item 7)

| Check | Result |
|---|---|
| **i18n — backend rule message** | new keys `documents.dates.due_date_before_document_date` / `…valid_until_before_document_date` in `apps/api/lang/en/documents.php:116-119` and `apps/api/lang/fr/documents.php:54-57`. **`ar` absent — correct, not a gap:** `lang/ar/documents.php` declares only `purchase_order, proforma, credit_note, posting_marker` where `en` declares eleven blocks, so `discount`, `dates`, `stock`, `bonus_quantity` etc. all already fall back to `en` (`config/app.php:83` `fallback_locale = en`). Consistent with the file's own state and with r1 F8. |
| **i18n — frontend** | `sales:documents.dueDateBeforeIssue` added to `en` + `fr` `sales.json`; `ar` bundle spread-merges over `en` (r1 F8 established the whole date block already does). Rule 11 respected — no hardcoded string in the new FE code. |
| **Magic strings (rule 9)** | none — message selection is by translation key, comparison is on `Y-m-d` strings from `date()`. |
| **Constructor injection (rule 13)** | `DueDateNotBeforeDocumentDate` is a value object `new`ed in `rules()` with two scalars — the same pattern as the module's existing `LineDiscountAmountWithinGross`. No `app()` helper anywhere in the diff (`grep -n "app("` on the three requests → 0 hits outside test files). The requests keep their existing `private readonly` constructor injection. |
| **Strict typing (rule 3)** | `declare(strict_types=1)` on the new rule and the new test; the only `mixed` is the `ValidationRule::validate()` signature the interface mandates, and it is immediately narrowed with `is_string()`. |
| **Module boundaries (rule 6)** | the rule lives in `Document/Presentation/Rules/` and is consumed only by `Document` requests. No cross-module import added. |
| **Rule 17 (UUIDs / real schema)** | tests use model-generated UUIDs throughout; second-company fixtures create real `Company`/`Partner`/`Document`/`DocumentLine` rows. |
| **Rule 18 (design tokens)** | no colour class added or touched in `DocumentForm.tsx` — n/a. |
| **Scope creep** | none. The 19 files are all on the due-date lane plus the two CI-gate files F1 demanded. F9 (`ArApOpeningService`) correctly untouched. |
| **Pint** | `{"result":"pass"}` (§4.2) |
| **PHPStan L8** | `[OK] No errors` on the new rule + 3 requests + 5 controllers + the new test (§4.2) |
| **ESLint** | `DocumentForm.tsx` + `DocumentForm.dueDateGuard.test.tsx`: **0 errors, 11 warnings** — the same 11 r1 measured on `DocumentForm.tsx` on both `dev` and the merged tree, so the new test file adds **0**. No new lint debt. |
| **`tsc --noEmit`** | exit 0, no output (swap free 480 MB at start, above the 300 MB floor) |
| **Commit hygiene** | 6 fix-round commits, conventional subjects, no build artifacts, no `.orig`/`.rej`, working tree clean. |

---

## 3. New findings

### N-1 — **HIGH · BLOCKER** · the "deliberately accepted residual" rests on a false premise: a draft with `due_date < document_date` can still be **confirmed and numbered**
`apps/api/app/Modules/Document/Presentation/Requests/AutoSaveDraftRequest.php:203-210` ·
`apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:261-262` ·
handback §4 "Known residual, deliberately accepted"

The handback accepts the residual on this stated ground:

> "The draft cannot become a real document without passing `CreateDocumentRequest`/`UpdateDocumentRequest`, both of which now refuse it."

**That is false.** `QuoteController::confirm()` (`QuoteController.php:488`) — and every sibling
confirm — takes a bare `Illuminate\Http\Request` and performs **no** date validation; it
checks existence, draft status and locking, then allocates the number. Nothing between the
auto-save write and the numbered, confirmed document re-reads the dates.

The residual path is reachable through the **shipped UI**, not just a scripted client:
`DocumentForm.tsx:248` emits `document_date: watchedDocumentDate`, which is `''` when the
operator clears the Issue Date input; Laravel's default global
`ConvertEmptyStringsToNull` (`vendor/…/Foundation/Configuration/Middleware.php:461-462`)
turns that into `null`; `DueDateNotBeforeDocumentDate` then has **no comparand** (no
`draft_id` on a new draft) and stays silent; `DraftPersistenceService::createNewDraft()`
writes `'document_date' => $data['document_date'] ?? now()->format('Y-m-d')` (`:261`) and
`'due_date' => $data['due_date'] ?? null` (`:262`). The `min` attribute cannot help either —
it is `watchedDocumentDate || undefined` (`DocumentForm.tsx:631`), i.e. `undefined` in
exactly this state.

Reproduced against the reviewed tree (temporary probe classes, both since removed; tree
verified clean afterwards):

```
[PROBE R1] dateless-doc_date autosave => status 200
[PROBE R1] documents before=2 after=3
[PROBE R1] persisted draft document_date=2026-09-05 due_date=2026-08-05
[PROBE R2] confirm draft => status 200
[PROBE R2] after confirm status=confirmed number=QT-2026-0001 document_date=2026-09-05 due_date=2026-08-05
```

and with the byte-exact FE payload (`document_date: ''` rather than an explicit null):

```
[PROBE R3] document_date='' autosave => status 200
[PROBE R3] persisted draft document_date=2026-09-05 due_date=2026-08-05
```

So after this PR a **confirmed, numbered quote** can carry a due date one month before its
document date. That is DEV-QA-008/057 itself, still open.

**Fix** (small, and it does *not* strand the operator's work): on the auto-save CREATE
branch — `draft_id` absent — the comparand is not "unknown", it is exactly the value
`createNewDraft()` is about to write, `now()->format('Y-m-d')`. Pass that as the third
candidate in `AutoSaveDraftRequest`:

```php
new DueDateNotBeforeDocumentDate(
    submittedDocumentDate: is_string($submittedDocumentDate) ? $submittedDocumentDate : null,
    storedDocumentDate: $this->storedDraftDocumentDate()
        ?? (is_string($this->input('draft_id')) ? null : now()->format('Y-m-d')),
)
```

This leaves `test_auto_save_accepts_a_payload_that_carries_no_dates` green (the rule is
silent when `due_date` itself is absent — `:57-59`), and refuses only the state that
actually produces the corrupt row: a due date typed **before today** with no issue date at
all. Pin it with a test that asserts the auto-save 422s **and** that no third document row
is authored, mirroring `test_auto_save_rejects_a_due_date_before_the_document_date:417-421`.

**If the owner instead rules the residual acceptable**, that ruling must (a) replace the
false premise in the handback with the true one — that `confirm` does not re-validate, so a
confirmed document may legitimately carry `due_date < document_date` — and (b) be pinned by
a test that documents the accepted behaviour, so no future reader mistakes it for a bug.
Silence is what r1 F3 already refused.

### N-2 — **LOW** · the auto-save UPDATE branch now 422s over a header field it never persists
`AutoSaveDraftRequest.php:203-210` · `DraftPersistenceService.php:135-137`

On the update branch (`draft_id` present) `saveDraft()` writes **lines only** — "header is
immutable for now" (`:135`) — so `due_date` in that payload was, and still is, discarded.
The new rule nonetheless refuses the whole request, which turns a previously-successful
**line** auto-save into a failure over a field the service ignores. Consequence: an operator
whose due date is earlier than the issue date stops getting line auto-saves entirely until
they fix the date (`useDraftAutoSave.ts:269-279` sets `autosaveFailed`; the work is kept in
the form but is no longer being persisted).

This is defensible — r1 F3 asked for exactly this guard, the operator is visibly warned, and
the state being refused is invalid anyway — but it is a behaviour change the handback labels
"belt-and-braces" without noting that it costs a line save. **Not a blocker.** Worth one
sentence in the handback, or narrowing the update-branch arm to fire only when the payload
also carries a `document_date` (i.e. when the operator is actually asserting a date pair).

### N-3 — **INFO** · `storedDocumentDate()` is type-agnostic, so a cross-type id 422s where it should 404
`UpdateDocumentRequest.php:229-232`

The lookup is `where('company_id', …)->find($parameter)` with **no** `->ofType(...)`, while
the controller's is `baseQuery()->ofType(DocumentType::Quote)->find(...)`
(`QuoteController.php:327-329`). So `PATCH /api/v1/quotes/{id}` where `{id}` is an **invoice**
in the *same* company resolves a comparand and can return 422 before the controller returns
404. No cross-company read (the `company_id` predicate is the whole boundary and `Document`
carries no global scope), no write, and the disclosure is confined to a company the caller
already holds — hence INFO, not a finding. Adding `->whereIn('type', …)` is not worth the
coupling; if it is ever tidied, do it by moving the resolution behind the controller instead.

### N-4 — **INFO, trivial** · `storedDraftDocumentDate()` is not memoised
`AutoSaveDraftRequest.php:275-296`

Unlike its update-side twin (`UpdateDocumentRequest.php:30-32, 207-211`) it re-queries on
every call. `rules()` is invoked once per request in practice, so this is one query either
way; noted only for symmetry.

---

## 4. Verbatim outputs

### 4.1 Feature-lane manifest checker + CI allowlist

```
$ cd .worktrees/pr-212/apps/api && php tools/feature-lane-manifest-check.php
tests/Feature lane manifest OK — 1509 Feature classes in 74 groups; every group has a disposition;
every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched
against 1920 test classes across all suites.
  ⚠ PARKED BEHIND AN EXECUTION GATE: 70 group(s) / 1246 class(es) are laned but not yet running —
    their job(s) are guarded by a repository-variable flag that is off. Flipping it is the owner
    ops step in docs/handoff/DESIGN-f2-feature-lane-execution-2026-08-21.md §7.
  ⚠ COVERAGE DEBT: 1 group(s) / 1 class(es) sit in groups that NO CI lane runs as a whole,
    pending the F-2 CI-budget decision. …
EXIT=0
```

```
$ grep -c "DocumentDueDateGuardTest" .github/workflows/ci.yml
1
$ grep -n "DocumentDueDateGuardTest" .github/workflows/ci.yml | tail -c 200
…|TenantOnlyUniqueOnCatalogueTablesRatchetTest|TenantOnlyUniqueRatchetLivenessTest|RepositoryNormalisationTest|StockTransferIdempotencyCollisionPostgresTest|DocumentDueDateGuardTest)::/'
```

Line 1117, appended at the end of the alternation, inside the closing `)::/'` — regex shape
intact, and the checker's "every `--filter` entry is anchored and uniquely matched" assertion
independently confirms it parses.

Manifest values (`python3 -c json.load`):

```
dev (a622d7e97): gated_ceiling 1245  Document 92
HEAD (99466f3c7): gated_ceiling 1246  Document 93
```

Class counts (`git ls-tree -r --name-only <rev> apps/api/tests/Feature/Document/ | grep -c 'Test\.php$'`):
`fa000edc3` = 92, `dev` = 92, `HEAD` = 93. The raise is exactly one class and matches reality.

### 4.2 Pint + PHPStan level 8

```
$ ./vendor/bin/pint --test app/Modules/Document/Presentation/Rules/DueDateNotBeforeDocumentDate.php \
    app/Modules/Document/Presentation/Requests/ app/Modules/Document/Presentation/Controllers/ \
    tests/Feature/Document/DocumentDueDateGuardTest.php lang/en/documents.php lang/fr/documents.php
{"result":"pass"}
PINT_EXIT=0
```

```
$ ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G --no-progress \
    app/Modules/Document/Presentation/Rules/DueDateNotBeforeDocumentDate.php \
    app/Modules/Document/Presentation/Requests/{CreateDocumentRequest,UpdateDocumentRequest,AutoSaveDraftRequest}.php \
    app/Modules/Document/Presentation/Controllers/{Quote,Invoice,SalesOrder,DeliveryNote,PurchaseOrder}Controller.php \
    tests/Feature/Document/DocumentDueDateGuardTest.php
Note: Using configuration file /…/.worktrees/pr-212/apps/api/phpstan.neon.

 [OK] No errors
```

### 4.3 Backend — sqlite (GREEN, counts match the handback exactly)

```
$ ./vendor/bin/phpunit tests/Feature/Document/DocumentDueDateGuardTest.php
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.15
Configuration: /…/.worktrees/pr-212/apps/api/phpunit.xml

..................                                                18 / 18 (100%)

Time: 00:14.908, Memory: 167.00 MB

OK (18 tests, 54 assertions)
```

```
$ ./vendor/bin/phpunit tests/Feature/Document/DocumentDueDateGuardTest.php tests/Feature/Document/CreateDocumentTest.php \
    tests/Feature/Document/UpdateDocumentTest.php tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php \
    tests/Feature/Document/DocumentServiceLineValidationTest.php tests/Feature/Document/AutoSaveRouteHardeningTest.php \
    tests/Feature/Document/AutoSaveDraftLineTaxResolutionTest.php tests/Feature/Document/Types/QuoteControllerTest.php \
    tests/Feature/Document/IngressPrecisionTest.php

...............................................................  63 / 154 ( 40%)
............................................................... 126 / 154 ( 81%)
............................                                    154 / 154 (100%)

Time: 01:33.239, Memory: 201.00 MB

OK, but there were issues!
Tests: 154, Assertions: 589, PHPUnit Deprecations: 2.
```

```
$ ./vendor/bin/phpunit tests/Feature/Document/DeferredDocumentNumberingTest.php \
    tests/Feature/Document/DocumentNumberingCompanyScopeTest.php tests/Feature/Document/DocumentConversionScenarioTest.php \
    tests/Feature/Document/DiscountToleranceValidationTest.php tests/Feature/Document/DiscountPolicyDocumentValidationTest.php \
    tests/Architecture/FeatureLaneManifestCheckerTest.php

...............S...............................................  63 / 128 ( 49%)
............................................................... 126 / 128 ( 98%)
..                                                              128 / 128 (100%)

Time: 00:56.008, Memory: 179.00 MB

OK, but some tests were skipped!
Tests: 128, Assertions: 601, Skipped: 1.
```

### 4.4 Backend — PostgreSQL 16 (GREEN, private DB `autoerp_test_g212b`, dropped after)

```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_g212b DB_CENTRAL_DATABASE=autoerp_test_g212b \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Document/DocumentDueDateGuardTest.php

   PASS  Tests\Feature\Document\DocumentDueDateGuardTest
  ✓ quote create rejects due date before issue date                     11.64s
  ✓ quote create accepts due date on or after issue date                 1.55s
  ✓ quote update rejects due date before issue date                      1.56s
  ✓ quote update accepts due date on or after issue date                 1.50s
  ✓ purchase order create rejects due date before issue date             1.48s
  ✓ purchase order create accepts due date on or after issue date        1.47s
  ✓ purchase order update rejects due date before issue date             1.47s
  ✓ quote create accepts a due date equal to the issue date              1.55s
  ✓ quote create rejects a valid until before the issue date             1.47s
  ✓ quote partial update rejects a due date before the stored document…  1.36s
  ✓ quote partial update accepts a due date equal to the stored documen… 1.43s
  ✓ quote partial update rejects a valid until before the stored docume… 1.44s
  ✓ purchase order partial update rejects a due date before the stored…  1.30s
  ✓ the partial update guard applies in a second company                 1.58s
  ✓ auto save rejects a due date before the document date                1.55s
  ✓ auto save accepts a due date equal to the document date              1.66s
  ✓ auto save accepts a payload that carries no dates                    1.50s
  ✓ auto save rejects a due date before the stored draft document date   1.48s

  Tests:    18 passed (54 assertions)
  Duration: 37.05s
```

```
$ … php artisan test -c phpunit-pgsql.xml tests/Feature/Document/CreateDocumentTest.php tests/Feature/Document/UpdateDocumentTest.php \
    tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php tests/Feature/Document/AutoSaveRouteHardeningTest.php \
    tests/Feature/Document/AutoSaveDraftLineTaxResolutionTest.php tests/Feature/Document/Types/QuoteControllerTest.php
…
  Tests:    109 passed (429 assertions)
  Duration: 182.85s
```

```
$ … php artisan test -c phpunit-pgsql.xml tests/Feature/Document/DeferredDocumentNumberingTest.php \
    tests/Feature/Document/DocumentNumberingCompanyScopeTest.php tests/Feature/Document/DocumentConversionScenarioTest.php \
    tests/Feature/Document/DiscountToleranceValidationTest.php tests/Feature/Document/DiscountPolicyDocumentValidationTest.php \
    tests/Feature/Document/DocumentServiceLineValidationTest.php tests/Feature/Document/IngressPrecisionTest.php
…
  Tests:    79 passed (291 assertions)
  Duration: 88.10s
```

```
$ PGPASSWORD=… psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "DROP DATABASE IF EXISTS autoerp_test_g212b;"
DROP DATABASE
```

### 4.5 Frontend — vitest by file, ESLint, tsc (GREEN)

```
$ npx vitest run src/features/documents/DocumentForm.test.tsx \
    src/features/documents/__tests__/DocumentForm.blankUnitPrice.test.tsx \
    src/features/documents/__tests__/DocumentForm.payload.test.ts \
    src/features/documents/__tests__/DocumentForm.tenantScope.test.tsx \
    src/features/documents/__tests__/DocumentForm.dueDateGuard.test.tsx \
    src/locales/__tests__/saveActionKeys.test.ts src/locales/__tests__/proformaCopyParity.test.ts

 Test Files  7 passed (7)
      Tests  96 passed (96)
   Duration  2.86s
```

```
$ npx eslint src/features/documents/__tests__/DocumentForm.dueDateGuard.test.tsx src/features/documents/DocumentForm.tsx
✖ 11 problems (0 errors, 11 warnings)
```

(all 11 are on `DocumentForm.tsx` at lines 297/335/386/404/472/482/484/497/725 — the
pre-existing set r1 measured identically on `dev`; the new test file contributes none, and
no warning falls on the new lines 620-638.)

```
$ sysctl vm.swapusage
vm.swapusage: total = 10240.00M  used = 9759.25M  free = 480.75M  (encrypted)
$ npx tsc --noEmit
TSC_EXIT=0        # zero lines of output
```

### 4.6 Falsifiability — backend (6 RED against the pre-fix requests)

Method: `git checkout 0ba98436f -- apps/api/app/Modules/Document/Presentation/Requests/{Create,Update}DocumentRequest.php AutoSaveDraftRequest.php`,
run, then `git checkout HEAD -- apps/api/app/Modules/Document/Presentation/Requests/`.

```
5) …::test_auto_save_rejects_a_due_date_before_the_document_date
Expected response status code [422] but received 200.
6) …::test_auto_save_rejects_a_due_date_before_the_stored_draft_document_date
Expected response status code [422] but received 200.

FAILURES!
Tests: 18, Assertions: 33, Failures: 6.
=== RESTORED ===
(git status --porcelain: empty)
```

Matches the handback's claim exactly (6 of 18).

### 4.7 Falsifiability — frontend (2/4 RED with the RHF guard reverted)

Method: `git checkout fa000edc3 -- apps/web/src/features/documents/DocumentForm.tsx`, run,
then `git checkout HEAD -- …`.

```
   × … > binds the due-date `min` attribute to the issue date as it is typed          32ms
   × … > blocks the submit and shows the localized message when the due date is earlier 1078ms
   ✓ … > does not fire the guard when the due date equals the issue date (server semantics are >=)
   ✓ … > does not fire the guard when no due date was entered at all
 Test Files  1 failed (1)
      Tests  2 failed | 2 passed (4)
=== RESTORED ===
CLEAN
```

### 4.8 N-1 probes (temporary classes, both removed; tree clean afterwards)

```
[PROBE R1] dateless-doc_date autosave => status 200
[PROBE R1] documents before=2 after=3
[PROBE R1] persisted draft document_date=2026-09-05 due_date=2026-08-05
[PROBE R2] confirm draft => status 200
[PROBE R2] after confirm status=confirmed number=QT-2026-0001 document_date=2026-09-05 due_date=2026-08-05

[PROBE R3] document_date='' autosave => status 200
[PROBE R3] persisted draft document_date=2026-09-05 due_date=2026-08-05
```

### 4.9 Counts vs. the handback

| Leg | Handback claim | Gate r2 measured | |
|---|---|---|---|
| sqlite guard | 18/18 | **18/18 (54 assertions)** | ✔ |
| sqlite regression | 154/154 | **154/154 (589 assertions)** | ✔ |
| sqlite numbering + manifest arch test | 128/128, 1 skipped | **128/128 (601 assertions), 1 skipped** | ✔ |
| PG guard | 18/18 | **18/18 (54 assertions)** | ✔ |
| PG regression | 109/109 | **109/109 (429 assertions)** | ✔ |
| PG numbering | 79/79 | **79/79 (291 assertions)** | ✔ |
| vitest | 96/96 | **96/96 (7 files)** | ✔ |
| PHPStan L8 | `[OK]` | **`[OK] No errors`** | ✔ |
| Pint | pass | **`{"result":"pass"}`** | ✔ |
| `tsc --noEmit` | exit 0 | **exit 0** | ✔ |
| backend falsifiability | 6 red | **6 red** | ✔ |
| FE falsifiability | 2/4 red | **2/4 red** | ✔ |

**Every numeric claim in the handback reproduced.**

---

## 5. Merge outlook — `git merge-tree` against the moved local `dev`

```
$ cd .worktrees/pr-212 && git rev-parse dev
a622d7e97e162cd14ca6610b8396aebb9bc0f569
$ git merge-tree --write-tree dev HEAD
9cf16eb00bc995bb618fe887210c2ff3eaee9f71
MT_EXIT=0
```

**No conflicts.** Merged-tree sanity checks:

- merged `feature-lane-manifest.json`: `gated_ceiling 1246`, `Document 93` — the lane's
  values, correctly (`git diff fa000edc3 dev -- apps/api/tests/feature-lane-manifest.json .github/workflows/ci.yml`
  is **empty**: local `dev` has made no manifest or `ci.yml` edit since the base, so the
  "dev also carries manifest edits this session" concern does not materialise for these two
  files);
- merged `tests/Feature/Document/` holds **93** classes = the declared ceiling;
- `dev` added exactly one Feature class since the base —
  `tests/Feature/Treasury/PaymentRefundRefusalTest.php` (commit `e6f576be1`). Treasury's
  lane `treasury-spine-pgsql/feature-treasury` is **live**, not parked, so its `classes`
  entry is informational (declared 124 vs. 142 actual on `dev` today) and the addition
  neither breaks the checker nor moves `gated_ceiling`. **No manifest reconciliation is
  owed at merge time.**

So the only thing standing between this lane and a clean merge is N-1.

---

## 6. Could not verify

- **DEV-QA-008 / DEV-QA-057 themselves** — the registry is still not in this repository
  (`grep -rn "DEV-QA-008\|DEV-QA-057"` over tracked files → the only hits are this lane's own
  code comments, tests and review docs). Ticket text, reproduction and acceptance criteria
  remain taken on trust from the PR body. Standing ask to Dhouha, unchanged from r1 F10.
- **`apps/web/e2e/document-due-date-guard.spec.ts`** — still eslint-ignored, still asserts
  hardcoded English UI strings, still needs a live API + vite stack on private ports. **Not
  run** in this gate either. Its r1 objection (only FE coverage) is answered by the new unit
  test, so this is now a cosmetic residual, not a gap.
- **A browser leg on the real app** — no local stack was started. The FE guard is covered by
  the unit test and by `tsc`/ESLint, not by a rendered round. N-1's UI reachability is
  established by code reading (`DocumentForm.tsx:248`, `:631`; `Middleware.php:461-462`)
  plus the byte-exact `document_date: ''` server probe, **not** by driving a browser.
- **Whole CI suite / `pnpm lint`** — not run (standing rule: never the full PHPUnit suite
  without permission). By-path legs on both engines and by-file vitest only.
- **F9 (`ArApOpeningService`)** — untouched by design; whether DEV-QA-008/057 is meant to
  close the opening-balance importer is still an owner decision.

---

## 7. What would make this MERGE at r3

1. **N-1** — close the auto-save CREATE-branch residual by comparing against
   `now()->format('Y-m-d')` when there is no `draft_id` and no submitted `document_date`
   (patch in §3), with a test that asserts the 422 **and** that no document row is authored.
   **Or** an explicit *owner* ruling that a confirmed document may carry
   `due_date < document_date`, replacing the false "cannot become a real document" premise
   in the handback and pinned by a test.
2. **N-2** — one sentence in the handback acknowledging that the update-branch arm costs a
   line auto-save, or narrow that arm to payloads that also carry a `document_date`.
3. N-3 / N-4 are INFO; no action required.

Nothing else needs to be re-run at r3 beyond the guard test on both engines — the rest of
§4 is measured and green on this tree.

---

**Merge to local dev: NO.**

---
---

# Targeted re-gate r3 — fix round 2 (N-1)

| | |
|---|---|
| **Reviewed tree** | `gate/pr-212` head `ac5b04d32` (= r2 head `99466f3c7` + 4 commits: `afd147b2b`, `c16987fa4`, `0ab2dfdef`, `ac5b04d32`) |
| **Diff reviewed** | `git diff 99466f3c7 ac5b04d32` — 6 files, +734/−25 (5 code/test + the handback) |
| **Gate date** | 2026-09-05 |
| **PG leg** | private throwaway DB `autoerp_test_g212c` on 127.0.0.1:5433 — created, used, **dropped** |
| **Reviewer actions** | read-only. Three transient path-scoped `git checkout` round-trips (falsifiability, dev-manifest reproduction) and one temporary probe class, all restored/removed; `git status --porcelain` empty after each. No merge, no push. |

## VERDICT: **MERGE**

**Merge to local dev: YES** — with one **merger precondition** that is *not* this lane's
defect: local `dev` has moved again (`4a6af4912`, now carrying the PR #214 merge) and its
own `feature-lane-manifest.json` is already red. The merger must re-derive the Document
union at merge time (**93 → 94**, `gated_ceiling` **1246 → 1247**) or the merged tree is
CI-red. Detail and proof in §r3.5.

N-1 is **closed at both layers**, reproduced empirically. N-2/N-3/N-4 are addressed
(documented / no-action / fixed). One new LOW finding, N-5, is a follow-up, not a blocker.

---

## r3.1 — `afd147b2b` · the auto-save comparand order

`AutoSaveDraftRequest::documentDateFallback()` (`:299-310`) resolves, in order:

1. the payload's own `document_date` (passed separately as `submittedDocumentDate`, `:216`);
2. the **stored draft's** `document_date` when `draft_id` resolves (`:311-341`);
3. otherwise **`now()->format('Y-m-d')`** (`:305`).

**Same source and timezone as the service** — `DraftPersistenceService.php:261` writes
`'document_date' => $data['document_date'] ?? now()->format('Y-m-d')`. Byte-identical
expression, evaluated in the same request. ✔

**Stricter than the r2 snippet, and correctly so.** My proposed patch fell back to `now()`
only when `draft_id` was absent; this one falls back whenever the draft does not *resolve* —
including a well-formed but unknown or foreign `draft_id`. That is **more accurate**, not
merely stricter: `saveDraft()` takes the CREATE branch on exactly the same condition
(`DraftPersistenceService.php:104-119` — `$document === null` → `createNewDraft()`), under
the identical tenant+company scoping. The comparand now matches the value that will actually
be written on every branch.

`documentDateFallback()` can still return `null`, but only if a resolved draft's
`document_date` is not a `CarbonInterface`. That is unreachable: the column is
`$table->date('document_date')` with **no** `->nullable()`
(`database/migrations/tenant/2025_11_30_080000_create_documents_table.php:21`; `due_date`
and `valid_until` on lines 22-23 *are* nullable) and no later migration changes it.

**Dateless auto-save still saves** — the rule short-circuits on an absent/blank `due_date`
(`DueDateNotBeforeDocumentDate.php:57-59`), so
`test_auto_save_accepts_a_payload_that_carries_no_dates` stays green (it does, §r3.4).

**N-4 fixed** — `resolveTargetDraft()` is memoised via
`$targetDraftResolved`/`$resolvedTargetDraft` (`:110-111`, `:326-330`), matching its
update-side twin.

### Which layer refuses now — PROBE R1 → R3 → R2 re-run against `ac5b04d32`

Temporary probe class, since removed; tree verified clean afterwards.

```
[PROBE R1] no document_date at all => status 422 body={"error":{"code":"VALIDATION_ERROR","message":"The due date cannot precede the document date (2026-09-05).","errors":{"due_date":["The due date cannot precede the document date (2026-09-05)."]}}}
[PROBE R1] documents before=2 after=2
[PROBE R3] document_date='' => status 422 body={"error":{"code":"VALIDATION_ERROR","message":"The due date cannot precede the document date (2026-09-05).","errors":{"due_date":["The due date cannot precede the document date (2026-09-05)."]}}}
[PROBE R3] documents now=2
[PROBE R2] confirm a directly-written bad draft => status 422 body={"error":{"code":"INVALID_STATUS_TRANSITION","message":"The due date cannot precede the document date (2026-09-05)."}}
[PROBE R2] after: status=draft number=NULL
```

**The auto-save refuses. No row is authored** (count 2 → 2 on both shapes), so the r2
PROBE R2 sequence is never reached from the UI path — exactly as the lane claims. The
independent second layer is proven separately by writing a bad row straight to the table and
confirming it: refused, still `draft`, `document_number` still `NULL`.

## r3.2 — `c16987fa4` · the confirm-time guard

### Is `transition()` really the single lifecycle write path and numbering point?

**Not literally — but the guard's coverage of this defect class is complete.** I grepped
every write of `DocumentStatus::Confirmed` in `apps/api/app/` and classified all of them:

| Writer | Route to `Confirmed` | Can it carry `due_date`/`valid_until` < `document_date`? |
|---|---|---|
| `QuoteController:537`, `InvoiceController:625`, `CreditNoteController:287`, `SalesOrderService:107,187`, `PurchaseOrderService:127`, `DeliveryNoteService:192`, `ReturnNoteService:689`, `CorrectingEntryService:110` | `documentStatusService->transition(...)` | **guarded** |
| `DocumentStatusService:594` | inside the service (`allocateNumberAndTransition`) | n/a — downstream of the guard |
| `Workshop/…/DocumentGenerationAdapter.php:104` | **direct** `$document->status = Confirmed; save();` | **no** — `buildDocument()` sets `'document_date' => Carbon::now()` and `'due_date' => null` (`:147-148`), fully server-derived |
| `Procurement/PurchaseQuoteRequestService.php:94,122,178` | **direct** `$document->status = Confirmed; save();` (RFQ sent / response / reopen) | **no** — RFQ rows are created with `document_date => now()` and **never** write the `due_date` or `valid_until` columns; the RFQ's `validityDate` lives in the JSON `payload`, and RFQs are not on any `UpdateDocumentRequest` route |

So the docblock's "the ONE place a `documents` row changes lifecycle status" is **overstated**
(→ **N-6, INFO**): two direct writers exist. Neither can produce the inconsistency, so the
guard is not bypassable *for this defect*. Note also that the existing PHPStan rule
`DocumentStatusWriteOnlyViaStatusService` only polices `Paid` and Treasury-`Posted` writes
(`tests/PHPStan/DocumentStatusWriteOnlyViaStatusServiceTest.php:17-22`) — it does **not**
enforce the `Confirmed` claim, so nothing stops a future third bypass.

**Conversions and the POS bridge are all covered**, because every converted/bridged document
is born **Draft** and reaches `Confirmed` through `transition()`:
`Conversion/Concerns/CopiesDocumentData.php:75` `'status' => DocumentStatus::Draft`,
`PurchaseQuoteRequestToPurchaseOrderConverter.php:119` idem,
`POSAccountChargeDraftService.php:56` idem (its `document_date`/`due_date` at `:58-59` are
server-derived from the fiscal command).

### Is the scope correct?

- **`Draft -> Confirmed` only** (`DocumentStatusService.php:172-174`). ✔
- **Not `Draft -> Posted`** — only four types post directly from draft
  (`DocumentStatusMachine::postsDirectlyFromDraft()` `:104-112`: `SupplierInvoice`,
  `SupplierCreditNote`, `Expense`, `Income`). Verified individually:
  - SupplierInvoice **and** SupplierCreditNote both enter through
    `SupplierInvoiceController::store(CreateSupplierInvoiceRequest)` (`:212`), whose
    `due_date` rule is `['nullable','date','after_or_equal:issue_date']`
    (`CreateSupplierInvoiceRequest.php:110`) — **already guarded**, as claimed;
  - Expense and Income **never write `documents.due_date` at all**
    (`grep -rn "due_date" app/` filtered to those modules returns only
    `ExpenseRecurrenceTemplate.next_due_date`, the recurrence command's own template field
    and a notification payload — no write of the document column), and neither
    `ExpenseRequest` nor any income request declares a `due_date`. The exclusion is safe;
    "already guarded" is true for the supplier pair and vacuous-but-safe for the other two.
  - A **sales** invoice cannot reach `Posted` without passing `Confirmed` first, so it is
    covered. ✔
- **Not `Draft -> Cancelled`** — correct: refusing to cancel a bad draft would strand it.
- **Not later edges** — correct and deliberate; an already-confirmed document must stay
  correctable through the credit-note path rather than be bricked by a guard added after it
  was sealed.

### No number burned on refusal

The guard at `:172-174` sits **above** the number-allocation branch at `:176-187`
(`if ($from === Draft && $persistedNumber === null && $to !== Draft && $to !== Cancelled) return $this->allocateNumberAndTransition(...)`).
Asserted by `test_confirming_a_quote_whose_due_date_precedes_its_document_date_is_refused`
(`assertNull($draft->document_number)`) and proven by PROBE R2 above
(`status=draft number=NULL`). The positive control
`test_confirming_a_quote_with_consistent_dates_still_allocates_a_number` shows the number is
still allocated on a clean confirm — without it a guard that refused *everything* would look
green.

### Bootstrap handler ordering and envelope

**Ordering holds.** `bootstrap/app.php:996` registers the
`DocumentDatesInconsistentException` closure; the generic `DomainException` closure is at
**`:1053`**. Laravel 11/12 matches render callbacks in registration order, first match wins,
so the subclass is caught first. This is the file's documented, repeatedly-applied pattern
(`:352-353`, `:541-543`, `:958-960`, `:1032`), and `DocumentTransitionException` at `:1015`
sits in the same band.

**Envelope shape** matches the house form —
`{error:{code:'DOCUMENT_DATES_INCONSISTENT', message, errors:{<field>:[…]}, details:{…}}}`,
422. The extra `details` key is consistent with sibling typed handlers in the same file.

**But see N-5:** that envelope is unreachable on 7 of the 8 confirm surfaces.

### N-5 — **LOW (new)** · the typed envelope only ever reaches the CorrectingEntry path
`bootstrap/app.php:982-1013` · `HandlesDocuments.php:317-325`

Seven of the eight confirm controllers already `catch (\DomainException)` and answer with
their own `validationErrorResponse($code, $message)`, which emits
`{error:{code, message}}` — **no `errors` bag**, and the code is the caller's own. PROBE R2
shows the real response on the quote path:

```
{"error":{"code":"INVALID_STATUS_TRANSITION","message":"The due date cannot precede the document date (2026-09-05)."}}
```

The message is correct and localized, so the operator is served. But the bootstrap comment's
stated benefit — *"so a client can key on the same field name whether the refusal came from
the write boundary or from confirm"* — is delivered **only** on
`CorrectingEntryController::confirm()` (`:126-134`), the one method with **zero**
`catch (\DomainException)` (verified by per-file count across all Document controllers), and
`INVALID_STATUS_TRANSITION` is a misleading code for a date problem.

No functional break: no FE code keys on a confirm-time date error today. **Fix** (r4 or a
follow-up): add `catch (DocumentDatesInconsistentException $e)` *before* the
`\DomainException` arm in the confirm methods and pass
`['errors' => [$e->attribute => [$e->getMessage()]]]` as `validationErrorResponse`'s
`$extra`; **or** correct the bootstrap comment to say the typed envelope serves uncaught
callers only.

### N-6 — **INFO (new)** · "the ONE place a document changes status" is overstated
`DocumentStatusService.php:169-171` docblock · `DocumentDatesInconsistentException.php:31-33`

Two direct `Confirmed` writers bypass `transition()` (table in §r3.2). Neither can carry the
inconsistency, so the guard's coverage is complete today — but the comment as written would
mislead the next reader, and no static rule holds the invariant for `Confirmed`. Worth one
qualifying clause in the docblock.

## r3.3 — `0ab2dfdef` · the 7 new tests

25 methods total (`grep -c "public function test_"` = 25); the diff is **purely additive**
(`git diff --numstat` = `193 0`), so the r2 second-company case
`test_the_partial_update_guard_applies_in_a_second_company` is **kept** (grep = 1).

New cases: PROBE R1 (`due_date` with no `document_date`), PROBE R3 (`document_date: ''`, the
byte-exact FE payload), the positive control for a same-day due date on a brand-new draft,
three confirm refusals (quote/`due_date`, quote/`valid_until`, PO/`due_date`) each asserting
**status still Draft and `document_number` still NULL**, and the confirm positive control.
The fixture helper `makeUnnumberedDraft()` writes straight to the table on purpose — that is
what a legacy row or an importer looks like from confirm's point of view, which is the right
shape for a defence-in-depth test.

**Falsifiability — reasoned from the bodies, then executed.** Reverting the three changed
code files should redden exactly 5: the two auto-save *rejection* cases (the third, an
accept, survives) and the three confirm *refusals* (the confirm positive control survives).
Measured:

```
$ git checkout 99466f3c7 -- apps/api/app/Modules/Document/Presentation/Requests/AutoSaveDraftRequest.php \
    apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php apps/api/bootstrap/app.php
$ ./vendor/bin/phpunit tests/Feature/Document/DocumentDueDateGuardTest.php
1) …::test_auto_save_rejects_a_due_date_before_today_on_a_brand_new_draft
2) …::test_auto_save_rejects_a_due_date_before_today_when_the_issue_date_was_cleared
3) …::test_confirming_a_quote_whose_due_date_precedes_its_document_date_is_refused
4) …::test_confirming_a_quote_whose_valid_until_precedes_its_document_date_is_refused
5) …::test_confirming_a_purchase_order_whose_due_date_precedes_its_document_date_is_refused
FAILURES!
Tests: 25, Assertions: 64, Failures: 5.
RESTORED_CLEAN
```

**Exactly the predicted set. Falsifiability: confirmed.**

## r3.4 — Re-runs (verbatim)

```
$ ./vendor/bin/phpunit tests/Feature/Document/DocumentDueDateGuardTest.php
.........................                                         25 / 25 (100%)
Time: 00:43.638, Memory: 169.00 MB
OK (25 tests, 78 assertions)
```

```
$ ./vendor/bin/phpunit tests/Feature/Document/CorrectingEntryEndpointTest.php \
    tests/Feature/Document/PurchaseOrderUnpricedLineConfirmTest.php tests/Feature/Document/InvoiceDeliveryNoteConfirmationTest.php \
    tests/Feature/Document/ReturnNoteConfirmSealAndPeriodTest.php tests/Feature/Document/CreditNoteIntegrationTest.php \
    tests/Feature/Document/CompleteSalesCycleWithReturnTest.php tests/Feature/Document/PartialDeliveryTest.php \
    tests/Feature/Document/MissingStockLevelConfirmRefusalTest.php
..............SS...................S.......................S..... 65 / 83 ( 78%)
..................                                                83 / 83 (100%)
Time: 01:05.149, Memory: 187.00 MB
OK, but there were issues!
Tests: 83, Assertions: 344, PHPUnit Deprecations: 15, Skipped: 4.
```

```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_g212c DB_CENTRAL_DATABASE=autoerp_test_g212c \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Document/DocumentDueDateGuardTest.php
…
  ✓ auto save rejects a due date before today on a brand new draft       3.03s
  ✓ auto save rejects a due date before today when the issue date was c… 5.05s
  ✓ auto save accepts a due date from today on a brand new draft         2.15s
  ✓ confirming a quote whose due date precedes its document date is ref… 2.13s
  ✓ confirming a quote whose valid until precedes its document date is…  2.22s
  ✓ confirming a purchase order whose due date precedes its document da… 2.04s
  ✓ confirming a quote with consistent dates still allocates a number    2.99s

  Tests:    25 passed (78 assertions)
  Duration: 77.83s
```

```
$ … php artisan test -c phpunit-pgsql.xml tests/Feature/Document/DeferredDocumentNumberingTest.php \
    tests/Feature/Document/DocumentNumberingCompanyScopeTest.php tests/Feature/Document/CorrectingEntryEndpointTest.php \
    tests/Feature/Document/PurchaseOrderUnpricedLineConfirmTest.php tests/Feature/Document/ReturnNoteConfirmSealAndPeriodTest.php \
    tests/Feature/Document/DocumentConversionScenarioTest.php
  Tests:    71 passed (283 assertions)
  Duration: 262.21s

$ PGPASSWORD=… psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "DROP DATABASE IF EXISTS autoerp_test_g212c;"
DROP DATABASE
```

```
$ ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G --no-progress \
    app/Modules/Document/Domain/Exceptions/DocumentDatesInconsistentException.php \
    app/Modules/Document/Domain/Services/DocumentStatusService.php \
    app/Modules/Document/Presentation/Requests/AutoSaveDraftRequest.php \
    bootstrap/app.php tests/Feature/Document/DocumentDueDateGuardTest.php
 [OK] No errors

$ ./vendor/bin/pint --test <the same five files>
{"result":"pass"} PINT_EXIT=0

$ php tools/feature-lane-manifest-check.php
tests/Feature lane manifest OK — 1509 Feature classes in 74 groups; …
$ python3 -c "…"
gated_ceiling 1246 Document 93          # unchanged by round 2, as claimed
```

All four re-run counts match the lane's numbers exactly: guard 25/25 (78 assertions) on both
engines, confirm-surface 83/83 sqlite, 71/71 PG.

## r3.5 — Merge outlook (local `dev` moved again: `4a6af4912`)

```
$ git rev-parse dev
4a6af49123994d5634619dfeee4e80a245d96970
$ git merge-tree --write-tree dev ac5b04d32
50b8165d25319050b4d2e17cefd2430dbc03be94
MT_EXIT=0
```

**No textual conflicts** — including on the files `dev` has touched under this lane
(`CreditNoteService.php`, `Document.php`, `RefundService.php`, `CreditNoteController.php`,
`InvoiceController.php`, and five `apps/web/src/features/documents/` files from PR #214).
None of them is a file this lane edits.

**But there is a semantic manifest conflict the merger must resolve — caused by `dev`, not
by this lane.** PR #214 landed `tests/Feature/Document/CreditNotePaidInvoiceSourceTest.php`
(commit `c26cbb9ff`) with **no** manifest raise and **no** `ci.yml` allowlist entry:

```
dev Document test classes:      93     dev manifest declares:      92 / gated 1245
merged Document test classes:   94     merged manifest declares:   93 / gated 1246
```

So **local `dev` is already manifest-red on its own.** Reproduced by checking `dev`'s
manifest into a 93-class tree (restored immediately afterwards):

```
$ git checkout dev -- apps/api/tests/feature-lane-manifest.json && php tools/feature-lane-manifest-check.php
tests/Feature lane manifest — FAILED
  ✗ PARKED-LANE COVERAGE GREW: group "Document" now holds 93 class(es), ceiling is 92. …
  ✗ GATED-LANE COVERAGE GREW: 1246 class(es) … ceiling is 1245. …
RESTORED_CLEAN
```

**Merger precondition:** re-derive the union at merge time — `groups.Document.classes`
**94**, `gated_ceiling` **1247** — and give `CreditNotePaidInvoiceSourceTest` its own
raise note plus an allowlist decision (that is PR #214's owed work, per the Document
`raise_note`'s own standing instruction: *"Do NOT add 1 to a stale number … it is recomputed
and never carried forward"*). Without it the merged tree fails
`backend-architecture`.

## r3.6 — Could not verify (r3)

- **The typed `DOCUMENT_DATES_INCONSISTENT` envelope itself** — reachable only through
  `CorrectingEntryController::confirm()`, which needs a correcting-entry fixture I did not
  build. The ordering (`:996` before `:1053`) and the shape are verified by reading; the
  envelope was **not executed**. The seven catching surfaces were executed (PROBE R2).
- **Frontend** — untouched in round 2; not re-run in r3 (r2 measured 96/96 vitest,
  `tsc` exit 0, ESLint 0 errors on this lane's FE files).
- **Whole CI suite** — not run (standing rule). By-path legs only.
- **DEV-QA-008 / DEV-QA-057** — registry still absent from the repo (r1 F10 / r2 §6).

## r3.7 — Finding ledger after r3

| Finding | Severity | Status |
|---|---|---|
| **N-1** confirm/auto-save residual, false safety premise | HIGH (r2 blocker) | **CLOSED at both layers — verified by probe, by 7 new tests, and by 5-red falsifiability** |
| **N-2** auto-save UPDATE branch refuses over an unpersisted field | LOW | **documented, deliberately kept** (handback §8.5) — accepted |
| **N-3** `storedDocumentDate()` type-agnostic | INFO | no action, as advised |
| **N-4** `storedDraftDocumentDate()` unmemoised | INFO | **fixed** (`:326-330`) |
| **N-5** typed envelope unreachable on 7 of 8 confirm surfaces | **LOW (new)** | follow-up — non-blocking |
| **N-6** "single lifecycle write path" overstated | **INFO (new)** | one qualifying clause owed in the docblock |
| **r1 F1-F10** | — | unchanged from r2: all fixed / correctly deferred |

---

**Merge to local dev: YES** — conditional on the merger re-deriving the manifest union
(`Document` 93 → **94**, `gated_ceiling` 1246 → **1247**) at merge time, per §r3.5. N-5 and
N-6 are follow-ups, not merge blockers.
