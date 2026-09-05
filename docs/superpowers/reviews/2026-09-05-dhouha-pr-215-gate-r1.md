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

---
---

# Gate r2 — fix round 1 (PR #215)

| | |
|---|---|
| **Reviewed** | `git diff 2b7994db4 023c4e49b` — 5 commits (`cb7713517`, `222fce906`, `eada7caa1`, `ab54459ad`, `023c4e49b` docs), 16 files |
| **Diff vs base** | `d56d62535..023c4e49b` = 20 files, +1190/−186 (docs included) |
| **Handback** | `docs/superpowers/reviews/2026-09-05-dhouha-pr-215-fix-round-1-handback.md` |
| **Engines** | web vitest (node) · api PHPUnit **sqlite + PG** (private DB `autoerp_test_g215b`, created and dropped) |
| **Reviewer** | Fable 5.1, 2026-09-05 |
| **Verdict** | **CHANGES REQUIRED** — one blocker, everything else resolved |
| **Merge to local dev** | **NO** |

**Eight of the nine r1 findings are genuinely fixed and independently verified.** The round also correctly
widened F-3 (three dead read surfaces, not two) and correctly refused the cheap re-key on F-1. One blocker
remains, and it is a **new regression created by the round itself**.

| r1 # | Status (verified by me, not taken on trust) |
|---|---|
| F-1 | **FIXED** — atoms adopted; `audit:design-system` `802 acknowledged, 0 new, 0 stale`, exit 0 |
| F-2 | **FIXED** — `Str::isUuid` guard; non-UUID id now 400, proven on the PG leg |
| F-3 | **FIXED and correctly widened** — three read surfaces; claim independently re-derived |
| F-4 | **FIXED** — second-company test against the real key, "second location: N/A" declared |
| F-5 | **FIXED in production code — but it broke a test file that the round did not run** (R2-1) |
| F-6 | **FIXED** — all 8 fields render; unmapped 422s routed to the alert |
| F-7 | **FIXED** — `max:64` / `max:128` match the column widths |
| F-8 | **FIXED** — import back at the top |
| F-9 / F-10 | Not done; correctly filed as residuals (out of the r2 list) |

---

## R2-1 — **BLOCKER**: the F-5 consolidation turns a green test file red

`src/features/partners/partners.test.tsx` mocks the module with an **object literal** that never exported
`getFieldErrors`:

```ts
// src/features/partners/partners.test.tsx:36-44
vi.mock('../../lib/api', () => ({
  apiGet: mockApiGet, apiPost: mockApiPost, apiPatch: mockApiPatch, apiDelete: mockApiDelete,
  api: mockApiInstance, getErrorMessage: mockGetErrorMessage, isApiError: mockIsApiError,
}))
```

Before the round, `PartnerForm` defined `getFieldErrors` **locally** (it only needed `isApiError`, which the
mock does provide), so the mock was complete. After `222fce906` deleted the local copy,
`PartnerForm.tsx:383` calls the imported `getFieldErrors`, which resolves to `undefined` under that mock, so
`handleMutationError` throws before reaching `toast.error`.

**Measured on both trees — this is a regression, not a pre-existing red:**

```
# current dev 143cded50 (main checkout)
 ✓ src/features/partners/partners.test.tsx (49 tests) 2493ms

# gate/pr-215 @ 023c4e49b
 × Partner Management > PartnerForm > shows error toast when create mutation fails with generic error
 × Partner Management > PartnerForm > shows error toast when create mutation fails with 422 validation error
   AssertionError: expected "spy" to be called with arguments: [ 'Network error' ]   Number of calls: 0
   TestingLibraryElementError: Unable to find an element with the text:
     The VAT number format is invalid for the selected country.
 Test Files  1 failed | 7 passed (9)   Tests  4 failed | 137 passed (141)
```

The two failures are precisely the two mutation-**error** tests — the only ones that reach the deleted code
path. The round fixed `PartnerForm.test.tsx` (via `vi.importActual`) and stopped there; `partners.test.tsx`
was never run.

