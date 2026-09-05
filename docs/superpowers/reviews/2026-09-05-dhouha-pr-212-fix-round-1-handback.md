# Fix round 1 — PR #212 `fix(documents): enforce due_date >= issue_date on create & update (DEV-QA-008/057)`

| | |
|---|---|
| **Gate answered** | [`docs/superpowers/reviews/2026-09-05-dhouha-pr-212-gate-r1.md`](2026-09-05-dhouha-pr-212-gate-r1.md) — VERDICT **CHANGES REQUIRED** |
| **Branch** | `gate/pr-212`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-212` |
| **Base** | `fa000edc39c5e2c060748db534ff0a22ed1e31a8` (local `dev` at gate time) + PR head, merged at `0ba98436f` |
| **Fix-round commits** | `ed05e72cc`, `543ee8511`, `8ec53b389`, `876c8d510`, `233f9b907` (HEAD) |
| **PG leg** | private throwaway DB `autoerp_test_f212` on 127.0.0.1:5433 — created, used, **dropped** |
| **Merge state** | **NOT merged, NOT pushed.** Working tree clean. |

⚠️ **Baseline note for the next gate:** local `dev` has moved since the gate
(`a622d7e97` now). `git diff --stat dev HEAD` therefore shows dev's newer
commits as deletions and is **not** this lane's diff. Diff against
`fa000edc3` — the commit the gate reviewed.

---

## 1. What changed, finding by finding

| Finding | Severity in r1 | Status | Where |
|---|---|---|---|
| **F1** manifest ceiling + no live CI lane | BLOCKER (CI red) | **FIXED** | `apps/api/tests/feature-lane-manifest.json`, `.github/workflows/ci.yml:1117` |
| **F2** partial `PATCH` bypasses the guard | BLOCKER | **FIXED** | new `DueDateNotBeforeDocumentDate.php`; `UpdateDocumentRequest.php:72-104, 225-262` |
| **F3** auto-save persists `due_date < document_date` | BLOCKER | **FIXED** | `AutoSaveDraftRequest.php:186-215, 276-296` |
| **F4** `replace($this->except(...))` promotes query params | MEDIUM | **FIXED** (removed, not narrowed — §3) | `CreateDocumentRequest.php:194-228`, `UpdateDocumentRequest.php:178-204` |
| **F5** test-coverage gaps (5 items) | MEDIUM | **FIXED** (all 5) | `DocumentDueDateGuardTest.php` 7 → 18 tests; new `DocumentForm.dueDateGuard.test.tsx` |
| **F6** PHPStan docblock on `lines()` | LOW | **FIXED** | `DocumentDueDateGuardTest.php:150` |
| **F7** dead `issue_date` rules + 9 controller normalisers | LOW | **FIXED** (proven dead, deleted) | 5 controllers, both requests |
| **F8** missing `ar` key | INFO, not a gap | no action (see §4) | — |
| **F9** AR/AP opening import has no relational guard | INFO, out of scope | **NOT fixed** — owner decision owed (§4) | `ArApOpeningService.php:200-224` |
| **F10** DEV-QA registry absent from the repo | INFO | **NOT verifiable** — ask Dhouha (§4) | — |

Every r1 claim was re-verified against the tree before acting. All ten
reproduced exactly as written; no finding was rejected.

---

## 2. F1 — the CI red this PR created

Confirmed before touching anything, on the merged tree:

```
$ php tools/feature-lane-manifest-check.php
tests/Feature lane manifest — FAILED
  ✗ PARKED-LANE COVERAGE GREW: group "Document" now holds 93 class(es), ceiling is 92. …
  ✗ GATED-LANE COVERAGE GREW: 1246 class(es) now sit in lanes parked behind an unflipped execution gate, ceiling is 1245. …
