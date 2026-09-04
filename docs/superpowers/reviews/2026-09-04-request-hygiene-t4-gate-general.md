# Gate — Request Hygiene Phase A, Task 4 (bounded audit reads + legacy document `limit` clamp, S-3 / S-33)

- **Reviewer:** general adversarial merge gate (Opus), round 1
- **Date:** 2026-09-04
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t4`, branch `lane/rh-t4-audit-bounds`
- **Base:** `b133caf21` · **Commits reviewed:** `817ac93e2` (impl) + `4cbb4d48e` (handback)
- **Handback:** `docs/handoff/HANDBACK-request-hygiene-T4-2026-09-04.md`
- **Plan:** `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1185-1594`

---

## VERDICT: CHANGES

One blocking finding (B1, four sub-cases, one root cause). Everything else in the lane is
verified correct and green on both drivers. The fix is surgical: add `nullable` to the five
optional string params and stop reading `per_page`/`page` off the raw request. **The plan's own
Step 1 snippet carries the same gap**, so the plan needs the same amendment (a plan rev), not just
the lane.

No tenancy hole. No 500. No regression for any in-repo consumer. Merge is blocked on a
*silent-wrong-answer* class defect, which is exactly the class S-3 exists to close.

---

## BLOCKING

### B1 — Empty query-string params bypass every non-implicit rule, so `''` reaches the controller as a *real filter value*; the invariant asserted in the controller docblock is false

`apps/api/app/Modules/Compliance/Presentation/Requests/ListAuditEventsRequest.php:36-48` —
none of `event_type`, `aggregate_type`, `aggregate_id`, `from`, `to`, `include` carries `nullable`.

Laravel skips non-implicit rules for a present-but-empty string
(`Validator::presentOrRuleIsImplicit()`: `if (is_string($value) && trim($value) === '') return $this->isImplicit($rule);`).
`required_with` is implicit; `string`, `max`, `date_format`, `in`, `integer` are **not**. And
`required_with:X` does not fire when `X` is itself empty (`allFailingRequired`). So an empty param
passes validation untouched and lands in `validated()` as `''`.

Measured (worktree, `php artisan tinker`, exact rules array copied from the request):

```
A  ?event_type=&from=&to=          => PASSES validated={"event_type":"","from":"","to":""}
B  ?aggregate_type=&aggregate_id=  => PASSES validated={"aggregate_type":"","aggregate_id":""}
C  ?from=&to=2026-09-03            => FAILS  (correct — the only half-pair the rules catch)
D  ?event_type=                    => PASSES validated={"event_type":""}
E  ?per_page=                      => PASSES validated={"per_page":""}
F  ?include=                       => PASSES validated={"include":""}
```

`CarbonImmutable::parse('')` is **now**, measured in the same worktree:

```
parse empty string : string(25) "2026-09-04T00:06:35+00:00"
```

Consequences at `apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php:48-70`
(`is_string('')` is `true`, so every guard on that block passes the empty string straight through):

- **(a) `GET /api/v1/audit/events?from=&to=`** → line 56-57 `CarbonImmutable::parse('')->startOfDay()` /
  `->endOfDay()` = **today 00:00 .. today 23:59:59** → `$hasRange = true` (line 59) → the read is
  silently scoped to *today* and re-ordered ascending. **Before this lane** the same URL hit
  `if ($from && $to)` with two falsy `''` and fell through to the company branch — the caller got
  the full (100-row) list. Falsifying scenario: an operator opens an audit screen with empty date
  inputs and is shown an empty/one-day log for a company that has thousands of events, with a 200
  and no error. This is the S-3 defect class (unvalidated input reaching `Carbon::parse()`) with the
  failure mode changed from 500 to silent-wrong-answer, not removed.
- **(b) `?aggregate_type=&aggregate_id=`** → line 58 `$hasAggregate = true` with two empty strings →
  `paginateEvents()` adds `where('aggregate_type','')->where('aggregate_id','')`
  (`AuditService.php:208-210`) → **always zero rows**, and `event_type` is suppressed
  (line 64 `eventType: $hasAggregate ? null : $eventType`). This directly contradicts the docblock at
  `AuditController.php:41-43` — *"`required_with` rejects every half-specified aggregate/date pair
  before this method runs, so each validated pair is either complete or absent"*. A pair of empty
  strings is neither complete nor absent, and it reaches the aggregate branch.
- **(c) `?event_type=`** → line 53 `$eventType = ''` → `where('event_type','')`
  (`AuditService.php:205`) → **zero rows** where the pre-lane code returned the whole company list.
- **(d) `?per_page=`** → the controller reads the **raw** request, not the validated set
  (`AuditController.php:69` `$request->integer('per_page', 50)`).
  `InteractsWithData::integer()` is `(int) $this->data($key, $default)`
  (`vendor/.../Support/Traits/InteractsWithData.php:273-276`) → `(int) '' === 0` → the default `50`
  is never applied → `Builder::paginate(0, …)` → `$perPage = value($perPage,$total) ?: $this->model->getPerPage()`
  (`vendor/.../Eloquent/Builder.php:1122`) → **15**, and `meta.per_page` reports 15. Not a crash
  (verified: no division-by-zero path), but the documented "default 50" contract is silently wrong
  for a caller that sends an empty `per_page`. Same for `page=` → 0 → `Paginator::resolveCurrentPage()`.

**Why blocking rather than a note.** No in-repo client calls this endpoint today (verified below),
so nothing regresses on merge — but the lane ships a contract whose stated invariant is false in
code, in a programme whose whole premise is that malformed input must produce a 422 rather than a
quietly different answer. The same empty-string shape is what a browser form emits by default. It
is a one-line-per-rule fix plus one test, and it should not be deferred to the consumer's lane.

**Suggested fix (reviewer's, not binding):** add `nullable` to `event_type`, `aggregate_type`,
`aggregate_id`, `from`, `to`, `include`; read `per_page`/`page` from `$request->validated()` with a
null-coalesce (or `$request->filled(...)`) instead of `$request->integer()`. Add one test asserting
`?event_type=&from=&to=` returns the same body as the bare `GET` (or 422 — the lane must pick and
state which), and one asserting `?per_page=` yields `meta.per_page === 50`. **Amend the plan's
Step 1 snippet at `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1213-1246` to match** —
the lane reproduced the plan verbatim, so the plan carries the defect.

---

## NON-BLOCKING

### N1 — The 92-day ceiling is off-by-one against the queried window, and the boundary is untested
`ListAuditEventsRequest.php:20` `MAX_SPAN_DAYS = 92`, checked as
`diffInDays(...) > 92` (`:56`). Measured in-worktree (Carbon 3.11.4, `diffInDays` returns a signed
float; `after_or_equal:from` guarantees the sign):

```
2026-01-01 -> 2026-04-03 : float(92)   => ACCEPTED
2026-01-01 -> 2026-04-04 : float(93)   => REJECTED
2026-01-01 -> 2026-01-01 : float(0)    => ACCEPTED
```

So the accepted boundary is **diff 92**, which — because the controller widens to
`startOfDay(from) .. endOfDay(to)` (`AuditController.php:56-57`) — is a **93-calendar-day** window.
The plan says "≤ 92 days"; the shipped ceiling is 93 days inclusive. Harmless in magnitude, but the
only span test in the suite uses a 151-day range
(`tests/Feature/Compliance/AuditTrailTest.php:305` `from=2026-01-01&to=2026-06-01`), so **no test
pins either side of the boundary**: narrowing the rule to `>= 92` or widening it to `> 93` would not
turn a single test red. Recommend a two-case boundary test (92 → 200, 93 → 422) and one sentence in
the plan fixing "92 days" vs "93-day window".

### N2 — `orderBy('id')` tie-break is deterministic but not chronological
`AuditService.php:222` `return $query->orderBy('id')->paginate(...)`. `audit_events.id` is a
**UUID** (`database/migrations/tenant/2025_11_30_140000_create_audit_events_table.php:14`
`$table->uuid('id')->primary()`), not an ordered key. **Ruling: acceptable.** The requirement the
tie-break exists for is *stable page traversal* (no row seen twice / skipped across pages), and a
total order on a unique column delivers that on PG and SQLite alike. It is **not** a chronological
tie-break: two events written in the same `occurred_at` tick come back in UUID order, i.e. random
relative to insertion. That is fine for the pagination test, which asserts set equality
(`AuditTrailTest.php:411` `assertEqualsCanonicalizing`), and it is not a regression (the pre-lane
branches had no tie-break at all, i.e. no stability). Flagged only so nobody later reads
`orderBy('id')` as "then by insertion order". If chronological determinism inside a tick is ever
needed, the tie-break should become `created_at, id`.

### N3 — Legacy `meta.total` is the page count, not the collection total
`DocumentController.php:118-121`: `meta.total => $documents->count()` is the size of the **clamped**
page. `?limit=5000` against 101 documents now returns `meta.total = 100` while 101 exist — a caller
cannot tell truncation from exhaustion. Pre-existing shape (the field already meant this before the
clamp), and the lane's `meta.per_page` addition is the right mitigation, so not a lane defect. The
one in-repo reader, `apps/web/src/features/dashboard/Dashboard.tsx:138-142`, uses only
`response.data.data` and never touches `meta` — verified — so nothing breaks.

### N4 — Campaign's five `limit=100` calls now sit exactly on the ceiling — **follow-up ticket recommended**
`apps/web/e2e/campaign/onboarding.campaign.ts:293, 300, 347, 527, 530`, all
`` `${apiRoutes.documents}?limit=100` ``. `max(1, min(100,100)) === 100` — behaviour is unchanged by
this lane (the pre-lane `take((int) $limit)` also returned 100). The trap is that the campaign then
*filters* the returned page (`:301-302`, `:348-350`) and asserts exact counts of `HIST-` documents;
against a reused tenant that has accumulated >100 documents, the assertion silently reads a truncated
page. **Not a Task 4 regression** — it was already truncating at 100 before the clamp existed — but
it is now pinned *at* a documented ceiling instead of an accidental one. Recommend a ticket to move
those five sites to the paginated `page`/`per_page` branch with a cursor loop, or to add
`&document_number=HIST-` style server-side filtering. Do not block T4 on it.

### N5 — Three service methods are now dead production code
`AuditService::getEventsForCompany()` (`:94`), `getEventsForAggregate()` (`:118`),
`getEventsByType()` (`:153`) have **no production caller** after this lane — verified by grep over
`apps/api/app`: the only hits are their own declarations plus `paginateEvents` in the controller.
Two test call sites remain (`AuditTrailTest.php:184`, `:221`) and `getEventsInRange` is still
exercised at `AuditTrailTest.php:240`. The handback flags this and declines to delete; **agreed** —
deleting public service methods is out of Task 4's scope. Ticket it for the programme's cleanup pass.

### N6 — Response-shape change to a published endpoint is undocumented
`include=payload` removes `payload`/`metadata` from the default response, and the default page size
drops from 100 (`getEventsForCompany`'s `$limit = 100`) to 50. No in-repo consumer exists, but the
archived contract docs describe the old shape
(`docs/_archive/live-readiness/07-AUDIT-LOGGING.md:204, :269`;
`docs/_archive/REPORTING-ROADMAP.md:163`). There is no OpenAPI document for `/audit/events` in this
repo (verified) so no spec drifts, but an out-of-repo consumer (mobile, platform) would silently
lose two fields. Worth one line in the programme's change log.

---

## What held up (verified, with citations)

### 1. Tenancy / authorization — CLEAN
- `AuditController::index()` resolves the company **only** from context:
  `AuditController.php:63` `companyId: $this->companyContext->requireCompanyId()`. No request value
  can reach that argument; the request object exposes no `company_id` rule
  (`ListAuditEventsRequest.php:36-48`) and there is no `X-Company-Id` read in the controller.
- `paginateEvents()` applies the company predicate **first and unconditionally**, before any
  optional filter and before pagination: `AuditService.php:203`
  `$query = AuditEvent::query()->where('company_id', $companyId);`. Every subsequent clause
  (`:204-215`) narrows, none widens; there is no `orWhere`, no `when()` that can drop the predicate.
- Route is permission-gated: `ComplianceServiceProvider.php:108-109`
  `Route::get('/audit/events', …)->middleware('can:compliance.view_reprint_log')`, inside the
  `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` group (`:101-105`).
  `ListAuditEventsRequest::authorize()` returning `true` (`:24-27`) does not bypass that — FormRequest
  authorization runs *after* route middleware.
- Cross-tenant hardening tests still falsifying and still green:
  `ComplianceCrossTenantHardeningTest.php:264-276` seeds the **same** `aggregate_id` under company A
  and company B and asserts exactly 1 row with `['ref' => 'doc-A']`;
  `:320-324` asserts admin A sees only the tenant-A event; `:326-330` asserts a foreign
  `X-Company-Id` gets **403**. The lane's only edit to this file is `&include=payload` on two URLs
  (needed because the assertions read `payload`) — the foreign-company request at `:328` is
  deliberately left without it, per plan. No assertion was weakened.
- **No path where a validated parameter widens scope.** B1 makes filters *narrower* than intended,
  never broader.

### 2. Validation contract vs plan — matches, with B1/N1 as the two gaps
| Plan | Shipped | Verdict |
|---|---|---|
| `date_format:Y-m-d` | `ListAuditEventsRequest.php:44-45` | ✅ |
| paired `from`/`to` via `required_with` | `:44-45` | ✅ (except empty-string, B1a) |
| ≤ 92 days | `:20`, `:56` | ✅ mechanism, N1 on the boundary |
| `per_page` 1..100 default 50 | `:47` rule; default at `AuditController.php:69` | ✅ (except empty-string, B1d) |
| payload/metadata only for `include=payload` | `:48` rule; `AuditController.php:73-86` | ✅ |
| `aggregate_id` = `string\|max:100`, **not** uuid | `:42` with the storage-contract comment | ✅ |

- **`aggregate_id` is not `uuid`** and the positive HTTP regression exists and is real:
  `AuditTrailTest.php:439-478` records three events, two keyed `doc-123`, and asserts a **200** with
  `meta.total === 2` for `?aggregate_type=Document&aggregate_id=doc-123`. Narrowing the rule to
  `uuid` turns that into a 422 — genuinely falsifying. Backed by the storage contract
  (`create_audit_events_table.php:19` `$table->string('aggregate_id', 100)`) and by the archived
  contract doc's own example `aggregate_id=INV-001`
  (`docs/_archive/live-readiness/07-AUDIT-LOGGING.md:204`).
- `withValidator` guards correctly: it returns early when `from`/`to` already have errors
  (`:53-55`) and when either is a non-string (`:58-60`), so the span check never runs on garbage.
  `after_or_equal:from` (`:45`) guarantees the `diffInDays` sign is non-negative, so the unsigned/
  signed change in Carbon 3 is not a hazard here.
- `page`/`per_page` are `integer` (`:46-47`); `per_page[]=5` and `per_page=abc` both 422.
- `include` restricted to `payload` (`:48`).
- **Which boundary the tests prove:** only "151 days → 422" (`AuditTrailTest.php:305-309`). Neither
  92 nor 93 is pinned — see N1.
- **Empty-string behaviour:** measured above. They do **not** 422; they 200 with a narrowed result
  set. No web audit page exists to send them (see §5), which is why B1 is a contract defect rather
  than a live regression.

### 3. Branch selection — correct, and the ordering contract is byte-preserved
- `$hasRange = ! $hasAggregate && …` (`AuditController.php:59`) — aggregate wins over range, exactly
  as the old `if/elseif` chain did.
- `eventType: $hasAggregate ? null : $eventType` (`:64`) reproduces the old behaviour where the
  aggregate branch ignored `event_type`; the range branch still forwards it, matching
  `getEventsInRange($…, $eventType)`.
- Half-specified pairs cannot fall through **for absent halves** (`required_with` 422s them — four
  tests: `AuditTrailTest.php:317, 331, 345, 359`). They *can* for present-but-empty halves — B1b.
- `oldestFirst` per branch matches the retired methods exactly:
  old `getEventsForAggregate` `orderBy('occurred_at')` (`AuditService.php:120-123`) and
  `getEventsInRange` `orderBy('occurred_at')` (`:143-146`) → ascending; old `getEventsForCompany`
  (`:96-98`) and `getEventsByType` (`:155-158`) `orderByDesc('occurred_at')` → descending.
  New: `$oldestFirst = $hasAggregate || $hasRange` (`AuditController.php:60`) →
  `AuditService.php:217-221` ascending/descending accordingly. ✅
- `orderBy('id')` tie-break: see N2 (acceptable, ruled).

### 4. Serialization — correct
`AuditController.php:73-89`. `event_hash` is in the **unconditional** base array (`:81`), present on
both shapes. `payload`/`metadata` merge only when `$includePayload` (`:73`, `:84-87`). No float
casts anywhere in the diff (grep over the diff for `(float)`/`floatval`: none). `occurred_at` is
`->toIso8601String()` (`:82`). All six meta fields present and named per plan (`:92-99`:
`current_page`, `last_page`, `per_page`, `total`, `from`, `to`).

### 5. Consumers — verified by grep, handback's claim confirmed
- `grep -rn "audit/events" apps/web/src apps/web/e2e apps/pos/src` → **zero hits**. The handback's
  "no in-repo client calls the audit read endpoint" is **true**. Every `audit_events` hit in
  `apps/pos` is the client-side write outbox `queued_audit_events`
  (`apps/pos/src/lib/db/repositories/queuedAuditEventRepository.ts`), a different table and a
  different direction.
- `/documents?limit=`: `apps/web/src/features/dashboard/Dashboard.tsx:140` (`limit=5`, clamp is a
  no-op; reads only `.data`, never `meta` — `:141`) and the five campaign sites (N4). No other
  in-repo caller of the legacy branch.
- `getEventsInRange` / `countEventsByType` **`CarbonInterface` widening breaks no caller** — the only
  production caller is `AnomalyDetectionService.php:87` (`countEventsByType`), which passes
  `Illuminate\Support\Carbon` instances; `Illuminate\Support\Carbon extends Carbon\Carbon implements CarbonInterface`,
  so widening a parameter type is contravariant and safe. Test caller `AuditTrailTest.php:240` also
  still compiles (PHPStan clean, tests green).

### 6. Legacy `limit` clamp — correct and the tests are falsifying
`DocumentController.php:110-124`: `$cappedLimit = max(1, min((int) $limit, 100))` (`:111`),
`->take($cappedLimit)` (`:112`), `meta.total => $documents->count()` (`:118`) and
`meta.per_page => $cappedLimit` (`:119`). The three tests are executable and each fails on the base
implementation for a *different* reason, which is what makes them non-redundant:
`ListDocumentsTest.php:770-779` (`limit=0` → base `take(0)` returns 0 rows, expects 1),
`:781-790` (`limit=-5` → base `take(-5)` returns **all** rows, expects 1),
`:792-800` (`limit=5000` over 101 seeded docs → base returns 101, expects exactly 100). The
handback's Red B output matches those three mechanisms exactly. Fixture
`seedLegacyLimitDocuments()` at `:753-767`.

### 7. PHPStan deviation (D2) — the right fix, confirmed
`AuditService.php:189-192` types the return as the **concrete**
`\Illuminate\Pagination\LengthAwarePaginator`. Confirmed correct at source:
`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:1116` declares
`@return \Illuminate\Pagination\LengthAwarePaginator` (concrete) and returns `$this->paginator(...)`.
The contract `Illuminate\Contracts\Pagination\LengthAwarePaginator` genuinely has no
`getCollection()`, which the controller uses at `AuditController.php:74`. So the concrete type is
*truthful about the runtime value*, not a workaround. **No `@phpstan-ignore`, no baseline entry, no
cast** — verified by grep over the diff. This is the correct resolution; the plan's Step 2 import
(`docs/…/2026-09-03-request-hygiene-phase-a.md:1305`) is the thing that is wrong and should be
corrected in the same plan rev as B1.

### 8. Locales — correct, and the partial `ar` file is safe
- `audit_date_range_max` present with `:max` in all three:
  `lang/en/validation.php:183`, `lang/fr/validation.php:183`, `lang/ar/validation.php:6`.
- `lang/ar/validation.php` is a valid strict-types file (`<?php` + `declare(strict_types=1);` +
  a single-key `return`), 7 lines, and PHPStan/Pint pass on it.
- **Does a one-key `ar/validation.php` clobber Arabic fallback for every other rule? No.** Laravel's
  `Translator::get()` iterates `localeArray($locale)` = `[$locale, $fallback]` and returns the first
  locale in which the **individual key** resolves — fallback is per-key, not per-file. Verified
  empirically in the worktree with `app()->setLocale('ar')`:
  ```
  validation.required            => The x field is required.
  validation.integer             => The x field must be an integer.
  validation.max.numeric         => The x field must not be greater than 92.
  validation.date_format         => The x field must match the format Y-m-d.
  validation.audit_date_range_max => لا يجوز أن تتجاوز الفترة 92 يومًا.
  fallback=en
  ```
  Other Arabic messages are unaffected; `config/app.php:83` sets `fallback_locale` to `en`.
  `lang/ar/` already held `documents.php` and `treasury.php`, so the locale directory was live before
  this lane. No locale-parity ratchet test covers `validation.php`
  (only `tests/Unit/Lang/DocumentsProformaLangParityTest.php`, a different namespace) — nothing to break.

### 9. Pre-existing PG reds — confirmed pre-existing by code, without re-running the main checkout
`tests/Traits/ProvisionsTenantDatabases.php:71` issues
`select sql from sqlite_master where sql is not null …` — a hardcoded SQLite catalogue query with no
driver branch, inside `provisionTenantDatabaseWithSchema()` (`:66-75`). Under `phpunit-pgsql.xml`
that is `SQLSTATE[42P01] relation "sqlite_master" does not exist` for **every** test using the trait,
independent of any lane change. `git diff b133caf21..4cbb4d48e -- apps/api/tests/Traits/ProvisionsTenantDatabases.php`
is **empty** — the file is untouched by this lane, and neither affected test file
(`DetectFraudPatternsDriftDbPerTenantTest`, `VerifyFiscalChainGenesisDocumentTest`) is in the diff.
Pre-existence accepted. The handback's recommendation of a separate ticket ("this trait makes the
whole db-per-tenant test family PG-incapable") is endorsed.

---

## Commands and outputs

### SQLite, by path
```
$ cd .worktrees/rh-t4/apps/api && php artisan test \
    tests/Feature/Compliance/AuditTrailTest.php \
    tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php \
    tests/Feature/Document/ListDocumentsTest.php
  …
  ✓ legacy limit zero clamps to one
  ✓ legacy limit negative five clamps to one
  ✓ legacy limit 5000 returns exactly 100 of 101 documents
  Tests:    49 passed (182 assertions)
  Duration: 38.16s
