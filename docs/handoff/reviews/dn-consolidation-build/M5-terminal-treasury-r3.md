# Terminal Re-Review Register — M5, round 3, lens: treasury

**Wave:** `dn-consolidation-build` · **Branch:** `codex/dn-consolidation-2026-08-12`
**Round-2 register:** `M5-terminal-treasury-r2.md` (CHANGES-REQUIRED — 1 Critical, 2 Important, 3 Minor)
**Delta re-reviewed:** `dd97e2806..a04b23dc5` (3 commits: the Critical, the fix batch, the record commit)
**Tip at review:** `a04b23dc5` (unchanged throughout; the concurrent tenancy r3 register was untracked
and untouched).
**Scratch databases:** `autoerp_dn_term_r3`, `autoerp_dn_term_r3b` (both created for this round;
`autoerp_test` never touched).

Every closure below was re-derived by executing the code at this tip, not by reading the handback.
The four PostgreSQL runs, the SQLite run, the Carbon/`timestamptz` grammar comparison and the
exception-propagation path were each re-executed or re-read at `a04b23dc5`.

---

## VERDICT

**CHANGES-REQUIRED.** One Important, four Minor. **All six round-2 items are genuinely closed** and
I verified every one of them by execution.

What blocks is a **new defect introduced by this delta**, and it is the third consecutive instance of
this wave's signature failure mode — *a fix whose written claim outruns what it actually delivers*.
`SalesOrderToDeliveryNoteConverter.php:186-188` states, in a docblock added this round, that the new
lock-violation alarm "surfaces as a 500-class alert rather than a routine 422, the same disposition
F-7 established for this wave's other integrity alarms." That is **false on the only HTTP lane that
reaches the code**: `DocumentConversionController::convertOrderToDelivery` (`:136-153`) has a single
blanket `catch (\Exception $e) → 422`, and — unlike `convertOrderToInvoice`, where F-7 added an
explicit rethrow arm at `:84` precisely because *"this method also has a catch-all `catch (\Exception)`
that would still flatten them into a 422"* — no such arm exists. The round re-asserted F-7's
disposition while shipping a path where it does not hold, and the new test drives the method by
`ReflectionMethod` so the HTTP disposition is never exercised.

No money is wrong, no GL or treasury balance moves, and the transaction rolls back cleanly (the lock
is the first statement inside both `DB::transaction` closures). This is Important, not Critical. It is
a one-line rethrow arm plus a comment correction.

---

## A. What I executed at this tip

```bash
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "CREATE DATABASE autoerp_dn_term_r3  OWNER autoerp;"
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "CREATE DATABASE autoerp_dn_term_r3b OWNER autoerp;"
cd apps/api && DB_HOST=127.0.0.1 DB_PORT=5433 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
  DB_DATABASE=<scratch> DB_CENTRAL_DATABASE=<scratch> CACHE_STORE=array \
  php artisan test -c phpunit-pgsql.xml <paths>
```

| Run | Claimed | Measured |
|---|---|---|
| The CI PostgreSQL step's **exact three files in its own order** (`…Concurrency`, `…Projection`, `…ClaimService`) | 32 / 350 | **32 passed / 350 assertions** ✔ |
| All **14** wave backend test files **in ONE process**, `phpunit-pgsql.xml` | 139 / 1045 / 0 | **139 passed / 1045 assertions / 0 failures** ✔ |
| Same 14 files, **default engine** (SQLite) | 126 passed / 13 skipped / 0 | **126 passed / 13 skipped / 0 failures** ✔ |
| `DeliveryNoteBillingMarkerMigrationTest` (R2-4) | 5 / 54 | **5 passed / 54 assertions** ✔ |
| `SalesOrderBillingClaimTest` (R2-6) | 17 / 127 | **17 passed / 127 assertions** ✔ |

Plus: a direct `psql` probe of `'+1 day'::timestamptz` (ERROR 22007) and
`'2026-08-13T09:10:11+00:00'::timestamptz` (accepted); a `CarbonImmutable` probe of
`toIso8601String()` over five fractional/offset shapes; a framework-booted `__()` probe of the new
translation key in `en`/`fr`/`ar`; and a repository-wide read of the `DocumentConverted` dispatch,
listener and controller catch chains.

