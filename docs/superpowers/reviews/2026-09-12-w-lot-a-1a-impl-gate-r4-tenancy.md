# W-LOT-A-1a — implementation gate r4, tenancy-authz

| Field | Value |
|---|---|
| Lane | `lane/w-lot-a-1a` |
| Base | `4373ba2f6` (`git merge-base dev lane/w-lot-a-1a`, re-derived) |
| Tip reviewed | `1e19a0f37` (`Phase 1.3.2: Reconcile the batch push ledger and round-three evidence`) |
| Previous gate | r3 on `52f5ad796` — CHANGES-REQUIRED 0B/1M/4m (`docs/superpowers/reviews/2026-09-10-w-lot-a-1a-impl-gate-r3-tenancy.md`) |
| New dev tip analysed for merge impact (NOT merged) | `474e38e2a` (contains T-2 S1 receipt spine + RBAC wave 0a) |
| Date | 2026-09-12 |
| Reviewer | tenancy-authz-reviewer (adversarial, code-grounded) |
| Diff size | 84 files; **76 non-doc** (`git diff --name-only 4373ba2f6...lane/w-lot-a-1a \| grep -v '\.md$' \| wc -l` → 76) |
| Code delta since r3 | 3 non-doc source files + the Vitest split (`git diff --stat 52f5ad796..1e19a0f37`) |

## Verdict

**VERDICT: spec ✅ + quality MERGE-WITH-FIXES — 0 Blockers / 4 Majors / 2 minors.**

Every r3 finding (M-1, N-1..N-4) is CLOSED at `1e19a0f37`, and every r2 blocker/major closure verified at r3 survives by blob identity plus targeted re-verification. All six PostgreSQL classes named in the handback's round-3 verification table reproduce GREEN on a private database, and both split Vitest files pass with the exact counts claimed. **No lane code change is required.**

The four Majors are all **merge-integration actions against the new dev `474e38e2a`** (gate item 7). They are not lane defects — they are work the integration owner MUST perform inside the merge commit, and three of the four turn the merged tree red or silently 403 if skipped.

---

## Item-by-item status

### Round-3 findings

