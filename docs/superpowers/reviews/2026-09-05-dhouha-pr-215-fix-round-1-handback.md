# PR #215 — fix round 1 handback (answers gate r1)

| | |
|---|---|
| **PR** | #215 · author `dhouhaferjani-lgtm` · "fix(catalog/pricing): surface validation errors instead of silent rejects" |
| **Gate answered** | [`2026-09-05-dhouha-pr-215-gate-r1.md`](2026-09-05-dhouha-pr-215-gate-r1.md) — verdict CHANGES REQUIRED |
| **Tree** | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-215`, branch `gate/pr-215` |
| **Base** | local dev `d56d62535` (the merge base — `dev` has moved since; all diffs below are `d56d62535..HEAD`) |
| **Fix commits** | `cb7713517`, `222fce906`, `eada7caa1`, `ab54459ad` (on top of merge `2b7994db4`) |
| **Diff vs base** | 20 files, +863 / −186 |
| **Author** | Claude Opus 5, 2026-09-05 |
| **Merged** | **NO** — handed back for r2 |

Every gate finding in the r2 list is addressed. Each claim below was re-verified against the code
before acting; two claims were found to be **larger than the gate stated** (F-3 and the fixture damage)
and are called out explicitly.

---

## Verdict per finding

| # | Sev | Status | Commit |
|---|---|---|---|
| F-1 | MAJOR (CI red) | **Fixed — preferred route** (atoms, not a re-key) | `ab54459ad` |
| F-2 | MAJOR (new 500) | **Fixed + PG regression test, falsified** | `cb7713517` |
| F-3 | MAJOR | **Fixed — and it was bigger than reported (3 surfaces, not 2)** | `eada7caa1` |
| F-4 | MEDIUM | **Fixed** — second-company test + "second location: N/A" declared | `cb7713517` |
| F-5 | MEDIUM | **Fixed** — one exported helper, both copies deleted, `ApiError.errors` added | `222fce906` |
| F-6 | MEDIUM | **Fixed** — all 8 fields render; unmapped fields go to the alert | `ab54459ad` |
| F-7 | LOW | **Fixed** — ceilings match the column widths | `cb7713517` |
| F-8 | LOW | **Fixed** — import moved to the top | `ab54459ad` |
| F-9 / F-10 | LOW / INFO | **Not done** — not in the r2 list; filed as residuals below | — |

---

## F-2 — `Str::isUuid` guard + PG regression (MAJOR)

**Claim verified.** `apps/api/app/Modules/Catalog/Presentation/Requests/AddAttributeValueRequest.php:33-34`
bound the raw route param into `Rule::unique(...)->where('attribute_id', …)`. `attribute_id` is a `uuid`
column (`apps/api/database/migrations/tenant/2026_06_02_100002_create_product_attribute_values_table.php:17`)
and the FormRequest runs before `AttributeController::storeValue()`'s guard
(`apps/api/app/Modules/Catalog/Presentation/Controllers/AttributeController.php:68-70`).
`apps/api/app/Modules/Catalog/Presentation/routes.php:90` puts no pattern on `{attributeId}`.

**Behaviour chosen: the controller's existing 400 contract**, not a new 422 — `storeValue`,
`indexValues` (`AttributeController.php:95-97`) and `destroy` all answer a malformed id with
`{"message":"Invalid ID format"}`, 400, and only `storeValue` has a FormRequest to diverge from that.
Scoping the rule to `null` for a non-UUID makes it a harmless no-match and hands the case straight back
to that guard.

**Regression test:** `test_non_uuid_attribute_id_returns_400_not_500`
(`apps/api/tests/Feature/Catalog/AddAttributeValueEndpointTest.php:120-133`). Driver-agnostic so both
legs run it; only PG can go red.

**Falsified (verbatim).** With the `Str::isUuid` half of the guard removed, PG leg:

```
SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type uuid: "not-a-uuid"
CONTEXT:  unnamed portal parameter $2 = '...' (Connection: pgsql, Host: 127.0.0.1, Port: 5433,
Database: autoerp_test_f215, SQL: select count(*) as aggregate from "product_attribute_values"
where "code" = xl and "attribute_id" = not-a-uuid)

  at tests/Feature/Catalog/AddAttributeValueEndpointTest.php:131
  Tests:    1 failed (1 assertions)
