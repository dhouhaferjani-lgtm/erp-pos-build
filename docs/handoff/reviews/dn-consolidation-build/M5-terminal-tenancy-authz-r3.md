# TERMINAL re-review register — M5 round 3, lens: **tenancy-authz**

**Wave:** `dn-consolidation-build` · **Branch:** `codex/dn-consolidation-2026-08-12`
**Delta reviewed:** `dd97e2806..a04b23dc5` (3 commits, 15 files) · **Tip:** `a04b23dc5`
**Round 1:** `M5-terminal-tenancy-authz.md` (CHANGES-REQUIRED) · **Round 2:**
`M5-terminal-tenancy-authz-r2.md` (CHANGES-REQUIRED — `F-R2-1` Critical, `F-R2-2`/`F-R2-3`/`F-R2-4`
Minor).
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/dn-consolidation` (read-only;
this register is the only file I commit).

Everything below was re-derived at `a04b23dc5` by reading the files and by executing code on a
dedicated PostgreSQL scratch database `autoerp_dn_tz3` (the shared `autoerp_test` was never
touched). No probe class was written into the repo; `git status` is clean apart from this file.

---

## VERDICT SUMMARY

**CHANGES-REQUIRED**, on **two Important** findings — and I want to be precise about what that does
and does not mean.

**All four of my round-2 items are genuinely CLOSED and verified by run**, including the Critical.
The behaviour on this branch is strictly better than at `b6091a74a`. What blocks is not a
regression: it is that the round's **other** completion — `R2-4`, the migration's `invoiced_at`
grammar — is **again narrowed rather than closed**, and it ships a shipped-code claim that I can
falsify in one command (`F-R3-1`), while the CI gate this same round added to stop exactly that
pattern **does not cover it** (`F-R3-2`). Both land on `tenants:migrate`, which this repository
auto-runs per tenant on every push to `origin/dev`.

---

## A. What I executed

Scratch database:

```bash
PGPASSWORD=… psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres \
  -c "DROP DATABASE IF EXISTS autoerp_dn_tz3;" -c "CREATE DATABASE autoerp_dn_tz3 OWNER autoerp;"
```

| Run (all `DB_DATABASE=autoerp_dn_tz3 DB_CENTRAL_DATABASE=autoerp_dn_tz3 CACHE_STORE=array … -c phpunit-pgsql.xml`) | Result |
|---|---|
| `DeliveryNoteBillingProjectionTest` + `DeliveryNoteBillingMarkerMigrationTest` | **15 passed / 180 assertions** |
| `SalesOrderBillingClaimTest` + `DeliveryNoteConsolidationTest` | **34 passed / 306 assertions** |
| `DeliveryNoteConsolidationAccessControlTest` + `DeliveryNoteToBillQueueTest` + `DeliveryNoteBillingClaimServiceTest` | **22 passed / 181 assertions** |
| Carbon/SQLite/PostgreSQL grammar probe (scratchpad `php -r`, `psql`) | falsifies the `R2-4` "by construction" claim → `F-R3-1`, and the CI gate's reach → `F-R3-2` |

Every touched backend file passes on PostgreSQL at tip. **71 tests / 667 assertions, 0 failures.**

---

## B. My four round-2 items — verified one by one

### B.1 `F-R2-1` (Critical) — **CLOSED. Verified by run, on both keys and both surfaces.**

`DeliveryNoteBillingState.php:50-55` now guards **both** casts, exactly as asked:

```php
$invoicedAt = isset($payload['invoiced_at']) && is_string($payload['invoiced_at'])
    ? $payload['invoiced_at'] : null;                                    // :50-52
$invoiceId = isset($payload['invoice_id']) && is_string($payload['invoice_id'])
    ? $payload['invoice_id'] : null;                                     // :53-55