| Finding | Status | Evidence at `1e19a0f37` |
|---|---|---|
| **M-1(r3)** Push-3 Vitest must not depend on the Push-5 `BatchDetailPage` | **CLOSED** | `apps/web/src/features/batches/pages/__tests__/BatchListPermissions.test.tsx:1-5` — the complete import list is `@testing-library/react`, `react-router-dom`, `vitest`, `../../types`, `../BatchListPage`. **Zero** reference to the detail page; its `vi.mock` of `../../hooks/useBatches` exposes only `useBatches` (`:16-18`), not `useBatch`/`useBatchStock`/`useDeleteBatch`/`useRecallBatch` (which only the detail file mocks, `BatchDetailPermissions.test.tsx:17-20`). Four list cases `:30,:34,:38,:43`; five detail cases `BatchDetailPermissions.test.tsx:31,:35,:39,:44,:49`. |
| **M-1(r3)** nine original bodies preserved | **CLOSED — byte-for-byte verified** | Extracted `52f5ad796:apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx` lines 35–77 and concatenated `BatchListPermissions.test.tsx:30-48` + `BatchDetailPermissions.test.tsx:31-54`; `diff` is **empty**. All nine `it()` titles are identical and none was reworded. The retired combined file is absent from `apps/web/src/features/batches/pages/__tests__/` (created and removed inside the lane, so it does not appear in the base diff). |
| **M-1(r3)** ledger reconciles to 76 rows | **CLOSED — independently re-derived** | The per-file ledger is `docs/handoff/HANDBACK-WLOTA-1a-2026-09-09.md:~199-281`. Data rows (excluding the table header) = **76**; `comm -23` and `comm -13` against the 76 non-doc diff paths are **both empty**, `uniq -d` empty. Distribution: Push 2 = 2, Push 3 = 52, Push 5 = 22. Assignment consistent: `BatchListPermissions.test.tsx` → Push 3 (`:280`) with `BatchListPage.tsx` and `types.ts`; `BatchDetailPermissions.test.tsx` → Push 5 (`:281`) alongside `BatchDetailPage.tsx`, `RolesPage.tsx`, `routes/index.tsx`, `Sidebar.tsx`, `usePermissions.ts`, `permissionsMap.generated.ts`. |
| **N-1(r3)** plan §6.1 fourth declared exception | **CLOSED (orchestrator-side, no lane change)** | `docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-12.md:1` header records `§6.1 fourth declared flag-off exception = product.quantity_decimals`. |
| **N-2(r3)** `Str::isUuid()` guard on the flag-off expiring path | **CLOSED** | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:321-322` — `$rawLocationId = $request->input('location_id'); $locationId = is_string($rawLocationId) && Str::isUuid($rawLocationId) ? $rawLocationId : null;` replacing the r3 `(string)` cast. The flag-on arm is untouched at `:324-326` and still routes through `resolvedReadLocationIds()` (`:56-72`), whose `$request->validate(['location_id' => ['sometimes','nullable','uuid'], …])` at `:61-64` keeps returning 422. Real HTTP regression: `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php:26-32` (`test_flag_off_ignores_non_uuid_expiring_location`) sets `lot_action_permissions.enforce => false` and asserts `getJson('/api/v1/batches/expiring?location_id=0')->assertOk()`; the flag-on 422 stays pinned at `:20`. PG re-run **3 tests / 7 assertions, exit 0**. |
| **N-3(r3)** driver skip on the round-2b failure pin | **CLOSED** | `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php:237-239` — `if (DB::getDriverName() !== 'pgsql') { markTestSkipped('The ticketed transfer failure pins PostgreSQL SQLSTATE 23502.'); }`. The known-red success skip (`:215-217`, `WLOTA1A_RUN_KNOWN_REDS`) and the writer are untouched (`git diff 52f5ad796..1e19a0f37` on this file is exactly these 4 added lines). |
| **N-4(r3)** corrected round-2 method citations | **CLOSED — all six re-verified** | `grep -n 'public function test_'` on `BatchReadLocationScopeTest.php` returns `:156`, `:177`, `:200`, `:213`, `:235`, `:274` — an exact match for the handback's corrected citation set, including the write-off method now at `:274`. |

### Round-2 closures — regression check at this tip

`git diff --stat 52f5ad796..1e19a0f37` touches only 4 non-doc files: `BatchController.php` (+3/-1, the N-2 guard), `BatchExpiringLocationScopeTest.php` (+8), `BatchReadLocationScopeTest.php` (+4, the N-3 skip), and the Vitest split. Every other file carrying an r2 closure is **blob-identical to the r3-audited tip**, so each r3 VERIFIED verdict transfers. Targeted re-verification of the items called out in the round-4 brief:

| r2 closure | Status | Evidence at `1e19a0f37` |
|---|---|---|
| Pre-write membership scope (B-1(r2)) | **HOLDS** | `BatchController.php:111-127` unchanged; `mutationLocationIds()` reads membership only (`LocationContext::getAllowedLocationIds`), `abort_if` 403 on request targets, called first in store/update/recall/transfer/writeOff. The N-2 hunk is 200 lines away in `expiring()`, a pure read. `BatchReadLocationScopeTest.php:156` (403-before-write + nine-table snapshot) and `:177` (unrestricted = company-wide, one movement per success) both green in the PG re-run. |
| Activation-gated membership rule (B-2(r2)) | **HOLDS** | `RoleController.php:378` `abort_if($this->activation->enforced() && $membership === null, 422, …)`; flag-off/flag-on HTTP pins at `GeneralManagerAssignmentTest.php:18-27`. PG: **8 tests / 144 assertions, exit 0**. |
| Allowlisted response pins (M-1(r2)) | **HOLDS** | recall `BatchReadLocationScopeTest.php:200`, write-off `:274`, transfer `:213` (round-2b skip), flag-off contract duplication `:292`. Class named in `.github/workflows/ci.yml:1133`. |
| Known-red gated both ways | **HOLDS** | `BatchReadLocationScopeTest.php:215-217` (`getenv('WLOTA1A_RUN_KNOWN_REDS') !== '1'` → skip). Default PG run: **16 tests / 109 assertions / 1 skipped, exit 0** — identical to the r3 numbers. `WLOTA1A_RUN_KNOWN_REDS` appears nowhere in `ci.yml` or any `phpunit*.xml`, so CI always skips. |
| `BatchActionPermissionsTest` / `RoleProvisioningSourceSchemaTest` OUT of the CI allowlist | **HOLDS** | `grep` of `.github/workflows/ci.yml:1133` returns **zero** occurrences of either name. The `--filter` alternation carries exactly **12** lane classes: `LotActionPermissionDeltaTest`, `GeneralManagerAssignmentTest`, `RolesAndPermissionsSeederMarkedTenantTest`, `LotActionSeededRoleMatrixTest`, `GeneralManagerRoleProtectionTest`, `RoleIndexResponseContractTest`, `GeneralManagerAssignmentWriterCensusTest`, `LotActionReseedMarkerTest`, `ApplyLotActionPermissionDeltaCommandTest`, `BatchReadLocationScopeTest`, `BatchExpiringLocationScopeTest`, `BatchTraceReaderContractTest`. |
| NULL-team legacy roles ruling | **HOLDS** | `LotActionPermissionDeltaTest.php:203` `test_null_team_unmarked_general_manager_is_never_adopted`; `:164` real-legacy-catalogue preservation; `:183` scoped arm; `:193` mixed-collision rollback-without-writes. PG: **12 tests / 148 assertions, exit 0**. |
| `general_manager` role protection | **HOLDS** | `apps/api/tests/Feature/Identity/GeneralManagerRoleProtectionTest.php` blob-identical since r3; in the CI allowlist. |
| `LotActionPermissionDelta` idempotency + per-tenant marker | **HOLDS** | Idempotency `LotActionPermissionDeltaTest.php:247` (explicit first/second outcomes), `:253` (re-run preserves role id and custom grants), `:238` (concurrent first apply creates one marked role). Tenant discrimination `:213`, `:225`. Spatie team save/restore `LotActionPermissionDelta.php:47-48,:99` pinned by `:284`. Marker emitters exactly at `RolesAndPermissionsSeeder.php:48` (`ACTIVATED`) and `:53` (`LEGACY`) — re-read and confirmed; both observed live in the PG run (`mode=ACTIVATED outcome=APPLIED`, `mode=LEGACY outcome=ALREADY_APPLIED`). |

### Item 5 — tenant isolation and the migration

| Check | Status | Evidence |
|---|---|---|
| Every new read/write tenant + company + location-membership scoped | **PASS** | Reads route through `resolvedReadLocationIds()` (`BatchController.php:56`) → `LocationScopeResolver::resolve`; writes through `mutationLocationIds()` (`:111`). `LocationContext::getAllowedLocationIds` returns `[]` (not `null`) for no membership → fail-closed. `BatchReadLocationScopeTest.php:347` (`test_every_read_filters_other_branch_and_empty_scope`), `:324` (restricted POS foreign location denied without writes), `BatchExpiringLocationScopeTest.php:34` (empty membership scope → `data: []`). |
| Convention 09 — second company / second location / re-run | **PASS** | `BatchReadLocationScopeTest.php:389` `test_second_company_selected_second_location_and_duplicate_create_are_isolated`: creates a real second company via `POST /api/v1/companies`, **two** locations per company, a second-company product **re-using the same SKU**, and a duplicate-`batch_number` create in both companies — i.e. second-company + second-location + idempotency/collision in one real-HTTP case. |
| Migration tenant-scoped and idempotent | **PASS** | `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php` lives under `migrations/tenant/` (per-tenant DB). `up()` is fully re-runnable: `Schema::hasColumn` guard before the column add; the CHECK constraint, the SQLite triggers and the partial unique index are each read back first and only created when absent, and a divergent existing definition throws rather than silently differing. `down()` refuses while a marked role exists. |
| Convention 09 — new unique key without `company_id` | **PASS (pre-existing waiver, correct)** | The new `roles_provisioning_source_team_unique (tenant_id, provisioning_source) WHERE provisioning_source IS NOT NULL` is tenant-only. `roles` carries a standing waiver in `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:160` — "Roles define the tenant-wide authorization namespace." The waiver is table-level, and roles genuinely are tenant-global here (Spatie teams key on tenant). No BLOCKER. |
| Convention 11 — one surface per concept | **PASS** | `docs/glossary.md:21` adds the **General manager** row (definition · `roles` + `roles.provisioning_source` / Identity · Settings → Users · synonym "central manager"), and `:91` declares `LotActionPermissionDelta` the **sole** general-manager role-definition writer with Settings → Roles read-only. No second write path. |
| Central/tenant connection correctness | **PASS** | The only `connection('central')` occurrences in the diff are test fixtures/teardown against the tenant directory (`tenants`, `personal_access_tokens`, `pg_database`) — correct pinning. No tenant-scoped write is forced onto `central`; no new "global" query assumes row-level tenant scoping. |

### Item 6 — route middleware (rule 12) and both-layer module gating

| Check | Status | Evidence |
|---|---|---|
| Rule 12 middleware chain | **PASS** | `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12` — `Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:BatchExpiry'])`. `'api'` present (no 401), `SetPermissionsTeam` present (team context set). |
| Backend module gate | **PASS** | `module:BatchExpiry` on the group (`routes.php:12`). |
| Frontend module gate | **PASS** | `apps/web/src/routes/index.tsx` — every batches route keeps `<ModuleGuard module="BatchExpiry">` and the lane tightens the inner guard from `RequirePermission moduleKey="inventory"` to per-action `RequirePermission permission="batches.view"/"batches.create"/"batches.update"`. Both layers gated. |
| Module name in the SoT | **PASS** | `BatchExpiry` is defined in `apps/api/config/verticals.php:47` (`compatible_extras`), `:60` and `:355` (`default_modules`). No gating decision contradicts the SoT. |
| New `can:` guard without a seeded permission (silent-403 trap) | **N/A — none added** | The lane adds **zero** new `can:` route guards. It attaches `BatchActionAccess::class.':<perm>'` to 10 existing routes; all four permissions in that middleware's allow-list (`BatchActionAccess.php:21` — `batches.view`, `batches.traceability`, `batches.delete`, `batches.recall`) are already in `legacyPermissionNames()`. `batches.recall.request` and `treasury.manage_all_locations` are new **catalogue** entries and are granted to roles in `rolePermissionGrants()` (`RolesAndPermissionsSeeder.php:97-111`) — no permission is seeded-but-ungranted. |