**Fix:** add `getFieldErrors` to that mock factory — preferably by converting it to the same
`vi.importActual` spread used in `PartnerForm.test.tsx`, so the real helper runs and the 422 test keeps its
meaning. Then re-run `partners.test.tsx` (49 tests) **and** `PartnerForm.test.tsx` (32).

### The mock sweep the gate asked for

`grep -rn "vi.mock('.*lib/api', () => ({" src` returns **87** object-literal (non-`importActual`) mocks. Only
files that render a `getFieldErrors` consumer *and* drive a mutation error can break. I ran every test file
that references `PartnerForm`, `AddQuickProductModal`, `PriceListForm` or `ProductForm`:

- broken: **`partners.test.tsx`** (above) — the only one.
- green: `PartnerForm.test.tsx` 32 ✓ · `partners/__tests__/tenantScope` 10 ✓ · `partnerIsActiveToggle` 3 ✓ ·
  `partnerListRouteType` 11 ✓ · `PartnerBankAccountsSection` 1 ✓ · `PartnerRoutes.gates` 19 ✓ ·
  `TunisiaLocalization` 2 ✓ · `AddQuickProductModal` 7 ✓ · `DocumentLineEditor` 34 ✓ + `.quantityStep` 3 ✓ +
  `.purchasePriceDefault` 17 ✓ + `.refusedFields` 4 ✓ · `DocumentComponents.tenantScope` 3 ✓ ·
  `LineMappingTable` 7 ✓ · `pricing.test.tsx` 22 ✓ · `ProductForm.test.tsx` 54 ✓.

**Latent (not currently failing, worth a one-line hardening):**
`src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx:26` renders `PartnerForm` behind an
object-literal `@/lib/api` mock with no `getFieldErrors`. Its current tests never hit the error path, so it
does not fail today — but the next error-path test there will.

### Two reds that are **NOT** this PR

Both were measured red on the **current dev checkout (143cded50)** as well:

```
 FAIL src/components/__tests__/SharedSingletons.tenantScope.test.tsx > scopes modal invalidations (.001-.003)
      TypeError: countries.map is not a function  (AddPartnerModal.tsx:370)
 FAIL src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx > posts only PartnerFormData keys …
      expected [ 'city', 'country_code', …(8) ] to deeply equal [ 'address', 'city', 'country', …(7) ]
 Test Files  2 failed | 1 passed (3)
```

Neither is on any path this PR touches (`AddPartnerModal` imports only `apiPost`; `PartnerForm`'s payload
code is byte-identical to base). **Pre-existing dev reds — flagged for the owner, not chargeable here.**

---

## 1. F-2 / F-4 / F-7 — backend

**F-2 verified.** `AddAttributeValueRequest.php:37-39` now computes
`$scopedAttributeId = is_string($attributeId) && Str::isUuid($attributeId) ? $attributeId : null` and binds
that. The chosen status is **400, matching the controller contract** — and that contract is real, not
invented: `AttributeController.php:69`, `:99` and `:122` all answer a malformed id with
`{"message":"Invalid ID format"}`, 400. A non-UUID makes the unique rule a no-match, so the controller guard
(`:68-70`) resumes ownership. `test_non_uuid_attribute_id_returns_400_not_500`
(`AddAttributeValueEndpointTest.php:120-133`) asserts both the status **and** the body string, so a silent
drift to 422 would go red. In r1 I independently proved the underlying mechanism on this same PG instance
(`SQLSTATE[22P02]` from the real Laravel validator), so the guard is addressing a real, measured failure.

**F-7 verified against the migration.** `code` `max:64`, `label` `max:128` — matching
`2026_06_02_100002_create_product_attribute_values_table.php:18-19` (`string('code', 64)`,
`string('label', 128)`). `test_over_long_code_and_label_are_rejected_with_422` drives 65/129 chars; I proved
the pre-fix overflow directly in r1 (`ERROR: value too long for type character varying(64)`).

