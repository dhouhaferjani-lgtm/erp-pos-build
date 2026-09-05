# Adversarial merge gate r1 — PR #212 `fix(documents): enforce due_date >= issue_date on create & update (DEV-QA-008/057)`

| | |
|---|---|
| **PR** | otospexsolutions/erp #212 — author `dhouhaferjani-lgtm`, base `dev`, head `dedac29ccfbfd6756a181d6e8da9a0c3c8f2eef4` (2 commits, 7 files, +357/−5) |
| **Reviewed tree** | MERGED tree `0ba98436fb0b3430a4c10b5f2163066dacce0613` (`gate/pr-212` = local dev `fa000edc39c5e2c060748db534ff0a22ed1e31a8` + PR head) |
| **Worktree** | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-212` |
| **Gate date** | 2026-09-05 |
| **PG leg** | private throwaway DB `autoerp_test_g212` on 127.0.0.1:5433 — created, used, dropped |
| **Reviewer actions** | read-only. No code modified, no merge, no push. Two transient path-scoped `git checkout` round-trips (falsifiability + ESLint baseline) and one temporary probe test file, all restored/removed; final `git status --porcelain` is empty. |

---

## VERDICT: **CHANGES REQUIRED**

**Merge to local dev: NO.**

The change is correct as far as it goes and is genuinely falsifiable (4/7 new tests go red against the `dev` requests — verbatim output below). It is blocked by one hard CI red that this PR itself creates (F1), and by two proven residual holes in the very bug class the PR claims to close (F2, F3) — both reproduced empirically in this gate against the merged tree.

---

## 1. Coverage matrix — every writer of `documents` that accepts dates

Verified against the merged tree. `apps/erp/apps/api` paths.

| Entry point | Request / writer | Guard `due_date >= document_date`? | Verdict |
|---|---|---|---|
| `POST /api/v1/{quotes,sales-orders,invoices,purchase-orders,delivery-notes,return-notes}` | `CreateDocumentRequest.php:82` + `:83` (`valid_until`), normalisation at `:178-188` | YES — fires now | **covered** (proven: probe + new tests) |
| Same endpoints, `PATCH`/`PUT` **with** `issue_date`/`document_date` in the payload | `UpdateDocumentRequest.php:84-85`, normalisation `:160-166` | YES | **covered** |
| Same endpoints, **partial** `PATCH` sending `due_date` (or `valid_until`) **alone** | `UpdateDocumentRequest.php:77` (`document_date` is `sometimes`) | **NO** — Laravel's `after_or_equal:document_date` degrades to a no-op when the referenced field is absent from the request; it never reads the STORED `document_date` | **HOLE — see F2** |
| Draft auto-save (`POST /api/v1/documents/auto-save`) | `AutoSaveDraftRequest.php:190-191` (`document_date`, `due_date` both bare `nullable, date`) → `DraftPersistenceService.php:260-262` | **NO** — no rule at all | **HOLE — see F3** |
| Quote → order → invoice / DN → invoice conversions | `SalesOrderToInvoiceConverter.php:242`, `DeliveryNoteToInvoiceConverter.php:237,299` — server sets `due_date = now()->addDays(30)`; `PurchaseQuoteRequestToPurchaseOrderConverter.php:125` sets `document_date = now()` | n/a — server-derived, always `document_date + 30d` | **not applicable** |
| Credit note creation (`CreditNoteController::store`) | inline `$rules` at `CreditNoteController.php:136-167` | n/a — the endpoint accepts **no** `due_date`/`document_date` input at all | **not applicable** |
| Return notes | `ReturnNoteController` uses `CreateDocumentRequest`/`UpdateDocumentRequest` | inherits the guard | **covered** (and inherits F2) |
| Supplier invoice (procurement + `DocumentIngestion` committer) | `CreateSupplierInvoiceRequest.php:109-110` — already had `after_or_equal:issue_date`; `SupplierInvoiceCommitter.php:70-78` routes through `CreateSupplierInvoiceService`, no `due_date` | already guarded / n/a | **not applicable** |
| AR/AP opening-balance import | `ArApOpeningService.php:200-224` — validates `document_date` and `due_date` **independently**, no relational check | **NO** | **pre-existing hole, out of PR scope — see F9** |
| POS account-charge bridge | `POSAccountChargeDraftService.php:53-60` — `document_date = $command->businessDate`, `due_date = $command->dueDate`, both server-derived from the fiscal command | n/a — no operator input on this path | **not applicable** |

---

## 2. Findings

### F1 — **BLOCKER (CI RED, caused by this PR)** · the new test class breaks the feature-lane manifest check
`apps/api/tests/Feature/Document/DocumentDueDateGuardTest.php` (new) · `apps/api/tests/feature-lane-manifest.json`

The `Document` group carries an **enforced** class ceiling of `92` (its lane `feature-lane-documents/Document` is wired but PARKED behind `vars.SELF_HOSTED_RUNNER_READY`, so the ceiling stays enforced). The merged tree holds 93. `tools/feature-lane-manifest-check.php` runs as a discrete, unguarded step in the `backend-architecture` job:

```
$ php tools/feature-lane-manifest-check.php     # merged tree 0ba98436f
tests/Feature lane manifest — FAILED
  ✗ PARKED-LANE COVERAGE GREW: group "Document" now holds 93 class(es), ceiling is 92. Its lane
    "feature-lane-documents/Document" is wired but parked behind an unflipped execution gate, so a
    new class here still runs nowhere — the ceiling stays enforced until the gate is flipped.
  ✗ GATED-LANE COVERAGE GREW: 1246 class(es) now sit in lanes parked behind an unflipped execution
    gate, ceiling is 1245. Lower the ceiling when a gate is flipped or a class leaves; raising it is
    a deliberate edit.
