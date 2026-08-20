# Terminal Whole-Branch Gate Register — M5, lens: treasury

**Wave:** `dn-consolidation-build` · **Branch:** `codex/dn-consolidation-2026-08-12`
**Range reviewed:** `60df88a01..8faec0952` (whole branch) · **Tip at review:** `8faec0952`
**Inputs consulted:** `M5-evidence.md`, `M1-round1..3.md`, `M3-round1..2.md`, `M4-round1..3.md`,
`docs/handoff/SPEC-dn-consolidation-billing-2026-08-11.md` §2.3 / §6.1 / §6.2.
**Scratch database:** `autoerp_dn_term` (created for this review; `autoerp_test` never touched).

Everything below was re-derived from code and from runs I executed at this tip. No line number,
count, or property in the M5 evidence was taken on trust. The lock inventory was rebuilt from a
repository-wide search for writers of the billing triple and callers of `DocumentNumberingService`,
not from the evidence's table.

---

## VERDICT

**CHANGES-REQUIRED.** One Critical, six Important, two Minor.

The billed-once guarantee itself — reserve-then-finalise, count-guarded on both representations,
invoice creation strictly inside the claim closure, sequence conservation on every refusal path —
**holds and is proven on real PostgreSQL** (§B, §C). What blocks is not the guarantee: it is that the
wave's **read projection** for the very state the guarantee writes carries an unguarded non-UUID
comparison against a PostgreSQL `uuid` primary key, on data the wave's own migration explicitly
enumerates as existing in the field. It is a hard 500 on `GET /api/v1/delivery-notes`, and it is
invisible to the entire §6.1 registry because that registry runs on SQLite.

---

## A. Spot-run results (dedicated scratch DB, `phpunit-pgsql.xml`)

```bash
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "CREATE DATABASE autoerp_dn_term OWNER autoerp;"
cd apps/api && DB_HOST=127.0.0.1 DB_PORT=5433 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
  DB_DATABASE=autoerp_dn_term DB_CENTRAL_DATABASE=autoerp_dn_term \
  php artisan test -c phpunit-pgsql.xml <paths>
```

| File | Result on PostgreSQL |
|---|---|
| `DeliveryNoteConsolidationConcurrencyTest` | **12 passed, 148 assertions** — matches M5 §1.4 exactly |
| `DeliveryNoteBillingClaimServiceShapeTest` | 3 passed |
| `SalesOrderBillingClaimTest` · `DeliveryNoteBillingMarkerMigrationTest` · `DeliveryNoteConsolidationTest` | all green (part of the 42-passed combined run) |
| `DeliveryNoteBillingClaimServiceTest` | **2 failed / 7 passed** — F-3, F-4 |
| `DeliveryNoteBillingProjectionTest` | **2 failed / 6 passed** — F-1 |

The §6.2 expectation (12/148) is confirmed. The claim-service and projection files were **never run
on PostgreSQL by M5** — §1.1 runs them under the default-SQLite registry and §1.4 PG-runs only the
concurrency file. Four failures appear the moment the production engine is used.

---

## B. Re-derived property 1 — the lock inventory as built

