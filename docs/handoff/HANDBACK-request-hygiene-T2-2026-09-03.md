# HANDBACK — Request Hygiene Phase A, Task 2

**Bounded, server-filtered stock-movement reads (S-2 inventory half)**

- Date: 2026-09-03
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t2`
- Branch: `lane/rh-t2-stock-movements`
- Base: `b133caf21` (`docs(request-hygiene): plan rev 9 …, gate r8`)
- Plan: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 2:` (dispatch-ready at gate r8)
- Commits: see the **Commits** section below.

---

## 1. What landed

| File | Change |
|---|---|
| `apps/api/app/Modules/Inventory/Presentation/Requests/ListStockMovementsRequest.php` | **NEW.** Validates every accepted input: `location_id`, `location_ids[]`, `product_id`, `movement_type` (enum values + `transfer`), `reason` (enum values + `write_off`), `search` (`max:120`), `page` (`min:1`), `per_page` (`min:1,max:100`). |
| `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php` | `index(ListStockMovementsRequest)`; consumes only `validated()`. Unpaged branch **deleted** — every call is paginated (default 25, max 100) and returns the six-field meta. Server-side `search` (portable `LOWER(col) LIKE ? ESCAPE '!'` over `stock_movements.reference`, `products.name`, `products.sku`), `movement_type=transfer` → `transfer_in`/`transfer_out`, `reason=write_off` → `write_off`/`expiry`/`damage`. Deterministic `created_at DESC, id DESC`. `resolveSourceDocument()` (one query per row) replaced with ONE bulk `Document` lookup per page keyed by id. |
| `apps/api/tests/Feature/Inventory/StockMovementTest.php` | +165 lines: `ledgerRow()` helper and 7 new tests (cap, transfer alias, write-off alias, tri-column search, literal `%`/`_`, 121-char rejection, tied-`created_at` page-boundary sequence). |
| `apps/web/src/features/inventory/StockMovementsPage.tsx` | `page`/`perPage` state, filter-change page reset, all filters sent as server params, `placeholderData: keepPreviousData`, client-side filtering **deleted**, `FilterTabs` counts removed, subtitle uses `data?.meta.total`, real `OffsetPagination` rendered below `DataTable`. Query key is `locationScopedKey(['stock-movements', search, filter, page, perPage], scope)`. |
| `apps/web/src/features/inventory/StockMovementsPage.test.tsx` | Hoisted `apiGetMock` + `queryCapture`, `beforeEach` reset, real `../../lib/api` preserved via `importActual`, `useQuery` captures options, `mockReturn` keeps both rows and gains six-field `meta`, new executable pagination/DOM test. |
| `apps/web/src/features/inventory/__tests__/tenantScope.test.tsx` | Key fixture → `['stock-movements', '', 'all', 1, 25, { locScope: 'all' }]`; `/stock-movements` branch added to `mockApiGet` so the fixture carries the meta the endpoint now always returns (see Deviation D2). |
| `apps/web/src/features/stock-adjustments/__tests__/queries.test.tsx` | Key fixture → `['stock-movements', '', 'all', 1, 25, { locScope: 'all' }, tenant, company]`. |
| `apps/web/e2e/money-campaign/w4-support.ts` | `stockMovements()` now pins `page=1&per_page=100` and asserts `meta.last_page === 1`, so a W4 scenario that outgrows one page fails loudly instead of silently reading page 1. |

Untouched, as the plan requires: `ProductMovementsTab.tsx` (already sends `product_id`/`page`/`per_page`/`location_ids[]` — all still valid), `DocumentForm.tsx`, `ProductController.php`, Inventory Counting, and all locale files (Step 7: `common:pagination` verified present in en/fr/ar with the same 8 keys — `item, items, next, of, page, previous, rowsPerPage, showing`).

---

## 2. Environment setup (per the dispatch brief)

```
cp -R apps/api/vendor .worktrees/rh-t2/apps/api/vendor
cp apps/api/.env .worktrees/rh-t2/apps/api/.env
cd .worktrees/rh-t2/apps/api && composer dump-autoload
```
```
Generated optimized autoload files containing 19583 classes
```

Private PG database for this lane (never the shared default):
```
createdb -h 127.0.0.1 -p 5433 -U autoerp autoerp_test_t2
→ CREATED
```

