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
