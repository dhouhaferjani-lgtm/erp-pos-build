# Terminal Re-Review Register — M5, round 2, lens: treasury

**Wave:** `dn-consolidation-build` · **Branch:** `codex/dn-consolidation-2026-08-12`
**Round-1 register:** `M5-terminal-treasury.md` (CHANGES-REQUIRED — 1 Critical, 6 Important, 2 Minor)
**Delta re-reviewed:** `4df4b68ec..b6091a74a` (7 fix commits + the record commit)
**Tip at review:** `b6091a74a`, with the concurrent tenancy re-review register `635977760` on top.
**Scratch database:** `autoerp_dn_term_r2` (created for this round; `autoerp_test` never touched).

Every closure below was re-derived by running the code, not by reading the handback. The PostgreSQL
counts, the jsonb semantics, the `text = uuid` comparison, the lock order, the migration fixture and
the exception blast radius were each re-executed or re-greppped at this tip.

---

## VERDICT

**CHANGES-REQUIRED.** One Critical, two Important, three Minor.

Nine of my ten round-1 findings are genuinely closed, and the fixes are real: the object-safe merge,
the `::text` cast that finally lets the flagship both-representations assertion execute, the lock-order
conformance, the seventh migration counter, the 500-class integrity alarm and the `company_id`
predicate are all present, correct, and pinned by tests that **execute on PostgreSQL** (I ran them).

What blocks is that the **Critical is only half closed**. `F-1`'s guard was placed on the *lookup*
(`DocumentData.php:198`) but the fatal ingress is one layer earlier, in the *cast*
(`DeliveryNoteBillingState.php:20-21`). An object- or array-shaped `payload.invoice_id` — or
`payload.invoiced_at`, a second, entirely unguarded ingress — raises `ErrorException: Array to string
conversion` **before** `Str::isUuid()` is ever reached, and that is the same 500, on the same list
surface, with the same blast radius as the defect the fix round claims to have closed. The shape is
not hypothetical: this wave's own migration fixture enumerates `['not' => 'a UUID']` and
`['not' => 'a timestamp']` as field data.

(The concurrent tenancy-authz re-review reached the same conclusion independently as `F-R2-1`. I
verified it first-hand from the framework-booted runtime before adopting it; the proof below is mine.)

---

## A. What I executed at this tip

```bash
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "CREATE DATABASE autoerp_dn_term_r2 OWNER autoerp;"
cd apps/api && DB_HOST=127.0.0.1 DB_PORT=5433 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
  DB_DATABASE=autoerp_dn_term_r2 DB_CENTRAL_DATABASE=autoerp_dn_term_r2 CACHE_STORE=array \
  php artisan test -c phpunit-pgsql.xml <paths>
```

| Run | Result |
|---|---|
| `DeliveryNoteBillingClaimServiceTest` + `DeliveryNoteBillingProjectionTest` (the two round-1 PG reds) | **19 passed / 169 assertions** — both files now green on PostgreSQL |
| The wave's **13** backend test files (executor's split) | **125 passed / 857 assertions / 0 failures** — reproduces the handback exactly |
| `DeliveryNoteConsolidationConcurrencyTest` alone | 12 passed / 148 assertions (unchanged) |
| All **14** files **in one process** | **1 failed / 136 passed / 994 assertions** — see `R2-3` |
| `SalesOrderBillingClaimTest --filter="locks_the_order_header|surfaces_as_a_server_error"` | 2 passed — both new tests **execute**, neither is skipped, on PG |
| `DeliveryNoteConsolidationTest` alone | 17 passed / 179 assertions |

Plus direct PostgreSQL 16.10 probes for the jsonb and `text = uuid` semantics, a framework-booted
PHPUnit probe (run from the scratchpad, nothing written into the worktree) for the Critical, and a
`Carbon` vs `timestamptz` acceptance comparison for `F-6`.

---

## B. Round-1 findings — closure verified one by one

### F-1 — **NOT CLOSED** (the lookup half is closed; the cast half is not). See `R2-1`.