---

## 3. Step-by-step evidence

### Step 1–2 — RED on both drivers

**SQLite** — `cd apps/api && php artisan test tests/Feature/Inventory/StockMovementTest.php`
```
  ✓ can list stock movements
  ✓ stock movement list exposes product unit quantity decimals
  ✓ can filter movements by product
  ✓ can filter movements by type
  ✓ list exposes source document provenance
  ✓ movements are ordered by date descending
  ✓ the four raw stock write endpoints no longer exist
  ✓ a negative adjustment is refused at the reserved aware boundary
  ✓ unauthorized user cannot write stock
  ⨯ index without page is bounded to 25
  ⨯ transfer alias is server side across pages
  ⨯ write off alias is server side across pages
  ⨯ search matches product name sku and movement reference
  ⨯ search treats percent and underscore as literals
  ⨯ search rejects 121 characters
  ✓ tied created at rows cross two pages without duplicates or omission…

  Tests:    6 failed, 10 passed (45 assertions)
  Duration: 17.06s
```

**PostgreSQL** — `DB_DATABASE=autoerp_test_t2 DB_CENTRAL_DATABASE=autoerp_test_t2 php artisan test -c phpunit-pgsql.xml tests/Feature/Inventory/StockMovementTest.php`
```
  ⨯ index without page is bounded to 25
  ⨯ transfer alias is server side across pages
  ⨯ write off alias is server side across pages
  ⨯ search matches product name sku and movement reference
  ⨯ search treats percent and underscore as literals
  ⨯ search rejects 121 characters

  Tests:    6 failed, 10 passed (45 assertions)
  Duration: 84.62s
```

Identical 6 failures on both drivers. (The tied-`created_at` test is green pre-change on both: with only `ORDER BY created_at DESC` the engines happened to return a stable sequence for this fixture. It is retained because it is the only assertion that pins the `id DESC` tiebreak; its falsifying power is structural, not incidental — see Deviation D3.)

### Step 3–4 — GREEN, SQLite

`cd apps/api && php artisan test tests/Feature/Inventory/StockMovementTest.php`
```
  ✓ index without page is bounded to 25                                  0.94s
  ✓ transfer alias is server side across pages                           1.19s
  ✓ write off alias is server side across pages                          1.18s
  ✓ search matches product name sku and movement reference               1.24s
  ✓ search treats percent and underscore as literals                     1.15s
  ✓ search rejects 121 characters                                        1.25s
  ✓ tied created at rows cross two pages without duplicates or omission… 1.20s

  Tests:    16 passed (60 assertions)
  Duration: 26.66s
```

### Step 3–4 — GREEN, PostgreSQL

`DB_DATABASE=autoerp_test_t2 DB_CENTRAL_DATABASE=autoerp_test_t2 php artisan test -c phpunit-pgsql.xml tests/Feature/Inventory/StockMovementTest.php`
```
  ✓ search matches product name sku and movement reference               1.93s
  ✓ search treats percent and underscore as literals                     2.63s
  ✓ search rejects 121 characters                                        1.84s
  ✓ tied created at rows cross two pages without duplicates or omission… 1.52s

  Tests:    16 passed (60 assertions)
  Duration: 166.37s
```

The `ESCAPE '!'` predicate is proven portable: literal `%` and literal `_` each return exactly one row on **both** drivers.

### PHPStan level 8

`cd apps/api && ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G app/Modules/Inventory/Presentation/Controllers/StockMovementController.php app/Modules/Inventory/Presentation/Requests/ListStockMovementsRequest.php`
```
Note: Using configuration file …/.worktrees/rh-t2/apps/api/phpstan.neon.
 2/2 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

 [OK] No errors
```

The project's `phpstan.neon` analyses `app/` only. Analysing the test file explicitly surfaces one error, and it is **pre-existing** — see Deviation D1.

### Pint

`./vendor/bin/pint --test <3 touched php files>`
```
{"result":"pass"}
```

### Step 5–6, 8 — frontend tests