The 14 files are the complete wave set from `git diff --name-only 60df88a01..a04b23dc5 -- 'apps/api/tests/**'`
minus the ten PHPStan `Fixtures/` classes (12 `Feature/Document` + `Feature/Partner/B2BPartnerTest` +
`tests/PHPStan/DeliveryNoteBillingWritesOnlyViaClaimServiceTest`).

---

## B. Round-2 findings — closure verified one by one

### R2-1 — **CLOSED (Critical).** The cast ingress is guarded on both keys.

`DeliveryNoteBillingState.php:50-55` now reads

```php
$invoicedAt = isset($payload['invoiced_at']) && is_string($payload['invoiced_at']) ? $payload['invoiced_at'] : null;
$invoiceId  = isset($payload['invoice_id'])  && is_string($payload['invoice_id'])  ? $payload['invoice_id']  : null;
```

— full parity with `invoiced_via` at `:56` and with the migration's guard ORDER (`safeInvoicedAt():191`
and `safeInvoiceId():212` both do `is_string` first, then the format check). The `(string)` casts that
raised `ErrorException: Array to string conversion` are gone; there is no cast left in the method.

**Fixtures extended on both keys, and they execute on PostgreSQL.**
`DeliveryNoteBillingProjectionTest.php:263-268` adds `'DN-DIRTY-OBJECT' => ['nested' => 'object']` and
`'DN-DIRTY-LIST' => ['a','b']` to the `invoice_id` shapes, and the whole count assertion is now
`assertCount(count($dirtyShapes), …)` rather than a hardcoded `3`, so the fixture cannot drift away
from its own assertion. The new
`test_a_non_string_payload_invoiced_at_reads_as_absent_instead_of_500ing_the_list_and_detail_surfaces`
(`:329-380`) covers the second ingress over object / list / bool on **both** the list and the detail
surface. Both tests were green in my PG runs.

**The pinned divergence note is honest and I re-derived it.** The test discloses that
`invoiced=1` is a DATABASE predicate (`scopeWhereDeliveryNoteInvoiced` → `whereNotNull('payload->invoiced_at')`,
`Document.php:662-663`) while the DTO reads a non-string stamp as absent, so the two disagree for
exactly this dirty subset. I checked the direction of that disagreement, because it is the only place
this Critical could have touched money: **it is fail-safe on the billing side.** The to-bill queue and
the re-claim guard are both DB-driven — `DeliveryNoteBillingClaimService::reserve()` (`:64-75`) CASes on
`payloadValueSql('invoiced_at') IS NULL` at the database, so a dirty-stamped note still loses the
reserve and can never be double-billed, and `DeliveryNoteController::uninvoiced` (`:166-190`) routes
through `UninvoicedDeliveryNoteService`, not the DTO. The divergence is display-only.

**No write path was destabilised.** `toPayloadPatch()` has exactly two callers
(`DeliveryNoteBillingClaimService.php:61` and `:110`), both on a `DeliveryNoteBillingState`
constructed from trusted in-process values — never one round-tripped through `fromPayload()`. So the
new `null` cannot be written back over a legacy payload. I grepped for every reference before
concluding this.

### R2-2 — **CLOSED, and the gate can actually go red.**

`.github/workflows/ci.yml:653-656` now runs three **paths** (not a `--filter`) in the PostgreSQL step:
`DeliveryNoteConsolidationConcurrencyTest`, `DeliveryNoteBillingProjectionTest`,
`DeliveryNoteBillingClaimServiceTest`. I ran that exact step, in that exact order, on a scratch
database: **32 passed / 350 assertions.** The 20-line rationale block at `:635-652` records why each
of the three r1 fixes is unfalsifiable on SQLite, which is the durable half F-2 was missing.

Worth stating explicitly, because it is the non-obvious risk of this change: the step now runs the
fork-based concurrency file **first** in the same process as the two new files, which is precisely the
bleed geometry `R2-3` is about. It does not bleed into them — I verified by running it, not by
reasoning about it.

### R2-3 — **CLOSED, and the scoping choice is sound. I re-ran the one-process set myself.**

`DeliveryNoteConsolidationTest.php:236-240` scopes `stored_events` by
`event_properties->targetDocumentId` and `:242-245` scopes `audit_events` by `company_id`.
**14 files, one process: 139 passed / 1045 assertions / 0 failures.** The executor's finding that
`aggregate_uuid` is NULL is consistent with the code — `DocumentConverted::__construct` does call
`parent::__construct($targetDocumentId)` (`DocumentConverted.php:51`), but these events are dispatched
through `event()` / `Event::dispatch` from the converters (`CopiesDocumentData.php:254`,
`PurchaseOrderToGoodsReceiptConverter.php:147`, …), not through an aggregate root.

