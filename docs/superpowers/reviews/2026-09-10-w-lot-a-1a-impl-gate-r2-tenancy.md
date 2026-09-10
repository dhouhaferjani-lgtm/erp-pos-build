# Gate r2 — W-LOT-A-1a (tenancy-authz-reviewer, 2026-09-10)

**Audited HEAD:** `2fa724c1d` on `lane/w-lot-a-1a`, fix-round diff `04e60530c..2fa724c1d` (10 commits, 37 files), whole-lane diff `4373ba2f6..2fa724c1d` (81 files), worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w-lot-a-1a`. Read-only: no edit, commit, merge, push, or Dokploy action. All PG/SQLite legs run one file per invocation on `autoerp_test_w` (port 5433, `DB_DATABASE` **and** `DB_CENTRAL_DATABASE` pinned), env from the committed-adjacent `docs/sessions/wlota1a/test-env.sh`.
Orchestrator: session_01AcU81as26G2apodoimmyq7 (reviewer = Claude Opus `tenancy-authz-reviewer` agent).

## Verdict
VERDICT: CHANGES-REQUIRED
BLOCKER=2 MAJOR=3 MINOR=5

## Gate r1 closure table

| r1 item | Claimed resolution | Status | Evidence `path:line` |
|---|---|---|---|
| **B-1** race harness DB-pinned | driver skip, DB-name assertion deleted | **VERIFIED** | `apps/api/tests/Feature/Identity/LotActionPermissionDeltaTest.php:32-34` (`if (DB::getDriverName() !== 'pgsql') { markTestSkipped(...) }`, no `getDatabaseName()` assertion anywhere in the file); SQLite legs 12/45/1-skipped and 7/22/1-skipped, both exit 0 |
| **B-2** Push 3 not inert | Option B retained + real flag-off HTTP contract | **VERIFIED for the two declared exceptions; CONTRADICTED as a general inertness claim** | Test `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php:43-96` (real `getJson`/`postJson`, `enforce=false`, `assertExactJson`); but two further flag-off changes exist — see BLOCKER B-2(r2) and MAJOR M-3(r2) |
| **M-1** inertness guard asserted nothing | rewritten as HTTP payload contract | **VERIFIED** | `BatchActionPermissionsTest.php:47-50` (flag off + `batches.view` revoked and asserted absent), `:75,77,80,85,95` (`assertExactJson` on `/batches`, `/products/{id}/batch-stock`, `/batches/{uuid}`, `/batches/expired?location_ids[]`, `/batches/expiring?location_id`, `POST /batches`) |
| **M-2** technician | owner ruling: NOT granted | **VERIFIED** | `apps/api/database/seeders/RolesAndPermissionsSeeder.php:99` (ruling comment), `:100` loop is exactly `['cashier','viewer','operator']`; no `batches.*` anywhere in technician's legacy grants; pinned by `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php:79,92` (PG 5/38 green) |
| **M-3** `Array<any>` in RoleData | `LiteralTypeScriptType('Array<string>')` + regenerate | **VERIFIED** | `apps/api/app/Modules/Identity/Application/DTOs/RoleData.php:20`; `packages/shared/types/generated.d.ts:1012` `permissions: Array<string>`; a fresh `php artisan typescript:transform` output is **byte-identical** to the committed file (regenerated to a temp path and diffed; artefact removed) |
| **M-4** backwardTrace 500 on bad uuid/date | `Str::isUuid`→404, `sometimes\|uuid`/`sometimes\|date`→422 | **VERIFIED** | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:108-115`, values taken from `$validated` at `:120`; pinned `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php:87-94` (PG 5/38 green) |
| **M-5** `productId` null→`""` | `?string` on both DTOs, casts dropped | **VERIFIED** | `apps/api/app/Shared/Contracts/BatchTraceability/BackwardDocumentBatchTraceData.php:16,18`; `ForwardDocumentBatchTraceData.php:11`; casts removed at `DocumentBatchTraceReaderAdapter.php:25,56`; pinned `BatchTraceReaderContractTest.php:97-107` |
| **N-1** flag-independent POS uuid rule | documented | **VERIFIED** | `docs/handoff/RELEASE-NOTES-WLOTA-1a-2026-09-10.md:12` |
| **N-2** env key absent | added | **VERIFIED** | `apps/api/.env.example:192-193` |
| **N-3** stderr marker on every boot | marker only on marked/activated paths | **VERIFIED** (cite imprecise, see N-1(r2)) | emitter called only at `RolesAndPermissionsSeeder.php:47` (ACTIVATED) and `:52` (marked LEGACY); pinned `apps/api/tests/Feature/Console/LotActionReseedMarkerTest.php:127-131` (`Log::shouldReceive('channel')->with('stderr')->never()`) |
| **N-4** `currentTenantId()` throws outside tenancy | returns null, legacy fallback, logged reason | **VERIFIED** | `RolesAndPermissionsSeeder.php:61-69` + `:35-41`; pinned `LotActionReseedMarkerTest.php:134-143` (asserts DB state, not just the log) |
| **N-5** duplicate-key React warning | fixture completed | **VERIFIED (frontend-owned)** | `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx:9-13` (`satisfies Batch`, `id` present) — but see N-4(r2) on `product_variant_id` |
| **I-1** unfiltered `batchStock` on mutation responses | all five paths load scoped stock | **VERIFIED in code, PARTIALLY UNEVIDENCED** | `BatchController.php:105-110` + call sites `:223,241,280,477,516`; only `store` and `update` are pinned by a test — see MAJOR M-1(r2) |
| **I-2** expiring/expired not inert | Option B adopted, declared | **VERIFIED** | `docs/handoff/RELEASE-NOTES-WLOTA-1a-2026-09-10.md:11`; pinned `BatchActionPermissionsTest.php:78-85` |
| **I-3** silent `0.0000` on unloaded relation | loud | **VERIFIED** | `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:23-25`; pinned `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:150-155` |
| **I-4** convention-09 cases | three cases added | **VERIFIED** | `BatchReadLocationScopeTest.php:157-167` (restricted POS foreign-location 403 + 7-table snapshot), `:169-178` (POS JSON identical across activation), `:246-273` (two companies sharing one partner, each sees only its own `document_number`) |
| **I-5** `document_number` null→`''` | nullable on both DTOs | **VERIFIED** | `BackwardDocumentBatchTraceData.php:18`, `ForwardDocumentBatchTraceData.php:11`, adapter `:25,56`; pinned `BatchTraceReaderContractTest.php:104-106` |
| **I-6** positive-stock-only picker | comment + pin | **VERIFIED** | `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:109`; pinned `BatchReadLocationScopeTest.php:216` |
| **I-7** boundary ratchet glob | widened to all controllers | **VERIFIED** | `apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php:13-19`; green 1/4 |
| **I-8 / B-7 / frontend precision** | deferred by ticket | **VERIFIED** | `docs/superpowers/tickets/2026-09-10-batch-detail-parsefloat-quantities.md:1`; `docs/superpowers/tickets/2026-09-10-batch-create-update-api-enforcement.md:1` |
| **I-9** duplicate `resolvedReadLocationIds` in `stock()` | hoisted | **VERIFIED** | `BatchController.php:344-345,352` (one resolve, passed to both) |
| **Manifest / CI raise** | 21/18/39/15, 1264, 12 PG classes | **VERIFIED** | see item 8 |

