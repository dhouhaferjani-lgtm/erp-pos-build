# Gate r2 — Request Hygiene Phase A, Task 3 (backend / treasury half, S-2)

**Reviewer:** treasury-reviewer (adversarial, code-grounded)
**Date:** 2026-09-04
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t3`
**Branch:** `lane/rh-t3-payments-list` · **Base:** `dev` @ `b133caf21`
**Reviewed range:** `b133caf21..bebf67a29 -- apps/api` (3 files, +212 / −27)
**Commits:** `4bdbebe24` backend · `522bd92bc` web+e2e · `f699245e7` handback · `cbbdad9c0` fix round 1 · `bebf67a29` handback fix round
**Scope:** backend only. The web/e2e half (`522bd92bc` + in-flight uncommitted edits) is the
frontend-conventions-reviewer's gate.

---

## VERDICT: **MERGE** (backend half) — spec ✅ + quality APPROVED

All four gate-r1 items are closed against code, not against the handback narrative. The four
findings below are all **non-blocking**; none of them can produce a wrong number, a cross-company
read, or a broken in-repo caller. Zero blocking findings.

**Backend promotion is still conditioned on the two lane-level preconditions the handback already
records** (they are not gate findings, they are unfinished lane work): the live-stack Playwright run
and the browser check of the five-row dashboard / payment-list page 2, and the
frontend-conventions-reviewer gate on the web half.

---

## 0. Worktree state at review time

```
$ git -C <worktree> status --porcelain
 M apps/web/src/features/treasury/PaymentListPage.search.test.tsx
 M apps/web/src/features/treasury/PaymentListPage.test.tsx
 M apps/web/src/features/treasury/PaymentListPage.tsx
```

**No `apps/api` path is dirty** — every backend line reviewed here is committed at `bebf67a29`.
The three modified web files are a parallel FE fix round in progress (it moves the page-reset out
of `useEffect` into render and de-duplicates the hand-rolled `Payment` type); explicitly out of
this gate's scope. Because the tree carried another round's uncommitted work, I did **not**
re-perform the destructive "remove `orderByDesc('id')` and re-run" probe — see §1 item 1 for how
falsifiability was established instead.

```
$ git -C <worktree> diff --stat 4bdbebe24..bebf67a29 -- apps/api
 .../Presentation/Requests/ListPaymentsRequest.php  |   7 +-
 apps/api/tests/Feature/Treasury/PaymentTest.php    | 111 +++++++++++++++---
