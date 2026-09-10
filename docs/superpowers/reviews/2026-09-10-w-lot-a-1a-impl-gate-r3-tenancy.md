# Gate r3 — W-LOT-A-1a (tenancy-authz-reviewer, 2026-09-10)

**Audited HEAD:** code at `52f5ad796`, docs at `a7010fe4d` on `lane/w-lot-a-1a`. Rounds 2+2b diff `2fa724c1d..52f5ad796` (19 files), whole-lane diff `4373ba2f6..52f5ad796` (83 files), worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w-lot-a-1a`. Read-only: nothing edited, committed, merged, pushed or deployed.

**Deviation from the brief's premise (benign, confirmed with the orchestrator mid-review):** the three doc files were NOT uncommitted at review time. The implementer committed them as `a7010fe4d "Phase 1.2.10: Record the transfer scope ruling and verification"` after the brief was written. `git diff --name-only 52f5ad796..a7010fe4d` = exactly `docs/handoff/HANDBACK-WLOTA-1a-2026-09-09.md`, `docs/handoff/RELEASE-NOTES-WLOTA-1a-2026-09-10.md`, `docs/superpowers/tickets/2026-09-10-batch-transfer-missing-movement-reference.md` — **docs-only, no code change**. The worktree was clean on arrival and clean on exit. The parent-repo `REALIGNMENT-LOG.md` edit remains uncommitted (`M`) as intended.

## Verdict

```
VERDICT: CHANGES-REQUIRED
BLOCKER=0 MAJOR=1 MINOR=4
```

Every r2 blocker is genuinely closed, verified in code and re-executed on PostgreSQL. The single MAJOR is a push-ledger sequencing defect found by mechanical reconciliation, not a tenancy/authz defect: the Push-3 file set is red on its own.

## Gate r2 closure table

| r2 item | Claimed resolution | Status | Evidence `path:line` |
|---|---|---|---|
| **B-1(r2)** post-write 403 (write committed, caller told forbidden) | membership-only scope captured once before the write; response step cannot throw | **VERIFIED** | New `mutationLocationIds()` at `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:111-127`: flag guard `:113-115`, membership read `:118` (`LocationContext::getAllowedLocationIds`, **no** request input), write-target authorisation `:120-123` (`abort_if` 403 on `location_id`/`from_location_id`/`to_location_id`), returns membership only `:126`. Called as the **first statement** of every mutation: `:222` store, `:260` update, `:296` recall, `:476` transfer, `:519` writeOff. `loadScopedResourceRelations($batch, $locations)` `:130-134` is a bare `$batch->load()` — no resolver, no authorisation, cannot throw. Independent scan: every `locationScopeResolver->resolve` / `resolvedReadLocationIds` call site (`:89, :190, :324, :357, :371, :416, :449`) is a pure-read endpoint; **no mutation path can reach an authorisation throw** |
| **B-1(r2)** tests: pre-write 403 + snapshot | restricted PATCH/write-off 403 before the write, retries create nothing | **VERIFIED (exceeds ask)** | `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:156-175`. Snapshot at `:167` covers **nine** tables (the asked-for seven plus `stock_levels` and `stock_movements`). PATCH `:169` → `assertForbidden` + `assertSame($before, $snapshot())` `:170`; write-off twice `:171-174` → 403 + snapshot identical each time |
| **B-1(r2)/M-2(r2)** unrestricted = company-wide | 200 with company-wide totals; one write-off = one movement | **VERIFIED** | `:177-198`: `restrict(null)` `:188`, fixture 10+7, asserts `'16.0000'→'15.0000'` then `'15.0000'→'14.0000'` `:190-193`, `assertJsonCount(2,'data.batch_stock')` `:193`, and `assertSame($before + 1, DB::table('stock_movements')->count())` `:194` — exactly one row per success. PATCH with the same out-of-scope-for-a-restricted-actor body `location_id` also returns company-wide `:195-196` |
| **B-2(r2)** undeclared flag-off 422 on `POST /users/{id}/roles` | gated behind `activation->enforced()` | **VERIFIED** | `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:378` now `abort_if($this->activation->enforced() && $membership === null, 422, …)`; service injected `:68`; guard calls made null-safe `:380, :386` (`$membership?->allowed_location_ids`). Flag-off null membership is safe: `GeneralManagerAssignmentGuard::assertAssignable` early-returns unless `general_manager` is in the effective roles (`apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php:21`), a role that does not exist while the flag is false. §6.1 amended (`plan rev 11:433`) |
| **B-2(r2)** flag-off/flag-on pins | HTTP test both ways | **VERIFIED** | `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php:18-27`: target user with **no** membership → flag off `assertOk` + `hasRole('viewer')` `:21-22`; flag on → `assertStatus(422)` + `assertJsonPath('message','Active company membership required.')` `:25-26`. Caveat: proves "succeeds", not byte-equality with `4373ba2f6`'s response body — see N-4(r3) note in the table below (not a finding) |
| **M-1(r2)** recall/transfer/write-off responses unpinned | real HTTP tests in the allowlisted class | **VERIFIED** | recall `BatchReadLocationScopeTest.php:200-211`, write-off `:270-286` (both assert membership-scoped `total_quantity`/`available_quantity`/`assertJsonCount(1,…)`/`batch_stock.0.location_id`); transfer `:213-233` present but skipped (round 2b). Class **is** in the CI PG allowlist (`.github/workflows/ci.yml:1133`) |
| **M-1(r2)** flag-off contracts duplicated into the allowlisted class | expired/expiring/create | **VERIFIED** | `BatchReadLocationScopeTest.php:288-311`: `batches.view` actually revoked and asserted absent `:293-295`, `/batches/expired?location_ids[]=` `:302-304`, `/batches/expiring?location_id=` `:306-308`, create `batch_stock: []` `:309-310` |
| **M-1(r2)** `BatchActionPermissionsTest` stays out of the allowlist | owner ruling | **VERIFIED** | `grep` of `ci.yml` returns `BatchReadLocationScopeTest` ×1, `GeneralManagerAssignmentTest` ×1, `LotActionPermissionDeltaTest` ×1 and **zero** occurrences of `BatchActionPermissionsTest` or `RoleProvisioningSourceSchemaTest`. Twelve lane classes in the filter, unchanged since r2 |
| **M-3(r2)** declare `batch_stock: []` on create | release note + REALIGNMENT-LOG | **VERIFIED** | `docs/handoff/RELEASE-NOTES-WLOTA-1a-2026-09-10.md:7` ("an explicit `batch_stock: []` (the key was previously omitted). This create-response shape change also applies with the flag off"); `/Users/houssamr/Projects/syneriva/docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md:14` (uncommitted `M`, entry extended) |
| **N-1(r2)** cite drift on N-3 | cite both emitter call sites | **VERIFIED** | handback `:36` now cites `RolesAndPermissionsSeeder.php:48` and `:53`; actual emitter calls are exactly `:48` (ACTIVATED) and `:53` (LEGACY) |
| **N-2(r2)** assertion counts not reproducible | declared unstable, not a fingerprint | **VERIFIED (closed by declaration)** | handback `:63` of the round-2 table. My fresh runs again differ (GM **138** vs 118 claimed; Delta **138** vs 132 claimed) — test counts match exactly (8, 12). Recorded as an observation, not a finding |
| **N-3(r2)** dead `whenLoaded` branch | removed | **VERIFIED** | `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:60-65` is now an unconditional map. **Fail-closed, not a lazy-load leak**: `:23-25` throws `LogicException` when `batchStock` is unloaded. I enumerated all 11 `BatchResource` construction sites (`BatchController.php:195,213,251,270,310,329,362,465,508,548`) and confirmed every one eager-loads `batchStock` — repository `BatchRepository.php:34,153`, `getByProduct` `:112`, FEFO `FEFOInventoryService.php:859,903`, `loadScopedResourceRelations` `:132`, flag-off `show` `:209`. No path can 500 |
| **N-4(r2)/F-1(r2)** `types.ts` inaccuracies | `variant_id`, string members, UUID location | **VERIFIED** | `apps/web/src/features/batches/types.ts:18` `variant_id: string \| null`; `:50-55` all four `batch_stock[]` members `string`; `:274-275` `ExpiredBatchStock.location_id: string`; `:48` `quantity_decimals: number`. `product?:` correctly stays **optional** (`:44`) because `/products/{id}/batch-stock` omits it, and `getQuantityDecimals` handles null (`apps/web/src/lib/quantityScale.ts:9-16`, clamped 0..4). Fixtures corrected: `tenantScope.test.tsx:118,139,143-145`, `ExpiryWriteOffPage.test.tsx:104,119,163`, `BatchPermissions.test.tsx:10` |
| **N-5(r2)** seeder NPE outside console | null-safe | **VERIFIED** | `apps/api/database/seeders/RolesAndPermissionsSeeder.php:84-86` (`/** @var Command\|null */` + `$command?->info($marker)`) |
| **Round 2b** known-red gating | skip unless opt-in, ticket named | **VERIFIED** | `BatchReadLocationScopeTest.php:215-217` — `getenv('WLOTA1A_RUN_KNOWN_REDS') !== '1'` → `markTestSkipped` naming the ticket. Test body `:219-232` unchanged in substance: real `postJson` `:226`, no mock, no schema relaxation. `WLOTA1A_RUN_KNOWN_REDS` appears **nowhere** in `ci.yml` or any `phpunit*.xml` → CI always skips |
| **Round 2b** sibling failure/rollback pin | 500/23502 + snapshot unchanged, ticket named | **VERIFIED** | `:235-268`: ticket named `:237-238`, ten-table snapshot `:254`, `assertSame('23502', $exception->errorInfo[0])` `:263`, `inventory_batch_movements` `:264`, `movement_id` `:265`, rollback `assertSame($before, $snapshot())` `:267`, and `self::fail(...)` `:261` forces retirement when the writer is repaired |
| **Round 2b** service/migration untouched | empty diffs | **VERIFIED** | `git diff 4373ba2f6..52f5ad796 -- …/BatchStockService.php` → **0 lines**; `… 2026_01_05_150002_create_inventory_batch_movements_table.php` → **0 lines**; `…/Entities/BatchStock.php` → **0 lines**. `movement_id` is `foreignUuid(...)->constrained('stock_movements')` i.e. NOT NULL at migration `:22` |
| **Round 2b** ticket content | ruling, caller grep, priority | **VERIFIED, census independently reproduced** | `docs/superpowers/tickets/2026-09-10-batch-transfer-missing-movement-reference.md:11` (out of scope, after T-2 S1), `:24-30` census, `:30` priority. My own independent grep of `apps/web/src` and `apps/pos/src` found **no** caller of `/batches/{uuid}/transfer` — the ticket's census is accurate and honestly qualified ("does not establish whether external API/mobile callers exist") |
| **Round 2b** handback | `blocking_decision: none` | **VERIFIED** | `docs/handoff/HANDBACK-WLOTA-1a-2026-09-09.md:4` |

## Required verifications (items 1–10) and r2 citation regression

| # | Requirement | Status | Evidence |
|---|---|---|---|
| 1 | B-1(r2): membership-only scope, once, before the write; response cannot throw; write targets pre-authorised; tests; PG re-run | **VERIFIED** | See closure table. PG: `BatchReadLocationScopeTest` **16 tests / 109 assertions / 1 skipped, exit 0**. Note: for `store` the target loop is vacuous (`CreateBatchRequest.php:33-41` has no `location_id` rule and create writes no stock — confirmed by `batch_stock: []`); harmless, fail-closed. `getAllowedLocationIds` returns `[]` (not `null`) when there is no membership (`LocationContext.php:198-200`) → any supplied target 403s and the response scope is empty: **fail-closed** |
| 1b | `resolvedReadLocationIds()` survives only on pure reads | **VERIFIED** | `BatchController.php:89` show, `:190` index, `:324` expiring, `:371` stock, `:416` POS, `:449` productBatchStock; `:357` (`expired`) uses the resolver directly and is **byte-identical to `4373ba2f6`** (diffed the whole method). Zero mutation call sites |
| 2 | B-2(r2): injection, `enforced()` gate, flag-off + flag-on HTTP pins; PG re-run | **VERIFIED** | See closure table. PG: `GeneralManagerAssignmentTest` **8 tests, exit 0** |
| 3 | M-1(r2): recall/write-off in the allowlisted class; flag-off contracts duplicated; `BatchActionPermissionsTest` outside | **VERIFIED** | See closure table |
| 4 | Round 2b: unchanged substance, skip + pin, green without / one failure with the env var, service+migration untouched, ticket, handback | **VERIFIED** | Default run **16/109/1 skipped exit 0**; opt-in run **16/110, exactly 1 failure, exit 1**, the failure being `test_transfer_controller_response_uses_all_membership_locations` with `SQLSTATE[23502] … null value in column "movement_id" of relation "inventory_batch_movements"`. The captured SQL confirms the diagnosis: `insert into "inventory_batch_movements" ("tenant_id","batch_id","quantity")` — `movement_id` omitted. Both numbers match the handback (`:62-63` of the 2b block) exactly |
| 5 | M-3(r2): release note + REALIGNMENT-LOG | **VERIFIED** | `RELEASE-NOTES…:7`; parent `REALIGNMENT-LOG.md:14-18` (uncommitted `M`) |
| 6 | N-1..N-5(r2) closures | **VERIFIED** | See closure table. Fresh counts recorded below; assertion instability is now declared |
| 7 | Push ledger: every non-doc file exactly once; string-tolerance slice in Push 3; gating files in Push 5; ci.yml + manifest in Push 3 | **VERIFIED mechanically, but the Push-3 set is internally inconsistent** | `comm` reconciliation: **75 non-doc lane files vs 75 ledger rows**, `uniq -d` empty, `comm -23` empty, `comm -13` empty. `types.ts` (`:283`), `BatchListPage.tsx` (`:278`), `BatchPermissions.test.tsx` (`:281`) all Push 3 ✓; `ci.yml` and `feature-lane-manifest.json` Push 3 ✓; `Sidebar.tsx`, `routes/index.tsx`, `usePermissions.ts`, `permissionsMap.generated.ts`, `RolesPage.tsx` Push 5 ✓. **But** `BatchPermissions.test.tsx` also tests `BatchDetailPage.tsx`, which is Push 5 → **MAJOR M-1(r3)** |
| 8 | Manifest + CI raise if a new class was added; checker exit 0; alternation parses | **VERIFIED — no raise needed** | Rounds 2/2b added **no new test class** (the 2b pin is a new *method* in the pre-existing `BatchReadLocationScopeTest`). `feature-lane-manifest.json` and `ci.yml` blobs are **byte-identical to `2fa724c1d`** (`e88072c2`, `25b767ec`), so no re-verification of the alternation was required. `php tools/feature-lane-manifest-check.php` → **exit 0**, "1529 Feature classes in 74 groups; every `--filter` entry is anchored and uniquely matched against 1941 test classes", with only the two pre-existing warnings (1264 parked, 1 coverage-debt) — identical to r2 |
| 9 | Generated artefacts byte-identical to fresh regeneration; route manifest `--check` exit 0 | **VERIFIED** | Fresh `php artisan typescript:transform` (560 types) left `packages/shared/types/generated.d.ts` **byte-identical** (`f6010d61…` before and after; `git status` clean). Fresh `php artisan permissions:export-frontend-map --path=<tmp>` **byte-identical** to the committed `permissionsMap.generated.ts` (`diff -q` clean) — so the map still matches the seeder after the round-2 seeder edit. Both SHA-256s equal the handback's declared values (`f6010d61…`, `20177320…`). `node scripts/factory/gen-route-manifest.mjs --check` → **exit 0** |
| 9b | r2 citation regression: legacy delta, allow-list, routes, technician | **VERIFIED** | Blob identity across `04e60530c` / `2fa724c1d` / `52f5ad796`: `LotActionPermissionDelta.php` `a0fac45e` (unchanged since r1 → **no NULL-team adoption**), `BatchActionAccess.php` `5299a5fa`, `routes.php` `fb9e5df6`, `GeneralManagerAssignmentGuard.php` `1e542369`. Allow-list still exactly four permissions (`BatchActionAccess.php:21`). Technician ungranted: `RolesAndPermissionsSeeder.php:103` owner-ruling comment, `:104` loop is exactly `['cashier','viewer','operator']` |
| 10 | Scope census; nothing from A-1b | **VERIFIED** | See Scope census |

## Findings

### BLOCKER

None. No post-write authorisation throw, no cross-tenant read, no technician grant, no `BatchStockService` edit, no red allowlisted class, and no undeclared flag-off behaviour change (the fourth flag-off change **is** declared in both consumer-facing artefacts — see N-1(r3) for the plan-text gap).

### MAJOR

**M-1(r3) — the Push-3 file set is red on its own: `BatchPermissions.test.tsx` moves to Push 3 but three of its cases require `BatchDetailPage.tsx`, which stays in Push 5.**

Plan rev 11 §00 row 13 ruled that `types.ts` and `BatchListPage.tsx` "(with its Vitest)" move to Push 3. The implementer assigned `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx` to Push 3 (handback ledger `:281`; release note `:16` names it explicitly). That file is not only the list's Vitest — it renders `BatchDetailPage` at `:26` and asserts the lane's **new** detail gating:

- `:54-57` "hides edit without batches.update"
- `:58-61` "hides delete without batches.delete"
- `:62-66` "hides recall from the revised manager payload"

`apps/web/src/features/batches/pages/BatchDetailPage.tsx` is ledger **Push 5** (handback `:276`). Pre-lane, that page has **no** permission gating at all — `git show 4373ba2f6:…/BatchDetailPage.tsx:75-77` is `canEdit = batch.is_active && !batch.is_recalled`, `canRecall = … && !batch.is_expired`, `canDelete = batch.is_active`, with zero `usePermissions`/`hasPermission` occurrences; the gating is added by this lane at `:77-79`. For the fixture (`is_active: true, is_recalled: false, is_expired: false`, `permissions: []` per `beforeEach` `:28`) all three affordances render (`git show 4373ba2f6:…:117,126,135`), so the three cases above **fail** with the Push-3 file set. (The fourth detail case, `:67-71`, passes accidentally.)

Consequence: promoting Push 3 as ledgered yields three failing Vitest cases — a red CI on a promotion the plan requires to be clean, at the one step whose whole purpose is to ship the string contract safely. Moving `BatchDetailPage.tsx` into Push 3 is **not** an option: plan rev 11 §0 promotion order forbids web gating reaching staging before the Push-4 delta grants `batches.view`.

**Minimum fix:** split the file — keep the four list cases (`:35-53`, which only need `BatchListPage.tsx` + `types.ts`) in a Push-3 Vitest, and leave the four `BatchDetailPage` gating cases in a Push-5 Vitest. Then re-reconcile the ledger (76 rows) and update release note `:16` to name the Push-3 file actually promoted.

### MINOR

**N-1(r3) — plan §6.1's exhaustive "Nothing else changes while false" was not amended for the fourth flag-off change (`product.quantity_decimals`).**
Round 2 added `product.unitOfMeasure` to `BatchRepository::findVisibleByUuid` (`:34`), `getByCompany` (`:153`) and `loadScopedResourceRelations` (`BatchController.php:132`), plus flag-off `show` (`:209`). Baseline loaded only `['product']` (`git show 4373ba2f6:…/BatchRepository.php:116`; `…/BatchController.php` `show` → `load(['product','batchStock.location'])`), so `quantity_decimals` fell back to the hardcoded `4` (`BatchResource.php:55-57`). Flag-off `/batches` and `/batches/{uuid}` now emit the unit's real precision — pinned by the changed expectation `4 → 2` at `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php:67`. (`/batches/expiring` and `/batches/expired` already behaved this way at baseline — `git show 4373ba2f6:…/FEFOInventoryService.php:858,898` — so the change makes list/detail *consistent* with them.)
This is **ordered** by plan rev 11 §00 row 16 and **declared** in `RELEASE-NOTES…:7` and `REALIGNMENT-LOG.md:16`. But plan rev 11 `:434` still lists only three exceptions and closes with "Nothing else changes while false", which is now literally false. Declaration-only fix: add the precision bullet to §6.1's exception list.

**N-2(r3) — `getExpiringProducts` swapped a truthy guard for `!== null`, turning a falsy-but-non-null `location_id` into a PostgreSQL UUID 500 on the flag-off path.**
`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:855` is now `if ($locationId !== null)` (baseline: `if ($locationId)`), feeding `whereIn('location_id', …)` at `:856` and `:861`. Flag-off, `BatchController::expiring()` `:321` passes raw input with **no** UUID validation (`resolvedReadLocationIds` returns at `:58-59` before its `uuid` rule). So `?location_id=0` now reaches a `uuid` column as `'0'` → SQLSTATE 22P02 → 500, where baseline returned 200 (filter ignored). Reachability is narrow: `ConvertEmptyStringsToNull` is in the default global stack (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:462`, not removed in `bootstrap/app.php`) so `?location_id=` is safe, no first-party caller sends the parameter at all (`apps/web/src/features/batches/api/batches.ts:111` sends only `days`), any other malformed value already 500'd at baseline via `whereRaw`, and flag-on validates to 422. Fix: `Str::isUuid()` guard (or filter to UUIDs) on the flag-off path, per the repo's own PG-UUID rule.

