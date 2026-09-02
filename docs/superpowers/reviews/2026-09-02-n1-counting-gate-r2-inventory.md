# N-1 (API) — adversarial gate r2 (condition check) — inventory-costing-reviewer

- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n-inventory-mobile`
- Branch `test/N-inventory-mobile`, base `dev` `3615cab8f`
- r1: `docs/superpowers/reviews/2026-09-02-n1-counting-gate-r1-inventory.md` (MERGE-WITH-CONDITIONS, C1–C4)
- Fix round reviewed: `34172a675` (API) on top of `6e47bde17` + `a143f4bad`. `a43f70f04` and `24e06d507` are web-only, out of scope for this gate.

## VERDICT: CHANGES

Six of the seven ruled conditions are genuinely closed in code, and I re-verified each independently
(not from the commit message). But the IMPORTANT-1 fix introduced a **new DB round-trip inside the
batch transaction on an unvalidated device-supplied string**, and under PostgreSQL that turns a
garbage `location_id` — the exact input this fix exists to refuse — into a silently rolled-back batch
that still answers **201 with `serverId`s for drafts that do not exist**. Proven empirically against
the local PG 16 (below). The SQLite suite cannot see it. That is a BLOCKER on the offline-sync path.

## Verification runs (this round, in this worktree)

```
./vendor/bin/phpunit tests/Feature/Inventory/SubmitCountQuantityScaleTest.php \
  tests/Feature/Inventory/ActivateDraftCountingTest.php \
  tests/Feature/Inventory/InventoryTenantIsolationTest.php \
  tests/Feature/Inventory/ZoneScopedCountingTest.php \
  tests/Feature/Inventory/CountingBlockTest.php \
  tests/Feature/Inventory/CountingOverlapGuardTest.php
=> Tests: 104, Assertions: 352, Failures: 1
   the ONLY failure is ZoneScopedCountingTest::test_location_hierarchy_counting_flow_keeps_variant_stock_at_location_grain
   (ZoneScopedCountingTest.php:600 — `assertCount(2, $corrections)` got 0). PRE-EXISTING and
   untouched by this diff: that test drives `service->create()` + `service->activate()` +
   `ApplyStockAdjustmentsOnCountingCompleted`, none of which this commit modifies; the
   diff's guards live in activateDraft / batchCreateDrafts / CreateCountingRequest(block_sales).

./vendor/bin/phpunit tests/Feature/Inventory/LiveCountingScenarioTest.php \
  tests/Feature/Inventory/OnboardingFirstCountTest.php
=> OK (10 tests, 40 assertions)

./vendor/bin/phpstan analyse <6 changed app files + 4 changed test files> --memory-limit=1G
=> 1 error, PRE-EXISTING and NOT this lane:
   tests/Feature/Inventory/InventoryTenantIsolationTest.php:769 method.alreadyNarrowedType,
   introduced by `dadd8a008` (git log -L). `phpstan.neon:7` limits `paths` to `app/`, so CI is
   unaffected; all six changed app/ files are clean.