## Required verifications (items 1–11)

| # | Requirement | Status | Evidence |
|---|---|---|---|
| 1 | Every r1 tenancy item closed and pinned | **VERIFIED except B-2's general claim** | table above |
| 2 | PG race harness skips cleanly on SQLite; no private DB-name assertion | **VERIFIED** | `LotActionPermissionDeltaTest.php:32-34`; SQLite `-c phpunit.xml`: Delta 12 tests/45 assertions/1 skipped exit 0; GM 7 tests/22 assertions/1 skipped exit 0 |
| 3 | Riders R1/R2/R3 honoured; real flag-off contract test asserts SHAPE | **VERIFIED** | **R1** "load the scoped relation before building `BatchResource` on every response path, never recompute from a fresh query" → `BatchController.php:105-110` closure mirrors `BatchRepository::findVisibleByUuid` (`BatchRepository.php:34`), applied at `:223` (store), `:241` (update), `:280` (recall), `:477` (transfer), `:516` (writeOff); `BatchResource.php:72-90` sums only the loaded collection, no query. **R2** "an unloaded relation is loud, not `0.0000`" → `BatchResource.php:23-25` + test `:150-155`. **R3** "types.ts→string, float comments gone, Vitest renders `3.1234`" → `apps/web/src/features/batches/types.ts:38-39,270-280,319-322`; `apps/web/src/features/batches/pages/ExpiryWriteOffPage.test.tsx:160-176` (renders `3.1234` twice and submits `quantity: '3.1234'`); `BatchPermissions.test.tsx:35-38`. Shape (not status) asserted: `assertExactJson` (`BatchActionPermissionsTest.php:75,77,80,85,95`) compares re-encoded JSON, so `'12.1234'` ≠ `12.1234` — types are enforced; no `batches.view` is held (`:48-50`) |
| 4 | Flag-off inert for every existing role except the declared exceptions | **CONTRADICTED** | Declared exceptions (plan rev 10 §0, release notes `:11`): (a) 4-dp string `total_quantity`/`available_quantity` from the loaded scoped relation on every response; (b) `/batches/expiring?location_id=` and `/batches/expired?location_ids[]=` narrow `batch_stock[]` and totals. Additionally verified inert: `expired()` byte-identical to `4373ba2f6`; `BatchActionAccess.php:18-19` returns `$next()` before evaluation; `resolvedReadLocationIds` returns `null` at `BatchController.php:58-59`; seeder unmarked flag-off path is the verbatim legacy catalogue (`:56-57`); `batch_stock[].available_quantity` `bcsub` equals the stored generated column `quantity - reserved_quantity` (`apps/api/database/migrations/tenant/2026_01_05_150001_create_inventory_batch_stock_table.php:29-31`); GM guard fires only when `general_manager` is in the effective roles (`GeneralManagerAssignmentGuard.php:21`), a role that does not exist while the flag is false. **Two undeclared flag-off changes found** → BLOCKER B-2(r2), MAJOR M-3(r2) |
| 5 | Legacy role handling: name/guard + `(tenant_id IS NULL OR = current)`, IDs/team/custom grants/assignments preserved, fail-closed on mixed collisions, only the new GM marked + tenant-scoped; technician has no `batches.view` | **VERIFIED** | `LotActionPermissionDelta.php:114-116` (predicate, `lockForUpdate`), `:123-124` `ambiguous_legacy_role_collision` thrown inside `DB::transaction` (`:50`), `:118-121,145-147` unmarked-GM collision, `:154-155` marked role created with the mandatory team + `provisioning_source`, `:174-178` `syncPermissions` only for genuinely new roles / additive `givePermissionTo($missing)` otherwise, `:61-63` `invalid_global_marked_role`. Advisory lock at `:134` acquired inside the transaction at `:54`, **before** any role read or write — no role write outside the lock. Files byte-identical to the r1-reviewed blob (`git rev-parse` equal for `LotActionPermissionDelta.php`, `BatchActionAccess.php`, `routes.php`, `RoleController.php`, `GeneralManagerAssignmentGuard.php`) |
| 6 | Route middleware + module gate on both layers; B-7 FormRequest claim | **VERIFIED** | `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:13` single group `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'module:BatchExpiry']`; no route file changed in the fix round; Identity group `apps/api/app/Modules/Identity/routes.php:57` carries `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` and is unchanged by the lane. FE both-layer: `apps/web/src/routes/index.tsx:1206` `ModuleGuard module="BatchExpiry"`. B-7 claim opened and confirmed: `apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRequest.php:22` `can('batches.create')`, `UpdateBatchRequest.php:13` `can('batches.update')`, and pre-delta both are held by `admin`/`manager` (`git show 4373ba2f6:apps/web/src/hooks/permissionsMap.generated.ts:13,17`), so the Push-5 create/edit gates need no Push-4 grant |
| 7 | Convention 09 on batch surfaces, data assertions | **VERIFIED** | `BatchReadLocationScopeTest.php:225` second company via real `POST /api/v1/companies`, `:231-232` two locations per company with `pos_enabled` on the second, `:263-273` selected-second-location data assertions + cross-company trace discrimination, `:277-279` re-run → 422 + `meta.outcome=already_exists` + exactly one company-A lot + seven-table snapshot equality |
| 8 | Manifest + CI | **VERIFIED** | `apps/api/tests/feature-lane-manifest.json:701` BatchExpiry 21, `:758` Console 18, `:822` Identity 39, `:879` Migrations 15, `:9` `gated_ceiling` 1264; the four `raise_note_2026_09_10_wlota1a` entries + `:1062` each carry the four statements (lane parked/ceiling enforced · twelve PG selections with the two exclusions · local per-file evidence · "no observed CI run is claimed"). `.github/workflows/ci.yml:1133` `--filter` adds exactly the twelve classes and neither `BatchActionPermissionsTest` nor `RoleProvisioningSourceSchemaTest`. `php tools/feature-lane-manifest-check.php` → **exit 0** ("every `--filter` entry is anchored and uniquely matched against 1941 test classes") |
| 9 | Generated artefacts in sync | **VERIFIED** | `generated.d.ts:1012` `Array<string>`; fresh `typescript:transform` byte-identical; `scripts/factory/manifests/routes-web.yaml:297,301,305,309` = `batches.view`/`batches.view`/`batches.update`/`batches.create`; `apps/web/src/hooks/permissionsMap.generated.ts` matches the seeder — `ExportFrontendPermissionsMapCommandTest` 3/19 green on PG and both artefact SHA-256s equal the handback's declared values (`f6010d61…`, `20177320…`) |
| 10 | Push ledger completeness | **VERIFIED** | 75 non-doc lane files vs 75 ledger rows, `comm` shows zero unassigned and zero phantom, `uniq -d` shows zero duplicates. Push 3 carries `.github/workflows/ci.yml` (`:148`), `feature-lane-manifest.json` (`:198`) and all Option B files (`BatchResource.php`, `BatchController.php`, `FEFOInventoryService.php`). Caveat: rider R3's `types.ts` is Push 5 while its wire change is Push 3 → N-4(r2) |
| 11 | Nothing from A-1b or later slipped in | **VERIFIED** | `BatchActionAccess.php` unchanged in the fix round and still allow-lists exactly `['batches.view','batches.traceability','batches.delete','batches.recall']` (`:21`); `routes.php` unchanged (no middleware on create/update/transfer/write-off, asserted at `BatchActionPermissionsTest.php:35-41`); no technician grant; `LotActionPermissionDelta.php` unchanged → no NULL-team adoption. Scope census below |

