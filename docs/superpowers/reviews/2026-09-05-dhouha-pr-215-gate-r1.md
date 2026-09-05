# Gate r1 — PR #215 "fix(catalog/pricing): surface validation errors instead of silent rejects"

| | |
|---|---|
| **PR** | #215 · author `dhouhaferjani-lgtm` · base `dev` · head `324fe25201bd83728e1ed299a07569889ad65409` |
| **Gate tree** | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-215`, branch `gate/pr-215`, merge `2b7994db4` (parents: local dev `d56d62535` + PR head) |
| **True diff** | `git diff d56d62535 2b7994db4` → **12 files, +462/−18** (identical to `merge-base..head`; the merge introduced nothing and lost nothing) |
| **Reviewer** | Fable 5.1 (adversarial merge gate), 2026-09-05 |
| **Engines** | web vitest (node) · api PHPUnit sqlite **and** PG (private DB `autoerp_test_g215` on 127.0.0.1:5433, created + dropped) |
| **Verdict** | **CHANGES REQUIRED** |
| **Merge to local dev** | **NO** |

> **Merge-tree note.** `git diff dev HEAD` in the worktree shows ~2 900 extra deletions. That is an artefact: `dev`
> has moved 2 commits ahead (`d6e543f5b`, `4a6af4912`) since the worktree was cut. Against the real merge base
> (`d56d62535`) the merge is exactly the 12 PR files. `ProductForm.tsx` auto-merged against dev's ESLint-autofix
> commit **cleanly** — the merged file carries only the PR's three hunks, and `ProductForm.test.tsx` (untouched by
> the PR, carrying dev's restored `as HTMLInputElement` assertions) is **54/54 green** in the merged tree.

---

## Verdict summary

Two blockers, one strongly-recommended fix, three medium and three low findings.

| # | Sev | One line | File:line |
|---|---|---|---|
| F-1 | **MAJOR (blocks CI)** | `pnpm audit:design-system` now exits 1 — 1 new C2 violation + 1 stale baseline entry | `PriceListForm.tsx:241` |
| F-2 | **MAJOR (new 500)** | the new `Rule::unique(...)->where('attribute_id', …)` runs before the controller's `Str::isUuid` guard → PG 22P02 → 500 where dev returned 400 | `AddAttributeValueRequest.php:33-34` |
| F-3 | **MAJOR (unfixed twin, pre-existing)** | the very same double-unwrap the PR fixed on the write path is still live on the read path — the price-list **edit form and detail page never populate** — and the PR's own fixture edit cements it | `PriceListForm.tsx:63`, `PriceListDetailPage.tsx:65`, `tenantScope.test.tsx:82-84` |
| F-4 | MEDIUM | rule 22 / convention 09: catalogue-entity lane with no second-company test | `AddAttributeValueEndpointTest.php:31-56` |
| F-5 | MEDIUM | the "shared helper" is a **third** copy — two local `getFieldErrors` left in place (convention 11) | `lib/api.ts:110`, `PartnerForm.tsx:126`, `AddQuickProductModal.tsx:102` |
| F-6 | MEDIUM | 5 of the 8 fields `applyServerErrors` writes to have **no inline error renderer** | `PriceListForm.tsx:80-88` vs 193-234, 261-288 |
| F-7 | LOW | `code`/`label` validation ceilings exceed the column widths → the *same* class of 500 the PR set out to kill | `AddAttributeValueRequest.php:32,36` |
| F-8 | LOW | `import` statement placed after top-level code | `PriceListForm.tsx:23` |
| F-9 | LOW | partially-mapped 422s drop the unmapped fields; raw untranslated backend English shown in a toast | `ProductForm.tsx:576-582` |
| F-10 | INFO | product-save error surface broadened to every error class (5xx/403/network), untested branch | `ProductForm.tsx:568-572` |

---

## 1. `apps/web/src/lib/api.ts` — the shared HTTP layer (highest risk)

**What changed:** exactly one thing — a **new exported function** `getFieldErrors` inserted after `getErrorMessage`
(hunk `@@ -97,6 +97,32 @@`, `api.ts:100-126`). Nothing else in the file is touched: no interceptor, no
`isApiError`, no `getErrorMessage`, no `apiGet`/`apiPost`/`apiPatch`/`apiPut`/`apiDelete`, no auth/CSRF/company
logic. **Purely additive → backwards-compatible for every existing caller by construction.**

- **House envelope, no double-unwrap.** It reads `data.error.errors` off the raw axios `error.response.data`
  (`api.ts:110-126`), which matches the backend contract emitted at `apps/api/bootstrap/app.php:324-334`
  (`{ error: { code:'VALIDATION_ERROR', message: $e->getMessage(), errors: $e->errors() } }`). It never touches
  `response.data.data`, so the `apiGet`/`apiPost` unwrap contract (`api.ts:407-418`, docs/conventions/01) is
  untouched.
- **401 / 403 / 419 / 5xx unchanged.** Those live in the request/response interceptors and `getErrorMessage`,
  none of which are in the diff. `api.ts:83-98` — the region the Phase A T12/T13 "possibly recorded" path leans
  on — is byte-identical to dev.
- **Idempotency / payment surfaces re-run by file, all green:**
  - `src/lib/__tests__/api.unauthorized.test.ts` 4 ✓, `api.csrfRetry.test.ts` 5 ✓, `api.companyScope.test.ts` 16 ✓
  - `RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx` 14 ✓
  - `treasury/PaymentForm.test.tsx` 19 ✓ · `TransferCashModal.test.tsx` 5 ✓ · `AdjustBalanceDialog.test.tsx` 5 ✓
- **Campaign / e2e helpers:** `grep -rn "lib/api'" apps/web/e2e scripts` → **no hits**. Unaffected.
- **Silent swallow?** No. `getFieldErrors` returns `null` (never `undefined`) for a non-422, and both call sites
  have a visible fallback (`ProductForm.tsx:570-572` toasts `getErrorMessage`; `PriceListForm.tsx:292-296`
  renders the form-level alert). See F-6/F-9 for the *partial* drops.
- **F-5 (MEDIUM).** The PR bills this as "new shared helper", but two near-identical private copies already
  existed and were **left in place**: `src/features/partners/PartnerForm.tsx:126` and
  `src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx:102` (the latter is the new function
  almost verbatim, including the `Object.keys(result).length > 0 ? result : null` tail). Three surfaces for one
  concept — CLAUDE.md rule 22 / `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md`.
  **Fix:** delete both local copies, import the shared one; and extend the shared `ApiError` interface
  (`api.ts:74-84`, which still declares only `details?`) with `errors?: Record<string, string[]>` so the type
  describes the field the new helper reads.

---

## 2. Backend — `AddAttributeValueRequest`

The bug is real: `AttributeService::addValue()` (`AttributeService.php:56-79`) has **no** duplicate guard, so a
repeat `code` hit the DB index `unique(['attribute_id','code'])`
(`database/migrations/tenant/2026_06_02_100002_create_product_attribute_values_table.php:24`) → `QueryException`
→ 500. The `Rule::unique` turns it into a 422. Envelope, `messages()` override and `Rule::unique` scoping are
otherwise correct, and the table has **no** `deleted_at` (only the parent `product_attributes` soft-deletes), so
there is no trashed-row leak in the rule.

### F-2 — MAJOR: the fix introduces a *new* 500 on a malformed `{attributeId}`

```php
// AddAttributeValueRequest.php:26,33-34
$attributeId = $this->route('attributeId');
Rule::unique('product_attribute_values', 'code')
    ->where('attribute_id', is_string($attributeId) ? $attributeId : null),
