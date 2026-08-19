# TERMINAL re-review register — M5 round 2, lens: **tenancy-authz**

**Wave:** `dn-consolidation-build` · **Branch:** `codex/dn-consolidation-2026-08-12`
**Delta reviewed:** `4df4b68ec..b6091a74a` (8 commits, 17 files) · **Tip:** `b6091a74a`
**Round 1:** `M5-terminal-tenancy-authz.md` (CHANGES-REQUIRED — `F-T1` Critical, `F-T2`/`F-T3`
Important, `F-T4`/`F-T5` Minor).
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/dn-consolidation` (read-only;
this register is the only file I commit).

Everything below was re-derived at `b6091a74a` by reading the files and by executing code. Every
"closed" label was proven by a run, not accepted from the handback. Probes ran on a dedicated
PostgreSQL scratch database `autoerp_dn_tz2` (the shared `autoerp_test` was never touched); probe
classes lived in the session scratchpad, never in the repo, and `git status` is clean.

---

## VERDICT SUMMARY

**CHANGES-REQUIRED**, on **one Critical**: the `F-T1` fix is **incomplete**, and I can reproduce the
exact failure it was raised for — `GET /delivery-notes` (the whole list) and
`GET /delivery-notes/{id}` still return **500** on a dirty payload shape. `F-T2`, `F-T3` and
`F-T4` are genuinely closed and verified by live probe; `F-T5` is correctly dispositioned as
recorded-not-fixed.

---

## A. What I executed

Scratch database:

```bash
PGPASSWORD=… psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres \
  -c "DROP DATABASE IF EXISTS autoerp_dn_tz2;" -c "CREATE DATABASE autoerp_dn_tz2 OWNER autoerp;"