```

### PostgreSQL, by path (lane's private container `autoerp_pg_t4` on 127.0.0.1:5454)
```
$ DB_HOST=127.0.0.1 DB_PORT=5454 DB_DATABASE=autoerp_test_t4 DB_CENTRAL_DATABASE=autoerp_test_t4 \
    php artisan test -c phpunit-pgsql.xml \
    tests/Feature/Compliance/AuditTrailTest.php \
    tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php \
    tests/Feature/Document/ListDocumentsTest.php
  Tests:    49 passed (182 assertions)
  Duration: 84.53s
```
(`docker ps` confirms `autoerp_pg_t4 127.0.0.1:5454->5432/tcp` running; left running as instructed.
The shared `autoerp_postgres` on 5433 was not touched.)

### PHPStan level 8
```
$ ./vendor/bin/phpstan analyse --memory-limit=2G --no-progress \
    app/Modules/Compliance/Presentation/Requests/ListAuditEventsRequest.php \
    app/Modules/Compliance/Presentation/Controllers/AuditController.php \
    app/Modules/Compliance/Services/AuditService.php \
    app/Modules/Document/Presentation/Controllers/DocumentController.php \
    lang/en/validation.php lang/fr/validation.php lang/ar/validation.php
Note: Using configuration file …/apps/api/phpstan.neon.
 [OK] No errors