```
→ `PaymentController.php` is **byte-identical to `4bdbebe24`**; the handback's claim that the r1
tie-break probe was reverted is confirmed by the diff, not taken on trust.

---

## 1. Re-verification of the four gate-r1 items

### Item 1 — the tie-break test is genuinely falsifying ✅

`apps/api/tests/Feature/Treasury/PaymentTest.php:1187-1239`.

- `:1197-1200` — 30 **explicit** ids `sprintf('7f000000-0000-4000-8000-%012x', 1..30)`, set via
  `(new Payment)->forceFill([... 'id' => …])->save()` at `:1207-1222`. Laravel's `HasUuids`
  `creating` hook only mints a key when the attribute is empty, so the ordered-UUID coupling that
  made the r1 fixture vacuous (`apps/api/app/Modules/Treasury/Domain/Payment.php:18`) is out of the
  picture.
- `:1204` — insertion permutation `[...range(1,29,2), ...range(30,2,-2)]` = `1,3,5,…,29,30,28,…,2`.
  The **first** row inserted holds the **lowest** id and the **last** holds the **second-lowest**,
  so neither insertion order nor reverse-insertion order coincides with `id DESC`. `:1205` asserts
  the permutation really has 30 elements (guards a typo in the ranges).
- `:1227-1228` — `$expectedIds = $ids; rsort($expectedIds, SORT_STRING);` — the oracle is derived
  from the fixture, **not** from a database query that would re-use the very `ORDER BY` under test
  (that was the r1 defect). Verified by hand that a descending byte sort of a fixed prefix plus a
  zero-padded lowercase-hex suffix (`…0001`…`…001e`) is exactly numeric-descending, so the oracle
  is `30,29,…,1`.
- `:1236-1238` — `assertCount(30)`, `assertCount(30, array_unique(...))`, `assertSame($expectedIds,
  $actualIds)` across `page=1` and `page=2` at `per_page=15`. Without `->orderByDesc('id')` the
  tied-date rows come back in engine order (insertion order on both drivers), which is
  `01,03,…,1d,1e,1c,…,02` — not equal to the oracle, so the test is red. The handback §R1.1
  documents exactly that red on both SQLite and PostgreSQL with the diff excerpt showing the
  odd/even interleave.
- Load-bearing in production, not just in the fixture: `payment_date` is cast `'date'`
  (`apps/api/app/Modules/Treasury/Domain/Payment.php:122`), so **every payment recorded on the same
  day ties**. A missing tie-break would drop/duplicate rows across pages for any real operator.

### Item 2 — `nullable` on `status` / `search` + the 200-on-empty test ✅

`apps/api/app/Modules/Treasury/Presentation/Requests/ListPaymentsRequest.php:26-27`:
```php
'status' => ['sometimes', 'nullable', 'string', Rule::enum(PaymentStatus::class)],
'search' => ['sometimes', 'nullable', 'string', 'max:120'],
```
Test `test_index_accepts_cleared_filters_sent_as_empty_strings` at `PaymentTest.php:1241-1267` —
`GET /api/v1/payments?status=&search=` → `assertOk()` **plus** the six-field meta structure
(`:1261-1264`) and `meta.current_page`/`meta.per_page` values (`:1265-1266`). It asserts data
meaning (the envelope), not just a status code. Green on both drivers (§4).
Direct rule probe (no HTTP, no DB) confirms the semantics:
`{"status":null} => PASS`, `{"search":null} => PASS`.

### Item 3 — 422 boundary tests assert `error.code` + the exact `error.errors.<field>.0` ✅

- `PaymentTest.php:1269-1281` `per_page=101` → `error.code = VALIDATION_ERROR` **and**
  `error.errors.per_page.0 = 'The per page field must not be greater than 100.'`
- `PaymentTest.php:1283-1295` `page=0` → `error.errors.page.0 = 'The page field must be at least 1.'`
- `PaymentTest.php:1297-1309` 121-char search → `error.errors.search.0 = 'The search field must not
  be greater than 120 characters.'`

Each pins the locale first (`app()->setLocale('en')` at `:1271`, `:1285`, `:1299`) so the message
assertions are not environment-dependent. All three green on both drivers.

### Item 4 — `max:120` present, `index()` consumes only `validated()` ✅

`ListPaymentsRequest.php:27` carries `'max:120'`.
`PaymentController.php:260-320`: `$validated = $request->validated()` at `:264`, then only
`$validated[...]` is read — `:274` `partner_id`, `:277` `status`, `:283` `search`, `:301` `per_page`,
`:304` `page`. No `has()` / `input()` / `query()` / `integer()` anywhere in the method; the only
other `$request` use is the type-hint at `:260`. Every other action on the controller keeps
`Request` (`show` `:322`, `store` `:340`), as the plan requires.

---

## 2. Full-lane backend checks

### 2.1 Tenant + company scoping — unchanged, still before pagination ✅

`PaymentController.php:266-272` keeps the api.treasury.075 / go-live-audit-#4 comment verbatim and
the two predicates:
```php
$query = Payment::query()
    ->where('tenant_id', $tenantId)
    ->where('company_id', $companyId)
```
Both are applied to `$query` **before** `->paginate(...)` at `:300`, so the `COUNT(*)` behind
`meta.total` is scoped too (a scope applied after pagination would leak a foreign-company row
count). Second-company behaviour is covered by live tests, not by reading:
`PaymentCompanyScopeTest::payment index does not leak other company payments` and the 13
`TreasuryCompanyIsolationTest` cases (incl. `index filters by search reference or partner name`,
`apps/api/tests/Feature/Treasury/TreasuryCompanyIsolationTest.php:413-431`) are green on both
drivers after the change.

### 2.2 Determinism on PostgreSQL ✅

`payment_date DESC, id DESC` at `PaymentController.php:297-299`, proven end-to-end on PG by
`test_tied_payment_dates_cross_two_pages_without_duplicates_or_omissions` (1.61s, green in the PG
run below) with 30 rows sharing one `payment_date`.

### 2.3 In-repo consumers of `GET /api/v1/payments` ✅ (one FE note, not a backend blocker)