`cd apps/web && pnpm vitest run src/features/inventory/StockMovementsPage.test.tsx src/features/inventory/__tests__/tenantScope.test.tsx src/features/stock-adjustments/__tests__/queries.test.tsx`
```
 ✓ src/features/stock-adjustments/__tests__/queries.test.tsx (5 tests) 4ms
 ✓ src/features/inventory/StockMovementsPage.test.tsx (5 tests) 217ms
 ✓ src/features/inventory/__tests__/tenantScope.test.tsx (8 tests) 220ms

 Test Files  3 passed (3)
      Tests  18 passed (18)
```
(No `Errors` line — the unhandled `meta` exception seen on the first run is fixed; see Deviation D2.)

Verbose confirmation the new test actually runs:
```
 ✓ … > requests bounded server filters and renders the real OffsetPagination DOM 72ms
 Test Files  1 passed (1)
      Tests  5 passed (5)
```

**Falsification check** — temporarily removed the two `params.append('page'|'per_page', …)` lines from `StockMovementsPage.tsx` and re-ran:
```
 × … > requests bounded server filters and renders the real OffsetPagination DOM 12ms
AssertionError: expected last "spy" call to have been called with [ Array(1) ]
      Tests  1 failed | 4 passed (5)
RESTORED
```
The new test is falsifying, not decorative.

### typecheck

`cd apps/web && pnpm typecheck`
```
> @autoerp/web@0.1.0 typecheck …
> tsc --noEmit
(no output — clean)
```

### eslint (touched files)

`pnpm exec eslint src/features/inventory/StockMovementsPage.tsx src/features/inventory/StockMovementsPage.test.tsx src/features/stock-adjustments/__tests__/queries.test.tsx src/features/inventory/__tests__/tenantScope.test.tsx`
```
✖ 15 problems (0 errors, 15 warnings)
```
**0 errors.** All 15 warnings are in `tenantScope.test.tsx` and are pre-existing (`require-await`, `restrict-template-expressions`, `no-unsafe-type-assertion`, `array-type`, `react-hooks/globals` at lines 117/128/343/345/350/357/367/369/374/381/433/456/459×2/467 — none on a line this lane wrote). `StockMovementsPage.tsx`, `StockMovementsPage.test.tsx` and `queries.test.tsx` report **zero** problems.

`w4-support.ts` is inside `apps/web/e2e/`, which the ESLint config ignores and which is outside the `parserOptions.project` tsconfig, so it cannot be linted (`--no-ignore` → `Parsing error: … file was not found in any of the provided project(s)`). Not introduced by this lane.

### Repo audits touching this lane

- `pnpm audit:keys` — FAILS on `src/features/uom/hooks/useUnits.ts:53` (a `tenantScopedKey` no-op invalidate filter). **Pre-existing**: that file is not in this lane's diff (last touched by `5edc719a9`, another lane). The two stock-movement keys this lane changed are not reported.
- `pnpm audit:design-system` — FAILS on `src/features/import/pages/ImportWizardPage.tsx` C3 entries. Grepping the report for `StockMovements|OffsetPagination` returns nothing.
- `pnpm audit:i18n:local` — FAILS on `ar|uom|*` and `fr|import|plural`. Grepping the report for `inventory` returns nothing.

All three are pre-existing lane-base debt in files this lane does not touch.

### Step 10 — full path-scoped verification, both drivers

**SQLite** — `php artisan test tests/Feature/Inventory/StockMovementTest.php tests/Feature/Inventory/StockMovementLocationFilterTest.php tests/Feature/BatchExpiry/ReverseWriteOffRouteTest.php tests/Feature/Inventory/InventoryTenantIsolationTest.php`
```
  Tests:    59 passed (185 assertions)
  Duration: 106.44s
```

**PostgreSQL** — same four paths with `DB_DATABASE=autoerp_test_t2 DB_CENTRAL_DATABASE=autoerp_test_t2 … -c phpunit-pgsql.xml`
```
  Tests:    59 passed (185 assertions)
  Duration: 295.04s
```

`InventoryTenantIsolationTest.php:713` and `:811` are the existing no-`page` consumers; both are green on both drivers, proving they now receive a bounded page one plus metadata without changing their assertions.

### Step 10 (live stack) and Step 11 (browser probe) — NOT RUN

