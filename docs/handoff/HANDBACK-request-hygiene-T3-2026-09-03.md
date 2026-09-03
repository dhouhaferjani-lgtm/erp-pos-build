# HANDBACK — Request Hygiene Phase A, Task 3

**Task:** Task 3 "Bounded payment reads and required list pagination (S-2 treasury half)" of
`docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` (rev 9, gate r8 = dispatch-ready).

| | |
|---|---|
| Worktree | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t3` |
| Branch | `lane/rh-t3-payments-list` |
| Base | `dev` @ `b133caf21` |
| Commit 1 (backend) | `4bdbebe24` — always paginate the payment index behind a dedicated list request |
| Commit 2 (frontend + e2e) | `522bd92bc` — paginate the payment list page, fix the dashboard params |
| Commit 3 (this doc) | see `git log` (docs commit, path-scoped) |
| Date | 2026-09-03 |
| Reviewers requested by the plan | treasury-reviewer **and** frontend-conventions-reviewer |

Diff: 11 files, +245 / −67. No migration. No locale file changed (see §i18n).

---

## 0. Environment setup (per the lane brief)

```
cp -R <main>/apps/api/vendor <worktree>/apps/api/vendor
cp <main>/apps/api/.env <worktree>/apps/api/.env
cd <worktree>/apps/api && composer dump-autoload
```
→ `Generated optimized autoload files containing 19583 classes`

Private PG database reserved for this lane (never the shared default):
```
PGPASSWORD=… psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "CREATE DATABASE autoerp_test_t3"
→ CREATE DATABASE
```
All PG legs run with `DB_DATABASE=autoerp_test_t3 DB_CENTRAL_DATABASE=autoerp_test_t3`.
`vendor/` and `.env` are git-ignored (`apps/api/.gitignore:22` and `:3`) and were **not** committed.

---

## 1. Step-by-step evidence

### Step 1 — dedicated list request (plan-exact)

Created `apps/api/app/Modules/Treasury/Presentation/Requests/ListPaymentsRequest.php`, verbatim from the
plan: `partner_id` (uuid), `status` (`Rule::enum(PaymentStatus::class)`), `search` (max:120), `page`
(min:1), `per_page` (min:1, max:100). `PaymentStatus` confirmed at
`apps/api/app/Modules/Treasury/Domain/Enums/PaymentStatus.php:7` (pending/completed/failed/reversed).

### Step 2 — failing tests added to the existing fixture graph

`apps/api/tests/Feature/Treasury/PaymentTest.php` — both methods verbatim from the plan, using the
existing `setUp()` fixtures (`$this->tenant/company/customer/cashMethod/user`). Imports added:
`App\Modules\Treasury\Domain\Enums\PaymentStatus` (line 30) and `Carbon\CarbonImmutable` (line 35).

```
php -l tests/Feature/Treasury/PaymentTest.php
→ No syntax errors detected in tests/Feature/Treasury/PaymentTest.php
```

### Step 3 — red, both drivers

SQLite (`php artisan test tests/Feature/Treasury/PaymentTest.php tests/Feature/Treasury/PaymentCompanyScopeTest.php tests/Feature/Treasury/TreasuryCompanyIsolationTest.php`):

```
 FAILED  Tests\Feature\Treasury\PaymentTest > index without page is bounde…
 Failed to assert that the response count matched the expected 25
 Failed asserting that actual size 30 matches expected size 25.
 at tests/Feature/Treasury/PaymentTest.php:1181
 …
 Tests:    2 failed, 41 passed (128 assertions)
 Duration: 39.93s
```

PostgreSQL (`DB_DATABASE=autoerp_test_t3 DB_CENTRAL_DATABASE=autoerp_test_t3 php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentTest.php --filter='tied_payment_dates|index_without_page'`):

```
 ⨯ index without page is bounded to 25                                 38.03s
 ✓ tied payment dates cross two pages without duplicates or omissions   2.16s
 …
 Tests:    1 failed, 1 passed (7 assertions)
```

The second failure in the SQLite run (`supplier invoice payment cle…`, `PaymentType::SupplierPayment`
vs expected `DocumentPayment`) is **pre-existing on the `b133caf21` baseline** — proven by reverting
both touched files with `git checkout --` and re-running just that filter on pristine code:

```
  1   tests/Feature/Treasury/PaymentTest.php:576     ← pristine line number
  Tests:    1 failed, 1 passed (7 assertions)