---

## Merge-impact analysis against the NEW dev `474e38e2a` (analysis only — nothing merged)

### (a) `apps/api/database/seeders/RolesAndPermissionsSeeder.php` — exact placement instruction

Dev `474e38e2a` (T-2 S1) added `inventory.transfers.reconcile` and `inventory.transfers.close` in two places: the flat permission list at `:201-202`, and the **`manager`** role block at `:604`. `admin` on dev is `self::permissionNames()` (`:584`), so it picks both up automatically.

W-LOT restructures the seeder into three static tiers:

- `legacyPermissionNames()` (lane `RolesAndPermissionsSeeder.php:128`) — the pre-lane catalogue; the four `inventory.transfers.*` keys sit at `:277-280`.
- `permissionNames()` (`:91-94`) — `legacyPermissionNames()` **spread** plus `batches.recall.request` and `treasury.manage_all_locations`.
- `legacyRolePermissionGrants()` (`:658`) — `'admin' => self::legacyPermissionNames()` (`:662`); the `manager` transfers line is `:682`.
- `rolePermissionGrants()` (`:97-111`) — starts from `legacyRolePermissionGrants()` and layers the canonical delta.

**INSTRUCTION — the two T-2 keys MUST land in the LEGACY tier, never in the W-LOT tier:**