**Adversarial check — can `event_properties->targetDocumentId` miss a legitimately-asserted event?**
No, and this is settled empirically rather than by argument: the assertion is `assertCount(3, …)`, so
if the predicate missed *any* of the three the count would be `< 3` and the test would go **red**. It
passed on PostgreSQL (139/1045) and on SQLite (126/13/0), where Laravel compiles the same operator to
`json_extract`. `targetDocumentId` is a promoted `public readonly` property, so it is serialised by
name; a future serializer rename makes this predicate match **0** and fail loudly rather than pass
vacuously. The scoping is strictly **tighter** than the old global count — an event carrying the
*wrong* target now fails the test where the global count would have passed it.

The one genuine coverage loss is the opposite direction: a **spurious extra** `DocumentConverted` with
a *different* target is now invisible to the `stored_events` count. That is compensated two lines
down — `DomainEventSubscriber::handleDocumentConverted` (`:381-402`) writes exactly one `audit_events`
row per event with `companyId: $event->companyId`, so a spurious same-company event still makes
`assertCount(3, $auditEvents)` read 4 and go red. The `audit_events` loop additionally asserts each
row's `target_document_id === $invoiceId` and `assertEqualsCanonicalizing($requestedIds, $auditSources)`.
Net: no weakening that matters. Residual at `R3-4`.

### R2-4 — **CLOSED, and the mechanism is real.** Migration `:199-206` returns
`$parsed->toIso8601String()` instead of the raw string. I confirmed both halves of the grammar claim
directly against PostgreSQL 16 on the scratch database:

```
SELECT '+1 day'::timestamptz;                     ERROR: invalid input syntax … "+1 day"   (22007)
SELECT '2026-08-13T09:10:11+00:00'::timestamptz;  2026-08-13 09:10:11+00
```

So the `'+1 day'` fixture is decisive exactly as claimed: pre-fix its mere presence in one tenant's
payload aborted `tenants:migrate` for that tenant. The fixture shapes are `'now'` (silently-wrong
class) and `'+1 day'` (abort class), the marker count moves `13 → 15` and the logged `rows_written`
moves with it (`DeliveryNoteBillingMarkerMigrationTest.php:145-165, 178`), and the new
`assertEqualsWithDelta(24.0, …diffInHours…, 0.05)` (`:207-215`) proves the resolution happened in
Carbon's grammar. **5 passed / 54 assertions.** No DST flake: `config/app.php:68` is `'UTC'`.
Docblock overstatement at `R3-3`.

### R2-6 — **CLOSED on both halves.**

*The assertion.* `SalesOrderBillingClaimTest.php:677-694` is now `assertSame(500, …)` +
`assertInstanceOf(DeliveryNoteClaimNotFinalisedException::class, $response->exception)` + the exact
message `'Delivery-note payload finalisation affected 0 rows; expected 1.'`. It can no longer pass on
a 500 from the container swap failing.

*The lock.* `SalesOrderToDeliveryNoteConverter::lockOrderHeader()` (`:194-210`) captures `->first()`
and throws when it is null, with a dedicated engine-independent test (`:753-793`) driving the
predicate miss through `ReflectionMethod`. Both green — **17 passed / 127 assertions** on PostgreSQL,
and the guard test also runs in the SQLite lane (it is 1 of the +2 in the `124 → 126` default-engine
move). I confirmed the throw is safe with respect to the transaction: `lockOrderHeader()` is the
**first statement inside both** `DB::transaction` closures (`:214` full delivery, `:276` partial), so
it precedes every write and the rollback is trivially clean. The *disposition* of that throw is the
new defect — `R3-1`.

### R2-5 disposition — **HONEST, and properly parked. Accepted.**

I judged this against three tests and it passes all three.

1. **The finding is restated at full strength, not softened.** Both the handback (`:1140-1152`) and
   the YAML (`:238`) call it what it is — *"a SILENT DESTRUCTIVE WRITE … an array/scalar/JSON-null
   payload is discarded wholesale, with no counter and no log"* — and both concede *"Round-1 F-3 asked
   for the guard AND a legacy survey count; only the guard landed."* Neither claims closure.