```

Fixed as a deliberate raise, in the house style:

- `groups.Document.classes` **92 → 93** with a new `raise_note_2026_09_05`
  (what the class pins, the sqlite + PG run records, the allowlist decision
  and when to remove it). The pre-existing `raise_note` (91 → 92) is kept
  verbatim below it, as every prior round of this group has done.
- top-level `gated_ceiling` **1245 → 1246**, with a
  `gated_ceiling_raise_note_2026_09_05_pr212` sibling to the existing
  `…_2026_09_05` note (which records the 1243 → 1245 Inventory raise, and is
  left untouched).
- `DocumentDueDateGuardTest` added to the `backend-test-pgsql` `--filter`
  allowlist in `.github/workflows/ci.yml:1117`, at the end of the alternation,
  regex shape intact. Same precedent as `CorrectingEntryEndpointTest`,
  `ProformaOutputTest`, `PurchaseOrderUnpricedLineConfirmTest`. Without it the
  parked lane means a P1 fiscal-date guard runs on **no CI event at all**.

Green after:

```
$ php tools/feature-lane-manifest-check.php
tests/Feature lane manifest OK — 1509 Feature classes in 74 groups; every group has a disposition;
every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched
against 1920 test classes across all suites.
  ⚠ PARKED BEHIND AN EXECUTION GATE: 70 group(s) / 1246 class(es) …
  ⚠ COVERAGE DEBT: 1 group(s) / 1 class(es) …