| Consumer | file:line | Sends pagination? | Verdict |
|---|---|---|---|
| Payment list page | `apps/web/src/features/treasury/PaymentListPage.tsx:79-83` | `page` + `per_page` always | OK |
| Dashboard recent payments | `apps/web/src/features/dashboard/Dashboard.tsx:152` | `?page=1&per_page=5` | OK (was `limit`/`sort`, never read) |
| Partner detail payments tab | `apps/web/src/features/partners/PartnerDetailPage.tsx:201-206` | `partner_id` + `page` + `per_page` | OK (`partner_id` is a route UUID, passes the new `uuid` rule) |
| Campaign helper `allPaymentIds()` | `apps/web/e2e/money-campaign/w5c-support.ts` | page-walks to `meta.last_page` | OK |
| W8 isolation reads | `apps/web/e2e/money-campaign/w8-isolation.spec.ts:71,201,390,483` | `?per_page=100` | OK, at the new cap |
| POS | — | no `/payments` API call exists (`apps/pos/src` grep: only local receipt/payment-store types) | N/A |
| Mobile | — | no mobile app in this repo (`apps/` = `api`, `pos`, `web`) | N/A |

No in-repo caller sends `status`, so the "unknown `status` is now 422" behaviour change breaks
nothing today. Grep for a `status=` query on `/payments` across `apps/` returns exactly one hit —
the new test at `PaymentTest.php:1259`.

**Noted, not blocking (FE round owns it):** `apps/web/src/features/treasury/PaymentListPage.tsx:34`
types `status` as `'pending' | 'completed' | 'cancelled'` while
`apps/api/app/Modules/Treasury/Domain/Enums/PaymentStatus.php:9-12` is
`pending|completed|failed|reversed`. The union is only used to render a badge for a value the server
sent, so it cannot trigger the new 422 — but it is a FE/BE contract drift and `failed`/`reversed`
have no badge branch. Confirmed a separate web fix round is in flight on that file.

### 2.4 Money handling (rule 19) ✅

`formatPayment()` (`PaymentController.php:2185-2225`) is **outside the diff** and unchanged.
`'amount' => $payment->amount` (`:2207`) with `'amount' => 'decimal:3'`
(`apps/api/app/Modules/Treasury/Domain/Payment.php:121`); allocation amounts pass through at `:2221`.
No `(float)`, no `number_format`, no `bcmath` scale literal, no `CurrencyScaleResolverInterface`
call added anywhere in the diff. The only numeric casts introduced are `(int)` on `per_page`/`page`
(`:301`, `:304`) — pagination counters, not money.

### 2.5 Journey-hardening cross-cuts

- **Second-of-everything:** `payments` is not a catalogue entity (not code/SKU/number-keyed and not
  operator-edited as a catalogue), the lane adds **no migration and no unique key**, and the read is
  idempotent by nature. Second-company coverage nonetheless exists and is green
  (`PaymentCompanyScopeTest`, `TreasuryCompanyIsolationTest`). No finding.
- **One surface per concept:** the lane introduces one new class name, `ListPaymentsRequest`; grep
  shows no sibling payment-list request/controller/second write path. No new noun. No finding.
- **Benchmark-first:** Task 3 is an internal request-hygiene change to an existing surface, not a new
  user-facing flow; the plan carries the contract. No finding.
- **Data-meaning tests:** all five new tests assert response *content* (row counts, exact id
  sequence, meta values, exact validation messages), not bare status codes. No finding.

### 2.6 Pre-existing red — re-verified independently ✅

`PaymentTest::test_supplier_invoice_payment_clears_401_and_reduces_payable_balance` fails on both
drivers in the lane. Verified pre-existing **without trusting the handback's revert**: no commit
between `b133caf21` and the main checkout's `HEAD` (`451e444b6`) touches
`apps/api/tests/Feature/Treasury/PaymentTest.php` or `apps/api/app/Modules/Treasury`
(`git diff --stat b133caf21..HEAD -- <those paths>` → empty), and that file is unmodified in the
main working tree. Running the single filter there (read-only, SQLite) reproduces it at the pristine
line 576. The main checkout was not modified.

---

## 3. Findings (all NON-BLOCKING — 0 blocking)

**[Minor] `apps/api/app/Modules/Treasury/Presentation/Requests/ListPaymentsRequest.php:23-25` (and
the mirrored comment at `apps/api/tests/Feature/Treasury/PaymentTest.php:1256-1257`) — the stated
justification for `nullable` is factually false.** The comment says "the web list page sends
`status=` / `search=` when the operator clears a filter". `PaymentListPage.tsx:79-83` builds the URL
as `if (search) params.set('search', search); params.set('page', …); params.set('per_page', …)` — it
**never** sets `status` at all, and only sets `search` when truthy, so it can never emit either
empty parameter. *Why it matters:* the rule itself is correct and defensively right
(`ConvertEmptyStringsToNull` is real, and any future URL-synced filter form will emit `status=`), but
a future reader who greps for the cited FE behaviour will not find it and may either "correct" the FE
to match the comment or delete the `nullable` as unused. *Fix:* reword to the actual reason — "an
empty query parameter reaches the validator as `null` after `ConvertEmptyStringsToNull`; a cleared
filter must read as *no filter*, not as a 422" — and drop the claim about what the page sends.

