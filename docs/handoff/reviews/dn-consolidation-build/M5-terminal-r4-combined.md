# TERMINAL re-review register — M5 round 4, **COMBINED** (lenses: treasury + tenancy-authz)

**Wave:** `dn-consolidation-build` · **Branch:** `codex/dn-consolidation-2026-08-12`
**Round-3 registers re-verified:** `M5-terminal-treasury-r3.md` (CHANGES-REQUIRED — 1 Important,
4 Minor) and `M5-terminal-tenancy-authz-r3.md` (CHANGES-REQUIRED — 2 Important, 1 Minor).
**Delta reviewed:** `37d8c3af6..362105e16` — one fix commit (`362105e16`, 11 files) applied by the
parent for BOTH lenses, plus the two r3 registers themselves.
**Tip at review:** `362105e16` (expected tip confirmed).
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/dn-consolidation` — read-only
apart from one *temporary, restored* arm-removal red-proof (see §A.2) and this register.
**Scratch databases:** `autoerp_dn_r4`, `autoerp_dn_r4b` (created for this round; `autoerp_test`
never touched).

Every closure below was re-derived by **executing** code at this tip. Nothing was accepted from the
fix commit's message or from either r3 register's prose.

---

## VERDICT

**CHANGES-REQUIRED.** Two Important, seven Minor.

**Eight of the nine r3 items are genuinely closed**, including both r3 Importants' *behavioural*
halves — the controller rethrow arm is real and I re-proved its red direction myself by removing the
arm and watching the test fail with `422 !== 500`, and the marker-migration test is now on the
PostgreSQL CI lane in a job that runs on PR→dev.

What blocks is two things, and both are this wave's signature failure mode for the fourth
consecutive round:

1. **The commit is Pint-red, and it is the only Pint-red file in `apps/api`.** `./vendor/bin/pint
   --test` over the whole app returns exactly one failing path — the test file this delta edited.
   `ci.yml:58` runs that command. The delta turns a green code-style job red. The pre-delta version
   of the same file passes (proved by checkout of `37d8c3af6`).
2. **`F-R3-1`'s claim-correction half was not delivered, and the claim is still false.** The
   tenancy r3 register asked for two things: bound the parse (done) **and** "*correct `:180` to state
   the residual set instead of 'by construction'*" (not done). `:180` still reads "*every value this
   method accepts is now, by construction, a value PostgreSQL accepts*", and I falsified it again in
   one command on a different axis: a Carbon-parseable timezone **offset** outside PostgreSQL's
   ±15:59 displacement range survives the new year bound and aborts `tenants:migrate` with
   **SQLSTATE 22009**. The predicate approach has now been narrowed three rounds running; the
   structural alternative the r3 register offered (catch the per-row `QueryException`) closes the
   whole class in four lines.

No money is wrong. No GL entry, no `journal_lines`, no `payment_repositories.balance` and no fiscal
chain is touched anywhere in this delta — the rule-19 scan over the diff is empty.

---

## A. What I executed at this tip

```bash
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "CREATE DATABASE autoerp_dn_r4  OWNER autoerp;"
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "CREATE DATABASE autoerp_dn_r4b OWNER autoerp;"
cd apps/api && DB_HOST=127.0.0.1 DB_PORT=5433 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
  DB_DATABASE=<scratch> DB_CENTRAL_DATABASE=<scratch> CACHE_STORE=array \
  php artisan test -c phpunit-pgsql.xml <paths>