```

## F-7 — validation ceilings

`code` `max:100` → `max:64`, `label` `max:255` → `max:128`, matching migration lines 18-19
(`varchar(64)` / `varchar(128)`). New test `test_over_long_code_and_label_are_rejected_with_422`.

## F-4 — second company (rule 22 / convention 09)

**Key shapes read and cited in the test docblock:** `product_attribute_values` is
`unique(['attribute_id','code'])` with **no** `company_id` (migration line 24), and its parent
`product_attributes` is `unique(['tenant_id','code'])`
(`2026_06_02_100001_create_product_attributes_table.php:24`). Both are **tenant**-wide.

`test_second_company_shares_the_tenant_wide_attribute_value_scope` records that: company B (same
tenant, same user, new `UserCompanyMembership` + `CompanyContext`) **collides** with company A's value
code (422) and is otherwise unblocked (a fresh code still stores, 201). That is the current, too-wide
behaviour — a re-scoping lane now has a red to flip.

- **Re-run / idempotency:** already covered by `test_duplicate_attribute_value_returns_422_...`.
- **Second location: N/A** — neither table carries a `location_id` and the endpoint takes none;
  attribute values are not location-scoped. Declared, not silently skipped.

Also switched the test's two `app()` helper calls to `$this->app->make(...)` (gate's non-blocking nit).

---

## F-3 — the read path was worse than reported: **three** dead surfaces, not two

The gate found the edit form and the detail page. Re-verifying it turned up a **third**, and the gate's
"sibling, out of scope" note understates it:

| fetcher | endpoint | what `apiGet` actually resolves | declared type | consumer | result |
|---|---|---|---|---|---|
| `fetchPriceList` | `PricingController::show():88` → `{data: $priceList}` | `PriceListDetail` | `{data: PriceListDetail}` | `PriceListForm.tsx:63` | `reset()` never ran → **edit form empty** |
| " | " | " | " | `PriceListDetailPage.tsx:65` | `undefined` → **detail page empty** |
| `fetchPriceLists` | `PricingController::index():68` → **raw paginator** | `PriceList[]` | `{data, meta}` | `PriceListListPage.tsx:53` | `data?.data ?? []` → **list page empty** |

`apiGet` returns `response.data.data` (`apps/web/src/lib/api.ts:407-410`, docs/conventions/01) and there
is no success-response envelope middleware (`apps/api/bootstrap/app.php` shapes errors only), so the
index endpoint's raw paginator body means `fetchPriceLists` resolves the page **array**.

**I fixed the sibling too rather than filing it.** It is two lines in the file already being corrected,
and leaving it would re-cement the exact contract this round exists to fix — which is the same objection
the gate raised against the PR's own fixture edit. `PriceListResponse` / `PriceListsResponse` are
deleted: they describe a shape no caller ever receives.

### The fixtures were the load-bearing part

The gate flagged `tenantScope.test.tsx:82-84`. There was more: **`src/features/pricing/pricing.test.tsx`
mocks `apiGet` ITSELF** and stubbed **20** returns as `{data, meta}` / `{data}` envelopes — a shape the
real `apiGet` never returns. That is what green-lit the bug for as long as it lived. Evidence: with the
production fix applied and the old fixtures in place, that file went

```
Test Files  1 failed | 2 passed (3)
     Tests  11 failed | 24 passed (35)