**F-4 verified against the actual unique key.** The test docblock cites the right keys and I re-read both
migrations: `product_attribute_values` is `unique(['attribute_id','code'])` with **no `company_id`**
(migration line 24) and the parent `product_attributes` is `unique(['tenant_id','code'])`
(`…100001_create_product_attributes_table.php:24`) — both tenant-wide, exactly the shape
`docs/conventions/09-SECOND-OF-EVERYTHING.md` lists as legacy-too-wide.
`test_second_company_shares_the_tenant_wide_attribute_value_scope` provisions company B in the same tenant,
adds a real `UserCompanyMembership`, switches `CompanyContext`, and asserts on **data meaning** (B collides
on `xl` → 422; B stores `xxl` → 201). Re-run/idempotency is the existing duplicate test; "second location:
N/A" is declared with a reason. The `app()` → `$this->app->make()` nit is done.
*Minor, non-blocking:* company B is created with `Company::factory()`, where convention 09 asks for "the real
company-creation path". Acceptable here — this endpoint has no company dimension to seed — but worth a line.

```
$ php artisan test tests/Feature/Catalog/AddAttributeValueEndpointTest.php          # sqlite
  ✓ duplicate attribute value returns 422 with readable message   ✓ same code under a different attribute is allowed
  ✓ non uuid attribute id returns 400 not 500                     ✓ over long code and label are rejected with 422
  ✓ second company shares the tenant wide attribute value scope
  Tests: 5 passed (21 assertions)  Duration: 7.39s

$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_g215b DB_CENTRAL_DATABASE=autoerp_test_g215b \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Catalog/AddAttributeValueEndpointTest.php
  Tests: 5 passed (21 assertions)  Duration: 27.53s
```

---

## 2. F-3 — the widened read-path claim: **verified, and the list-page claim holds**

I re-derived the `fetchPriceLists` claim from source rather than accepting it:

```
PricingController::index()                              apps/api/.../PricingController.php:68
    $priceLists = $query->latest()->paginate(20);
    return response()->json($priceLists);               ← the RAW paginator, no {data,meta} wrapper:
                                                          { current_page, data:[…], …, total }
apiGet<T>(url)                                          apps/web/src/lib/api.ts:407-410
    const response = await api.get<ApiResponse<T>>(url)
    return response.data.data                           ← axios body → paginator → its `data` = the PAGE ARRAY
PriceListListPage.tsx:53 (pre-fix)  const filteredPriceLists = data?.data ?? []
                                                        ← array.data === undefined  ⇒  ALWAYS []
```

**Claim confirmed: the price-list index page rendered permanently empty on dev**, exactly as the handback
states — and there is no success-envelope middleware in `apps/api/bootstrap/app.php` that could have made it
otherwise. Together with the two surfaces r1 found, **all three pricing read surfaces were dead**. That makes
this round's scope extension the right call, not scope creep.

**All three now populate, proven by rendering assertions, not call counts:**
`tenantScope.test.tsx` — *"PriceListForm (edit mode) POPULATES from the real unwrapped fetch shape (F-3)"* —
asserts six field **values** (`PL-EDIT`, `Retail edit`, `Seeded description`, `EUR`, `2026-01-01`,
`2026-12-31`) via `toHaveValue`. `pricing.test.tsx` covers the list rows and the detail page.
`PriceListDetailPage.tsx:65` → `const priceList = data`; `PriceListListPage.tsx:39` → `data ?? []`;
`PriceListForm.tsx:75-77` → `if (existingPriceList) { const data = existingPriceList …`.
The dead `PriceListResponse` / `PriceListsResponse` types are deleted and replaced by an explanatory note in
`types.ts` — `tsc --noEmit` exit 0 proves nothing else referenced them.

### The `pricing.test.tsx` fixture rewrite — **correct for every endpoint**

The file mocks `apiGet` **itself**, so each fixture must be what the real `apiGet` resolves. Checked all 20:

| endpoint | real chain | new fixture | verdict |
|---|---|---|---|
| index (`/price-lists`) | raw paginator → `.data` → **page array** | `mockResolvedValue([])` / `mockResolvedValue(mockPriceLists)` (13×) | **correct** |
| show (`/price-lists/{id}`) | `{data: $priceList}` → `.data` → **the object** | `mockResolvedValue(mockExistingPriceList)` / `(mockPriceListDetail)` (7×) | **correct** |