## Findings

### BLOCKER

**B-1(r2) — `loadScopedResourceRelations()` runs an authorization check AFTER the mutation has committed: a successful write returns 403 with no rollback (activated path).**
`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:105-110` calls `resolvedReadLocationIds($request)` (`:56-73`) in the *response-shaping* step. That method re-reads request **input** (`:61-65`, `Request::validate()` reads body as well as query) and, when anything was requested, delegates to `LocationScopeResolver::resolve()`, which **throws `AuthorizationException`** on an out-of-scope id (`apps/api/app/Modules/Company/Services/LocationScopeResolver.php:41-43`). Every mutation calls it after the write: `:241` (update), `:280` (recall), `:477` (transfer), `:516` (writeOff), `:223` (store). None of these handlers wraps the write in a transaction that includes the resolve.
Empirically reproduced on PostgreSQL at this HEAD (temporary probe file, since removed; tree clean): `PATCH /api/v1/batches/{uuid}` with body `{"notes":"probe-note","location_id":<foreign location>}`, flag ON, actor restricted to one location →
```
PATCH STATUS=403
BODY={"error":{"code":"FORBIDDEN","message":"You do not have permission to perform this action..."}}
NOTES_IN_DB="probe-note"
```
The update was persisted and the caller was told it was forbidden.
Worst path is `POST /api/v1/batches/{uuid}/write-off`, where `location_id` is a **required body field** (`apps/api/app/Modules/BatchExpiry/Presentation/Requests/WriteOffBatchRequest.php:35`) validated only against company scope (`ScopedExists::company`). A location-restricted operator writing off at a company location outside their `allowed_location_ids` commits the stock decrement **and the GL journal entry** and then receives 403; the natural client reaction (retry) posts a second write-off. The write-off probe could not reach the resolve in the `BatchPermissionFixture` (the service raised `InsufficientStockException` first, because the fixture creates batch stock but no aggregate inventory), so the write-off leg is reasoned from the same code path rather than executed — the `PATCH` reproduction proves the mechanism.
Pre-fix-round `update`/`recall` did `$result->refresh()->load(['product','batchStock'])` with no authorization call (`git show 04e60530c:…BatchController.php:238,277`), so this is introduced by commit `a491b5415`.
**Minimum fix:** resolve the read scope **once, before** the write (or derive it from `LocationContext::getAllowedLocationIds()` only, never from request input) and pass the resolved list into `loadScopedResourceRelations()`; the response-shaping step must not be able to throw an authorization exception. Add an HTTP test for `write-off` and `PATCH` with an out-of-scope body `location_id` asserting either a pre-write 403 with a seven-table snapshot unchanged, or a 200 with the correct scoped payload.