```

The extended coverage is real and it is the coverage I specified:

- `DeliveryNoteBillingProjectionTest.php:266-271` adds `'DN-DIRTY-OBJECT' => ['nested' => 'object']`
  and `'DN-DIRTY-LIST' => ['a','b']` to the existing three `invoice_id` shapes; the test asserts the
  **list** (`:283-296`, `assertOk` + full id set + `invoiced_at`/`invoiced_via` preserved) and the
  **detail** for every shape (`:297-306`).
- A **new** test, `test_a_non_string_payload_invoiced_at_reads_as_absent_instead_of_500ing_the_list_and_detail_surfaces`
  (`:330-380`), covers the second ingress with `['not' => 'a timestamp']`, a **list** shape and a
  bool, again asserting list + detail 200.

So object AND list under BOTH keys, list AND detail, all `assertOk` — **10 passed / 126 assertions
on PostgreSQL** in my run. This is the surface that was 500ing at `b6091a74a`.

**The layered-contract comment is HONEST.** I checked it clause by clause rather than reading it:

| Claim | Verified where |
|---|---|
| "This DTO owns the `is_string` half for both keys" | `DeliveryNoteBillingState.php:50-55` — true, and `invoiced_via` at `:56` was already guarded |
| "the format half lives at the consumer (`DocumentData.php:198` runs `Str::isUuid()`)" | `DocumentData.php:207-212` at tip — `if ($billingState->invoice_id !== null && Str::isUuid(...))` before the `documents.id` lookup. Cited line number drifted by the comment's own insertion (now `:208`), which is cosmetic |
| "the migration guards `is_string()` FIRST" | `…create_delivery_note_billing_marks_table.php:192` (`safeInvoicedAt`) and `:219` (`safeInvoiceId`) — `! is_string(…) \|\| trim(…) === ''` precedes the format check in both |
| "NEITHER layer reproduces it alone" | correct, and it is the right factoring: the DTO cannot run `Str::isUuid` (it is not a uuid field for every caller) and `DocumentData` cannot prevent a cast that happens upstream of it |

One inaccuracy in that comment — see `F-R3-3`.

**The three claim corrections + the OI-12 rewording are all present and all honest:**

1. `DocumentData.php:193-205` — "*Mirror that contract here*" is gone; replaced by "This is the
   FORMAT half of the migration's contract, not the whole of it."
2. `HANDBACK-…md:957-969` — the paragraph is rewritten and carries an explicit
   `> **CORRECTED in fix round 2.**` block naming what the old wording got wrong.
3. `progress.yaml:222` — the narrative entry now says "CORRECTED IN FIX ROUND 2 — this entry
   originally said 'mirroring the migration's safeInvoiceId semantics'. It did not mirror them".
4. **OI-12 rewording** (`progress.yaml:240`) is the strongest of the four. It does not merely
   restore my round-1 escalation; it states what is actually guaranteed: the counter is a *union of
   three rejection reasons folded into one number*, so it "tells a promoter HOW MANY delivery notes
   carry an unusable invoice attribution and never WHICH SHAPE"; the guarantee is "about the CODE,
   not the count"; and — correctly — "the r1 register's escalation to a blocking pre-promotion check
   was correct WHILE the cast was unguarded and is **DISCHARGED** by R2-1/F-R2-1, **not waived**."
   I agree with that disposition and I am not re-raising the OI-12 block. `unparseable_invoice_id`
   is a survey signal again.

### B.2 `F-R2-2` (Minor) — **CLOSED, verified.**

`DeliveryNoteController.php:301-303` now throws
`__('documents.to_bill_queue.no_active_location_in_scope')`. Both keys exist —
`lang/en/documents.php:50-52` and `lang/fr/documents.php:25-27` — and the fr string is a real
translation, not a copy. `DeliveryNoteToBillQueueTest::test_a_location_restricted_user_without_an_active_location_is_refused_rather_than_served_the_whole_company`
asserts the envelope (`error.code = VALIDATION_ERROR`) rather than the literal, so the translation
cannot silently break the test; it passes on PostgreSQL.

**The ar-fallback note is recorded** and it is accurate: `apps/api/lang/ar/` contains only
`treasury.php` (verified by `ls`), `config/app.php:83` sets `fallback_locale` to `en`, so Arabic
falls back to English for the whole `documents` namespace — pre-existing, not introduced. Recorded
at `progress.yaml:236` and `HANDBACK:1166-1169`. The deliberate **non**-sweep of the sibling English
literal at `:274` is also recorded rather than silently fixed, which is the right call under rule 4.

### B.3 `F-R2-3` (Minor) — **CLOSED, and the test is red-proof by construction, not by claim.**

`SalesOrderToDeliveryNoteConverter.php:194-210` assigns `$locked = …->first()` and throws
`RuntimeException('Lock order violation: the sales-order header %s could not be locked …')` on null.

I did not need to take the executor's in-place-revert red-proof on trust, and I did not:
`SalesOrderBillingClaimTest::test_the_order_header_lock_refuses_to_proceed_when_its_predicate_matches_nothing`
(`:767-785`) calls the private method directly through `ReflectionMethod` with a replicated order
carrying an unmatchable id, and asserts **the specific exception**:

```php
$this->expectException(RuntimeException::class);
$this->expectExceptionMessage('Lock order violation: the sales-order header '.$missingId);
```

Remove the guard and the method returns `void`, so PHPUnit fails with "Failed asserting that
exception … is thrown" — the assertion **cannot** pass without the fix, on any engine. That is a
stronger property than the revert evidence claimed for it. Passes on PostgreSQL.

The related `R2-6` test nit is closed too: `SalesOrderBillingClaimTest:683-696` replaces
`assertGreaterThanOrEqual(500, …)` with `assertSame(500, …)` **plus**
`assertInstanceOf(DeliveryNoteClaimNotFinalisedException::class, $response->exception)` **plus** the
exact message, so the integrity-alarm test can no longer pass on an unrelated 500.

### B.4 `F-R2-4` (Minor) — **CLOSED, verified.**

`ToBillPage.tsx:426-439`. The comment now states the entitlement dependency explicitly ("a user with
`can_view_all_locations` serves the whole company, while a location-restricted user gets a 422 …
so for them this branch never renders at all, the QueryError below does") and closes by naming the
old claim as wrong. Rendering logic untouched, which is correct — it was already right.

---

## C. Findings (delta only)

### `F-R3-1` — **IMPORTANT** — `apps/api/database/migrations/tenant/2026_08_18_000002_create_delivery_note_billing_marks_table.php:180-184, :206` — `R2-4` narrows the abort path a second time; the shipped "by construction" claim is false, and a Carbon-parseable zero-date still aborts `tenants:migrate` for that tenant

The new code and its claim:

```php
 * Inserting `$parsed->toIso8601String()` collapses the two grammars into Carbon's alone:
 * every value this method accepts is now, by construction, a value PostgreSQL accepts,   // :180
 …
 * A well-formed absolute timestamp keeps its instant and its offset (ISO 8601 is
 * round-trip exact for the `timestamptz` column), so nothing about the existing
 * backfill's output changes.                                                              // :182-184
 …
 return $parsed->toIso8601String();                                                        // :206