1. Add `'inventory.transfers.reconcile',` and `'inventory.transfers.close',` to **`legacyPermissionNames()` immediately after `apps/api/database/seeders/RolesAndPermissionsSeeder.php:280`** (`'inventory.transfers.cancel',`).
2. Append `'inventory.transfers.reconcile', 'inventory.transfers.close',` to the **`manager` transfers line at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:682`**.
3. Make **no** edit to `permissionNames()` (`:91`) or `rolePermissionGrants()` (`:97`).

Why this placement satisfies all three requirements:

- **(i) legacy / NULL-team tenant** — `run()` falls to `createPermissionsFrom(self::legacyPermissionNames())` + `createLegacyRoles()` at `:37-39` (no tenant context) and `:57-58` (unmarked, flag off). Only the legacy tier is consulted on those arms, so the keys must be there. **If they are placed only in `permissionNames()`/`rolePermissionGrants()`, every legacy and every unmarked flag-off tenant ends up without them — and dev already ships live routes `can:inventory.transfers.reconcile` and `can:inventory.transfers.close` at `apps/api/app/Modules/Inventory/Presentation/routes.php:120` and `:124`, plus `require.any.permission:…,inventory.transfers.reconcile` at `:100` and `:108`. That is a silent 403 on the receipt-spine close/reconcile flow for the entire fleet.**
- **(ii) activated tenant** — the delta path calls `apply($tenantId, self::permissionNames(), self::rolePermissionGrants())` (`:44`); `permissionNames()` spreads `legacyPermissionNames()` and `rolePermissionGrants()` starts from `legacyRolePermissionGrants()`, so both keys and the manager grant flow through automatically. `admin` inherits via `:100`; `general_manager` inherits via `:103` (`[...$grants['manager'], …]`).
- **(iii) test truthfulness** — `LotActionSeededRoleMatrixTest::assertCanonicalMatrix()` (`apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php:62-75`) derives its expectations from `rolePermissionGrants()`/`permissionNames()` at runtime, so it stays truthful with no edit. `test_general_manager_has_the_ruled_permission_delta` (`:88`, `array_diff($grants['manager'], $grants['general_manager']) === []`) stays true because GM is built from manager. `RolesAndPermissionsSeederMarkedTenantTest` asserts snapshot **preservation** across reseeds, not literal contents, so it is unaffected. **But see (M-2) — one hardcoded table in the same file does break.**

### (b) `apps/web/src/hooks/permissionsMap.generated.ts` — regenerate, never merge

**Confirmed: the lane does not hand-edit it.** I ran `php artisan permissions:export-frontend-map --path=<tmp>` from the lane worktree and `diff`ed against the committed file — **byte-identical**. The file's header hash (`sha256:02b7a451…`, `permissionsMap.generated.ts:3`) is a self-hash of the rendered body (`ExportFrontendPermissions Map.php:82-90`), so it changes whenever `permissionNames()`/`rolePermissionGrants()` change.

At merge the map **must be regenerated on the merged tree**, not textually merged: it will gain two new rows (`'inventory.transfers.close'` and `'inventory.transfers.reconcile'`, each `['admin','general_manager','manager']`) and a new source hash. A three-way text merge would produce a file whose hash line no longer matches its body and which the generated-artifact drift check rejects.

### (c) RBAC wave-0a route-coverage ratchet — **no ceiling move; the ratchet stays green**

The lane adds **zero** new API routes. `git diff --name-only 4373ba2f6...HEAD` shows exactly one API route file changed, `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`; no Identity or Inventory route file is touched (`UserController`/`RoleController` changed, their routes did not).

Enumeration of the 10 routes the lane **re-middlewares** (all pre-existing, all already in the dev baseline):

| Route | Lane change | Post-merge classification |
|---|---|---|
| `GET api/v1/batches/expiring` | `+BatchActionAccess:batches.view` | **Uncovered** (unchanged) |
| `GET api/v1/batches/expired` | `+BatchActionAccess:batches.view` | **Uncovered** |
| `GET api/v1/batches` | `+BatchActionAccess:batches.view` | **Uncovered** |
| `GET api/v1/batches/{uuid}` | `+BatchActionAccess:batches.view` | **Uncovered** |
| `DELETE api/v1/batches/{uuid}` | `+BatchActionAccess:batches.delete` | **Uncovered** (write) |
| `POST api/v1/batches/{uuid}/recall` | `+BatchActionAccess:batches.recall` | **Uncovered** (write) |
| `GET api/v1/batches/{uuid}/traceability` | `+BatchActionAccess:batches.traceability` | **Uncovered** |
| `GET api/v1/partners/{partnerId}/batch-history` | `+BatchActionAccess:batches.traceability` | **Uncovered** |
| `GET api/v1/batches/{uuid}/stock` | `+BatchActionAccess:batches.view` | **Uncovered** |
| `GET api/v1/products/{productId}/batch-stock` | `+BatchActionAccess:batches.view` | **Uncovered** |
| `GET api/v1/pos/products/{productId}/batches` | `+BatchActionAccess:batches.view` | **Uncovered** |

(`POST api/v1/batches`, `PATCH api/v1/batches/{uuid}`, `POST api/v1/batches/{uuid}/transfer`, `POST api/v1/batches/{uuid}/write-off` are unchanged by the lane and remain baselined.)

Reason: `RouteCoverageClassifier::GATING_ALIASES` (`apps/api/tests/Architecture/Support/RouteCoverageClassifier.php:31-37`) is exactly `can`, `require.any.permission`, `super_admin`, `central_admin`, `central_admin_role` — `BatchActionAccess` is deliberately excluded, and the class docblock at `:18-28` names `lane/w-lot-a-1a` as the worked example, because `BatchActionAccess::handle` returns `$next($request)` with **no** permission check while the flag is off (`apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php:17-19`) and `false` is the shipped default (`apps/api/config/lot_action_permissions.php:5`). I re-read both and confirm the wave-0a description is accurate at this tip.

Consequences: direction **(a) GROWTH** cannot fire (no new route); direction **(b) STALE** cannot fire (no route becomes `Gated`); all 13 batch routes remain present in `apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json` (`:6, :29-34, :95, :173, :198-201`) and the 152-write/146-read ceiling is unmoved. **No baseline edit is needed or permitted at merge.**

### (d) CI allowlist and manifest — internally consistent

- `.github/workflows/ci.yml:1133` names exactly **12** lane classes (enumerated above), with `BatchActionPermissionsTest` and `RoleProvisioningSourceSchemaTest` deliberately absent per the owner ruling.
- Manifest raises in `apps/api/tests/feature-lane-manifest.json`: BatchExpiry **17→21 (+4)**, Console **16→18 (+2)**, Identity **32→39 (+7)**, Migrations **14→15 (+1)**; `gated_ceiling` **1250→1264 (+14)** = 4+2+7+1. ✅ consistent.
- Cross-checked against the classes the lane actually adds: BatchExpiry — `BatchActionPermissionsTest`, `BatchExpiringLocationScopeTest`, `BatchReadLocationScopeTest`, `BatchTraceReaderContractTest` (4). Console — `ApplyLotActionPermissionDeltaCommandTest`, `LotActionReseedMarkerTest` (2). Identity — `GeneralManagerAssignmentTest`, `GeneralManagerAssignmentWriterCensusTest`, `GeneralManagerRoleProtectionTest`, `LotActionPermissionDeltaTest`, `LotActionSeededRoleMatrixTest`, `RoleIndexResponseContractTest`, `RolesAndPermissionsSeederMarkedTenantTest` (7). Migrations — `RoleProvisioningSourceSchemaTest` (1). `BatchTraceabilityModuleBoundaryTest` is an Architecture class and correctly outside the Feature groups. ✅
- `php tools/feature-lane-manifest-check.php` → **exit 0**: "1529 Feature classes in 74 groups; every group has a disposition; every declared lane is present in ci.yml; every `--filter` entry is anchored and uniquely matched against 1941 test classes", with only the two pre-existing warnings (1264 parked, 1 coverage-debt). Round 3 added no new class, so no further raise was owed.
- `.github/workflows/ci.yml` alternation conflict resolution at the merge remains the integration owner's job (not re-litigated here).

---

## Findings

| id | severity | file:line | claim | required fix |
|---|---|---|---|---|
| **M-1(r4)** | Important (merge action) | `apps/api/database/seeders/RolesAndPermissionsSeeder.php:280` and `:682` | T-2 S1's `inventory.transfers.reconcile` / `inventory.transfers.close` must land in the **legacy** tier. Placing them in `permissionNames()` (`:91`) or `rolePermissionGrants()` (`:97`) starves every legacy / NULL-team / unmarked-flag-off tenant, producing a silent 403 on the live dev routes `apps/api/app/Modules/Inventory/Presentation/routes.php:120` (`can:inventory.transfers.reconcile` + `can:inventory.transfers.close`) and `:124`. | In the merge commit, insert the two keys after `legacyPermissionNames()` line `:280`, and append them to the `manager` transfers line `:682`. Touch neither W-LOT static. Existing tenants still need a `RolesAndPermissionsSeeder` re-sync to pick them up. |
| **M-2(r4)** | Important (merge action) | `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php:21` and `:24` | `test_flag_off_generic_seeder_retains_exact_legacy_role_behavior` pins a **hardcoded SHA-256 per role** of the legacy matrix at base `4373ba2f6`. Adding the two T-2 keys to the legacy tier changes `admin` (304→306 names) and `manager` (248→250). The class is in the live `backend-test-pgsql` allowlist (`ci.yml:1133`) and that job runs on PR→dev, so the merged tree is **RED** unless the two values are recomputed. | Update exactly two entries at merge. Computed by reflection against the lane seeder with the M-1 placement applied (my reproduction of the five unchanged roles and of both BEFORE values matches the committed table byte-for-byte, so the method is validated): `'admin' => '3c5519cd603401e08706ed5ba6c231d47c0b7a37c9f17c93fd581336b6b09ca4'`, `'manager' => '0f979bd6b43042223354b7b8bf94942e888f08d1806e33b973ed7114a03ac231'`. Leave `accountant`/`cashier`/`operator`/`technician`/`viewer` untouched. Re-run the class on PG to confirm before pushing. |
| **M-3(r4)** | Important (merge action) | `apps/web/src/hooks/permissionsMap.generated.ts:3` | The file is generated and carries a self-hash of its body. After the seeder merge it must gain `'inventory.transfers.close'` and `'inventory.transfers.reconcile'` rows and a new hash. A text/three-way merge yields a file whose header hash does not match its body and which the generated-artifact drift check rejects. | On the merged tree run `php artisan permissions:export-frontend-map` and commit the regenerated file. Do **not** merge it textually. (Verified: the lane contains no hand-edit — regeneration in the lane worktree is byte-identical to the committed file.) |
| **M-4(r4)** | Important (promotion protocol) | `docs/handoff/HANDBACK-WLOTA-1a-2026-09-09.md:196`; plan rev 12 `:37`; `apps/web/src/routes/index.tsx` (batches routes) | Merging the lane into `dev` places all 76 files on the auto-deploying branch at once, collapsing the five-push ledger. Push 5 replaces `RequirePermission moduleKey="inventory"` with `RequirePermission permission="batches.view"` on `/batches`; legacy grants give `batches.view` to `admin`/`cashier`/`manager` only, so `viewer` and `operator` lose the Batches page unless the **Push-4 delta has already run**. The plan rules Push 4 strictly before Push 5. | Do not let the merge trigger a staging deploy of the whole branch. Assemble staging promotions from the per-file ledger (`HANDBACK…:199-281`) per plan §10/§11, and verify the Push-4 delta before any Push-5 web promotion. Orchestrator/integration owner's call — recorded here so it is not lost at the merge. |
| **m-1(r4)** | Minor | `docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-12.md:2359` | The plan's §10 Push-3 bullet still names the retired `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx`, a file that does not exist at `1e19a0f37`. The rev-12 header records the M-1(r3) split ruling and the handback ledger + release note are both correct, so this is a stale doc line only. | Orchestrator: update the §10 bullet to `__tests__/BatchListPermissions.test.tsx`. No lane change. |
| **m-2(r4)** | Minor | `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php:19-27` | The hash is taken over `permissions()->orderBy('name')->pluck('name')` — a **database**-ordered list, so the assertion is coupled to the server collation. It reproduces exactly under `C` collation (my private DB, and the five unchanged role hashes match the committed table). I **cannot verify** the collation of the CI `timescale/timescaledb:latest-pg16` service from the repository, so I cannot rule out a non-C ordering there. Pre-existing at r3, not a round-3 regression. | Optional hardening: `sort($names, SORT_STRING)` in PHP before hashing so the pin is collation-independent. Not merge-blocking. |

---

## Empirical evidence

**Database:** private `autoerp_test_wl` on native PostgreSQL `127.0.0.1:5432` (PostgreSQL 15.15 Homebrew, `C`/`C` collation), used for **both** `DB_DATABASE` and `DB_CENTRAL_DATABASE`. Credentials read from the main checkout's `apps/api/.env` (`autoerp`/`autoerp_secret`). Database created for this review. The lane's `autoerp_test_w` (port 5433) was not used — that container is down. A gitignored `apps/api/.env` was created in the lane worktree from the main checkout with the three DB overrides; it is untracked and matched by `.gitignore:3-4`.

**Protocol:** every class run **by path, one class per `phpunit` invocation, serially**, `-c phpunit-pgsql.xml`, never the full suite, never `--parallel`, one PHPUnit process at a time. `WLOTA1A_RUN_KNOWN_REDS` unset.

| Class | Result | Handback claim | Match |
|---|---|---|---|
| `tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php` | `OK (3 tests, 7 assertions)` — `Time: 00:13.331`, EXIT=0 | 3 tests / 7 assertions, exit 0 | ✅ |
| `tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | `OK, but some tests were skipped! Tests: 16, Assertions: 109, Skipped: 1.` — `Time: 00:43.706`, EXIT=0 | 16 / 109 / 1 skipped, exit 0 | ✅ |
| `tests/Feature/Identity/GeneralManagerAssignmentTest.php` | `OK (8 tests, 144 assertions)` — `Time: 00:25.096`, EXIT=0 | 8 tests, exit 0 (assertion count declared unstable) | ✅ |
| `tests/Feature/Identity/LotActionPermissionDeltaTest.php` | `OK (12 tests, 148 assertions)` — `Time: 00:25.709`, EXIT=0 | 12 tests, exit 0 (assertion count declared unstable) | ✅ |
| `tests/Feature/Identity/RolesAndPermissionsSeederMarkedTenantTest.php` | `OK (2 tests, 4 assertions)` — `Time: 00:12.170`, EXIT=0. Live markers observed: `WLOTA1A-RESEED tenant=01a0965d-32d9-… mode=LEGACY outcome=ALREADY_APPLIED reason=marked_tenant_delta_preserved`; `… mode=ACTIVATED outcome=APPLIED reason=canonical_delta_applied` | 2 tests, exit 0 | ✅ |
| `tests/Feature/Identity/LotActionSeededRoleMatrixTest.php` | `OK (5 tests, 38 assertions)` — `Time: 00:29.808`, EXIT=0. Markers: `mode=ACTIVATED outcome=APPLIED reason=canonical_delta_applied`, then `outcome=ALREADY_APPLIED reason=canonical_state_matches` (idempotency observed live) | (allowlist class) exit 0 | ✅ |