2. **The latency argument is re-verifiable, and I re-verified it independently rather than trusting
   it.** `grep -rn "'payload' => \[\]" app/ database/` → empty; `grep -rn "'payload'"
   app/Modules/Document/Presentation/Requests/*.php` → empty. No production writer creates the
   non-object shape, so the counter would survey a shape that does not exist yet.
3. **The parking is structural, not silent.** It appears in the YAML's machine-readable
   `recorded_not_fixed: [R2-5, HandlesDocuments-partner_id-product_id]` (`:181`), not only in prose,
   and it names the owner (the OI-12 survey lane the promoter owns).

The stated reason — *"adding the counter means editing the M1C backfill loop, which is the one file
whose abort behaviour this same round is already changing (R2-4)"* — is the weakest part of the
record: the round **is** already editing that file, so "don't touch it" is not really an argument.
But for a MINOR with proven zero latency and a named owner, deferring is a legitimate call and the
record is honest about the fact that a call was made. **Ticket properly parked.**

### Money discipline (rule 19) across the delta — **CLEAN.**
`git diff dd97e2806..a04b23dc5` over `apps/api/app`, `apps/api/database`, `apps/web` and `apps/pos`
contains no added `(float)`, `(double)`, `floatval`, `number_format`, `round(`, `parseFloat`,
`Number(`, or `toFixed`, and no bare no-arg `getScale()`. No money arithmetic was added anywhere in
this delta; the only numeric change is a timestamp normalisation.

---

## C. Findings (delta only)

### R3-1 — **[IMPORTANT]** `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToDeliveryNoteConverter.php:186-188` — the new lock-violation alarm is flattened to a **422** on the only lane that reaches it, and the docblock added this round asserts the opposite

The docblock the fix round wrote says:

```
 * a RuntimeException surfaces as a 500-class alert rather than a routine 422, the
 * same disposition F-7 established for this wave's other integrity alarms.
```

The only HTTP caller of this converter is `DocumentConversionController::convertOrderToDelivery`
(`:136-153`, routed at `Presentation/routes.php:115` as `POST /orders/{order}/convert-to-delivery`),
and its entire error handling is:

```php
} catch (\Exception $e) {
    return response()->json(['error' => $e->getMessage()], 422);   // :148-152
}
```

`RuntimeException extends Exception`, and `DocumentConverterRegistry::convert()` (`:91-106`) rethrows
without wrapping, so the alarm is caught here and returned as a **422 carrying the internal message
verbatim** — never reaching the reporter as a 500. That is exactly the disposition F-7 rejected.

What makes this a defect rather than a nit is that **the branch already knows this and documented it
one method away.** `convertOrderToInvoice` has an explicit first-arm rethrow at `:85-91` whose own
comment reads: *"this method also has a catch-all `catch (\Exception)` that would still flatten them
into a 422 — so they are rethrown explicitly here. (M5-terminal treasury F-7.)"* The identical hazard
on the delivery lane was not addressed, and the new test cannot see it: `:753-793` invokes
`lockOrderHeader` through `ReflectionMethod`, so it asserts the **throw** while the docblock asserts
the **disposition**, and nothing tests the disposition.

Blast radius is genuinely small and I want to be precise about it: reachability is a concurrent delete
of the order header mid-transaction (`convert()` has already validated the same model), the lock is the
first statement inside both transactions so nothing is half-written, and the rollback is clean. No
money is wrong, no GL entry and no `payment_repositories.balance` moves, and no delivery note or
invoice is created. The harm is (a) an integrity alarm that never fires in Sentry, (b) a client
plausibly retrying a 422, and (c) a false claim entering the permanent record at the terminal gate —
in a wave whose last two rounds were both about claims outrunning fixes.

*Fix:* add a rethrow arm to `convertOrderToDelivery` mirroring `:85-91`, on a dedicated exception type
rather than bare `RuntimeException` (the converter already throws bare `RuntimeException` at `:138`
and `:147` for *"Cannot convert cancelled sales order"* and *"Sales order has already been fully
delivered"*, which are routine 422 refusals — the class cannot distinguish alarm from refusal on this
lane, so a `DeliveryNoteLockOrderViolationException extends RuntimeException` is needed). Add one HTTP
test that drives the miss and asserts 500. Or, if the disposition is deliberately left as a 422,
delete the claim from the docblock and record why.

