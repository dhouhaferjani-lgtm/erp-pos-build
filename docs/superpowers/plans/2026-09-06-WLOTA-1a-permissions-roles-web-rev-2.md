<!-- W-LOT-A-1a rev 2, Codex CLI fix round 1 (gpt-5.6-sol, read-only) 2026-09-06, saved verbatim by the orchestrator. Rev 1 = 0bc84c48f. Status: awaiting gate r2. -->
<!-- W-LOT-A-1a rev 2; Tasks 1/2/6 only. Prepared read-only at 3b326a71f700c885d471461a7e6069fb59d0ac43 on 2026-09-06. The orchestrator saves this response verbatim. -->
# Slice plan W-LOT-A-1a — lot action permissions, general-manager role delta, web gating (rev 2)

## 0. Round-1 change log

The governing review is [gate r1](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r1.md:1). Every finding is closed by a named plan line below.

| Finding | Disposition and closing plan line |
|---|---|
| B1 — constructors | CLOSED — all changed constructors now reproduce correct FQCNs and promoted `private readonly` properties; NEW command constructors call `parent::__construct()`. See [`R2-B1`](#r2-b1) and the named-symbol census. |
| B2 — Push 3 seeder not inert | CLOSED — the generic seeder uses the legacy catalogue and role matrix while enforcement is off; only the explicit Push-4 delta changes existing tenants. Activated fresh provisioning uses the new matrix. Rollout-window tenants are re-censused and re-delta’d. See [`R2-B2`](#r2-b2). |
| B3 — premature browser gate | CLOSED — browser evidence is split into pre-activation web-only gating/fingerprint checks and post-activation duplicate/location checks. See [`R2-B3`](#r2-b3). |
| B4 — impossible SHA equality | CLOSED — this plan declares the SHA read, but dispatch records and pins its actual HEAD; drift triggers a symbol/citation recensus rather than an impossible equality with a pre-plan commit. See [`R2-B4`](#r2-b4). |
| B5 — fail-open fleet/census gates | CLOSED — every fleet phase captures a non-empty expected tenant set, validates exact-once success markers, rejects missing/duplicate/unexpected/`FAILED`/`SKIPPED` markers, and gates every census summary. The manifest checklist is copied verbatim and activation is worker → API → scheduler. See [`R2-B5`](#r2-b5). |
| M1 — trace DTO contract loss | CLOSED — three discriminated DTOs preserve every current forward-document, forward-POS, and backward-document field. See [`R2-M1`](#r2-m1). |
| M2 — delta transaction/team scope | CLOSED — the role delta captures, sets, and restores the Spatie team ID and places lock/read/write/verification inside one `DB::transaction`. See [`R2-M2`](#r2-m2). |
| M3 — assignment transaction | CLOSED — create, update, and dedicated assignment use one transaction covering ordered locks, merged-state validation, role mutation, and audit/event emission; team ID is restored in `finally`. See [`R2-M3`](#r2-m3). |
| M4 — denied mutation snapshots | CLOSED — exact cashier/viewer/manager denial tests compare batch, stock, reservation, trace-history, journal-entry, and journal-line snapshots before and after 403. See [`R2-M4`](#r2-m4). |
| M5 — two create affordances | CLOSED — one `canCreate` value gates both the header and empty-state links, with populated and empty-state tests. See [`R2-M5`](#r2-m5). |
| M6 — incomplete Task-6 symbols | CLOSED — every Task-6 Vitest and Playwright `describe`/`it` title, first failing assertion, command, and lane is enumerated. See [`R2-M6`](#r2-m6). |
| M7 — incomplete CLI | CLOSED — exactly one of `--apply`/`--verify` is mandatory; neither and both exit 2. Apply, verify, collision, schema failure, and invalid-option command tests are named. See [`R2-M7`](#r2-m7). |
| M8 — inventory reviewer omitted | CLOSED — `inventory-costing-reviewer` is mandatory for Tasks 1, 2, 6 and the combined gate. See [`R2-M8`](#r2-m8). |
| M9 — convention-10 matrix | CLOSED — the exact convention-10 header is used, rerun is `MATCH — Task 2`, and unsupported competitor claims are `NV` rather than `DIVERGE`. See [`R2-M9`](#r2-m9). |
| M10 — wrong typecheck command | CLOSED — verification uses the real `typecheck` and `typecheck:e2e` scripts. See [`R2-M10`](#r2-m10). |
| N1 — inoperative citations | CLOSED — manager recall points to the grant at line 632, `RequirePermission` to line 57, company creation to line 76, and viewer omission to the complete 705–742 grant range. See [`R2-N1`](#r2-n1). |
| N2 — unnamed selected location | CLOSED — the second-location test persists `allowed_location_ids=[B2]` and proves B2 is the selected restricted location. See [`R2-N2`](#r2-n2). |

## 1. Plan identity and dispatch pin

- Repository: `/Users/houssamr/Projects/syneriva/apps/erp`.
- Planning HEAD read in full: `3b326a71f700c885d471461a7e6069fb59d0ac43`.
- Branch state: local `dev`.
- No repository files were changed and no tests were run while preparing this plan.
- All `path:line` citations describe source at `3b326a71f700c885d471461a7e6069fb59d0ac43`.
- Preserve these unrelated untracked files:

  - `apps/api/docs/sessions/2026-08-04-cross-tenant-scheduled-jobs-plan.md`
  - `docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md`

<a id="r2-b4"></a>
**R2-B4:** implementation does not compare HEAD to a commit that necessarily predates this saved plan. At dispatch, the implementer must run:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
DISPATCH_SHA="$(git rev-parse HEAD)"
test -n "$DISPATCH_SHA"
git status --short
git show -s --format='%H %cI %s' "$DISPATCH_SHA"
```

Record `DISPATCH_SHA` in the dispatch evidence. If it equals the planning HEAD, the citations are already pinned. If it differs:

1. Run the named-symbol census in §6 against `DISPATCH_SHA`.
2. Re-open every cited production seam.
3. Record corrected `path:line` citations in a dispatch addendum pinned to `DISPATCH_SHA`.
4. Confirm the complete source-push ledger still matches the production and test inventories.
5. Begin implementation only after that repin is reviewed.

Documentation-only ancestry does not block dispatch. Any production-tree change affecting a named seam requires the repin procedure; there is no unconditional equality gate against the planning SHA.

## 2. Outcome and hard scope

This slice contains only Tasks 1, 2, and 6 extracted from W-LOT-A-1 rev 5:

1. API action permissions and location-safe batch reads.
2. Seeded role delta and guarded `general_manager` assignment.
3. Web route/action gating and deterministic permission-map generation.

It guarantees after activation:

- Every existing BatchExpiry HTTP action has its intended permission boundary.
- Batch list, detail, stock, expiry, product-stock, POS suggestion, and trace reads honor company and membership-location scope.
- `null` membership scope remains unrestricted; `[]` remains fail-closed.
- Unrestricted users retain company lot metadata, including zero-stock lots and null-location history.
- Restricted users see only current or historical lots attributable to allowed locations.
- `BatchExpiry` imports no Document or POS persistence models for trace queries.
- Batch totals are four-decimal strings computed only from the scoped eager-loaded relation.
- `manager` loses `batches.recall` and gains dormant `batches.recall.request`.
- A marked seeded `general_manager` receives the revised manager set plus `batches.recall` and `treasury.manage_all_locations`.
- `general_manager` may be assigned only when every active company membership is unrestricted.
- The marked role cannot be renamed or deleted.
- The web uses the same exact permission names as the API.
- Push 3 is behaviorally inert while `LOT_ACTION_PERMISSIONS_ENFORCE=false`.

Explicitly deferred to W-LOT-A-1b:

- Recall-request and hold tables.
- Requested/recalled/released/rejected state.
- Local branch holds.
- Recall request/history/capability routes.
- Recall escalation, replay, operation UUIDs, and append-only recall evidence.
- Sale, transfer, delivery, write-off, stock-count, and return eligibility changes.
- Company-wide recall-service replacement.
- Request, history, hold, release, or reject UI.
- Device/Tauri work.

This slice must not:

- Change `Batch::recall()` behavior.
- Fix the existing web recall payload key.
- Change quantity, reservation, valuation, stock movement, or GL posting behavior.
- Add a handwritten web trace DTO.
- Add a recall-request button.
- Add release or reject permissions.

The existing web payload remains `{ recall_reason: ... }` at [BatchDetailPage.tsx:49](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:49); W-LOT-A-1b owns that defect.

## 3. Owner rulings

### 3.1 RD2 — verbatim

> A manager is branch-linked, or is a **general manager** (explicit company-wide role). Recall is a safety action: a branch manager **initiates** a recall for a lot present in their branch; the recall **escalates** to the general manager, who executes it company-wide. Permissions stay tight; escalation, not denial.

Source: [OWNER-RULINGS:9](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:9).

A-1a implements only the permission and role split. Request and escalation behavior remains in A-1b.

### 3.2 Q4 — verbatim

> CONFIRMED: new seeded `general_manager` role (manager set, no location restriction, `batches.recall`, `treasury.manage_all_locations`).

Source: [OWNER-RULINGS:113](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:113).

### 3.3 Q10 — verbatim

> Hold lifecycle `requested → recalled` or `requested → released`; only the general manager may release or reject; mandatory reason and append-only evidence; the requesting branch never lifts its own hold.

Source: [OWNER-RULINGS:151](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151).

A-1a creates no hold lifecycle or Q10 state schema. A-1b must implement and review the complete forward-compatible requested/recalled/released/rejected model.

## 4. Industry baseline (benchmark-first — convention 10)

<a id="r2-m9"></a>
**R2-M9:** the following table uses the exact convention-10 header and decision vocabulary from [10-BENCHMARK-FIRST-SPECS.md:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37).

Flow: permissioned lot reads, restricted-location visibility, central recall authority, and deterministic role provisioning. Reference systems: Odoo 18 and current ERPNext manuals. A competitor cell is `NV` when the cited material does not directly establish the guarantee.

Sources:

- [Odoo 18 lots documentation](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/product_management/product_tracking/lots.html)
- [Odoo 18 access-rights documentation](https://www.odoo.com/documentation/18.0/applications/general/users/access_rights.html)
- [ERPNext Batch documentation](https://docs.frappe.io/erpnext/user/manual/en/batch)
- [ERPNext User Permissions documentation](https://docs.frappe.io/erpnext/user/manual/en/user-permissions)

| # | Guarantee the baseline gives the user | Odoo | ERPNext | Dolibarr | AutoERP today (path:line) | Gap | Decision |
|---|---|---|---|---|---|---|---|
| create | Creating a lot requires explicit action authority | Model operations are controlled through access rights | Document operations are role/permission controlled | NV — exact batch-create contract not verified | POST has no route permission at [routes.php:23](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:23) | Missing explicit route action guard | MATCH — Task 1 |
| duplicate | A repeated create has a meaningful, non-mutating outcome | NV — lot documentation does not establish retry response semantics | NV — Batch documentation does not establish retry response semantics | NV — not verified | Duplicate returns a generic 422 without an outcome at [BatchController.php:138](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:138) | No `already_exists` result | MATCH — Task 1 |
| edit | Lot metadata updates require explicit update authority | Model write access is group-controlled | Document updates are permission-controlled | NV — exact batch-edit contract not verified | PATCH has no route permission at [routes.php:25](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:25) | Route matrix incomplete | MATCH — Task 1 |
| cancel | Lot deactivation requires explicit delete/deactivate authority | Model write/unlink access is group-controlled | Delete/cancel operations are permission-controlled | NV — exact batch-deactivation contract not verified | DELETE has no route permission at [routes.php:26](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:26) | Body-less route bypasses FormRequest authorization | MATCH — Task 1 |
| rerun | Role provisioning is deterministic and does not erase unrelated grants | NV — cited access documentation does not prove module-update idempotency | NV — cited manuals do not prove reseed idempotency | NV — not verified | Seeded roles are blindly synchronized at [RolesAndPermissionsSeeder.php:547](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:547) | Rerun can erase tenant-authored grants | MATCH — Task 2 |
| second company | Company B cannot observe company A’s lot | Access rules can be company-scoped, but exact lot guarantee is not established here: NV | User permissions support constrained document access; exact cross-company lot guarantee is not established here: NV | NV — not verified | Detail explicitly returns 404 on company mismatch at [BatchController.php:68](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:68), but trace adapters do not yet carry the predicate | Trace and read paths are inconsistent | MATCH — Task 1 |
| second location | A branch user sees only attributable branch stock/history | NV — cited pages do not directly establish this precise trace rule | User Permissions can restrict linked records such as Warehouse | NV — not verified | Detail loads all stock rows at [BatchController.php:112](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:112) | Scope not threaded through every read | MATCH — Task 1 |
| permission | Company-wide recall is held by a distinct unrestricted authority | NV — cited lot/access pages do not define a central recall role | NV — cited Batch/User Permission pages do not define a central recall role | NV — not verified | Manager currently receives global recall at [RolesAndPermissionsSeeder.php:632](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:632) | No distinct unrestricted general-manager role | MATCH — Task 2 |
| audit | Role assignment records actor, target, company, role, and time | NV — cited page establishes access control, not this audit payload | NV — not established by cited manuals | NV — not verified | Dedicated assignment emits `RoleAssigned` with the required values at [RoleController.php:365](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:365) | Existing assignment audit already covers the guarantee | ALREADY — preserve in Task 2 |

Second-of-everything: Task 1 creates company B through the real endpoint, selects a second POS-enabled location B2, and repeats batch creation with `already_exists`; Task 2 repeats the permission delta and tests an explicitly B2-restricted second-company membership; Task 6 repeats those guarantees in the post-activation browser lane.

## 5. Vocabulary and current-state evidence

Concepts: Lot (batch) (glossary ✅), Location (glossary ✅), Membership (glossary ✅), General manager (NEW — glossary row added in this lane).

Add this exact row to the existing five-column glossary table:

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| **General manager** | A seeded tenant role containing the revised manager grants plus company-wide lot recall and all-location Treasury authority; every active company membership held by the assignee must be unrestricted. | Existing Spatie `roles`, marked by `roles.provisioning_source` / Identity | Existing Settings → Users and Settings → Roles surfaces | `general_manager`, central manager |

Do not add Recall request, Branch hold, Recall transition, or Global recall evidence rows in A-1a.

Current operative evidence:

- The BatchExpiry route group already retains API authentication, Spatie team setup, tenant-claim enforcement, and module gating at [routes.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12).
- The missing read/write/trace guards are visible at [routes.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:14), [routes.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:22), [routes.php:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:29), and [routes.php:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:38).
- Grouped and reversed write-off routes already use `batches.write-off` at [routes.php:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:18) and [routes.php:34](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:34).
- Expiring reads an unvalidated location string at [BatchController.php:219](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:219); expired already validates UUIDs and resolves scope at [BatchController.php:241](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:241) and [BatchController.php:252](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:252).
- Stock is currently read across all locations at [BatchController.php:271](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:271).
- Batch totals currently use relation accessors that issue unscoped aggregate queries and cast to float at [Batch.php:143](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:143) and are serialized at [BatchResource.php:39](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:39).
- `LocationContext` returns `[]` for no active membership and `null` for unrestricted membership at [LocationContext.php:194](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationContext.php:194).
- `LocationScopeResolver` rejects requested IDs outside scope at [LocationScopeResolver.php:41](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationScopeResolver.php:41).
- Permission teams are enabled and tenant-keyed at [permission.php:127](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/permission.php:127).
- HTTP requests set the team ID from the authenticated user’s tenant at [SetPermissionsTeam.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:22) and [SetPermissionsTeam.php:28](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:28).
- New registration invokes full tenant initialization at [TenantProvisioningService.php:222](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:222), which runs `RolesAndPermissionsSeeder` when roles are absent at [TenantInitializationService.php:199](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:199) and [TenantInitializationService.php:213](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:213).
- Role names are tenant-team scoped by the existing unique key at [2025_11_29_231806_create_permission_tables.php:44](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:44).
- User creation assigns a role before membership creation at [UserController.php:232](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:232); update synchronizes roles before location scope at [UserController.php:380](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:380).
- Dedicated assignment writes directly at [RoleController.php:350](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:350).
- Both batch-create links are currently unconditional at [BatchListPage.tsx:86](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchListPage.tsx:86) and [BatchListPage.tsx:132](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchListPage.tsx:132).
- Web batch routes use the broad inventory key at [routes/index.tsx:1185](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/routes/index.tsx:1185), navigation is module-only at [Sidebar.tsx:222](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/Sidebar/Sidebar.tsx:222), and detail action booleans ignore authorization at [BatchDetailPage.tsx:75](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:75).

<a id="r2-n1"></a>
**R2-N1:** operative gate-r5 N2 evidence is pinned to the statements that perform or fully prove each behavior:

- Manager’s current recall grant: [RolesAndPermissionsSeeder.php:632](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:632).
- Exact permission check: [RequirePermission.tsx:57](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/auth/components/RequirePermission.tsx:57).
- Real second-company payload begins at [CreateCompanyTest.php:76](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Company/CreateCompanyTest.php:76).
- Viewer’s complete current grants, proving omission of `batches.view`: [RolesAndPermissionsSeeder.php:705](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:705) through line 742.

## 6. Shared contracts and named-symbol census

### 6.1 Activation contract

- Config key: `lot_action_permissions.enforce`.
- Environment key: `LOT_ACTION_PERMISSIONS_ENFORCE`.
- Default: `false`.
- Application code reads Laravel configuration only; it does not call `env()` outside the config file.
- While false:

  - New route middleware passes through immediately.
  - Newly location-scoped endpoints retain pre-activation read behavior.
  - Duplicate creation retains the existing payload.
  - Generic seeding uses the exact legacy permission catalogue and legacy role synchronization behavior.
  - Generic seeding does not create `general_manager`, remove manager recall, or add viewer/operator grants.

- Push 4 changes existing tenants only through `permissions:apply-lot-action-delta --apply`.
- No fleet-wide generic reseed runs while the flag is false.
- After API activation, fresh registration uses the canonical new matrix.
- Registration is paused during final pre-activation census/activation, then resumed only after post-activation recensus.

<a id="r2-b2"></a>
**R2-B2:** Push 3 is inert even for newly registered tenants because `RolesAndPermissionsSeeder::run()` selects its legacy helpers while the flag is false. Existing-tenant role changes are owned only by the Push-4 delta. Tenants created between Push 3 and the activation maintenance window are included by the final pre-activation recensus and delta; a post-activation recensus proves no tenant escaped.

### 6.2 Location and visibility contract

- `null` means every location in an active company membership.
- `[]` means no visible location.
- A non-empty list means exactly those UUIDs.
- `[]` is never converted into an omitted predicate.
- User-supplied location IDs are UUID-validated.
- Requested IDs pass through `LocationScopeResolver` with no Treasury bypass permission.
- Company mismatch remains indistinguishable from absence.
- Unrestricted users see zero-stock company lots, depleted history, and null-location history.
- Restricted users see lots with current allowed stock or attributable allowed-location history.
- Restricted users receive only allowed stock rows; null-location history is excluded.
- Empty scope returns no list, stock, expiry, product-stock, POS, or trace results and detail returns 404.

### 6.3 Decimal aggregate contract

- `BatchResource` sums only the loaded `batchStock` collection.
- It performs no fresh relationship query.
- `total_quantity` and `available_quantity` are decimal strings at scale four.
- No float conversion is permitted.
- `total_quantity` remains physical on-hand.
- `available_quantity` remains on-hand minus reserved.
- Neither becomes hold-aware.

### 6.4 Permission route matrix

| Existing surface | Permission after activation |
|---|---|
| GET `/api/v1/batches` | `batches.view` |
| GET `/api/v1/batches/expiring` | `batches.view` |
| GET `/api/v1/batches/expired` | `batches.view` |
| GET `/api/v1/batches/{uuid}` | `batches.view` |
| GET `/api/v1/batches/{uuid}/stock` | `batches.view` |
| GET `/api/v1/products/{productId}/batch-stock` | `batches.view` |
| GET `/api/v1/pos/products/{productId}/batches` | `batches.view` |
| GET `/api/v1/batches/{uuid}/traceability` | `batches.traceability` |
| GET `/api/v1/partners/{partnerId}/batch-history` | `batches.traceability` |
| POST `/api/v1/batches` | existing request authorization plus `batches.create` |
| PATCH `/api/v1/batches/{uuid}` | existing request authorization plus `batches.update` |
| DELETE `/api/v1/batches/{uuid}` | `batches.delete` |
| POST `/api/v1/batches/{uuid}/recall` | `batches.recall` |
| POST `/api/v1/batches/{uuid}/transfer` | existing request authorization |
| POST `/api/v1/batches/{uuid}/write-off` | existing request authorization plus `batches.write-off` |
| POST `/api/v1/batches/write-off-grouped` | existing `batches.write-off` |
| POST `/api/v1/stock-movements/{movementId}/reverse-write-off` | existing `batches.write-off` |

No `batches.recall.request` route is added.

### 6.5 Named-symbol census at planning HEAD

Existing symbols verified at HEAD:

| Existing FQCN/symbol | Operative evidence |
|---|---|
| `App\Modules\BatchExpiry\Presentation\Controllers\BatchController::__construct()` | [BatchController.php:33](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:33) |
| `App\Modules\BatchExpiry\Presentation\Controllers\BatchTraceabilityController::__construct()` | [BatchTraceabilityController.php:28](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:28) |
| `App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface` | [BatchRepositoryInterface.php:10](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php:10) |
| `App\Modules\BatchExpiry\Infrastructure\Persistence\BatchRepository` | [BatchRepository.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:12) |
| `App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService` | [FEFOInventoryService.php:45](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:45) |
| `App\Modules\BatchExpiry\Presentation\Resources\BatchResource::toArray()` | [BatchResource.php:20](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:20) |
| `App\Modules\Document\Providers\DocumentServiceProvider::register()` | [DocumentServiceProvider.php:30](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php:30) |
| `App\Modules\POS\Providers\POSServiceProvider::register()` | [POSServiceProvider.php:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Providers/POSServiceProvider.php:38) |
| `Database\Seeders\RolesAndPermissionsSeeder::__construct()` | [RolesAndPermissionsSeeder.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:14) |
| `App\Modules\Identity\Presentation\Controllers\UserController::__construct()` | [UserController.php:55](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:55) |
| `App\Modules\Identity\Presentation\Controllers\RoleController::__construct()` | [RoleController.php:58](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:58) |
| `App\Modules\BatchExpiry\BatchExpiryServiceProvider::boot()` | [BatchExpiryServiceProvider.php:23](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php:23) |
| `App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam::handle()` | [SetPermissionsTeam.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:22) |
| `App\Services\CompanyConfigService` | Imported by the existing role controller at [RoleController.php:16](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:16) |
| `App\Modules\Tenant\Application\Services\IdentityIndexService` | Imported by the existing user controller at [UserController.php:23](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:23) |
| `App\Modules\Tenant\Application\Services\TenantLinkSigner` | Imported by the existing user controller at [UserController.php:24](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:24) |
| `App\Modules\BatchExpiry\Domain\Services\BatchWriteOffService` | Imported at [BatchController.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:12) |
| `App\Modules\BatchExpiry\Domain\Services\ReverseWriteOffService` | Imported at [BatchController.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:14) |
| `AppRoutes` | Existing route component in `apps/web/src/routes/index.tsx` |
| `buildNavigation(boolean): NavModule[]` | Existing Sidebar navigation builder |
| `BatchListPage()` | Existing batch list component |
| `BatchDetailPage()` | Existing batch detail component |
| `usePermissions()` and `MODULE_PERMISSIONS` | [usePermissions.ts:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/usePermissions.ts:29) and [usePermissions.ts:120](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/usePermissions.ts:120) |

NEW symbols are declared with complete FQCNs and signatures in Tasks 1 and 2. NEW test classes and exact methods are declared in each task’s test inventory. Laravel auto-resolution is the binding unless an explicit provider binding is specified.

<a id="r2-b1"></a>
**R2-B1:** every constructor added or changed by this slice appears below with correct namespaces, promoted `private readonly` properties, and—where the parent is `Illuminate\Console\Command`—an explicit `parent::__construct()` call. Adapters with no dependencies and `GeneralManagerAssignmentGuard` deliberately declare no constructor.

## 7. Task 1 — API action permissions and location-safe reads

### 7.1 Files

Add:

- `apps/api/config/lot_action_permissions.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/LotActionPermissionActivation.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php`
- `apps/api/app/Shared/Contracts/BatchTraceability/ForwardDocumentBatchTraceData.php`
- `apps/api/app/Shared/Contracts/BatchTraceability/ForwardPosBatchTraceData.php`
- `apps/api/app/Shared/Contracts/BatchTraceability/BackwardDocumentBatchTraceData.php`
- `apps/api/app/Shared/Contracts/BatchTraceability/DocumentBatchTraceReader.php`
- `apps/api/app/Shared/Contracts/BatchTraceability/PosBatchTraceReader.php`
- `apps/api/app/Modules/Document/Infrastructure/BatchTraceability/DocumentBatchTraceReaderAdapter.php`
- `apps/api/app/Modules/POS/Infrastructure/BatchTraceability/PosBatchTraceReaderAdapter.php`

Modify:

- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php`
- `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`
- `apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php`
- `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`

Add tests:

- `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php`
- `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php`
- `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php`
- `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php`
- `apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php`

### 7.2 Config and activation

`apps/api/config/lot_action_permissions.php` has no namespace:

```php
<?php

declare(strict_types=1);

return [
    'enforce' => (bool) env('LOT_ACTION_PERMISSIONS_ENFORCE', false),
];
```

NEW `App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation`:

```php
final readonly class LotActionPermissionActivation
{
    public function __construct(
        private readonly \Illuminate\Contracts\Config\Repository $config,
    ) {}

    public function enforced(): bool;
}
```

Binding: Laravel constructor auto-resolution.

NEW `App\Modules\BatchExpiry\Presentation\Middleware\BatchActionAccess`:

```php
final readonly class BatchActionAccess
{
    public function __construct(
        private readonly \App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation $activation,
    ) {}

    public function handle(
        \Illuminate\Http\Request $request,
        \Closure $next,
        string $permission,
    ): \Symfony\Component\HttpFoundation\Response;
}
```

Binding: direct middleware class reference in `routes.php`.

The only accepted middleware arguments are:

```text
batches.view
batches.traceability
batches.create
batches.update
batches.delete
batches.recall
batches.write-off
```

Unknown arguments return 403. Flag-off returns `$next($request)` before permission evaluation.

### 7.3 Trace response compatibility

<a id="r2-m1"></a>
**R2-M1:** current response serialization is owned by `BatchTraceabilityController`: forward-document fields at [BatchTraceabilityController.php:65](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:65), forward-POS fields at [BatchTraceabilityController.php:80](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:80), the forward envelope at [BatchTraceabilityController.php:90](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:90), backward fields at [BatchTraceabilityController.php:141](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:141), and the backward envelope at [BatchTraceabilityController.php:155](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:155).

NEW `App\Shared\Contracts\BatchTraceability\ForwardDocumentBatchTraceData`:

```php
final readonly class ForwardDocumentBatchTraceData
{
    public function __construct(
        public readonly string $type,
        public readonly string $documentNumber,
        public readonly string $documentType,
        public readonly string $documentDate,
        public readonly string $partnerName,
        public readonly ?string $partnerId,
        public readonly string $productName,
        public readonly string $quantity,
    ) {}
}
```

`type` must equal `document`.

NEW `App\Shared\Contracts\BatchTraceability\ForwardPosBatchTraceData`:

```php
final readonly class ForwardPosBatchTraceData
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $receiptNumber,
        public readonly ?string $saleDate,
        public readonly ?string $customerName,
        public readonly ?string $customerIdentifier,
        public readonly ?string $batchNumber,
        public readonly string $quantity,
    ) {}
}
```

`type` must equal `pos_receipt`.

NEW `App\Shared\Contracts\BatchTraceability\BackwardDocumentBatchTraceData`:

```php
final readonly class BackwardDocumentBatchTraceData
{
    public function __construct(
        public readonly ?string $batchNumber,
        public readonly int $batchId,
        public readonly ?string $expiryDate,
        public readonly bool $isRecalled,
        public readonly bool $isExpired,
        public readonly string $productName,
        public readonly string $productId,
        public readonly string $quantity,
        public readonly string $documentNumber,
        public readonly string $documentType,
        public readonly string $documentDate,
    ) {}
}
```

The controller maps those DTO properties back to the existing snake-case JSON keys exactly. It preserves:

- `document_type` and `customer_identifier`.
- Forward `document_sales`, `pos_sales`, and `total_sales_count`.
- Forward batch `id`, `uuid`, `batch_number`, `product_name`, `expiry_date`, and `is_recalled`.
- Backward `expiry_date`, `is_recalled`, and `is_expired`.
- Existing nullability and string/boolean/integer JSON types.
- Existing top-level `data` envelopes.

NEW `App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader`:

```php
interface DocumentBatchTraceReader
{
    /** @return list<ForwardDocumentBatchTraceData> */
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
    ): array;

    /** @return list<BackwardDocumentBatchTraceData> */
    public function backwardForPartner(
        string $tenantId,
        string $companyId,
        string $partnerId,
        ?array $locationIds,
        ?string $productId,
        ?string $dateFrom,
        ?string $dateTo,
    ): array;

    /** @return list<int> */
    public function batchIdsVisibleAtLocations(
        string $tenantId,
        string $companyId,
        array $locationIds,
    ): array;
}
```

NEW `App\Shared\Contracts\BatchTraceability\PosBatchTraceReader`:

```php
interface PosBatchTraceReader
{
    /** @return list<ForwardPosBatchTraceData> */
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
    ): array;

    /** @return list<int> */
    public function batchIdsVisibleAtLocations(
        string $tenantId,
        string $companyId,
        array $locationIds,
    ): array;
}
```

NEW `App\Modules\Document\Infrastructure\BatchTraceability\DocumentBatchTraceReaderAdapter`:

```php
final readonly class DocumentBatchTraceReaderAdapter implements
    \App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader
{
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
    ): array;

    public function backwardForPartner(
        string $tenantId,
        string $companyId,
        string $partnerId,
        ?array $locationIds,
        ?string $productId,
        ?string $dateFrom,
        ?string $dateTo,
    ): array;

    public function batchIdsVisibleAtLocations(
        string $tenantId,
        string $companyId,
        array $locationIds,
    ): array;
}
```

No constructor is declared. It applies tenant and company predicates before line predicates. Effective location uses line location first and document location only when line location is null. Null effective locations are returned only for unrestricted reads.

NEW `App\Modules\POS\Infrastructure\BatchTraceability\PosBatchTraceReaderAdapter`:

```php
final readonly class PosBatchTraceReaderAdapter implements
    \App\Shared\Contracts\BatchTraceability\PosBatchTraceReader
{
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
    ): array;

    public function batchIdsVisibleAtLocations(
        string $tenantId,
        string $companyId,
        array $locationIds,
    ): array;
}
```

No constructor is declared. It applies receipt tenant, company, and persisted-location predicates. Null-location receipts are included only when `$locationIds === null`.

Add these exact bindings to the existing provider `register()` methods:

```php
$this->app->bind(
    \App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader::class,
    \App\Modules\Document\Infrastructure\BatchTraceability\DocumentBatchTraceReaderAdapter::class,
);
```

```php
$this->app->bind(
    \App\Shared\Contracts\BatchTraceability\PosBatchTraceReader::class,
    \App\Modules\POS\Infrastructure\BatchTraceability\PosBatchTraceReaderAdapter::class,
);
```

### 7.4 Repository and service signatures

Modify both interface and implementation:

```php
public function findVisibleByUuid(
    string $uuid,
    string $companyId,
    ?array $locationIds,
    array $historicallyVisibleBatchIds = [],
): ?\App\Modules\BatchExpiry\Domain\Entities\Batch;

public function getByProduct(
    string $tenantId,
    string $companyId,
    string $productId,
    bool $activeOnly = true,
    ?array $locationIds = null,
): \Illuminate\Support\Collection;

public function getByCompany(
    string $companyId,
    array $filters = [],
    ?array $locationIds = null,
    array $historicallyVisibleBatchIds = [],
): \Illuminate\Support\Collection;
```

Behavior:

- UUID and company always match.
- `null` retains company-wide zero-stock metadata.
- A list requires allowed current stock or a supplied historical-visible ID.
- `[]` returns nothing unless the historical ID set explicitly contains the batch.
- Every eager-loaded `batchStock` relation uses the same resolved location predicate.
- Product reads retain tenant, company, product, active-only, FEFO, and deterministic ID ordering.

Modify `App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService`:

```php
public function getExpiringProducts(
    string $companyId,
    int $daysThreshold = 30,
    string|array|null $locationId = null,
): \Illuminate\Support\Collection;

public function getBatchStockByLocation(
    string $batchId,
    ?array $locationIds = null,
): \Illuminate\Support\Collection;
```

Its existing constructor remains:

```php
public function __construct(
    private readonly \App\Modules\Product\Application\Contracts\ProductVariantLookup $variantLookup,
) {}
```

`getExpiredBatchesWithStock()` retains its current signature and fail-closed `[]` semantics. Expiring existence and eager-load predicates use the same location list.

Modify `BatchResource`:

```php
public function toArray(
    \Illuminate\Http\Request $request,
): array;

private function scopedTotalQuantity(): string;

private function scopedAvailableQuantity(): string;
```

### 7.5 Correct controller constructors and signatures

Modify `App\Modules\BatchExpiry\Presentation\Controllers\BatchController`:

```php
public function __construct(
    private readonly \App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface $batchRepository,
    private readonly \App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService $fefoService,
    private readonly \App\Modules\Company\Services\CompanyContext $companyContext,
    private readonly \App\Modules\BatchExpiry\Application\Services\BatchStockService $batchStockService,
    private readonly \App\Modules\BatchExpiry\Domain\Services\BatchWriteOffService $batchWriteOffService,
    private readonly \App\Modules\BatchExpiry\Domain\Services\ReverseWriteOffService $reverseWriteOffService,
    private readonly \App\Modules\Company\Services\LocationScopeResolver $locationScopeResolver,
    private readonly \App\Modules\Company\Services\LocationContext $locationContext,
    private readonly \App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation $activation,
    private readonly \App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader $documentTraceReader,
    private readonly \App\Shared\Contracts\BatchTraceability\PosBatchTraceReader $posTraceReader,
) {}
```

Add:

```php
private function resolvedReadLocationIds(
    \Illuminate\Http\Request $request,
    array $requestedLocationIds = [],
): ?array;

private function historicallyVisibleBatchIds(
    string $tenantId,
    string $companyId,
    array $locationIds,
): array;

private function findVisibleBatchOrFail(
    \Illuminate\Http\Request $request,
    string $uuid,
): \App\Modules\BatchExpiry\Domain\Entities\Batch|\Illuminate\Http\JsonResponse;
```

Modify:

```php
public function index(
    \Illuminate\Http\Request $request,
): \Illuminate\Http\Resources\Json\AnonymousResourceCollection;

public function show(
    \Illuminate\Http\Request $request,
    string $uuid,
): \Illuminate\Http\JsonResponse;

public function store(
    \App\Modules\BatchExpiry\Presentation\Requests\CreateBatchRequest $request,
): \Illuminate\Http\JsonResponse;

public function expiring(
    \Illuminate\Http\Request $request,
): \Illuminate\Http\JsonResponse;

public function expired(
    \Illuminate\Http\Request $request,
): \Illuminate\Http\JsonResponse;

public function stock(
    \Illuminate\Http\Request $request,
    string $uuid,
): \Illuminate\Http\JsonResponse;

public function posAvailableBatches(
    \Illuminate\Http\Request $request,
    string $productId,
): \Illuminate\Http\JsonResponse;

public function productBatchStock(
    \Illuminate\Http\Request $request,
    string $productId,
): \Illuminate\Http\JsonResponse;
```

Flag-off returns legacy read and duplicate behavior. Flag-on validates `expiring.location_id`, resolves membership without bypass, filters all reads, and adds `meta.outcome="already_exists"` to the existing 422 duplicate response.

Modify `App\Modules\BatchExpiry\Presentation\Controllers\BatchTraceabilityController`:

```php
public function __construct(
    private readonly \App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface $batchRepository,
    private readonly \App\Modules\Company\Services\CompanyContext $companyContext,
    private readonly \App\Modules\Company\Services\LocationContext $locationContext,
    private readonly \App\Modules\Company\Services\LocationScopeResolver $locationScopeResolver,
    private readonly \App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation $activation,
    private readonly \App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader $documentTraceReader,
    private readonly \App\Shared\Contracts\BatchTraceability\PosBatchTraceReader $posTraceReader,
) {}

public function forwardTrace(
    \Illuminate\Http\Request $request,
    string $uuid,
): \Illuminate\Http\JsonResponse;

public function backwardTrace(
    \Illuminate\Http\Request $request,
    string $partnerId,
): \Illuminate\Http\JsonResponse;

private function resolvedTraceLocationIds(
    \Illuminate\Http\Request $request,
): ?array;
```

Remove all imports of:

- `App\Modules\Document\Domain\Document`
- `App\Modules\Document\Domain\DocumentLine`
- `App\Modules\Document\Domain\Enums\DocumentType`
- `App\Modules\POS\Domain\ReceiptLineBatchAllocation`

### 7.6 Tests and denied-mutation evidence

NEW `Tests\Feature\BatchExpiry\BatchActionPermissionsTest`:

```php
final class BatchActionPermissionsTest extends \Tests\TestCase
{
    public function test_each_existing_batch_route_requires_its_exact_action_permission(): void;

    public function test_flag_off_preserves_pre_activation_access_and_payloads(): void;

    public function test_cashier_denied_recall_leaves_stock_history_reservations_and_gl_unchanged(): void;

    public function test_viewer_denied_delete_leaves_stock_history_reservations_and_gl_unchanged(): void;

    public function test_manager_denied_global_recall_leaves_stock_history_reservations_and_gl_unchanged(): void;
}
```

NEW `Tests\Feature\BatchExpiry\BatchReadLocationScopeTest`:

```php
final class BatchReadLocationScopeTest extends \Tests\TestCase
{
    public function test_detail_excludes_other_branch_stock(): void;

    public function test_every_read_filters_other_branch_and_empty_scope(): void;

    public function test_zero_stock_company_lot_is_visible_only_to_unrestricted_actor(): void;

    public function test_depleted_lot_is_visible_only_through_attributable_history(): void;

    public function test_second_company_selected_second_location_and_duplicate_create_are_isolated(): void;
}
```

NEW `Tests\Feature\BatchExpiry\BatchExpiringLocationScopeTest`:

```php
final class BatchExpiringLocationScopeTest extends \Tests\TestCase
{
    public function test_expiring_validates_uuid_and_filters_loaded_stock(): void;

    public function test_empty_membership_scope_returns_no_expiring_lots(): void;
}
```

NEW `Tests\Feature\BatchExpiry\BatchTraceReaderContractTest`:

```php
final class BatchTraceReaderContractTest extends \Tests\TestCase
{
    public function test_document_and_pos_adapters_apply_company_and_location_scope(): void;

    public function test_nullable_location_is_visible_only_to_unrestricted_membership(): void;

    public function test_forward_and_backward_json_contracts_are_field_for_field_compatible(): void;
}
```

NEW `Tests\Architecture\BatchTraceabilityModuleBoundaryTest`:

```php
final class BatchTraceabilityModuleBoundaryTest extends \PHPUnit\Framework\TestCase
{
    public function test_batch_expiry_traceability_imports_only_shared_contracts(): void;
}
```

<a id="r2-m4"></a>
**R2-M4:** each denied mutation test snapshots, in deterministic primary-key order, the target `product_batches` row, `inventory_batch_stock`, `stock_reservations`, `document_lines`, `pos_receipt_line_batch_allocations`, `journal_entries`, and `journal_lines`. After the 403 it asserts strict equality of the normalized before/after arrays and unchanged row counts. It also asserts unchanged `is_active`, `is_recalled`, `recall_reason`, `recalled_at`, quantities, reservations, trace allocations, and GL totals. A 403 alone is not sufficient.

Red-first table:

| Exact file | Exact class::method | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | `BatchActionPermissionsTest::test_each_existing_batch_route_requires_its_exact_action_permission` | `$response->assertForbidden();` for a recall actor without `batches.recall` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchActionPermissionsTest::test_each_existing_batch_route_requires_its_exact_action_permission'` | PHPUnit PostgreSQL |
| `tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | `BatchReadLocationScopeTest::test_detail_excludes_other_branch_stock` | `self::assertNotContains($locationA2Id, array_column($response->json('data.batch_stock'), 'location_id'));` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_detail_excludes_other_branch_stock'` | PHPUnit PostgreSQL |
| `tests/Architecture/BatchTraceabilityModuleBoundaryTest.php` | `BatchTraceabilityModuleBoundaryTest::test_batch_expiry_traceability_imports_only_shared_contracts` | `self::assertSame([], $violations, implode(PHP_EOL, $violations));` | `cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchTraceabilityModuleBoundaryTest::test_batch_expiry_traceability_imports_only_shared_contracts'` | PHPUnit no-DB |

### 7.7 Convention-09 evidence

`BatchReadLocationScopeTest::test_second_company_selected_second_location_and_duplicate_create_are_isolated()` must:

1. Create company A through existing setup.
2. Register company B through `POST /api/v1/companies` using the real payload beginning at [CreateCompanyTest.php:76](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Company/CreateCompanyTest.php:76).
3. Create A1 and A2 through `POST /api/v1/locations`; make A2 `pos_enabled=true`.
4. Create B1 and B2 through the same endpoint; make B2 `pos_enabled=true`.
5. Create the same SKU and lot label independently in A and B.
6. Persist the restricted actor’s A membership with `allowed_location_ids=[A2]`.
7. Prove A2—not A1—is the selected location by placing stock and attributable trace history at A2 and asserting those exact IDs are visible.
8. Assert A1 and company-B stock/history identifiers are absent.
9. Repeat the company-A create request.
10. Assert 422, `meta.outcome=already_exists`, exactly one company-A lot, and unchanged quantity/reservation/history/GL snapshots.

<a id="r2-n2"></a>
**R2-N2:** Task 2’s second-company test creates B1 and POS-enabled B2, persists `allowed_location_ids=[B2]` on the company-B active membership, and asserts rejection is caused by that exact B2-restricted membership. B2 is the selected location, not decorative setup.

### 7.8 Implementation and reviewer gate

Order:

1. Add and capture the three red-first assertions.
2. Add activation config and middleware.
3. Apply the route matrix.
4. Add the three complete trace DTOs and two interfaces.
5. Add and bind owning-module adapters.
6. Add response field-for-field compatibility tests.
7. Remove cross-module persistence imports.
8. Add repository scope parameters and filtered eager loads.
9. Thread scope through list/detail/stock/expiry/product/POS/trace reads.
10. Preserve unrestricted zero-stock and null-location history.
11. Replace float/unscoped totals.
12. Add the duplicate outcome behind the flag.
13. Run focused, architecture, denied-snapshot, and convention-09 tests.

<a id="r2-m8"></a>
**R2-M8:** Task 1 requires ACCEPT from both `tenancy-authz-reviewer` and `inventory-costing-reviewer`. The latter must explicitly approve four-decimal scoped totals and unchanged quantity, reservation, history, journal-entry, and journal-line snapshots on denial. Task 2, Task 6, and the combined diff require the same reviewer because they change role eligibility for batch reads/actions.

Rollback:

- Before activation, revert the Task-1 Push-3 commit and keep the flag false.
- After activation, disable the flag, redeploy worker → API → scheduler, rebuild configuration cache, reset permission cache, and preserve additive data.
- Record that flag-off temporarily restores legacy API read/authorization behavior.

## 8. Task 2 — role delta and guarded general-manager assignment

### 8.1 Files

Add:

- `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php`
- `apps/api/app/Modules/Identity/Domain/Enums/SystemRoleName.php`
- `apps/api/app/Modules/Identity/Domain/Enums/RoleProvisioningSource.php`
- `apps/api/app/Modules/Identity/Domain/Enums/LotActionPermissionDeltaOutcome.php`
- `apps/api/app/Modules/Identity/Application/DTOs/LotActionPermissionDeltaResult.php`
- `apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php`
- `apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php`
- `apps/api/app/Console/Commands/ApplyLotActionPermissionDelta.php`

Modify:

- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`
- `apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php`
- `docs/glossary.md`

Add tests:

- `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php`
- `apps/api/tests/Feature/Identity/LotActionPermissionDeltaTest.php`
- `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php`
- `apps/api/tests/Feature/Identity/GeneralManagerRoleProtectionTest.php`
- `apps/api/tests/Feature/Identity/GeneralManagerAssignmentWriterCensusTest.php`
- `apps/api/tests/Feature/Console/LotActionReseedMarkerTest.php`
- `apps/api/tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php`
- `apps/api/tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php`

### 8.2 Migration

Anonymous migration, no namespace:

```php
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void;

    public function down(): void;
};
```

Target: `roles`.

| Column | SQL type | Nullable | Default | FK | Index |
|---|---|---:|---|---|---|
| `provisioning_source` | `VARCHAR(32)` | yes | SQL `NULL` | none | none |

PostgreSQL constraint:

```sql
CONSTRAINT roles_provisioning_source_check
CHECK (
    provisioning_source IS NULL
    OR (
        provisioning_source = 'w-lot-a-1a'
        AND name = 'general_manager'
        AND guard_name = 'sanctum'
    )
)
```

SQLite triggers:

```text
roles_provisioning_source_insert_guard
roles_provisioning_source_update_guard
```

`up()` must assert the table, add only an absent column, validate existing type/length/nullability/default, compare the named PostgreSQL constraint after normalized whitespace, install equivalent SQLite triggers, and be a no-op only for the exact schema.

`down()` must refuse while any row is marked; drop only the named constraint/triggers and column; and never delete a role, permission, or pivot.

### 8.3 Enums and result DTO

NEW `App\Modules\Identity\Domain\Enums\SystemRoleName`:

```php
enum SystemRoleName: string
{
    case GeneralManager = 'general_manager';
}
```

NEW `App\Modules\Identity\Domain\Enums\RoleProvisioningSource`:

```php
enum RoleProvisioningSource: string
{
    case Wlota1a = 'w-lot-a-1a';
}
```

NEW `App\Modules\Identity\Domain\Enums\LotActionPermissionDeltaOutcome`:

```php
enum LotActionPermissionDeltaOutcome: string
{
    case Applied = 'APPLIED';
    case AlreadyApplied = 'ALREADY_APPLIED';
    case Skipped = 'SKIPPED';
    case Failed = 'FAILED';
}
```

NEW `App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult`:

```php
final readonly class LotActionPermissionDeltaResult
{
    public function __construct(
        public readonly \App\Modules\Identity\Domain\Enums\LotActionPermissionDeltaOutcome $outcome,
        public readonly string $reason,
    ) {}
}
```

### 8.4 Delta service and permission-team boundary

NEW `App\Modules\Identity\Application\Services\LotActionPermissionDelta`:

```php
final readonly class LotActionPermissionDelta
{
    public function __construct(
        private readonly \Spatie\Permission\PermissionRegistrar $permissionRegistrar,
    ) {}

    public function apply(
        string $tenantId,
        array $permissionNames,
        array $rolePermissionGrants,
    ): \App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult;

    public function verify(
        string $tenantId,
        array $permissionNames,
        array $rolePermissionGrants,
    ): \App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult;

    private function execute(
        string $tenantId,
        array $permissionNames,
        array $rolePermissionGrants,
        bool $write,
    ): \App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult;

    private function acquireTenantLock(string $tenantId): void;

    private function provisionGeneralManager(
        array $permissions,
        bool $write,
    ): \Spatie\Permission\Models\Role;

    private function synchronizeSeededRole(
        \Spatie\Permission\Models\Role $role,
        array $permissions,
        bool $newRole,
        bool $write,
    ): void;

    private function verifyCanonicalState(
        string $tenantId,
        array $permissionNames,
        array $rolePermissionGrants,
    ): void;
}
```

Binding: Laravel constructor auto-resolution.

<a id="r2-m2"></a>
**R2-M2:** `execute()` must use this boundary:

```php
$previousTeamId = $this->permissionRegistrar->getPermissionsTeamId();
$this->permissionRegistrar->setPermissionsTeamId($tenantId);

try {
    return \Illuminate\Support\Facades\DB::transaction(function () use (
        $tenantId,
        $permissionNames,
        $rolePermissionGrants,
        $write,
    ): \App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult {
        $this->acquireTenantLock($tenantId);
        // Lock tenant-team roles in deterministic name/id order.
        // Read or create permissions and roles.
        // Apply only allowed additions and the explicit manager recall removal.
        // Verify the complete canonical postcondition before commit/return.
    });
} finally {
    $this->permissionRegistrar->setPermissionsTeamId($previousTeamId);
}
```

The PostgreSQL advisory lock is transaction-scoped and therefore remains held across read/write/verification. The SQLite lane relies on the enclosing transaction. Tests must cover concurrent first apply, cross-tenant discrimination, stale prior team context restoration, and exception-path restoration.

Rules:

- An unmarked existing `general_manager` is a collision and is never adopted.
- No force option exists.
- A marked role retains its bigint ID.
- Seeded roles gain missing canonical grants.
- Unrelated custom grants remain.
- The only explicit revocation is manager losing `batches.recall`.
- `verify()` writes nothing.
- `SKIPPED` and `FAILED` are never promotion-success outcomes.

### 8.5 Canonical and legacy matrices

Add exactly these catalogue permissions:

```text
batches.recall.request
treasury.manage_all_locations
```

Canonical delta:

| Seeded role | Result |
|---|---|
| `admin` | All permission names |
| `general_manager` | Revised manager set plus `batches.recall` and `treasury.manage_all_locations` |
| `manager` | `batches.view`, create, update, delete, write-off, traceability, request; no `batches.recall` |
| `cashier` | `batches.view` |
| `viewer` | `batches.view` |
| `operator` | `batches.view` |
| `technician` | No new batch grant |
| `accountant` | No new batch grant |

The flag-off legacy helpers must reproduce the HEAD catalogue and role arrays exactly, including manager global recall, no `general_manager`, and no new viewer/operator batch grant. They may be mechanically extracted from the current arrays but cannot call the canonical helpers.

### 8.6 Seeder contract

Modify `Database\Seeders\RolesAndPermissionsSeeder`:

```php
public function __construct(
    private readonly \Spatie\Permission\PermissionRegistrar $permissionRegistrar,
    private readonly \App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation $activation,
    private readonly \App\Modules\Identity\Application\Services\LotActionPermissionDelta $lotActionPermissionDelta,
) {}

public function run(): void;

private function createPermissionsFrom(array $permissionNames): void;

private function createLegacyRoles(): void;

/** @return list<string> */
private static function legacyPermissionNames(): array;

/** @return array<string, list<string>> */
private static function legacyRolePermissionGrants(): array;

/** @return list<string> */
public static function permissionNames(): array;

/** @return array<string, list<string>> */
public static function rolePermissionGrants(): array;

private function currentTenantId(): string;

private function emitWlota1aReseedMarker(
    string $tenantId,
    string $mode,
    \App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult $result,
): void;
```

Behavior:

- Flag off: run exact legacy creation/synchronization and emit one marker:

```text
WLOTA1A-RESEED tenant=<uuid> mode=LEGACY outcome=ALREADY_APPLIED reason=enforcement_off
```

- Flag on: invoke the canonical delta and emit:

```text
WLOTA1A-RESEED tenant=<uuid> mode=ACTIVATED outcome=APPLIED|ALREADY_APPLIED reason=<token>
```

- A failure throws and emits no success marker.
- The exporter continues to consume public canonical `permissionNames()` and `rolePermissionGrants()`.
- Push 4 does not invoke the generic seeder while the flag is false.

### 8.7 Delta command

NEW `App\Console\Commands\ApplyLotActionPermissionDelta`:

```php
final class ApplyLotActionPermissionDelta extends \Illuminate\Console\Command
{
    protected $signature = 'permissions:apply-lot-action-delta
        {--apply : Apply the permission and role delta}
        {--verify : Verify the permission and role delta without writing}';

    protected $description =
        'Apply or verify W-LOT-A-1a lot-action permissions and the seeded general-manager role.';

    public function __construct(
        private readonly \App\Modules\Identity\Application\Services\LotActionPermissionDelta $delta,
    ) {
        parent::__construct();
    }

    public function handle(): int;
}
```

Register the command by modifying:

```php
public function \App\Modules\BatchExpiry\BatchExpiryServiceProvider::boot(): void;
```

Add `\App\Console\Commands\ApplyLotActionPermissionDelta::class` to the existing command list.

<a id="r2-m7"></a>
**R2-M7:** exactly one mode is mandatory:

| Invocation | Result |
|---|---|
| `--apply` only | Apply; emit one tenant marker; exit 0 only for `APPLIED` or `ALREADY_APPLIED` |
| `--verify` only | Read-only verify; emit one marker; exit 0 only for `ALREADY_APPLIED` |
| neither | emit `outcome=FAILED reason=invalid_mode`; exit 2 |
| both | emit `outcome=FAILED reason=invalid_mode`; exit 2 |
| collision/schema/invariant failure | emit `outcome=FAILED reason=<stable-token>`; exit 1 |
| any `SKIPPED` result | emit it and exit 1 |

Marker:

```text
WLOTA1A-PERMISSIONS tenant=<uuid> mode=APPLY|VERIFY outcome=APPLIED|ALREADY_APPLIED|SKIPPED|FAILED reason=<token>
```

No option containing `force` is added.

### 8.8 Assignment guard, transactions, and correct constructors

NEW `App\Modules\Identity\Application\Services\GeneralManagerAssignmentGuard` declares no constructor:

```php
final readonly class GeneralManagerAssignmentGuard
{
    public function assertAssignable(
        \App\Modules\Identity\Domain\User $actor,
        string $targetUserId,
        string $companyId,
        array $effectiveRoleNames,
        ?array $effectiveAllowedLocationIds,
    ): void;

    public function assertLocationChangeAllowed(
        \App\Modules\Identity\Domain\User $actor,
        string $targetUserId,
        string $companyId,
        array $effectiveRoleNames,
        ?array $effectiveAllowedLocationIds,
    ): void;

    private function assertEveryActiveMembershipIsUnrestricted(
        string $targetUserId,
        string $companyId,
        ?array $effectiveAllowedLocationIds,
    ): void;
}
```

It throws validation errors with HTTP 422 semantics and code `GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP`. An explicit `null` is unrestricted; `[]` is restricted. Omitted fields use persisted values. Atomic demotion plus narrowing is allowed.

Modify `App\Modules\Identity\Presentation\Controllers\UserController`:

```php
public function __construct(
    private readonly \App\Modules\Company\Services\CompanyContext $companyContext,
    private readonly \App\Modules\Company\Services\LocationContext $locationContext,
    private readonly \App\Modules\Tenant\Application\Services\IdentityIndexService $identityIndexService,
    private readonly \App\Modules\Tenant\Application\Services\TenantLinkSigner $tenantLinkSigner,
    private readonly \App\Modules\Identity\Application\Services\GeneralManagerAssignmentGuard $generalManagerAssignmentGuard,
    private readonly \Spatie\Permission\PermissionRegistrar $permissionRegistrar,
) {}

public function store(
    \App\Modules\Identity\Presentation\Requests\CreateUserRequest $request,
): \Illuminate\Http\JsonResponse;

public function update(
    \App\Modules\Identity\Presentation\Requests\UpdateUserRequest $request,
    string $id,
): \Illuminate\Http\JsonResponse;
```

Modify `App\Modules\Identity\Presentation\Controllers\RoleController`:

```php
public function __construct(
    private readonly \App\Modules\Company\Services\CompanyContext $companyContext,
    private readonly \App\Services\CompanyConfigService $configService,
    private readonly \App\Modules\Identity\Application\Services\GeneralManagerAssignmentGuard $generalManagerAssignmentGuard,
    private readonly \Spatie\Permission\PermissionRegistrar $permissionRegistrar,
) {}

public function update(
    \App\Modules\Identity\Presentation\Requests\UpdateRoleRequest $request,
    int $id,
): \Illuminate\Http\JsonResponse;

public function destroy(
    \App\Modules\Identity\Presentation\Requests\DeleteRoleRequest $request,
    int $id,
): \Illuminate\Http\JsonResponse;

public function assignRole(
    \App\Modules\Identity\Presentation\Requests\AssignRoleRequest $request,
    string $userId,
): \Illuminate\Http\JsonResponse;

private function isProtectedSystemRole(
    \Spatie\Permission\Models\Role $role,
): bool;
```

<a id="r2-m3"></a>
**R2-M3:** each create, update, and dedicated assignment path captures the previous Spatie team ID, sets the authenticated tenant ID, executes one `DB::transaction`, and restores the prior team ID in `finally`. The transaction covers:

1. Target-user `FOR UPDATE`.
2. Active memberships ordered by ascending `company_id`, then locked.
3. Team-scoped role row lookup/lock.
4. Merge of omitted request values with persisted roles/location scope.
5. Final-state guard validation.
6. Membership write.
7. Role assignment/synchronization.
8. Existing audit row and `RoleAssigned` event emission.
9. Postcondition verification.

User creation creates the user and active membership, writes location scope, then validates and assigns. Invitation delivery stays after commit. Dedicated assignment requires an active membership in the current company and retains `AssignableRole`.

`isProtectedSystemRole()` is true for:

- Existing `super-admin`, `admin`, and `owner`.
- `name=general_manager`, `guard_name=sanctum`, `provisioning_source=w-lot-a-1a`.

Protected roles cannot be renamed or deleted, even with zero users.

### 8.9 Tests

NEW `Tests\Feature\Identity\LotActionSeededRoleMatrixTest`:

```php
final class LotActionSeededRoleMatrixTest extends \Tests\TestCase
{
    public function test_viewer_and_operator_gain_batch_view_in_canonical_matrix(): void;

    public function test_manager_loses_global_recall_and_gains_request_in_canonical_matrix(): void;

    public function test_general_manager_has_the_ruled_permission_delta(): void;

    public function test_flag_off_generic_seeder_retains_exact_legacy_role_behavior(): void;

    public function test_flag_on_fresh_provisioning_uses_canonical_matrix(): void;
}
```

NEW `Tests\Feature\Identity\LotActionPermissionDeltaTest`:

```php
final class LotActionPermissionDeltaTest extends \Tests\TestCase
{
    public function test_first_apply_and_second_apply_have_explicit_outcomes(): void;

    public function test_rerun_preserves_role_id_and_custom_permissions(): void;

    public function test_unmarked_general_manager_collision_fails_closed(): void;

    public function test_concurrent_first_apply_creates_one_marked_role(): void;

    public function test_verify_is_read_only(): void;

    public function test_transaction_restores_previous_permission_team_on_success_and_exception(): void;

    public function test_two_tenants_with_same_role_names_are_discriminated_by_team(): void;
}
```

NEW `Tests\Feature\Identity\GeneralManagerAssignmentTest`:

```php
final class GeneralManagerAssignmentTest extends \Tests\TestCase
{
    public function test_create_update_and_dedicated_assignment_reject_restricted_membership(): void;

    public function test_unrestricted_assignment_succeeds_after_membership_creation(): void;

    public function test_general_manager_cannot_later_be_location_narrowed(): void;

    public function test_atomic_demotion_and_location_narrowing_succeeds(): void;

    public function test_second_company_selected_b2_membership_must_also_be_unrestricted(): void;

    public function test_dedicated_assignment_restores_previous_permission_team(): void;

    public function test_concurrent_assignment_and_membership_narrowing_are_serialized(): void;
}
```

The concurrency test uses a barrier-controlled PostgreSQL race and proves bounded completion and one valid final state.

NEW `Tests\Feature\Identity\GeneralManagerRoleProtectionTest`:

```php
final class GeneralManagerRoleProtectionTest extends \Tests\TestCase
{
    public function test_marked_general_manager_cannot_be_renamed(): void;

    public function test_marked_general_manager_cannot_be_deleted(): void;

    public function test_delta_rerun_after_protection_preserves_role_identity(): void;
}
```

NEW `Tests\Feature\Identity\GeneralManagerAssignmentWriterCensusTest`:

```php
final class GeneralManagerAssignmentWriterCensusTest extends \Tests\TestCase
{
    public function test_runtime_general_manager_assignment_sites_use_the_guard_and_team_boundary(): void;
}
```

NEW `Tests\Feature\Console\LotActionReseedMarkerTest`:

```php
final class LotActionReseedMarkerTest extends \Tests\TestCase
{
    public function test_flag_off_seeder_emits_one_legacy_marker_without_canonical_role_changes(): void;

    public function test_flag_on_fresh_seed_emits_one_activated_applied_marker(): void;

    public function test_activated_reseed_emits_one_already_applied_marker(): void;
}
```

NEW `Tests\Feature\Console\ApplyLotActionPermissionDeltaCommandTest`:

```php
final class ApplyLotActionPermissionDeltaCommandTest extends \Tests\TestCase
{
    public function test_apply_emits_exact_applied_marker_and_exits_zero(): void;

    public function test_verify_emits_exact_already_applied_marker_and_is_read_only(): void;

    public function test_neither_mode_emits_invalid_mode_and_exits_two(): void;

    public function test_both_modes_emit_invalid_mode_and_exit_two(): void;

    public function test_unmarked_collision_emits_failed_marker_and_exits_one(): void;

    public function test_missing_schema_emits_failed_marker_and_exits_one(): void;
}
```

NEW `Tests\Feature\Migrations\RoleProvisioningSourceSchemaTest`:

```php
final class RoleProvisioningSourceSchemaTest extends \Tests\TestCase
{
    public function test_postgresql_schema_matches_exact_contract(): void;

    public function test_sqlite_triggers_reject_invalid_markers(): void;

    public function test_down_refuses_while_a_marked_role_exists(): void;
}
```

Red-first assertions:

| Exact file | Exact class::method | First failing assertion | Command | Lane |
|---|---|---|---|---|
| `tests/Feature/Identity/LotActionSeededRoleMatrixTest.php` | `LotActionSeededRoleMatrixTest::test_viewer_and_operator_gain_batch_view_in_canonical_matrix` | `self::assertContains('batches.view', $grants['viewer']);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotActionSeededRoleMatrixTest::test_viewer_and_operator_gain_batch_view_in_canonical_matrix'` | PostgreSQL |
| same | `LotActionSeededRoleMatrixTest::test_manager_loses_global_recall_and_gains_request_in_canonical_matrix` | `self::assertNotContains('batches.recall', $grants['manager']);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotActionSeededRoleMatrixTest::test_manager_loses_global_recall_and_gains_request_in_canonical_matrix'` | PostgreSQL |
| `tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php` | `ApplyLotActionPermissionDeltaCommandTest::test_neither_mode_emits_invalid_mode_and_exits_two` | `$this->artisan('permissions:apply-lot-action-delta')->assertExitCode(2);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'ApplyLotActionPermissionDeltaCommandTest::test_neither_mode_emits_invalid_mode_and_exits_two'` | PostgreSQL |

Task-2 convention-09 evidence persists `allowed_location_ids=[B2]`, attempts `general_manager`, and asserts 422 plus the stable error code. Delta rerun proves `APPLIED`, then `ALREADY_APPLIED`, one marked role, the same ID, no duplicate pivots, and preserved custom grants.

Task 2 requires ACCEPT from `tenancy-authz-reviewer` and `inventory-costing-reviewer`. Review must cover team identity, transaction extent, lock order, collision behavior, role identity, custom grants, manager recall removal, assignment paths, B2 selection, narrowing race, rename/delete protection, marker cardinality, and absence of hold behavior.

Rollback is forward-only: preserve the marker column and marked role ID; correct grants through a new delta; never delete/rename/unmark the role or edit pivots ad hoc.

## 9. Task 6 — web route/action gating and generated map

### 9.1 Files

Modify:

- `apps/web/src/routes/index.tsx`
- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- `apps/web/src/features/batches/pages/BatchListPage.tsx`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/web/src/hooks/usePermissions.ts`
- `apps/web/src/hooks/permissionsMap.generated.ts`
- `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx`
- `apps/web/src/hooks/__tests__/usePermissions.moduleAccess.test.tsx`

Add:

- `apps/web/src/routes/__tests__/BatchRoutePermissions.test.tsx`
- `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx`
- `apps/web/src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts`
- `apps/web/e2e/batch-permissions.spec.ts`

No additional hook, API type, locale file, recall history, hold component, or request-recall action is permitted.

### 9.2 Production symbols

Modify existing `AppRoutes()` and add:

```ts
export const WLOTA1A_WEB_FINGERPRINT =
  'wlota1a-batch-permission-gating-v1' as const
```

Attach:

```tsx
handle={{ featureFingerprint: WLOTA1A_WEB_FINGERPRINT }}
```

Route gates:

| Route | Module | Permission |
|---|---|---|
| `/inventory/batches` | `BatchExpiry` | `batches.view` |
| `/inventory/batches/:uuid` | `BatchExpiry` | `batches.view` |
| `/inventory/batches/new` | `BatchExpiry` | `batches.create` |
| `/inventory/batches/:uuid/edit` | `BatchExpiry` | `batches.update` |
| `/inventory/expiry-write-off` | `BatchExpiry` | retain `batches.write-off` |

Modify existing:

```ts
function buildNavigation(isAutomotiveVertical: boolean): NavModule[]
```

Batch child:

```ts
{
  key: 'batches',
  href: '/inventory/batches',
  icon: Pill,
  module: 'BatchExpiry',
  permission: 'batches.view',
}
```

Modify:

```ts
export function BatchListPage()
```

<a id="r2-m5"></a>
**R2-M5:** compute one value:

```ts
const canCreate = hasPermission('batches.create')
```

Use it to conditionally render both the header link and the empty-state link. A denied link is absent, not disabled/focusable.

Modify:

```ts
export function BatchDetailPage()
```

Final booleans:

```ts
const canEdit =
  hasPermission('batches.update') &&
  batch.is_active &&
  !batch.is_recalled

const canRecall =
  hasPermission('batches.recall') &&
  batch.is_active &&
  !batch.is_recalled &&
  !batch.is_expired

const canDelete =
  hasPermission('batches.delete') &&
  batch.is_active
```

Do not change `handleRecall()`.

Modify:

```ts
const SERVER_AUTHORITATIVE_PERMISSIONS: Set<Permission>
```

Add every batch permission:

```text
batches.view
batches.create
batches.update
batches.delete
batches.recall
batches.recall.request
batches.write-off
batches.traceability
```

Modify `MODULE_PERMISSIONS` with:

```ts
'batches.view': ['batches.view']
```

A present server permission payload is authoritative; role fallback must not recreate an absent batch permission.

Generated map expectations:

| Permission | Roles |
|---|---|
| `batches.view` | `admin`, `cashier`, `general_manager`, `manager`, `operator`, `viewer` |
| `batches.create` | `admin`, `general_manager`, `manager` |
| `batches.update` | `admin`, `general_manager`, `manager` |
| `batches.delete` | `admin`, `general_manager`, `manager` |
| `batches.recall.request` | `admin`, `general_manager`, `manager` |
| `batches.recall` | `admin`, `general_manager` |
| `batches.write-off` | `admin`, `general_manager`, `manager` |
| `batches.traceability` | `admin`, `general_manager`, `manager` |
| `treasury.manage_all_locations` | `admin`, `general_manager` |

Regenerate only through `php artisan permissions:export-frontend-map`; the current exporter reads both seeder helpers at [ExportFrontendPermissionsMap.php:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:29).

### 9.3 Exact Vitest and Playwright symbols

<a id="r2-m6"></a>
**R2-M6:** these titles are mandatory and exact.

`src/routes/__tests__/BatchRoutePermissions.test.tsx`:

```ts
describe('W-LOT-A-1a batch route permissions', () => {
  it('denies list and detail with inventory.view but without batches.view', ...)
  it('allows list and detail with batches.view', ...)
  it('denies create with batches.view alone', ...)
  it('allows create with batches.create', ...)
  it('denies edit with batches.view alone', ...)
  it('allows edit with batches.update', ...)
  it('denies every batch route when BatchExpiry is disabled', ...)
})
```

`src/features/batches/pages/__tests__/BatchPermissions.test.tsx`:

```ts
describe('W-LOT-A-1a batch action permissions', () => {
  it('hides the populated-list header create link without batches.create', ...)
  it('hides the empty-state create link without batches.create', ...)
  it('shows both create affordances only with batches.create', ...)
  it('hides edit without batches.update', ...)
  it('hides delete without batches.delete', ...)
  it('hides recall from the revised manager payload', ...)
  it('shows recall to an eligible general manager with batches.recall', ...)
  it('retains state-based action suppression when permission exists', ...)
})
```

`src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts`:

```ts
describe('W-LOT-A-1a generated seeded permission map', () => {
  it('matches the exact canonical batch and all-location role matrix', ...)
})
```

Add to `Sidebar.test.tsx`:

```ts
describe('W-LOT-A-1a batch navigation', () => {
  it('hides batch navigation without batches.view', ...)
  it('shows batch navigation with batches.view and BatchExpiry enabled', ...)
  it('hides batch navigation when BatchExpiry is disabled', ...)
})
```

Add to `usePermissions.moduleAccess.test.tsx`:

```ts
describe('W-LOT-A-1a server-authoritative batch permissions', () => {
  it('accepts batches.view as a valid ModuleKey', ...)
  it('uses a present server permission list as authoritative', ...)
  it('does not restore a missing batch permission from role fallback', ...)
})
```

`e2e/batch-permissions.spec.ts`:

```ts
test.describe('W-LOT-A-1a pre-activation web gating', () => {
  test('serves the fingerprint and gates routes and actions while API enforcement is off', ...)
})

test.describe('W-LOT-A-1a post-activation isolation', () => {
  test('uses selected location B2 and reports duplicate create as already_exists', ...)
})
```

First-failure and command table:

| File / exact `it` or `test` | First failing assertion | Exact command | Lane |
|---|---|---|---|
| `BatchRoutePermissions.test.tsx` — `denies list and detail with inventory.view but without batches.view` | `expect(screen.queryByText(/product batches/i)).not.toBeInTheDocument()` | `cd apps/web && pnpm vitest run src/routes/__tests__/BatchRoutePermissions.test.tsx -t 'denies list and detail with inventory.view but without batches.view'` | Vitest |
| same — `allows list and detail with batches.view` | `expect(screen.getByText(/product batches/i)).toBeInTheDocument()` | same file with `-t 'allows list and detail with batches.view'` | Vitest |
| same — `denies create with batches.view alone` | `expect(screen.queryByText(/create batch/i)).not.toBeInTheDocument()` | same file with `-t 'denies create with batches.view alone'` | Vitest |
| same — `allows create with batches.create` | `expect(screen.getByText(/create batch/i)).toBeInTheDocument()` | same file with `-t 'allows create with batches.create'` | Vitest |
| same — `denies edit with batches.view alone` | `expect(screen.queryByText(/edit batch/i)).not.toBeInTheDocument()` | same file with `-t 'denies edit with batches.view alone'` | Vitest |
| same — `allows edit with batches.update` | `expect(screen.getByText(/edit batch/i)).toBeInTheDocument()` | same file with `-t 'allows edit with batches.update'` | Vitest |
| same — `denies every batch route when BatchExpiry is disabled` | `expect(screen.queryByText(/product batches/i)).not.toBeInTheDocument()` | same file with `-t 'denies every batch route when BatchExpiry is disabled'` | Vitest |
| `BatchPermissions.test.tsx` — `hides the populated-list header create link without batches.create` | `expect(screen.queryAllByRole('link', { name: /add batch/i })).toHaveLength(0)` | `cd apps/web && pnpm vitest run src/features/batches/pages/__tests__/BatchPermissions.test.tsx -t 'hides the populated-list header create link without batches.create'` | Vitest |
| same — `hides the empty-state create link without batches.create` | `expect(screen.queryAllByRole('link', { name: /add batch/i })).toHaveLength(0)` | same file with the exact title | Vitest |
| same — `shows both create affordances only with batches.create` | `expect(screen.getByRole('link', { name: /add batch/i })).toBeInTheDocument()` in each render mode | same file with the exact title | Vitest |
| same — `hides edit without batches.update` | `expect(screen.queryByRole('link', { name: /edit/i })).not.toBeInTheDocument()` | same file with the exact title | Vitest |
| same — `hides delete without batches.delete` | `expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument()` | same file with the exact title | Vitest |
| same — `hides recall from the revised manager payload` | `expect(screen.queryByRole('button', { name: /recall/i })).not.toBeInTheDocument()` | same file with the exact title | Vitest |
| same — `shows recall to an eligible general manager with batches.recall` | `expect(screen.getByRole('button', { name: /recall/i })).toBeInTheDocument()` | same file with the exact title | Vitest |
| same — `retains state-based action suppression when permission exists` | `expect(screen.queryByRole('button', { name: /recall/i })).not.toBeInTheDocument()` | same file with the exact title | Vitest |
| `BatchSeededPermissionMap.test.ts` — exact matrix title | `expect(PERMISSIONS['batches.view']).toEqual(['admin', 'cashier', 'general_manager', 'manager', 'operator', 'viewer'])` | `cd apps/web && pnpm vitest run src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts` | Vitest |
| `Sidebar.test.tsx` — each named batch-navigation test | Its expected absent/present batch link assertion | `cd apps/web && pnpm vitest run src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx -t '<exact title>'` | Vitest |
| `usePermissions.moduleAccess.test.tsx` — each named server-authoritative test | Its `canAccessModule`/`hasPermission` boolean assertion | `cd apps/web && pnpm vitest run src/hooks/__tests__/usePermissions.moduleAccess.test.tsx -t '<exact title>'` | Vitest |
| Playwright pre-activation exact test | `await expect(page.getByRole('link', { name: /add batch/i })).toHaveCount(0)` | `cd apps/web && pnpm exec playwright test e2e/batch-permissions.spec.ts --project=chromium --grep 'pre-activation web gating'` | Playwright |
| Playwright post-activation exact test | `expect(duplicateResponse.meta.outcome).toBe('already_exists')` and exact B2 response assertions | `cd apps/web && pnpm exec playwright test e2e/batch-permissions.spec.ts --project=chromium --grep 'post-activation isolation'` | Playwright |

### 9.4 Browser split

<a id="r2-b3"></a>
**R2-B3:** while the backend flag remains false, run only the pre-activation Playwright test. It verifies the served fingerprint, direct-route web denial, sidebar gating, both create affordances, and detail actions. It does not expect `already_exists` or location-scoped API responses.

The post-activation test runs only after worker → API → scheduler activation and API read-back `ON`. It:

1. Registers company B.
2. Creates B1 and a second `pos_enabled` B2.
3. Persists the test viewer’s selected scope as `allowed_location_ids=[B2]`.
4. Places identifiable B2 stock/history and proves it is visible.
5. Proves B1 and company-A identifiers are absent.
6. Repeats company-B lot creation.
7. Asserts 422, `meta.outcome=already_exists`, one lot row, and unchanged stock/history/GL state.
8. Confirms the viewer can list but cannot create/edit/delete/recall.
9. Confirms unrestricted general manager can see recall.
10. Confirms no request-recall or hold surface exists.

### 9.5 Static checks, reviewers, and rollback

<a id="r2-m10"></a>
**R2-M10:** `apps/web/package.json` defines `typecheck` at [package.json:21](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/package.json:21) and `typecheck:e2e` at [package.json:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/package.json:22). Run:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/web
pnpm typecheck
pnpm typecheck:e2e
pnpm lint
```

Regenerate twice and require byte identity:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
php artisan permissions:export-frontend-map
cp ../web/src/hooks/permissionsMap.generated.ts /tmp/wlota1a-permissions-map-first.ts
php artisan permissions:export-frontend-map
diff /tmp/wlota1a-permissions-map-first.ts ../web/src/hooks/permissionsMap.generated.ts
```

Task 6 requires ACCEPT from `frontend-conventions-reviewer`, `tenancy-authz-reviewer`, and `inventory-costing-reviewer`. Reviewers must cover API/web name parity, direct routes, navigation, both create links, action guards, server authority, map provenance, fingerprint retention, selected B2 isolation, unchanged inventory/GL evidence, and absence of hold UI.

Do not hand-edit the generated map. After activation prefer forward correction; backend emergency rollback is flag-off, never restoring manager recall to compensate for a web defect.

## 10. Complete source-push ledger

Each source file appears exactly once.

### Push 1 — operations only

No source files.

### Push 2 — additive schema

- `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php`
- `apps/api/tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php`

### Push 3 — dormant backend runtime and verification

All Task-1 API files and tests, plus:

- `apps/api/app/Modules/Identity/Domain/Enums/SystemRoleName.php`
- `apps/api/app/Modules/Identity/Domain/Enums/RoleProvisioningSource.php`
- `apps/api/app/Modules/Identity/Domain/Enums/LotActionPermissionDeltaOutcome.php`
- `apps/api/app/Modules/Identity/Application/DTOs/LotActionPermissionDeltaResult.php`
- `apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php`
- `apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php`
- `apps/api/app/Console/Commands/ApplyLotActionPermissionDelta.php`
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`
- `apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php`
- `docs/glossary.md`
- Every Task-2 test except the Push-2 schema test.

Push 3 is accepted only with the flag false and a successful legacy-seeder inertness test.

### Push 4 — operations only

No source files. Apply/verify the explicit delta, reset permissions, perform recensus, and run all censuses. Do not run the generic seeder while enforcement is false.

### Push 5 — web bundle before activation

All Task-6 modified and added files, including the generated map and split Playwright specification.

No runtime file appears in two pushes. No Task-1/2/6 runtime file is absent.

## 11. Deployment variables and fail-closed fleet protocol

> Deployment follows `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`
> (five-push sequence §2, web block §3, gate checklist §4). This slice supplies only the variables
> below; it does not restate deploy mechanics.

| Variable | W-LOT-A-1a value |
|---|---|
| `<slice>` | `wlota1a-permissions-roles-web` |
| Migrations list | `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php` — additive, self-guarding |
| Flags | `lot_action_permissions.enforce` / `LOT_ACTION_PERMISSIONS_ENFORCE` / `apps/api/config/lot_action_permissions.php` / `false` |
| Commands | `permissions:apply-lot-action-delta`; existing `permission:cache-reset`; existing `permissions:export-frontend-map` |
| Censuses | Day-one, lot drift, phantom DEFAULT dry-run; every tenant and summary explicitly gated |
| Web changes | yes — `wlota1a-batch-permission-gating-v1` |
| Device build | no |
| Queues | none |
| Collapsed pushes | none |
| Env path | Dokploy Environment tab |

Do not modify `docker-compose.staging.yml`.

### 11.1 Capture the immutable expected tenant set

<a id="r2-b5"></a>
**R2-B5:** immediately before each fleet phase, capture the directory and create a normalized manifest:

```bash
set -o pipefail
php artisan tenants:list 2>&1 | tee /tmp/wlota1a-permissions-roles-web-tenants.log

grep -oE '[0-9a-fA-F-]{36}' \
  /tmp/wlota1a-permissions-roles-web-tenants.log \
  | tr 'A-F' 'a-f' \
  | sort -u \
  > /tmp/wlota1a-permissions-roles-web-expected-tenants.txt

test -s /tmp/wlota1a-permissions-roles-web-expected-tenants.txt

TENANT_IDS="$(
  tr '\n' ',' < /tmp/wlota1a-permissions-roles-web-expected-tenants.txt |
  sed 's/,$//'
)"
test -n "$TENANT_IDS"
export TENANT_IDS
echo "TENANT_IDS=$TENANT_IDS"
```

The expected manifest is copied outside the container before continuing. A fleet gate must:

- Reject an empty manifest.
- Reject `FAILED`, `SKIPPED`, `ERRORED`, missing-database, or unaccounted output.
- Extract only its exact marker prefix.
- Reject malformed UUIDs.
- Reject duplicate tenant markers.
- Reject unexpected tenant UUIDs.
- Reject missing expected UUIDs.
- Require one and only one success marker for every expected UUID.

For delta logs, extract the tenant field from exact `WLOTA1A-PERMISSIONS` lines, compare `sort -u` output with the expected manifest using `comm`, and separately require raw marker count equals unique marker count equals expected count. Only `APPLIED`/`ALREADY_APPLIED` are success in apply mode; verify permits only `ALREADY_APPLIED`.

### 11.2 Push 1

Capture the tenant manifest first. Run day-one one expected tenant at a time:

```bash
: > /tmp/wlota1a-permissions-roles-web-census-p1.log

while IFS= read -r tenant_id; do
  tenant_log="/tmp/wlota1a-census-p1-${tenant_id}.log"

  php artisan tenants:run tenant:census-day-one \
    --tenants="$tenant_id" \
    --option='fail-on-drift=1' \
    2>&1 | tee "$tenant_log"

  test "$(grep -c "^Tenant: ${tenant_id}$" "$tenant_log")" -eq 1
  test "$(grep -c "^DAY-ONE CENSUS ${tenant_id} .*: CLEAN$" "$tenant_log")" -ge 1
  test "$(grep -Ec 'DRIFT\\(|NO-COMPANY|FAILED|SKIPPED|ERRORED' "$tenant_log")" -eq 0

  echo "WLOTA1A-CENSUS-P1 tenant=${tenant_id} outcome=PASS" \
    | tee -a /tmp/wlota1a-permissions-roles-web-census-p1.log
done < /tmp/wlota1a-permissions-roles-web-expected-tenants.txt
```

Apply the exact-once marker comparison to `WLOTA1A-CENSUS-P1`.

Verify `SYNC_PERMISSIONS_ON_BOOT` is `false`, `0`, or `UNSET`; any truthy value blocks Push 2.

### 11.3 Push 2

Take and verify a non-zero host-side backup. Deploy only the migration/test and run:

```bash
php artisan tenants:migrate-rolling --force
```

Then rerun for one captured tenant. Verify exact schema, no marked roles, idempotent rerun, and no role/permission changes. Rollback is forward-only.

### 11.4 Push 3

Set `LOT_ACTION_PERMISSIONS_ENFORCE=false` in worker, API, and scheduler environments. Redeploy/read back `OFF` in all three.

Prove:

- Pre-activation authorization and payload fixtures remain unchanged.
- Restricted reads retain legacy semantics.
- Duplicate response retains its old payload.
- Generic seeding on a fresh test tenant uses the legacy role behavior.
- No `general_manager` is created.
- Manager retains legacy recall.
- Viewer/operator are not changed by the generic flag-off seed.

### 11.5 Push 4 delta

Recapture the current non-empty tenant manifest. Run one tenant at a time so each child result can be parsed despite `tenants:run` discarding child exit status:

```bash
: > /tmp/wlota1a-permissions-roles-web-delta.log

while IFS= read -r tenant_id; do
  tenant_log="/tmp/wlota1a-delta-${tenant_id}.log"

  php artisan tenants:run permissions:apply-lot-action-delta \
    --tenants="$tenant_id" \
    --option='apply=1' \
    2>&1 | tee "$tenant_log"

  test "$(grep -c "^Tenant: ${tenant_id}$" "$tenant_log")" -eq 1
  test "$(grep -Ec "^WLOTA1A-PERMISSIONS tenant=${tenant_id} mode=APPLY outcome=(APPLIED|ALREADY_APPLIED) reason=[a-z0-9_]+$" "$tenant_log")" -eq 1
  test "$(grep -Ec 'outcome=(FAILED|SKIPPED)|ERRORED|missing database' "$tenant_log")" -eq 0

  grep "^WLOTA1A-PERMISSIONS tenant=${tenant_id} " "$tenant_log" \
    >> /tmp/wlota1a-permissions-roles-web-delta.log
done < /tmp/wlota1a-permissions-roles-web-expected-tenants.txt
```

Run the exact-once/missing/duplicate/unexpected comparison, reset permission cache, then repeat with `--verify` and require exactly one `mode=VERIFY outcome=ALREADY_APPLIED` marker per expected UUID.

Do not run `tenants:seed` here.

### 11.6 Census summary gates

Run lot drift and phantom dry-run directly per tenant so their exit statuses are observable. For every tenant:

- Lot drift must exit 0.
- `Tuples drifted: 0` occurs exactly once.
- `Net drift:` and `Absolute drift:` parse numerically as zero.
- No `DRIFT`, `FAILED`, `SKIPPED`, or `ERRORED` token appears.
- Emit exactly one `WLOTA1A-LOT-CENSUS tenant=<uuid> outcome=PASS`.

For phantom dry-run:

- Command exits 0.
- `Phantom DEFAULT lots: 0` occurs exactly once.
- `Total phantom quantity:` parses as zero.
- `Reservations re-pointed to real lots: 0`.
- `Reservations left on the DEFAULT lot: 0`.
- `Lots only partially reduced: 0`.
- `Lots skipped (excess changed under the lock): 0`.
- `Tuples still drifted: 0`.
- Reject every `SKIPPED`, `FAILED`, or `ERRORED` line.
- Emit exactly one `WLOTA1A-PHANTOM-CENSUS tenant=<uuid> outcome=PASS`.

Run day-one exactly as Push 1 and emit `WLOTA1A-CENSUS-P4`. Apply exact-once manifest comparison to all three marker logs. Compare Push-1/Push-4 census artifacts; only the permission catalogue/role delta may differ. Catalogue entities, quantities, balances, lot drift, and phantom findings may not.

### 11.7 Rollout-window recensus

Immediately before activation:

1. Pause new registration.
2. Recapture the non-empty tenant manifest.
3. Diff it against the Push-4 manifest.
4. Apply the delta to every newly observed UUID.
5. Verify the full current fleet again, using exact-once markers.
6. Re-run and gate day-one, lot, and phantom censuses for newly observed UUIDs.
7. Keep registration paused through worker → API → scheduler activation.
8. Recapture the manifest after API activation.
9. Require no unexpected tenant; if any exists, delta and verify it before registration resumes.
10. Resume registration only after a disposable new registration proves activated fresh provisioning emits `mode=ACTIVATED` and receives the canonical matrix.

### 11.8 Push 5 and activation

Before deployment:

- Regenerate the permission map twice.
- Run focused API/web suites.
- Run `pnpm typecheck`, `pnpm typecheck:e2e`, and `pnpm lint`.
- Keep the backend flag false.
- Deploy web and require changed served asset hash.
- Require `wlota1a-batch-permission-gating-v1` in the served bundle.
- Run only the pre-activation Playwright test.

Activate in this exact order:

1. Worker: set true, redeploy, read back `ON`.
2. API: set true, redeploy, read back `ON`.
3. Scheduler: set true, redeploy, read back `ON`.

Then:

```bash
php artisan config:clear
php artisan config:cache
php artisan permission:cache-reset
php artisan horizon:terminate
php artisan horizon:status
```

Only now run the post-activation duplicate/B2/location Playwright test and API authorization smoke.

## 12. Manifest §4 gate checklist — copied verbatim

- [ ] **Onboarding campaign GREEN** — `scripts/campaign-onboarding.sh` (local) or
      `scripts/campaign-onboarding.sh --web https://erp.otospex.dev --api https://api.erp.otospex.dev --country TN`
      (`docs/qa/ONBOARDING-CAMPAIGN.md:5-22`; flags `scripts/campaign-onboarding.sh:9-29`).
      Promotion reads the **ledger**, not the exit code (`ONBOARDING-CAMPAIGN.md:3`); the target
      must run a worker consuming `imports` + `fiscal-projections` (`:49`); registration is
      throttled and every run leaves a tenant behind (`:50,53`).
- [ ] **Day-one census CLEAN** — `php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1'`,
      every verdict line clean; grep `DAY-ONE CENSUS` / `DRIFT(` because `tenants:run` discards exit
      codes (`docs/handoff/RUNBOOK-day-one-census.md:7-9,25`). CLAUDE.md rule 22.
- [ ] **Promotion-checklist rows** — migrations enumerated, each declared self-guarding
      (`PROMOTION-CHECKLIST-2026-08-26.md:22-67`); non-self-running seeders listed (`:69-74`);
      post-deploy censuses run (`:84-90`); Horizon queue coverage confirmed (`:94`).
- [ ] **Preflight green at host scope** — `PREFLIGHT_TEST_PATHS='…' ./scripts/preflight.sh` on the
      laptop; full suite is VPS/CI only (`WORKFLOW.md:36,147-162`). A `paths` run with **no** paths
      skips PHPUnit and is not a green.
- [ ] **dev-push-guard behaviour understood** — force-push to `dev` denied; a behind/diverged local
      `dev` denied with the exact reconcile command (`.claude/hooks/git-dev-push-guard.sh:73-88`).
      Commit and push are separate Bash calls.
- [ ] **Fast-forward-only promotion** — `git log --oneline dev..origin/dev | wc -l` is `0` before
      promoting; never rewrite shared history (`PROMOTION-CHECKLIST-2026-08-26.md:15-20`; CLAUDE.md rule 21).
- [ ] **Backup taken on the host and verified non-zero** before any migrating/backfilling push (row I).

## 13. Verification commands

Task 1:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/BatchExpiry/BatchActionPermissionsTest.php \
  tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php \
  tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php \
  tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php

./vendor/bin/phpunit -c phpunit.xml \
  tests/Architecture/BatchTraceabilityModuleBoundaryTest.php
```

Task 2:

```bash
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/Identity/LotActionSeededRoleMatrixTest.php \
  tests/Feature/Identity/LotActionPermissionDeltaTest.php \
  tests/Feature/Identity/GeneralManagerAssignmentTest.php \
  tests/Feature/Identity/GeneralManagerRoleProtectionTest.php \
  tests/Feature/Identity/GeneralManagerAssignmentWriterCensusTest.php \
  tests/Feature/Console/LotActionReseedMarkerTest.php \
  tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php \
  tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php \
  tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php
```

Task 6:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/web
pnpm vitest run \
  src/routes/__tests__/BatchRoutePermissions.test.tsx \
  src/features/batches/pages/__tests__/BatchPermissions.test.tsx \
  src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts \
  src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx \
  src/hooks/__tests__/usePermissions.moduleAccess.test.tsx

pnpm typecheck
pnpm typecheck:e2e
pnpm lint
```

Pre-activation:

```bash
pnpm exec playwright test e2e/batch-permissions.spec.ts \
  --project=chromium \
  --grep 'pre-activation web gating'
```

Post-activation only:

```bash
pnpm exec playwright test e2e/batch-permissions.spec.ts \
  --project=chromium \
  --grep 'post-activation isolation'
```

Run the repository’s complete API PostgreSQL lane and web E2E lane after focused tests.

## 14. Combined reviewer gate and rollback

After Tasks 1 and 2 are green, dispatch:

- `tenancy-authz-reviewer`
- `inventory-costing-reviewer`

After Task 6/map regeneration is green, dispatch:

- `frontend-conventions-reviewer`
- `tenancy-authz-reviewer`
- `inventory-costing-reviewer`

Then run all three against the combined diff. Required result:

```text
ACCEPT
BLOCKER=0
MAJOR=0
```

The inventory reviewer must explicitly cite:

- Four-decimal scoped totals.
- Filtered eager-load totals.
- Unchanged batch stock.
- Unchanged reservations.
- Unchanged Document/POS history.
- Unchanged journal entries and lines.
- No eligibility or hold behavior.

Emergency rollback:

1. Set `LOT_ACTION_PERMISSIONS_ENFORCE=false`.
2. Redeploy worker.
3. Redeploy API.
4. Redeploy scheduler.
5. Rebuild configuration cache.
6. Reset permission cache.
7. Restart Horizon.
8. Leave the gated web bundle deployed.
9. Preserve the schema and marked role.
10. Record temporary legacy API exposure.

Never delete/rename/unmark `general_manager`, roll back the marker migration over a marked row, restore manager recall through direct SQL, or hand-edit Spatie pivots.

## 15. W-LOT-A-1b boundary

A-1b begins only after this slice is accepted and activated. It owns:

- Recall request and hold schema.
- Requested/recalled/released/rejected lifecycle.
- Append-only request/transition/global-recall evidence.
- Operation UUID, replay, and concurrency.
- POS and transfer eligibility.
- Company-wide recall service.
- History/capability endpoints.
- Request/history/release/reject UI.
- The Q10 release/reject authority contract.

A-1a supplies only the prerequisite permission split, unrestricted general-manager invariant, safe batch reads, and trace contracts.

## 16. Dispatch order

1. Record actual `DISPATCH_SHA`; run the repin procedure on drift.
2. Preserve unrelated untracked files.
3. Capture the non-empty Push-1 tenant manifest.
4. Run exact-once Push-1 day-one census gates.
5. Prove boot permission synchronization is off.
6. Add Task-1 red tests and capture failures.
7. Implement Task 1 behind the false flag.
8. Run Task-1 tests and both reviewers.
9. Add Task-2 red tests and capture failures.
10. Implement/test the additive migration.
11. Implement legacy-off seeder behavior, canonical delta, team boundaries, and assignment transactions.
12. Run Task-2 PostgreSQL concurrency, command, team, and SQLite schema tests.
13. Obtain preliminary reviewer ACCEPT.
14. Promote Push 2 and verify exact schema.
15. Promote Push 3 with the flag false in worker/API/scheduler.
16. Prove generic fresh seeding remains legacy and Push 3 is inert.
17. Recapture the Push-4 tenant manifest.
18. Apply the explicit delta once per expected tenant.
19. Enforce exact-once marker gates.
20. Reset permission cache.
21. Verify once per expected tenant.
22. Run and gate every Push-4 census summary.
23. Add Task-6 red tests with exact symbols.
24. Implement routes, navigation, both create links, detail actions, and server-authoritative permissions.
25. Regenerate the map twice and require byte identity.
26. Run Vitest, real typecheck scripts, lint, and reviewers.
27. Deploy Push 5 with the backend flag false.
28. Verify changed served asset and fingerprint.
29. Run only the pre-activation browser test.
30. Pause registration.
31. Recapture tenants; delta and verify rollout-window additions.
32. Activate worker.
33. Activate API.
34. Activate scheduler.
35. Clear caches and restart Horizon.
36. Recapture tenants and close any unexpected rollout-window gap.
37. Prove activated fresh provisioning.
38. Resume registration.
39. Run post-activation API and Playwright duplicate/B2/location gates.
40. Archive SHA, manifests, markers, censuses, snapshots, asset evidence, and reviewer verdicts.
41. Dispatch W-LOT-A-1b separately.

## 17. Final verification checklist

- [ ] Planning SHA `3b326a71f700c885d471461a7e6069fb59d0ac43` is recorded.
- [ ] Actual `DISPATCH_SHA` is recorded and all named seams were re-censused after drift.
- [ ] Only Tasks 1, 2, and 6 were implemented.
- [ ] RD2, Q4, and Q10 remain quoted verbatim.
- [ ] No A-1b schema, behavior, permission, route, or UI leaked into A-1a.
- [ ] Every changed constructor uses the correct FQCN and `private readonly` promotion.
- [ ] NEW command constructors call `parent::__construct()`.
- [ ] Batch write-off services use `Domain\Services`.
- [ ] Identity index/signer use `Modules\Tenant\Application\Services`.
- [ ] Company config uses `App\Services\CompanyConfigService`.
- [ ] The route matrix is exact and unknown middleware arguments fail closed.
- [ ] Push 3 is inert for existing and newly registered tenants.
- [ ] Generic flag-off seeding preserves the exact legacy role behavior.
- [ ] Existing-tenant role changes occur only through the Push-4 delta.
- [ ] Activated fresh provisioning uses the canonical new matrix.
- [ ] Rollout-window tenants were re-censused and re-delta’d.
- [ ] Empty scope never becomes unrestricted.
- [ ] Restricted reads expose only selected-location stock/history.
- [ ] Unrestricted reads retain zero-stock and null-location history.
- [ ] Selected B2 is persisted as `allowed_location_ids=[B2]`.
- [ ] Batch totals are scoped four-decimal strings with no float conversion.
- [ ] Trace DTOs preserve every current response field and envelope.
- [ ] BatchExpiry imports no Document/POS persistence model.
- [ ] Document and POS adapters are bound in their owning providers.
- [ ] Denied recall/delete assertions compare stock, reservation, history, and GL snapshots.
- [ ] Manager loses global recall and gains request permission.
- [ ] Viewer and operator gain batch view only through the canonical delta.
- [ ] Marked general manager has recall and all-location Treasury authority.
- [ ] Unmarked `general_manager` collision fails closed.
- [ ] Role delta lock/read/write/verify is one transaction.
- [ ] Delta captures, sets, and restores Spatie team context.
- [ ] Every assignment writer uses the same transaction/team boundary.
- [ ] Barrier-controlled assignment/narrowing race is green.
- [ ] Marked role cannot be renamed or deleted.
- [ ] Rerun preserves role ID, custom grants, and pivot cardinality.
- [ ] CLI rejects neither/both options with exit 2.
- [ ] Collision/schema failures emit `FAILED` and exit 1.
- [ ] Every fleet expected tenant set is non-empty.
- [ ] Every expected UUID has exactly one success marker.
- [ ] No missing, duplicate, unexpected, `FAILED`, `SKIPPED`, or `ERRORED` marker passed.
- [ ] Every day-one, lot-drift, and phantom summary is explicitly gated.
- [ ] Manifest §4 appears verbatim.
- [ ] Both existing create affordances use one `canCreate`.
- [ ] Every Task-6 exact Vitest/Playwright symbol exists.
- [ ] Generated map matches the canonical seeder helpers.
- [ ] Second map generation is byte-identical.
- [ ] `pnpm typecheck` and `pnpm typecheck:e2e` pass.
- [ ] Pre-activation browser evidence contains no flag-on API expectation.
- [ ] Post-activation browser evidence runs only after API activation.
- [ ] Served asset hash changed and fingerprint is present.
- [ ] Activation order was worker → API → scheduler.
- [ ] `tenancy-authz-reviewer` returned ACCEPT.
- [ ] `inventory-costing-reviewer` returned ACCEPT.
- [ ] `frontend-conventions-reviewer` returned ACCEPT.
- [ ] Reviewer BLOCKER and MAJOR counts are zero.
- [ ] Host backup is non-zero.
- [ ] No device build or queue was added.
- [ ] No unrelated untracked file was staged.
- [ ] W-LOT-A-1b remains a separate dispatch.