The lookup guard itself is correct and present: `DocumentData.php:198`
`if ($billingState->invoice_id !== null && Str::isUuid($billingState->invoice_id))`, and it mirrors
the migration's `safeInvoiceId()` semantics — an unparseable id yields **no** invoicing document while
`invoiced_at` and the lane survive. `DeliveryNoteBillingProjectionTest.php:257` pins that contract on
**both** the list and the detail surface over three dirty shapes, and it is **PG-green** (I ran it).
The FE consequence is also as claimed: `DeliveryNoteBillingStatus.tsx:49` renders the badge alone and
only links when **both** id and number are non-null, so no dead link was introduced.

The problem is what the fixtures do *not* cover — scalars only (`'INV-2024-001'`, `' '`, `123`). See
`R2-1` for the object/array shapes, which never reach line 198.

### F-3 — **CLOSED**, and the adversarial questions answered at the database.

`DeliveryNoteBillingClaimService.php:176-179` returns
`CASE WHEN jsonb_typeof(payload) = 'object' THEN payload ELSE '{}'::jsonb END`, used by **both**
merges (`:149` finalise, `:156` reserve). Probed on PostgreSQL 16.10:

```
typeof(NULL) is null:  t        -- jsonb_typeof(NULL) is SQL NULL, so `NULL = 'object'` is NULL,
NULL payload merge:    {"invoiced_at": "x"}   -- not TRUE => ELSE branch. The CASE SUBSUMES COALESCE.
array payload merge:   {"invoiced_at": "x"}
scalar payload merge:  {"invoiced_at": "x"}
json-null merge:       {"invoiced_at": "x"}
old || on array:       [{"invoiced_at": "x"}] -- the round-1 defect, reproduced
```

So the SQL-NULL branch is safe (it degrades to `'{}'`, exactly what `COALESCE` did) and the claim
invariant is preserved: after reserve, `payload->>'invoiced_at'` is non-NULL, so finalise's guard
(`:131`) matches and the note is billable. Nothing is *fabricated* for the projection either — the
projection reads string keys, and `'[1,2]'::jsonb ->> 'invoiced_at'` is NULL, i.e. an array payload
never carried a readable key in the first place. Pinned by
`DeliveryNoteBillingClaimServiceTest.php:152`, which forces `payload = '[]'` **at the database**
rather than through the Eloquent cast — the right way to write that fixture. **PG-green.**
Residual (`R2-5`): the normalisation silently *destroys* a non-object payload and the legacy survey
counter round 1 asked for was not added.

### F-4 — **CLOSED, and the assertion is load-bearing.**

`DeliveryNoteBillingClaimServiceTest.php:389-408` now builds `mark.invoice_id::text` on PostgreSQL.
I re-ran the flagship test (`:128` calls `assertMatchingFinalisedPairs`) — green — and then mutated
one side of the predicate directly on the scratch database to prove it discriminates:

```
matching pair                                   => 1     (assertSame(1, 1) passes)
payload points at a DIFFERENT invoice id        => 0     (assertSame(1, 0) FAILS)
payload key absent                              => 0     (assertSame(1, 0) FAILS)
pre-fix uncast form                             => ERROR: operator does not exist: text = uuid
```

The both-representations agreement is therefore genuinely enforced on the production engine for the
first time.

### F-5 — **CLOSED for the cited inversion, and the engine gate is honest.**

`SalesOrderToDeliveryNoteConverter::lockOrderHeader()` (`:182-191`) is called first inside **both**
delivery transactions — `performFullDelivery` `:199` and `performPartialDelivery` `:258` — before
`createTargetDocument` reaches `DocumentNumberingService` (L2). The invoice lane still takes L1 first
(`SalesOrderToInvoiceConverter.php:171-178`), then L2 on the auto-create branch (`:204`), L3
(`lockCompleteDeliveryNoteSet`, `:644-647`), L4 (claim), L5 (invoice numbering inside the closure), so
`L1 < L2 < L3 < L4 < L5` holds for the writer set I enumerated in round 1 — the two sales-order lanes,
the consolidation lane, and the `pre_post_delivery` lane (which takes no invoice number:
`DocumentPostingService` is absent from the repository-wide `generateNumber(` caller list, re-verified
here). The back edge is gone.