**[Minor] `ListPaymentsRequest.php:28-29` — `page` / `per_page` are not `nullable`, so
`?page=&per_page=` is a 422 while `?status=&search=` is a 200.** Confirmed empirically against the
real rule set (no HTTP, no DB):
```
{"page":null}     => FAIL {"page":["The page field must be an integer.","The page field must be at least 1."]}
{"per_page":null} => FAIL {"per_page":["The per page field must be an integer.","The per page field must be at least 1."]}
```
*Why it matters:* the exact reasoning that earned `status`/`search` their `nullable` in fix round 1
applies verbatim to the pagination parameters, and this request is the template Task 2's shared
list-request pattern is being copied from. Today no in-repo caller emits an empty `page`, so nothing
breaks — but a URL-synced list page that round-trips `?page=` through `URLSearchParams` would get a
422 instead of the documented default of 1. *Fix (or an explicit "won't fix" line in the handback):*
add `'nullable'` to both and keep the `?? 25` / `?? 1` defaults at `PaymentController.php:301,304`,
which already handle `null`.

**[Minor] `apps/api/tests/Feature/Treasury/PaymentTest.php:1179-1184` — the default-cap test asserts
the page *size* but not the page *contents*.** It checks `assertJsonCount(25, 'data')` and the three
meta fields but never that the 25 rows are the 25 **newest**. *Why it matters:* an accidental
`orderBy('payment_date')` (ascending) would keep this test green while showing operators the 25
oldest payments as "recent" — and `Dashboard.tsx:149-152` now relies specifically on "page 1 at 5
rows IS the five newest payments". *Fix:* also assert the first row's `reference` is `CAP-1` (the
`now()->subMinutes(1)` row) — note the fixture's minute offsets collapse under the `date` cast, so
prefer distinct `payment_date` **days** if a strict newest-first assertion is wanted.

**[Minor] `ListPaymentsRequest.php:22` — `partner_id` is shape-validated (`uuid`) but not
existence- or company-scoped (`ScopedExists`).** *Why it matters:* nothing leaks — the index query is
tenant+company scoped at `PaymentController.php:270-271`, so a foreign `partner_id` yields an empty
page rather than another company's rows — and this is strictly tighter than the pre-change code,
which accepted any string. It is only worth a line in the follow-up list so the next reader does not
assume the FK was validated. *Fix (optional):* `ScopedExists` on `partners`, matching the pattern
already imported in this controller (`PaymentController.php:52`).

---

## 4. Commands and outputs

### Backend, SQLite (three files by path)
```
$ cd <worktree>/apps/api && php artisan test \
    tests/Feature/Treasury/PaymentTest.php \
    tests/Feature/Treasury/PaymentCompanyScopeTest.php \
    tests/Feature/Treasury/TreasuryCompanyIsolationTest.php

  ✓ index without page is bounded to 25                                  0.72s
  ✓ tied payment dates cross two pages without duplicates or omissions   0.74s
  ✓ index accepts cleared filters sent as empty strings                  0.68s
  ✓ index rejects per page above 100 with validation envelope            0.64s
  ✓ index rejects page zero with validation envelope                     0.63s
  ✓ index rejects search longer than 120 characters                      0.84s
   PASS  Tests\Feature\Treasury\PaymentCompanyScopeTest
  ✓ payment index does not leak other company payments                   0.88s
   PASS  Tests\Feature\Treasury\TreasuryCompanyIsolationTest   (13 cases, all ✓)
   FAILED  PaymentTest > supplier invoice payment cle…  (pre-existing, :578)
  Tests:    1 failed, 46 passed (152 assertions)
  Duration: 40.73s
```