```

| Run (all `DB_DATABASE=autoerp_dn_tz2 DB_CENTRAL_DATABASE=autoerp_dn_tz2 … -c phpunit-pgsql.xml`) | Result |
|---|---|
| `tests/Feature/Document/DeliveryNoteToBillQueueTest.php` | **6 passed / 57 assertions** |
| `tests/Feature/Document/DeliveryNoteBillingProjectionTest.php` | **9 passed / 93 assertions** |
| `tests/Feature/Document/DeliveryNoteConsolidationAccessControlTest.php` + `DeliveryNoteBillingMarkerMigrationTest.php` | **11 passed / 99 assertions** |
| `tests/Feature/Document/DeliveryNoteBillingClaimServiceTest.php` + `SalesOrderBillingClaimTest.php` | **26 passed / 199 assertions** |
| Scratchpad probe A — non-scalar `payload.invoice_id` / `payload.invoiced_at` over HTTP | **3 of 4 shapes 500; the list 500s** → `F-R2-1` |
| Scratchpad probe B — four location/entitlement shapes on the queue | confirms `F-T3` closed **and** the central-invoicing shape unharmed |

All touched test files pass on PostgreSQL. The defect below is one the wave's own new test does not
cover.

---

## B. My three fix-before-merge items — verified one by one

### B.1 `F-T1` (Critical) — **NOT fully closed.** See `F-R2-1`.

The `Str::isUuid()` guard is in place and correct as far as it goes
(`DocumentData.php:198`, import at `:10`), and the regression test
`DeliveryNoteBillingProjectionTest::test_a_non_uuid_payload_invoice_id_reads_as_unresolved_instead_of_500ing_the_list_and_detail_surfaces`
(`:256-300`) is a real test that exercises the list, the detail **and** the invoiced/uninvoiced
complement over three dirty shapes. It passes on PostgreSQL (9/9, 93 assertions).

But the guard is downstream of an unguarded `(string)` cast, so a whole class of dirty payload still
500s. Proven live — see `F-R2-1`.

**Adversarial sweep asked for: can any OTHER consumer of `DeliveryNoteBillingState->invoice_id`
reach a raw uuid binding? — NO.** `grep -rn "DeliveryNoteBillingState\|fromPayload" apps/api/app`
returns exactly one caller of `fromPayload`: `DocumentData.php:141`. The only binding was
`DocumentData.php:202`, now guarded. The three other places a `payload['invoice_id']` is touched are
PHP-side **string comparisons**, never bindings:
`SalesOrderToInvoiceConverter.php:372` and `DeliveryNoteToInvoiceConverter.php:210`
(`(string) ($payload['invoice_id'] ?? '') !== (string) $winner->winner_invoice_id`), and
`DeliveryNoteBillingClaimService.php:62` (an `unset`). The two winner-join reads
(`SalesOrderToInvoiceConverter.php:346`, `DeliveryNoteToInvoiceConverter.php:184`) join on
`delivery_note_billing_marks.invoice_id`, a typed **column**, not a payload value. Clean.

**The FE-degradation claim in the record is ACCURATE.** `DeliveryNoteBillingStatus.tsx:49` —
`if (invoiceId === null || invoiceNumber === null) return badge` — so an unresolvable id renders the
lane badge alone with no `<Link>`. No dead link is introduced. Verified, not assumed.

### B.2 `F-T2` (Important) — **CLOSED, verified.**

`DeliveryNoteController.php:330-332` adds `$request->validate(['location_id' => ['nullable','uuid']])`
immediately before the raw binding at `:336`. Covered by
`DeliveryNoteToBillQueueTest::test_the_index_location_filter_refuses_a_malformed_uuid_instead_of_500ing`
(`:260-269`), which asserts **both** halves — 422 with
`error.errors.location_id.0` on garbage, and 200 on a valid location uuid. Passes on PostgreSQL.

**Filter-precedence check (the literal `all` path) — UNHARMED, and the reason is structural.**
`grep -rn "applyDeliveryNoteFilters" apps/api/app apps/api/tests` returns exactly two hits: the
definition (`:314`) and the single call site inside `index()` (`:118`). The queue endpoints
(`uninvoiced` `:169`, `uninvoicedForPartner` `:197`) build their filters through `toBillFilters()`,
which carries its **own** conditional rule that suppresses `uuid` when the value is the literal
`'all'` (`:237-240`) and never routes through `applyDeliveryNoteFilters`. So the new unconditional
`uuid` rule cannot reach the `all` sentinel. Confirmed empirically: the four pre-existing `all`
assertions in `test_location_and_filter_validation_never_silently_widens_the_queue` still pass.
`GET /delivery-notes` itself has no `all` semantics and no FE caller sends it —
`grep -rn "location_id" apps/web/src` shows no delivery-note caller passing the parameter at all;
`ToBillPage.tsx:319` (`locationId: allLocations ? 'all' : currentLocationId`) targets the **queue**
hook, not the index.

### B.3 `F-T3` (Important) — **CLOSED, and the legitimate central-invoicing shape is verified unharmed.**

`DeliveryNoteController.php:277-295` adds the `elseif (! $canViewAllLocations)` arm that raises 422
instead of silently widening. The in-repo test (`DeliveryNoteToBillQueueTest.php:281-303`) covers the
refusal and the unrestricted 200.

I did not take that on trust — the specific question was whether an **unrestricted** user
(`can_view_all_locations: true`) with no active location still gets the whole-company central-invoicing
shape. Live probe on PostgreSQL, one company, two locations, three confirmed DNs (Tunis, Sfax, and one
with `location_id = NULL`):

| Shape | `allowed_location_ids` | active locations | Result |
|---|---|---|---|
| 1 — central invoicing | `null` (unrestricted) | none | **200**, `delivery_note_count: 3`, `total: "30.000"` — the whole company, **including the NULL-location DN**. Unharmed. |
| 2 — the fix | `[Tunis]` | none | **422** `"No active location is available within your allowed scope; select a location explicitly."` |
| 3 — pre-existing | `[Tunis]` | Sfax active + default | **422** `"The selected location is invalid or outside your allowed scope."` (the `canAccessLocation` arm at `:272-276`, unchanged by this round) |
| 4 — narrowing still works | `null` | Sfax active + default | **200**, `delivery_note_count: 1` — scoped to the default location, not the company |

Shapes 1 and 4 prove the fix is correctly narrow: it fires **only** when `resolveLocationId()` returns
null AND the user is restricted, i.e. when there is genuinely nothing in scope to serve. The
`LocationContext` mechanics I asserted in round 1 re-verified at tip: `getDefaultLocation` filters
`is_active` on both lookups (`LocationContext.php:102-105`, `:112-115`); `getAllowedLocationIds`
returns `[]` (fail-closed, i.e. restricted) for a user with no active membership (`:198-200`).

### B.4 `F-10` / `F-T4` — **CLOSED, verified.**

`DeliveryNoteBillingClaimService.php:119` adds `->where('company_id', $set->companyId)` to the
finalise marker UPDATE, mirroring the payload half at `:130/:137`. `$set->companyId` is populated in
`reserve()` from `$request->companyId` (`:99-104`) — the same value the reserve CAS already predicates
on (`:67`, `:74`) — so the two halves now carry identical scope. Both claim test files pass on
PostgreSQL (26/26, 199 assertions), including the rollback and short-finalise paths.

### B.5 The two MINORs — **correctly dispositioned.**

- `F-T4` → fixed (above), with an honest comment at `:112-116` that keeps my round-1 framing
  ("defence in depth rather than a live hole — `delivery_note_id` is the marker table's primary key").
  No overstatement.
- `F-T5` (`protected readonly` vs rule 13) → **recorded, not changed**, with the rationale and the
  two subclass citations intact, in `progress.yaml` (`recorded_not_fixed: [F-8, F-9, F-T5]` plus the
  narrative finding entry) and in the handback. That is the disposition I asked for.

---

## C. Findings (delta only)

### `F-R2-1` — **CRITICAL** — `apps/api/app/Modules/Document/Application/DTOs/DeliveryNoteBillingState.php:20-21` — the `F-T1` fix guards the *lookup* but not the *cast*; a non-scalar `payload.invoice_id` or `payload.invoiced_at` still 500s the whole delivery-note list

```php
$invoicedAt = isset($payload['invoiced_at']) ? (string) $payload['invoiced_at'] : null;   // :20
$invoiceId  = isset($payload['invoice_id'])  ? (string) $payload['invoice_id']  : null;   // :21
$invoicedVia = isset($payload['invoiced_via']) && is_string($payload['invoiced_via'])     // :22 ← guarded
```

`documents.payload` is cast `'array'` (`Document.php:195`), so a nested JSON object or array decodes
to a PHP array. `(string) $array` raises `E_WARNING`, which Laravel's `HandleExceptions` converts to
an `ErrorException` — a **500**, before `Str::isUuid()` at `DocumentData.php:198` is ever reached.
Note `invoiced_via` at `:22` **is** `is_string`-guarded: the pattern was known and applied to one of
three fields.

**Proven live at tip** (scratchpad PG probe on `autoerp_dn_tz2`, four DNs, one dirty shape each):

```
[PROBE] DN-ARRAY-OBJ   detail status=500   payload.invoice_id  = {"nested":"object"}
[PROBE] DN-ARRAY-LIST  detail status=500   payload.invoice_id  = ["a","b"]
[PROBE] DN-BOOL        detail status=200   payload.invoice_id  = true          (degrades honestly)
[PROBE] DN-AT-ARRAY    detail status=500   payload.invoiced_at = {"x":"y"}
[PROBE] LIST           status=500          GET /api/v1/delivery-notes?page=1
```

`storage/logs/laravel.log` confirms the class and the exact lines:

```
testing.ERROR: Array to string conversion
  ErrorException: … DTOs/DeliveryNoteBillingState.php:21   (invoice_id)
  ErrorException: … DTOs/DeliveryNoteBillingState.php:20   (invoiced_at)