```

---

## Condition-by-condition

### IMPORTANT-1 / IMPORTANT-2 (per-row batch refusal) — **PARTIALLY CLOSED** (see BLOCKER-1, IMPORTANT-1)

What I verified as done:
- `InventoryCountingController.php:1066` — the inline rule is back to `'drafts.*.scopeFilters.location_id' => 'nullable|string'`; no whole-batch `required_if`. The poison-pill for a *missing* location is gone.
- `:1093-1101` — missing-location refusal is per row: `errors[] = {localId, 'A location must be selected for this scope'}` + `continue`.
- `:1103-1111` — foreign-location refusal per row: `errors[] = {localId, 'Location not found for the current company'}` + `continue`.
- **Company scope, not tenant-only: confirmed.** `Location::query()->forCompany($companyId)` at `:1104` resolves to `Location::scopeForCompany` = `where('company_id', $companyId)` (`app/Modules/Company/Domain/Location.php:166-169`). `$companyId` comes from `CompanyContext::requireCompanyId()` at `:1039`. In the db-per-tenant model the tenant boundary is the database itself, so `company_id` is the correct and only additional boundary.
- **A refused row persists nothing: confirmed by code order.** Both `continue`s are at `:1100` / `:1110`, i.e. BEFORE `new InventoryCounting` at `:1113` and `$counting->save()` at `:1135`. No counting row, no assignment (assignments are only created at activation, `InventoryCountingService::createAssignments` via `activateDraft`), no event. Asserted by data-meaning tests: `ActivateDraftCountingTest.php:514` (only the offending draft dropped, the healthy sibling persisted with `scope_type = product`), `:555` (0 countings for the company), `InventoryTenantIsolationTest.php:357` (1 counting persisted, and it carries `locationA`).
- **Transaction semantics unchanged: confirmed structurally.** Still one `\DB::transaction` at `:1077` wrapping the whole loop, with the same per-row `try/catch (\Exception)` at `:1142-1147`, and the endpoint still answers 201 at `:1151-1156`. No nesting, no savepoints added, no attempt count changed.

What is NOT closed: BLOCKER-1 (malformed uuid → PG aborts the transaction) and IMPORTANT-1 (zone drafts with no location are still minted by this endpoint). Both below.

### IMPORTANT-3 (cross-company test) — **CLOSED**
`InventoryTenantIsolationTest.php:328` (single draft: company B's location → 422 `VALIDATION_ERROR` on `scope_filters.location_id`, and 0 `product_location` rows born for company A) and `:357` (batch twin: per-row `errors[]`, sibling persisted, persisted `location_id` asserted to be company A's). Both assert data meaning, not status codes alone. Green in the run above. Convention-09 second-**company** limb now covered; the second-**location** limb was already covered (`ActivateDraftCountingTest.php:312-360`).

### IMPORTANT-4 (zone location + zero-item activation) — **CLOSED for the mobile path; OPEN for the web path (IMPORTANT-2)**
- Zone at the boundary: `CreateDraftCountingRequest.php:79-85` now carries `required_if:scope_type,zone` beside `required_if:scope_type,product_location`, both still behind `ScopedExists::company('locations', $company->id)`. Zone at activation: `InventoryCountingController.php:997-1006`. Tests: `ActivateDraftCountingTest.php:398` (422 + status stays Draft + 0 items) and the create-draft twin.
- Zero-item throw: `InventoryCountingService.php:661-663`, inside the pre-existing `DB::transaction` opened at `:620`. Rollback is asserted on the values that matter, not on the code: `ActivateDraftCountingTest.php:438` asserts status back to `Draft`, `counting_number` back to `null`, 0 items AND 0 assignments. 422 `BUSINESS_ERROR` mapping confirmed at `bootstrap/app.php:1022-1031` (generic `DomainException` renderer, registered last before the catch-all).
- **Cannot fire for a legitimate onboarding / `include_zero_stock` / `full_inventory` activation — verified by reading the generator, not by trusting the tests.** `resolveIncludesZeroStock` (`InventoryCountingService.php:197-213`) returns true only for `FullInventory`/`Location`, then `catalogItemSeeds` (`:453-497`) seeds the **cartesian product of every active product × every resolved location** with `theoretical_qty` defaulting to `'0.0000'` when no stock row exists (`:491`). So an onboarding first count emits items for a catalog with zero stock — it can only reach 0 seeds when the company has **no active products at all** (`:468-470`) or no active locations (`:457-459`), i.e. genuinely nothing to count. `LiveCountingScenarioTest` + `OnboardingFirstCountTest` green (10/10); note that neither exercises `activateDraft`, so the empirical cover for this comes from `ActivateDraftCountingTest` (22 cases) rather than from the onboarding suite.
- One behaviour change worth recording in the mobile brief (not a defect): for `product` / `product_location` / `category`, `resolveIncludesZeroStock` is always false (`:203-205`) and `getStockLevelsForScope` ends in `->where('quantity','>',0)` (`:559`), so a draft over products that are all at zero on-hand now **hard-refuses activation** where it previously produced an empty live count. Neither shape is countable — `CountingItemController::lookupByBarcode` returns 404 for a product that is not already an item (`CountingItemController.php:151-156`) and no endpoint adds an item to an active counting (`allow_unexpected_items` is stored but never read outside the payloads) — so the refusal is the honest half of a pre-existing gap, not a regression.

### IMPORTANT-5 (`Rule::enum` on scopeType) — **CLOSED as ruled** (residual: MINOR-2)
`InventoryCountingController.php:1053` — `['required', Rule::enum(CountingScopeType::class)]`; `CountingScopeType` was already imported (`:13`). Test `ActivateDraftCountingTest.php:603` sends `'productLocation'` and asserts 422 + 0 countings persisted. The `ValueError`-escapes-`catch(\Exception)` 500 is genuinely unreachable now.

### IMPORTANT-6 (census) — **CLOSED for local; staging census still owed**
The census SQL is not committed anywhere in the worktree (grep for `scope_filters->>` finds only the r1 review), so I could not check the author's SQL text — I re-ran my own instead, read-only, against the local PG (`127.0.0.1:5433`, `autoerp_postgres`), iterating every non-template database and skipping those without `public.inventory_countings`:

```
databases with inventory_countings: 303 (of 312)
total countings: 79
  location/cancelled 1 · location/draft 1 · location/finalized 9
  product_location/cancelled 42 · product_location/count_1_in_progress 2
  product_location/finalized 18 · product_location/pending_review 6