```

All 20 are corrected to the unwrapped payloads (22/22 green), with a header comment stating the rule so
the next author does not reintroduce it.

### Falsifying test

`tenantScope.test.tsx` — *"PriceListForm (edit mode) POPULATES from the real unwrapped fetch shape (F-3)"*
asserts six rendered field **values** (`PL-EDIT`, `Retail edit`, `Seeded description`, `EUR`,
`2026-01-01`, `2026-12-31`), not call counts. Reverting `PriceListForm` to `existingPriceList?.data`:

```
Test Files  1 failed (1)
     Tests  1 failed | 9 skipped (10)
   expect(screen.getByLabelText(/Code/)).toHaveValue('PL-EDIT')
```

---

## F-5 — one `getFieldErrors`

Both private copies deleted, both call sites now import the shared `lib/api` export:

- `apps/web/src/features/partners/PartnerForm.tsx:126` — returned `{}` (not `null`) for an empty bag; its
  only call site already guarded `Object.keys(fieldErrors).length > 0`, so the shared helper's `null` is
  behaviour-identical.
- `apps/web/src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx:102` — the shared
  function almost verbatim, including the `Object.keys(result).length > 0 ? result : null` tail.

Their now-unused `isApiError` imports and the local `hasOwnProperty` helper go with them.
`ApiError.error` gains `errors?: Record<string, string[]>` (`apps/web/src/lib/api.ts:36-48`), matching
the envelope emitted at `apps/api/bootstrap/app.php:324-334`.

**Latent break caught while doing it:** `PartnerForm.test.tsx` mocked the whole `lib/api` module with an
object literal, so after the consolidation `getFieldErrors` would have resolved to `undefined` at that
site — silently, because no existing test drove a mutation error. The mock now passes the **real** export
through `vi.importActual`, and a new test asserts a 422 renders inline there. Falsified: stubbing the
mock to `getFieldErrors: () => null` fails it (`1 failed | 31 skipped`).

---

## F-1 — design-system gate: took the preferred route

Root cause confirmed: `tools/audit-design-system.mjs` keys its baseline on the **snippet text**
(rule C2 at `tools/audit-design-system.mjs:259-274`; `TAG_RE` is case-sensitive so `<Input>` is not a raw
control, `tools/audit-design-system.mjs:26-40`), so editing the `valid_until` `register(...)` call made
the acknowledged entry stale and counted the same input as new.

**Fixed the underlying violation, not the key.** All 8 raw form controls in `PriceListForm.tsx` now use
the canonical atoms (`Input` / `Select` / `Textarea` / `Checkbox`). This is not a visual change: the atom
base classes are the design-token equivalents of the bespoke strings they replace —
`tokens.input.base` (`src/lib/designTokens.ts:863-867`) is
`mt-1 block w-full rounded-[var(--radius-input)] border border-gray-300 px-3 py-2 shadow-[var(--elevation-input)] focus:border-blue-500 …`
against the file's `mt-1 block w-full rounded-lg border … px-3 py-2 shadow-sm …`.

That makes all 8 baseline entries stale. They are removed from
`tools/audit-design-system-baseline.json` by **line surgery — shrink-only, never `--write-baseline`**.
Baseline 810 → 802. The file's one C3 entry (the raw submit `<button>`) is untouched and still
acknowledged: its snippet did not change, so it is neither new nor stale, and migrating it was outside
the finding.

```
$ node tools/audit-design-system.mjs
[sweep-progress] Design-system audit C1-C6 violations: 802
[gate-summary] Design-system baseline: 802 acknowledged, 0 new, 0 stale baseline entries
exit=0
```

---

## F-6 — no server 422 message is dropped

`applyServerErrors` wrote to 8 fields; only `code`, `name`, `valid_until` rendered one. Now:

- all 8 fields have an inline `<p>` renderer, and the atoms carry `error={Boolean(errors.X)}` so the
  control itself shows the error state;
- fields the form has **no** input for are no longer discarded — they are collected into
  `unmappedServerErrors` and listed under the form-level alert. (Previously the alert showed only
  `getErrorMessage(mutation.error)`, i.e. Laravel's first field message.)

Two new tests in `PriceListForm.validation.test.tsx`: one drives a 422 on
`currency`/`description`/`valid_from`/`is_default` and asserts all four rendered strings; one drives a
422 on `company_id` (no such input) and asserts it reaches the alert. Falsified: removing the alert's
`<ul>` and the `errors.currency` renderer fails both (`2 failed | 3 passed`).

## F-8

`import { semanticColorTokens … }` moved back above the top-level const and type guard it had been
placed after (`PriceListForm.tsx:23` pre-fix).

---

## Verification — verbatim

### Backend

```
$ ./vendor/bin/phpunit tests/Feature/Catalog/AddAttributeValueEndpointTest.php
.....                                                               5 / 5 (100%)
Time: 00:07.388, Memory: 165.00 MB
OK (5 tests, 21 assertions)