### R3-2 — **[MINOR]** `docs/handoff/HANDBACK-dn-consolidation-build-2026-08-12.md:1096` — the promoter-facing table misdescribes the `R2-3` fix as the axis the executor tried, failed on, and abandoned

The row reads: *"the global `stored_events` count scoped to this consolidation's **`aggregate_uuid`**;
**`audit_events` scoped to this test's tenant**"*. The shipped code
(`DeliveryNoteConsolidationTest.php:238, 244`) scopes by `event_properties->targetDocumentId` and by
`company_id`. Those are not paraphrases of each other — `aggregate_uuid` is **NULL** for these rows
(the test comment at `:224-228` and the YAML at `:233` both say so, and the YAML adds *"the first
scoping attempt using it was red"*), and `tenant_id` was explicitly rejected in favour of `company_id`
because *"the forked children run under a company of their own, which is the axis the bleed crosses"*.

So the code, the test comment and the YAML are all correct and mutually consistent; the **handback
table alone** describes the abandoned attempt. That is the artefact a promoter reads first, and this
round's own headline is *"an honest correction to round 1's disclosure"* (`:1112`). Two lines.

*Fix:* correct `:1096` to `event_properties->targetDocumentId` / `company_id`, and say in one clause
why `aggregate_uuid` was not usable.

### R3-3 — **[MINOR]** migration `2026_08_18_000002_create_delivery_note_billing_marks_table.php:180-182` — the `R2-4` docblock claims a round-trip exactness `toIso8601String()` does not have, and omits the timezone change it *does* make

The docblock asserts: *"A well-formed absolute timestamp keeps its instant and its offset (ISO 8601 is
round-trip exact for the `timestamptz` column), so nothing about the existing backfill's output
changes."* Measured at this tip:

| input | `CarbonImmutable::parse(…)->toIso8601String()` | parsed micros |
|---|---|---|
| `2026-08-12T09:10:11.123456+00:00` | `2026-08-12T09:10:11+00:00` | 123456 |
| `2026-08-12T09:10:11.123456Z` | `2026-08-12T09:10:11+00:00` | 123456 |
| `2026-08-12 09:10:11.987654` | `2026-08-12T09:10:11+00:00` | 987654 |

`toIso8601String()` formats to whole seconds, so sub-second precision is **truncated** — and
`invoiced_at` is a `timestampTz` (`:46`) that would have carried it. Not round-trip exact.

**No live data is affected**, which is why this is Minor and not Important: I traced every producer.
The current writer is `DeliveryNoteBillingClaimService.php:59` `now()->toIso8601String()` (already
second-precision — the new call is a byte-for-byte identity on it), and every pre-branch writer at the
merge base used `now()->toDateTimeString()` (`git grep -n invoiced_at 60df88a01 -- apps/api/app` →
`DeliveryNoteToInvoiceConverter.php:330`, `SalesOrderToInvoiceConverter.php:457`,
`InvoiceController.php:1020`), also second-precision. Nothing in this codebase has ever written a
fractional `invoiced_at`.

The same paragraph also **understates** a real improvement: the legacy `toDateTimeString()` shape
`'2026-08-12 09:10:11'` carries **no offset**, so before this fix PostgreSQL resolved it in the
*session* `TimeZone` while Carbon would have read it in `config('app.timezone')`. Emitting
`+00:00` explicitly removes that ambiguity. That is a behaviour change on real legacy rows and it is
worth a line, not silence.

*Fix:* replace "round-trip exact" with the truthful bound — *"second precision; no producer in this
repository has ever written a fractional `invoiced_at` (legacy `toDateTimeString()`, current
`toIso8601String()`)"* — and add the offset-pinning improvement. Or use `->format('Y-m-d\TH:i:s.uP')`
if sub-second fidelity is wanted.

### R3-4 — **[MINOR]** `apps/api/tests/Feature/Document/DeliveryNoteConsolidationTest.php:187` — the `R2-3` class is left half-closed three lines above the comment that says it should not be

`$this->assertSame(1, Document::query()->where('type', DocumentType::Invoice)->count());` is an
**absolute, global** count in the same test method `R2-3` just scoped — no tenant, company or
document predicate, and `Document` carries no global scope (no `addGlobalScope` / `booted` in
`Document.php`). It is the identical order-dependency shape, and the fix's own comment at `:232-235`
argues *"there is no reason to leave the class half-closed"* while leaving it.