**N-3(r3) — the round-2b pin test asserts a PostgreSQL-specific SQLSTATE with no driver guard, and fails off PostgreSQL (empirically reproduced).**
`apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:263` asserts `'23502'`. I ran that single method on SQLite: `Failed asserting that two strings are identical. -'23502' +'23000'`. No CI path is red — `backend-test` runs `--testsuite=Unit` only (`ci.yml:420`, comment `:175-176`), the `feature-lane-inventory/BatchExpiry` lane is parked behind `vars.SELF_HOSTED_RUNNER_READY` and sets `DB_CONNECTION: pgsql` with a `postgres:16` service when armed (`ci.yml:1671,1678,1708`). So this is a local-developer trap only: anyone running the manifest's own declared selector `./vendor/bin/phpunit tests/Feature/BatchExpiry/` without the PG env gets a red. Inconsistent with the precedent this lane already adopted for the same reason at gate r1 B-1 (`apps/api/tests/Feature/Identity/LotActionPermissionDeltaTest.php:32-34` skips when the driver is not `pgsql`). Fix: the same three-line driver skip.

**N-4(r3) — handback round-2 table cite drift (same class as N-1(r2), which was fixed for the seeder).**
`HANDBACK…:56` cites `BatchReadLocationScopeTest.php:155` and `:176`; `:59` cites `:199`, `:212`, `:230`, `:248`. Of those, `:155`, `:176`, `:199`, `:212` are **blank lines** and `:230`/`:248` are mid-test statements. The method declarations are `:156`, `:177`, `:200`, `:213`, `:235` (pin) and `:270` (write-off). Substance verified everywhere; the citations are off by one or point into a body.