$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_f215 DB_CENTRAL_DATABASE=autoerp_test_f215 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Catalog/AddAttributeValueEndpointTest.php
   PASS  Tests\Feature\Catalog\AddAttributeValueEndpointTest
  ✓ duplicate attribute value returns 422 with readable message         16.06s
  ✓ same code under a different attribute is allowed                     1.72s
  ✓ non uuid attribute id returns 400 not 500                            1.52s
  ✓ over long code and label are rejected with 422                       1.46s
  ✓ second company shares the tenant wide attribute value scope          4.09s
  Tests:    5 passed (21 assertions)
  Duration: 24.90s

$ ./vendor/bin/pint <the two touched PHP files>
{"result":"pass"}

$ ./vendor/bin/phpstan analyse <the two touched PHP files> --memory-limit=1G --no-progress
 [OK] No errors
```

(private DB `autoerp_test_f215` on 127.0.0.1:5433, created at the start and `DROP DATABASE`d at the end.)

### Web — vitest, by file

```
src/features/pricing/pricing.test.tsx                              22 passed
src/features/pricing/__tests__/tenantScope.test.tsx                10 passed  (was 9)
src/features/pricing/__tests__/PriceListForm.validation.test.tsx    5 passed  (was 3)
src/features/inventory/ProductForm.test.tsx                        54 passed
src/features/inventory/__tests__/ProductForm.serverValidation.test.tsx  2 passed
src/features/inventory/__tests__/ProductFormInvalidSubmit.test.tsx  3 passed
src/features/products/sections/ProductGeneralSection.test.tsx       2 passed
src/features/partners/PartnerForm.test.tsx                         32 passed  (was 31)
src/components/organisms/AddQuickProductModal/AddQuickProductModal.test.tsx  7 passed
src/lib/__tests__/api.unauthorized|csrfRetry|companyScope.test.ts   25 passed
```

### Web — gates

```
$ node tools/audit-design-system.mjs
[sweep-progress] Design-system audit C1-C6 violations: 802
[gate-summary] Design-system baseline: 802 acknowledged, 0 new, 0 stale baseline entries
exit=0

$ node tools/audit-tanstack-keys.mjs
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
exit=0

$ node tools/audit-quantity-display.mjs
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
exit=0

$ pnpm -s audit:i18n:local
i18n completeness OK — 55 namespaces, authored keys: en=9502, fr=9519, ar=5146 authored
(1922 behind aliases); 2816 known gap(s) held at the baseline.
exit=0                                        ← identical to the r1 base line: no new i18n finding

$ npx eslint <the 15 touched TS/TSX files>
✖ 55 problems (0 errors, 55 warnings)          ← 0 ERRORS. All warnings pre-existing style rules
                                                  (no-unnecessary-template-expression, no-non-null-assertion,
                                                  no-unsafe-type-assertion, …) on lines this round did not add.