```

(warnings only — the same two the check emits on `dev`).

---

## 3. F2 / F3 / F4 / F7 — the design, and one deliberate deviation

### The rule object

`apps/api/app/Modules/Document/Presentation/Rules/DueDateNotBeforeDocumentDate.php`
(new, `Illuminate\Contracts\Validation\ValidationRule`, sibling of the
module's existing `LineDiscountAmountWithinGross`). Constructor takes the two
halves explicitly:

```php
public function __construct(
    private readonly ?string $submittedDocumentDate,
    private readonly ?string $storedDocumentDate,
) {}
```

- the **submitted** value always wins when present — this preserves the exact
  precedence the gate probed at `[PROBE E]` (a client sending both keeps the
  explicit `document_date`);
- the **stored** value is the fallback the Laravel rule could never reach;
- **silent** when neither is known, when the value under test is empty
  (`nullable`'s job), or when either side is an unparseable date (`date`'s
  job) — one error per problem, never two;
- comparison at **day** granularity (`strtotime` → `Y-m-d`), so a time
  component can never decide the outcome, and **equality passes** — same
  semantics as `after_or_equal`, now pinned by tests.

Used by `due_date` **and** `valid_until`, on create, update and auto-save; it
replaces `after_or_equal:document_date` on all of them so the three paths
refuse identically. Messages are new keys
`documents.dates.due_date_before_document_date` /
`…valid_until_before_document_date` in `lang/en` + `lang/fr`.

### F2 — resolving the stored `document_date` on update

`UpdateDocumentRequest::storedDocumentDate()` (memoised, one query per
request). It reads the **route parameter**, not a bound model: every update
route in `Document/Presentation/routes.php` declares a plain string id
(`/quotes/{quote}:107`, `/orders/{order}:140`, `/invoices/{invoice}:177`,
`/purchase-orders/{purchaseOrder}:291`, `/return-notes/{returnNote}:357`) and
each controller does its own `find()`, so there is no `Document` instance in
the route bag. `Str::isUuid()` guards the lookup because `documents.id` is a
PostgreSQL `uuid` column and a non-UUID in a `where` on it raises 22P02, not
an empty result. Scoped to the active company: the value only ever produces a
422, never a write, but it must not be readable across the company boundary
either — an unknown or foreign id resolves to null, the guard stays silent,
and the controller's own `find()` answers with the 404 the request deserves.

Delivery notes have **no** update route on this tree
(`grep "Route::(patch|put)" routes.php` → 6 hits, none for delivery notes), so
"both quote and PO" plus the shared request class covers every update writer.

### F3 — auto-save

Same rule, fallback resolved from `draft_id`, scoped by tenant **and** company
— the identical scoping `DraftPersistenceService::saveDraft()` uses to decide
whether `draft_id` addresses a row at all, so a foreign id resolves to null on
both sides.

The rule is **deliberately silent when no comparand is known**. A draft
mid-typing is a legal incomplete state, and a 422 on a keystroke auto-save
strands the operator's work. Pinned by
`test_auto_save_accepts_a_payload_that_carries_no_dates`.

**Frontend consequence, checked as the gate asked:** T14's serialized autosave
already handles a 422 correctly — `useDraftAutoSave.ts:270-280` catches, sets
`autosaveFailed` + `lastError`, calls `onError`, and **preserves**
`draftIdRef`/`draftId` and the form state. `DocumentForm`'s
`handleAutoSaveSuccess` (the only thing that calls `reset()`) runs on the
success path only, and `shouldWarn` picks `autosaveFailed` up so the operator
is warned before navigating away. Nothing wipes the form. No FE change was
needed for F3.

### F4 — deliberate deviation from the brief

The brief asked for `Arr::except($this->getInputSource()->all(), ['issue_date'])`
fed back through `replace()`. **I removed the `replace()` call entirely
instead**, in both requests. Rationale:

- the purpose of the line was to keep the FE-only alias out of `validated()`;
- `Validator::validated()` returns **only rule-declared keys**, so once
  `issue_date` carries no rule (F7) the alias cannot reach `validated()` on
  **any** transport — body or query string. The `getInputSource()` variant
  would still have left a query-string `?issue_date=` in `all()` and therefore
  in `validationData()`;
- removing the rewrite is strictly narrower than narrowing it: the body bag is
  no longer mutated at all, so no downstream reader of `$request->json()` is
  affected, which is exactly what F4 objected to;
- there was no existing repo pattern to follow — `grep -rn "getInputSource"
  --include='*.php' app/` returns **0 hits**, and the other 24
  `prepareForValidation()` implementations use plain `merge()` only (e.g.
  `CreatePartnerRequest`'s VAT normaliser).

Consequence worth stating: a request whose only date is a **malformed**
`issue_date` now 422s on the `document_date` key instead of `issue_date`. The
frontend never maps server field errors onto form fields (no `setError` call
anywhere in `DocumentForm.tsx`), so nothing regresses. `document_date` on
create also moved from `required_without:issue_date` to plain `required` —
equivalent, because `prepareForValidation()` guarantees `document_date` is
present whenever `issue_date` is, and honest now that `issue_date` is not a
validated key.

### F7 — proven dead, then deleted

All nine normaliser sites sit inside methods typed on
`CreateDocumentRequest`/`UpdateDocumentRequest` (verified per site:
QuoteController `store:168`/`update:328`, InvoiceController `store:250`/
`update:415`, SalesOrderController `store:159`/`update:309`,
PurchaseOrderController `store:354`/`update:496`, DeliveryNoteController
`store:395`). With `issue_date` undeclared they are unreachable, so all nine
are gone, together with both `issue_date` rules.

`grep -rn "issue_date" --include='*.php' app/` after the deletion leaves only:
the procurement supplier-invoice path (`CreateSupplierInvoiceRequest.php:109-110`,
its own already-correct `after_or_equal:issue_date`), two report docblocks in
`AgedPayablesService`/`AgedReceivablesService`, and the new comments. **No API
client path still needs the fallback**, so nothing minimal was kept.

**PR-body correction owed to Dhouha:** the PR body describes the controller
blocks as "a harmless post-validation fallback for API clients that already
send `document_date`". That is wrong in both directions — a client sending
`document_date` never had an alias to normalise, and one sending the alias has
it resolved upstream. The blocks were dead, not a fallback.

---

## 4. Not fixed — and why

- **F9 (AR/AP opening-balance import, `ArApOpeningService.php:200-224`)** —
  same defect class, different lane, explicitly out of PR scope in r1. Left
  untouched. **Owner decision owed:** does DEV-QA-008/057 mean to close the
  opening-balance importer too?
- **F10 (DEV-QA registry)** — still not in the repo; ticket text and
  acceptance criteria remain taken on trust from the PR body. Ask Dhouha for
  the registry (this is the standing note already in MEMORY.md).
- **F8 (`ar` translation key)** — r1 classified it as INFO/not-a-gap. The two
  new **backend** message keys follow the file's own precedent and land in
  `en` + `fr` only: `lang/ar/documents.php` has no `discount.*` validation
  block either, and Laravel falls back to `fallback_locale`.
- **`apps/web/e2e/document-due-date-guard.spec.ts`** — left exactly as the PR
  wrote it (still eslint-ignored, still asserts English strings, still needs a
  live stack, still not run). The r1 objection was that it was the *only* FE
  coverage; that is now answered by a real unit test. Retiring or localising
  the e2e spec is a separate call.

### Known residual, deliberately accepted

An auto-save that carries a `due_date` **but no `document_date`** and creates a
NEW draft still lands a row whose `document_date` defaults to
`now()->format('Y-m-d')` (`DraftPersistenceService.php:261`) with an earlier
`due_date`. Closing it would mean comparing against `now()` — which would 422
every keystroke auto-save of an operator who has cleared the Issue Date field
while a due date is typed, stranding their work for a state the submit-time
guard already refuses. The draft cannot become a real document without passing
`CreateDocumentRequest`/`UpdateDocumentRequest`, both of which now refuse it.
Recorded here rather than silently left.

The auto-save **update** branch writes no header dates at all
(`DraftPersistenceService.php:135` — "lines only, header is immutable for
now"), so the stored-fallback arm there is belt-and-braces, not the hole.

---

## 5. Verification — verbatim

### 5.1 Falsifiability of the new tests (RED before the fix, merged tree)

```
$ ./vendor/bin/phpunit tests/Feature/Document/DocumentDueDateGuardTest.php
…
3) …::test_purchase_order_partial_update_rejects_a_due_date_before_the_stored_document_date
Expected response status code [422] but received 200.
4) …::test_the_partial_update_guard_applies_in_a_second_company
Expected response status code [422] but received 200.
5) …::test_auto_save_rejects_a_due_date_before_the_document_date
Expected response status code [422] but received 200.
6) …::test_auto_save_rejects_a_due_date_before_the_stored_draft_document_date
Expected response status code [422] but received 200.