```

Baseline proof that this PR is the cause (same command, same tree, with only the new test file moved aside):

```
$ mv tests/Feature/Document/DocumentDueDateGuardTest.php <scratch>/ && php tools/feature-lane-manifest-check.php
  ⚠ COVERAGE DEBT: 1 group(s) / 1 class(es) …          # warnings only — PASSES
```

Class counts: `dev` = 92 (`git ls-tree -r --name-only dev apps/api/tests/Feature/Document/ | grep -c 'Test\.php$'`), merged = 93.

**Second half of the same finding:** `DocumentDueDateGuardTest` is named in **no** live CI `--filter` allowlist —
`grep -rn "DocumentDueDateGuardTest" .github/ apps/api/tests/feature-lane-manifest.json` → **0 hits**.
Because its lane is parked, the only guard on a P1 fiscal-date defect would execute on **no CI event at all**. The manifest's own precedent for exactly this situation (`CorrectingEntryEndpointTest`, `ProformaOutputTest`, `PurchaseOrderUnpricedLineConfirmTest`, `SupplierGoodsReturnNoteTest`) is to ALSO name the class in the `backend-test-pgsql` `--filter` allowlist in `.github/workflows/ci.yml`.

**Fix:** deliberate raise `Document.classes` 92 → 93 **and** `gated_ceiling` 1245 → 1246, with a `raise_note` in the house style (what the class pins, sqlite + PG run records); **and** add `DocumentDueDateGuardTest` to the `backend-test-pgsql` `--filter` allowlist in `.github/workflows/ci.yml`.

---

### F2 — **HIGH** · the guard is a no-op on a partial `PATCH` that omits the date; the stored `document_date` is never consulted
`apps/api/app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php:77` (`'document_date' => ['sometimes','date']`) with `:84-85`

Laravel's `after_or_equal:<field>` resolves the comparand with `Validator::getValue($parameter)`. When the field is absent from the request the comparand is `null`, `getDateTimestamp(null)` returns `null`, and `compare($timestamp, null, '>=')` is `int >= null` → PHP juggles `null` to `0` → **passes**. The rule never reaches the persisted row.

Reproduced against the merged tree (temporary probe, since removed; stored quote had `document_date = 2026-06-15`):

```
[PROBE A] partial PATCH due_date=2026-01-01 (stored document_date=2026-06-15) => status 200
[PROBE A] stored due_date now = 2026-01-01 ; document_date = 2026-06-15
[PROBE C2] partial PATCH valid_until only => status 200
```

So the exact defect DEV-QA-008/057 describes — a document persisted with a due date preceding its issue date — is **still reachable on the update path**, just via a payload that omits the date rather than one that includes it. This is not hypothetical for non-browser clients (mobile, API, scripted PATCH) and it is the shape the autosave-adjacent flows use.

**Fix:** make the comparand fall back to the persisted row. Either (a) in `prepareForValidation()`, when neither `document_date` nor `issue_date` is present, `merge(['document_date' => $this->route('…')->document_date?->toDateString()])` from the route-bound document (note `document_date` is `sometimes`, so merging it does not force a write — but confirm the controllers don't then re-write it), or (b) add a `withValidator()->after()` closure that compares `due_date`/`valid_until` against `$request->input('document_date') ?? $document->document_date`. Option (b) is the safer of the two because it cannot accidentally introduce a `document_date` into `validated()`.

---

### F3 — **HIGH** · the auto-save path can still persist `due_date < document_date`
`apps/api/app/Modules/Document/Presentation/Requests/AutoSaveDraftRequest.php:190-191` → `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:260-262`

`AutoSaveDraftRequest` declares `'document_date' => ['nullable','date']` and `'due_date' => ['nullable','date']` with **no** relational rule, and `DraftPersistenceService` writes both straight onto the `documents` row. The frontend's own autosave payload sends both — `apps/web/src/features/documents/DocumentForm.tsx:248-249` (`document_date: watchedDocumentDate`, `due_date: watchedDueDate || null`) — so this is the app's own primary write path while the operator is typing, not a theoretical API. The PR does not touch it.

Reproduced against the merged tree:

```
[PROBE D] autosave due<doc => status 200 body={"draft_id":"…","saved_at":"2026-09-05T11:46:01+00:00","line_count":1}
[PROBE D] persisted draft document_date=2026-06-15 due_date=2026-01-01
```

The new client-side `min`/RHF guard does **not** cover this: RHF `validate` runs on `handleSubmit`, not on the debounced autosave, and the native `min` attribute does not block programmatic form state. So an operator who types an early due date gets it persisted on the draft even if they never press Save.

**Fix:** add `after_or_equal:document_date` to `due_date` in `AutoSaveDraftRequest` (and, per F2, fall back to the persisted draft's `document_date` when the payload omits it), plus a test on the autosave route. If the lane owner argues a draft may legitimately hold an inconsistent date mid-typing, that must be an explicit written ruling, not silence — because that draft is the row that later gets confirmed and numbered.

---

### F4 — **MEDIUM** · `$this->replace($this->except('issue_date'))` promotes query-string parameters into the JSON body bag
`CreateDocumentRequest.php:188` · `UpdateDocumentRequest.php:166`

`Request::except()` is built on `all()`, which merges `getInputSource()->all() + $this->query->all()`. Feeding that back through `replace()` therefore writes every query-string parameter into the JSON body bag permanently, for every downstream reader of `$request->json()` / `getInputSource()`. Proven on a bare `Illuminate\Http\Request`:

```
isJson=true
BEFORE json bag: {"issue_date":"2026-06-15","due_date":"2026-07-01"}
BEFORE all():    {"issue_date":"2026-06-15","due_date":"2026-07-01","document_date":"2999-01-01","foo":"bar"}
AFTER  json bag: {"due_date":"2026-07-01","document_date":"2999-01-01","foo":"bar"}
AFTER  all():    {"due_date":"2026-07-01","document_date":"2999-01-01","foo":"bar"}
```

I found **no exploit**: `input()` already merged the query bag before this PR, so validation saw the same values, and `validated()` only returns rule-declared keys. It is nonetheless an unintended widening of the request body that no reviewer would expect from a line whose stated purpose is "drop one alias", and it runs unconditionally even when `issue_date` was never sent.

**Fix:** narrow it to the actual input source, e.g. `$this->replace(Arr::except($this->getInputSource()->all(), ['issue_date']));` — this still drops the alias (which is genuinely necessary: once `prepareForValidation()` sets `document_date`, the controllers' `isset($validated['issue_date']) && ! isset($validated['document_date'])` strip no longer fires, so leaving the alias in would leak a non-column into `$validated`).

---

### F5 — **MEDIUM** · test-coverage gaps in `DocumentDueDateGuardTest`
`apps/api/tests/Feature/Document/DocumentDueDateGuardTest.php`

The 7 tests are well-built (real models, real UUIDs from model creates, correct `{error:{errors}}` envelope via `AssertsApiValidation.php:33-58`, both quote and PO endpoints, the real FE `issue_date` payload shape) and genuinely falsifiable. Missing:

1. **The equality boundary** `due_date == issue_date`. I verified by probe that it passes (`[PROBE B] create due==issue => status 201`), but it is unpinned — an over-eager future tightening to `after:` would not be caught.
2. **The partial-`PATCH` case** (F2) — the hole that remains open.
3. **`valid_until`**, although the PR adds the rule for it on update. Create-side works (`[PROBE C1] … status 422 … "valid_until"`), update-partial does not (`[PROBE C2] … 200`). Neither is pinned.
4. **A second company** (CLAUDE.md rule 22, second-of-everything). Documents are number-keyed and there is repo precedent (`DocumentNumberingCompanyScopeTest`). Low weight for a pure validation guard, but the lane touches a catalogue-entity write path.
5. **A frontend unit test** for the new RHF `validate` / native `min` (rule 2, TDD). The only FE coverage is `apps/web/e2e/document-due-date-guard.spec.ts`, which is **excluded from ESLint** (`File ignored because of a matching ignore pattern`) and needs a live stack — it was **not run** in this gate. It also asserts hardcoded English UI strings (`'More save options'`, `'Save & Close'`, `'Due date cannot be before the issue date'`), which will break under any non-EN default locale.

---

### F6 — **LOW** · PHPStan level-8 error in the new test file
`apps/api/tests/Feature/Document/DocumentDueDateGuardTest.php:154`

```
  154    Method Tests\Feature\Document\DocumentDueDateGuardTest::lines()
         should return array<string, mixed> but returns array<int, array<string, string>>.
         🪪  return.type