**B-2(r2) — a third flag-off behaviour change, outside the two declared Option B exceptions: `POST /api/v1/users/{userId}/roles` now returns 422 for every tenant at Push 3, and the flag cannot roll it back.**
`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:374-376`:
```php
$memberships = UserCompanyMembership::query()->where('user_id', $userId)->where('status', MembershipStatus::Active)…->get();
$membership = $memberships->firstWhere('company_id', $companyId);
abort_if($membership === null, 422, 'Active company membership required.');
```
Pre-lane `assignRole` had no such requirement (`git show 4373ba2f6:…RoleController.php`, `assignRole` = validate → `resolveTenantUser` → `assignRole`), so assigning a role to a tenant user who has no active membership in the *currently selected* company previously succeeded and now 422s. There is no `activation->enforced()` guard anywhere in `assignRole`. Plan §8.8 (`docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-10.md:1745`, "Dedicated assignment requires an active membership in the current company") mandates the behaviour, but §6.1's "While false" list (`:439-446`) and the Push-3 release note (`docs/handoff/RELEASE-NOTES-WLOTA-1a-2026-09-10.md:7-16`) both still assert flag-off inertness with only the two batch-read exceptions. Per this gate's rule, an undeclared flag-off behaviour change blocks.
Failure scenario: Push 3 lands; an admin who selected company B tries to grant a role to a user whose only active membership is company A and gets a 422 they did not get yesterday, with no flag to turn it off.
**Minimum fix (may be declaration-only):** either an orchestrator ruling that amends §6.1 to declare a third Push-3 exception and adds it to the release note (plus a flag-off HTTP test pinning the 422 and pinning that the previously-working case is deliberately withdrawn), or gate `:374-376` behind `activation->enforced()`.