No local stack is running in this session:
```
curl --max-time 3 localhost:8010/api/v1/health → 000
curl --max-time 3 localhost:8000/api/v1/health → 000
curl --max-time 3 localhost:5173             → 000
curl --max-time 3 localhost:8011/api/v1/health → 000
```
Therefore:
- **Browser probe not run.** The Step 11 checks (product-name / SKU / reference / literal `%` / literal `_` / transfer / write-off searches issuing server params, global totals on page 2, pager changing requests) are **owed** before promotion.
- **W4 Playwright specs not run.** `inventory-costing.spec.ts`, `inventory-counting.spec.ts`, `inventory-opening.spec.ts`, `inventory-stock.spec.ts` are **owed** against a live W4 stack; the new `expect(meta.last_page).toBe(1)` bound in `stockMovements()` is unexercised until then.

---

## 4. Deviations from the plan text

**D1 — PHPStan on the test file surfaces a pre-existing error.**
`tests/Feature/Inventory/StockMovementTest.php` line 264 (line 260 before this lane's 4 added imports): `Parameter $reference of method StockAdjustmentService::receive() expects string, string|null given` — `reference: $document->document_number`. Proven pre-existing by running PHPStan on the **pristine** file in the main checkout (`apps/erp/apps/api`), which reports the identical error at line 260. The project's `phpstan.neon` sets `paths: [app/]`, so tests are not analysed by the gate. Not fixed — out of lane scope (rule 4). Reviewer may want it as a separate cleanup.

**D2 — `tenantScope.test.tsx` needed one fixture branch beyond the plan's Step 8.**
Plan Step 8 changes only the key assertion. Running it revealed a real crash: that file's `mockApiGet` fallback returns `{ data: { data: [] } }` with **no** `meta`, and `data?.meta.total` (the plan's own Step 6 subtitle expression) throws `TypeError: Cannot read properties of undefined (reading 'total')` at `StockMovementsPage.tsx:359`, surfacing as a Vitest unhandled error. Fixed by adding a `/stock-movements` branch to that mock returning the six-field meta the endpoint now **always** returns — i.e. the fixture was made to match the new contract rather than the page made to tolerate a shape the server can no longer emit. `apps/web/src/features/inventory/__tests__/tenantScope.test.tsx:128-138`. Page code is unchanged from the plan text.

**D3 — filter-change page reset uses derived state during render, not `useEffect`.**
Plan Step 6 prescribes:
```tsx
useEffect(() => { setPage(1) }, [searchQuery, movementFilter, scope])
```
That form is the only new ESLint warning this lane would have introduced (`react-hooks/set-state-in-effect` at `StockMovementsPage.tsx:130`, rule severity `warn` per `eslint.config.js:108`), and no other paginated page in `apps/web/src/features` uses it — every existing consumer (`ModifierGroupListPage`, `CompositeItemListPage`, `MemberListPage`, `StockByLocationPage`, …) resets in the change handler. A handler-only reset cannot cover `scope`, which changes from the shared view-scope store, so the reset is instead performed with React's documented adjust-state-during-render pattern at `apps/web/src/features/inventory/StockMovementsPage.tsx:127-137`:
```tsx
const filterSignature = JSON.stringify([searchQuery, movementFilter, scope])
const [appliedFilterSignature, setAppliedFilterSignature] = useState(filterSignature)
if (appliedFilterSignature !== filterSignature) {
  setAppliedFilterSignature(filterSignature)
  setPage(1)
}
```
Same intent and the same three dependencies, one ESLint warning fewer, and strictly better behaviour: the effect version would let one request for the now-stale page escape before the reset committed. `useEffect` was consequently dropped from the React import.

**D4 — pagination wrapper element.**
Plan Step 6 says "Render below DataTable". `DataTable` sits inside the `error ? … : …` ternary, so the `OffsetPagination` block and `DataTable` are wrapped in a single `<div>` to keep the ternary a single JSX expression (`StockMovementsPage.tsx:381-433`). No styling change.

**D5 — `filter()` callback parameter typed `mixed`.**
Plan Step 4 writes `->filter(static fn ($id): bool => is_string($id))`; an untyped parameter is an implicit `mixed` in a strict-types file, so it is written `static fn (mixed $id): bool` (`StockMovementController.php:130`). PHPStan level 8 clean.