```

Not CI-red — `phpstan.neon` analyses `paths: app/` only — but it is an incorrect docblock on new code (rule 3). Correct annotation: `@return list<array<string, string>>`.

---

### F7 — **LOW** · dead code left behind
- `CreateDocumentRequest.php:81` `'issue_date' => ['required_without:document_date', 'date']` and `UpdateDocumentRequest.php:78` `'issue_date' => ['sometimes','date']` can never fire — the key is unconditionally removed in `prepareForValidation()` before validation runs.
- The six controllers' post-validation normalisers are now unreachable for the same reason: `QuoteController.php:185-188` and `:362-365`, `InvoiceController.php:267-270` and `:449-452`, `SalesOrderController.php:176-179` and `:343-346`, `DeliveryNoteController.php:412-415`, `PurchaseOrderController.php:371-374` and `:530-533`. The PR body calls them "a harmless post-validation fallback for API clients that already send `document_date`" — that description is wrong: an API client sending `document_date` never had an `issue_date` to normalise, and one sending `issue_date` has it stripped upstream.

I confirmed no other consumer reads `'issue_date'` out of `validated()` on these routes (`grep -rn "issue_date" --include='*.php' app/`): the remaining hits are the procurement supplier-invoice path (`CreateSupplierInvoiceRequest.php:109-110`, its own already-correct `after_or_equal:issue_date`), `CreateSupplierInvoiceService.php:130`, `SupplierInvoiceController.php:485,542`, `SupplierInvoiceCommitter.php:74` — all a different request class, unaffected. **Dropping the alias breaks no consumer.**

---

### F8 — **INFO, not a gap** · the missing `ar` translation key
`apps/web/src/lib/i18n.ts:291-297`

The `ar` bundle spread-merges over English: `sales: { ...enSales, ...arSales, documents: { ...enSales.documents, ...arSales.documents, … } }`. `apps/web/src/locales/ar/sales.json` has **no** `documents.documentDate`, `documents.issueDate` or `documents.dueDate` either — the whole date block already falls back to EN. So the en/fr-only `dueDateBeforeIssue` behaves exactly like its siblings and is **not** a regression. Locale parity tests pass (`saveActionKeys`, `proformaCopyParity`: 37/37).

---

### F9 — **INFO, pre-existing, out of scope** · AR/AP opening-balance import has no relational date guard
`apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:200-224` validates `document_date` and `due_date` in isolation. The same defect class, reachable through the opening-balance importer. Not this PR's to fix — logged so the lane owner can decide whether DEV-QA-008/057 is meant to close it.

---

### F10 — **INFO** · DEV-QA registry not found in the repo
`grep -rn "DEV-QA-008\|DEV-QA-057" --include='*.md' --include='*.json' --include='*.csv'` over the repo (excluding `node_modules` and `.worktrees`) returns **zero hits**. The DEV-QA registry is not in this repository, so **I could not verify** the ticket text, the reported reproduction, or whether the acceptance criteria in the PR body match what was filed. Ask Dhouha for the registry, per the standing note.

---

## 3. Hygiene

| Check | Result |
|---|---|
| **Pint `--test`** (2 requests + new test) | `{"result":"pass"}` |
| **PHPStan** (project `phpstan.neon`, level 8) on the two touched requests | `[OK] No errors` |
| **PHPStan** level 8 on the new test file | 1 error — see F6 (not in CI paths) |
| **ESLint** `DocumentForm.tsx` | **0 errors**, 11 warnings — byte-identical warning set to `dev` (verified by linting `git show dev:…DocumentForm.tsx` in place: 11 warnings both sides). No new lint debt. |
| **ESLint** `e2e/document-due-date-guard.spec.ts` | file is eslint-ignored (see F5.5) |
| **`tsc --noEmit`** (`apps/web`, full project) | exit 0 (swap checked: 857 MB free) |
| **Design tokens** (rule 18) | no color classes added or touched in `DocumentForm.tsx` — n/a |
| **Convention 06-FORMS** | RHF `validate` + `FormField error=` + `Input error=` mirrors the sibling `issue_date` field at `DocumentForm.tsx:605-616`. Conforms. |
| **Convention 01-API-RESPONSES** | no API-response handling touched. |
| **422 envelope** | correct — `AssertsApiValidation` asserts `{error:{errors:{…}}}`. |
| **Scope creep** | none. 7 files, all on the due-date lane. K-1 (purchase-line "Total" price entry) untouched — confirmed by `git diff dev HEAD --stat`. |
| **Commit hygiene** | 2 commits, conventional subjects, body explains root cause, `Co-Authored-By` present. No build artifacts, no `.orig`/`.rej`, no debug leftovers. |
| **i18n rule 11** | new string goes through `t('sales:documents.dueDateBeforeIssue')`; keys added to en + fr (see F8 for ar). |

---

## 4. Timezone / date semantics (gate item 3)

Verified as **correct**.

- Both `document_date` and `due_date` are `date` casts (`Document.php:183`), and every payload in play is a plain `Y-m-d` string: `type="date"` inputs on the FE, `now()->toDateString()` in the tests.
- Laravel's `after_or_equal` uses `>=`, so **equality passes** — proven: `[PROBE B] create due==issue => status 201`.
- The FE `min={watchedDocumentDate || undefined}` (`DocumentForm.tsx:631`) is fed by `useWatch({name:'issue_date'})` (`:237`), whose value comes from the same `type="date"` control and from `toDateInputValue()` on load (`:337`) — so the `min` attribute is in the exact `YYYY-MM-DD` format the HTML spec requires, matching the server comparand.
- The RHF comparison `value >= watchedDocumentDate` is a lexicographic string compare over two `YYYY-MM-DD` values, which is order-equivalent to a date compare. Correct.
- No `Date`/timezone conversion is introduced anywhere on this path, so TN/FR locale rendering is untouched (display formatting is unchanged; only the `min` attribute and a validation message were added).

---

## 5. Verbatim test outputs

### 5.1 Falsifiability — new test against the `dev` versions of the two requests (RED)

Method: `git checkout dev -- <CreateDocumentRequest.php> <UpdateDocumentRequest.php>`, run, then `git checkout HEAD -- …` (tree restored, verified clean).

```
$ ./vendor/bin/phpunit tests/Feature/Document/DocumentDueDateGuardTest.php
...
1) Tests\Feature\Document\DocumentDueDateGuardTest::test_quote_create_rejects_due_date_before_issue_date
   Expected response status code [422] but received 201.