### MAJOR

**M-1(r2) — the R1/I-1 resolution is claimed for five paths but only two are pinned; `recall`, `transfer` and `writeOff` response scoping has no test.**
The handback row asserts "All five mutation/create resource paths load scoped stock". The code does (`BatchController.php:223,241,280,477,516`), but the only response-scoping tests are `BatchReadLocationScopeTest.php:136-148` (`PATCH`, restricted actor) and `BatchActionPermissionsTest.php:86-95` (`POST`, flag off). `recall` (`:280`), `transfer` (`:477`) and `writeOff` (`:516`) responses are unpinned in either flag state, and their pre-fix loads differed (`transfer`/`writeOff` previously loaded `batchStock.location`, `git show 04e60530c:…BatchController.php:474,513`). I-1 named exactly these four mutation responses.
**Minimum fix:** one HTTP test per remaining path asserting the restricted actor's `total_quantity`/`available_quantity`/`batch_stock` are the membership-scoped values (as `:136-148` does for update).

**M-2(r2) — `resolvedReadLocationIds()` conflates a write-target location with a read scope, so mutation responses narrow their totals for *unrestricted* actors too.**
`BatchController.php:107` passes the whole `$request` into `resolvedReadLocationIds`, which at `:65` treats any body/query `location_id` as a requested read scope. For an **unrestricted** actor, `LocationScopeResolver::effectiveAllowedIds` returns all company locations (`LocationScopeResolver.php:59-61`), so `resolve()` returns `[thatOneLocation]` (`:45`) and the write-off/PATCH response's `total_quantity`, `available_quantity` and `batch_stock[]` shrink to the single write-off location instead of the whole lot — flag ON, for an admin with no location restriction at all. Nothing in §6.1, §6.3 or the release note describes that; §6.3 says the totals are "physical on-hand"/"on-hand minus reserved". No test covers it.
**Minimum fix:** derive the response scope from membership only (`LocationContext::getAllowedLocationIds`) rather than from request input, and pin an unrestricted write-off/PATCH response as company-wide totals.