```

Both files were restored from the scratchpad copies immediately afterwards (`git status` re-checked).

### Step 4 — validated-only index (plan-exact)

`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:260-320`. Signature is
`index(ListPaymentsRequest $request)`; every other action keeps `Request`. Import added at line 49.
No `$request->has()` / `->input()` / `->query()` / `->integer()` remains in `index()` — grep-verifiable.
The pre-existing tenant+company scope comment (api.treasury.075 / go-live audit #4) and the search
grouping comment were kept verbatim.

### Step 5–9 — frontend, dashboard, e2e

- `apps/web/src/features/treasury/PaymentListPage.tsx`: `page`/`perPage` state (lines 58-59), the
  `useEffect(() => setPage(1), [search])` reset (63-65), key
  `tenantScopedKey(['payments', search, page, perPage])` (77), URL built as search → page → per_page
  (79-83), `placeholderData: keepPreviousData` (89), `meta` now required
  (`PaymentsResponse.meta: OffsetPaginationMeta`), `OffsetPagination` rendered after `DataTable`
  (235-249) with all six meta fields, `onPageChange={setPage}`, `onPerPageChange` resetting to page 1
  — the same shape Task 2 specifies.
- `PaymentListPage.search.test.tsx`: mock is now Axios-shaped with the six-field meta; asserts
  `'/payments?page=1&per_page=25'` and `'/payments?search=Alice&page=1&per_page=25'` plus the rendered
  `pagination.page 1 pagination.of 1` bar.
- `PaymentListPage.test.tsx`: meta type is `OffsetPaginationMeta`; the fixture is
  `{ current_page: 1, last_page: 1, per_page: 25, total: 2, from: 1, to: 2 }`.
- `__tests__/TreasuryTenantScope.test.tsx:185`: key assertion updated to
  `['payments', '', 1, 25, 'tenant-A', 'company-1']`, with the comment above it rewritten.
- `e2e/payments.spec.ts`: `populatedPaymentsMeta` / `emptyPaymentsMeta` declared after the import and
  used in all four `/api/v1/payments` GET arms (1 populated + 3 empty); `should display payment list`
  keeps every row assertion and adds `await expect(page.getByText(/Page 1 of 1/i)).toBeVisible()`.
- `e2e/money-campaign/w5c-support.ts`: `allPaymentIds()` replaced with the raw-response
  `do { … } while (page <= lastPage)` loop at `per_page=100`, asserting `meta.current_page === page`
  per iteration; its docblock no longer claims an unbounded `->get()` branch.
- `e2e/money-campaign/expenses-lifecycle.spec.ts:245`: the obsolete "omits `page` → `->get()` branch"
  comment replaced with "iterates every page".
- `src/features/dashboard/Dashboard.tsx:152`: `/payments?limit=5&sort=-created_at` →
  `/payments?page=1&per_page=5` with a comment recording that neither old parameter was ever read.

### Step 10 — verification

**Backend, SQLite** (three files by path):
```
 Tests:    1 failed, 42 passed (131 assertions)
 Duration: 63.46s
```
**Backend, PostgreSQL** (`autoerp_test_t3`, same three files):
```
 Tests:    1 failed, 42 passed (131 assertions)
 Duration: 202.68s
```
The single failure in each is the pre-existing `supplier invoice payment cle…` case proven above; both
new tests pass on both drivers, and the three `TreasuryCompanyIsolationTest.php:415` search reads stay
green as bounded page-one reads.

**PHPStan level 8** (touched `app/` files):
```
./vendor/bin/phpstan analyse app/Modules/Treasury/Presentation/Requests/ListPaymentsRequest.php \
  app/Modules/Treasury/Presentation/Controllers/PaymentController.php --memory-limit=2G
→ [OK] No errors
```
Adding `tests/Feature/Treasury/PaymentTest.php` to that command surfaces 5 errors, all on pre-existing
lines 273/279/317/335/402 (`withholding_certificate_id`, `assertIsString`) and all outside the
configured analysis scope (`phpstan.neon` analyses `app/` only). No error is on a line this lane wrote.

**Pint:** `./vendor/bin/pint <3 touched php files>` → `{"result":"pass"}`

**Frontend, by path:**
```
pnpm vitest run src/features/treasury/PaymentListPage.search.test.tsx \
  src/features/treasury/PaymentListPage.test.tsx \
  src/features/treasury/__tests__/TreasuryTenantScope.test.tsx \
  src/features/dashboard/dashboard.test.tsx \
  src/features/dashboard/__tests__/Dashboard.tenantScope.test.tsx