2) Tests\Feature\Document\DocumentDueDateGuardTest::test_quote_update_rejects_due_date_before_issue_date
   Expected response status code [422] but received 200.
3) Tests\Feature\Document\DocumentDueDateGuardTest::test_purchase_order_create_rejects_due_date_before_issue_date
   Expected response status code [422] but received 201.
4) Tests\Feature\Document\DocumentDueDateGuardTest::test_purchase_order_update_rejects_due_date_before_issue_date
   Expected response status code [422] but received 200.

FAILURES!
Tests: 7, Assertions: 7, Failures: 4.
```

This matches the PR body's claim exactly (4 cases returned 201/200 before the fix). **Falsifiability: confirmed.**

### 5.2 New test — sqlite (GREEN)

```
$ ./vendor/bin/phpunit tests/Feature/Document/DocumentDueDateGuardTest.php
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.15
Configuration: .../.worktrees/pr-212/apps/api/phpunit.xml

.......                                                             7 / 7 (100%)

Time: 00:14.028, Memory: 165.00 MB

OK (7 tests, 19 assertions)
```

### 5.3 New test — PostgreSQL 16 (GREEN)

```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_g212 DB_CENTRAL_DATABASE=autoerp_test_g212 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Document/DocumentDueDateGuardTest.php

   PASS  Tests\Feature\Document\DocumentDueDateGuardTest
  ✓ quote create rejects due date before issue date                     14.28s
  ✓ quote create accepts due date on or after issue date                 2.00s
  ✓ quote update rejects due date before issue date                      1.73s
  ✓ quote update accepts due date on or after issue date                 1.38s
  ✓ purchase order create rejects due date before issue date             2.34s
  ✓ purchase order create accepts due date on or after issue date        1.42s
  ✓ purchase order update rejects due date before issue date             1.46s

  Tests:    7 passed (19 assertions)
  Duration: 24.66s