### Backend, PostgreSQL (`autoerp_pg_t3`, 127.0.0.1:5453, db `autoerp_test_t3`)
```
$ DB_HOST=127.0.0.1 DB_PORT=5453 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
  DB_DATABASE=autoerp_test_t3 DB_CENTRAL_DATABASE=autoerp_test_t3 \
  php artisan test -c phpunit-pgsql.xml \
    tests/Feature/Treasury/PaymentTest.php \
    tests/Feature/Treasury/PaymentCompanyScopeTest.php \
    tests/Feature/Treasury/TreasuryCompanyIsolationTest.php

  ✓ index without page is bounded to 25                                  1.78s
  ✓ tied payment dates cross two pages without duplicates or omissions   1.61s
  ✓ index accepts cleared filters sent as empty strings                  1.64s
  ✓ index rejects per page above 100 with validation envelope            1.31s
  ✓ index rejects page zero with validation envelope                     1.26s
  ✓ index rejects search longer than 120 characters                      1.37s
   PASS  Tests\Feature\Treasury\PaymentCompanyScopeTest      (1 case ✓)
   PASS  Tests\Feature\Treasury\TreasuryCompanyIsolationTest (13 cases ✓)
   FAILED  PaymentTest > supplier invoice payment cle…  (pre-existing, :578)
  Tests:    1 failed, 46 passed (152 assertions)
  Duration: 114.24s
```
Container `autoerp_pg_t3` left running as instructed. The shared `autoerp_postgres` on 5433 was not
touched (port still held by another project).

### Pre-existing red, on base, in the main checkout (read-only)
```
$ cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api && \
  php artisan test tests/Feature/Treasury/PaymentTest.php --filter='supplier_invoice_payment_clears'
  ⨯ supplier invoice payment clears 401 and reduces payable balance      4.54s
  SupplierPayment does not match expected DocumentPayment
  at tests/Feature/Treasury/PaymentTest.php:576      ← pristine line number
  Tests:    1 failed (2 assertions)
```

### PHPStan level 8 (the two app files)
```
$ ./vendor/bin/phpstan analyse \
    app/Modules/Treasury/Presentation/Requests/ListPaymentsRequest.php \
    app/Modules/Treasury/Presentation/Controllers/PaymentController.php --memory-limit=2G
 [OK] No errors
```

### Pint
```
$ ./vendor/bin/pint --test <3 touched php files>
{"result":"pass"}
```

### Direct rule probe (bootstrapped app, no HTTP, no DB)
```
{"page":null}                  => FAIL {"page":["The page field must be an integer.","The page field must be at least 1."]}
{"per_page":null}              => FAIL {"per_page":[...]}
{"page":"2"}                   => PASS {"page":"2"}
{"status":null}                => PASS {"status":null}
{"status":"cancelled"}         => FAIL {"status":["The selected status is invalid."]}
{"partner_id":"not-a-uuid"}    => FAIL {"partner_id":["The partner id field must be a valid UUID."]}
{"search":null}                => PASS {"search":null}
{"page":"0"}                   => FAIL {"page":["The page field must be at least 1."]}
```

### Consumer sweep
```
$ grep -rn "api/v1/payments|'/payments" --include='*.ts' --include='*.tsx' --include='*.php' apps/ scripts/
→ list reads only in apps/web/src/{features/treasury,features/dashboard,features/partners} and
  apps/web/e2e{,-local}; every one sends page/per_page or page-walks. apps/pos has no /payments call.
$ grep -rn "payments?[^\"']*status=" apps/
→ apps/api/tests/Feature/Treasury/PaymentTest.php:1259 only
```

---

## 5. What held up

- The whole plan-Task-3 backend contract is implemented as specified: dedicated `ListPaymentsRequest`,
  `validated()`-only `index()`, mandatory pagination defaulting to 25 and capped at 100, six-field
  offset meta, `payment_date DESC, id DESC`, search preserved across reference + partner name.
- Tenant+company scoping is untouched and correctly ordered relative to pagination; the api.treasury.075
  / go-live-audit-#4 comment survived intact.
- Money serialisation is byte-identical to base — no float, no `number_format`, no scale-resolver call.
- The r1 test-quality objections are genuinely fixed, not papered over: the oracle no longer comes from
  the query under test, the 200-on-empty test asserts the envelope, and the 422 tests pin exact
  per-field messages.
- The contract break (omitting `page` no longer returns the whole set) has no in-repo victim, and the
  one helper that relied on it was converted to a real page walk.

## 6. One line to fix before merge

Nothing blocking — optionally reword the false `nullable` rationale at `ListPaymentsRequest.php:23-25`
and add `'nullable'` to `page`/`per_page` for symmetry; both can also ride a later round.