```

### A.1 Runs

| Run | Expected by the brief | Measured |
|---|---|---|
| `SalesOrderBillingClaimTest` alone | — | **19 passed / 132 assertions** ✔ |
| `SalesOrderBillingClaimTest` + `DeliveryNoteToBillQueueTest` + `DeliveryNoteConsolidationTest`, one process | 42 / 369 | **42 passed / 369 assertions** ✔ |
| `DeliveryNoteBillingMarkerMigrationTest` | 5 / 54 | **5 passed / 54 assertions** ✔ |
| The **exact CI PostgreSQL step, in its shipped order** (`…Concurrency`, `…BillingProjection`, `…BillingClaimService`, `…MarkerMigration`) | — | **37 passed / 404 assertions** ✔ |
| `phpstan analyse` over the four changed `app/` files (config analyses `app/` only) | — | **No errors** ✔ |
| `./vendor/bin/pint --test` (whole `apps/api`) | — | **FAIL — 1 file** ✘ (`R4-1`) |

The 4-file CI-step run matters on its own: this delta adds a **fourth** file to a process that
already runs the fork-based concurrency file first, which is exactly the bleed geometry `R2-3`/`R3-4`
are about. It does not bleed — verified by running it, not by reasoning about it.

### A.2 The arm-removal red-proof (temporary, restored)

The treasury r3 Important asked for the controller disposition to be pinned over HTTP. I did not
take the parent's in-comment red-proof on trust. `git status` was clean; I deleted lines 149-156 of
`DocumentConversionController.php` (the whole `catch (SalesOrderHeaderLockException)` arm), ran the
two new tests, and restored the file with `git checkout --` (tree verified clean again):

```
⨯ a lock order violation surfaces as a 500 class alert over http
  A lock-order violation must raise a 500-class alert, never a routine 422.
  Failed asserting that 422 is identical to 500.