→ Test Files  5 passed (5)
        Tests  21 passed (21)
```
**Typecheck:** `pnpm typecheck` → clean (no output). `pnpm typecheck:e2e` → clean.
**ESLint** (8 touched files): `✖ 15 problems (0 errors, 15 warnings)`. 14 warnings are pre-existing
(hardcoded entity routes, `no-unnecessary-condition`); the one new warning is
`react-hooks/set-state-in-effect` on the plan-mandated `useEffect(() => setPage(1), [search])` — see
deviation D3. `lint:eslint` has no `--max-warnings`, so this does not fail lint.

**i18n:** no key added. `common:pagination.{page,of,showing,rowsPerPage,item,items,previous,next}` verified
present in `src/locales/{en,fr,ar}/common.json`; `OffsetPagination` owns all the copy.

**Playwright:** the browser suite was **not executed**. `e2e/payments.spec.ts`,
`e2e/money-campaign/w5c-support.ts` and `e2e/money-campaign/expenses-lifecycle.spec.ts` were
**type-checked only** (`pnpm typecheck:e2e`, clean), per the lane brief.

**Browser probe: not run** — no local stack was listening on 5173/5174/8010/8011 during this lane.
The five-row dashboard and payment-list page 2 still owe a manual browser check before promotion.

---

## 2. Deviations

**D1 — the tied-date test is not falsifying against the pre-fix ordering, but the tie-break direction
IS load-bearing.** `test_tied_payment_dates_cross_two_pages_without_duplicates_or_omissions` passes on
both SQLite and PostgreSQL *before* the controller change (evidence in §Step 3). Cause: `Payment` uses
`Illuminate\Database\Eloquent\Concerns\HasUuids` (`apps/api/app/Modules/Treasury/Domain/Payment.php:18`),
whose ids are *ordered* UUIDs, and both engines happened to return the tied rows in reverse-insertion
order, which coincides with `id DESC`. I empirically checked the assertion is still load-bearing by
temporarily flipping `->orderByDesc('id')` to `->orderBy('id')` at
`PaymentController.php:299` and re-running the test:
```
  1   tests/Feature/Treasury/PaymentTest.php:1221
  Tests:    1 failed (5 assertions)
```
The line was restored immediately (`grep -n "orderByDesc('id')"` → `299`). The falsifying red for this
step is carried by `test_index_without_page_is_bounded_to_25`, which failed on both drivers. Reviewers
who need a tie-break test that is red against *no* tie-break should note this and decide whether a
stronger fixture (e.g. explicitly inserting rows with descending ids) is wanted in a follow-up.

**D2 — pre-existing red left untouched.** `PaymentTest::test_supplier_invoice_payment_clears...`
(`tests/Feature/Treasury/PaymentTest.php:578`, pristine `:576`) fails on the `b133caf21` baseline for an
unrelated `PaymentType::SupplierPayment` vs `DocumentPayment` reason. Out of this lane's scope; proven
pre-existing in §Step 3.

**D3 — new lint warning, plan-mandated code.** `react-hooks/set-state-in-effect` fires on
`PaymentListPage.tsx:64`. The plan's Step 5 dictates that exact `useEffect`, and Task 2 uses the same
shape, so it was kept for cross-task consistency rather than moved into the `SearchInput` `onChange`
handler. Warning only, zero errors.

**D4 — the search test's pagination assertion needed a `waitFor`.** The plan's Step 6 line
`expect(screen.getByText('pagination.page 1 pagination.of 1')).toBeInTheDocument()` runs while the table
is still in its loading skeleton (the `waitFor` above it only awaits the *request*, not the committed
render). Adapted minimally by wrapping the same assertion in `await waitFor(...)`
(`PaymentListPage.search.test.tsx:67-71`); the assertion string is unchanged.

**D5 — `TreasuryTenantScope.test.tsx`'s generic api mock still returns `meta: { total: 0 }`**
(`src/features/treasury/__tests__/TreasuryTenantScope.test.tsx:135`). The plan only asked for the
line-181 comment/key update, so the fallback fixture was left alone. Consequence: `OffsetPagination`
renders with `undefined` page numbers in that one test. Harmless (no crash, no assertion on it, suite
green), but a reviewer may want the six-field meta there too.

**D6 — two repo-wide audits are red on the baseline, not on this lane.** `pnpm audit:keys` reports one
new unscoped-key violation in `src/features/uom/hooks/useUnits.ts:53` and `pnpm audit:design-system`
reports C3 violations in `src/features/import/pages/ImportWizardPage.tsx`. Neither file is touched by
this lane (`git status` lists only treasury/dashboard/e2e files). No violation is reported against any
file this lane changed.

---

## 3. Reviewer pointers

**treasury-reviewer**
- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:260-320` — validated-only
  reads, mandatory pagination, six-field meta, `payment_date DESC, id DESC`.