```

**Falsified in one command.** Carbon accepts values whose ISO-8601 rendering has a year `<= 0`, and
PostgreSQL rejects every one of them:

```
'0000-00-00'  -> Carbon OK -> toIso8601String() = '-0001-11-30T00:00:00+00:00'
'0000-01-01'  -> Carbon OK -> toIso8601String() = '0000-01-01T00:00:00+00:00'
'-0001-01-01' -> Carbon OK -> toIso8601String() = '-0001-01-01T00:00:00+00:00'
```

and against the real column type (`timestampTz('invoiced_at')`, NOT NULL, `:46`), by **INSERT**,
not by cast — on my scratch database:

```
psql> create table probe_ts (v timestamptz not null);
psql> insert into probe_ts (v) values ('-0001-11-30T00:00:00+00:00');
ERROR:  22007: invalid input syntax for type timestamp with time zone: "-0001-11-30T00:00:00+00:00"
LOCATION:  DateTimeParseError, datetime.c:4026
psql> insert into probe_ts (v) values ('0000-01-01T00:00:00+00:00');
ERROR:  date/time field value out of range: "0000-01-01T00:00:00+00:00"          -- 22008
```

`22007` is the **exact SQLSTATE** the docblock at `:162` names as the failure F-6 exists to
remove. The residual set is small and well-defined — values Carbon resolves to year `<= 0` — but
`'0000-00-00'` is the canonical MySQL zero-date, i.e. the single most common junk date in any
legacy import, and this backfill exists precisely because `documents.payload` carries legacy junk
(the wave's own fixtures are `true`, `'yes'`, `['not' => 'a timestamp']`).

**Why this matters at my lens, not just treasury's.** Under database-per-tenant the backfill runs
inside `tenants:migrate`, once per tenant DB, and this repository auto-runs it on every push to
`origin/dev`. One such row in one tenant throws inside the chunk loop, the migration rolls back, and
that tenant ends the deploy **without** `delivery_note_billing_marks` — the billed-once guard table
this whole wave is built on — while every other tenant has it. That is a per-tenant divergence
created by a deploy, and it is the precise contract F-6 wrote down and this round re-asserted:
"*a dirty row is written … and COUNTED, never aborting the tenant loop*" (`progress.yaml:62`).

**Second, smaller falsification in the same docblock.** "*A well-formed absolute timestamp keeps its
instant*" is not true at sub-second precision: `toIso8601String()` drops microseconds —
`'2026-08-12T09:10:11.123456+05:30'` becomes `'2026-08-12T09:10:11+05:30'`. Harmless for an
attribution marker, but "nothing about the existing backfill's output changes" is stated absolutely
and is false for any stamp carrying a fractional second.

**Suggested fix (either is acceptable; the second is the smaller diff):**

```php
$parsed = CarbonImmutable::parse($invoicedAt);
if ($parsed->year < 1) {                       // PostgreSQL timestamptz has no year 0
    $counts['unparseable_invoiced_at']++;
    return null;
}
```

or wrap the per-row `insert()` in a `try { … } catch (QueryException) { $counts[…]++; }` so the
contract "counted and skipped, never fatal" is enforced by the **insert** rather than by a
predicate that has now been wrong twice. Add `'0000-00-00'` as a fixture. Then correct `:180` to
state the residual set instead of "by construction", and qualify `:182-184` for sub-second values.

### `F-R3-2` — **IMPORTANT** — `.github/workflows/ci.yml:635-655` — the CI gate this round added to stop "unfalsifiable on SQLite" does not cover `R2-4`, whose only test is unfalsifiable on SQLite *and* runs in no lane at all on the `dev` path

The new gate is genuinely good and its stated rationale is exactly right:

> "the guards need a gate that can actually go red, not just a manual run recorded in a handback"
> (`ci.yml:648-650`)

It adds `DeliveryNoteBillingProjectionTest.php` and `DeliveryNoteBillingClaimServiceTest.php` to the
PostgreSQL lane (`:653-655`). But `DeliveryNoteBillingMarkerMigrationTest.php` — the **only** test
that exercises the M1C backfill, and the file this same commit modified to cover `R2-4` — is in
**no** PostgreSQL lane. `grep -n "DeliveryNote" .github/workflows/ci.yml` returns exactly four hits,
all inside that one step.

**Its new assertion cannot fail on SQLite.** The added check (`DeliveryNoteBillingMarkerMigrationTest.php:204-215`)
reads the raw row via `DB::table(…)->first()` (`:403-408`) and asserts a 24-hour delta through
Carbon. Measured, both halves:

```
CarbonImmutable::parse('now')->diffInHours(CarbonImmutable::parse('+1 day'), absolute: true) = 24.0000
sqlite> create table m (id integer primary key, invoiced_at timestamp not null);
sqlite> insert into m (invoiced_at) values ('+1 day');   -- ACCEPTED, stored as '+1 day'
```

So **pre-fix on SQLite** the raw strings `'now'` and `'+1 day'` are stored verbatim, Carbon parses
them back to a 24.0000-hour delta, and the assertion passes. **Post-fix** it also passes. The test
discriminates the fix only on PostgreSQL — the one engine it is never run on.

**And on the branch that actually deploys, it runs nowhere.** `backend-test` (the SQLite suite) is
gated `if: … github.base_ref == 'main' || (push && ref == refs/heads/main) …` (`ci.yml:185`) — it
does **not** run on PR→dev. `backend-test-pgsql` does (`ci.yml:342`). Net effect on the `dev` path,
which is the auto-deploying one: the M1C backfill migration has **zero** CI coverage in either lane.

**Suggested fix:** one line — add
`tests/Feature/Document/DeliveryNoteBillingMarkerMigrationTest.php` to the same
`php artisan test -c phpunit-pgsql.xml` invocation at `ci.yml:652-655`. With `F-R3-1`'s fixture
added, that step then goes red on the exact shape that would abort a tenant's migration.

### `F-R3-3` — **MINOR** — `apps/api/app/Modules/Document/Application/DTOs/DeliveryNoteBillingState.php:39-42` — the new contract comment overstates the backfill for the `invoice_id` half

```php
 * A non-string value is therefore treated as ABSENT, never cast:
 * a dirty `invoice_id` resolves to no invoicing document, and a dirty `invoiced_at`
 * reads as not-yet-billed — exactly what the backfill records (it writes no marker
 * row for either shape) and never a 500.