**D6 — `DOCUMENT_REFERENCE_TYPES` extracted as a class constant.**
Plan Step 4 inlines `['Document', Document::class]` in `index()` and asks `formatMovement()` to resolve "only for the two accepted document reference types". Both sites now read one private const (`StockMovementController.php:41`) so the page-level `whereIn` and the per-row lookup cannot drift apart.

**D7 — commit messages.**
The plan names no commit message for Task 2, so conventional lane-scoped messages were used (see below).

---

## 5. Commits

All path-scoped. `vendor/` and `.env` are **not** committed (both are untracked-by-design copies made per the dispatch brief; `git status` after the last commit shows a clean tree).

| Hash | Message | Paths |
|---|---|---|
| `90bae8431` | `fix(rh-t2): bound and server-filter stock-movement reads (S-2 inventory half)` | `apps/api/app/Modules/Inventory/Presentation/Requests/ListStockMovementsRequest.php`, `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php`, `apps/api/tests/Feature/Inventory/StockMovementTest.php` |
| `cf6c11ea6` | `fix(rh-t2): paginate StockMovementsPage against the bounded endpoint` | `apps/web/src/features/inventory/StockMovementsPage.tsx`, `apps/web/src/features/inventory/StockMovementsPage.test.tsx`, `apps/web/src/features/inventory/__tests__/tenantScope.test.tsx`, `apps/web/src/features/stock-adjustments/__tests__/queries.test.tsx`, `apps/web/e2e/money-campaign/w4-support.ts` |
| `<this commit>` | `docs(rh-t2): T2 handback` | `docs/handoff/HANDBACK-request-hygiene-T2-2026-09-03.md` |

### React Doctor

The repo's `pre-commit` hook (`.git/hooks/pre-commit`, non-blocking — it prints to stderr and does not fail the commit) reported "React Doctor found staged regressions" on the frontend commit. That message is emitted for **any** non-zero exit of the wrapper, including a failed tool invocation. Re-run authoritatively against the exact five changed files:

`npx react-doctor --scope changed --base 90bae8431 --blocking warning`
```
✔ Scanned 5 files in 13.0s

React Doctor — rh-t2
Score: 93 / 100 Great

✔ No issues found!
```
React Doctor is not installed as a project dependency here, so the hook falls through to `npx`/`pnpm dlx` from the repo root; the scoped scan above is the real result.

---

## 6. What the reviewer should look at

Gate: **inventory-costing-reviewer** + **frontend-conventions-reviewer** (plan Step 11).

1. **The removed unpaged branch is a contract break for any client that read the whole ledger.** `index()` no longer returns an unbounded array when `page` is absent. In-repo consumers are covered (`InventoryTenantIsolationTest:713,811`, `StockMovementLocationFilterTest`, `ProductMovementsTab`, W4 helper). Plan Phase 0 requires an inventory of **external POS/mobile consumers** before promotion; none exist in this checkout, so external-owner contract evidence is still owed.
2. **`ESCAPE '!'` escaping order.** `str_replace(['!','%','_'], ['!!','!%','!_'], mb_strtolower($search))` — verify the `!`-first ordering cannot double-escape (it cannot: later passes only introduce `!`, never re-scan it) and that `whereRaw` binds the pattern rather than interpolating. `StockMovementController.php:82-95`.
3. **`orWhereHas('product', …)` inside the search closure.** Confirm the correlated subquery keeps the tenant/company predicates effective (they are applied on the outer `stock_movements` query) and that `products.name`/`products.sku` are unambiguous inside the `EXISTS`.
4. **`created_at DESC, id DESC` on PostgreSQL vs SQLite.** The page-boundary test computes the expected sequence in PHP with `strcmp` on the canonical UUID strings and asserts it **exactly** across two pages on both drivers. Verify that reasoning holds for the deployed `id` column type.
5. **Bulk document lookup.** `formatMovement()` now reads from a per-page `Collection` keyed by id. Verify no movement with a Document `reference_type` can silently lose its `source_document_id`/`source_document_type` (covered by `test_list_exposes_source_document_provenance`, green on both drivers).
6. **Removed `FilterTabs` counts.** Deliberate: a single page cannot supply global counts for the unselected tabs. Confirm this is acceptable UX for tenant #1 or raise it.
7. **Deviation D3** (derived-state reset) and **D2** (fixture meta) are the two judgement calls in this lane.
8. **Owed before promotion:** the Step 11 browser probe and the four W4 Playwright specs — neither could run (no local stack).