```

### Pint
```
$ ./vendor/bin/pint --test <the 7 files above + the 3 touched test files>
{"result":"pass"}
```

### Probes written for this gate (read-only, tinker)
```
$ CACHE_STORE=array php artisan tinker --execute='… Carbon boundary …'
diff 2026-01-01 -> 2026-04-03 : float(92)
diff 2026-01-01 -> 2026-04-04 : float(93)
diff same day                 : float(0)
parse empty string            : string(25) "2026-09-04T00:06:35+00:00"

$ CACHE_STORE=array php artisan tinker --execute='… Validator::make with the shipped rules …'
A empty all           => PASSES validated={"event_type":"","from":"","to":""}
B empty agg pair      => PASSES validated={"aggregate_type":"","aggregate_id":""}
C from empty to set   => FAILS {"from":["…required when to is present."],"to":["…after or equal to from."]}
D event_type empty    => PASSES validated={"event_type":""}
E per_page empty      => PASSES validated={"per_page":""}
F include empty       => PASSES validated={"include":""}

$ CACHE_STORE=array php artisan tinker --execute='app()->setLocale("ar"); … '
validation.required             => The x field is required.
validation.audit_date_range_max => لا يجوز أن تتجاوز الفترة 92 يومًا.
fallback=en
```

No file in the worktree was modified by this review (this report is the only addition).

---

## Gate-scope ruling on `fiscal-pos-reviewer`

**Not required — the handback's argument checks out.** `event_hash` is emitted unconditionally
(`AuditController.php:81`), hash computation lives in `Compliance/Domain/AuditEvent.php` which is not
in the diff, `git diff b133caf21..4cbb4d48e -- apps/api/app` contains no write verb, and
`AuditService::record()` is byte-identical. `include=payload` is a serialization gate only. The
plan's trigger ("only if audit-chain behavior changes") is not met.

---

## What round 2 must show

1. B1 fixed in `ListAuditEventsRequest.php` and `AuditController.php`, with the plan's Step 1/Step 3
   snippets amended to match (a plan rev), plus the two tests named in B1.
2. A stated ruling on which semantics `?from=&to=` gets — ignored (200, unfiltered) or rejected (422)
   — and a test that pins it.
3. N1's boundary test (92 → 200, 93 → 422) and the plan wording fixed.
4. Re-run of the same three files on both drivers, PHPStan and Pint.
5. N4 and N5 filed as tickets (no code needed in this lane).