## PostgreSQL and SQLite runs

All from `<worktree>/apps/api`, **one file per invocation, strictly sequential, never the full suite, never in parallel**, env sourced from the committed `docs/sessions/wlota1a/test-env.sh` (`DB_DATABASE` **and** `DB_CENTRAL_DATABASE` = `autoerp_test_w`, host 127.0.0.1, port 5433, `LOT_ACTION_PERMISSIONS_ENFORCE=false`). Swap was 13.1 GB used at start (under the 13.5 GB ceiling); it peaked at 14.2/15.4 GB after the fourth PG leg and recovered to 11.6 GB, so **the full required set was executed** — no reduction to three classes was needed. `pg_stat_activity` showed only `postgres` and `iziposcentral_rfqe2e` alongside my connections; the DB was effectively exclusive.

| File | Runner | Result | Matches handback? |
|---|---|---|---|
| `tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | PG `phpunit-pgsql.xml`, **no** opt-in | **OK 16 tests / 109 assertions / 1 skipped, exit 0** | ✅ exact (`:62` of 2b block) |
| `tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | PG, `WLOTA1A_RUN_KNOWN_REDS=1` | **16 tests / 110 assertions / exactly 1 failure, exit 1** — only `test_transfer_controller_response_uses_all_membership_locations`, `SQLSTATE[23502]` on `inventory_batch_movements.movement_id` | ✅ exact (`:63` of 2b block) |
| `tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | PG | OK 6 / 34, exit 0 | ✅ exact |
| `tests/Feature/Identity/GeneralManagerAssignmentTest.php` | PG | OK **8** / **138**, exit 0 | tests ✅ (8, incl. the new B-2 pin); assertions ✗ (118 claimed) — covered by the declared instability caveat |
| `tests/Feature/Identity/LotActionPermissionDeltaTest.php` | PG | OK 12 / **138**, exit 0 | tests ✅; assertions ✗ (132 claimed) — same declared caveat |
| `tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php` | PG | OK 3 / 19, exit 0 | ✅ exact |
| `tests/Feature/Identity/LotActionPermissionDeltaTest.php` | SQLite `phpunit.xml` | OK 12 / 45, **1 skipped**, exit 0 | ✅ exact |
| `tests/Feature/Identity/GeneralManagerAssignmentTest.php` | SQLite `phpunit.xml` | OK 8 / 26, **1 skipped**, exit 0 | ✅ exact |
| `BatchReadLocationScopeTest::test_transfer_missing_movement_reference_ticket_pins_failure_and_rollback` | SQLite (adversarial probe) | **1 failure** — `-'23502' +'23000'` | n/a → **N-3(r3)** |

No environmental failures. No test file was added, edited or removed by me.

## Static gates

- Scoped **PHPStan level 8** on all five changed `apps/api/{app,database}` PHP files (`BatchRepository.php`, `BatchController.php`, `BatchResource.php`, `RoleController.php`, `RolesAndPermissionsSeeder.php`) → **`[OK] No errors`**.
- `php tools/feature-lane-manifest-check.php` → **exit 0** (captured explicitly), with only the two pre-existing warnings, identical to r2.
- `php artisan typescript:transform` → 560 types, `generated.d.ts` **byte-identical** (`f6010d61…` unchanged), tree still clean.
- `php artisan permissions:export-frontend-map --path=<tmp>` → **byte-identical** to the committed `permissionsMap.generated.ts`; both artefact SHA-256s equal the handback's declared values.
- `node scripts/factory/gen-route-manifest.mjs --check` → **exit 0**.
- `ci.yml` PG `--filter` unchanged since r2 (blob `25b767ec`); 12 lane classes present, `BatchActionPermissionsTest` and `RoleProvisioningSourceSchemaTest` absent per the owner's ruling; `WLOTA1A_RUN_KNOWN_REDS` set nowhere.

## Push-ledger check

Mechanically reconciled: `git diff --name-only 4373ba2f6..52f5ad796 | grep -v '^docs/'` = **75** files; ledger rows = **75** (75 unique). `uniq -d` empty, `comm -23` empty, `comm -13` empty — no duplicate, no unassigned, no phantom. Rounds 2/2b introduced no new file, so the count is unchanged from r2.

The web string-tolerance slice sits in Push 3 as ruled (`types.ts` `:283`, `BatchListPage.tsx` `:278`, `BatchPermissions.test.tsx` `:281`); `ci.yml` `:271` and `feature-lane-manifest.json` `:270` are Push 3; every permission-gating web file remains Push 5. **The defect is not in the reconciliation but in the content of the Push-3 set** — see M-1(r3).

## Scope census

All 19 files in `2fa724c1d..52f5ad796` (plus the 3 docs re-touched in `a7010fe4d`) map one-to-one onto an r2 register item or a plan rev 11 §00 ruling:

- `BatchRepository.php`, `BatchController.php` (`:132`), `BatchResource.php`, `BatchListPage.tsx`, `types.ts` (`quantity_decimals`), `BatchActionPermissionsTest.php` (`4→2`) → frontend M-1(r2) / inventory I-1(r2) / F-2(r2) / F-5(r2), §00 row 16.
- `BatchController.php` (`:111-134` + five call sites), `BatchReadLocationScopeTest.php` (`:156-198`, `:200-211`, `:270-286`) → B-1(r2) + M-2(r2) + M-1(r2).
- `RoleController.php`, `GeneralManagerAssignmentTest.php` → B-2(r2).
- `BatchResource.php` (`whenLoaded` removal) → N-3(r2); `RolesAndPermissionsSeeder.php` → N-5(r2); `types.ts`/`tenantScope.test.tsx`/`ExpiryWriteOffPage.test.tsx`/`BatchPermissions.test.tsx` fixtures → N-4(r2) + F-1(r2); `batch-permissions.spec.ts` (`!== '1'`) → F-6(r2).
- `BatchReadLocationScopeTest.php` (`:215-217`, `:235-268`) + transfer ticket → round-2b ruling.
- Release notes / handback / `2026-09-10-batch-stock-dead-float-accessors.md` (new, `5d847b2ba`) / `2026-09-10-batch-detail-parsefloat-quantities.md` → M-3(r2), I-4(r2), F-3(r2).

**Nothing outside the register asks. Nothing from A-1b or later:** `BatchActionAccess.php` byte-identical to `04e60530c` and still allow-listing exactly four permissions; `routes.php` byte-identical (no middleware on create/update/transfer/write-off); no technician grant; `LotActionPermissionDelta.php` byte-identical → no legacy NULL-team adoption; `BatchStockService.php`, `BatchStock.php` and `2026_01_05_150002_create_inventory_batch_movements_table.php` all have **empty** whole-lane diffs.

## Tree state after review

`git status --short` → **empty (0 lines)**. HEAD `a7010fe4ddd0cb48991068810c7a0a115f03a12c` on `lane/w-lot-a-1a` — unchanged from arrival. The three doc files were already committed as `a7010fe4d` before I started (noted above); the parent-repo `/Users/houssamr/Projects/syneriva/docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` remains `M` and untouched. `typescript:transform`, `gen-route-manifest --check` and the permission-map export all produced byte-identical output, so no tracked file was modified; the temporary map went to the session scratchpad. Nothing was edited, committed, merged, pushed, or deployed, and Dokploy was not touched.

**What to fix before merge:** split `BatchPermissions.test.tsx` so the Push-3 promotion carries only the list cases and the `BatchDetailPage` gating cases stay with `BatchDetailPage.tsx` in Push 5 (M-1(r3)); then the four minors — add the `quantity_decimals` bullet to plan §6.1, a `Str::isUuid` guard on the flag-off `expiring` location filter, a `pgsql` driver skip on the 2b pin test, and correct the handback's six drifted citations.

Orchestrator: session_01AcU81as26G2apodoimmyq7 (reviewer = Claude Opus `tenancy-authz-reviewer` agent, PG runner owner).

## Orchestrator rulings (session_01AcU81as26G2apodoimmyq7, 2026-09-10)
- **M-1(r3): FIX** — split `BatchPermissions.test.tsx`: list cases stay in the Push-3 Vitest (`BatchListPermissions.test.tsx` or equivalent), the four `BatchDetailPage` gating cases move to a Push-5 Vitest; ledger re-reconciled (76 rows); release note `:16` names the Push-3 file. `BatchDetailPage.tsx` stays in Push 5.
- **N-1(r3): FIX in plan rev 11 → rev 12** (orchestrator): §6.1 exception list gains the `product.quantity_decimals` bullet (fourth declared flag-off change, ruled at §00 row 16).
- **N-2(r3): FIX** — `Str::isUuid()` guard (or UUID filter) on the flag-off `expiring` `location_id` path. **N-3(r3): FIX** — `pgsql` driver skip on the 2b pin test (precedent `LotActionPermissionDeltaTest:32-34`). **N-4(r3): FIX** cites.
- Fix round 3 prompt: `docs/handoff/CODEX-PROMPT-WLOTA-1a-fix-round-3-2026-09-10.md`. Inventory-costing r3 and frontend-conventions r3 run against the round-3 HEAD (one at a time); tenancy re-verifies M-1(r3)/N-2/N-3 by a light r4 pass (no full PG re-run needed beyond `BatchReadLocationScopeTest` and `BatchExpiringLocationScopeTest`).