```

### 5.4 Regression suites — sqlite (GREEN)

```
$ ./vendor/bin/phpunit tests/Feature/Document/CreateDocumentTest.php tests/Feature/Document/UpdateDocumentTest.php \
    tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php tests/Feature/Document/DocumentServiceLineValidationTest.php \
    tests/Feature/Document/AutoSaveRouteHardeningTest.php tests/Feature/Document/AutoSaveDraftLineTaxResolutionTest.php \
    tests/Feature/Document/Types/QuoteControllerTest.php tests/Feature/Document/IngressPrecisionTest.php

...............................................................  63 / 136 ( 46%)
............................................................... 126 / 136 ( 92%)
..........                                                      136 / 136 (100%)

Time: 01:27.004, Memory: 197.00 MB

OK, but there were issues!
Tests: 136, Assertions: 535, PHPUnit Deprecations: 2.
```

```
$ ./vendor/bin/phpunit tests/Feature/Document/DeferredDocumentNumberingTest.php \
    tests/Feature/Document/DocumentNumberingCompanyScopeTest.php tests/Feature/Document/DocumentConversionScenarioTest.php \
    tests/Feature/Document/DiscountToleranceValidationTest.php tests/Feature/Document/DiscountPolicyDocumentValidationTest.php

...............S....................................              52 / 52 (100%)