✓ a routine delivery conversion refusal still returns 422
```

Both directions proved in one run: the alarm test goes **red** without the arm (and red for the right
reason — the catch-all's 422), while the control test stays green, so the arm is **narrow** and does
not swallow routine refusals. This is stronger than the structural argument the brief allowed.

### A.3 Probes

| Probe | Result |
|---|---|
| `CarbonImmutable::parse('0000-00-00')` / `('0000-01-01')` | year `-1` → `-0001-11-30T00:00:00+00:00`; year `0` → `0000-01-01T00:00:00+00:00` |
| `INSERT` of both into a real `timestamptz NOT NULL` column | `22007 invalid input syntax` / `22008 date/time field value out of range` — **`F-R3-1`'s premise independently confirmed; the two new fixtures are decisive** |
| Carbon over offsets `+16:00 … +23:59` | parsed, and `toIso8601String()` **re-emits the offset verbatim** |
| `INSERT '2026-01-01T00:00:00+20:00'` into `timestamptz NOT NULL` | **`ERROR: 22009 time zone displacement out of range`** — see `R4-2` |
| `INSERT '10000-01-01T00:00:00+00:00'` | **accepted** — PostgreSQL's range runs to 294276 AD, so the new `year > 9999` half over-rejects (fail-safe, but the comment's stated reason is wrong) |
| Carbon over `'2026-01-01 00:00:00 PST'` | → `2026-01-01T00:00:00-08:00`. **The brief's abbreviation hypothesis is clean**: `toIso8601String()` is `Y-m-d\TH:i:sP`, so a named/abbreviated zone always leaves as a numeric offset. The only two vectors are year range and offset range |
| framework-booted `__('documents.to_bill_queue.definitely_missing_key')` | returns the raw key string → see `R4-6` |
| rule-19 scan: `git diff 37d8c3af6..362105e16` (non-docs) `\| grep '^+'` for `(float)`, `(double)`, `floatval`, `number_format`, `round(`, `parseFloat`, `Number(`, `toFixed`, bare `getScale()`, `bcadd/bcsub/bcmul` | **zero matches** — no money arithmetic added |

---

## B. Round-3 findings — closure verified one by one

### B.1 TREASURY LENS

#### `R3-1` (IMPORTANT) — **CLOSED, and I re-proved it independently.**

Three parts, all real:

1. **A dedicated type.** `app/Modules/Document/Domain/Exceptions/SalesOrderHeaderLockException.php`
   (new, 30 lines) extends `RuntimeException` with a `forOrder()` named constructor. The dedicated
   type was required, not cosmetic: `SalesOrderToDeliveryNoteConverter::convert()` throws bare
   `RuntimeException` at `:139` (*"Cannot convert cancelled sales order"*) and `:148` (*"already
   fully delivered"*) for routine 422 refusals on the same lane, so the class is the only axis that
   can separate alarm from refusal. The exception's own docblock says exactly that.
2. **The arm.** `DocumentConversionController.php:149-156` — `catch (SalesOrderHeaderLockException $e) { … throw $e; }` placed **before** the catch-all at `:157`. It mirrors the F-7 arm at `:85-92`
   on the invoice lane.
3. **Two HTTP tests, and they are the right two.** `SalesOrderBillingClaimTest.php:889`
   (`test_a_lock_order_violation_surfaces_as_a_500_class_alert_over_http`) asserts
   `assertSame(500, …)` **plus** `assertInstanceOf(SalesOrderHeaderLockException::class,
   $response->exception)` **plus** `assertStringContainsString('Lock order violation', …)`, so it
   cannot pass on an unrelated 500. `:925`
   (`test_a_routine_delivery_conversion_refusal_still_returns_422`) is the **control** that keeps the
   arm honest — a bare `RuntimeException` on the same route must still be a 422 with its message in
   `error`.

**Reachability of the arm is complete.** I checked that this is genuinely the only lane:
`grep -rn "converterRegistry->convert"` over `app/` returns seven call sites and exactly one converts
to `DocumentType::DeliveryNote` — `DocumentConversionController.php:143`. There is no second HTTP
path that could still flatten the alarm.

**The test seam is legitimate.** `registryWithDeliveryConverterThrowing()` (`:955`) builds a **real**
`DocumentConverterRegistry` and registers an anonymous `DocumentConverterInterface`. The comment's
justification — "the registry and the production converter are both final, so the tests swap at the
ONE seam the design leaves open" — is true at tip: `DocumentConverterRegistry.php:32` is
`final class`, `SalesOrderToDeliveryNoteConverter.php:54` is `final class`. This is not mocking the
thing under test: the thing under test is the **controller's disposition**, and the converter is a
fixture for it. The *throw itself* stays pinned by the pre-existing `ReflectionMethod` test at `:767`.

The converter docblock at `:189-193` now states the disposition correctly — with one dangling
citation, `R4-3`.

#### `R3-2` (MINOR) — **CLOSED.** `HANDBACK-…md:1096` now reads *"scoped by `event_properties->targetDocumentId` (NOT `aggregate_uuid`, which is NULL for these bus-dispatched events — the first attempt used it and was red); `audit_events` scoped by `company_id` (`tenant_id` is auth-derived and unreliable here)"*. That matches the shipped code (`DeliveryNoteConsolidationTest.php:238, :244`) and it states *why* the abandoned axis was abandoned, which is what the finding asked for.

#### `R3-3` (MINOR) — **CLOSED, and the replacement is measurably true.** Migration `:181-186` drops "round-trip exact" and now says `toIso8601String()` *"truncates sub-second precision — harmless here, since no producer in this repo has ever written a fractional `invoiced_at`"* and adds the omitted improvement, *"it pins the offset explicitly where PostgreSQL used to resolve bare datetimes in the session timezone."* Both halves match my probes. (The neighbouring `:180` sentence is a different claim and is **not** closed — `R4-2`.)

#### `R3-4` (MINOR) — **PARTIALLY CLOSED: two of the three lines the finding named.** `:189` and `:675` are now `->where('company_id', $this->company->id)`, each with the reason inline. `:786` is untouched — `R4-5`.

#### `R3-5` (MINOR) — **CLOSED in substance.** `DeliveryNoteToBillQueueTest.php:297` adds `->assertJsonPath('error.errors.location_id.0', __('documents.to_bill_queue.no_active_location_in_scope'))`, which does catch the failure mode the finding named (a **controller-side** key typo: the test's `__()` resolves the real key, the response carries the typo, red). Green in my 42/369 run. The *comment* claims a stronger property than the assertion has — `R4-6`.

#### `R2-5` — still **RECORDED-NOT-FIXED**, unchanged by this delta, parked with a named owner. Re-confirmed as acceptable; not re-raised.

### B.2 TENANCY-AUTHZ LENS

#### `F-R3-1` (IMPORTANT) — **PARTIALLY CLOSED. The year bound is real and gated; the claim correction was not delivered and the claim is still false.**

**What landed and works.** Migration `:214` adds `if ($parsed->year < 1 || $parsed->year > 9999)` →
`$counts['unparseable_invoiced_at']++; return null;`, placed after the `try/catch` parse and before
the `toIso8601String()` return (`:220`). The two fixtures landed at
`DeliveryNoteBillingMarkerMigrationTest.php:175` (`'0000-00-00'`) and `:180` (`'0000-01-01'`), the
count moves `4 → 6` at `:244`, and `rows_written` stays at `15` — which is itself the assertion that
**no marker row** was written for either, since a marker would read 17. The test is red-proof by
construction: without the bound, `safeInvoicedAt()` returns `-0001-11-30T00:00:00+00:00`, the INSERT
raises 22007 and the test errors. I confirmed both halves of that mechanism by direct probe (§A.3).
Green at 5/54 standalone **and** 37/404 as the 4th file of the CI step.

**What did not land.** The finding's second ask was explicit: *"Then correct `:180` to state the
residual set instead of 'by construction'."* `:180` is byte-identical to the falsified version.
See `R4-2` for the falsification I ran at this tip.

#### `F-R3-2` (IMPORTANT) — **CLOSED.** `ci.yml:656` adds `tests/Feature/Document/DeliveryNoteBillingMarkerMigrationTest.php` as a **path** to the `phpunit-pgsql.xml` invocation. I checked the half that actually decides whether the gate exists on the deploying branch: the owning job is `backend-test-pgsql` (`ci.yml:332`) and its condition (`:342`) is `workflow_dispatch || base_ref == 'main' || base_ref == 'dev' || (push && ref == refs/heads/main)` — so it **does** run on PR→dev, which is the auto-deploying path the finding was about. Ran the step as shipped: **37 passed / 404 assertions**, no bleed from the fork-based file that runs first. The one omission is documentation — `R4-7`.

#### `F-R3-3` (MINOR) — **PARTIALLY CLOSED.**

*First clause — closed, and the replacement is accurate.* `DeliveryNoteBillingState.php:41-45` now
reads *"The backfill's treatment differs by key — a stamped row with an unparseable `invoice_id`
still gets a marker row with `invoice_id = NULL` + lane `legacy_unknown` (the DN stays claimed),
while an unparseable `invoiced_at` is only counted — so this projection is the more conservative
reader of the two, by design."* I verified that against the loop: `:86-88` `continue`s when
`safeInvoicedAt()` is null (no marker), while `:93-95` downgrades the lane and `:97-104` inserts the
marker anyway with a NULL `invoice_id`. True on both keys, in the direction that matters.

*Second clause — not closed.* The finding named **three** stale in-code citations; one was
symbol-ized. See `R4-4`.

---

## C. Findings (delta only)

### `R4-1` — **[IMPORTANT]** `apps/api/tests/Feature/Document/SalesOrderBillingClaimTest.php` — the delta is Pint-red and is the **only** Pint-red file in `apps/api`; `ci.yml:58` runs `pint --test`

```
$ ./vendor/bin/pint --test          # whole apps/api
{"result":"fail","files":[{"path":"tests/Feature/Document/SalesOrderBillingClaimTest.php",
 "fixers":["class_definition","fully_qualified_strict_types","unary_operator_spaces",
           "braces_position","not_operator_with_successor_space","single_line_empty_body",
           "ordered_imports"]}]}
```

**Attributable to this delta, proved rather than assumed.** I extracted the same path at
`37d8c3af6` and ran Pint on it: `{"result":"pass"}`. The repository was clean before this commit
(commit `4b93ec229` cleared the inherited 25-file baseline precisely so that every lane's preflight
would be meaningful), and it is clean now except for this one file.

The three drifts are all in the new code:

```php
app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();   // :891, :927
$registry->register(new class($thrower) implements \App\Modules\…\DocumentConverterInterface  // :958
{
    public function __construct(private readonly Closure $thrower)
    {
    }                                                                            // :961-963
```

Pint wants the two FQCNs imported (`PermissionRegistrar` is **already** imported in this file, so
`:891`/`:927` are pure noise), the anonymous-class `implements` on the same line, and the empty
constructor body collapsed to `{}`.

*Why it matters:* rule 10 makes preflight a precondition of "task complete", and `ci.yml:58` is a
hard job. This is a terminal gate on a wave whose whole point is that the record matches reality —
shipping a commit that reddens a green style job is the cheapest possible way to fail that.

*Fix:* `./vendor/bin/pint apps/api/tests/Feature/Document/SalesOrderBillingClaimTest.php`, one
command, no behaviour change (I ran it into a scratch copy; the diff is import ordering, brace
position and the empty body — nothing semantic).

### `R4-2` — **[IMPORTANT]** `apps/api/database/migrations/tenant/2026_08_18_000002_create_delivery_note_billing_marks_table.php:180` and `:209-214` — the "by construction" claim `F-R3-1` asked to be corrected is unchanged, and it is still false: a Carbon-parseable **timezone offset** outside ±15:59 survives the new year bound and aborts `tenants:migrate` with SQLSTATE 22009

`:180` still reads, absolutely:

```
 * every value this method accepts is now, by construction, a value PostgreSQL accepts,
```

Falsified at this tip, on a different axis from the one the r3 register used:

```
php>  CarbonImmutable::parse('2026-01-01T00:00:00+20:00')->year            = 2026     (passes :214)
php>  …->toIso8601String()                                                = '2026-01-01T00:00:00+20:00'
psql> insert into probe (v /* timestamptz not null */) values ('2026-01-01T00:00:00+20:00');
      ERROR:  22009: time zone displacement out of range: "2026-01-01T00:00:00+20:00"
```

PHP accepts offsets up to `±23:59` (`+50:00` and above throw); PostgreSQL's `timestamptz` text input
accepts up to `±15:59` (`+15:59` inserts, `+16:00` raises 22009). The residual set is therefore
**precisely** `±16:00 … ±23:59`, it passes `is_string`, `trim`, `CarbonImmutable::parse` and the new
year bound, and `toIso8601String()` re-emits it verbatim into the NOT NULL `timestamptz` column at
`:97-103`. One such row in one tenant's `documents.payload` throws inside the chunk loop, the
migration rolls back, and **that tenant** ends the deploy without `delivery_note_billing_marks` while
every other tenant has it — the per-tenant divergence F-6 exists to prevent, on a repository that
auto-runs `tenants:migrate` on every push to `origin/dev`.

I want to be fair about likelihood, because it is lower than `'0000-00-00'`: no producer in this
repository and no real IANA zone can emit an offset beyond `+14:00`, so the shape only arrives via a
hand-edited or third-party legacy import. The reason this is Important anyway is the other half:
**the finding explicitly asked for this sentence to be corrected and it was not**, and the sentence
is the one a future maintainer will trust when deciding whether the class is closed. Three rounds
running, this predicate has been asserted total and then falsified — F-6 → `R2-4` → `F-R3-1` → now.

Two smaller inaccuracies in the same block, both introduced by this delta:

- `:211` says the bound is "*what timestamptz text input accepts in ISO form*". PostgreSQL accepts
  years to **294276**; I inserted `'10000-01-01T00:00:00+00:00'` successfully. The `> 9999` half
  over-rejects. That is **fail-safe** (counted and skipped, never an abort) so the behaviour is fine
   — but the stated reason is wrong, and the honest reason is better: `toIso8601String()`'s `Y` token
  is the thing that stops being unambiguous, and a marker dated after 9999 is junk regardless.
- `:209-210` enumerates only the year-zero shapes as "shapes PostgreSQL rejects", which is now the
  same completeness claim as `:180` in miniature.

*Fix (either; the second closes the class permanently and is what the r3 register offered):*

```php
// (a) extend the predicate — still a predicate, still enumerative
if ($parsed->year < 1 || $parsed->year > 9999 || abs($parsed->getOffset()) > 15 * 3600 + 59 * 60) { … }

// (b) enforce the contract at the INSERT, where it cannot be enumerated wrong
try { DB::table('delivery_note_billing_marks')->insert([...]); $counts['rows_written']++; }
catch (QueryException) { $counts['unparseable_invoiced_at']++; }
```

and in either case replace `:180`'s "by construction" with the residual set actually claimed.
If (b) is taken, add a `'2026-01-01T00:00:00+20:00'` fixture — with the migration test now on the
PG lane (`F-R3-2`), that fixture can finally go red in CI.

### `R4-3` — **[MINOR]** `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToDeliveryNoteConverter.php:192-193` — the corrected docblock cites a test class that does not exist

```
 * for routine 422 refusals), the same disposition F-7 established. Pinned over
 * HTTP by DocumentConversionIntegrityDispositionTest. (M5-terminal treasury r3.)
```

`grep -rn "DocumentConversionIntegrityDispositionTest"` across the whole repository (excluding
`vendor/`) returns **exactly one hit: this line.** The tests that actually pin the disposition are
`SalesOrderBillingClaimTest::test_a_lock_order_violation_surfaces_as_a_500_class_alert_over_http`
(`:889`) and `…::test_a_routine_delivery_conversion_refusal_still_returns_422` (`:925`).

This is the same class of defect as `R3-2` and `F-R3-3`-clause-2, which this very commit exists to
fix: a citation that sends a reader to an artefact that is not there. It is the **only** pointer
from the production code to the evidence for the claim in the sentence before it.

*Fix:* replace with `SalesOrderBillingClaimTest::test_a_lock_order_violation_surfaces_as_a_500_class_alert_over_http` (and its 422 control), or create the named class.

### `R4-4` — **[MINOR]** `DeliveryNoteBillingState.php:32-33` and `DocumentData.php:196` — `F-R3-3`'s second clause fixed one stale citation of three

The finding named three. One was symbol-ized (`DeliveryNoteBillingState.php:35`, now
`DocumentData::fromModel()` — correct, `Str::isUuid()` is at `DocumentData.php:208` inside
`fromModel()`). The other three are unchanged and, measured at this tip, all wrong:

| citation | at | actual |
|---|---|---|
| `safeInvoicedAt():176` | `DeliveryNoteBillingState.php:32` | migration `:192` |
| `safeInvoiceId():204` | `DeliveryNoteBillingState.php:33` | migration `:224` |
| `safeInvoiceId():204` | `DocumentData.php:196` | migration `:224` |

`:204` now lands inside `safeInvoicedAt`'s body (the `catch (Throwable)` counter), i.e. a reader
following it reads the *wrong guard* and concludes `safeInvoiceId` counts unparseable **timestamps**.

*Fix:* symbol-ize all three, exactly as `:35` was.

### `R4-5` — **[MINOR]** `apps/api/tests/Feature/Document/DeliveryNoteConsolidationTest.php:786` — `R3-4` scoped two of the three lines it named

```php
$this->assertSame(0, Document::query()->where('type', DocumentType::Invoice)->count());   // :786
```

Still absolute and global, in a class where the two sibling absolutes were scoped this round with the
inline reason *"absolute global counts in this class bleed under one-process PG runs alongside the
fork-based concurrency file."* The reason applies verbatim here. Not red today (37/404 and 42/369
both green), so it is latent, exactly as `R3-4` characterised the class.

*Fix:* `->where('company_id', $this->company->id)`, one predicate, or record why `:786` is exempt.

### `R4-6` — **[MINOR]** `apps/api/tests/Feature/Document/DeliveryNoteToBillQueueTest.php:294-296` — the new comment claims a property the new assertion does not have

```php
// … this assertion keeps the key resolving (a raw-key render would fail it). (treasury r3 minor)
->assertJsonPath('error.errors.location_id.0', __('documents.to_bill_queue.no_active_location_in_scope'));
```

Both sides of the comparison resolve the **same** key through `__()`. Framework-booted probe at this
tip: `__('documents.to_bill_queue.definitely_missing_key')` returns the literal string
`documents.to_bill_queue.definitely_missing_key`. So if `lang/en/documents.php` ever lost the entry,
the controller renders the raw key, the test's `__()` renders the same raw key, and the assertion is
**green** — a raw-key render does *not* fail it.

What the assertion **does** pin, correctly and usefully, is a controller-side key **typo** (the
response would carry the typo while the test resolves the real key). That is what `R3-5` asked for,
so the finding is closed; only the comment overstates.

*Fix:* either reword to "pins the controller's key against this test's key" or assert the literal
English string (`'No active location is available within your allowed scope; select a location
explicitly.'`), which pins resolution for real.

### `R4-7` — **[MINOR]** `.github/workflows/ci.yml:635-656` — the rationale block was not extended to cover the file added at `:656`

The block is 16 lines of durable reasoning for why `DeliveryNoteBillingProjectionTest` and
`DeliveryNoteBillingClaimServiceTest` must run on PostgreSQL ("*each test passed with or without its
fix*" on SQLite, with three concrete mechanisms). `DeliveryNoteBillingMarkerMigrationTest` is added
one line below it with no rationale — yet its argument is the sharpest of the four and it is the
durable half of `F-R3-2`: on SQLite the raw string `'+1 day'` is stored **verbatim** in a `timestamp`
column, Carbon parses it back, and the 24-hour delta assertion passes with or without the fix.
Without that sentence in the workflow, a future cleanup that trims this step has no reason not to
drop the file again.

*Fix:* two lines inside the existing comment block.

### `R4-8` — **[MINOR]** `apps/api/tests/Feature/Document/DeliveryNoteBillingMarkerMigrationTest.php:175, :180` — the two new fixtures are assigned to variables that are never read

`$invoicedAtYearZero` and `$invoicedAtYearZeroIso` appear exactly once each (`grep -n
"invoicedAtYearZero"` → two hits, both the assignments). The proof rides entirely on the aggregate
log assertion (`unparseable_invoiced_at => 6` with `rows_written` held at `15`), which **is** a real
and sufficient assertion — a marker for either row would read 17. But every sibling fixture in this
test is used, and a per-row `assertDatabaseMissing('delivery_note_billing_marks', ['delivery_note_id'
=> $invoicedAtYearZero->id])` would localise the failure instead of moving one aggregate number.

*Fix:* two `assertDatabaseMissing` calls, or drop the assignments.

### `R4-9` — **[MINOR]** `docs/handoff/HANDBACK-…md` / `docs/handoff/…/progress.yaml` — the r3 fix round is not in the promoter-facing record

The delta changed exactly one line of the handback (the `R3-2` correction) and did not touch
`progress.yaml`. So the handback a promoter reads first contains no entry for
`SalesOrderHeaderLockException`, the `convertOrderToDelivery` rethrow arm, the `safeInvoicedAt` year
bound, the two year-zero fixtures, or the CI-lane addition. `grep -n "r3\|R3"` over the handback
returns one unrelated hit (an M4 line). Every previous round in this wave landed a handback +
`progress.yaml` entry alongside its fixes; this one landed only in the commit message.

*Fix:* one handback row per r3 item and the matching `progress.yaml` narrative entries, in the same
form the r2 round used.

---

## D. Residuals looked at and deliberately NOT raised

- **`DocumentData.php:133-144` unguarded `(string)` casts on `converted_to_order_id` /
  `converted_at`** — present verbatim at the wave base `60df88a01`; carried from the tenancy r3
  register's §D. Not a delta defect; belongs to a repo-wide lane.
- **`R2-5`** (`postgresPayloadObjectSql()` silently normalising a non-object payload base) —
  unchanged by this delta, still recorded-not-fixed with a named owner. Correct disposition.
- **`DeliveryNoteConsolidationTest.php:366, :433`** absolute `delivery_note_billing_marks` counts —
  same latent class as `R4-5` but never named by any register; not introduced here.
- **`performPartialDelivery` has no HTTP caller** (`:143` passes no `delivery_quantities`) — the
  lock and the new exception cover both transaction closures either way; pre-existing, out of scope.
- **Authorization / rule 12** — the delta touches no `routes.php`, no middleware, no
  `config/verticals.php`, and adds no new endpoint. The two new tests exercise the existing
  `deliveries.create` permission on the existing route. No gating drift.

---

## E. Disposition — stated per lens

### E.1 TREASURY lens (`M5-terminal-treasury-r3.md`) — **all five items CLOSED or substantively closed**

| r3 id | Sev | Status at `362105e16` | Verified by |
|---|---|---|---|
| `R3-1` | **Important** | **CLOSED** — dedicated exception + narrow controller arm + two HTTP tests; sole reachable lane confirmed | independent arm-removal red-proof (422→red, control stays green) + 19/132 + 42/369 |
| `R3-2` | Minor | **CLOSED** — handback `:1096` now names `targetDocumentId`/`company_id` and why `aggregate_uuid` was unusable | file read |
| `R3-3` | Minor | **CLOSED** — sub-second truncation stated, offset-pinning improvement added | Carbon probe + file read |
| `R3-4` | Minor | **PARTIAL** — `:189` and `:675` scoped; `:786` left (`R4-5`) | grep + 42/369 |
| `R3-5` | Minor | **CLOSED in substance**; comment overstates (`R4-6`) | `__()` probe + 42/369 |

**Treasury lens disposition: CHANGES-REQUIRED** — on `R4-1` (Pint-red CI) and the residual minors
`R4-3`, `R4-5`, `R4-6`. No treasury-lens Important remains open on behaviour; no money, GL, treasury
balance or fiscal artefact is touched anywhere in the delta.

### E.2 TENANCY-AUTHZ lens (`M5-terminal-tenancy-authz-r3.md`) — **one Important closed, one half-closed**

| r3 id | Sev | Status at `362105e16` | Verified by |
|---|---|---|---|
| `F-R3-1` | **Important** | **PARTIAL** — year bound + fixtures land and are decisive; the claim correction was not delivered and the claim is still falsifiable on the offset axis (`R4-2`) | Carbon + `psql` INSERT probes (22007 / 22008 / 22009) + 5/54 |
| `F-R3-2` | **Important** | **CLOSED** — migration test on the PG lane as a path, in a job that runs on PR→dev | ran the exact step: 37/404; job `if:` at `ci.yml:342` read |
| `F-R3-3` | Minor | **PARTIAL** — per-key backfill truth corrected; 3 of 4 stale citations remain (`R4-4`) | file reads at tip |

**Tenancy-authz lens disposition: CHANGES-REQUIRED** — on `R4-2` (Important) plus `R4-4`, `R4-7`,
`R4-8`, `R4-9`.

### E.3 New findings, by severity

| id | Sev | One-liner |
|---|---|---|
| `R4-1` | **Important** | the delta is the only Pint-red file in `apps/api`; `ci.yml:58` runs `pint --test` (pre-delta version proved clean) |
| `R4-2` | **Important** | migration `:180` "by construction" unchanged and still false — offsets ±16:00…±23:59 abort `tenants:migrate` with 22009; `:211`'s stated year rationale is also wrong (PG reaches 294276) |
| `R4-3` | Minor | converter `:193` cites `DocumentConversionIntegrityDispositionTest`, which exists nowhere |
| `R4-4` | Minor | 3 of 4 stale `safeInvoicedAt():176` / `safeInvoiceId():204` citations remain |
| `R4-5` | Minor | `DeliveryNoteConsolidationTest.php:786` absolute global invoice count left unscoped |
| `R4-6` | Minor | the i18n-pin comment claims "a raw-key render would fail it"; proved false for a missing entry |
| `R4-7` | Minor | CI rationale block not extended to the file added at `:656` |
| `R4-8` | Minor | the two new year-zero fixtures are assigned and never read |
| `R4-9` | Minor | the r3 fix round is absent from the handback and `progress.yaml` |

---

**What to fix before merge:** run `./vendor/bin/pint` on
`apps/api/tests/Feature/Document/SalesOrderBillingClaimTest.php` (`R4-1`, one command, no behaviour
change); then close `safeInvoicedAt()` against its own contract for real — either extend the
predicate with the ±15:59 offset bound **or**, better, wrap the per-row `insert()` in
`catch (QueryException) { $counts['unparseable_invoiced_at']++; }` — add a `'+20:00'` fixture (which
the now-gated migration test can finally fail on), and replace `:180`'s "by construction" with the
residual set actually claimed (`R4-2`). `R4-3` and `R4-4` are two-line citation corrections on a
terminal gate and I would take them; `R4-5`…`R4-9` are record-or-fix.

**VERDICT: CHANGES-REQUIRED**