- `apps/api/app/Modules/Treasury/Presentation/Requests/ListPaymentsRequest.php` — every accepted list
  parameter, `per_page` capped at 100. Note the behaviour change: an unknown `status` is now a 422
  instead of silently returning an empty list.
- `apps/api/tests/Feature/Treasury/PaymentTest.php:1160-1222` — the two new tests. Read D1 first.
- `apps/api/tests/Feature/Treasury/TreasuryCompanyIsolationTest.php:415,423,429` — the three search reads
  the plan asked to keep bounded; green on both drivers.
- **Contract break to weigh at promotion:** callers that omitted `page` used to get the whole company
  payment set. In-repo sweep found no POS/mobile consumer of `GET /api/v1/payments`
  (`grep -rn "api/v1/payments" apps/ scripts/` returns only `apps/api/tests` and `apps/web/e2e`), which
  matches the plan's Phase 0 note; external-owner evidence is still a promotion precondition.

**frontend-conventions-reviewer**
- `apps/web/src/features/treasury/PaymentListPage.tsx:57-90, 235-249` — state, key, URL order,
  `keepPreviousData`, canonical `OffsetPagination`. Key stays rooted at `payments` so existing bare-prefix
  invalidation still matches.
- `apps/web/src/features/dashboard/Dashboard.tsx:149-153` — `page=1&per_page=5`, unsupported `sort` gone.
- `apps/web/e2e/money-campaign/w5c-support.ts:193-232` — the page-walking `allPaymentIds()`; it reads the
  raw response because `asJsonResult()` discards sibling `meta`.
- `apps/web/e2e/payments.spec.ts:6-22, 51` — the two shared meta fixtures and the rendered-pagination
  regression. **Type-checked, not executed.**
- Deviations D3, D4, D5 are the three places a convention reviewer will most likely want an opinion.