```

"*writes no marker row for either shape*" is true for `invoiced_at` — `safeInvoicedAt()` returns
null and the loop `continue`s (migration `:85-88`) — but **false for `invoice_id`**. When
`safeInvoiceId()` returns null the loop does not skip: it downgrades the lane and **inserts the
marker anyway** with `invoice_id = NULL`:

```php
if ($invoiceId === null) {
    $invoicedVia = DeliveryNoteBillingLane::LegacyUnknown->value;   // :94
}
DB::table('delivery_note_billing_marks')->insert([…]);              // :97
$counts['rows_written']++;                                          // :104
```

Confirmed by the wave's own numbers: the migration test's `unparseable_invoice_id: 4` rows are
inside `rows_written`, and the round-2 count change was `13 -> 15` — exactly the two new
`invoiced_at` fixtures, none for `invoice_id`.

The **behaviour** is right (and a marker with a NULL `invoice_id` is what blocks re-billing); only
the comment is wrong, and it is wrong in the direction that matters — a reader following it would
conclude a dirty-`invoice_id` DN has no marker and is therefore re-billable. Same class as the
"mirrors the migration" overstatement this round exists to correct. One clause to fix.

**Second clause, same class — the in-code line citations are stale.** The new comments cite
`safeInvoicedAt():176` and `safeInvoiceId():204` (`DeliveryNoteBillingState.php:32-34`) and
`safeInvoiceId():204` again (`DocumentData.php:196`). At tip those guards live at migration `:192`
and `:219` — the citations were invalidated by the very docblock insertions that carry them. Purely
cosmetic, but this wave's records are being read by a promoter, and a reader who follows `:204`
lands in the middle of the `safeInvoicedAt` docblock.

---

## D. Residuals I looked at and am *not* raising as delta defects

- **`DocumentData.php:133-134` still holds two unguarded `(string)` casts on free-form payload** —
  `converted_to_order_id` and `converted_at` — plus `array_map('strval', …)` at `:144-145` and
  `:169-170`. A nested-array value in any of the four raises "Array to string conversion" in the
  **same method, on the same request path**, with the identical blast radius `F-R2-1` had.
  **Not a delta defect:** all four exist verbatim at the wave base —
  `git show 60df88a01:…/DocumentData.php` has them at `:128`, `:138-139`, `:163` — and they are
  shared by every document surface, not just delivery notes. Recorded so nobody reads `F-R2-1` as
  "the unguarded-cast class is closed in this file"; closing it belongs in a repo-wide lane.
- **The `invoiced=1` filter vs the DTO disagree for a dirty `invoiced_at`** — disclosed honestly in
  the new test (`DeliveryNoteBillingProjectionTest.php:371-380`). I checked the consequence that
  actually matters rather than accepting the disclosure: the to-bill **queue** is driven by
  `whereDeliveryNoteUninvoiced()` (`UninvoicedDeliveryNoteService.php:354`), which is
  `whereNull('payload->invoiced_at')` (`Document.php:653`), and on PostgreSQL a JSON object renders
  as a non-null `->>` text. So a dirty-stamp DN is **excluded** from the queue and **cannot** be
  re-consolidated. The divergence is cosmetic (the row shows on the billed side with a blank date),
  not a double-billing path. Verified, not assumed.
- **`partner_id` / `product_id` on `GET /delivery-notes` remain raw uuid bindings**
  (`Concerns/HandlesDocuments.php:155`, `:181`) — carried unchanged from my r2 register; the file is
  untouched by the whole branch.
- **`R2-5`** (`postgresPayloadObjectSql()` silently normalising a non-object payload base) is
  recorded-not-fixed with a rationale and a parent ticket (`progress.yaml:238`). Treasury's lens;
  the disposition is explicit rather than silent, which is what I would ask for.

---

## E. Re-verified GREEN at tip (re-run, not re-quoted)

| Charge | Result | Evidence |
|---|---|---|
| Role matrix + both-layer gating survive fix round 2 | **PASS** | `DeliveryNoteConsolidationAccessControlTest` 6 passed on PostgreSQL, incl. both module-off 403 arms and the foreign-tenant/foreign-company exclusion |
| Rule 12 middleware chain | **PASS** | `git diff --name-only dd97e2806..a04b23dc5` contains no `routes.php`, no middleware, no `verticals.php` |
| No module-gating or vertical-SoT drift | **PASS** | zero gating changes in the delta |
| Rule 19 / rule 20 / PG-uuid pitfalls on added lines | **PASS** | `git diff dd97e2806..a04b23dc5 -- apps/api/* apps/web/* \| grep '^+'` matches **zero** of `(float)`, `floatval`, `parseFloat`, `Number(`, `latestOfMany`, `ofMany(`, `app(`, bare `getScale()`, `onQueue`, `ShouldQueue`, `dispatch(` |
| Tenant/company discipline on the changed query | **PASS** | `lockOrderHeader` keeps `tenant_id` + `company_id` + `id` + `type` (`:197-200`) and now *asserts* the match |
| Test-scope hygiene of the two count fixes (`R2-3`) | **PASS** | `DeliveryNoteConsolidationTest.php:236-246` scopes `stored_events` by `event_properties->targetDocumentId` (`:238`) and `audit_events` by `company_id` (`:244`); both assert `3`, so a mis-compiled selector goes red rather than silently passing. Green on PostgreSQL |
| Test quality of the three added/changed tests | **PASS** | real models, `RefreshDatabase`, real HTTP; the lock test is red-proof by construction; the integrity-alarm test now pins the exception class *and* message |

---

## F. Disposition

| Id | Sev | Status | One-liner |
|---|---|---|---|
| `F-R2-1` | **Critical** | **CLOSED** | `is_string()` on both casts (`DeliveryNoteBillingState.php:50-55`); object + list under both keys, list + detail, all 200 on PostgreSQL; layered-contract comment honest; 3 claim corrections + OI-12 rewording all present and accurate |
| `F-R2-2` | Minor | **CLOSED** | `__('documents.to_bill_queue.no_active_location_in_scope')`; en `:50-52` + fr `:25-27`; ar-fallback recorded; `:274` sibling deliberately left recorded |
| `F-R2-3` | Minor | **CLOSED** | `lockOrderHeader` throws on a predicate miss; the test asserts the exact exception class **and** message and is red-proof by construction |
| `F-R2-4` | Minor | **CLOSED** | `ToBillPage.tsx:426-439` comment now entitlement-dependent and names the old claim as wrong |
| `F-R3-1` | **Important** | **NEW** | `R2-4` narrowed a second time: Carbon-parseable year `<= 0` values (`'0000-00-00'`) still abort `tenants:migrate` with SQLSTATE 22007/22008; the "by construction … a value PostgreSQL accepts" claim is false, as is "keeps its instant" at sub-second precision |
| `F-R3-2` | **Important** | **NEW** | the new PG CI gate omits `DeliveryNoteBillingMarkerMigrationTest`; its `R2-4` assertion is provably unfalsifiable on SQLite, and on PR→dev the file runs in no lane at all — the fix has no gate that can go red |
| `F-R3-3` | Minor | **NEW** | `DeliveryNoteBillingState.php:39-42` — "writes no marker row for either shape" is false for `invoice_id`; the backfill inserts a marker with `invoice_id = NULL` (migration `:94-104`) |

**What to fix before merge:** close `R2-4` against its own contract — reject a Carbon-parse that
resolves to year `< 1` (or catch the per-row `QueryException` and count it), add a `'0000-00-00'`
fixture, and correct the two absolute claims at migration `:180-184`; add
`DeliveryNoteBillingMarkerMigrationTest.php` to the PostgreSQL lane at `ci.yml:652-655` so that
fixture can actually go red; and fix the one false clause at `DeliveryNoteBillingState.php:41-42`.

**VERDICT: CHANGES-REQUIRED**