location-less (scope_filters->>'location_id' IS NULL OR '') product_location|zone rows:
  product_location/cancelled => 1
location-less DRAFT rows (i.e. stranded by the new guards): NONE
```

`scope_filters` is `jsonb` (`database/migrations/tenant/2025_12_02_070000_create_inventory_countings_table.php:28`), so `->>` is the right operator; `status` is a plain string column with a CHECK constraint (`:33`, `:116`) whose `draft` value matches `CountingStatus::Draft`. Conclusion matches the commit's claim (larger sample: 303 DBs/79 countings vs the claimed 239/40) and is stronger: **zero zone countings exist locally at all**, so the new zone guards have no legacy exposure. Local ≠ staging: the same query must be run on staging before promotion, and it is not repeatable by anyone else today (MINOR-5).

### FE IMPORTANT-1 at the API (`block_sales` scope rule) — **CLOSED**
`CreateCountingRequest.php:117-123` refuses `block_sales:true` for `product`/`category`; `zone` keeps its own message at `:162-164`. The underlying claim is true in code: `CountingBlockService::activeBlockFor` filters `whereIn('scope_type', [location, full_inventory, product_location])` (`CountingBlockService.php:76-81`) and `scopeCoversLocation()` ends in `default => false` (`:159`). Tests: `ZoneScopedCountingTest.php:639` (product → 422, and 0 rows with `block_sales = true`), `:661` (category), `:681` (control: product_location still 201 with `block_sales` true). `block_sales` has exactly one writer (`InventoryCountingService::create` `:89`, fed only by `CreateCountingRequest`) — grep across `app/` finds no other assignment — so the surface is fully closed; drafts and the batch endpoints cannot set it at all.

### MINOR-1..4 — **CLOSED**
M-1 `ActivateDraftCountingTest.php:655` now assigns `now()`; M-2 the overclaiming docblock is split into `test_activate_draft_leaves_product_scope_unaffected` / `..._location_scope_unaffected` (+ real zone cases); M-3 `SubmitCountQuantityScaleTest.php:181-190` hardcodes `'12.5000'/'12.0000'/'12.0000'/'6.7500'`; M-4 `:212` pins `0.0001` accepted / `0.00001` refused with `count_1_qty` asserted null on refusal.

---

## NEW findings

### BLOCKER

**BLOCKER-1 — the new company check queries PostgreSQL with an unvalidated string; a non-UUID `location_id` aborts the whole batch transaction, and the endpoint still answers 201 with `serverId`s for drafts that were rolled back.**

`InventoryCountingController.php:1104` — `Location::query()->forCompany($companyId)->whereKey($scopeLocationId)->exists()`, with `$scopeLocationId` only `trim`med (`:1091`) and validated by nothing stronger than `nullable|string` (`:1066`). `locations.id` is `uuid` (`database/migrations/tenant/2025_11_30_105000_create_locations_table.php:25`). A value like `"undefined"` (the r1 failure scenario, and the "stale/garbage uuid" the commit message itself claims to close) makes PG raise `22P02 invalid input syntax for type uuid`. That is a `QueryException` → `Exception`, so it IS caught at `:1142` and the loop `continue`s — but in PostgreSQL the enclosing transaction opened at `:1077` is now **aborted**, so:

- every later draft fails with `25P02 current transaction is aborted…` and lands in `errors[]` with a raw SQL string;
- `COMMIT` on an aborted PG transaction returns silently as a ROLLBACK — Laravel's `commit()` throws nothing;
- so drafts that succeeded **before** the bad row are already in `$results` and are returned in `data.success[]` with a `serverId`, while nothing was persisted.

Measured, not assumed — probe against the live local PG 16 (`127.0.0.1:5433`), faithfully mirroring the loop (temp tables, same statement shapes, same `try/catch`+`continue`, same commit):

```
COMMIT returned without exception
success[] = ["draft-1-good"]
errors[]  = ["draft-2-garbage-location => SQLSTATE[22P02] invalid input syntax for type uuid: \"undefined\"",
             "draft-3-good => SQLSTATE[25P02] current transaction is aborted, commands ignored until end of transaction"]