It is **not red today** — I proved that directly with the 139/1045/0 one-process run — because the
forked children's `documents` writes roll back while their `stored_events` writes commit, which is
exactly the asymmetry the r2 register measured (18 orphan `stored_events`, 0 orphan `documents`). So
it is latent, and it depends on an accident of what the concurrency file happens to commit.

For completeness: the sibling counts at `:269-279` / `:294-315` are **not** exposed, because they use
a captured-`$before` delta rather than an absolute, which cancels any pre-existing bleed. Only the
absolute counts (`:187`, `:671`, `:782`) carry the hazard, and `:187` is the one inside the method
this round edited.

*Fix:* add `->where('company_id', $this->company->id)` at `:187` (one predicate), or record why the
absolute form is retained.

### R3-5 — **[MINOR]** the new translation key is unpinned by any test

`DeliveryNoteController.php:301` now emits `__('documents.to_bill_queue.no_active_location_in_scope')`.
The only test that reaches this refusal —
`DeliveryNoteToBillQueueTest::test_a_location_restricted_user_without_an_active_location_is_refused_rather_than_served_the_whole_company`
(`:282-303`) — asserts `assertUnprocessable()` and `assertJsonPath('error.code', 'VALIDATION_ERROR')`
and **never touches the message body**. A mistyped key makes `__()` return the raw key string, an
operator reads `documents.to_bill_queue.no_active_location_in_scope`, and every test stays green.

The key is correct **today** — framework-booted probe at this tip:

```
en  : No active location is available within your allowed scope; select a location explicitly.
fr  : Aucun emplacement actif n'est disponible dans votre périmètre autorisé ; …
ar  : No active location is available within your allowed scope; …     (falls back to en)
```

The `ar` fallback is honestly disclosed at handback `:1166-1170` (`lang/ar/` contains only
`treasury.php` — I confirmed by listing the directory), so that half is recorded, not a defect. Only
the missing assertion is.

*Fix:* one `->assertJsonPath('error.errors.location_id.0', __('documents.to_bill_queue.no_active_location_in_scope'))`
on the existing test.

---

## D. Disposition

| Round-2 finding | Status at `a04b23dc5` | Verified by |
|---|---|---|
| `R2-1` CRITICAL — unguarded `(string)` cast, both keys | **CLOSED** — `is_string` on both; fixtures extended on both keys; divergence pinned and fail-safe on the billing side | code read + 32/350 + 139/1045 |
| `R2-2` IMPORTANT — PG-only regressions gated on SQLite only | **CLOSED** — both files in the PG step as paths, with rationale | ran the exact CI step: 32/350 |
| `R2-3` IMPORTANT — set order-dependent in one process | **CLOSED** — scoping sound, strictly tighter, fails loudly | ran 14 files in ONE process: **139/1045/0** |
| `R2-4` MINOR — two timestamp grammars | **CLOSED** — inserts `$parsed->toIso8601String()`; `'+1 day'` fixture decisive | 5/54 + direct `psql` grammar probe |
| `R2-5` MINOR — silent destructive write, survey counter | **RECORDED, honest, properly parked** — latency independently re-verified | handback `:1140`, YAML `:181`/`:238` + my greps |
| `R2-6` MINOR — weak `>= 500`; `->first()` discarded | **CLOSED** on both halves | 17/127 |

| New finding | Severity |
|---|---|
| `R3-1` — lock alarm flattened to 422; docblock claims the opposite | **IMPORTANT** |
| `R3-2` — handback `:1096` describes the abandoned scoping axis | MINOR |
| `R3-3` — `toIso8601String()` is not round-trip exact; offset change undocumented | MINOR |
| `R3-4` — `:187` absolute global count left in the method `R2-3` scoped | MINOR |
| `R3-5` — new i18n key unpinned by any assertion | MINOR |

**Before merge:** add the `convertOrderToDelivery` rethrow arm on a dedicated exception type, with one
HTTP test asserting 500 — or delete the disposition claim from
`SalesOrderToDeliveryNoteConverter.php:186-188` and record the 422 as deliberate (`R3-1`, blocking).
`R3-2`, `R3-3`, `R3-4`, `R3-5` are record-or-fix at the executor's discretion; `R3-2` and `R3-3` are
both two-line record corrections and this is the terminal gate, so I would take them.

---

**VERDICT: CHANGES-REQUIRED**