FAILURES!
Tests: 18, Assertions: 33, Failures: 6.
```

(findings 1 and 2 are the quote partial-PATCH `due_date` and `valid_until` cases.)

### 5.2 `DocumentDueDateGuardTest` — sqlite (GREEN)

```
$ ./vendor/bin/phpunit tests/Feature/Document/DocumentDueDateGuardTest.php
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.15
Configuration: /…/.worktrees/pr-212/apps/api/phpunit.xml

..................                                                18 / 18 (100%)

Time: 00:34.067, Memory: 167.00 MB

OK (18 tests, 54 assertions)
```

### 5.3 `DocumentDueDateGuardTest` — PostgreSQL 16 (GREEN)

```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_f212 DB_CENTRAL_DATABASE=autoerp_test_f212 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Document/DocumentDueDateGuardTest.php

   PASS  Tests\Feature\Document\DocumentDueDateGuardTest
  ✓ quote create rejects due date before issue date                     13.10s
  ✓ quote create accepts due date on or after issue date                 1.45s
  ✓ quote update rejects due date before issue date                      1.86s
  ✓ quote update accepts due date on or after issue date                 1.34s
  ✓ purchase order create rejects due date before issue date             1.76s
  ✓ purchase order create accepts due date on or after issue date        1.91s
  ✓ purchase order update rejects due date before issue date             1.42s
  ✓ quote create accepts a due date equal to the issue date              1.61s
  ✓ quote create rejects a valid until before the issue date             1.72s
  ✓ quote partial update rejects a due date before the stored document…  2.25s
  ✓ quote partial update accepts a due date equal to the stored documen… 1.74s
  ✓ quote partial update rejects a valid until before the stored docume… 1.91s
  ✓ purchase order partial update rejects a due date before the stored…  1.61s
  ✓ the partial update guard applies in a second company                 1.84s
  ✓ auto save rejects a due date before the document date                1.49s
  ✓ auto save accepts a due date equal to the document date              1.80s
  ✓ auto save accepts a payload that carries no dates                    1.60s
  ✓ auto save rejects a due date before the stored draft document date   2.22s

  Tests:    18 passed (54 assertions)
  Duration: 42.67s