$ npx tsc --noEmit -p tsconfig.json
tsc exit=0                                     (swap free 1201 MB at run time — above the 300 MB floor)
```

---

## Nothing left red

No gate, test or type check in the list above is failing.

One **advisory, non-gating** note: the repo's pre-commit hook prints "React Doctor found staged
regressions" on any web commit. `npx react-doctor --blocking warning --base d56d62535` reports the same
whole-repo pre-existing findings on `dev` as on this branch, and the tool itself prints *"React Doctor is
not installed in this project"*. It is not in `pnpm lint`, `scripts/preflight.sh` or `.github/workflows/ci.yml`.
Not a blocker for this PR; flagged so the owner can decide whether to install or silence it.

---

## Residuals (filed, not done this round)

1. **`fetchPriceLists` loses the paginator meta** to the `apiGet` unwrap — **sharpened in round 2**, see
   the residual list at the end of this document. Comment at `apps/web/src/features/pricing/api.ts`.
2. **F-9 (LOW, not in the r2 list)** — `ProductForm.tsx:582` still puts a raw untranslated backend English
   string into a toast when *no* field maps. The PriceListForm treatment (route unmapped messages into a
   form-level alert) is the pattern to port.
3. **F-10 (INFO)** — the broadened non-422 error branch on product save (5xx / 403 / network) is still untested.
4. **No browser leg.** F-3 is now proven by a rendering test that asserts field values, but the three
   pricing surfaces (list, edit form, detail page) deserve one live click before promotion — they were
   *all* empty on `dev`, so this is the highest-value manual check in the PR.
5. **Attribute-value re-scoping.** The new second-company test records the tenant-wide key as current
   behaviour. `docs/conventions/09-SECOND-OF-EVERYTHING.md` already lists
   `product_attribute_values(attribute_id,code)` as legacy-too-wide; the red to flip now exists.

## Commits

```
ab54459ad fix(pricing): PriceListForm adopts the form atoms and drops no server 422 (gate r1 F-1, F-6, F-8)
eada7caa1 fix(pricing): correct the read-path double-unwrap that emptied every price-list surface (gate r1 F-3)
222fce906 refactor(web): one getFieldErrors surface for all three call sites (gate r1 F-5)
cb7713517 fix(catalog): scope the attribute-value unique rule to real UUIDs (gate r1 F-2, F-4, F-7)
```

---
---

# Fix round 2 — answers gate r2

| | |
|---|---|
| **Gate answered** | the r2 section of [`2026-09-05-dhouha-pr-215-gate-r1.md`](2026-09-05-dhouha-pr-215-gate-r1.md) — verdict CHANGES, one blocker (R2-1) |
| **Fix commit** | `77ddb32f0` |
| **Author** | Claude Opus 5, 2026-09-05 |
| **Merged** | **NO** |

r2 confirmed every round-1 finding as FIXED and raised exactly one blocker plus two follow-ups. All three
are done.

## R2-1 (BLOCKER) — `partners.test.tsx` went red on the F-5 consolidation

**Claim verified, and it was slightly larger than the gate measured: 3 failures, not 2.**

`src/features/partners/partners.test.tsx:36-44` mocked `../../lib/api` with an object literal that never
exported `getFieldErrors`. Before round 1 that mock was *complete*: `PartnerForm` defined the helper
locally and imported only `isApiError`, which the mock does provide. Once `222fce906` deleted the local
copy, `PartnerForm.tsx:383` called the imported `getFieldErrors` → `undefined`, and
`handleMutationError` threw before reaching `toast.error`.

Measured on `gate/pr-215` @ `023c4e49b` before the fix:

```
× Partner Management > PartnerForm > shows error toast when create mutation fails with generic error
    → expected "spy" to be called with arguments: [ 'Network error' ]
× Partner Management > PartnerForm > shows error toast when create mutation fails with 422 validation error
    → expected "spy" to be called at least once
× Partner Management > PartnerForm > displays field-level error from 422 response under the specific field
    → Unable to find an element with the text: The VAT number format is invalid for the selected country.
 Test Files  1 failed (1)      Tests  3 failed | 46 passed (49)