---

## Fix round 1 (2026-09-04)

Both gates returned **CHANGES**:

- `docs/superpowers/reviews/2026-09-04-request-hygiene-t2-gate-inventory-costing.md` (B1, B2, B3)
- `docs/superpowers/reviews/2026-09-04-request-hygiene-t2-gate-frontend-conventions.md` (B1, B2)

This round implements the **four code items** from those two reports. Scope was held to exactly those four; §"Not done in this round" below lists what the gates asked for that is deliberately still open.

### Environment deviation (disclosed)

The reserved PG at `127.0.0.1:5433` is still held by an unrelated container (`locaplex-postgres`), and the reviewer's throwaway `rht2-review-pg` was already removed. This round ran its PG leg against a lane-private `timescale/timescaledb:latest-pg16` container `autoerp_pg_t2` on `127.0.0.1:5452`, database `autoerp_test_t2`, user `autoerp`. Never the shared default DB. Left running for the next round.

```
docker run -d --name autoerp_pg_t2 --shm-size=1g -p 127.0.0.1:5452:5432 \
  -e POSTGRES_USER=autoerp -e POSTGRES_PASSWORD=autoerp_secret \
  -e POSTGRES_DB=autoerp_test_t2 timescale/timescaledb:latest-pg16
```

---

### Item 1 — B1 (backend): the tie-break test is now falsifying

**Diagnosis stands.** `StockMovement` uses `HasUuids`, Laravel 12's `newUniqueId()` returns `Str::uuid7()`, so model-generated ids are **time-ordered**: insertion order already encoded `id` order and the old fixture could not distinguish a working tie-break from the engine's incidental row order.

**Change (test only —** `tests/Feature/Inventory/StockMovementTest.php`**).** 30 explicit v4-shaped ids `7f000000-0000-4000-8000-%012x` (sequence 1..30) assigned via `forceFill(['id' => …])`, with `created_at`/`updated_at` pinned to a single tied instant. Insertion permutation `1,3,5,…,29,30,28,…,2` — the first row inserted holds the **lowest** id and the last holds the **second-lowest**, so `id DESC` matches neither insertion order nor its reverse. `$expectedIds` is computed from the ids themselves with `rsort($ids, SORT_STRING)`, never from a query.

**Proof it now falsifies.** `->orderByDesc('id')` deleted from `StockMovementController.php:121`, both drivers:

```
# SQLite — tie-break REMOVED
$ php artisan test tests/Feature/Inventory/StockMovementTest.php \
    --filter test_tied_created_at_rows_cross_two_pages_without_duplicates_or_omissions
  at tests/Feature/Inventory/StockMovementTest.php:590
  ➜ 590▕         self::assertSame($expectedIds, $actualIds);
  Tests:    1 failed (6 assertions)
  Duration: 3.43s

# PostgreSQL — tie-break REMOVED
$ DB_HOST=127.0.0.1 DB_PORT=5452 DB_DATABASE=autoerp_test_t2 DB_CENTRAL_DATABASE=autoerp_test_t2 \
    php artisan test -c phpunit-pgsql.xml tests/Feature/Inventory/StockMovementTest.php --filter …
  ➜ 590▕         self::assertSame($expectedIds, $actualIds);
  Tests:    1 failed (6 assertions)
  Duration: 11.18s
```

Both diffs showed the same shape — the actual sequence came back in odd-then-even insertion order (`…13, 11, 0f, 0d, 0b, 09, 07, 05, 03, 01`) instead of `id DESC`. Contrast the gate's measurement of the OLD fixture, which **passed** on both drivers with the same mutation.

**Restored and re-verified.** `git checkout --` on the controller; `git diff` on `StockMovementController.php` is **empty** (0 lines) — the controller is byte-identical to `ae0921a2c`, item 2 touched only the FormRequest. Green on both drivers in the full run below.

### Item 2 — B2 (backend): `nullable` on the optional scalar filters

