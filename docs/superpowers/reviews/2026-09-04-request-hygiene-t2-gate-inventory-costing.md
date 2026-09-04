# Gate — Request Hygiene Phase A, Task 2 (S-2 inventory half)

**Reviewer:** inventory-costing-reviewer (adversarial, code-grounded)
**Date:** 2026-09-04
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t2` — branch `lane/rh-t2-stock-movements`
**Range reviewed:** `b133caf21..ae0921a2c` (`90bae8431` backend, `cf6c11ea6` web, `ae0921a2c` handback)
**Scope:** backend/API/query/tenancy/ordering half. The web half is the frontend-conventions reviewer's; web items below are only those with data-correctness weight.

## VERDICT: CHANGES-REQUESTED

Spec: **mostly met** — the endpoint is bounded, every input is validated, filters are server-side, tenant+company scoping is preserved and correctly parenthesised around the new OR-search, no write/costing path was touched, and 6 of the 7 new tests are genuinely red pre-change (measured). Two defects and one owed verification block the merge.

---

## 1. Blocking findings

### B1 — [Important, blocks under the stated bar] The determinism test proves nothing; the `id DESC` tie-break is untested

`apps/api/tests/Feature/Inventory/StockMovementTest.php:532` (`test_tied_created_at_rows_cross_two_pages_without_duplicates_or_omissions`) is the *only* assertion that pins the lane's page-boundary guarantee (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:119-122`, comment @117-118: "`id` breaks created_at ties so page 2 cannot repeat or drop a row").

**Measured, not reasoned.** I deleted `->orderByDesc('id')` from `StockMovementController.php:121` and ran the test on both drivers:

```
# tie-break REMOVED
$ php artisan test tests/Feature/Inventory/StockMovementTest.php \
    --filter test_tied_created_at_rows_cross_two_pages_without_duplicates_or_omissions
  ✓ tied created at rows cross two pages without duplicati…  4.64s
  Tests:    1 passed (5 assertions)

$ DB_HOST=127.0.0.1 DB_PORT=55433 DB_DATABASE=autoerp_test_t2 DB_CENTRAL_DATABASE=autoerp_test_t2 \
    php artisan test -c phpunit-pgsql.xml tests/Feature/Inventory/StockMovementTest.php --filter …
  ✓ tied created at rows cross two pages without duplicati… 10.63s
  Tests:    1 passed (5 assertions)
```

**Root cause of the false green.** `StockMovement` uses `HasUuids` (`apps/api/app/Modules/Inventory/Domain/StockMovement.php:57`), and Laravel 12's `HasUuids::newUniqueId()` returns `Str::uuid7()` (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasUuids.php:16-19`) — a **time-ordered** UUID. Insertion order therefore already encodes `id` order, and both engines happen to emit tied `created_at` rows in an order that coincides with `id DESC` (index scanned backwards). The fixture cannot distinguish "the tie-break works" from "the plan happened to match the engine's incidental order". The handback acknowledges the green-pre-change result (`docs/handoff/HANDBACK-request-hygiene-T2-2026-09-03.md:90`) and calls the falsifying power "structural, not incidental" — that claim is false as measured.

**Falsifying scenario / proof the correct formulation works.** I added a probe that reassigns 30 **explicit random (v4) UUIDs** to the tied rows and asserts the same two-page sequence:

```
# tie-break REMOVED, shuffled explicit UUIDs
  Tests:    1 failed (3 assertions)   # assertSame($ids, $actual) at StockMovementTest.php:587
# tie-break RESTORED, shuffled explicit UUIDs — SQLite
  ✓ gate probe shuffled uuid tiebreak   6.95s   Tests: 1 passed
# tie-break RESTORED, shuffled explicit UUIDs — PostgreSQL
  ✓ gate probe shuffled uuid tiebreak  14.74s   Tests: 1 passed
```

The probe was removed afterwards (`git checkout --`); the worktree is clean.

**Why it matters.** A later refactor that drops the second `orderBy` (or a plan flip on a bigger table, a parallel seq scan, a different index) silently reintroduces duplicated/omitted rows across page boundaries in the stock ledger — the exact defect the lane claims to close — and CI stays green.