```

**Why this is Critical and not a residual.** It is the *same* defect as `F-T1`, on the *same*
surface, with the *same* blast radius: `DeliveryNoteController::index` maps every row through
`DocumentData::fromModel` (`:131` offset branch, `:141` cursor branch), so **one** dirty legacy row
takes out the entire delivery-note list for **every** user in that tenant. `invoiced_at` (`:20`) is a
second, independent ingress with the identical blast radius, and it is **not** guarded by anything
this round added.

**This is the branch's own code, not inherited.** `DeliveryNoteBillingState.php` is a new file in the
diff (`git diff 60df88a01..b6091a74a` shows it as `new file mode`), and
`git show 60df88a01:…/DocumentData.php` contains **no** read of `payload['invoiced_at']` or
`payload['invoice_id']` at all. The wave introduced this ingress.

**The "mirrors the migration" claim is an overstatement — the migration guards `is_string` first.**
The fix comment (`DocumentData.php:193`, *"Mirror that contract here"*), the handback
(*"The fix mirrors the migration's own semantics"*) and `progress.yaml`
(*"mirroring the migration's safeInvoiceId semantics"*) all claim parity with
`2026_08_18_000002_create_delivery_note_billing_marks_table.php`. That migration's actual guards are:

```php
if (! is_string($invoicedAt) || trim($invoicedAt) === '') { …unparseable_invoiced_at… }   // :177
if (! is_string($invoiceId) || trim($invoiceId) === '' || ! Str::isUuid($invoiceId)) { … } // :204
```

`! is_string(...)` comes **first**, precisely because the author knew the value need not be a string.
The DTO implements only the third clause. Parity is claimed, not achieved.

**Promotion consequence — this invalidates the round's own downgrade of the OI-12 gate.** The
handback now states: *"With F-1 fixed, a non-zero count no longer 500s the list."* That is false for
this subset. Worse, the migration folds **all three** rejection reasons into the single counter
`unparseable_invoice_id` (`:205`), so a promoter reading a non-zero count off the staging
`tenants:migrate` log **cannot tell** whether a tenant carries the still-fatal non-scalar shape or
the now-safe non-uuid-string shape. Until `F-R2-1` is fixed, my round-1 escalation stands unchanged:
`unparseable_invoice_id > 0` on any tenant is a **blocking** pre-promotion check, not a survey.

**Suggested fix (3 lines, in the DTO where the class belongs):**

```php
$invoicedAt = isset($payload['invoiced_at']) && is_string($payload['invoiced_at'])
    ? $payload['invoiced_at'] : null;