The gate is **not** a skip-everything: `markTestSkipped` sits inside the single test method
(`SalesOrderBillingClaimTest.php:706`), and I ran that test on PostgreSQL — it **executed** and passed.
It is also load-bearing: `queryPosition()` (`:851-865`) calls `$this->fail()` when the fragment is not
found, so before the fix — when no `FOR UPDATE` on the order existed at all — it would have failed
rather than passed vacuously.
Minor residual in `R2-6` (`->first()` silently locks nothing if the row does not match).

### F-6 — **CLOSED for every dirty shape the wave enumerates; narrowly incomplete (`R2-4`).**

`safeInvoicedAt()` (migration `:174-195`) is called at `:85`, **before** `billingLane()` and
`safeInvoiceId()`, and returns `null` for a non-string or blank value and for anything
`CarbonImmutable::parse()` rejects, incrementing `unparseable_invoiced_at` (`:178`, `:186`; declared at
`:302`). The `catch (Throwable)` is **not** a silent swallow — it counts, and the count is logged
per tenant. The fixture proves counted-and-skipped rather than aborting:
`DeliveryNoteBillingMarkerMigrationTest.php` adds `true`, `'yes'`, `'   '` and `['not' => 'a timestamp']`,
asserts no marker row for any of them, asserts `'unparseable_invoiced_at' => 4` in the log (`:185`) and
`=> 0` on the empty invocation (`:312`) — and, decisively, the assertions *after* the migration run all
execute, i.e. `up()` completed. **PG-green.**

### F-7 — **CLOSED; the rethrow preserves context and no 422 path regressed.**

Both exceptions now extend `RuntimeException` (`DeliveryNoteClaimNotFinalisedException.php:24`,
`DeliveryNoteClaimRequiresTransactionException.php:15`). I traced every lane that can raise them:

- `convertOrderToInvoice` — `DocumentConversionController.php:84` `throw $e;`. A bare rethrow of the
  same instance: message, `previous` and the original stack trace are all preserved; nothing is
  wrapped or flattened. It is placed **first** in the catch chain but catches only those two concrete
  classes, so it cannot shadow `DeliveryNoteBatchValidationException` (`:92`),
  `DeliveryNoteAlreadyClaimedException` (`:107`), the `\DomainException` arm (`:117`) or the catch-all
  (`:124`) — the routine 422 paths are byte-identical and still green in the PG runs.
- `createInvoiceFromDeliveryNotes` (consolidation) — last arm is `catch (\DomainException)` (`:352`),
  so the alarm now escapes. Handback claim verified.
- `InvoiceController` `pre_post_delivery` (`:1017` claim) — last arm is `catch (\DomainException)`
  (`:1063`). Escapes.
- `DeliveryNoteBillingConcurrencyRetrier::isRetryable()` only matches `PDOException` `40P01`/`40001`,
  so the alarm is **not** retried or converted into an attributed refusal.

Blast radius of the re-parenting: I grepped every `catch (\RuntimeException` / `catch (RuntimeException`
in `app/`. None is on a lane that can see these two (`DocumentConversionController.php:423` is the
purchase-order goods-receipt lane; `DeliveryNoteController.php:579` is DN confirm; the rest are
Accounting/POS/Fiscal). `bootstrap/app.php` registers no generic `RuntimeException` render, so it
surfaces as a 500 and is reported through the Sentry `reportable` at `:211`.
Pinned by `SalesOrderBillingClaimTest.php:650` (**PG-green**, executes); weak assertion noted in `R2-6`.

### F-10 — **CLOSED.** `DeliveryNoteBillingClaimService.php:119` `->where('company_id', $set->companyId)`
now mirrors the payload half (`:130`). No behavioural risk: markers are only ever created by
`reserve()` (already company-predicated at `:67`) or by the M1C backfill with the delivery note's own
`company_id`, and a backfilled note can never reach `finalise()` because its non-null
`payload.invoiced_at` loses the reserve CAS first.