**No red where the handback claims green.** Assertion counts for `GeneralManagerAssignmentTest` (144) and `LotActionPermissionDeltaTest` (148) again differ from the round-2 handback numbers; this instability was declared at r2/r3 and test counts match exactly — observation, not a finding.

**Vitest** (from the lane's `apps/web`, one file per invocation):

| File | Result |
|---|---|
| `src/features/batches/pages/__tests__/BatchListPermissions.test.tsx` | `✓ (4 tests) 103ms` — `Test Files 1 passed (1) / Tests 4 passed (4)` |
| `src/features/batches/pages/__tests__/BatchDetailPermissions.test.tsx` | `✓ (5 tests) 110ms` — `Test Files 1 passed (1) / Tests 5 passed (5)` |

`ps aux | grep 'node (vitest' | grep -v grep` after both runs → **empty (0 processes)**. No stragglers to kill.

**Other gates run in this review:**
- `php artisan permissions:export-frontend-map --path=<tmp>` → `diff` against the committed `permissionsMap.generated.ts` is **clean** (no hand-edit).
- `php tools/feature-lane-manifest-check.php` → **exit 0**, only the two pre-existing warnings.

**Precision / PG-trap scan on the lane diff:** no `latestOfMany(`/`ofMany(` added; no `app()` helper added; no `(float)` cast added in `apps/api/app`; no `parseFloat`/`Number(` added in `apps/web/src`. The disclosed pre-existing float/parseFloat residuals remain ticketed (`docs/superpowers/tickets/2026-09-10-batch-detail-parsefloat-quantities.md`, `…-batch-stock-dead-float-accessors.md`).

---

## What to fix before merge

Perform M-1 (two T-2 keys into `legacyPermissionNames():280` and the `manager` line `:682` — never into the W-LOT statics), M-2 (recompute the `admin`/`manager` hashes in `LotActionSeededRoleMatrixTest.php:21,:24`), and M-3 (regenerate `permissionsMap.generated.ts` on the merged tree) **inside the merge commit**, and honour M-4 by promoting to staging from the per-file ledger rather than from the merged branch.