Both old shapes (`{data, meta}` and `{data}`) were shapes `apiGet` can never return — for the paginated index
in particular, `apiGet` structurally **cannot** deliver `meta`, since meta sits on the paginator's top level
which the unwrap discards. The header comment now states the rule for the next author. This is the right
correction, and it is the load-bearing one: these fixtures are what green-lit the bug for its whole life.

**Residual — `fetchPriceLists` drops the paginator meta: acceptable, with a caveat to file.**
`PriceListListPage` has no pagination control and never reads meta (`grep` for Pagination/cursor/total →
nothing but the local `filteredPriceLists.length`), so nothing is broken *today* and restoring meta means
switching to `api.get` + `response.data`, which is a separate change with its own double-unwrap trap. But the
backend paginates at 20: past 20 price lists the page silently truncates **and the header count
(`PriceListListPage.tsx:73`) reports 20 as the total.** That is strictly better than the always-empty page it
replaces, so it should not hold the merge — but it must stay on the residual list with that consequence
spelled out, not just "meta is unread".

---

## 3. F-5 — consolidation is behaviour-identical (production code)

One exported `getFieldErrors` in `lib/api.ts:110`; both private copies deleted
(`PartnerForm.tsx`, `AddQuickProductModal.tsx`), along with the now-unused `isApiError` imports and the local
`hasOwnProperty` helper. `ApiError.error` gains `errors?: Record<string, string[]>` (`lib/api.ts:41-47`),
matching `apps/api/bootstrap/app.php:324-334`.

Behaviour deltas checked:
- `AddQuickProductModal`'s old copy was the shared function verbatim, tail included → identical.
- `PartnerForm`'s old copy returned `{}` (not `null`) for an empty bag, but its only call site guards
  `if (fieldErrors && Object.keys(fieldErrors).length > 0)` (`PartnerForm.tsx:384`) → identical.

Production behaviour is right. The defect is in the **test fixtures** — R2-1.

---

## 4. F-1 / F-6 / F-8

**F-1 — the preferred route, and the baseline surgery is clean.** All 8 raw controls in `PriceListForm` now
use `Input` / `Select` / `Textarea` / `Checkbox`, each carrying `error={Boolean(errors.X)}`. The baseline
diff is **deletions only — 8 lines, every one keyed `src/features/pricing/PriceListForm.tsx`**; no other
file's key was touched and nothing was added (no `--write-baseline` sweep). 810 → 802. The file's single
remaining entry is the untouched C3 raw submit `<button>`, still acknowledged and neither new nor stale —
verified by grepping the baseline directly.

**F-6 — nothing is dropped now.** All 8 mapped fields have an inline `<p>` renderer (`currency` :232-234,
`description` :249-251, `valid_from` :265-267, `is_active` :305-307, `is_default` :318-320, plus the three
that already had one). Fields the form has no input for go to `unmappedServerErrors` and render as an `<ul>`
under the form-level alert (:337-343), cleared on each submit (:139). Two new falsifiable tests: one drives a
4-field 422 and asserts all four rendered strings; one drives `company_id` (no such input) and asserts it
reaches the alert. *Nit:* `key={message}` on the `<li>` collides if the backend returns two identical
messages — cosmetic.

**F-8** — `import { semanticColorTokens … }` is back above the top-level const (`PriceListForm.tsx:12`).

---

## 5. Re-run — verbatim