**M-3(r2) — `POST /api/v1/batches` gains a `batch_stock` key it never emitted before, flag-independent, and the release note does not say so.**
Pre-lane `store()` loaded only `['product']` (`git show 4373ba2f6:…BatchController.php:148`), so `BatchResource`'s `whenLoaded('batchStock')` (`BatchResource.php:60`) **omitted** the key on create. At HEAD the relation is always loaded (`BatchController.php:223`), so create now returns `"batch_stock": []` in addition to the string totals. It is pinned (`BatchActionPermissionsTest.php:93`) and arguably implied by declared exception (a), but the release note only mentions the totals (`RELEASE-NOTES-WLOTA-1a-2026-09-10.md:11`, "A newly created empty batch returns genuine `"0.0000"` totals"), and no FE consumer census covers a newly-present key on create.
**Minimum fix:** add the new create-response key to the Push-3 release note and to REALIGNMENT-LOG, or restore `whenLoaded` semantics for create.

### MINOR

**N-1(r2) — handback citation imprecision for N-3.** The Fix-round table (`docs/handoff/HANDBACK-WLOTA-1a-2026-09-09.md:36`) cites `RolesAndPermissionsSeeder.php:35` for "N-3/N-4 stderr marker limited to marked/activated paths". `:35` is the N-4 null-tenant fallback; the N-3 substance is the two remaining call sites at `:47` and `:52`. Substance verified, cite wrong.

**N-2(r2) — the handback's PG assertion counts are not reproducible.** Re-runs: `LotActionPermissionDeltaTest` 12 tests / **126** assertions (handback `:59` says 122); `GeneralManagerAssignmentTest` 7 tests / **108** assertions (handback `:60` says 116). Test counts and exit codes match everywhere; assertion totals do not, so the handback's table cannot be used as a fingerprint.

**N-3(r2) — dead branch left behind.** `BatchResource.php:60` still guards `batch_stock` with `whenLoaded('batchStock')`, unreachable now that `:23-25` throws when the relation is unloaded. Harmless, but it hides the new invariant from a reader.

**N-4(r2) — the R3 FE contract ships one push after the wire change it describes, and two residual hand-rolled inaccuracies remain in the file the fix round touched.** `apps/web/src/features/batches/types.ts` is Push 5 in the ledger (`HANDBACK…:210`) while the string totals are Push 3, so between the two pushes the served bundle declares `number`. Runtime-tolerant only because `formatQuantity` accepts `string | number` (`apps/web/src/lib/decimal.ts:206`) and `ExpiryWriteOffPage.tsx:129,140,155,245` routes everything through it. Separately, `types.ts:49-54` still types `batch_stock[]` members as `string | number` unions although the API now emits strings for all four, and `types.ts:18` declares `product_variant_id` — a key the API does not emit (`BatchResource.php:31` emits `variant_id`) — now cemented by the new `satisfies Batch` fixture (`BatchPermissions.test.tsx:9-13`).