**Fix.** Seed the 30 tied rows with pre-generated `Str::uuid()` (v4) values assigned explicitly (or `DB::table('stock_movements')->update(['id' => $shuffled])` after creation) instead of relying on model-generated UUIDv7, then keep the existing `strcmp` expectation. Side note: the PG `uuid` column's ordering **does** match `strcmp` on the canonical lowercase string — my probe proves that reasoning (handback §6 item 4) holds.

### B2 — [Important] `search` / `movement_type` / `reason` lack `nullable`: 200 → 422 for an empty filter value

`apps/api/app/Modules/Inventory/Presentation/Requests/ListStockMovementsRequest.php:33-49` gives `movement_type`, `reason` and `search` the rules `['sometimes','string',…]` with no `nullable`. Laravel's global `ConvertEmptyStringsToNull` turns `?search=` into a **present null**, `sometimes` therefore does not skip it, and `string` fails.

**Measured matrix** (probe test against the lane's controller, SQLite):

```
search=                    => 422      product_id=                => 200
movement_type=             => 422      location_id=               => 200
reason=                    => 422      per_page=200               => 422
page=0                     => 422      per_page=abc               => 422
page=abc                   => 422      per_page=0                 => 422
movement_type=bogus        => 422      reason=bogus               => 422
product_id=notauuid        => 422      location_ids[]=notauuid    => 422
search=abc                 => 200      per_page=100               => 200
```

Pre-change, `?search=` and `?movement_type=` both returned **200** (the old controller ignored `search` entirely and `where('movement_type', null)` merely matched nothing — `git show b133caf21:…/StockMovementController.php`, lines with `$request->has('movement_type')`). This is the same defect class the Task 3 treasury gate required fixing, and it fails the task's own criterion that no previously accepted parameter now 422s.

**Blast radius today is zero in-repo**: `apps/web/src/features/inventory/StockMovementsPage.tsx:148` guards with `if (searchQuery)` and only ever emits enum-valid `movement_type`/`reason` (lines 152-159); `ProductMovementsTab.tsx:108-114` sends only `product_id`/`page`/`per_page`/`location_ids[]`. It is still a live contract regression for any client that clears a filter by sending the empty value, and the fix is three tokens.

**Falsifying scenario.** `GET /api/v1/stock-movements?search=&page=1&per_page=25` (a UI that keeps the param and empties it) → 422 today, 200 before this lane.

**Fix.** `'search' => ['sometimes','nullable','string','max:120']` and add `'nullable'` to `movement_type`/`reason`. The controller already tolerates null: `is_string($search) && $search !== ''` (`StockMovementController.php:80`), `is_string($movementType)` (`:101`), `is_string($validated['reason'] ?? null)` (`:111`).

### B3 — [Blocks promotion, not the code] Plan Step 10 (live stack) and Step 11 (browser probe) + the four W4 Playwright specs were never run

Handback §3 "Step 10 (live stack) and Step 11 (browser probe) — NOT RUN" (`docs/handoff/HANDBACK-request-hygiene-T2-2026-09-03.md:214-225`). The lane changes the **response envelope** of a shipped endpoint (the unpaged branch is deleted; `meta` is now always present) and adds a hard new assertion inside the W4 helper — `expect(body.meta?.last_page …).toBe(1)` at `apps/web/e2e/money-campaign/w4-support.ts:657` — which has never executed once. Its eight call sites (`inventory-opening.spec.ts:94`, `inventory-stock.spec.ts:115,206,210,250`, `inventory-counting.spec.ts:213`, `inventory-costing.spec.ts:197,260`) all pass `?product_id=…`, so they are per-product ledgers well under 100 rows — the assertion is expected to hold, but "expected" is not evidence, and a 422 from any of them would surface as a confusing `meta undefined` failure.

**Fix.** Run the Step 11 browser probe (product-name / SKU / reference / literal `%` / literal `_` / transfer / write-off searches; global totals on page 2; pager changes requests) and the four W4 inventory specs against a live stack before promotion.

---

## 2. Non-blocking findings

- **[Minor] `docs/api/README.md:448-449` is now false.** The DPA V7 note states "`GET /api/v1/stock-movements` is unchanged". It is now mandatory-paginated, always returns `meta`, accepts `search`/`reason`, and 422s inputs that used to 200. Update the published contract note (endpoint documented at `docs/api/README.md:439-441`).
- **[Minor, web, data-adjacent] Inconsistent `meta` guarding.** `apps/web/src/features/inventory/StockMovementsPage.tsx:365` uses `data?.meta.total ?? 0` (optional chain only guards `data`) while `:422` correctly guards `data?.meta`. A meta-less response crashes the page — this is exactly the `TypeError: Cannot read properties of undefined (reading 'total')` the lane hit in `tenantScope.test.tsx` and resolved by editing the **fixture** (handback D2) rather than the code. `data?.meta?.total ?? 0` costs nothing.
- **[Minor] Search is unindexable and pays a `COUNT(*)` per page.** The predicate is a leading-wildcard `LOWER(col) LIKE ? ESCAPE '!'` over `stock_movements.reference` plus an EXISTS on `products` (`StockMovementController.php:87-91`). `stock_movements` has only `index('reference')` plus `(tenant_id, product_id|location_id|movement_type, created_at)` and **no `company_id` in any index** (`apps/api/database/migrations/tenant/2025_11_30_110000_create_inventory_tables.php:46-49`), and there is no functional `lower()` index. On a large ledger every search is a full scan plus an offset-pagination `COUNT(*)`. For a "request hygiene" lane, consider a FE debounce/min-length or a trigram index as a follow-up.
- **[Minor] Case-folding is driver-dependent in tests.** PHP `mb_strtolower` is Unicode-aware; SQLite's `LOWER()` is ASCII-only, PostgreSQL's is locale-aware. Production (PG) is correct; the SQLite leg will silently disagree on accented terms if a future test asserts one. Also note the search is accent-**sensitive**: "ecrou" will not find "Écrou". Not a regression (pre-change `search` was accepted and then ignored entirely — the box did nothing).
- **[Minor] Second-of-everything.** The new server-side `search`/`reason` filters ship no second-company assertion. I dumped the generated SQL and the OR-group is correctly parenthesised (see §3), so there is no OR-precedence leak today; per rule 22 a second-company search case belongs in `InventoryTenantIsolationTest` beside the existing `stock movements index excludes same tenant cross company rows`.
- **[Minor] `reason=write_off` silently widens the tab.** The old FE sent `movement_type=issue` and then filtered by reason client-side (`git show b133caf21:…/StockMovementsPage.tsx`, the `items.filter(m => isReversibleWriteOff(m.reason))` memo). The new server alias (`StockMovementController.php:105-110`) filters by reason **only**, so a non-`issue` movement carrying `reason=damage` now appears in the Write-offs tab. That matches `isReversibleWriteOff` (`StockMovementsPage.tsx:74-79`) and is a defensible improvement, but nothing asserts it.
- **[Minor] Ledger ordering uses `created_at`, not `occurred_at`.** Pre-existing (`StockMovement` carries `occurred_at`, which is not even in the row payload), but bounding the page to 25 makes it visible: a backdated opening-balance import now occupies page 1 ahead of older-inserted but later-occurring movements.
- **[Trivial] Empty `whereIn`.** `Document::query()->…->whereIn('id', $documentIds)` (`StockMovementController.php:133-138`) still round-trips when the page contains no document-linked movement. A `$documentIds->isEmpty()` short-circuit removes one query per page.
- **[Pre-existing, out of lane]** PHPStan on the test file reports `StockAdjustmentService::receive()` `$reference` `string|null` at `StockMovementTest.php:264` (handback D1). Confirmed out of the gate's path set (`phpstan.neon` analyses `app/` only).

---

## 3. What held up under attack

- **No write path changed.** The diff touches `index()`/`formatMovement()` only; no costing, WAC, batch/FEFO, or stock-decrement code is in range (`git diff --stat b133caf21..ae0921a2c` = controller, new FormRequest, tests, web).
- **Row payload is byte-identical.** `formatMovement()` (`StockMovementController.php:166-200`) emits the same fields as the pre-change version, including `quantity`/`quantity_before`/`quantity_after` as **strings** and `quantity_decimals` from `product.unitOfMeasure.decimal_places ?? 4` (`:177`). No float cast, no `number_format`, no scale downgrade — rule 19 clean. PHPStan level 8 clean on both app files.
- **Tenant + company scoping survives the new OR-search, correctly parenthesised.** Dumped SQL for `?search=ab%25c_d`:
  ```
  select count(*) as aggregate from "stock_movements"
   where "tenant_id" = ? and "company_id" = ? and "location_id" in (?)
     and (LOWER(stock_movements.reference) LIKE ? ESCAPE '!'
          or exists (select * from "products"
                     where "stock_movements"."product_id" = "products"."id"
                       and (LOWER(products.name) LIKE ? ESCAPE '!'
                            or LOWER(products.sku) LIKE ? ESCAPE '!')
                       and "products"."deleted_at" is null))
  bindings: [tenant, company, location, "%ab!%c!_d%", "%ab!%c!_d%", "%ab!%c!_d%"]
  ```
  The tenant/company predicates are outer `AND`s; the OR group is wrapped; the EXISTS is correlated on `stock_movements.product_id = products.id`; `products.name` / `products.sku` are qualified, so no ambiguity on PostgreSQL.
- **Search escaping is correct and bound.** `str_replace(['!','%','_'], ['!!','!%','!_'], mb_strtolower($search))` (`StockMovementController.php:84`) — `!` first cannot double-escape because the later passes only *introduce* `!`. The bindings above show the pattern is **bound**, never interpolated. Falsified: removing only the `str_replace` makes `test_search_treats_percent_and_underscore_as_literals` go red (`Tests: 1 failed`), so that test does pin the escape clause.
- **Bulk document lookup is safe.** `DOCUMENT_REFERENCE_TYPES` (`:42`) is shared by the page-level `whereIn` (`:126`) and the per-row read (`:162`), so they cannot drift; the lookup is `tenant_id` + `company_id` scoped to the **request's** company (`:134-135`), which is equivalent to the old per-row `$movement->tenant_id/company_id` because the outer query is already company-scoped. `test_list_exposes_source_document_provenance` is green on both drivers. No movement with a Document reference can lose `source_document_id/type`.
- **Every in-repo consumer is accounted for.** Repo-wide grep (`*.ts|*.tsx|*.php|*.js|*.mjs`, excluding node_modules/vendor/.worktrees) plus `apps/pos`, `/Users/houssamr/Projects/syneriva/erp-mobile`, `scripts/`, `.github/`: consumers are `StockMovementsPage.tsx`, `ProductMovementsTab.tsx:108-116` (already sends `page`/`per_page`), `w4-support.ts:652`, and three PHPUnit files including the two no-`page` callers at `InventoryTenantIsolationTest.php:713,811`. `apps/pos` and `erp-mobile` contain **zero** references to the endpoint. `OffsetPagination`'s `PER_PAGE_OPTIONS = [10, 25, 50, 100]` (`apps/web/src/components/ui/OffsetPagination.tsx:25`) cannot exceed the 100 cap.
- **The suffix-based invalidation predicate still matches.** `stockMovementsInvalidationPredicate` (`apps/web/src/features/inventory/_invalidation.ts:40-53`) matches on `k[0]` plus the last two elements, so the two extra key members (`page`, `perPage`) are harmless.
- **6 of 7 new tests are genuinely red pre-change** (measured against `b133caf21`'s controller, §4).

---

## 4. Commands run and exact output

Environment deviation, disclosed: the reserved PG at `127.0.0.1:5433` was occupied by an unrelated running container (`locaplex-postgres`) and `autoerp_postgres` was `Exited (0)`. Rather than stop the owner's stack, the PG leg ran against a throwaway `postgres:16` container `rht2-review-pg` on `127.0.0.1:55433`, database `autoerp_test_t2`, user `autoerp`. Never the shared default DB. Container removed after the run.

### PHPStan level 8 (the two touched app files)
```
$ ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G \
    app/Modules/Inventory/Presentation/Controllers/StockMovementController.php \
    app/Modules/Inventory/Presentation/Requests/ListStockMovementsRequest.php
Note: Using configuration file …/.worktrees/rh-t2/apps/api/phpstan.neon.
 2/2 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 [OK] No errors
```

### Pint
```
$ ./vendor/bin/pint --test <controller> <request> <test>
{"result":"pass"}
```

### Four backend test files — SQLite
```
$ php artisan test tests/Feature/Inventory/StockMovementTest.php \
    tests/Feature/Inventory/StockMovementLocationFilterTest.php \
    tests/Feature/BatchExpiry/ReverseWriteOffRouteTest.php \
    tests/Feature/Inventory/InventoryTenantIsolationTest.php
  Tests:    59 passed (185 assertions)
  Duration: 64.20s
```

### Four backend test files — PostgreSQL
```
$ DB_HOST=127.0.0.1 DB_PORT=55433 DB_DATABASE=autoerp_test_t2 DB_CENTRAL_DATABASE=autoerp_test_t2 \
    php artisan test -c phpunit-pgsql.xml <same four paths>
  Tests:    59 passed (185 assertions)
  Duration: 190.35s
```

### Red check against the pre-change controller (`git show b133caf21:…` restored in place, SQLite)
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
  ⨯ index without page is bounded to 25              (30 rows, no meta)
  ⨯ transfer alias is server side across pages
  ⨯ write off alias is server side across pages
  ⨯ search matches product name sku and movement reference
  ⨯ search treats percent and underscore as literals  (actual size 3 matches expected 1)
  ⨯ search rejects 121 characters                     (200, expected 422)
  ✓ tied created at rows cross two pages without duplicati…   <-- B1
  Tests:    6 failed, 10 passed (45 assertions)
```
Matches the handback's RED evidence exactly — including that the tie-break test was already green.

### Falsification runs (all restored afterwards)
| Mutation | Test | SQLite | PostgreSQL |
|---|---|---|---|
| remove `->orderByDesc('id')` (`:121`) | `test_tied_created_at_rows…` | **PASS (not falsifying)** | **PASS (not falsifying)** |
| remove `->orderByDesc('id')` | shuffled-v4-UUID probe | FAIL (correct) | — |
| tie-break restored | shuffled-v4-UUID probe | PASS | PASS |
| remove `str_replace` escaping (`:84`) | `test_search_treats_percent_and_underscore_as_literals` | FAIL (correct) | — |

### Web (data-correctness spot checks only)
```
$ pnpm vitest run src/features/inventory/StockMovementsPage.test.tsx \
    src/features/inventory/__tests__/tenantScope.test.tsx \
    src/features/stock-adjustments/__tests__/queries.test.tsx
 Test Files  3 passed (3)
      Tests  18 passed (18)

$ node tools/audit-tanstack-keys.mjs
[gate-summary] Gate C baseline: 0 acknowledged, 1 new, 0 stale baseline entries
  src/features/uom/hooks/useUnits.ts:53:9 …   # pre-existing, not in this diff
```

### Worktree cleanliness
```
$ git -C /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t2 status --short
?? docs/superpowers/reviews/2026-09-04-request-hygiene-t2-gate-frontend-conventions.md   # sibling reviewer's file, not mine
```
All temporary controller/test mutations were reverted with `git checkout --` and re-verified.

---

## 5. What to fix before merge

Make the tie-break test falsifying with explicit shuffled v4 UUIDs (B1), add `nullable` to `search`/`movement_type`/`reason` (B2), then run the Step 11 browser probe and the four W4 inventory specs against a live stack (B3).

---

# Re-gate r2 (2026-09-04)

**Reviewer:** inventory-costing-reviewer
**Range re-reviewed:** `ae0921a2c..e17506511` (backend fix round 1), plus a confirmation that `e17506511..HEAD` (`18fbcbe01`, `47e988267`) touches **zero** `apps/api` files.
**Mode:** read-only. Every mutation below was temporary and fully reverted; `git status --porcelain` is empty at the end (index *and* worktree verified against `HEAD` by SHA-256).
**Environment:** shared PG `:5433` left untouched (another project's container). PG leg ran on the lane container `autoerp_pg_t2` at `127.0.0.1:5452`, DB `autoerp_test_t2`. Container left running.

## VERDICT: MERGE — spec ✅ + quality APPROVED

Both r1 backend blockers (B1, B2) are **fixed and independently falsified by me on both drivers**. r1 B3 is confirmed as a **promotion precondition, not a merge blocker**. One new finding, non-blocking, ruled acceptable below.

---

## r2-1 — r1 B1 (tie-break test not falsifying) — **RESOLVED**

`apps/api/tests/Feature/Inventory/StockMovementTest.php:532-591`. The fixture now:

- builds 30 **explicit v4-shaped ids** `sprintf('7f000000-0000-4000-8000-%012x', $sequence)` for `$sequence` 1..30 (`:543-546`) — fixed prefix + zero-padded lowercase hex suffix, so a descending *string* sort is exactly `id DESC`, and PG's `uuid` byte order agrees;
- assigns them with `forceFill(['id' => …])` on a bare `new StockMovement` (`:555-572`). Verified this survives `HasUuids`: `bootHasUuids()` only assigns when `empty($model->{$column})`, so the explicit key is kept — and the green run proves it, since `$expectedIds` is built from the literals;
- inserts in permutation `[...range(1,29,2), ...range(30,2,-2)]` (`:551`) = `1,3,…,29,30,28,…,2`. First inserted holds the **lowest** id, last holds the **second-lowest**, so `id DESC` equals **neither** insertion order **nor** its reverse. A `assertCount(30, $insertionOrder)` self-check guards the permutation itself (`:552`);
- computes `$expectedIds` from the id literals via `rsort($expectedIds, SORT_STRING)` (`:579-580`), never from a query. `$ids` is unaffected (PHP copy-on-write), so the `forceFill` loop and the expectation cannot co-drift.

**Falsified by me, both drivers.** I deleted `->orderByDesc('id')` from `StockMovementController.php:121` and ran the single test:

```
# SQLite — tie-break REMOVED
$ php artisan test tests/Feature/Inventory/StockMovementTest.php \
    --filter test_tied_created_at_rows_cross_two_pages_without_duplicates_or_omissions
  ➜ 590▕         self::assertSame($expectedIds, $actualIds);
  Tests:    1 failed (6 assertions)
  Duration: 3.57s

# PostgreSQL (:5452) — tie-break REMOVED
$ DB_HOST=127.0.0.1 DB_PORT=5452 DB_DATABASE=autoerp_test_t2 DB_CENTRAL_DATABASE=autoerp_test_t2 \
    php artisan test -c phpunit-pgsql.xml tests/Feature/Inventory/StockMovementTest.php --filter …
  ➜ 590▕         self::assertSame($expectedIds, $actualIds);
  Tests:    1 failed (6 assertions)
  Duration: 12.44s
```

Both diffs show the actual sequence returned in odd-then-even **insertion** order (`…000013, 000011, 00000f, …, 000003, 000001`) rather than `id DESC` — i.e. the assertion now discriminates exactly the property it claims to pin. Contrast r1, where the same mutation left the old fixture **green** on both drivers.

Controller restored with `git checkout --`; SHA-256 of the working file equals SHA-256 of `git show e17506511:…StockMovementController.php` (`19ab967f…5a9e80`).

## r2-2 — r1 B2 (`nullable` on cleared filters) — **RESOLVED**

`apps/api/app/Modules/Inventory/Presentation/Requests/ListStockMovementsRequest.php:37-55` — `nullable` added to `movement_type` (`:39`), `reason` (`:48`), `search` (`:55`); `location_id` (`:29`) and `product_id` (`:32`) already carried it. Comment at `:33-36` records the `ConvertEmptyStringsToNull` mechanism.

Mechanism re-verified in vendor, not from memory: `Validator::isValidatable()` (`vendor/laravel/framework/src/Illuminate/Validation/Validator.php:817-827`) gates on `presentOrRuleIsImplicit()` (`:837-845` — a present null **is** "present", so the rule runs) and `isNotNullIfMarkedAsNullable()` (`:884-891` — only a `Nullable` rule skips it). Without `nullable`, `validateInteger`/`validateString` therefore execute against `null` and fail.

**Falsified by me.** With the FormRequest reverted to `ae0921a2c` and the controller/test at `e17506511`:

```
$ php artisan test tests/Feature/Inventory/StockMovementTest.php \
    --filter test_index_accepts_cleared_filters_sent_as_empty_strings
  ➜ 603▕             ->assertOk()
  Tests:    1 failed (1 assertions)
  Duration: 4.10s
```

Green with the fix (in the full run below). The test at `:593-608` asserts the whole envelope (200, one row, `meta.current_page=1`, `meta.per_page=25`, `meta.total=1`), not just a status code, so it is a data-meaning assertion.

Index and worktree fully restored (`git restore --source=HEAD --staged --worktree`; SHA-256 match, `git status --porcelain` empty).

## r2-3 — r1 B3 (browser probe + four W4 Playwright specs) — **NOT A MERGE BLOCKER**

**Explicit ruling, as requested.** B3 is a **promotion precondition**, not a merge blocker. Reasons, all code-grounded:

- The endpoint's correctness is fully pinned by server-side tests on both drivers (60/191, below). Nothing B3 would exercise is unverified *logic*; it is unverified *integration*.
- The only never-executed new assertion is `expect(body.meta?.last_page …).toBe(1)` at `apps/web/e2e/money-campaign/w4-support.ts:658`. Its eight call sites all pass `?product_id=…` and the helper pins `per_page=100` (`:654-655`), so they are per-product ledgers; the risk is a noisy Playwright failure, not wrong stock data at rest.
- No costing, WAC, batch/FEFO, movement-sign or stock-decrement path is in the diff at all.

It must nevertheless be run before this lane is promoted, and the handback already records it as owed ("Not done in this round — still owed", items 1–2).

## r2-4 — [Minor, NOT a blocker] `?page=` / `?per_page=` still 422, and the handback's stated reason for that is factually wrong

`ListStockMovementsRequest.php:56-57` deliberately leaves `page`/`per_page` without `nullable`. **What this controller actually does** (measured, exact rules pulled from the class by reflection):

```
$ CACHE_STORE=array php artisan tinker --execute='…Validator::make($data, (new ReflectionClass(ListStockMovementsRequest::class))->newInstanceWithoutConstructor()->rules())…'
["page"]                                                    => FAIL ["page"]
["per_page"]                                                => FAIL ["per_page"]
["page","per_page"]                                         => FAIL ["page","per_page"]
["search","movement_type","reason","location_id","product_id"] => PASS {"location_id":null,…,"search":null}
```

**Ruling: acceptable, not a blocker.** It **422s loudly** — it does *not* exhibit the Task 4 failure mode of silently falling back to a wrong page size. The controller never reads the raw request: `$perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE)` / `$page = (int) ($validated['page'] ?? 1)` (`StockMovementController.php:115-116`, `DEFAULT_PER_PAGE = 25` at `:44`), so a 15-row Eloquent default is structurally unreachable here. No in-repo client can emit an empty value either: `StockMovementsPage.tsx:169-170` and `ProductMovementsTab.tsx:110-111` both send `String(page)`/`String(perPage)` from numeric `useState`, and `w4-support.ts:654-655` uses `params.set('page','1')`. And the pre-change behaviour for `?per_page=` was not a *correct* 200 — `Request::integer()` is `(int) $this->data($key, $default)` (`vendor/laravel/framework/src/Illuminate/Support/Traits/InteractsWithData.php:273-276`), which yields `0` for a present null, clamped by `min(max(0,1),100)` to **1** in the old controller (`git show b133caf21:…StockMovementController.php`) — a degenerate one-row page. Trading that for a 422 is an improvement, not a regression worth blocking.

**But the recorded justification is wrong and must not propagate.** The handback says `page`/`per_page` were left non-nullable because "`(int) null` would degrade to `paginate(0)`". That is false for **this** controller: `??` coalesces a *present* null, so `nullable` would be safe here. Measured:

```
$ php -r '$validated=["per_page"=>null,"page"=>null]; var_dump((int)($validated["per_page"] ?? 25), (int)($validated["page"] ?? 1));'
int(25)
int(1)
```

`ListPaymentsRequest` (T3) is not on this base, so I cannot verify whether the "consistency with T3" half of the rationale holds — **cannot verify**. Fix-forward: either add `nullable` to `:56-57` (safe as measured), or correct the handback sentence so a later lane does not copy a wrong premise into a controller that *does* read `$validated[...]` without `??`.

## r2-5 — Nothing else in the backend changed

```
$ git diff --stat ae0921a2c e17506511 -- apps/api
 .../Requests/ListStockMovementsRequest.php        |  8 ++-
 .../tests/Feature/Inventory/StockMovementTest.php | 78 +++++++++++++++-----
 2 files changed, 68 insertions(+), 18 deletions(-)

$ git diff --stat ae0921a2c e17506511 -- .../Controllers/StockMovementController.php
(empty — controller byte-identical)

$ git diff --stat e17506511 HEAD -- apps/api
(empty — 18fbcbe01 and 47e988267 are web + docs only)
```

Consequences carried forward from r1 without re-derivation, since the controller is unchanged: the OR-search remains correctly parenthesised inside the outer `tenant_id`/`company_id` ANDs; `ESCAPE '!'` escaping order is still safe and bound; `formatMovement()` (`:159-201`) still emits `quantity`/`quantity_before`/`quantity_after` as **strings** with `quantity_decimals` from the unit (`:177`) — no float, no `number_format`, no scale downgrade (rule 19 clean); no WAC/batch/FEFO/stock-decrement code is in range. The new test fixture also uses string quantities (`'1.0000'`, `StockMovementTest.php:564-566`).

## r2-6 — Commands and exact outputs

**PHPStan level 8** (`apps/api`, controller + FormRequest)
```
$ ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G \
    app/Modules/Inventory/Presentation/Controllers/StockMovementController.php \
    app/Modules/Inventory/Presentation/Requests/ListStockMovementsRequest.php
Note: Using configuration file …/.worktrees/rh-t2/apps/api/phpstan.neon.
 2/2 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 [OK] No errors
```

**Pint** (controller + FormRequest + test)
```
$ ./vendor/bin/pint --test app/Modules/Inventory/Presentation/Controllers/StockMovementController.php \
    app/Modules/Inventory/Presentation/Requests/ListStockMovementsRequest.php \
    tests/Feature/Inventory/StockMovementTest.php
{"result":"pass"}
```

**Four backend files — SQLite**
```
$ php artisan test tests/Feature/Inventory/StockMovementTest.php \
    tests/Feature/Inventory/StockMovementLocationFilterTest.php \
    tests/Feature/BatchExpiry/ReverseWriteOffRouteTest.php \
    tests/Feature/Inventory/InventoryTenantIsolationTest.php
  Tests:    60 passed (191 assertions)
  Duration: 60.19s
```

**Four backend files — PostgreSQL (`autoerp_pg_t2`, 127.0.0.1:5452)**
```
$ DB_HOST=127.0.0.1 DB_PORT=5452 DB_DATABASE=autoerp_test_t2 DB_CENTRAL_DATABASE=autoerp_test_t2 \
    php artisan test -c phpunit-pgsql.xml <same four paths>
  Tests:    60 passed (191 assertions)
  Duration: 161.89s
```

r1 measured 59 tests / 185 assertions. The delta is exactly `+1` test (`test_index_accepts_cleared_filters_sent_as_empty_strings`, 5 assertions) and `+1` assertion inside the rewritten tie-break test (`assertCount(30, $insertionOrder)`, `StockMovementTest.php:552`) → 60 / 191. Arithmetic reconciles; no test was silently dropped or renamed.

**Worktree cleanliness after all mutations**
```
$ git status --porcelain
(empty)
$ shasum -a 256 …/ListStockMovementsRequest.php   == git show HEAD:… | shasum -a 256   ✔ 568d1ec1…c5b87e
$ shasum -a 256 …/StockMovementController.php     == git show e17506511:… | shasum -a 256 ✔ 19ab967f…5a9e80
```

## r2-7 — r1 non-blocking findings still open (carried, none blocking)

`docs/api/README.md:448-449` still claims the endpoint is "unchanged" (now false); no second-company assertion for the new `search`/`reason` filters beside `InventoryTenantIsolationTest`'s existing `stock movements index excludes same tenant cross company rows` (green on both drivers — the OR-group was proven correctly parenthesised at r1, so this is a test-coverage gap, not a leak); `reason=write_off` tab-widening unasserted; leading-wildcard search unindexable + `COUNT(*)` per page; ledger ordered by `created_at`, not `occurred_at`; empty-`whereIn` short-circuit. Plus the new r2-4. None gates the merge.

## r2-8 — What to fix before promotion (not before merge)

Run the Step 11 browser probe and the four W4 inventory Playwright specs against a live stack, and correct the `page`/`per_page` rationale (r2-4) so T3 does not inherit a wrong premise.