```
# vitest, by file (batched; per-file isolation, no --singleFork)
 ✓ src/features/pricing/pricing.test.tsx (22)          ✓ src/features/pricing/__tests__/tenantScope.test.tsx (10)
 ✓ .../PriceListForm.validation.test.tsx (5)           ✓ src/features/inventory/ProductForm.test.tsx (54)
 ✓ .../ProductForm.serverValidation.test.tsx (2)       ✓ .../ProductFormInvalidSubmit.test.tsx (3)
 ✓ src/features/products/sections/ProductGeneralSection.test.tsx (2)
 Test Files 7 passed (7)   Tests 98 passed (98)          ← pricing total 37, as claimed

 ✓ PartnerForm.test.tsx (32) · partners/__tests__/tenantScope (10) · partnerIsActiveToggle (3)
 ✓ partnerListRouteType (11) · PartnerBankAccountsSection (1) · TunisiaLocalization (2) · PartnerRoutes.gates (19)
 × partners.test.tsx  → 2 failed                        ← R2-1
 × ReviewIngestionPage.test.tsx → 1 failed              ← pre-existing on dev
 Test Files 2 failed | 7 passed (9)   Tests 4 failed | 137 passed (141)

 ✓ AddQuickProductModal (7) · DocumentLineEditor (34) · .quantityStep (3) · .purchasePriceDefault (17)
 ✓ .refusedFields (4) · DocumentComponents.tenantScope (3) · LineMappingTable (7)
 ✓ src/lib/__tests__/api.companyScope (16) · api.csrfRetry (5) · api.unauthorized (4)
 × SharedSingletons.tenantScope.test.tsx → 1 failed     ← pre-existing on dev
 Test Files 1 failed | 10 passed (11)   Tests 1 failed | 102 passed (103)

# backend — see §1: 5 passed (21 assertions) on sqlite AND PG

$ node tools/audit-design-system.mjs
[sweep-progress] Design-system audit C1-C6 violations: 802
[gate-summary] Design-system baseline: 802 acknowledged, 0 new, 0 stale baseline entries      exit=0

$ node tools/audit-tanstack-keys.mjs
[sweep-progress] Gate C — …queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries               exit=0

$ node tools/audit-quantity-display.mjs
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale)            exit=0

$ pnpm -s audit:i18n:local
i18n completeness OK — 55 namespaces, authored keys: en=9502, fr=9519, ar=5146 authored
(1922 behind aliases); 2816 known gap(s) held at the baseline.                                exit=0

$ ./vendor/bin/pint --test <the 2 touched PHP files>          {"result":"pass"}
$ ./vendor/bin/phpstan analyse <the 2 touched PHP files>       [OK] No errors
$ xargs npx eslint < (the 15 touched TS/TSX files)             ✖ 55 problems (0 errors, 55 warnings)
$ npx tsc --noEmit -p tsconfig.json                            exit=0   (swap free 1310 MB)

$ git merge-tree --write-tree --name-only 143cded50 023c4e49b
exit=0 — 3b59062dfb4fd048a473fbf3165fffe922ada8aa   ← NO CONFLICTS against current dev
```

---

## Could not verify (r2)

1. **Still no browser leg.** All three pricing surfaces were dead on `dev` and are now proven only by
   rendering tests. This remains the single highest-value manual check before promotion — the handback says
   the same.
2. **The handback's falsification runs** (guard removed → 22P02; `existingPriceList?.data` restored → the
   populate test fails; mock stubbed to `() => null` → the PartnerForm 422 test fails) were **not re-executed
   here** — the worktree is read-only for this gate. Each is structurally consistent with what I measured
   independently, and for F-2 I reproduced the underlying 22P02 myself in r1.
3. **DEV-QA registry** — still not in the repository (`grep -rl "DEV-QA-0"` returns only gate reports).
4. **Whole-suite / CI run** not performed (owner rule). Only the named files were run.
5. The **React Doctor pre-commit noise** the handback flags was not investigated; it is in none of
   `pnpm lint`, `scripts/preflight.sh`, `.github/workflows/ci.yml`, so it gates nothing.

---

## What r3 must contain

1. **R2-1** — `partners.test.tsx`'s `lib/api` mock exports `getFieldErrors` (preferably via
   `vi.importActual`); `partners.test.tsx` **49/49** and `PartnerForm.test.tsx` **32/32** pasted verbatim.
2. Optional, one line: the same hardening for `ReviewIngestionPage.test.tsx`'s mock (latent, not failing).
3. Residual list updated so the `fetchPriceLists` meta entry records the **>20 truncation + wrong header
   count**, not just "meta is unread".

Nothing else is outstanding. With R2-1 fixed this is a **MERGE**.

**Merge to local dev: NO.**