Time: 00:32.109, Memory: 179.00 MB

OK, but some tests were skipped!
Tests: 52, Assertions: 170, Skipped: 1.
```

### 5.5 Regression suites — PostgreSQL 16 (GREEN)

```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_g212 DB_CENTRAL_DATABASE=autoerp_test_g212 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Document/CreateDocumentTest.php tests/Feature/Document/UpdateDocumentTest.php \
    tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php tests/Feature/Document/AutoSaveRouteHardeningTest.php \
    tests/Feature/Document/AutoSaveDraftLineTaxResolutionTest.php tests/Feature/Document/Types/QuoteControllerTest.php
...
   PASS  Tests\Feature\Document\Types\QuoteControllerTest
  ✓ can list quotes … ✓ can search quotes by document number             2.83s

  Tests:    109 passed (429 assertions)
  Duration: 254.96s
```

### 5.6 Frontend — component tests by file (GREEN)

```
$ npx vitest run src/features/documents/DocumentForm.test.tsx \
    src/features/documents/__tests__/DocumentForm.blankUnitPrice.test.tsx \
    src/features/documents/__tests__/DocumentForm.payload.test.ts \
    src/features/documents/__tests__/DocumentForm.tenantScope.test.tsx

 ✓ src/features/documents/__tests__/DocumentForm.tenantScope.test.tsx (3 tests) 689ms
 ✓ src/features/documents/__tests__/DocumentForm.blankUnitPrice.test.tsx (10 tests) 1059ms
 ✓ src/features/documents/DocumentForm.test.tsx (20 tests) 1223ms

 Test Files  4 passed (4)
      Tests  55 passed (55)
```

```
$ npx vitest run src/locales/__tests__/saveActionKeys.test.ts src/locales/__tests__/proformaCopyParity.test.ts
 Test Files  2 passed (2)
      Tests  37 passed (37)
