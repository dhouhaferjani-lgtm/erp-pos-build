# TERMINAL whole-branch gate register — M5, lens: **tenancy-authz**

**Wave:** `dn-consolidation-build` · **Branch:** `codex/dn-consolidation-2026-08-12`
**Whole-branch range:** `60df88a01..8faec0952` (100 code/doc files; `8faec0952` was the tip when this
register was written, `git status` clean apart from a sibling reviewer's untracked register).
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/dn-consolidation` (read-only;
this register is the only file I commit).
**Inputs read:** `M5-evidence.md`, `M1-round1..3`, `M2-round1..2`, `M3-round1..2`, `M4-round3`,
SPEC `§2.2` (role matrix) / `§2.3` (Layer 0 / 0a claim protocol),
`CODEX-DISPATCH-dn-consolidation-build-2026-08-12.md` OI-13,
`docs/handoff/progress/dn-consolidation-build.progress.yaml`, `HANDBACK-…-2026-08-12.md`.

**Everything below was re-derived at `8faec0952` by reading the files and by executing code.** No
line number, no verdict and no "already dispositioned" label was taken on trust from an earlier
round. Three findings are proven by **live HTTP probes on a dedicated PostgreSQL scratch database**
(`autoerp_dn_tz` — the shared `autoerp_test` was never touched; probe files lived in the session
scratchpad, never in the repo, and `git status` is clean).

---

## VERDICT SUMMARY

**CHANGES-REQUIRED**, on one **Critical** defect (`F-T1`) that this lens can prove takes a
production read path to 500 on data the wave's own migration documents as existing, plus two
**Important** findings. The wave's *designed* authz surface — role matrix, both-layer module gating,
claim-protocol encapsulation, tenant/company predicates, cross-company attribution — is **correct and
verified green**; the defects are all in the *unguarded edges* around it.

---

## A. What I executed (not quoted from M5)

Scratch database created for this audit and used for every PostgreSQL run:

```bash
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres \
  -c "DROP DATABASE IF EXISTS autoerp_dn_tz;" -c "CREATE DATABASE autoerp_dn_tz OWNER autoerp;"
```

| Run | Command | Result |
|---|---|---|
| Role matrix, PostgreSQL | `DB_DATABASE=autoerp_dn_tz DB_CENTRAL_DATABASE=autoerp_dn_tz php artisan test -c phpunit-pgsql.xml tests/Feature/Document/DeliveryNoteConsolidationAccessControlTest.php` | **6 passed, 48 assertions**, 23.41s, EXIT 0 |
| FE gate tests | `pnpm vitest run --maxWorkers=1 src/routes/ToBillRoute.gates.test.tsx src/routes/DeliveryNoteConsolidationRoute.retired.test.tsx src/features/partners/PartnerDetailPage.deliveryNotes.gates.test.tsx src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx` | **4 files / 55 tests passed** |
| 3 adversarial HTTP probes (scratchpad-only test classes, PG) | see §C | 3 defects, 1 self-retraction |

---

## B. The role matrix, end to end at tip — **PASS**

`DeliveryNoteConsolidationAccessControlTest.php` is a real feature test (`RefreshDatabase`, real
models, `RolesAndPermissionsSeeder` at `:65`), it exercises the **deny** path, and its expectations
match SPEC §2.2 row for row.

| Surface | Roles asserted | Result |
|---|---|---|
| `GET /delivery-notes/uninvoiced` | operator, cashier, manager, viewer, accountant | 200 for all five (`:82-84`) |
| `GET /delivery-notes/uninvoiced/{partner}` | same five | 200 for all five (`:86-88`) |
| `POST /delivery-notes/consolidate-to-invoice` | same five | **201** for operator/cashier/manager, **403** for viewer/accountant (`:94-98`) |

Deny paths that are genuinely independent, not one combined test:

- module off + permission present → 403 on **GET** (`:102-111`) and on **POST** (`:145-153`);
- permission absent + module on → 403 on both GETs (`:113-121`, actor built with `invoices.create`
  only at `:115`);
- foreign tenant + foreign company rows excluded from the queue (`:155-200`).

**The `accountant` grant is a BASE dependency, not this branch's.** `deliveries.view` sits in the
accountant block at `RolesAndPermissionsSeeder.php:798-801` under the comment *"Accountant read
access ruled 2026-08-12 (OI-1a …) … pre-landed by the parent to clear the DN lane's F-1 gate"*, and
`git diff --name-only 60df88a01..8faec0952 -- apps/api/database/seeders/` is **empty** — the grant
arrived at `d682b38ec`, before the pinned base. Consequence for promotion, and it is the classic
silent-403 trap: **every tenant provisioned before `d682b38ec` lacks `deliveries.view` on
`accountant` and will 403 on the new queue until `RolesAndPermissionsSeeder` is re-run and
`permission:cache-reset` is issued.** This is inside D-6's scope (`progress.yaml:71-73`) but D-6 is
phrased as generic "P0 migrations + seeder + cache-reset"; the accountant row is the concrete thing
that breaks if the seeder step is skipped.

---

## C. Findings

### `F-T1` — **CRITICAL** — `apps/api/app/Modules/Document/Application/DTOs/DocumentData.php:185-190` — an unvalidated JSONB value is bound into a PostgreSQL `uuid` key, and it takes out the whole delivery-note list

**What's wrong.** The new billing projection resolves the taking invoice with

```php
$invoicingDocument = null;
if ($billingState->invoice_id !== null) {
    $invoicingDocument = Document::query()
        ->where('tenant_id', $document->tenant_id)
        ->where('company_id', $document->company_id)
        ->find($billingState->invoice_id);          // DocumentData.php:190
}
```

`$billingState->invoice_id` comes from `DeliveryNoteBillingState::fromPayload()`
(`DeliveryNoteBillingState.php:21`), which is a bare
`isset($payload['invoice_id']) ? (string) $payload['invoice_id'] : null` — **no `Str::isUuid()`
guard**. `documents.payload` is free-form JSONB. There is no route-parameter constraint in front of
it, because the value is not a route parameter.

**Why it matters — this is not hypothetical, the wave's own migration proves the row shape exists.**
`database/migrations/tenant/2026_08_18_000002_create_delivery_note_billing_marks_table.php`
guards the *same* value before *its* lookup (`Str::isUuid($invoiceId)` in `safeInvoiceId()`) and
counts the rejects into a dedicated survey counter `unparseable_invoice_id` (`newCounts()`), which
OI-12 requires the promoter to read out of the staging `tenants:migrate` log. The migration writes
`invoice_id = NULL` in the **marker table** for such rows but **deliberately leaves the dirty
`documents.payload.invoice_id` in place** — and the new DTO reads the payload, not the marker.

**Proven live at tip** (scratchpad PG probe, `autoerp_dn_tz`, one confirmed DN with
`payload = {invoiced_at: …, invoice_id: 'INV-2024-001', invoiced_via: 'consolidation'}`):

```
[A] GET /api/v1/delivery-notes/{dirty}                  → 500
    SQLSTATE[22P02]: invalid input syntax for type uuid: "INV-2024-001"
[B] GET /api/v1/delivery-notes            (list, run alone in a clean transaction) → 500
    SQLSTATE[22P02]: invalid input syntax for type uuid: "INV-2024-001"
```

[B] is the severe half: `DeliveryNoteController::index` maps **every** row through
`DocumentData::fromModel(...)` (`DeliveryNoteController.php:136-138` for the offset branch and
`:154` for the cursor branch), so **one** dirty legacy delivery note 500s the entire delivery-note
list for **every** user in that tenant. `DocumentData` is the shared document DTO, so the blast
radius is any document surface that renders a delivery note.

**Cross-milestone seam** — this is exactly the class of defect a per-milestone delta review cannot
see: **M1** added the DTO field, **M1C** added the migration whose counter proves the dirty shape,
and no round joined the two. `DocumentShowRouteUuidConstraintTest` (added by this wave) hardens the
route *parameter* and gives the false impression the uuid class is covered; the payload value is a
different ingress.

**Suggested fix.** Guard before the lookup, mirroring the migration's own `safeInvoiceId()`:

```php
if ($billingState->invoice_id !== null && Str::isUuid($billingState->invoice_id)) { … }
```

and add a feature test that puts a non-UUID `payload.invoice_id` on a DN and asserts **200** on both
`GET /delivery-notes/{id}` and `GET /delivery-notes`.

**Promotion linkage:** if the staging OI-12 survey reports `unparseable_invoice_id > 0` on any
tenant, that tenant's delivery-note list is dead the moment the FE ships. The OI-12 counts stop being
an informational survey and become a **blocking pre-promotion check**.

---

### `F-T2` — **IMPORTANT** — `apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php:304-307` — the new `location_id` list filter is the one uuid ingress this wave left unguarded

```php
$locationId = $request->query('location_id');          // :304
if (is_string($locationId) && $locationId !== '') {
    $query->where('location_id', $locationId);         // :306  ← raw query string → PG uuid column
}
```

No validation, no `uuid` rule, no `Str::isUuid()`. Proven live:

```
GET /api/v1/delivery-notes?location_id=not-a-uuid  → 500
   SQLSTATE[22P02]: invalid input syntax for type uuid: "not-a-uuid"
```

Reachable by any authenticated holder of `can:deliveries.view` (`routes.php:272`).

**Why it matters:** the same wave fixed precisely this class three times elsewhere — the conditional
`uuid` rule on the queue filter (`DeliveryNoteController.php:237-240`, M3 finding 5) and
`->whereUuid(...)` on `documents.show` (`routes.php:49`), `delivery-notes.show` (`:285`) and
`uninvoiced/{partner}` (`:280`) — and missed the filter it added itself in the same commit range.
`DeliveryNoteToBillQueueTest::test_location_and_filter_validation_never_silently_widens_the_queue`
(`:235-238`) asserts the 422 on the *queue* endpoint only; nothing covers the index filter.

**Fix:** add `'location_id' => ['nullable','uuid']` validation (or an `Str::isUuid()` early return)
on `index`, and extend the existing test to the index route.

---

### `F-T3` — **IMPORTANT** — `DeliveryNoteController.php:256-278` + `LocationContext.php:99-116,128-150` — the implicit location path grants what the explicit path denies: a location-restricted user gets an unscoped queue

**Mechanism, verified line by line at tip.** The membership check lives **inside**
`if ($locationId !== null)` (`:266-277`). `resolveLocationId()` (`LocationContext.php:128-150`) has
four priorities; **priority 2 is dead in a request** — `setLocationId()` has exactly one occurrence
in `apps/api/app` (`LocationContext.php:34`, its own definition; `grep -rn "setLocationId" app`
returns nothing else), so no middleware or controller ever populates it. That leaves priority 3
(`getDefaultLocation()`, which filters `is_active` on **both** its lookups, `:102-105` and `:112-115`)
and priority 4 (`null`). When a company has **no active location**, `$locationId` is `null`, neither
`belongsToCompany` nor `canAccessLocation` runs, and
`UninvoicedDeliveryNoteService::queueQuery` adds no predicate at all (`:331`
`if ($locationId !== null) { $query->where('documents.location_id', $locationId); }`).

**Proven live** — membership `allowed_location_ids = [Tunis]`, both company locations `is_active =
false`, one confirmed DN at Tunis and one at Sfax, request omits `location_id`:

```json
HTTP 200
{"data":[{"partner_name":"Probe customer","delivery_note_count":2,"total":"20.000", …}],
 "summary":{"grand_total":"20.000", …},
 "scope":{"location_id":null,"can_view_all_locations":false}}
```

The user sees the Sfax delivery note. **The response contradicts itself**: it serves the whole
company while declaring `can_view_all_locations: false`. And the *explicit* request for the same
scope — `?location_id=all` — is refused for that same user with 422 (`:258-263`, covered by
`DeliveryNoteToBillQueueTest.php:242-248`). The entitlement machinery this wave built for `all` is
bypassed by simply omitting the parameter.

**Disposition note.** M3-round2 recorded this as `N9 — P3 — pre-existing shape of the
LocationContext contract`. The *contract* is pre-existing; what is new is that this wave is what puts
a location-restricted user in front of a company-wide delivery-note backlog, and what introduced a
server-computed `can_view_all_locations` entitlement that this branch silently violates. I am
carrying it forward at **Important**, not P3.

**Fix (3 lines):** in the `else` branch, when `$locationId === null && ! $canViewAllLocations`,
either raise the same 422 or constrain the query to
`whereIn('documents.location_id', $this->locationContext->getAllowedLocationIds($company->id))`.

---

### `F-T4` — **MINOR** — `DeliveryNoteBillingClaimService.php:110-113` — the finalise marker UPDATE omits the `company_id` predicate its payload sibling carries

```php
$markerCount = $this->db->table('delivery_note_billing_marks')
    ->whereIn('delivery_note_id', $set->deliveryNoteIds)   // :111
    ->whereNull('invoice_id')
    ->update(['invoice_id' => $invoiceId]);
```

The payload half two statements later does carry it (`… AND type = ? AND company_id = ?`, `:122-129`).
**Not exploitable:** `delivery_note_id` is the marker table's primary key
(`…create_delivery_note_billing_marks_table.php`, `$table->uuid('delivery_note_id')->primary()`), and
every id in `$set` was already company-predicated by the reserve CAS (`:66-70`, `if ($affected !== 1)
throw` at `:78-80`). Recorded as a defence-in-depth asymmetry only.

### `F-T5` — **MINOR** — `DeliveryNoteBillingClaimService.php:37` — `protected readonly ConnectionInterface $db` deviates from rule 13's `private readonly`

Deliberate: the class is non-final so the two test harnesses can subclass it
(`DeliveryNoteBillingClaimServiceTest.php:448`, `SalesOrderBillingClaimTest.php:201`). Worth one line
in the record rather than a change.

---

### Self-retraction — a finding I raised and then killed

My first probe reported `GET /api/v1/delivery-notes/uninvoiced/{valid-uuid-that-is-not-a-partner}`
→ **500**. That was **wrong and I retract it**: it was a PostgreSQL `25P02 current transaction is
aborted` cascade from the `F-T2` 22P02 fired earlier in the *same* `RefreshDatabase` transaction.
Re-run as the first statement of a clean transaction the same request returns **404
`NotFoundHttpException`**, correctly. Recorded because the failure mode — one 22P02 poisoning every
later assertion in a PG feature test — will manufacture false positives for anyone re-testing `F-T1`
or `F-T2`.

---

## D. What I verified GREEN (the designed surface)

| Charge | Verified at tip | Evidence |
|---|---|---|
| Rule 12 middleware chain | **PASS** | `Document/Presentation/routes.php:33` — `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`. No other module `routes.php` is in the branch diff, so no group lost `'api'` or `SetPermissionsTeam` |
| Route ordering / uuid constraints | **PASS** | `uninvoiced` (`:275`) and `uninvoiced/{partner}` (`:279-280`, `whereUuid('partner')`) both precede `{deliveryNote}` (`:284-285`, `whereUuid` added); `documents.show` gains `whereUuid` (`:49`) |
| `module:Sales` is a real gate, not a no-op | **PASS** | alias `bootstrap/app.php:113` `'module' => RequireModule::class`; `RequireModule.php:38-67` fails closed (`abort(403)` at `:63`) and refuses super-admins (`:47-49`) |
| Module name matches the SoT | **PASS** | `Sales` appears in `default_modules` of **12/12** verticals in `config/verticals.php` (12 `default_modules` blocks, 12 `'Sales',` entries at that indent) — so `module:Sales` denies nothing for any *config-derived* vertical. See OI-13 below for the tenant-override caveat |
| Both-layer gating, four-test discipline (SPEC §2.2, GATE1 defect 3) | **PASS — all four exist and are independent** | (a) API GET 403 module-off `DeliveryNoteConsolidationAccessControlTest.php:102-111`; (b) API POST 403 module-off `:145-153`; (c) FE route `ToBillRoute.gates.test.tsx` — *"does not render for an admin when Sales is disabled, independently of permissions"*, admin + `deliveries.view` + `defaultCompanyConfig` (no `Sales` in `all_enabled_modules`, `test/fixtures/companyConfig.ts:18-30`); (d) FE tab/action `PartnerDetailPage.deliveryNotes.gates.test.tsx:140` |
| FE uses the **correct** mechanism, not the r1 no-op `moduleKey` | **PASS** | `apps/web/src/routes/index.tsx:584-594` — `<ModuleGuard module="Sales">` wrapping `<RequirePermission permission="deliveries.view">`. `mechanicCompanyConfig` carries `Sales` (`companyConfig.ts:93-105`), `defaultCompanyConfig` does not |
| Claim-protocol authz surface — can any role reach `reserve`/`finalise` outside `claim()`? | **PASS — no** | `claim()` public (`:40`), `reserve()` `protected` (`:55`), `finalise()` `protected` (`:107`); `grep -rn "extends DeliveryNoteBillingClaimService" app tests` returns **only** the two test harnesses. `claim()` refuses outside a caller transaction (`:44-46`) and orders `reserve → closure → finalise` (`:48-50`) |
| Cross-company invoice-**number** leak via `DeliveryNoteBillingState` | **PASS — closed** | The DTO carries `invoice_id` only, never a number (`DeliveryNoteBillingState.php:11-15`). Every place the number/date is resolved is tenant+company scoped: `DocumentData.php:186-190`; `DocumentConversionController::deliveryNoteFailureDetails` uses `scopedQuery()` (`:428-437`, `tenant_id` + `company_id`) for the DN, the invoice **and** the source order, and reads markers with `->where('company_id', $this->companyContext->requireCompanyId())`. A marker pointing at a foreign-company invoice therefore yields `invoice_number: null` / `invoice_date: null`, never the number |
| Tenant/company discipline on every new query | **PASS** | `UninvoicedDeliveryNoteService::baseUninvoicedQuery` → `forTenant($company->tenant_id)` + `documents.company_id`; `queueQuery`'s `whereHas('partner')` re-asserts `partners.tenant_id`, `partners.company_id` and customer-type independently of the controller; `DeliveryNoteController::index` gains `forTenant()`; `DeliveryNoteToInvoiceConverter::loadAndValidateDeliveryNotes:376-384` tenant+company+`lockForUpdate`; `SalesOrderToInvoiceConverter::lockCompleteDeliveryNoteSet:628-631` tenant+company; `createInvoiceFromDeliveryNotes` validates each id with `uuid` **and** `ScopedExists::tenantAndCompany('documents', tenant, company)` |
| No row-level "tenant_id fallback" reasoning, no raw cross-tenant join | **PASS** | isolation stays physical; the two `forTenant()` call sites carry explicit comments naming shared-DB compatibility mode as the only reason the row predicate is retained |
| Central-connection discipline | **PASS** | `RequireModule` reaches the tenant directory through `$user->tenant`; `Tenant` pins the central connection via stancl's `CentralConnection` trait (`vendor/stancl/tenancy/src/Database/Concerns/CentralConnection.php:9`, used at `Database/Models/Tenant.php:24`). No new central read/write on the swapped default connection anywhere in the diff |
| Migration placement | **PASS** | both new migrations are under `database/migrations/tenant/` — correct for tenant-scoped tables under db-per-tenant. The backfill explicitly refuses cross-company attribution (`safeInvoiceId` → counter `cross_company_invoice_id`) and guards `Str::isUuid` before its own `documents` lookup |
| Rule 20 — queue / console context | **N/A, verified** | zero added lines in `apps/api/app` matching `dispatch(`, `onQueue(`, `ShouldQueue` — the wave adds no job or command, so the no-`CompanyContext` scale trap is not engaged |
| Rule 19 spot-checks on added backend lines | **PASS** | zero added lines matching `(float)`/`floatval`, zero bare `getScale()`, zero `app(` helper |
| PG-UUID `latestOfMany` pitfall | **PASS** | zero added lines matching `latestOfMany`/`ofMany(` |

---

## E. OI-13 — the staging pre-promotion obligation, restated

**`module:Sales` added to the pre-existing `POST /delivery-notes/consolidate-to-invoice`
(`Document/Presentation/routes.php:299`) is a REVOCATION, not a new gate.** Any tenant whose
effective module set lacks `Sales` starts receiving **403** on an endpoint that worked yesterday.

**The obligation is carried in the record** — I verified both carriers rather than assuming them:

- `docs/handoff/progress/dn-consolidation-build.progress.yaml:65-68` — open item
  `OI-13-module-sales-revocation`, `status: recorded_finding`, wording:
  *"BUILD the gate; the live tenant module-assignment verification and the grant-vs-defer choice are
  the owner's, read before PROMOTION … Never silently ship it as routine."*
- `HANDBACK-dn-consolidation-build-2026-08-12.md:824` — *"the live-tenant Sales-module assignment
  remains a parent promotion gate."*
- `progress.yaml:173` records honestly that the M5 Playwright run produced **no 403 evidence either
  way**, because the only spec touching the endpoint died at `loginAsRole`.

**Restated as the concrete pre-promotion check.** Config alone is *not* sufficient evidence: `Sales`
is in `default_modules` for all 12 verticals in `config/verticals.php`, **but** the effective set is
`array_unique(array_merge($defaultModules, $enabledExtras))` computed per tenant and **cached** in
`CompanyConfigService::getConfigForTenant` (`:48-81`). So before the gate goes live, on staging:

1. For **every** tenant, resolve the effective module set and assert `Sales ∈ all_enabled_modules` —
   read it per tenant, do not infer it from `verticals.php`.
2. Any tenant that fails (1) is an owner grant-vs-defer decision **before** promotion, not after.
3. The `tenant_config` cache must be invalidated after any grant, or a granted tenant keeps 403-ing
   for the cache TTL.
4. Bundle with D-6: `RolesAndPermissionsSeeder` re-run + `permission:cache-reset` — otherwise the
   base-landed `accountant → deliveries.view` grant (§B) is absent on pre-`d682b38ec` tenants and
   the accountant 403s on the queue.
5. **New, from `F-T1`:** read the OI-12 `unparseable_invoice_id` count out of the staging
   `tenants:migrate` log **as a gate, not as a survey** — a non-zero count on any tenant means that
   tenant's delivery-note list 500s once the FE ships.

---

## F. Residuals I looked at and am *not* raising as defects

- **`GET /delivery-notes` (index) has no `module:Sales`** while the FE partner "Delivery notes" tab
  gates on `hasModule('Sales')`. Deliberate and correctly scoped: the index also backs
  `/inventory/delivery-notes` (`routes/index.tsx:1241`, `:1259`, `moduleKey="inventory"` — the
  F-3 standing constraint, `progress.yaml:78-80`), so it is not a Sales-exclusive route and the
  asymmetry discloses nothing a non-Sales tenant could not already read. Recorded by the handback at
  `:818-819` as an owner-scoped residual; I agree with that disposition.
- **`invoiced_by_document_number` is now returned to `deliveries.view` holders** on the DN DTO
  (`DocumentData.php:256`). Not a permission-boundary disclosure: `viewer` — the least-privileged
  role in the matrix that can read a DN — already holds `invoices.view`
  (`RolesAndPermissionsSeeder.php`, viewer block), and the lookup is tenant+company scoped.
- **`Company::query()->findOrFail($companyId)` in the queue service is not tenant-predicated.** The
  id is always `CompanyContext::requireCompany()->id`, i.e. already trusted, and under db-per-tenant
  `companies` lives on the swapped connection. Not reachable as a leak.
- **`SalesOrderToInvoiceConverter` binds `payload['delivery_note_ids']` entries into `whereIn('id',…)`**
  (`:628-631`) — same uuid class as `F-T1`, but those ids are code-written UUIDs and this shape
  pre-dates the branch. Flagged only so the `F-T1` fix is scoped deliberately rather than by
  accident.

---

## G. Disposition

| Id | Sev | One-liner |
|---|---|---|
| `F-T1` | **Critical** | `DocumentData.php:185-190` — unguarded `find()` on JSONB `payload.invoice_id` 500s `GET /delivery-notes/{id}` **and the whole DN list** on a row shape the wave's own migration counts (`unparseable_invoice_id`) |
| `F-T2` | Important | `DeliveryNoteController.php:304-307` — new index `location_id` filter binds a raw query string into a PG uuid column; `?location_id=not-a-uuid` → 500 |
| `F-T3` | Important | `DeliveryNoteController.php:256-278` — membership check sits inside `if ($locationId !== null)`; with no active location a restricted user gets the whole company's queue while the response declares `can_view_all_locations:false`, and `?location_id=all` is 422'd for the same user |
| `F-T4` | Minor | `DeliveryNoteBillingClaimService.php:110-113` — finalise marker UPDATE omits the `company_id` predicate its payload sibling carries (not exploitable; PK-protected) |
| `F-T5` | Minor | `DeliveryNoteBillingClaimService.php:37` — `protected readonly` deviates from rule 13; deliberate, test-subclass driven |

**What to fix before merge:** guard `DocumentData.php:190` with `Str::isUuid()` (mirroring the
migration's own `safeInvoiceId`) and cover it with a dirty-payload feature test on both the DN show
and the DN **list**; add `uuid` validation to the index `location_id` filter; close the
`$locationId === null && ! $canViewAllLocations` branch in `toBillFilters`.

**VERDICT: CHANGES-REQUIRED**