```

All three are mutation-**error** tests — the only ones that reach the deleted path. This was my miss in
round 1: I hardened `PartnerForm.test.tsx` and did not sweep for other mocks of the same module.

**Fix:** converted to the `vi.importActual` spread, matching what round 1 did for `PartnerForm.test.tsx`.
The real helper now runs, so the 422 tests keep their meaning rather than being satisfied by a stub, and
a future `lib/api` export cannot silently break the file again. The transport functions and the two
spies the tests drive (`getErrorMessage`, `isApiError`) stay overridden, so nothing else changes.

The plain-object error fixtures in this file carry `isAxiosError: true`, which is exactly what
`axios.isAxiosError` checks, so the real `getFieldErrors` (via the module-local real `isApiError`)
recognises them.

```
$ npx vitest run src/features/partners/partners.test.tsx
 ✓ src/features/partners/partners.test.tsx (49 tests) 2532ms
 Test Files  1 passed (1)      Tests  49 passed (49)

$ npx vitest run src/features/partners/PartnerForm.test.tsx
 ✓ src/features/partners/PartnerForm.test.tsx (32 tests) 1266ms
 Test Files  1 passed (1)      Tests  32 passed (32)
```

## The sweep — 6 object-literal mocks at risk, 2 real, 4 structurally immune

The gate noted 87 object-literal `lib/api` mocks and asked which matter. Narrowed mechanically:

- **Only four production files import `getFieldErrors`**: `PartnerForm.tsx`, `ProductForm.tsx`,
  `PriceListForm.tsx`, `AddQuickProductModal.tsx`.
- **200** test files mock `lib/api`; **21** also reference one of those four; **6** of those used an
  object-literal mock without `getFieldErrors`.
- **Four of the six cannot break**: `DocumentLineEditor.test.tsx`,
  `.quantityStep`, `.purchasePriceDefault` and `.refusedFields` all stub
  `AddQuickProductModal: () => null`, so the real module is never loaded. No change.
- **Two are genuine latent gaps** — `src/features/inventory/ProductForm.test.tsx:55` (renders the real
  `ProductForm`) and `src/features/pricing/pricing.test.tsx:19` (renders the real `PriceListForm`).
  Neither fails today because no test in them drives a mutation error — the same condition that made
  `ReviewIngestionPage.test.tsx` latent rather than red.

All three latent files (those two plus `ReviewIngestionPage.test.tsx:26`, the one the gate named) now use
the same spread. Full spread, not a targeted `getFieldErrors` re-export, and verified not to perturb
anything:

```
 ✓ src/features/inventory/ProductForm.test.tsx (54 tests)
 ✓ src/features/pricing/pricing.test.tsx (22 tests)
   src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx — 1 failed | 13 passed
                                                                             (the failure is pre-existing, below)
```

## The two reds that are NOT this PR — proven on dev

Re-measured read-only in the main checkout `/Users/houssamr/Projects/syneriva/apps/erp`, on `dev`
**`7494a0c02`**. The gate measured `143cded50`; that commit is an **ancestor** of `7494a0c02`, and
`git diff 143cded50..HEAD` over `ReviewIngestionPage.test.tsx`,
`SharedSingletons.tenantScope.test.tsx`, `PartnerForm.tsx`, `AddPartnerModal.tsx` and
`ReviewIngestionPage.tsx` is **empty**, so the two runs measure the same code.

```
$ (main checkout, dev 7494a0c02)
npx vitest run src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx                src/components/__tests__/SharedSingletons.tenantScope.test.tsx
 × shared singleton tenant scope > scopes modal invalidations (.001-.003)
     → Unable to find an accessible element with the role "button" and name "common:actions.create"
       TypeError: countries.map is not a function
           at AddPartnerModal (…/src/components/organisms/AddPartnerModal/AddPartnerModal.tsx:370:26)
 × ReviewIngestionPage > posts only PartnerFormData keys when creating a supplier from the review page …
     → expected [ 'city', 'country_code', …(8) ] to deeply equal [ 'address', 'city', 'country', …(7) ]
 Test Files  2 failed (2)      Tests  2 failed | 15 passed (17)