`ConvertEmptyStringsToNull` turns `?search=` into a **present null**, which `sometimes` does not skip and `string` rejects — a 422 where the pre-lane endpoint returned 200.

**RED first**, new test `test_index_accepts_cleared_filters_sent_as_empty_strings` against the unfixed FormRequest:

```
$ php artisan test tests/Feature/Inventory/StockMovementTest.php \
    --filter test_index_accepts_cleared_filters_sent_as_empty_strings
   FAILED  … > index accepts cleared filters sent as empty strings
  Expected response status code [200] but received 422.
  Tests:    1 failed (1 assertions)
```

**Fix** (`ListStockMovementsRequest.php`): `nullable` added to `movement_type`, `reason`, `search`. `location_id` and `product_id` already carried it (the gate matrix confirmed both were already 200 on empty). `page`/`per_page` deliberately left **without** `nullable` — they are not filters, `(int) null` would degrade to `paginate(0)`, and T3's `ListPaymentsRequest` makes the same call, so the two lanes stay consistent. The test asserts the full normal envelope: 200, one row, `meta.current_page=1`, `meta.per_page=25`, `meta.total=1`.

### Item 3 — B1 (web): `meta` crash + the un-run test file

**RED first.** `StockMovementsPage.reverseWriteOff.test.tsx` re-measured on `ae0921a2c`:

```
$ pnpm vitest run src/features/inventory/StockMovementsPage.reverseWriteOff.test.tsx
 Test Files  1 failed (1)
      Tests  15 failed (15)
```

**Fix, both halves as directed:** `StockMovementsPage.tsx:372` → `data?.meta?.total ?? 0` (matching the defensive `data?.meta ?` at the pager, now :429), and the six-field `meta` added to that file's fixture. → `Tests 15 passed (15)`.

### Item 4 — B2 (web): no Reverse button on write-off reversal receipts

**RED first.** Two tests added before the code change — a lone reversal receipt (`movement_type: 'receipt'`, `reason: 'write_off'`, `reverses_movement_id: 'wo-1'`) must show no Reverse button, and a genuine write-off beside its reversal must show exactly one:

```
 × … does NOT show a Reverse button for a write-off REVERSAL receipt
   → expected document not to contain element, found <button
 × … shows Reverse for the genuine write-off but not for its reversal receipt
   → expected [ <button …(3)></button>, …(1) ] to have a length of 1 but got 2
 Tests  2 failed | 15 passed (17)
```

**Fix.** `isReversibleMovement` (`StockMovementsPage.tsx:93-110`) now short-circuits on `movement.reverses_movement_id !== null` before the reference-type check. The backend refuses these unconditionally (`ReverseWriteOffService.php:91`), so the control is never offered. → `Tests 17 passed (17)`.

---

### Verification (by path, this round)

**Backend — SQLite**
```
$ php artisan test tests/Feature/Inventory/StockMovementTest.php \
    tests/Feature/Inventory/StockMovementLocationFilterTest.php \
    tests/Feature/BatchExpiry/ReverseWriteOffRouteTest.php \
    tests/Feature/Inventory/InventoryTenantIsolationTest.php
  Tests:    60 passed (191 assertions)
  Duration: 61.36s
```

**Backend — PostgreSQL**
```
$ DB_HOST=127.0.0.1 DB_PORT=5452 DB_DATABASE=autoerp_test_t2 DB_CENTRAL_DATABASE=autoerp_test_t2 \
    php artisan test -c phpunit-pgsql.xml <same four paths>
  Tests:    60 passed (191 assertions)
  Duration: 139.21s
```
59 → 60 tests, 185 → 191 assertions: exactly the one new empty-filter test, on both drivers.

**PHPStan level 8** (controller + FormRequest): `[OK] No errors`
**Pint** `--test` (controller, FormRequest, StockMovementTest): `{"result":"pass"}`

**Web — whole directory, as the gate directed**
```
$ pnpm vitest run src/features/inventory src/features/stock-adjustments/__tests__/queries.test.tsx
 Test Files  47 passed (47)
      Tests  328 passed (328)
   Duration  9.83s
```

**typecheck**
```
$ pnpm typecheck        # tsc --noEmit
(no output — clean)
```