```

### 5.7 Probes against the MERGED tree (temporary file, since removed)

```
[PROBE A] partial PATCH due_date=2026-01-01 (stored document_date=2026-06-15) => status 200
[PROBE A] stored due_date now = 2026-01-01 ; document_date = 2026-06-15
[PROBE B] create due==issue => status 201
[PROBE C1] create valid_until<issue => status 422 body={"error":{"code":"VALIDATION_ERROR","message":"The valid until field must be a date after or equal to document date.","errors":{"valid_until":["The valid until field must be a date after or equal to document date."]}}}
[PROBE C2] partial PATCH valid_until only => status 200
[PROBE D] autosave due<doc => status 200 body={"draft_id":"01a07163-cf68-707b-a1c5-ca4ab8c9ae2d","saved_at":"2026-09-05T11:46:01+00:00","line_count":1}
[PROBE D] persisted draft document_date=2026-06-15 due_date=2026-01-01
[PROBE E] both document_date=2026-06-15 + issue_date=2026-01-01, due=2026-03-01 => status 422 body={"error":{"code":"VALIDATION_ERROR","message":"The due date field must be a date after or equal to document date.","errors":{"due_date":["The due date field must be a date after or equal to document date."]}}}
```

Probe E also answers the "does `document_date` always win?" question: when a client sends **both**, the explicit `document_date` is kept and the alias discarded — the same precedence the controllers used before this PR. No behaviour change there.

---

## 6. `prepareForValidation` merge check (gate item 2)

- **No double definition and no lost merge.** Each request has exactly **one** `prepareForValidation()`: `CreateDocumentRequest.php:177-207` and `UpdateDocumentRequest.php:159-189`. `git diff dev HEAD` shows the PR only *adds* the 8-line normalisation block above the pre-existing `lines` trimming block in each; nothing from local dev's Phase A Task 4 was displaced. The Phase A blank→null normalisation does not live in these two classes on `dev` either, so there is nothing to clash with.
- **Rule ordering is sound:** `required_without:issue_date` / `required_without:document_date` (`CreateDocumentRequest.php:80-81`) still 422 when neither date is sent, because both keys are then absent post-normalisation.
- **`document_date` on update:** it does **not** always exist — see F2, which is the substantive failure of this gate item.

---

## 7. Could not verify

- **DEV-QA-008 / DEV-QA-057 themselves** — the registry is not in this repository (F10). Ticket text, reproduction steps and acceptance criteria are taken on trust from the PR body.
- **`apps/web/e2e/document-due-date-guard.spec.ts`** — Playwright needs a live API + vite stack on private ports, which was out of scope for this gate. The FE guard was verified by reading and by `tsc`/ESLint, not by execution.
- **POS account-charge `dueDate` provenance** — I confirmed `POSAccountChargeDraftService.php:59` writes a server-supplied `$command->dueDate` and that no operator input reaches it on that path, but I did not trace the command's construction end-to-end.
- **Whole-suite CI** — not run (standing rule: never the full PHPUnit suite without permission). Only the by-path legs above.

---

## 8. What would make this MERGE at r2

1. **F1** — raise `Document.classes` 92 → 93 and `gated_ceiling` 1245 → 1246 in `apps/api/tests/feature-lane-manifest.json` with a house-style `raise_note`, **and** name `DocumentDueDateGuardTest` in the `backend-test-pgsql` `--filter` allowlist in `.github/workflows/ci.yml`. Re-run `php tools/feature-lane-manifest-check.php` to green.
2. **F2** — make `after_or_equal` fall back to the persisted `document_date` on a partial `PATCH` (preferably via `withValidator()->after()`), for `due_date` **and** `valid_until`; pin it with a test.
3. **F3** — add the guard to `AutoSaveDraftRequest` (same stored-value fallback) with a route-level test — or an explicit owner ruling that drafts are exempt.
4. **F4** — narrow the alias drop to `Arr::except($this->getInputSource()->all(), ['issue_date'])`.
5. **F5** — add the equality-boundary case, the partial-`PATCH` case, a `valid_until` case, and a `DocumentForm` unit test for the RHF `validate`; consider a second-company case per rule 22.
6. **F6** — fix the `lines()` docblock to `@return list<array<string, string>>`.
7. **F7** — delete the now-unreachable `issue_date` rules and the six controllers' post-validation normalisers, or state why they are kept.

---

**Merge to local dev: NO.**