$invoiceId = isset($payload['invoice_id']) && is_string($payload['invoice_id'])
    ? $payload['invoice_id'] : null;
```

and extend `test_a_non_uuid_payload_invoice_id_reads_as_unresolved_…` with a nested-array
`invoice_id` **and** a nested-array `invoiced_at`, asserting 200 on the list and the detail. Then
correct the three "mirrors the migration" claims and restore the OI-12 wording.

---

### `F-R2-2` — **MINOR** — `DeliveryNoteController.php:293` — the new refusal ships a hardcoded English operator-facing message

```php
'location_id' => ['No active location is available within your allowed scope; select a location explicitly.'],
```

Same class as the already-recorded M3 residual at `:274` (*"hardcoded English validation message at
DeliveryNoteController.php:274"*, `progress.yaml` findings). It is now **user-visible**: `ToBillPage`
renders query failures through `<QueryError error={query.error} …>` (`:511-512`), so a
location-restricted user in a company with no active location sees raw English in a French or Arabic
UI. Add it to the same i18n follow-up as `:274` rather than creating a third instance.

### `F-R2-3` — **MINOR** — `SalesOrderToDeliveryNoteConverter.php:182-191` — `lockOrderHeader()` discards its result, so a predicate miss silently takes no lock

```php
Document::query()
    ->where('tenant_id', $order->tenant_id)->where('company_id', $order->company_id)
    ->where('id', $order->id)->where('type', DocumentType::SalesOrder->value)
    ->lockForUpdate()
    ->first();          // :190 — return value discarded