rows actually persisted = 0
```

Failure scenario: a device syncs 50 offline drafts, one carrying `location_id: "undefined"` (or a truncated/local id). HTTP 201. `data.success[]` lists server ids for the drafts before it. The device records those mappings and considers them synced; the server has zero rows. Every later draft reports an internal SQL error. Retrying the same payload reproduces it exactly — the "permanent poison pill" the IMPORTANT-2 ruling set out to remove, now with silent data loss instead of a 422.

**The SQLite suite structurally cannot catch this**: SQLite compares the text and returns no rows, so `test_batch_create_drafts_reports_a_foreign_location_per_row` (`ActivateDraftCountingTest.php:555`) passes because it only ever sends a well-formed foreign UUID. No test sends a malformed one.

Fix (both halves): (a) `Str::isUuid($scopeLocationId)` before the query, emitting the same per-row `errors[]` entry — the identical idiom already exists twice in this very controller: `onboardingWorklist` validates `['required','bail','uuid']` before `Location::query()->forCompany(...)->find()` (`:66-72`), and the sibling offline-sync endpoint `batchAddProducts` pre-filters with `Str::isUuid` and emits `'Invalid product ID; expected a UUID'` per row (`:1210`, `:1236-1245`); and (b) make the row body a **savepoint** (a nested `DB::transaction` per draft) so that any statement error — including the pre-existing one where a garbage `count1UserId` (`:1066` `nullable|string` → uuid FK at `:1128-1132`) blows up the INSERT — can no longer abort the outer transaction. Add a regression test that sends `location_id: "undefined"` plus a healthy sibling, and assert the sibling is actually in the DB.

### IMPORTANT

**IMPORTANT-1 — the batch endpoint still mints zone drafts with no location, which the new activation guard then refuses forever; and the mobile brief already documents the opposite.**
`InventoryCountingController.php:1093-1094` checks the missing-location case for `CountingScopeType::ProductLocation` **only**. The second check (`:1103`) fires only when a location_id IS present. So a `zone` draft with `zone_ids` and no `location_id` syncs, persists (`:1113-1134`) and returns success — then `activateDraft` refuses it at `:1004` ('A location must be selected before activation'), and no endpoint can repair it: `scope_filters` is written wholesale only at creation (`:694`, `:1126`), `UpdateDraftCountingRequest.php:39-53` has no `scope_filters` field, and `batchUpdateDrafts` (`:1396-1431`) touches only title/instructions/mode/flags/users/dates. The single-draft twin refuses this at validation (`CreateDraftCountingRequest.php:82`), so the offline path is the weaker one again. The exit is `cancel` (Draft → Cancelled is legal, `CountingStatus.php:41`) + re-create, so this is stranding, not data loss.
Worse, `docs/handoff/CODEX-mobile-inventory-alignment-2026-09-02.md:24` tells the mobile team that the batch endpoint refuses "a `product_location` (**or `zone`**) draft without `scopeFilters.location_id`" — the client will be written against a guarantee the server does not implement.
Fix: extend the `:1093` condition to `[ProductLocation, Zone]` (one array), or correct the brief.

**IMPORTANT-2 — the zero-item guard landed on the mobile activation path only; the web path still activates a counting with nothing in it.**
The commit says "activation never asserted the scope resolved to anything". That is now true for `activateDraft` (`InventoryCountingService.php:661`) but not for the primary web surface: `store` → `create()` generates items (`:101`) with **no count assertion**, and `activate()` (`:699-731`) never regenerates or counts them — it just `transitionTo(Count1InProgress)` at `:716`. So `POST /api/v1/inventory/countings` with, say, a `product_location` scope whose products hold no stock at the chosen location still yields a live counting with 0 items, `total_items = 0` assignments and a `COUNTING_ACTIVATED` event — the exact r1 IMPORTANT-4 outcome, on the surface most operators use. Two activation surfaces, one guarded (convention 11).
Fix: hoist the same assertion into `create()` (or into `generateCountingItems`), or into `activate()` before the transition.

### MINOR

**MINOR-1 — `errors[].error` leaks raw exception text to the client.** `:1145` returns `$e->getMessage()` verbatim; with BLOCKER-1 that is a full PG error including table/column names and the offending value. Map unexpected exceptions to a generic per-row message and log the detail.

**MINOR-2 — the endpoint now mixes both refusal shapes for the same class of bad row.** A missing/foreign location is per row (`:1093`, `:1103`), but an unknown `scopeType` (`:1053`) — and a non-string `scopeFilters.location_id` (`:1066`) — 422 the whole batch, which is the poison-pill shape the IMPORTANT-2 ruling rejected two rules above. This follows the ruling as written, and the brief mitigates it by contract ("never enqueue one", brief `:24`), so it is only MINOR; but `CountingScopeType::tryFrom(...) === null → errors[] + continue` would make the endpoint's contract uniform.

**MINOR-3 — two error envelopes on one endpoint.** `activate-draft` returns bare `{"error":"A location must be selected before activation"}` from the controller guards (`:985`, `:1004`) and `{"error":{"code":"BUSINESS_ERROR","message":…}}` for the new zero-item refusal (`bootstrap/app.php:1022-1031`). The mobile client must parse both shapes from the same route. Documented in the brief, so it is a contract wart, not a defect.

**MINOR-4 — no test covers a malformed (non-uuid) `location_id`** on either draft path, which is why BLOCKER-1 is invisible. Add it, and prefer running it on the PG lane — under SQLite it will pass for the wrong reason.

**MINOR-5 — the census is not reproducible.** No script, query or test was committed; the evidence lives only in the commit message. Add the SQL to the lane doc (or a `tenant:census-*` command) so the staging/production run before promotion is the same query.

**MINOR-6 — pre-existing, adjacent: `scope_filters.zone_ids` is validated on `CreateCountingRequest.php:55-60` but not on `CreateDraftCountingRequest`.** A mobile zone draft can carry arbitrary zone ids; `zoneItemSeeds` pins them to the (company-checked) location via `atLocation($locationId)` (`InventoryCountingService.php:326-334`), so there is no leak — the count just resolves to nothing and now 422s at activation. Worth a rule for symmetry.

## Not a finding (checked, clean)
- No stock movement, WAC arithmetic, batch/FEFO, opening-balance or lock-order code is touched. `MovementReason` signs untouched. No scale-resolver call added → no queue/console no-arg `getScale()` exposure.
- Rule 19: the only quantity surface in the diff is the r1-verified scale-4 regex; the new tests hardcode 4-dp strings. No float, no `number_format`, no `(float)`.
- Rules 3/9/13: `declare(strict_types=1)` throughout, enums (`CountingScopeType`, `CountingStatus`) used for every new comparison, `CompanyContext` constructor-injected, no `app()` added in `app/`.
- No migration and no new unique key → no `TenantOnlyUniqueOnCatalogueTablesRatchetTest` exposure. No new noun → no glossary entry owed.
- Convention 09: second-company (`InventoryTenantIsolationTest.php:328`, `:357`), second-location (`ActivateDraftCountingTest.php:312`) and per-row idempotency-ish partial-success coverage all present for the touched surface.

## Before merge
1. **BLOCKER-1** — `Str::isUuid` guard before `:1104` **and** a per-row savepoint around the row body, plus a `location_id: "undefined"` regression test asserting the healthy sibling is really in the DB (run it on PG).
2. **IMPORTANT-1** — add `Zone` to the `:1093` missing-location check (or fix `docs/handoff/CODEX-mobile-inventory-alignment-2026-09-02.md:24`).
3. **IMPORTANT-2** — close the zero-item hole on the `create()`/`activate()` web surface, or record it as a tracked follow-up with a lane id.
4. Re-run the census on staging before promotion, and commit the query (MINOR-5).