```

### 5.4 Regression suites — sqlite (GREEN)

```
$ ./vendor/bin/phpunit tests/Feature/Document/DocumentDueDateGuardTest.php tests/Feature/Document/CreateDocumentTest.php \
    tests/Feature/Document/UpdateDocumentTest.php tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php \
    tests/Feature/Document/DocumentServiceLineValidationTest.php tests/Feature/Document/AutoSaveRouteHardeningTest.php \
    tests/Feature/Document/AutoSaveDraftLineTaxResolutionTest.php tests/Feature/Document/Types/QuoteControllerTest.php \
    tests/Feature/Document/IngressPrecisionTest.php

...............................................................  63 / 154 ( 40%)
............................................................... 126 / 154 ( 81%)
............................                                    154 / 154 (100%)

Time: 05:08.712, Memory: 201.00 MB

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

Time: 03:20.786, Memory: 179.00 MB

OK, but some tests were skipped!
Tests: 128, Assertions: 601, Skipped: 1.
```

### 5.5 Regression suites — PostgreSQL 16 (GREEN)

```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_f212 DB_CENTRAL_DATABASE=autoerp_test_f212 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Document/CreateDocumentTest.php tests/Feature/Document/UpdateDocumentTest.php \
    tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php tests/Feature/Document/AutoSaveRouteHardeningTest.php \
    tests/Feature/Document/AutoSaveDraftLineTaxResolutionTest.php tests/Feature/Document/Types/QuoteControllerTest.php
…
  Tests:    109 passed (429 assertions)
  Duration: 256.79s
```

```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_f212 DB_CENTRAL_DATABASE=autoerp_test_f212 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Document/DeferredDocumentNumberingTest.php \
    tests/Feature/Document/DocumentNumberingCompanyScopeTest.php tests/Feature/Document/DocumentConversionScenarioTest.php \
    tests/Feature/Document/DiscountToleranceValidationTest.php tests/Feature/Document/DiscountPolicyDocumentValidationTest.php \
    tests/Feature/Document/DocumentServiceLineValidationTest.php tests/Feature/Document/IngressPrecisionTest.php
…
  Tests:    79 passed (291 assertions)
  Duration: 113.41s
```

(The PG numbering leg is new in this round — r1 ran those five suites on
sqlite only.)

### 5.6 Manifest checker + architecture test

See §2 for the checker output. `tests/Architecture/FeatureLaneManifestCheckerTest.php`
is inside the 128-test sqlite run above.

### 5.7 PHPStan level 8 — touched app files AND the test file

```
$ ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G --no-progress \
    app/Modules/Document/Presentation/Rules/DueDateNotBeforeDocumentDate.php \
    app/Modules/Document/Presentation/Requests/{CreateDocumentRequest,UpdateDocumentRequest,AutoSaveDraftRequest}.php \
    app/Modules/Document/Presentation/Controllers/{Quote,Invoice,SalesOrder,DeliveryNote,PurchaseOrder}Controller.php \
    tests/Feature/Document/DocumentDueDateGuardTest.php
Note: Using configuration file /…/.worktrees/pr-212/apps/api/phpstan.neon.

 [OK] No errors