**N-5(r2) — the new early-return seeder path can NPE outside a console command.** `RolesAndPermissionsSeeder.php:82` calls `$this->command->info($marker)`; `Seeder::$command` is unset when the seeder is constructed and `run()` directly. Same pre-existing pattern as `createLegacyRoles()`, but the marked-tenant early return (`:51-54`) adds a second occurrence on a path that otherwise touches nothing.

## PostgreSQL and SQLite runs

All from `<worktree>/apps/api`, one file per invocation, sequentially, `DB_DATABASE=DB_CENTRAL_DATABASE=autoerp_test_w`, port 5433. Before starting, `pg_stat_activity` showed only `postgres` and `iziposcentral_rfqe2e` — the DB was exclusive. No full suite, no parallelism.

| File | Runner | Result | Matches handback? |
|---|---|---|---|
| `tests/Feature/Identity/LotActionPermissionDeltaTest.php` | PG `phpunit-pgsql.xml` | OK 12 tests / 126 assertions, exit 0 | tests ✅, assertions ✗ (122 claimed) |
| `tests/Feature/Identity/GeneralManagerAssignmentTest.php` | PG | OK 7 / 108, exit 0 | tests ✅, assertions ✗ (116 claimed) |
| `tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | PG | OK 6 / 34, exit 0 | ✅ exact |
| `tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | PG | OK 9 / 54, exit 0 | ✅ exact |
| `tests/Feature/Console/LotActionReseedMarkerTest.php` | PG | OK 6 / 24, exit 0 | ✅ exact |
| `tests/Feature/Identity/LotActionSeededRoleMatrixTest.php` | PG | OK 5 / 38, exit 0 | ✅ exact |
| `tests/Feature/Identity/RoleIndexResponseContractTest.php` | PG | OK 1 / 12, exit 0 | ✅ exact |
| `tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php` | PG | OK 5 / 38, exit 0 | ✅ exact |
| `tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php` | PG | OK 3 / 19, exit 0 | ✅ exact |
| `tests/Feature/Identity/LotActionPermissionDeltaTest.php` | SQLite `phpunit.xml` | OK 12 / 45, **1 skipped**, exit 0 | ✅ exact |
| `tests/Feature/Identity/GeneralManagerAssignmentTest.php` | SQLite `phpunit.xml` | OK 7 / 22, **1 skipped**, exit 0 | ✅ exact |
| `tests/Architecture/BatchTraceabilityModuleBoundaryTest.php` | SQLite | OK 1 / 4, exit 0 | ✅ |
| *(temporary probe, removed)* | PG | reproduced B-1(r2): PATCH 403 with the write persisted | n/a |

No environmental failures. `autoerp_test_w` existed and was reachable throughout.

## Static gates

- `php tools/feature-lane-manifest-check.php` → **exit 0**; message confirms 1529 Feature classes / 74 groups, every declared lane present in `ci.yml`, every `--filter` entry anchored and uniquely matched, 1264 parked classes (matching the new `gated_ceiling`), 1 coverage-debt class (pre-existing).
- Scoped PHPStan level 8 on all **31** changed `apps/api/{app,database}` PHP files → **`[OK] No errors`**.
- `php artisan typescript:transform` regenerated output **byte-identical** to `packages/shared/types/generated.d.ts` (the command ignores `--output` and wrote under `apps/api/resources/private/…`; that untracked directory was removed and the tree re-verified clean).
- Generated-artefact SHA-256s equal the handback's declared values for both `generated.d.ts` and `permissionsMap.generated.ts`.

## Push-ledger check