```

A FormRequest validates **before** the controller body, so this now runs ahead of the existing guard:

```php
// AttributeController.php:68-70
if (! Str::isUuid($attributeId)) { return response()->json(['message' => 'Invalid ID format'], 400); }
```

`routes.php:90` puts no pattern constraint on `{attributeId}`, and no global `Route::pattern` exists.
On PostgreSQL a non-UUID bound against a `uuid` column is a hard error. Proven twice — raw SQL and the **real
Laravel validator** against PG:

```
$ psql … -c "CREATE TABLE t_probe(a uuid, code varchar(64));" \
         -c "SELECT count(*) FROM t_probe WHERE code='x' AND a='not-a-uuid';"
ERROR:  invalid input syntax for type uuid: "not-a-uuid"

$ php artisan tinker --execute 'Validator::make(["code"=>"xl"],
    ["code"=>["required","string", Rule::unique("t_probe","code")->where("a","not-a-uuid")]])->validate();'
THROWN Illuminate\Database\QueryException: SQLSTATE[22P02]: Invalid text representation: 7 ERROR:
invalid input syntax for type uuid: "not-a-uuid"
```

So `POST /api/v1/product-attributes/<garbage>/values` returns **500 on this branch where dev returned 400** —
the exact failure mode the PR set out to remove. This is also the standing repo pitfall
("validate `Str::isUuid()` before `where('uuid', $val)` or it 500s").

**Fix (one line):**

```php
use Illuminate\Support\Str;
->where('attribute_id', is_string($attributeId) && Str::isUuid($attributeId) ? $attributeId : null)
```

With `null` the unique rule passes harmlessly and the controller's 400 guard resumes ownership of the case.
**Add a regression test** (`…/values` with a non-UUID id ⇒ 400, not 500) — PG leg, since sqlite silently accepts
the comparison and will not reproduce it.

### F-7 — LOW: validation ceilings exceed the column widths (same 500 class)

`code` is `max:100` (`AddAttributeValueRequest.php:32`) but the column is `varchar(64)`; `label` is `max:255`
against `varchar(128)` (migration lines 18-19). A 65–100 char `code` still passes validation and 500s at the DB:

```
$ psql … -c "INSERT INTO t_probe(a, code) VALUES (gen_random_uuid(), repeat('x', 80));"
ERROR:  value too long for type character varying(64)
```

Pre-existing, but it is the *same ticket class* and the PR is editing that exact rule array. `max:64` / `max:128`.

### F-4 — MEDIUM: rule 22 / convention 09 (second-of-everything)

`product_attribute_values` is a catalogue table — it is in `CATALOGUE_TABLES`
(`tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:53`) and is called out by name in
`docs/conventions/09-SECOND-OF-EVERYTHING.md` as legacy-too-wide
(`product_attribute_values(attribute_id,code)` — "attribute itself tenant-wide"). The lane touches its
FormRequest, so it is in scope for the three mandated tests. `AddAttributeValueEndpointTest` provisions **one**
tenant and **one** company (`:31-56`).

- **Re-run / idempotency — satisfied.** `test_duplicate_attribute_value_returns_422_with_readable_message` *is*
  the second run and asserts an explicit outcome (422 + a non-empty message), not "no exception".
- **Second company — MISSING.** The new app-layer rule now *cements* the tenant-global scope the convention
  lists for re-scoping. A second-company assertion is required to record the current behaviour (company B sees
  and collides with company A's attribute values) so a later re-scope lane has a red to flip.
- **Second location — N/A**, but convention 09 wants that declared, not silently skipped.

The mechanical half is unaffected and green (see §5).

---

## 3. Frontend forms

**`ProductForm` (DEV-QA-026 / 056) — sound.** `if (!reportBarcodeConflict(error)) reportValidationErrors(error)`
at `ProductForm.tsx:613` and `:696`. `reportBarcodeConflict` (`:530-556`) returns `true` only for
`barcode_identity_conflict`, so there is no double-toast. Errors reach the inputs through the section adapter
(`ProductForm.tsx:984,1000` → `ProductSectionStack.tsx:58` → `ProductGeneralSection`), which is exactly why
`ProductGeneralSection.tsx` is in the diff — **not scope creep**; the `error={errors.unit_id?.message}` +
`error={Boolean(errors.unit_id)}` wiring is the only place a `unit_id` 422 can render, and `UnitDropdown`
already accepts `error?: boolean` (`UnitDropdown.tsx:10`).

**i18n.** `inventory:products.validationBlocked` pre-exists in **en** (`en/inventory.json:178`), **fr**
(`fr/inventory.json:178`) and **ar** (`ar/inventory.json:426`). The two new `pricing:validation.*` keys ship in
en+fr; there is no `ar/pricing.json` — `pricing` is deliberately English-aliased for ar
(`src/lib/i18n.ts:333 → enPricing`), and the completeness gate agrees (§5). No ar gap.

**Design tokens.** Touched lines use `colorTokens.*` throughout (`PriceListForm.tsx:254,257`); `ProductGeneralSection`
already imports `colors/textColors/tokens`. Rule 18 satisfied (but see F-1 for the atom rule).

**Rule 19 (money/quantity).** N/A — `PriceListFormData` (`types.ts:45-54`) carries no monetary or quantity field
(items live on the detail page). `grep parseFloat|Number(` over every touched file: **no hits**. No `MoneyInput`
is warranted here.

**Rule 7 (generated DTOs).** No new hand-rolled domain type; `PriceList*` types are pre-existing.

**Tenant-scoped keys.** Untouched and green (§5). `tenantScope.test.tsx` is in the diff only because two mocks
encoded the *old* (wrong) `createPriceList`/`updatePriceList` return shape — a legitimate fixture correction
(`:82-84`) — but see F-3: the *third* mock in the same block was left wrong.

### F-3 — MAJOR: the PR fixed the write half of the double-unwrap and left the read half live

DEV-QA-047's root cause, as the PR body itself states, is that `apiPost` already unwraps `response.data.data`.
The identical mistake is still in the same file on the read path:

```
apiGet<T>()                       → response.data.data                api.ts:407-410
PricingController::show()         → response()->json(['data' => $priceList])   PricingController.php:88
fetchPriceList()                  → Promise<PriceListResponse>        pricing/api.ts:38
PriceListResponse                 = { data: PriceListDetail }         pricing/types.ts:76-78
```

`fetchPriceList` therefore resolves the **PriceListDetail itself**, but both consumers read `.data` off it:

- `PriceListForm.tsx:63` — `if (existingPriceList?.data)` is always false ⇒ `reset()` never runs ⇒ **the edit form
  renders empty**, and a save would blank `currency`/`valid_from`/`valid_until`.
- `PriceListDetailPage.tsx:65` — `const priceList = data?.data` ⇒ `undefined` ⇒ **the detail page has no data**.

The PR's own fixture edit makes this harder to see rather than easier: in the same `beforeEach` where
`mockCreatePriceList`/`mockUpdatePriceList` were corrected, `mockFetchPriceList.mockResolvedValue({ data: {…} })`
(`tenantScope.test.tsx:82-84`) was left in the pre-unwrap shape — a mock that contradicts the production
contract and green-lights the bug.

Pre-existing on `dev`, so not a regression — but it is the same ticket class, in the same two files, a two-line
fix, and the in-diff fixture now cements it. **Required this round:** fix `fetchPriceList`'s type/consumers (or,
if the owner prefers to keep it out of scope, correct the mock to the real shape so the test goes red and file
the ticket). Do not merge a fixture that asserts the wrong contract.

*(Sibling, out of scope, informational: `fetchPriceLists` has the same shape problem for `{data, meta}` —
`apiGet` drops `meta`, the standing "double-unwrap" pitfall.)*

### F-6 — MEDIUM: `setError` on fields that render no error

`applyServerErrors` (`PriceListForm.tsx:80-88`) maps 8 fields, but only `code` (`:171-173`), `name` (`:188-190`)
and `valid_until` (`:256-258`) have an error renderer. A backend 422 on `currency` (`:193-207`), `description`
(`:209-221`), `valid_from` (`:223-234`), `is_active`/`is_default` (`:261-288`) is written into RHF state and
**never displayed**. It is not fully silent — the form-level alert at `:292-296` shows
`getErrorMessage(mutation.error)`, i.e. Laravel's `ValidationException::getMessage()` (the first field message)
via `bootstrap/app.php:326` — but the PR's stated inline guarantee is unmet for 5 of the 8 fields.
**Fix:** render `errors.X` under those inputs, or trim `PRICE_LIST_FIELDS` to what the form can actually show.

### F-8 / F-9 / F-10 — LOW / INFO

- **F-8** `import { semanticColorTokens … }` sits at `PriceListForm.tsx:23`, *after* the new const + type-guard at
  lines 15-22. Legal (hoisting) and `import/first` is not enabled, but it reads as an accidental merge artefact.
- **F-9** `ProductForm.tsx:582` — when *some* field errors map, the unmapped ones are dropped (only the generic
  `validationBlocked` toast); when none map, `Object.values(fieldErrors)[0]` puts a raw, untranslated backend
  English string into a user-facing toast (rule 11 in spirit). Related: the backend `messages()` strings at
  `AddAttributeValueRequest.php:49-50` are hardcoded English, not `__()` — consistent with the file's existing
  style, so informational only.
- **F-10** Previously any non-barcode error on product save was fully silent; now every class (5xx, 403, network)
  raises a toast (`ProductForm.tsx:570-572`). Intended and an improvement, but broader than the two tickets and
  the non-422 branch has no test.

---

## 4. Tests — falsifiability

| Test | Result | Falsifiable? |
|---|---|---|
| `AddAttributeValueEndpointTest` (sqlite) | **2 ✓, 10 assertions, 4.92s** | **Yes** — without the `Rule::unique` the duplicate POST hits the DB unique index (created on sqlite too, migration line 24) → `QueryException` → 500 ≠ 422 |
| `AddAttributeValueEndpointTest` (PG) | **2 ✓, 12.77s + 2.20s** | as above |
| `ProductForm.serverValidation.test.tsx` | **2 ✓ (624ms)** | **Yes** — on dev, `reportBarcodeConflict` returns `false` and the error is dropped, so neither `findByText` can resolve. Asserts **rendered text**, not call counts |
| `PriceListForm.validation.test.tsx` | **3 ✓ (334ms)** | **Yes** — DEV-QA-047 case explicitly guards `not.toHaveBeenCalledWith('/pricing/price-lists/undefined')`; the date-range case asserts rendered text + `expect(mockCreatePriceList).not.toHaveBeenCalled()` |
| `pricing/__tests__/tenantScope.test.tsx` | **9 ✓ (75ms)** | fixture-only change; see F-3 for the mock left in the wrong shape |
| `ProductForm.test.tsx` (merge check) | **54 ✓ (5.4s)** | dev's autofix survived the auto-merge |
| `ProductGeneralSection.test.tsx` | **2 ✓** | |
| `ProductFormInvalidSubmit.test.tsx` | **3 ✓** | |

> A combined `--poolOptions.forks.singleFork=true` run of the three new/changed web files showed 4 failures.
> That is **my** flag forcing shared-process execution (module-mock + zustand cross-file pollution), not a defect:
> every file is green run on its own, which is how `pnpm test` executes them.

**Backend test quality nits (non-blocking):** the assertions are on data meaning (422 + non-empty message, and
201 for the same code under a second attribute) — good. But it uses `app(PermissionRegistrar::class)` /
`app(CompanyContext::class)` in `setUp` (`:37,55`) — acceptable in a test, though the house style is the
`$this->app->make` / trait helpers used elsewhere. And see F-4 for the missing second company.

---

## 5. Hygiene — verbatim gate output

```
$ ./vendor/bin/pint --test app/Modules/Catalog/Presentation/Requests/AddAttributeValueRequest.php \
                           tests/Feature/Catalog/AddAttributeValueEndpointTest.php
{"result":"pass"}

$ ./vendor/bin/phpstan analyse <the two touched PHP files> --memory-limit=1G --no-progress
 [OK] No errors

$ npx eslint <8 touched TS/TSX files>
✖ 26 problems (0 errors, 26 warnings)          ← 0 ERRORS. Warnings are overwhelmingly pre-existing
                                                  (no-unnecessary-condition, no-non-null-assertion, …); the new
                                                  ones are 3 in the new test files + no-unsafe-type-assertion at
                                                  ProductForm.tsx:577 (`field as keyof ProductFormData`).

$ npx tsc --noEmit -p tsconfig.json
tsc exit=0                                      (swap free 1307 MB at run time — above the 300 MB floor)

$ node tools/audit-tanstack-keys.mjs
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
exit=0

$ node tools/audit-design-system.mjs
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 809 acknowledged, 1 new, 1 stale baseline entries

New design-system violations:
  src/features/pricing/PriceListForm.tsx:241:13 C2 Raw <input> should use the form atom (Input/Select/Textarea)
  (<input type="date" id="valid_until" {...register('valid_until', { validate: (value, formValues) => !value || …)

Stale design-system baseline entries; shrink tools/audit-design-system-baseline.json:
  C2|src/features/pricing/PriceListForm.tsx|<input type="date" id="valid_until" {...register('valid_until')} …|#1
exit=1                                          ← ***RED***

$ pnpm -s audit:i18n:local
i18n completeness OK — 55 namespaces, authored keys: en=9502, fr=9519, ar=5146 authored (1922 behind aliases);
2816 known gap(s) held at the baseline.
exit=0

$ php artisan test -c phpunit-pgsql.xml tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php
  PASS  Tests\Architecture\TenantOnlyUniqueOnCatalogueTablesRatchetTest
  ✓ live tenant only unique indexes match the reviewed baseline   19.11s
  ✓ the core catalogue tables stay in scope
  ✓ every table with a qualifying unique is explicitly classified
  Tests: 3 passed (134 assertions)
```

### F-1 — MAJOR: the design-system gate is red

`audit-design-system.mjs` matches its baseline on the **snippet text**. Editing the `valid_until` input's
`register(...)` call changed that text, so the acknowledged entry goes stale and the same input is counted as a
**new** C2 violation. The script exits **1**, and it is wired into all three gates:

- `apps/web/package.json:10` — `"lint": … && pnpm audit:design-system && …`
- `scripts/preflight.sh:182`
- `.github/workflows/ci.yml:2412`

so **`pnpm lint`, preflight and CI are all red on this branch.**
**Fix:** preferred — migrate the two date inputs to the `Input` atom (which is what C2 asks and what
`ProductGeneralSection` already does); minimum — refresh `tools/audit-design-system-baseline.json` so the entry
matches the new snippet and no stale row remains. Re-run must print `0 new, 0 stale`.

### Scope, commit, routes

- **Scope: clean.** All 12 files are on-mission; nothing outside catalog/pricing validation surfacing. The two
  files that look tangential are justified: `ProductGeneralSection.tsx` is the only renderer for `unit_id`
  errors, and `tenantScope.test.tsx` had mocks encoding the old return shape.
- **Route middleware untouched** — `apps/api/app/Modules/*/Presentation/routes.php` is not in the diff; the
  Catalog group keeps `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` + `can:` per route.
- **Commit hygiene: good.** One commit, conventional subject, an accurate body with a per-ticket root-cause
  table, honest disclosure that the browser e2e was not run, and a `Co-Authored-By` trailer. No build artefacts,
  no `dist/`, no lockfile churn.

---

## Could not verify

1. **No browser / Playwright leg.** The author disclosed this (no running stack, disk full); I did not run one
   either. F-3 (empty edit form / empty detail page) is proven by code + type + controller reading, **not** by a
   live render — it deserves one browser click before or right after any fix.
2. **The DEV-QA registry is not in this repository.** `grep -rl "DEV-QA-0"` over the repo returns only other
   gate reports — never a ticket list. I could not read the original reports for 015/026/045/047/056, so the
   ticket → root-cause mapping in the PR body is unverified at the source; I verified each *claimed* root cause
   against code independently, and all five hold.
3. **`unit_id` inline error end-to-end.** Exercised only through the mocked component test; not against a live
   `units` endpoint / real `UnitDropdown` data load.
4. **Whole-suite runs** were not performed (owner rule: never the full PHPUnit suite; web tests by file). The
   PR's "480/480 web tests pass" claim is therefore un-reproduced here.

---

## What r2 must contain

1. **F-1** — design-system gate green (`0 new, 0 stale`), verbatim output pasted.
2. **F-2** — `Str::isUuid` guard on the `Rule::unique` scope **plus** a PG regression test proving a non-UUID
   `{attributeId}` returns 400, not 500.
3. **F-3** — `fetchPriceList` read path corrected (or, at minimum, the `tenantScope.test.tsx` fetch mock moved to
   the true unwrapped shape + a filed ticket); no fixture may assert the wrong contract.
4. **F-4** — a second-company test on the attribute-value endpoint, and an explicit "second location: N/A because
   …" line in the PR body.
5. **F-5** — the two private `getFieldErrors` copies deleted in favour of the shared export; `ApiError` gains
   `errors?: Record<string, string[]>`.
6. **F-6** — inline renderers for the fields `applyServerErrors` writes to, or a trimmed `PRICE_LIST_FIELDS`.
7. **F-7 / F-8** — column-width ceilings; import moved to the top.

**Merge to local dev: NO.**