### F-2 — **CLOSED as written** (manual PG re-run + recorded delta), but see `R2-2`: the durable half
is missing. The finding asked for the wave's own files to be re-run under `phpunit-pgsql.xml` and the
delta recorded. That was done and the numbers reproduce. CI, however, still runs those files on
SQLite only.

### F-8, F-9 — **properly recorded, citations accurate.** The handback's "Recorded, NOT fixed" section
carries both with their file:line, the do-not-block/inherited status of F-8, and the promotion gate
("gates any future exposure of the year-end adjustment"). I spot-checked the citations at this tip:
`UninvoicedDeliveryNoteService::baseUninvoicedQuery` is at `:385`, `calculateUninvoicedTotals` at
`:341`, `generateYearEndAdjustment` at `:487`, and `ReportsController.php:160` wires only
`generateYearEndReport` — the adjustment still has no route. The YAML `findings` block carries the
same text. Accepted as recorded.

### Money discipline (rule 19) across the delta — **CLEAN.** `git diff 4df4b68ec..b6091a74a` over
`apps/api/app`, `apps/api/database` and `apps/web` contains no added `(float)`, `number_format`,
`round(`, `parseFloat`, `Number(` or `toFixed`. No money arithmetic was added; no bare no-arg
`getScale()` appears anywhere in the delta.

---

## C. Findings (delta and closure gaps only)

### R2-1 — **[CRITICAL]** `apps/api/app/Modules/Document/Application/DTOs/DeliveryNoteBillingState.php:20-21` — the F-1 guard is one layer too late; an object-shaped `invoice_id` **or** `invoiced_at` still 500s the delivery-note list

```php
$invoicedAt = isset($payload['invoiced_at']) ? (string) $payload['invoiced_at'] : null;   // :20
$invoiceId  = isset($payload['invoice_id'])  ? (string) $payload['invoice_id']  : null;   // :21
$invoicedVia = isset($payload['invoiced_via']) && is_string($payload['invoiced_via'])     // :22  ← guarded
```

`documents.payload` is cast `'array'` (`Document.php:195`), so a nested JSON object or array decodes to
a PHP array and `(string) $array` raises `E_WARNING`, which Laravel's `HandleExceptions` converts into
an `ErrorException`. Proven first-hand at this tip, framework booted, from a scratchpad PHPUnit file
(nothing written into the worktree):

```
[PROBE invoice_id=object]  ErrorException :: Array to string conversion @ DeliveryNoteBillingState.php:21
[PROBE invoiced_at=object] ErrorException :: Array to string conversion @ DeliveryNoteBillingState.php:20
```

`fromPayload()` is called at `DocumentData.php:141`, i.e. **before** the `Str::isUuid()` guard at
`:198`, so the guard is unreachable for these shapes. `DeliveryNoteController::index` maps every row
through `DocumentData::fromModel`, so **one** dirty legacy row takes out the entire delivery-note list
for every user in that tenant — the exact blast radius of the round-1 Critical, unchanged.

Three things make this a defect and not a residual:
1. **The wave's own migration enumerates these shapes as field data.** `MarkerMigrationTest` pins
   `['not' => 'a UUID']` for `invoice_id`, and this very fix round *added*
   `'invoiced_at' => ['not' => 'a timestamp']` (F-6 fixture). The backfill handles both correctly
   (`is_string` guards in `safeInvoiceId()` / `safeInvoicedAt():176`); the projection does not.
2. **`invoiced_at` is a second, independent ingress** that no part of the F-1 fix touches. It has the
   same blast radius and no guard at all.
3. **The pattern was known and applied to one of three fields**: `invoiced_via` at `:22` *is*
   `is_string`-guarded. Two lines away.

*Fix:* `is_string()`-guard (or `is_scalar()`-then-cast) both `:20` and `:21`, exactly as `:22` already
does, and extend `DeliveryNoteBillingProjectionTest.php:257`'s `$dirtyShapes` with an object and a list
shape on **both** keys. The existing test structure covers it in three added lines.