```

Byte-identical failures and messages on `gate/pr-215`. **Pre-existing dev reds — recorded, not fixed**,
per the coordinator's instruction. Neither is on a path this PR touches (`AddPartnerModal` imports only
`apiPost`; `PartnerForm`'s payload-building code is byte-identical to base).

## Round-2 verification — verbatim

```
$ npx vitest run partners.test.tsx PartnerForm.test.tsx ProductForm.test.tsx pricing.test.tsx                  ReviewIngestionPage.test.tsx
 ✓ src/features/pricing/pricing.test.tsx (22 tests) 1616ms
 ✓ src/features/partners/PartnerForm.test.tsx (32 tests) 3628ms
 ✓ src/features/inventory/ProductForm.test.tsx (54 tests) 6286ms
 ✓ src/features/partners/partners.test.tsx (49 tests) 5660ms
 × ReviewIngestionPage > posts only PartnerFormData keys …          ← pre-existing on dev
 Test Files  1 failed | 4 passed (5)      Tests  1 failed | 170 passed (171)

$ npx tsc --noEmit -p tsconfig.json                            exit=0   (swap free 916 MB)
$ npx eslint <the 5 files touched this round>                  ✖ 32 problems (0 errors, 32 warnings)
$ node tools/audit-design-system.mjs
[gate-summary] Design-system baseline: 802 acknowledged, 0 new, 0 stale baseline entries
$ node tools/audit-tanstack-keys.mjs
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
```

Backend is untouched this round (round-1 results stand: 5 passed / 21 assertions on sqlite and PG).

## Residual list — updated

1. **`fetchPriceLists` drops the paginator meta — with a user-visible consequence.** The meta is
   *structurally* unreachable through `apiGet`: it sits on the paginator's top level, which
   `response.data.data` discards. `PricingController::index()` paginates at **20**, so past 20 price
   lists **the index page silently truncates to the first 20, and the header count
   (`PriceListListPage.tsx:73`) plus the filter-tab counts (`:42`) report 20 as the total.** Strictly
   better than the always-empty page it replaces, so it should not hold the merge — but it needs
   `api.get` + `response.data` and a real pagination control to close. The comment in
   `apps/web/src/features/pricing/api.ts` now states this consequence, not just "meta is unread".
2. **Two pre-existing dev reds** (§ above) — `SharedSingletons.tenantScope` (`countries.map is not a
   function`, `AddPartnerModal.tsx:370`) and `ReviewIngestionPage` (payload-keys mismatch). Owner's call;
   not chargeable to this PR.
3. **F-9 (LOW)** — `ProductForm.tsx:582` still toasts a raw untranslated backend English string when no
   field maps. The `PriceListForm` treatment (unmapped messages → form-level alert) is the pattern to port.
4. **F-10 (INFO)** — the broadened non-422 error branch on product save (5xx / 403 / network) is untested.
5. **No browser leg.** All three pricing read surfaces were dead on `dev` and are now proven only by
   rendering tests. Still the highest-value manual check before promotion.
6. **Attribute-value re-scoping.** The second-company test records the tenant-wide key as current
   behaviour; a re-scoping lane now has a red to flip.
7. *Cosmetic, from the r2 nits:* `key={message}` on the unmapped-error `<li>` collides if the backend
   returns two identical messages; and the F-4 second company is built with `Company::factory()` rather
   than the real company-creation path convention 09 prefers (this endpoint has no company dimension to
   seed).

## Commits (round 1 + round 2)

```
77ddb32f0 test(web): lib/api mocks spread the real module so getFieldErrors resolves (gate r2 R2-1)
023c4e49b docs(review): PR #215 fix-round-1 handback (gate r1 answered)
ab54459ad fix(pricing): PriceListForm adopts the form atoms and drops no server 422 (gate r1 F-1, F-6, F-8)
eada7caa1 fix(pricing): correct the read-path double-unwrap that emptied every price-list surface (gate r1 F-3)
222fce906 refactor(web): one getFieldErrors surface for all three call sites (gate r1 F-5)
cb7713517 fix(catalog): scope the attribute-value unique rule to real UUIDs (gate r1 F-2, F-4, F-7)
```