**Still owed before promotion (per the plan's Step 10 and batch-2 gate)**
1. Browser check: five-row dashboard widget and payment list page 2.
2. Live-stack Playwright run of `e2e/payments.spec.ts`, `e2e/money-campaign/expenses-lifecycle.spec.ts`
   and `e2e/money-campaign/w8-isolation.spec.ts` (the W8 `per_page=100` reads stay bounded campaign
   fixtures, not whole-set helpers).
3. External POS/mobile owner pagination evidence, or a blocked rollout for that consumer.
4. Both named reviewer gates returning MERGE, and a rebase on `dev` before review.

---

## Fix round 1 (2026-09-04) — treasury-reviewer gate r1

Commit: `cbbdad9c0` — `fix(request-hygiene t3): gate r1 fixes — falsifying tie-break fixture, nullable filters, 422 boundaries`
(2 files, +104 / −14: `ListPaymentsRequest.php`, `tests/Feature/Treasury/PaymentTest.php`).
`PaymentController.php` is **byte-identical to `4bdbebe24`** — the removal in §R1.1 below was temporary
and reverted (`git diff --stat` on that path is empty).

### R1.0 — Environment note (PG port conflict)

`autoerp_postgres` was **down** at the start of this round and `locaplex-postgres` (another project,
started ~3 min earlier) had taken host port **5433**; `docker start autoerp_postgres` fails with
`Bind for 0.0.0.0:5433 failed: port is already allocated`. Rather than stop another session's
container, this round ran its PG legs against a **throwaway lane-private instance** with the same
image and init scripts:

```
docker run -d --name autoerp_pg_t3 \
  -e POSTGRES_DB=autoerp_test_t3 -e POSTGRES_USER=autoerp -e POSTGRES_PASSWORD=autoerp_secret \
  -p 127.0.0.1:5453:5432 \
  -v <repo>/apps/erp/docker/postgres/init:/docker-entrypoint-initdb.d:ro \
  timescale/timescaledb:latest-pg16
→ extensions present: pg_trgm 1.6, plpgsql, timescaledb 2.23.1, unaccent 1.1, uuid-ossp 1.1
```

Every PG command below is therefore prefixed with
`DB_HOST=127.0.0.1 DB_PORT=5453 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret DB_DATABASE=autoerp_test_t3 DB_CENTRAL_DATABASE=autoerp_test_t3`.
The database name and credentials are the ones the lane brief reserved; only the host port differs.
**Container `autoerp_pg_t3` is left running** — remove it with `docker rm -f autoerp_pg_t3` once
`autoerp_postgres` can reclaim 5433.

### R1.1 — Gate item 1: the tie-break test is now genuinely falsifying (closes D1)

`test_tied_payment_dates_cross_two_pages_without_duplicates_or_omissions`
(`apps/api/tests/Feature/Treasury/PaymentTest.php:1181-1239`) was rewritten:

- 30 **explicit** ids `7f000000-0000-4000-8000-%012x` for `x = 1..30`, set through
  `(new Payment)->forceFill([...])->save()` (the model's `HasUuids` only mints a key when none is
  set, and `id` is not `$fillable`). Ordered `HasUuids` keys are therefore out of the picture.
- Insertion order is the fixed permutation `1, 3, 5, …, 29, 30, 28, …, 2`: the **first** row inserted
  carries the **lowest** id and the **last** row inserted carries the **second-lowest**, so `id DESC`
  matches neither natural/insertion order nor reverse-insertion order.
- `$expectedIds` is now computed **from the ids themselves** — `rsort($ids, SORT_STRING)` — not from a
  database query. The shared prefix plus a zero-padded lowercase-hex suffix makes a descending string
  sort exactly `id DESC`.
- The assertion is unchanged in shape: `assertCount(30)`, `assertCount(30, array_unique(...))`,
  `assertSame($expectedIds, $actualIds)` across `page=1` and `page=2` at `per_page=15`.

**Proof — RED with `->orderByDesc('id')` temporarily removed from `PaymentController.php:299`:**

```
$ grep -n "orderByDesc" app/Modules/Treasury/Presentation/Controllers/PaymentController.php
298:            ->orderByDesc('payment_date')          ← the id tie-break line is gone
```

SQLite — `php artisan test tests/Feature/Treasury/PaymentTest.php --filter='tied_payment_dates'`:
```
  ⨯ tied payment dates cross two pages without duplicates or omissions
  …
  +    25 => '7f000000-0000-4000-8000-000000000009',
  +    26 => '7f000000-0000-4000-8000-000000000007',
  +    27 => '7f000000-0000-4000-8000-000000000005',
  +    28 => '7f000000-0000-4000-8000-000000000003',
       29 => '7f000000-0000-4000-8000-000000000001',
   ]
  at tests/Feature/Treasury/PaymentTest.php:1238
  ➜ 1238▕         self::assertSame($expectedIds, $actualIds);
  1   tests/Feature/Treasury/PaymentTest.php:1238
  Tests:    1 failed (6 assertions)
  Duration: 3.54s
```

PostgreSQL — `… php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentTest.php --filter='tied_payment_dates'`:
```
  ⨯ tied payment dates cross two pages without duplicates or omissions
  …
  +    25 => '7f000000-0000-4000-8000-000000000009',
  +    26 => '7f000000-0000-4000-8000-000000000007',
  +    27 => '7f000000-0000-4000-8000-000000000005',
  +    28 => '7f000000-0000-4000-8000-000000000003',
       29 => '7f000000-0000-4000-8000-000000000001',
   ]
  at tests/Feature/Treasury/PaymentTest.php:1238
  ➜ 1238▕         self::assertSame($expectedIds, $actualIds);
  1   tests/Feature/Treasury/PaymentTest.php:1238
  Tests:    1 failed (6 assertions)
  Duration: 16.18s
```

The tie-break line was then restored (`grep -n "orderByDesc"` → `298` *and* `299`; `git diff` on the
controller path is empty), and the test is **green on both drivers** inside the full Step 10 runs in
§R1.4 below (`✓ tied payment dates cross two pages without duplicates or omissions`).

**Deviation D1 in §2 is now closed.**

### R1.2 — Gate item 2: `nullable` on `status` and `search` (RED first)

`ListPaymentsRequest.php:23-27`:
```php
// `nullable` on both filters: the web list page sends `status=` /
// `search=` when the operator clears a filter, and the global
// ConvertEmptyStringsToNull middleware turns those into null.
'status' => ['sometimes', 'nullable', 'string', Rule::enum(PaymentStatus::class)],
'search' => ['sometimes', 'nullable', 'string', 'max:120'],
```

New test `test_index_accepts_cleared_filters_sent_as_empty_strings`
(`PaymentTest.php:1241-1272`): `GET /api/v1/payments?status=&search=` must return 200 with the six-field
meta envelope.

**RED — before adding `nullable`** (`php artisan test tests/Feature/Treasury/PaymentTest.php --filter='cleared_filters|per_page_above_100|page_zero|search_longer_than_120'`):
```
  ⨯ index accepts cleared filters sent as empty strings                  3.71s
  ✓ index rejects per page above 100 with validation envelope            0.63s
  ✓ index rejects page zero with validation envelope                     0.64s
  ✓ index rejects search longer than 120 characters                      0.64s
  ────────────────────────────────────────────────────────────────────────────
   FAILED  Tests\Feature\Treasury\PaymentTest > index accepts cleared filter…
  Expected response status code [200] but received 422.
  Failed asserting that 422 is identical to 200.
  at tests/Feature/Treasury/PaymentTest.php:1260
  Tests:    1 failed, 3 passed (10 assertions)
```

**GREEN — same command after adding `nullable`:**
```
   PASS  Tests\Feature\Treasury\PaymentTest
  ✓ index accepts cleared filters sent as empty strings                  3.51s
  ✓ index rejects per page above 100 with validation envelope            0.64s
  ✓ index rejects page zero with validation envelope                     0.61s
  ✓ index rejects search longer than 120 characters                      0.62s
  Tests:    4 passed (20 assertions)
```

The controller needed no change: `is_string($validated['status'] ?? null)` and
`is_string($search) && $search !== ''` already skip a `null` filter.

### R1.3 — Gate item 3: 422 boundary tests (honest status: NOT TDD reds)

Three tests added, each with `app()->setLocale('en')` and each asserting both
`error.code === 'VALIDATION_ERROR'` and the exact `error.errors.<field>.0` string, matching the global
renderer at `apps/api/bootstrap/app.php:323-333`:

| Test (`PaymentTest.php`) | Request | Asserted message |
|---|---|---|
| `test_index_rejects_per_page_above_100_with_validation_envelope` (`:1274`) | `?per_page=101` | `The per page field must not be greater than 100.` |
| `test_index_rejects_page_zero_with_validation_envelope` (`:1288`) | `?page=0` | `The page field must be at least 1.` |
| `test_index_rejects_search_longer_than_120_characters` (`:1302`) | `?search=` + 121 × `x` | `The search field must not be greater than 120 characters.` |

**Stated plainly, as the gate asked:** these three **passed on their very first run** (see the RED block
in §R1.2, where all three are already `✓` before any production change). They are **boundary proofs of
the caps shipped in `4bdbebe24`, not test-first reds.** They would only have been red against a
`ListPaymentsRequest` without `max:100` / `min:1` / `max:120`, which never existed on this branch.

### R1.4 — Gate item 4: cap at 120 and validated-only reads (grep proof)

```
$ grep -n "max:120" app/Modules/Treasury/Presentation/Requests/ListPaymentsRequest.php
27:            'search' => ['sometimes', 'nullable', 'string', 'max:120'],

$ awk 'NR>=260 && NR<=320' app/Modules/Treasury/Presentation/Controllers/PaymentController.php \
    | grep -nE '\$request->(input|query|has|all|get|integer|string|boolean)'
(no output — exit 1)

$ awk 'NR>=260 && NR<=320' app/Modules/Treasury/Presentation/Controllers/PaymentController.php \
    | grep -n '\$request->'
5:        $validated = $request->validated();
```

`index()` reads the request exactly once, through `validated()`.

### R1.5 — Step 10 verification (path-scoped, both drivers)

**SQLite** — `php artisan test tests/Feature/Treasury/PaymentTest.php tests/Feature/Treasury/PaymentCompanyScopeTest.php tests/Feature/Treasury/TreasuryCompanyIsolationTest.php`:
```
   FAILED  Tests\Feature\Treasury\PaymentTest > supplier invoice payment cle…
   Failed asserting that two values of enumeration …PaymentType are equal,
   SupplierPayment does not match expected DocumentPayment.
   at tests/Feature/Treasury/PaymentTest.php:578
  Tests:    1 failed, 46 passed (152 assertions)
  Duration: 38.51s
```

**PostgreSQL** (`autoerp_test_t3` on the §R1.0 instance) — same three paths with `-c phpunit-pgsql.xml`:
```
   FAILED  Tests\Feature\Treasury\PaymentTest > supplier invoice payment cle…
   … SupplierPayment does not match expected DocumentPayment.
   at tests/Feature/Treasury/PaymentTest.php:578
  Tests:    1 failed, 46 passed (152 assertions)
  Duration: 131.38s
```

**Counts: 1 failed / 46 passed on each driver.** The single failure on each is the pre-existing
baseline red **D2** (`test_supplier_invoice_payment_clears…`), untouched by this lane and proven
pre-existing on `b133caf21` in §Step 3. The previous round reported 1 failed / 42 passed; the delta of
+4 is exactly the four tests added in this round (the tie-break test was rewritten, not duplicated).

**PHPStan level 8:**
```
./vendor/bin/phpstan analyse app/Modules/Treasury/Presentation/Requests/ListPaymentsRequest.php \
  app/Modules/Treasury/Presentation/Controllers/PaymentController.php --memory-limit=2G
→  [OK] No errors
```

**Pint:**
```
./vendor/bin/pint --test app/Modules/Treasury/Presentation/Requests/ListPaymentsRequest.php \
  app/Modules/Treasury/Presentation/Controllers/PaymentController.php \
  tests/Feature/Treasury/PaymentTest.php
→ {"result":"pass"}
```

**Frontend:** untouched this round — no `apps/web` file changed (`git status` in §R1.6), so no vitest
or typecheck leg was re-run.

### R1.6 — Follow-ups explicitly deferred out of this round (gate instruction)

1. **LIKE wildcard escaping in the payment search.** `PaymentController.php:283` builds
   `'%'.$search.'%'` and binds it straight into `like` on `reference` and `partners.name`; a `%` or `_`
   typed by the operator is still a wildcard, and no `ESCAPE` clause is set. Task 2 solved the same
   problem for stock-movement search with bound `LOWER(...) LIKE ? ESCAPE '!'` predicates (plan rev 9,
   B2 fold row). Payments should adopt that shape in a follow-up lane, with literal-percent and
   literal-underscore tests on both drivers.
2. **FE `status` union drift vs `PaymentStatus` — verified, and already wrong today.**
   `apps/web/src/features/treasury/PaymentListPage.tsx:34` hand-writes
   `status: 'pending' | 'completed' | 'cancelled'`, but
   `apps/api/app/Modules/Treasury/Domain/Enums/PaymentStatus.php:7` is
   `pending | completed | failed | reversed`. So `cancelled` does not exist on the backend, and
   `failed` / `reversed` are missing from the frontend; the same file's mock fixture
   (`PaymentListPage.test.tsx:50`) repeats the wrong union, while
   `PaymentDetailPage.tsx:70` independently declares the *correct* four cases. Because the type is
   hand-rolled rather than the generated DTO, none of this is a TypeScript error — and now that an
   unknown `status` is a hard 422 (this lane's behaviour change), a status filter wired to
   `cancelled` would fail at runtime instead of returning a silent empty list. Fix belongs in a
   frontend lane under the "no hand-rolled FE type beside a generated DTO" rule (conventions 11).
   Not touched here: this round shipped no `apps/web` change.

### R1.7 — Gate items status

| Gate item | Status |
|---|---|
| 1 — falsifying tie-break fixture, red on both drivers with the tie-break removed | **Done** (§R1.1) — D1 closed |
| 2 — `nullable` on `status`/`search` + red-first empty-string test | **Done** (§R1.2) |
| 3 — three 422 boundary tests with exact messages | **Done** (§R1.3), declared as boundary proofs, not reds |
| 4 — `max:120` confirmed, `index()` reads only `validated()` | **Done** (§R1.4) |
| Out of scope this round | LIKE escaping, FE status union — recorded in §R1.6 |

Everything still owed at the bottom of §3 (browser check, live Playwright run, external-consumer
pagination evidence, both reviewer gates, rebase on `dev`) is unchanged by this round.