### R2-2 — **[IMPORTANT]** the three new regressions are engine-conditional and CI runs them on SQLite only, so the Critical's guard is unpinned in CI

The branch adds exactly one PostgreSQL CI step (`.github/workflows/ci.yml`, after the `--filter`
allowlist): `php artisan test -c phpunit-pgsql.xml tests/Feature/Document/DeliveryNoteConsolidationConcurrencyTest.php`.
Neither `DeliveryNoteBillingProjectionTest` nor `DeliveryNoteBillingClaimServiceTest` is in that step
or in either `--filter` allowlist, so both run **only** on the default SQLite connection in CI. On
SQLite:

- `test_a_non_uuid_payload_invoice_id_reads_as_unresolved_…` passes **with or without** the
  `Str::isUuid()` guard — SQLite has no `uuid` type, so `find('INV-2024-001')` simply matches nothing.
- `test_an_array_payload_is_normalised_…` passes **with or without** `postgresPayloadObjectSql()` —
  the SQLite branch uses `json_patch`, which already replaces a non-object base.
- `assertMatchingFinalisedPairs`'s cast is PostgreSQL-only by construction.

So all three fixes this round shipped are, in CI, pinned by tests that cannot fail if the fix is
reverted. That is F-2's root cause reappearing one level down: the manual run closed the finding, but
nothing keeps it closed. This wave has already demonstrated that the unguarded-uuid class recurs
(M3-round1 finding 5, M4-round1 finding 2, F-1, F-T2 — four occurrences in one wave).

*Fix:* add the two file paths to the PostgreSQL CI step the branch already created. Two lines.

### R2-3 — **[IMPORTANT]** the 14-file PostgreSQL set is red when run in one process; `137/1005/0` reproduces only under the executor's 13+1 split

I ran all 14 files in a single `php artisan test -c phpunit-pgsql.xml` invocation and got
**1 failed / 136 passed / 994 assertions**:

```
FAILED  DeliveryNoteConsolidationTest > can consolidate multiple delivery notes to single invoice
        Failed asserting that actual size 9 matches expected size 3.
        at tests/Feature/Document/DeliveryNoteConsolidationTest.php:214
```

Isolated to two files in order (`…ConcurrencyTest.php …ConsolidationTest.php`) it still fails; each
file alone is green (17/179 and 12/148). Mechanism: `DeliveryNoteConsolidationTest.php:211-214` counts
`stored_events` **globally** — `->where('event_class', DocumentConverted::class)` with no company,
tenant or document predicate — while `DeliveryNoteConsolidationConcurrencyTest` forks child processes
on cloned connections (`:41-42`, `tearDown` `:110`) whose writes **commit** and therefore survive
`RefreshDatabase`'s parent-connection transaction. 18 `stored_events` rows were still in the scratch
database after the run, with 0 `documents`.