```

F6's error is gone.

### 5.8 Pint

```
$ ./vendor/bin/pint --test app/Modules/Document/Presentation/Rules/DueDateNotBeforeDocumentDate.php \
    app/Modules/Document/Presentation/Requests/ app/Modules/Document/Presentation/Controllers/ \
    tests/Feature/Document/DocumentDueDateGuardTest.php lang/en/documents.php lang/fr/documents.php
{"result":"pass"}
```

### 5.9 Frontend — vitest by file (GREEN)

```
$ npx vitest run src/features/documents/DocumentForm.test.tsx \
    src/features/documents/__tests__/DocumentForm.blankUnitPrice.test.tsx \
    src/features/documents/__tests__/DocumentForm.payload.test.ts \
    src/features/documents/__tests__/DocumentForm.tenantScope.test.tsx \
    src/features/documents/__tests__/DocumentForm.dueDateGuard.test.tsx \
    src/locales/__tests__/saveActionKeys.test.ts src/locales/__tests__/proformaCopyParity.test.ts

 Test Files  7 passed (7)
      Tests  96 passed (96)
```

Falsifiability of the new FE test — with the `min` + RHF `validate` reverted
in `DocumentForm.tsx`:

```
   × … > binds the due-date `min` attribute to the issue date as it is typed
   × … > blocks the submit and shows the localized message when the due date is earlier
   ✓ … > does not fire the guard when the due date equals the issue date (server semantics are >=)
   ✓ … > does not fire the guard when no due date was entered at all
      Tests  2 failed | 2 passed (4)
```

(reverted only to capture this; `DocumentForm.tsx` restored and unmodified by
this fix round — `git diff` on it is empty.)

### 5.10 ESLint (new FE test) and `tsc --noEmit`

```
$ npx eslint src/features/documents/__tests__/DocumentForm.dueDateGuard.test.tsx
ESLINT_EXIT=0            # no errors, no warnings

$ npx tsc --noEmit       # swap free 849 MB at start
TSC_EXIT=0               # 0 lines of output
```

### 5.11 Diffstat

```
$ git diff --stat fa000edc3 HEAD
 .github/workflows/ci.yml                                             |   2 +-
 …/Controllers/DeliveryNoteController.php                             |   6 -
 …/Controllers/InvoiceController.php                                  |  12 -
 …/Controllers/PurchaseOrderController.php                            |  12 -
 …/Controllers/QuoteController.php                                    |  12 -
 …/Controllers/SalesOrderController.php                               |  12 -
 …/Requests/AutoSaveDraftRequest.php                                  |  57 ++-
 …/Requests/CreateDocumentRequest.php                                 |  43 +-
 …/Requests/UpdateDocumentRequest.php                                 |  97 ++++-
 …/Rules/DueDateNotBeforeDocumentDate.php                             | 127 ++++++
 apps/api/lang/en/documents.php                                       |   4 +
 apps/api/lang/fr/documents.php                                       |   4 +
 …/Feature/Document/DocumentDueDateGuardTest.php                      | 474 +++++++++++++++++++++
 apps/api/tests/feature-lane-manifest.json                            |   6 +-
 apps/web/e2e/document-due-date-guard.spec.ts                         |  50 +++
 apps/web/src/features/documents/DocumentForm.tsx                     |  25 +-
 …/__tests__/DocumentForm.dueDateGuard.test.tsx                       | 236 ++++++++++
 apps/web/src/locales/en/sales.json                                   |   1 +
 apps/web/src/locales/fr/sales.json                                   |   1 +
 19 files changed, 1113 insertions(+), 68 deletions(-)
```

---

## 6. Not run in this round

- **Whole CI suite** — standing rule: never the full PHPUnit suite without
  permission. By-path legs only, on both engines.
- **`pnpm lint` / whole-app vitest** — same reason; ESLint and vitest were run
  by file.
- **The Playwright e2e spec** — still needs a live API + vite stack on private
  ports (unchanged from r1).
- **A browser leg on the real app** — no local stack was started; the FE guard
  is covered by the unit test, not by a rendered browser round.

## 7. Anything still red

**Nothing.** Every command in §5 is green as printed. The one CI-relevant
check this PR broke (F1) is green again, and the two remaining items are
owner/teammate decisions (F9 scope, F10 registry), not code reds.

**Not merged, not pushed** — `gate/pr-212` is ready for gate r2.