```

If any predicate fails to match, the statement locks **nothing** and the converter proceeds — silently
reinstating the exact L1↔L2 back edge the `F-5` fix removed, with no exception and no signal. The
docblock states the lock is "taken for its ordering effect only", which is fine, but an ordering
guarantee that can silently not happen is not a guarantee. `if ($locked === null) throw` (or
`->firstOrFail()`) makes the invariant load-bearing. The new PG-gated assertion
(`SalesOrderBillingClaimTest::test_delivery_note_conversion_locks_the_order_header_before_the_delivery_note_sequence`)
only proves the ORDER when the lock *does* fire, so it cannot catch this.

### `F-R2-4` — **MINOR** — `apps/web/src/features/documents/to-bill/ToBillPage.tsx:427-432` — the scope comment is now stale

> *"with no active location the backend falls back to the company default (or to every location when
> none exists)"*

After `F-T3` the second clause holds **only** for `can_view_all_locations: true`. For a
location-restricted user the backend now returns 422. One-line comment correction; the rendering
logic itself is correct.

---

## D. Residuals I looked at and am *not* raising as delta defects

- **`partner_id` and `product_id` on the SAME request path are still raw uuid bindings.**
  `Concerns/HandlesDocuments.php:155` (`$query->where('partner_id', $partnerId)`) and `:181`
  (`$q->where('product_id', $productId)`) take unvalidated query strings, and `index()` calls
  `applyFilters` at `:117` — one line **before** the `F-T2` guard at `:118`. So
  `GET /api/v1/delivery-notes?partner_id=not-a-uuid` is still a 500 for any `deliveries.view` holder.
  **Not a delta defect:** `git diff --name-only 60df88a01..b6091a74a` does not contain
  `HandlesDocuments.php` — the file is untouched by the whole branch and the trait is shared by every
  document controller. Recorded so nobody reads `F-T2` as "the uuid class is closed on this endpoint";
  closing it belongs in a repo-wide lane.
- **A location-restricted user whose company default is not theirs gets 422 on the implicit queue
  path** (probe shape 3). That arm (`:272-276`) predates this round (M3) and this round did not touch
  it. Worth an owner note — the FE has no location selector on `/sales/to-bill` and
  `ToBillPage.tsx:319` sends `currentLocationId` straight from the persisted store — but not this
  delta's defect.
- **`invoiced_by_document_id` still emits the raw unparseable string to the FE**
  (`DocumentData.php:269`). Deliberate and safe: `DeliveryNoteBillingStatus.tsx:49` suppresses the
  `<Link>` whenever `invoiceNumber` is null, and `documents.show` carries `whereUuid` (`routes.php:49`),
  so the worst case is a 404, never a 500.

---

## E. Re-verified GREEN at tip (the designed surface, re-run not re-quoted)

| Charge | Result | Evidence |
|---|---|---|
| Role matrix + both-layer gating survive the fix round | **PASS** | `DeliveryNoteConsolidationAccessControlTest` 6 passed / 48 assertions on PostgreSQL, incl. both module-off 403 arms and the foreign-tenant/foreign-company exclusion |
| Rule 12 middleware chain | **PASS** | no `routes.php` in the delta (`git diff --stat 4df4b68ec..b6091a74a`); the chain at `Document/Presentation/routes.php:33` is unchanged |
| No new module gate / no `verticals.php` drift | **PASS** | zero gating changes in the delta |
| Rule 19 / rule 20 / PG-uuid pitfalls on added lines | **PASS** | `git diff 4df4b68ec..b6091a74a \| grep '^+'` matches **zero** of `(float)`, `floatval`, `parseFloat`, `Number(`, `latestOfMany`, `ofMany(`, `app(`, bare `getScale()`, `onQueue`, `ShouldQueue`, `dispatch(` |
| Integrity alarms escape every `DomainException` handler on **both** claim entry points | **PASS** | `DocumentConversionController` — `convertOrderToInvoice` (`:62`) has the explicit rethrow at `:84` ahead of its catch-all `catch (\Exception)` at `:126`; `createInvoiceFromDeliveryNotes` (`:283`) needs none, its arms end at `\InvalidArgumentException` (`:345`) / `\DomainException` (`:352`) and `RuntimeException` is neither. `InvoiceController` has no `catch (\Exception)` at all (four `\DomainException` arms only). Pinned by `SalesOrderBillingClaimTest::test_a_broken_billed_once_invariant_surfaces_as_a_server_error_not_a_validation_refusal` |
| Tenant/company discipline on the two changed queries | **PASS** | finalise marker UPDATE now `company_id`-predicated (`:119`); `lockOrderHeader` carries `tenant_id` + `company_id` + `id` + `type` (`:184-187`) |
| Test quality of the six added tests | **PASS** | all assert real behaviour on real models with `RefreshDatabase` + `RolesAndPermissionsSeeder`; `F-T3`'s test exercises the DENY path *and* the allow path; the one container substitution (`SalesOrderBillingClaimTest.php:653`) replaces a **collaborator** to test the controller's rendering, not the thing under test; the `F-5` assertion is correctly PostgreSQL-gated because SQLite compiles `lockForUpdate()` to nothing |

---

## F. Disposition

| Id | Sev | Status | One-liner |
|---|---|---|---|
| `F-T1` | **Critical** | **PARTIALLY CLOSED → `F-R2-1`** | `Str::isUuid()` guard correct at `DocumentData.php:198`, but the upstream `(string)` cast is unguarded |
| `F-T2` | Important | **CLOSED** | `location_id` `['nullable','uuid']` at `:330-332`; 422 on garbage, 200 on valid, `all` path structurally untouched |
| `F-T3` | Important | **CLOSED** | 422 instead of silent widening at `:277-295`; central-invoicing shape for an unrestricted user verified unharmed by live probe |
| `F-T4` | Minor | **CLOSED** | `company_id` predicate at `:119` |
| `F-T5` | Minor | **RECORDED, correct** | `protected readonly` deliberate, both subclass citations carried |
| `F-R2-1` | **Critical** | **NEW** | `DeliveryNoteBillingState.php:20-21` — unguarded `(string)` cast; non-scalar `invoice_id`/`invoiced_at` 500s the DN detail **and** the whole DN list. Branch-introduced. The three "mirrors the migration" claims are overstated (`safeInvoiceId:204` / `safeInvoicedAt:177` guard `is_string` first), and `unparseable_invoice_id` conflates the fatal and non-fatal subsets, so the OI-12 downgrade is unsupported |
| `F-R2-2` | Minor | NEW | `DeliveryNoteController.php:293` — hardcoded English message on a now user-visible path |
| `F-R2-3` | Minor | NEW | `SalesOrderToDeliveryNoteConverter.php:190` — `lockOrderHeader()` discards `first()`; a predicate miss silently skips the lock |
| `F-R2-4` | Minor | NEW | `ToBillPage.tsx:427-432` — scope comment stale after `F-T3` |

**What to fix before merge:** guard `DeliveryNoteBillingState::fromPayload` with `is_string()` on
**both** `invoiced_at` (`:20`) and `invoice_id` (`:21`) — full parity with the migration's
`safeInvoicedAt`/`safeInvoiceId`, not just the `Str::isUuid` clause — extend the dirty-payload test
with a nested-array shape on the list and the detail, and correct the three "mirrors the migration"
claims plus the OI-12 wording that was downgraded on the strength of the incomplete fix.

**VERDICT: CHANGES-REQUIRED**