Derivation start points (repository-wide, not the evidence's table):
`grep -rn "invoiced_at\|invoiced_via\|delivery_note_billing_marks" apps/api/app apps/api/database`
and `grep -rn "generateNumber(" apps/api/app`.

**Writers of the billing triple.** Exactly one in `app/`:
`DeliveryNoteBillingClaimService::reserve()` (`:65-76` payload, `:83-89` marker) and `finalise()`
(`:112-115`, `:122-133`). Everything else is a reader: the two DTOs, the two `Document` scopes
(`Document.php:653`, `:664`), the read-only guards in both converters, `DocumentConversionController`
`:473-506`, and the PHPStan rule. The only other writer anywhere is the M1C migration
(`2026_08_18_000002_…:91-97`), outside `app/` and outside the rule's reach — which its docblock states
(`DeliveryNoteBillingWritesOnlyViaClaimService.php:27-46`). Rule registration confirmed at
`apps/api/phpstan.neon:34`. **Three production claim callers** confirmed:
`DeliveryNoteToInvoiceConverter.php:229`/`:283` (consolidation), `SalesOrderToInvoiceConverter.php:290`
(order_conversion), `InvoiceController.php:1017` (pre_post_delivery); `legacy_unknown` refused at
`DeliveryNoteClaimRequest.php:36-37`. **Confirms the evidence.**

**Lock levels.** L0/L1/L2/L3/L4/L5 as the evidence states; I verified each acquisition point and each
"held until" independently, including that `DeliveryNoteFromDocumentFactory` opens no transaction of
its own so its L2 (`DocumentNumberingService.php:50`, a savepoint frame when nested) is held to the
outer commit — PostgreSQL row locks survive `RELEASE SAVEPOINT`. **The SO auto-created-DN branch is
covered**: `SalesOrderToInvoiceConverter.php:204` → `createDeliveryNoteForOrder:763` → factory `:84`,
inside the retrier transaction opened at `:165`. At `60df88a01` that call sat *outside* `DB::transaction`
— the OI-14 defect — and the fix is real.

**Total order and acyclicity — the evidence's claim is FALSE as written.** See **F-5**. The evidence
concludes globally ("no back edge… no deadlock is reachable by construction") from an inventory scoped
to "this lane's path", and the writer it explicitly sets aside — `SalesOrderToDeliveryNoteConverter` —
takes L2 then L1, the exact inversion of the L1→L2 order this branch newly introduced at
`SalesOrderToInvoiceConverter.php:171-178`.

---

## C. Re-derived property 2 — no writer reaches invoice creation before its claims succeed

All seven mechanisms verified against code at tip. Summary of what I confirmed myself:

1. `claim()` is the only public entry (`:40`); `reserve()` `:55` and `finalise()` `:107` are `protected`.
   Pinned by `DeliveryNoteBillingClaimServiceShapeTest:28` (PG-green).
2. `transactionLevel() < 1` refusal at `:44-46`, pinned at `ShapeTest:117` (PG-green).
3. Body order `reserve → $createInvoice($set) → finalise` at `:48-50`; the closure cannot precede the
   reservation.
4. Both invoice-creating lanes pass invoice creation *as that closure* and nothing else, so the only
   `DocumentNumberingService` call on those lanes (`createTargetDocument`, `CopiesDocumentData.php:76`)
   is inside it: `DeliveryNoteToInvoiceConverter.php:236`, `:298`; `SalesOrderToInvoiceConverter.php:237`
   passed at `:296`. **L5 is never taken on a losing claim.**
5. Reservation is a conditional write, not read-then-write (`:66-68` + `:78-80`).
6. Finalise is count-guarded to `$set->count()` on **both** surfaces (`:117-119` marker, `:135-137`
   payload).
7. C-5 is not a counter-example: `DocumentPostingService` never calls `generateNumber` (verified by
   repository-wide grep — it is absent from the caller list), and the claim shares the root transaction
   opened at `InvoiceController.php:921`.

**Integrity invariant** ("no committed row with `invoice_id` NULL and lane != `legacy_unknown`"):
asserted at `DeliveryNoteBillingClaimServiceTest.php:131-134` (that file is PG-red, F-3/F-4) **and** at
`DeliveryNoteConsolidationConcurrencyTest.php:938-942`, which is PG-green — so the invariant is
genuinely proven on the production engine, by the concurrency file rather than the unit file.

**Sequence-number conservation on refusal paths — CONFIRMED.** Every per-row 422 is raised before any
claim and before any `createTargetDocument`: `DeliveryNoteToInvoiceConverter::validateDeliveryNotes`
(`:413-446`, thrown from `:403`, i.e. inside `loadAndValidateDeliveryNotes` at `:135`, before the claim
at `:229`/`:283`) and `SalesOrderToInvoiceConverter::lockCompleteDeliveryNoteSet` (`:675-677`, `:700-702`,
called at `:211`, before the claim at `:290`). `assertArtifactsRolledBack`
(`DeliveryNoteBillingClaimServiceTest.php:331-334`) pins zero `document_sequences` rows and that
assertion path is PG-green. **OI-14's four artefacts**: I read
`SalesOrderBillingClaimTest.php:184-236` line by line; assertions `:223` (no orphan DN), `:224`/`:225`
(neither sequence advanced), `:226` (whole-payload equality), `:227` (`quantity_delivered` unchanged at
`'1.2500'`), `:228` (delivery status unchanged), `:229-236` (`DocumentConverted` stored/audit counts
unchanged) all exist as cited and the test drives the **production entry point**
(`registry()->convert(...)`, `:217`). It is **PG-green**. The evidence's §3 table is accurate.

**Money discipline (rule 19) across all new backend code — CLEAN.** No `(float)`, no `number_format`,
no `round()`. Scale is always resolver-derived with the currency passed explicitly
(`UninvoicedDeliveryNoteService.php:54`, `:122`, `:243`; `DeliveryNoteController.php:320`) — no bare
no-arg `getScale()` was added on any queue/console-reachable path. Aggregation is
`CurrencyScale::bcformatStrict` + `bcadd` at resolver scale (`:154`, `:175`, `:188-189`, `:200`, `:214`),
and the SQL aggregate is forced to a string at the database (`DeliveryNoteController.php:324`
`CAST(COALESCE(SUM(total), 0) AS TEXT)`), which is the right shape — `Query\Builder::sum()` uses
`aggregate()`, not `numericAggregate()`, so nothing floats. Aggregates are **page-invariant**: they are
computed from `clone $query` at `:121`, before `orderBy` (`:125`) and before `paginate`/`cursorPaginate`
(`:128`/`:138`), and are currency-scoped at `:318`. Frontend passes decimal strings straight to
`formatAmount`/`formatMoney` (`ToBillPage.tsx:190`, `:237`, `:264`, `:403`, `:416`); no `parseFloat`,
`Number(...)` or `toFixed` was added anywhere in the branch's web diff.

**Legacy backfill honesty — mostly CONFIRMED.** Counts are per-tenant and logged with the database name
(`…000002:263-269`); dirty rows are neutralised to `invoice_id = NULL` + `legacy_unknown` rather than
aborting (`:87-89`, `:139-149`, `:152-191`); `legacy_unknown` is write-once (runtime refusal at
`DeliveryNoteClaimRequest.php:36-37`, pinned by `DeliveryNoteBillingClaimServiceTest:307`, PG-green);
re-run is a guarded no-op (`:31-39`, pinned at `MarkerMigrationTest:160`). The one uncovered dirty shape
is `invoiced_at` itself — **F-6**.

---

## D. Findings

### F-1 — **[CRITICAL]** `apps/api/app/Modules/Document/Application/DTOs/DocumentData.php:186-191`

```php
$invoicingDocument = null;
if ($billingState->invoice_id !== null) {
    $invoicingDocument = Document::query()
        ->where('tenant_id', $document->tenant_id)
        ->where('company_id', $document->company_id)
        ->find($billingState->invoice_id);   // <- unguarded, PG uuid PK
}
```

**New in this branch** (`git diff 60df88a01..8faec0952 -- …/DocumentData.php` shows this whole block as
added). `$billingState->invoice_id` is `(string) $payload['invoice_id']`
(`DeliveryNoteBillingState.php:21`) — an arbitrary legacy JSON value, not a validated UUID. Comparing it
to the `uuid` primary key raises `SQLSTATE 22P02` on PostgreSQL, which is a **500** and, inside a
transaction, poisons it (`25P02` on every subsequent statement).

Confirmed at the database:

```
autoerp_dn_term=> SELECT id FROM documents WHERE id = 'invoice-consolidation' LIMIT 1;
ERROR:  invalid input syntax for type uuid: "invoice-consolidation"
autoerp_dn_term=> SELECT id FROM documents WHERE id = ' ' LIMIT 1;
ERROR:  invalid input syntax for type uuid: " "
```

Confirmed end-to-end: `DeliveryNoteBillingProjectionTest` is **2/8 red on PostgreSQL** at tip —
`test_uninvoiced_and_invoiced_filters_are_complements_and_match_the_compliance_service_for_all_json_shapes`
and `test_aggregates_are_opt_in_and_page_invariant_over_the_full_filtered_set`. Both die because
`GET /api/v1/delivery-notes` renders a delivery note stamped with the fixture
`'invoice_id' => 'invoice-'.$lane->value` (`DeliveryNoteBillingProjectionTest.php:347`). Both are green
on SQLite, which has no `uuid` type.

**Why it matters, and why "test fixture" is not a defence.** The wave's own migration is *built on the
premise that this data exists in production*: `…000002_create_delivery_note_billing_marks_table.php:161`
rejects `invoice_id` values that are not strings, are blank, or fail `Str::isUuid()`, and counts them as
`unparseable_invoice_id` (`:255-259`); `MarkerMigrationTest:85-100` pins the shapes `'not-a-uuid'`,
`' '`, `123`, `['not' => 'a UUID']`. M1C defends the **marker table** against exactly these rows and
then M1's **projection reads the same raw payload key with no guard**. Every tenant carrying one such
legacy delivery note gets a 500 on the delivery-note list, the DN detail page, the partner
"Delivery notes" tab and the ToBill drill-down — i.e. on the primary read surface of the feature this
wave ships.

This is also the third occurrence of the repository's own ledgered pitfall inside this one wave
(M3-round1 finding 5, M4-round1 finding 2 → `routes.php:49` `whereUuid`). The fix is the same one line:
`Str::isUuid($billingState->invoice_id)` before the lookup — plus a regression that runs on PostgreSQL.

### F-2 — **[IMPORTANT]** Whole-branch verification is SQLite-only; the production engine disagrees

M5 §1.1 discharges the §6.1 registry under the default SQLite connection and §1.4 PG-runs exactly one
file. Running the wave's own new billing tests on PostgreSQL at tip produces **4 failures across 2
files** (§A) that the registry structurally cannot see, one of which is F-1. §5's residual table R2
("Default-SQLite PHPUnit: exactly 2") is true as scoped and is not a false statement — but the scoping
is what hides the defect. Before promotion, the §6.1 registry must be re-run under `phpunit-pgsql.xml`
for at least the wave's own new/changed test files, and the delta recorded.

### F-3 — **[IMPORTANT]** `DeliveryNoteBillingClaimService.php:66`, `:147-152`, `:122-137` — the jsonb merge is not object-safe

`reserve()` merges with `COALESCE(payload,'{}'::jsonb) || jsonb_build_object(...)`. PostgreSQL
concatenating an **object onto an array** yields an array:

```
autoerp_dn_term=> SELECT '[]'::jsonb || jsonb_build_object('invoiced_at','x');
 [{"invoiced_at": "x"}]
```

so `payload->>'invoiced_at'` is NULL again, the original payload is destroyed, and `finalise()`'s
`AND (payload->>'invoiced_at') IS NOT NULL` guard (`:125`) matches **0 rows** →
`DeliveryNoteClaimNotFinalisedException::forPayloadCount`. Reproduced in isolation on PostgreSQL:

```
php artisan test -c phpunit-pgsql.xml …/DeliveryNoteBillingClaimServiceTest.php \
  --filter test_second_claim_loses_the_payload_cas_before_creating_an_invoice
  → Delivery-note payload finalisation affected 0 rows; expected 1.   (at ClaimService.php:136)
```

The trigger is a delivery note whose `payload` is a JSON **array** — the test fixture writes
`'payload' => []` (`DeliveryNoteBillingClaimServiceTest.php:383, :401`), which Eloquent's `array` cast encodes
as `[]`. SQLite's `json_patch` silently replaces the array, so the same test is green there — another
instance of F-2.

I could not reach `payload = '[]'` through any current production writer: `grep -rn "'payload' => \[\]"
app/ database/` is empty, `CreateDocumentRequest` has no `payload` rule, `createTargetDocument`
(`CopiesDocumentData.php:52-94`) omits payload entirely (NULL, which `COALESCE` handles), and
`DeliveryNoteFromDocumentFactory.php:100-102` always writes a non-empty object. So this is **latent, not
live** — but it is undefended, unpinned, and it is the failure mode that presents to an operator as
"this delivery note can never be invoiced", surfaced through F-7 as a routine 422. Add a
`jsonb_typeof(payload) <> 'array'` guard (or a CHECK constraint) and a legacy survey count.

### F-4 — **[IMPORTANT]** `apps/api/tests/Feature/Document/DeliveryNoteBillingClaimServiceTest.php:341-342, 356`

`assertMatchingFinalisedPairs` builds `document.payload->>'invoice_id'` (text) and compares it to
`mark.invoice_id` (uuid) with no cast:

```
SQLSTATE[42883]: operator does not exist: text = uuid
… and document.payload->>'invoice_id' = mark.invoice_id)
```

so the file's flagship proof — *"successful claim reserves in sorted order and finalises matching
pairs"*, the one assertion that checks agreement across **both representations** — has never executed on
PostgreSQL. The concurrency file gets this right (`DeliveryNoteConsolidationConcurrencyTest.php:935`,
`:973-975` use `mark.invoice_id::text`), which is why the invariant is nonetheless proven (§C). Add
`::text` and re-run under `phpunit-pgsql.xml`.

### F-5 — **[IMPORTANT]** Lock-order inversion introduced by this branch; M5 §2.3's acyclicity claim is false as written

- **This branch's invoice lane takes L1 → L2.** `SalesOrderToInvoiceConverter.php:171-178` locks the
  sales-order header immediately after BEGIN; the auto-create branch at `:204` then takes
  `document_sequences[delivery_note]` via `createDeliveryNoteForOrder:763` → factory `:84`. The header
  lock is **new**: `git show 60df88a01:…/SalesOrderToInvoiceConverter.php` has no `lockForUpdate` and
  runs `createDeliveryNoteForOrder` *before* `DB::transaction`.
- **The standalone SO→DN lane takes L2 → L1.** `SalesOrderToDeliveryNoteConverter::performFullDelivery`
  (`:162`) calls `createTargetDocument` first — `CopiesDocumentData.php:76` →
  `DocumentNumberingService.php:50`, i.e. L2 — and only then `appendToSourcePayload` (`:172-176` →
  `CopiesDocumentData.php:234` `$source->update(...)`), which write-locks the sales-order header. Same
  shape in `performPartialDelivery`.

Two concurrent requests on the **same** sales order (convert-to-invoice on an order with physical lines
and no DN yet, vs convert-to-delivery) therefore form a wait-for cycle → PostgreSQL `40P01`. Outcomes:
the invoice lane burns its two retries (`DeliveryNoteBillingConcurrencyRetrier.php:24`, `:72`), then
`SalesOrderToInvoiceConverter.php:328-331` finds no durable winner and `throw $previous` — a **500 with a
raw driver message**; the SO→DN lane has no retrier at all, so `DocumentConversionController` (the
`catch (\Exception)` on `convertOrderToDelivery`) returns a **422 carrying a deadlock string**.

Money-safe — both transactions roll back whole, and billed-once is unaffected. But M5 §2.3's conclusion
("No lane ever takes a lock at a lower level after one at a higher level. There is no back edge, so the
wait-for graph is acyclic and no deadlock is reachable by construction") is **not true of the writer set
that touches these two rows**, and §2.2 dismisses that writer with "it is not on this lane's path" while
§2.3 draws a global conclusion. Either extend the retrier/lock order to the SO→DN converter (lock the
order header first there too) or narrow the claim to the three claim-bearing lanes and record the
inversion as an accepted, bounded 40P01.

### F-6 — **[IMPORTANT]** `…/2026_08_18_000002_create_delivery_note_billing_marks_table.php:91-97` — `invoiced_at` is the one dirty shape that can abort the migration

Every other legacy shape is validated and counted. `'invoiced_at' => $payload['invoiced_at']` is inserted
**raw** into a `NOT NULL timestampTz`. A legacy payload whose `invoiced_at` is truthy but not a
timestamp (`true`, `"yes"`, `""` post-trim, an object) raises `22007`/`22P02` and aborts the migration —
directly contradicting the backfill's own contract that dirty rows never abort, and doing so during
`tenants:migrate`, which this repository auto-runs on every push to `origin/dev`. The gap is unpinned:
`MarkerMigrationTest:339-344` gives every dirty fixture a well-formed
`'2026-08-18T10:11:12+00:00'`. Add an `unparseable_invoiced_at` count that skips the row, and a fixture.

### F-7 — **[IMPORTANT]** The integrity alarm is rendered as a routine 422

`DeliveryNoteClaimNotFinalisedException` and `DeliveryNoteClaimRequiresTransactionException` both extend
`DomainException`, so they fall through to the generic handlers at
`DocumentConversionController.php:107-113` (`ORDER_NOT_CONFIRMED`) and `:341-347`
(`CONSOLIDATION_VALIDATION_FAILED`) — **HTTP 422, indistinguishable from a customer-data refusal**. The
count-guard is the wave's only detector for a broken billed-once invariant; the service docblock says so
itself (`DeliveryNoteBillingClaimService.php:30-33`: *"a committed runtime-lane marker with a null
invoice id is a defect"*). As shipped, that defect produces no 500, no alert, and an operator-facing
message reading *"Delivery-note payload finalisation affected 0 rows; expected 1."* under a validation
error code. This is also exactly how F-3 would present in the field. Both exceptions should be internal
(500 + logged), not `DomainException`.

### F-8 — **[IMPORTANT — inherited, do not block]** the 418 accrual sums mixed currencies into one GL amount

`UninvoicedDeliveryNoteService::calculateUninvoicedTotals` (`:385-413`) bcadds `total` across
`baseUninvoicedQuery` (`:341-365`), which has **no currency predicate**, and
`generateYearEndAdjustment` (`:487-564`) books that single figure as `Dr 418 / Cr 70x`
(`:542-557`). Foreign-currency delivery notes are added to company-currency ones at company scale.
Pre-existing at `60df88a01`, so **not introduced here** — but this branch added the currency filter to
the *new* queue path (`queueQuery:310`) and pinned the divergence with a test
(`test_index_returns_foreign_currency_rows_but_aggregates_only_company_currency_rows`), so the accrual is
now the odd one out and the wave sits directly on top of it. Currently unreachable over HTTP:
`generateYearEndAdjustment` has no route; only `generateYearEndReport` is wired
(`ReportsController.php:160`). Record as an owed GL item before anything exposes the adjustment.

### F-9 — **[MINOR]** `DocumentData.php:186-191` is an N+1 on every list page

One extra `documents` SELECT per stamped delivery note. A 55-row page issues 55 additional queries
(`DeliveryNoteBillingProjectionTest:285` builds exactly that page). Resolve the invoicing documents once
per page.

### F-10 — **[MINOR]** `DeliveryNoteBillingClaimService.php:112-115` — asymmetric company scoping

The marker half of `finalise()` filters on `delivery_note_id` + `invoice_id IS NULL` only, while the
payload half (`:124`) filters on `company_id`. Safe today (marker PK + db-per-tenant + `reserve()`
already company-checked at `:67`), but the two halves of one invariant should carry the same predicate.

---

## E. Cross-milestone seams checked

- **M3 roll-up vs M1 aggregates.** No drift: both are built from `baseUninvoicedQuery` and the roll-up
  layers `queueQuery` (`:301-336`) on top; the list aggregate applies the same currency predicate at
  `DeliveryNoteController.php:318`. The deliberate divergence (queue = company currency only, list rows =
  all currencies) is pinned by `DeliveryNoteBillingProjectionTest:323`. Sound. The **accrual** path is the
  one that does not share the stack — F-8.
- **The uninvoiced filter change.** `getUninvoicedDeliveryNotes` moved from
  `whereNull('payload->invoiced_at') OR whereJsonContains(payload, ['invoiced_at' => null])` to
  `whereDeliveryNoteUninvoiced()`. I dumped the compiled SQL at this tip: `"payload"->>'invoiced_at' is
  null`, which subsumes both old branches. Equivalent, and pinned by
  `DeliveryNoteBillingProjectionTest:189` (which is however F-1-red on PostgreSQL).
- **C-5 vs the claim order.** `InvoiceController.php:921-1024` claims *after* `post()`, which is safe
  only because that lane creates no invoice and consumes no invoice number. I verified independently
  that `DocumentPostingService` is absent from the repository-wide `generateNumber(` caller list, and
  that the claim, the DN creation, the confirm and the post all share the root transaction opened at
  `:921`. The C-5 rewrite (direct payload write → claim service) is correct. One residual: a
  `DeliveryNoteAlreadyClaimedException` from `:1017` has no `catch` in that method and would 500; it is
  unreachable in practice (the DN is created in the same transaction, so neither the payload CAS nor the
  marker PK can collide), so it is recorded, not raised as a finding.
- **Routes/authz.** `module:Sales` + `can:deliveries.view` on both new queue endpoints, `whereUuid` on
  `{partner}`, and `/delivery-notes/uninvoiced` declared before `/delivery-notes/{deliveryNote}`
  (`routes.php:275-287`) so there is no shadowing. Correct.

---

## F. What must change before merge

1. **F-1** — guard `DocumentData.php:186-191` with `Str::isUuid()` and add a regression that runs under
   `phpunit-pgsql.xml`. This alone blocks.
2. **F-2** — re-run the wave's own new/changed backend test files under `phpunit-pgsql.xml` and record
   the result; the SQLite registry is not evidence for a PostgreSQL product.
3. **F-3, F-4, F-6, F-7** — fix or explicitly record with owner sign-off.
4. **F-5** — either extend the lock order to `SalesOrderToDeliveryNoteConverter` or correct M5 §2.3's
   acyclicity claim to match the writer set it actually covers.
5. **F-8, F-9, F-10** — record; F-8 gates any future exposure of the year-end adjustment.

---

**VERDICT: CHANGES-REQUIRED**