**ESLint, the two touched src files**
```
$ pnpm exec eslint src/features/inventory/StockMovementsPage.tsx \
    src/features/inventory/StockMovementsPage.reverseWriteOff.test.tsx
  372:62  warning  Unnecessary optional chain on a non-nullish value
                   @typescript-eslint/no-unnecessary-condition
✖ 1 problem (0 errors, 1 warning)
```
**Judgement call, flagged for gate r2.** The warning is the direct consequence of the gate's own fix directive: `StockMovementsResponse.meta` is typed **non-optional** (`StockMovementsPage.tsx:65`), so TypeScript considers the new `?.` redundant. Three options were available — (a) widen the type to `meta?:`, (b) suppress the rule inline, (c) keep the runtime guard and accept the warning. Chose **(c)**: (a) would make the page tolerate a shape the server can no longer emit, which is the direction the frontend gate explicitly praised D2 for *not* taking; (b) is the suppression-comment evasion that gate watches for. The guard is defence-in-depth against a stale or third-party server, and the declared contract stays honest.

**React Doctor.** The `pre-commit` hook printed "React Doctor found staged regressions" on the web commit (non-blocking). Scanned authoritatively:
```
$ npx react-doctor src/features/inventory/StockMovementsPage.tsx \
    src/features/inventory/StockMovementsPage.reverseWriteOff.test.tsx --blocking warning
Score: 93 / 100 Great
2 issues — react-doctor/no-giant-component (StockMovementsPage.tsx:121)
           react-doctor/prefer-module-scope-pure-function (StockMovementsPage.tsx:215)
```
Both are **pre-existing**: the same two warnings reproduce verbatim on `git show ae0921a2c:…/StockMovementsPage.tsx` (the exact state the gate reviewed). This round introduced zero React Doctor regressions.

No vitest worker processes left behind.

---

### Commits (fix round 1)

| Hash | Message | Paths |
|---|---|---|
| `e17506511` | `fix(rh-t2): gate r1 fixes — falsifying tie-break fixture, nullable filters` | `apps/api/app/Modules/Inventory/Presentation/Requests/ListStockMovementsRequest.php`, `apps/api/tests/Feature/Inventory/StockMovementTest.php` |
| `18fbcbe01` | `fix(rh-t2 web): guard missing meta, hide Reverse on reversal receipts` | `apps/web/src/features/inventory/StockMovementsPage.tsx`, `apps/web/src/features/inventory/StockMovementsPage.reverseWriteOff.test.tsx` |
| `<this commit>` | `docs(rh-t2): gate r1 reports + handback fix round 1` | the two gate reports + this section |

---

### Not done in this round — still owed

**Promotion preconditions (both gates, unchanged):**

1. **Step 11 browser probe — NOT RUN.** Product-name / SKU / reference / literal `%` / literal `_` / transfer / write-off searches; global totals on page 2; pager changes requests. No local stack available in this worktree.
2. **The four W4 Playwright specs — NOT RUN** (`inventory-opening`, `inventory-stock`, `inventory-counting`, `inventory-costing`). The new bound `expect(body.meta?.last_page …).toBe(1)` at `apps/web/e2e/money-campaign/w4-support.ts:657` has still never executed.

Both remain **promotion preconditions**. This lane must not be promoted on the current evidence set.

**Gate merge conditions deliberately out of this round's scope** (the dispatch brief scoped it to the four code items; each needs an explicit decision before r2 closes):

- inventory-costing **B3** — same as items 1–2 above.
- inventory-costing non-blocking: `docs/api/README.md:448-449` still says the endpoint is "unchanged" (now false); second-company search case for `InventoryTenantIsolationTest`; `reason=write_off` tab-widening unasserted; search indexability; empty-`whereIn` short-circuit.
- frontend **N1** (dead duplicate `/stock-movements` mock branch, `tenantScope.test.tsx:178-180`), **N2** (`filterSignature` vs `locationScopedKey` scope normalisation), **N3** (two assertions pinning the transfer/write-off param mapping).
- frontend **merge condition 4 / D3** — reconcile the render-phase reset with T3's `useEffect` variant into **one shared hook** before either lane merges. This is cross-lane and needs an orchestrator decision, not a unilateral edit in this worktree.