Reconciled mechanically: 75 non-doc files in `4373ba2f6..2fa724c1d` vs 75 ledger rows; `comm -23` and `comm -13` both empty; no duplicate assignment. Push 3 correctly owns `.github/workflows/ci.yml`, `apps/api/tests/feature-lane-manifest.json`, and every Option B file (`BatchResource.php`, `BatchController.php`, `FEFOInventoryService.php`, `routes.php`, `BatchActionAccess.php`, seeder, delta service). Push 2 owns only the migration and its schema test. Pushes 1 and 4 are operations-only, as stated. One residual ordering concern recorded as N-4(r2): rider R3's `types.ts` sits in Push 5 while the wire change it documents ships in Push 3.

## Scope census (fix-round files outside the registers' asks)

36 of the 37 fix-round files map one-to-one onto a gate-r1 register item or a plan-rev-10 §0 ruling (B-1, B-2/R1-R3, M-1..M-5, N-1..N-5, I-1..I-9, FE B-1..B-7 and the three FE minors, manifest/CI raise, handback/release-notes/tickets). The one file with no register ask:

- `scripts/factory/manifests/routes-web.yaml` (`:297,301,305,309`) — generated route manifest, four `permission: null` rows regenerated to `batches.view`/`batches.view`/`batches.update`/`batches.create`. **Classification: in-scope generated-artefact resync**, not scope creep — it is the manifest projection of this lane's own `apps/web/src/routes/index.tsx` gating, the generator and routes were not modified, and the handback declares it (`:45`) and assigns it to Push 5 (`:222`).

Nothing from A-1b or later: `BatchActionAccess.php` byte-identical to `04e60530c` and still allow-listing four permissions (`:21`); `routes.php` byte-identical (no middleware on create/update/transfer/write-off); no `technician` grant (`RolesAndPermissionsSeeder.php:99-102`); `LotActionPermissionDelta.php` byte-identical → no legacy NULL-team adoption.

## Tree state after review

`git status --short` → empty (0 lines). HEAD still `2fa724c1d83098e12d05024e5e51b083d5566ed0` on `lane/w-lot-a-1a`. The temporary probe test and the `typescript:transform` artefact directory were both removed; no tracked file was modified, and nothing was committed, merged, pushed or deployed.

**What to fix before merge:** hoist the read-scope resolution out of the post-write response step so `PATCH /batches/{uuid}` and `POST /batches/{uuid}/write-off` can no longer commit a write and then return 403 (B-1(r2)), and an orchestrator ruling + §6.1/release-note amendment for the third flag-off change on `POST /users/{userId}/roles` (B-2(r2)).

## Orchestrator rulings (session_01AcU81as26G2apodoimmyq7, 2026-09-10)
- **B-1(r2): FIX** — response scope derives from membership only (`LocationContext::getAllowedLocationIds()`), resolved once before the write; the response step must never throw. This also closes **M-2(r2)** (no request-input narrowing for unrestricted actors). Tests: PATCH + write-off with out-of-scope body `location_id` (pre-write 403 + seven-table snapshot unchanged, and unrestricted actor gets company-wide totals).
- **B-2(r2): GATE behind `activation->enforced()`** — plan rev 10 §6.1 "While false" is the Push-3 dormancy contract and takes precedence over §8.8's wording; the membership requirement activates with the flag. Flag-off HTTP test pins the legacy (no-membership-required) behaviour; flag-on test pins the 422. Owner may overturn to "declare as third exception" — if so, §6.1 + release note + REALIGNMENT-LOG get the entry instead.
- **M-1(r2): FIX** — one HTTP test per remaining path (recall, transfer, write-off).
- **M-3(r2): DECLARE** — add the `batch_stock: []` create-response key to the release note and REALIGNMENT-LOG (no code change).
- Minors N-1..N-5(r2): fix in the same round (N-4: `types.ts` `batch_stock[]` members → string, drop `product_variant_id` or align to `variant_id`; move `types.ts` to Push 3's ledger row if the frontend reviewer agrees, else document the one-push tolerance).
- Fix round 2 prompt is written after the inventory-costing and frontend-conventions r2 registers land (one reviewer at a time on this laptop).