Both the unscoped assertion and the fork-based file are new in this branch (added in the M1/M2
phases — I missed the interaction in round 1 because I used the same file split the executor did).
CI is green today only because the branch runs the concurrency file in its own step and SQLite skips
it; the moment anyone follows `R2-2` and puts more of the wave's files into a PostgreSQL step, or a
promoter simply runs "the wave's tests", this goes red. The YAML *does* disclose the split
("125/857 across 13 files plus … 12/148"); the handback table does not ("over all 14 test files the
wave touches … 137 passed / 1005 / 0"), and neither says the set is order-dependent.

*Fix:* scope the assertion to the documents/company under test (one line), or reset
`stored_events`/`audit_events` in the concurrency file's `tearDown`.

### R2-4 — **[MINOR]** F-6's validator and the column speak different languages, so the abort path is narrowed, not closed

`safeInvoicedAt()` validates with `CarbonImmutable::parse()` but returns and inserts the **original
string** (migration `:191`), which PostgreSQL then parses itself. The two grammars differ. Measured at
this tip:

| value | `CarbonImmutable::parse` | `::timestamptz` |
|---|---|---|
| `+1 day` | OK | **ERROR 22007** |
| `@1755500000` | OK | **ERROR 22008** |
| `now` / `tomorrow` | OK | OK — but resolves to **migration-run time** |
| `yes`, `0`, `P1D`, `2026-13-45` | THROW (counted) | ERROR |

So a Carbon-parseable, PG-unparseable value still aborts `tenants:migrate`, and `now`/`tomorrow`
silently fabricate an `invoiced_at` of the migration run instead of being counted (inherited, not
introduced). Both are implausible for machine-written payloads (`now()->toIso8601String()`) and
plausible only for hand-edited or imported rows. Insert `$parsed->toIso8601String()` instead of the raw
string and the two grammars collapse into one; that also matches the F-6 docblock's intent.

### R2-5 — **[MINOR]** F-3's normalisation is a silent destructive write, and the legacy survey round 1 asked for was not delivered

`postgresPayloadObjectSql()` discards an array/scalar/JSON-null payload wholesale, with no counter and
no log. Round-1 F-3 asked for "a `jsonb_typeof(payload) <> 'array'` guard (or a CHECK constraint)
**and a legacy survey count**"; only the first half landed. Latency re-verified at this tip —
`grep -rn "'payload' => \[\]" app/ database/` is empty and no `payload` validation rule exists in the
Document request classes — so no production writer creates the shape and I do not raise it higher. But
the M1C backfill already visits every delivery note; counting non-object payloads there would have cost
one line and would be the same honest signal the other seven counters provide.

### R2-6 — **[MINOR]** two soft spots in the new tests/lock helper

- `SalesOrderBillingClaimTest.php:674` asserts only `assertGreaterThanOrEqual(500, …)`. It is red
  pre-fix (the old path returned 422) so the regression is real, but it would also pass on a 500
  raised for an unrelated reason — e.g. the anonymous-subclass container swap failing. Asserting
  `assertStatus(500)` plus `assertNotSame(422, …)`, or driving it with
  `withoutExceptionHandling()` and an `expectException`, would pin the intent.
- `SalesOrderToDeliveryNoteConverter::lockOrderHeader()` (`:182-191`) ends in `->first()` and ignores
  the result: if the row does not match (type/company/tenant drift, or a concurrent delete) it locks
  **nothing** and the ordering guarantee silently evaporates. Unreachable today — `convert()` has
  already validated the same model — but a `firstOrFail()` would make the guarantee non-silent.

---

## D. Disposition

| Round-1 finding | Status at `b6091a74a` |
|---|---|
| F-1 CRITICAL | **PARTIALLY CLOSED** — lookup guarded and pinned on PG; cast ingress still 500s → `R2-1` |
| F-2 IMPORTANT | CLOSED as written (manual PG re-run recorded, counts reproduce) — durability gap → `R2-2` |
| F-3 IMPORTANT | CLOSED (object-safe on both merges; NULL/array/scalar/json-null all verified) — residual `R2-5` |
| F-4 IMPORTANT | CLOSED and proven load-bearing by mutation |
| F-5 IMPORTANT | CLOSED (L1 first in both delivery paths; total order holds; PG gate honest) — nit `R2-6` |
| F-6 IMPORTANT | CLOSED for every enumerated shape; fixture proves counted-and-skipped — residual `R2-4` |
| F-7 IMPORTANT | CLOSED (500-class, context preserved, blast radius contained, 422 paths intact) |
| F-8 IMPORTANT (inherited) | RECORDED with accurate citations + promotion gate |
| F-9 MINOR | RECORDED with accurate citation |
| F-10 MINOR | CLOSED |

**Before merge:** guard the two `(string)` casts at `DeliveryNoteBillingState.php:20-21` and extend the
F-1 fixture with object/list shapes on both keys (`R2-1`, blocking); add the two PG-only test files to
the PostgreSQL CI step the branch already created (`R2-2`); scope
`DeliveryNoteConsolidationTest.php:214` so the wave's own test set is order-independent (`R2-3`).
`R2-4`, `R2-5`, `R2-6` are record-or-fix at the executor's discretion.

---

**VERDICT: CHANGES-REQUIRED**
