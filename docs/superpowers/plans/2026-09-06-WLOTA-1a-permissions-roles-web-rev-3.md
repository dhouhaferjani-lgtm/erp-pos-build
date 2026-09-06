<!-- W-LOT-A-1a rev 3, Codex CLI fix round 2 (gpt-5.6-sol, read-only) 2026-09-06, saved verbatim by the orchestrator. Rev 2 = 55bf3d142. Status: awaiting gate r3. -->
<!-- W-LOT-A-1a rev 3, Codex fix round 2, read-only, 2026-09-06, saved verbatim by the orchestrator. Rev 2 is the verbatim base. Status: awaiting gate r3. -->
<!-- W-LOT-A-1a rev 3; Tasks 1/2/6 only. Prepared read-only at f7647264caeacd8e6bbb9ee513d41b8d7feca238 on 2026-09-06. -->
# Slice plan W-LOT-A-1a — lot action permissions, general-manager role delta, web gating (rev 3)

## 0. Round-2 change log

The governing review is [gate r2](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r2.md:1). Every finding is closed below.

| Finding | Disposition and closing plan line |
|---|---|
| B-A — Q10 assigned to the wrong slice | CLOSED — “W-LOT-A-1b implements only request/hold and `requested → recalled`; W-LOT-A-1c exclusively implements release/reject transitions, GM authority, mandatory reasons, append-only evidence, routes, and UI.” See [`R3-B-A`](#r3-b-a) and §15. |
| B-B — deployment gates fail open | CLOSED — “No deployment phase advances unless its executable gate exits zero, its captured tenant manifest is non-empty, and each expected UUID has exactly one valid marker with no failed, skipped, missing, duplicate, or unexpected marker.” See [`R3-B-B`](#r3-b-b) and §11. |
| M-A — middleware outside API contract | CLOSED — “`BatchActionAccess` is attached only to reads, traceability, delete, and recall; create, update, transfer, and write-off retain their existing authorization unchanged.” See [`R3-M-A`](#r3-m-a), §6.4, §7.2, and §7.6. |
| M-B — marked global system role allowed | CLOSED — “A non-null `provisioning_source` requires the configured team column to be non-null, and the team-scoped partial unique index permits one marker per tenant.” See [`R3-M-B`](#r3-m-b) and §8.9. |
| N-A — glossary names two canonical surfaces | CLOSED — “`LotActionPermissionDelta` is the sole role-definition writer and Settings → Users is the sole assignment surface; Settings → Roles is read-only inspection.” See [`R3-N-A`](#r3-n-a). |

No round-2 item is rejected. The gate’s rejected false positives remain rejected: A-1a still contains no hold, eligibility, release/reject, POS refusal, transfer refusal, company-recall replacement, history, or hold UI implementation.

## 0A. Round-1 change log

The governing review is [gate r1](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r1.md:1). Every finding is closed by a named plan line below.

| Finding | Disposition and closing plan line |
|---|---|
| B1 — constructors | CLOSED — all changed constructors now reproduce correct FQCNs and promoted `private readonly` properties; NEW command constructors call `parent::__construct()`. See [`R2-B1`](#r2-b1) and the named-symbol census. |
| B2 — Push 3 seeder not inert | CLOSED — the generic seeder uses the legacy catalogue and role matrix while enforcement is off; only the explicit Push-4 delta changes existing tenants. Activated fresh provisioning uses the new matrix. Rollout-window tenants are re-censused and re-delta’d. See [`R2-B2`](#r2-b2). |
| B3 — premature browser gate | CLOSED — browser evidence is split into pre-activation web-only gating/fingerprint checks and post-activation duplicate/location checks. See [`R2-B3`](#r2-b3). |
| B4 — impossible SHA equality | CLOSED — this plan declares the SHA read, but dispatch records and pins its actual HEAD; drift triggers a symbol/citation recensus rather than an impossible equality with a pre-plan commit. See [`R2-B4`](#r2-b4). |
| B5 — fail-open fleet/census gates | CLOSED — every fleet phase captures a non-empty expected tenant set, validates exact-once success markers, rejects missing/duplicate/unexpected/`FAILED`/`SKIPPED` markers, and gates every census summary. The manifest checklist is copied verbatim and activation is worker → API → scheduler. See [`R3-B-B`](#r3-b-b). |
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
- Planning HEAD read in full: `f7647264caeacd8e6bbb9ee513d41b8d7feca238`.
- Branch state: local `dev`.
- Commit: `docs(parapharmacy): slice plan W-CASH-1 rev 14 (rev 10 base + Amendment A/B + architecture-safe scale factory + single dispatch order); awaiting gate r14`.
- No repository files were changed and no tests were run while preparing this plan.
- All local `path:line` citations resolve against `f7647264caeacd8e6bbb9ee513d41b8d7feca238`.
- Production code did not change between the round-2 gate audit and this planning HEAD; intervening changes were documentation-only.
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

- Every uncovered BatchExpiry read, traceability, deactivate, and recall HTTP action has its intended permission boundary.
- Existing create, update, transfer, and write-off authorization remains unchanged.
- Batch list, detail, stock, expiry, product-stock, POS suggestion, and trace reads honor company and membership-location scope.
- `null` membership scope remains unrestricted; `[]` remains fail-closed.
- Unrestricted users retain company lot metadata, including zero-stock lots and null-location history.
- Restricted users see only current or historical lots attributable to allowed locations.
- `BatchExpiry` imports no Document or POS persistence models for trace queries.
- Batch totals are four-decimal strings computed only from the scoped eager-loaded relation.
- `manager` loses `batches.recall` and gains dormant `batches.recall.request`.
- A marked seeded `general_manager` receives the revised manager set plus `batches.recall` and `treasury.manage_all_locations`.
- `general_manager` may be assigned only when every active company membership is unrestricted.
- The marked role cannot be global, renamed, duplicated within a tenant team, or deleted.
- The web uses the same exact permission names as the API.
- Push 3 is behaviorally inert while `LOT_ACTION_PERMISSIONS_ENFORCE=false`.

Explicitly deferred:

W-LOT-A-1b implements only:

- Recall-request and branch-hold tables.
- Request/hold creation.
- The `requested → recalled` transition.
- Local branch holds.
- Recall escalation, replay, operation UUIDs, and append-only request/recalled evidence.
- Sale, transfer, delivery, write-off, stock-count, and return eligibility changes required by the hold.
- Company-wide recall-service replacement.
- Request/history/capability routes and request/history UI.
- The existing web recall payload defect.

Its initial schema must accept all four forward-compatible states—`requested`, `recalled`, `released`, and `rejected`—but A-1b must expose and implement no release/reject transition, authority, route, or UI.

W-LOT-A-1c exclusively implements:

- `requested → released` and `requested → rejected`.
- General-manager-only release/reject authority.
- Mandatory release/reject reasons.
- Append-only release/reject evidence.
- Release/reject routes and UI.
- Enforcement that the requesting branch never lifts its own hold.

A-1a and A-1b must not implement those A-1c behaviors.

This slice must not:

- Change `Batch::recall()` behavior.
- Fix the existing web recall payload key.
- Change quantity, reservation, valuation, stock movement, or GL posting behavior.
- Add a handwritten web trace DTO.
- Add a recall-request button.
- Add release or reject permissions.
- Add route middleware to create, update, transfer, or single-lot write-off.

The existing web payload remains `{ recall_reason: ... }` at [BatchDetailPage.tsx:49](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:49); W-LOT-A-1b owns that defect.

## 3. Owner rulings

### 3.1 RD2 — verbatim

> A manager is branch-linked, or is a **general manager** (explicit company-wide role). Recall is a safety action: a branch manager **initiates** a recall for a lot present in their branch; the recall **escalates** to the general manager, who executes it company-wide. Permissions stay tight; escalation, not denial.

Source: [OWNER-RULINGS:9](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:9).

A-1a implements only the permission and role split. A-1b implements request/hold and `requested → recalled`.

### 3.2 Q4 — verbatim

> CONFIRMED: new seeded `general_manager` role (manager set, no location restriction, `batches.recall`, `treasury.manage_all_locations`).

Source: [OWNER-RULINGS:113](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:113).

### 3.3 Q10 — verbatim

> Hold lifecycle `requested → recalled` or `requested → released`; only the general manager may release or reject; mandatory reason and append-only evidence; the requesting branch never lifts its own hold.

Source: [OWNER-RULINGS:151](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151).

The ruled process consequence is:

> Later slices (hold release/reject, drawer session, reason codes, alignment) may now implement the ruled branches.

Source: [OWNER-RULINGS:158](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:158).

<a id="r3-b-a"></a>
**R3-B-A:** slice ownership is exact:

| Slice | Q10 portion implemented |
|---|---|
| W-LOT-A-1a | No hold lifecycle or Q10 state schema; permission split and unrestricted GM prerequisite only |
| W-LOT-A-1b | Request/hold plus `requested → recalled` only |
| W-LOT-A-1c | Release/reject transitions, GM authority, mandatory reasons, append-only evidence, routes, and UI |

W-LOT-A-1b must declare NEW `App\Modules\BatchExpiry\Domain\Enums\BatchRecallRequestStatus`:

```php
enum BatchRecallRequestStatus: string
{
    case Requested = 'requested';
    case Recalled = 'recalled';
    case Released = 'released';
    case Rejected = 'rejected';
}
```

Binding: none; this is a backed domain enum.

A-1b’s initial `batch_recall_requests.status` enum/CHECK must accept exactly:

```text
requested
recalled
released
rejected
```

Accepting `released` and `rejected` in the initial schema is forward compatibility, not authorization to implement those transitions in A-1b. W-LOT-A-1c is their sole behavior owner.

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
| create | Creating a lot requires explicit action authority | Model operations are controlled through access rights | Document operations are role/permission controlled | NV — exact batch-create contract not verified | `CreateBatchRequest::authorize()` already requires `batches.create` at [CreateBatchRequest.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRequest.php:22) | None in the contracted API scope | ALREADY — preserve in Task 1 |
| duplicate | A repeated create has a meaningful, non-mutating outcome | NV — lot documentation does not establish retry response semantics | NV — Batch documentation does not establish retry response semantics | NV — not verified | Duplicate returns a generic 422 without an outcome at [BatchController.php:138](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:138) | No `already_exists` result | MATCH — Task 1 |
| edit | Lot metadata updates require explicit update authority | Model write access is group-controlled | Document updates are permission-controlled | NV — exact batch-edit contract not verified | `UpdateBatchRequest::authorize()` already requires `batches.update` at [UpdateBatchRequest.php:13](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:13) | None in the contracted API scope | ALREADY — preserve in Task 1 |
| cancel | Lot deactivation requires explicit delete/deactivate authority | Model write/unlink access is group-controlled | Delete/cancel operations are permission-controlled | NV — exact batch-deactivation contract not verified | DELETE has no action guard at [routes.php:26](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:26) | Body-less route bypasses FormRequest authorization | MATCH — Task 1 |
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
| **General manager** | A seeded tenant role containing the revised manager grants plus company-wide lot recall and all-location Treasury authority; every active company membership held by the assignee must be unrestricted. | Existing Spatie `roles`, marked by `roles.provisioning_source` / Identity | Settings → Users (assignment) | central manager |

<a id="r3-n-a"></a>
**R3-N-A:** NEW `App\Modules\Identity\Application\Services\LotActionPermissionDelta` is the sole role-definition writer. Settings → Users is the sole operator assignment surface. Settings → Roles may remain visible only as read-only inspection for the protected seeded role; it is not a second canonical editor. The code identifier `general_manager` is not a UI synonym.

Do not add Recall request, Branch hold, Recall transition, or Global recall evidence rows in A-1a.

Current operative evidence:

- The BatchExpiry route group already retains API authentication, Spatie team setup, tenant-claim enforcement, and module gating at [routes.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12).
- The uncovered read/delete/recall/trace guards are visible at [routes.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:14), [routes.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:22), [routes.php:26](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:26), [routes.php:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:29), and [routes.php:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:38).
- Create and update already authorize through [CreateBatchRequest.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRequest.php:22) and [UpdateBatchRequest.php:13](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:13).
- Grouped and reversed write-off routes already use `batches.write-off` at [routes.php:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:18) and [routes.php:34](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:34).
- Expiring reads an unvalidated location string at [BatchController.php:219](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:219); expired already validates UUIDs and resolves scope at [BatchController.php:241](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:241) and [BatchController.php:252](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:252).
- Stock is currently read across all locations at [BatchController.php:271](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:271).
- Batch totals currently use relation accessors that issue unscoped aggregate queries and cast to float at [Batch.php:143](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:143) and are serialized at [BatchResource.php:39](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:39).
- `LocationContext` returns `[]` for no active membership and `null` for unrestricted membership at [LocationContext.php:194](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationContext.php:194).
- `LocationScopeResolver` rejects requested IDs outside scope at [LocationScopeResolver.php:41](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationScopeResolver.php:41).
- Permission teams are enabled and tenant-keyed at [permission.php:127](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/permission.php:127); the configured team column is `tenant_id` at [permission.php:99](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/permission.php:99).
- HTTP requests set the team ID from the authenticated user’s tenant at [SetPermissionsTeam.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:22) and [SetPermissionsTeam.php:28](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:28).
- New registration invokes full tenant initialization at [TenantProvisioningService.php:222](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:222), which runs `RolesAndPermissionsSeeder` when roles are absent at [TenantInitializationService.php:199](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:199) and [TenantInitializationService.php:213](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:213).
- Role team IDs are nullable at [2025_11_29_231806_create_permission_tables.php:37](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:37), and existing name uniqueness includes the team column at [2025_11_29_231806_create_permission_tables.php:44](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:44).
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

<a id="r3-m-a"></a>
**R3-M-A:** the authoring contract limits new middleware enforcement to body-less recall/deactivate, traceability, and reads. The complete route matrix is:

| Existing surface | Permission after activation |
|---|---|
| GET `/api/v1/batches` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/batches/expiring` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/batches/expired` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/batches/{uuid}` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/batches/{uuid}/stock` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/products/{productId}/batch-stock` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/pos/products/{productId}/batches` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/batches/{uuid}/traceability` | NEW `BatchActionAccess:batches.traceability` |
| GET `/api/v1/partners/{partnerId}/batch-history` | NEW `BatchActionAccess:batches.traceability` |
| POST `/api/v1/batches` | Existing `CreateBatchRequest::authorize()` only; unchanged |
| PATCH `/api/v1/batches/{uuid}` | Existing `UpdateBatchRequest::authorize()` only; unchanged |
| DELETE `/api/v1/batches/{uuid}` | NEW `BatchActionAccess:batches.delete` |
| POST `/api/v1/batches/{uuid}/recall` | NEW `BatchActionAccess:batches.recall` |
| POST `/api/v1/batches/{uuid}/transfer` | Existing `TransferBatchStockRequest` authorization; unchanged |
| POST `/api/v1/batches/{uuid}/write-off` | Existing `WriteOffBatchRequest` authorization; unchanged |
| POST `/api/v1/batches/write-off-grouped` | Existing `can:batches.write-off`; unchanged |
| POST `/api/v1/stock-movements/{movementId}/reverse-write-off` | Existing `can:batches.write-off`; unchanged |

No `BatchActionAccess` middleware is added to create, update, transfer, single-lot write-off, grouped write-off, or reverse-write-off. No `batches.recall.request` route is added.

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
| `App\Services\CompanyConfigService` | Imported by [RoleController.php:16](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:16) |
| `App\Modules\Tenant\Application\Services\IdentityIndexService` | Imported by [UserController.php:23](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:23) |
| `App\Modules\Tenant\Application\Services\TenantLinkSigner` | Imported by [UserController.php:24](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:24) |
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
batches.delete
batches.recall
```

Unknown arguments return 403. Flag-off returns `$next($request)` before permission evaluation. `batches.create`, `batches.update`, and `batches.write-off` are deliberately not accepted by this middleware because their existing authorization is preserved.

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

    public function test_create_update_transfer_and_write_off_keep_existing_authorization_without_batch_action_middleware(): void;

    public function test_flag_off_preserves_pre_activation_access_and_payloads(): void;

    public function test_cashier_denied_recall_leaves_stock_history_reservations_and_gl_unchanged(): void;

    public function test_viewer_denied_delete_leaves_stock_history_reservations_and_gl_unchanged(): void;

    public function test_manager_denied_global_recall_leaves_stock_history_reservations_and_gl_unchanged(): void;
}
```

`test_each_existing_batch_route_requires_its_exact_action_permission()` tests the complete matrix in §6.4, including the existing authorization sources. It must not expect `BatchActionAccess` on create, update, transfer, or write-off.

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
| same | `BatchActionPermissionsTest::test_create_update_transfer_and_write_off_keep_existing_authorization_without_batch_action_middleware` | `self::assertSame($expectedMiddleware, $actualMiddleware);` with no `BatchActionAccess` on those routes | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchActionPermissionsTest::test_create_update_transfer_and_write_off_keep_existing_authorization_without_batch_action_middleware'` | PHPUnit PostgreSQL |
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

1. Add and capture the red-first assertions.
2. Add activation config and middleware.
3. Apply `BatchActionAccess` only to reads, traceability, delete, and recall.
4. Assert create, update, transfer, and write-off authorization remains unchanged.
5. Add the three complete trace DTOs and two interfaces.
6. Add and bind owning-module adapters.
7. Add response field-for-field compatibility tests.
8. Remove cross-module persistence imports.
9. Add repository scope parameters and filtered eager loads.
10. Thread scope through list/detail/stock/expiry/product/POS/trace reads.
11. Preserve unrestricted zero-stock and null-location history.
12. Replace float/unscoped totals.
13. Add the duplicate outcome behind the flag.
14. Run focused, architecture, denied-snapshot, and convention-09 tests.

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

Target: `roles`. Resolve the configured team column through `config('permission.column_names.team_foreign_key')`; at HEAD it is `tenant_id` at [permission.php:99](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/permission.php:99).

| Column | SQL type | Nullable | Default | FK | Index |
|---|---|---:|---|---|---|
| `provisioning_source` | `VARCHAR(32)` | yes | SQL `NULL` | none | partial unique `roles_provisioning_source_team_unique` with configured team column |

PostgreSQL constraint:

```sql
CONSTRAINT roles_provisioning_source_check
CHECK (
    provisioning_source IS NULL
    OR (
        tenant_id IS NOT NULL
        AND provisioning_source = 'w-lot-a-1a'
        AND name = 'general_manager'
        AND guard_name = 'sanctum'
    )
)
```

PostgreSQL and SQLite partial unique index:

```sql
CREATE UNIQUE INDEX roles_provisioning_source_team_unique
ON roles (tenant_id, provisioning_source)
WHERE provisioning_source IS NOT NULL
```

SQLite triggers:

```text
roles_provisioning_source_insert_guard
roles_provisioning_source_update_guard
```

Each trigger rejects a non-null marker unless:

```text
NEW.tenant_id IS NOT NULL
NEW.provisioning_source = 'w-lot-a-1a'
NEW.name = 'general_manager'
NEW.guard_name = 'sanctum'
```

<a id="r3-m-b"></a>
**R3-M-B:** the marked-role invariant includes team identity. A row with `tenant_id=NULL`, `name=general_manager`, `guard_name=sanctum`, and `provisioning_source=w-lot-a-1a` is invalid in PostgreSQL and SQLite. The partial unique index permits exactly one non-null provisioning marker per tenant team while allowing different tenants to own their own marked role.

`up()` must:

1. Assert the roles table exists.
2. Resolve and validate the configured team column.
3. Add only an absent column.
4. Validate existing type, length, nullability, and default.
5. Compare the named PostgreSQL constraint after normalized whitespace.
6. Install and verify equivalent SQLite insert/update triggers.
7. Install and verify the team-scoped partial unique index.
8. Reject a pre-existing global marked role.
9. Be a no-op only for the exact schema, constraint, triggers, and index.

`down()` must refuse while any row is marked; drop only the named index, constraint/triggers, and column; and never delete a role, permission, or pivot.

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
- A global marked role is invalid and is never adopted.
- No force option exists.
- A marked role retains its bigint ID.
- Exactly one marked role exists per tenant team.
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
- Staging reseed checks use only named disposable tenants; a fleet-wide generic reseed is forbidden for this rollout.

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
- `name=general_manager`, `guard_name=sanctum`, non-null configured team ID, and `provisioning_source=w-lot-a-1a`.

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

    public function test_each_tenant_team_has_exactly_one_marked_general_manager(): void;
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

    public function test_postgresql_rejects_marked_role_without_team(): void;

    public function test_postgresql_partial_unique_index_enforces_one_marker_per_team(): void;

    public function test_sqlite_triggers_reject_invalid_and_null_team_markers(): void;

    public function test_sqlite_partial_unique_index_enforces_one_marker_per_team(): void;

    public function test_down_refuses_while_a_marked_role_exists(): void;
}
```

Red-first assertions:

| Exact file | Exact class::method | First failing assertion | Command | Lane |
|---|---|---|---|---|
| `tests/Feature/Identity/LotActionSeededRoleMatrixTest.php` | `LotActionSeededRoleMatrixTest::test_viewer_and_operator_gain_batch_view_in_canonical_matrix` | `self::assertContains('batches.view', $grants['viewer']);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotActionSeededRoleMatrixTest::test_viewer_and_operator_gain_batch_view_in_canonical_matrix'` | PostgreSQL |
| same | `LotActionSeededRoleMatrixTest::test_manager_loses_global_recall_and_gains_request_in_canonical_matrix` | `self::assertNotContains('batches.recall', $grants['manager']);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotActionSeededRoleMatrixTest::test_manager_loses_global_recall_and_gains_request_in_canonical_matrix'` | PostgreSQL |
| `tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php` | `RoleProvisioningSourceSchemaTest::test_postgresql_rejects_marked_role_without_team` | `$this->expectException(\Illuminate\Database\QueryException::class);` before inserting a null-team marker | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'RoleProvisioningSourceSchemaTest::test_postgresql_rejects_marked_role_without_team'` | PostgreSQL |
| same | `RoleProvisioningSourceSchemaTest::test_postgresql_partial_unique_index_enforces_one_marker_per_team` | `$this->expectException(\Illuminate\Database\QueryException::class);` before inserting the second marker for one tenant | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'RoleProvisioningSourceSchemaTest::test_postgresql_partial_unique_index_enforces_one_marker_per_team'` | PostgreSQL |
| same | `RoleProvisioningSourceSchemaTest::test_sqlite_triggers_reject_invalid_and_null_team_markers` | `$this->expectException(\Illuminate\Database\QueryException::class);` before the null-team insert | `cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'RoleProvisioningSourceSchemaTest::test_sqlite_triggers_reject_invalid_and_null_team_markers'` | SQLite |
| same | `RoleProvisioningSourceSchemaTest::test_sqlite_partial_unique_index_enforces_one_marker_per_team` | `$this->expectException(\Illuminate\Database\QueryException::class);` before the second same-team marker | `cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'RoleProvisioningSourceSchemaTest::test_sqlite_partial_unique_index_enforces_one_marker_per_team'` | SQLite |
| `tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php` | `ApplyLotActionPermissionDeltaCommandTest::test_neither_mode_emits_invalid_mode_and_exits_two` | `$this->artisan('permissions:apply-lot-action-delta')->assertExitCode(2);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'ApplyLotActionPermissionDeltaCommandTest::test_neither_mode_emits_invalid_mode_and_exits_two'` | PostgreSQL |

Task-2 convention-09 evidence persists `allowed_location_ids=[B2]`, attempts `general_manager`, and asserts 422 plus the stable error code. Delta rerun proves `APPLIED`, then `ALREADY_APPLIED`, one marked role, the same ID, no duplicate pivots, and preserved custom grants.

Task 2 requires ACCEPT from `tenancy-authz-reviewer` and `inventory-costing-reviewer`. Review must cover team identity, null-team rejection, partial-index cardinality, transaction extent, lock order, collision behavior, role identity, custom grants, manager recall removal, assignment paths, B2 selection, narrowing race, rename/delete protection, marker cardinality, and absence of hold behavior.

Rollback is forward-only: preserve the marker column, index, constraint, and marked role ID; correct grants through a new delta; never delete/rename/unmark the role or edit pivots ad hoc.

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

The permission-map gate is the executable fail-closed block in §11.8. It generates two independent temporary artifacts, verifies each exact success line and exit status, requires byte identity between both artifacts and the committed map, and rejects `FAILED`, `SKIPPED`, missing output, or drift.

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
| Migrations list | `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php` — additive; self-guarding; prerequisite: exact existing `roles` table and configured team column `tenant_id` |
| Flags | `lot_action_permissions.enforce` / `LOT_ACTION_PERMISSIONS_ENFORCE` / `apps/api/config/lot_action_permissions.php` / `false` |
| Commands | NEW `permissions:apply-lot-action-delta {--apply} {--verify}` under `tenants:run`, marker `WLOTA1A-PERMISSIONS`; existing `tenants:seed --tenants=<uuid> --force --class='Database\Seeders\RolesAndPermissionsSeeder'` for disposable test tenants only, marker `WLOTA1A-RESEED`; existing `permission:cache-reset` standalone, synthesized marker `WLOTA1A-PERMISSION-CACHE`; existing `permissions:export-frontend-map --path=<path>` standalone, exact marker `Exported frontend permission map to <path>.` |
| Censuses | Push 1 and Push 4: `tenants:run tenant:census-day-one --tenants=<uuid> --option='fail-on-drift=1'`, marker `DAY-ONE CENSUS` plus synthesized fleet marker; Push 4 per tenant: `inventory:lot-drift-census --tenant=<uuid> --fail-on-drift`, exact zero summaries; `inventory:repair-phantom-default-batches --tenant=<uuid> --dry-run`, exact zero summaries |
| Web changes | yes — served bundle string `wlota1a-batch-permission-gating-v1`; build SHA must equal promoted `CANDIDATE_SHA`; feature fingerprint must equal local generator output |
| Device build | no |
| Queues | none |
| Collapsed pushes | none; Pushes 1 and 4 are operational phases without source artifacts |
| Env path | Dokploy Environment tab; resolve manifest U-1 before Push 3 and do not edit `docker-compose.staging.yml` |

<a id="r3-b-b"></a>
**R3-B-B:** every command below is a fail-closed executable gate. `set -Eeuo pipefail` is mandatory. A missing command, non-zero direct command exit, empty tenant manifest, malformed UUID, failed parse, missing marker, duplicate marker, unexpected marker, `FAILED`, `SKIPPED`, `ERRORED`, missing-database marker, non-zero census result, stale permission map, stale web asset, or wrong build SHA exits the phase non-zero. Promotion stops immediately.

### 11.1 Shared strict gate functions and immutable tenant manifest

Run from the API container before every fleet phase:

```bash
set -Eeuo pipefail
export LC_ALL=C

SLICE='wlota1a-permissions-roles-web'
UUID_RE='[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'

fail_gate() {
  printf 'WLOTA1A-GATE outcome=FAILED reason=%s\n' "$1" >&2
  exit 1
}

line_count() {
  wc -l < "$1" | tr -d ' '
}

reject_bad_output() {
  local log="$1"
  if grep -Eq '(^|[ =:])(FAILED|SKIPPED|ERRORED)([ =:]|$)|missing database|does not exist|SQLSTATE|Unhandled|Exception' "$log"; then
    fail_gate "bad_output:${log}"
  fi
}

capture_tenant_manifest() {
  local phase="$1"
  local log="/tmp/${SLICE}-${phase}-tenants.log"
  local records="/tmp/${SLICE}-${phase}-tenant-records.txt"
  local raw="/tmp/${SLICE}-${phase}-tenant-ids-raw.txt"
  local lower="/tmp/${SLICE}-${phase}-tenant-ids-lower.txt"
  local expected="/tmp/${SLICE}-${phase}-expected-tenants.txt"

  if ! php artisan tenants:list --no-ansi 2>&1 | tee "$log"; then
    fail_gate "tenants_list_exit:${phase}"
  fi
  reject_bad_output "$log"

  grep '^\[Tenant\] ' "$log" > "$records" || true
  test -s "$records" || fail_gate "empty_tenant_records:${phase}"

  sed -nE \
    "s/^\\[Tenant\\] [^:]+: (${UUID_RE})( @ .*)?$/\\1/p" \
    "$records" > "$raw"

  test "$(line_count "$raw")" -eq "$(line_count "$records")" \
    || fail_gate "unparsed_tenant_record:${phase}"

  tr 'A-F' 'a-f' < "$raw" > "$lower"

  if grep -Ev "^${UUID_RE}$" "$lower"; then
    fail_gate "malformed_tenant_uuid:${phase}"
  fi

  sort "$lower" > "/tmp/${SLICE}-${phase}-tenant-ids-sorted.txt"
  uniq "/tmp/${SLICE}-${phase}-tenant-ids-sorted.txt" > "$expected"

  local raw_count
  local expected_count
  raw_count="$(line_count "$lower")"
  expected_count="$(line_count "$expected")"

  test "$expected_count" -gt 0 || fail_gate "empty_expected_tenants:${phase}"
  test "$raw_count" -eq "$expected_count" \
    || fail_gate "duplicate_directory_tenant:${phase}"

  EXPECTED_TENANTS="$expected"
  EXPECTED_TENANT_COUNT="$expected_count"
  TENANT_IDS="$(paste -sd, "$expected")"

  test -n "$TENANT_IDS" || fail_gate "empty_tenant_csv:${phase}"

  export EXPECTED_TENANTS EXPECTED_TENANT_COUNT TENANT_IDS

  printf 'TENANT_IDS=%s\n' "$TENANT_IDS"
  printf 'WLOTA1A-TENANT-MANIFEST phase=%s count=%s outcome=PASS\n' \
    "$phase" "$EXPECTED_TENANT_COUNT"
}

assert_exact_marker_set() {
  local expected="$1"
  local log="$2"
  local prefix="$3"
  local success_tail="$4"
  local raw="/tmp/${SLICE}-${prefix}-actual-raw.txt"
  local sorted="/tmp/${SLICE}-${prefix}-actual-sorted.txt"
  local unique="/tmp/${SLICE}-${prefix}-actual-unique.txt"
  local missing="/tmp/${SLICE}-${prefix}-missing.txt"
  local unexpected="/tmp/${SLICE}-${prefix}-unexpected.txt"

  reject_bad_output "$log"

  local marker_count
  local valid_count
  marker_count="$(grep -Ec "^${prefix} tenant=" "$log" || true)"
  valid_count="$(grep -Ec "^${prefix} tenant=${UUID_RE} ${success_tail}$" "$log" || true)"

  test "$marker_count" -eq "$valid_count" \
    || fail_gate "invalid_marker:${prefix}"

  awk -v prefix="$prefix" '
    $1 == prefix {
      for (i = 1; i <= NF; i++) {
        if ($i ~ /^tenant=/) {
          sub(/^tenant=/, "", $i)
          print tolower($i)
        }
      }
    }
  ' "$log" > "$raw"

  if grep -Ev "^${UUID_RE}$" "$raw"; then
    fail_gate "malformed_marker_uuid:${prefix}"
  fi

  sort "$raw" > "$sorted"
  uniq "$sorted" > "$unique"

  local expected_count
  local raw_count
  local unique_count
  expected_count="$(line_count "$expected")"
  raw_count="$(line_count "$raw")"
  unique_count="$(line_count "$unique")"

  test "$expected_count" -gt 0 || fail_gate "empty_expected_marker_set:${prefix}"
  test "$raw_count" -eq "$expected_count" \
    || fail_gate "missing_or_extra_marker:${prefix}"
  test "$unique_count" -eq "$raw_count" \
    || fail_gate "duplicate_marker:${prefix}"

  comm -23 "$expected" "$unique" > "$missing"
  comm -13 "$expected" "$unique" > "$unexpected"

  test ! -s "$missing" || fail_gate "missing_tenant_marker:${prefix}"
  test ! -s "$unexpected" || fail_gate "unexpected_tenant_marker:${prefix}"

  printf 'WLOTA1A-EXACT-ONCE gate=%s expected=%s observed=%s missing=0 duplicate=0 unexpected=0 outcome=PASS\n' \
    "$prefix" "$expected_count" "$raw_count"
}
```

The raw records, raw ID manifest, normalized expected manifest, and command log are four separate artifacts. `sort -u` is not used before duplicate detection. Copy each phase’s expected manifest outside the container before continuing.

### 11.2 Host backup gate for Pushes 2 and 4

Run on the staging host immediately before each migrating or backfilling phase. Copy the phase’s expected manifest from the API container first.

```bash
set -Eeuo pipefail
export LC_ALL=C

SLICE='wlota1a-permissions-roles-web'
PHASE='p2' # use p4 immediately before Push 4
EXPECTED_TENANTS="/root/${SLICE}-${PHASE}-expected-tenants.txt"
BACKUP_LOG="/root/${SLICE}-${PHASE}-backup.log"
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"

test -s "$EXPECTED_TENANTS" || exit 1

docker ps --format '{{.Names}}' \
  | awk 'tolower($0) ~ /postgres/' \
  > "/root/${SLICE}-${PHASE}-postgres-containers.txt"

test "$(wc -l < "/root/${SLICE}-${PHASE}-postgres-containers.txt" | tr -d ' ')" -eq 1
PG_CTR="$(head -n 1 "/root/${SLICE}-${PHASE}-postgres-containers.txt")"
test -n "$PG_CTR"

: > "$BACKUP_LOG"

while IFS= read -r tenant_id; do
  dump="/root/backup-${SLICE}-${PHASE}-${tenant_id}-${TIMESTAMP}.dump"

  if ! docker exec "$PG_CTR" \
    pg_dump -Fc -U autoerp -d "tenant_${tenant_id}" > "$dump"; then
    printf 'WLOTA1A-BACKUP-%s tenant=%s outcome=FAILED\n' \
      "$(printf '%s' "$PHASE" | tr '[:lower:]' '[:upper:]')" "$tenant_id" >&2
    exit 1
  fi

  test -s "$dump"

  printf 'WLOTA1A-BACKUP-%s tenant=%s outcome=PASS\n' \
    "$(printf '%s' "$PHASE" | tr '[:lower:]' '[:upper:]')" "$tenant_id" \
    | tee -a "$BACKUP_LOG"
done < "$EXPECTED_TENANTS"
```

Validate the backup log with §11.1’s `assert_exact_marker_set`, using the same expected manifest and:

```text
prefix: WLOTA1A-BACKUP-P2 or WLOTA1A-BACKUP-P4
success tail: outcome=PASS
```

Exit rule: every expected UUID has exactly one non-empty dump and one success marker; any missing, duplicate, unexpected, or failure marker blocks promotion.

### 11.3 Push 1 census

Capture `p1` first, then run:

```bash
set -Eeuo pipefail
: > "/tmp/${SLICE}-census-p1.log"

while IFS= read -r tenant_id; do
  tenant_log="/tmp/${SLICE}-census-p1-${tenant_id}.log"

  if ! php artisan tenants:run tenant:census-day-one \
    --tenants="$tenant_id" \
    --option='fail-on-drift=1' \
    --no-ansi \
    2>&1 | tee "$tenant_log"; then
    fail_gate "tenants_run_day_one_exit:p1:${tenant_id}"
  fi

  test "$(grep -Ec "^Tenant: ${tenant_id}$" "$tenant_log" || true)" -eq 1 \
    || fail_gate "day_one_parent_marker:p1:${tenant_id}"

  clean_count="$(grep -Ec "^DAY-ONE CENSUS ${tenant_id} ${UUID_RE}: CLEAN$" "$tenant_log" || true)"
  verdict_count="$(grep -Ec '^DAY-ONE CENSUS ' "$tenant_log" || true)"

  test "$clean_count" -gt 0 || fail_gate "missing_day_one_verdict:p1:${tenant_id}"
  test "$clean_count" -eq "$verdict_count" \
    || fail_gate "unclean_day_one_verdict:p1:${tenant_id}"

  reject_bad_output "$tenant_log"

  printf 'WLOTA1A-CENSUS-P1 tenant=%s outcome=PASS\n' "$tenant_id" \
    | tee -a "/tmp/${SLICE}-census-p1.log"
done < "$EXPECTED_TENANTS"

assert_exact_marker_set \
  "$EXPECTED_TENANTS" \
  "/tmp/${SLICE}-census-p1.log" \
  'WLOTA1A-CENSUS-P1' \
  'outcome=PASS'
```

`tenants:run` discards child exit status at [Run.php:54](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:54), so the exact `Tenant:` and `DAY-ONE CENSUS …: CLEAN` markers are authoritative.

Verify boot permission synchronization:

```bash
set -Eeuo pipefail

sync_value="$(
  php artisan tinker --no-ansi \
    --execute="fwrite(STDOUT, getenv('SYNC_PERMISSIONS_ON_BOOT') ?: 'UNSET');" \
    2>&1
)"

case "$sync_value" in
  false|0|UNSET) ;;
  *) fail_gate 'sync_permissions_on_boot_truthy' ;;
esac

printf 'WLOTA1A-BOOT-PERMISSION-SYNC value=%s outcome=PASS\n' "$sync_value"
```

Only `false`, `0`, or `UNSET` exits zero.

### 11.4 Push 2 migration

1. Capture the `p2` manifest.
2. Copy it to the host.
3. Complete §11.2 with `PHASE=p2`.
4. Deploy only the migration and schema test.
5. Run both PostgreSQL and SQLite schema-test commands from §13.
6. In the API container, run two exact migration passes per expected tenant:

```bash
set -Eeuo pipefail
: > "/tmp/${SLICE}-migration-p2-pass1.log"
: > "/tmp/${SLICE}-migration-p2-pass2.log"

for pass in 1 2; do
  aggregate="/tmp/${SLICE}-migration-p2-pass${pass}.log"

  while IFS= read -r tenant_id; do
    tenant_log="/tmp/${SLICE}-migration-p2-pass${pass}-${tenant_id}.log"

    if ! php artisan tenants:migrate-rolling \
      --force \
      --tenant="$tenant_id" \
      --no-ansi \
      2>&1 | tee "$tenant_log"; then
      fail_gate "migration_exit:pass${pass}:${tenant_id}"
    fi

    reject_bad_output "$tenant_log"

    test "$(grep -Fc 'Database-per-tenant mode is OFF' "$tenant_log" || true)" -eq 0
    test "$(grep -Fc 'No tenants to migrate.' "$tenant_log" || true)" -eq 0
    test "$(grep -Fc 'No tenant found with id' "$tenant_log" || true)" -eq 0
    test "$(grep -Ec '^Rolling tenant migrations across 1 tenant\\(s\\)\\.$' "$tenant_log" || true)" -eq 1
    test "$(grep -Ec '^→ ' "$tenant_log" || true)" -eq 1
    test "$(grep '^→ ' "$tenant_log" | grep -Fc "$tenant_id" || true)" -eq 1
    test "$(grep -Ec '^Done\\. 1 tenant\\(s\\) migrated, 0 failed\\.$' "$tenant_log" || true)" -eq 1

    prefix="WLOTA1A-MIGRATION-P2-PASS${pass}"
    printf '%s tenant=%s outcome=PASS\n' "$prefix" "$tenant_id" \
      | tee -a "$aggregate"
  done < "$EXPECTED_TENANTS"
done

assert_exact_marker_set \
  "$EXPECTED_TENANTS" \
  "/tmp/${SLICE}-migration-p2-pass1.log" \
  'WLOTA1A-MIGRATION-P2-PASS1' \
  'outcome=PASS'

assert_exact_marker_set \
  "$EXPECTED_TENANTS" \
  "/tmp/${SLICE}-migration-p2-pass2.log" \
  'WLOTA1A-MIGRATION-P2-PASS2' \
  'outcome=PASS'
```

Direct `tenants:migrate-rolling` must exit zero. Its exact success summary is emitted at [RollingTenantMigrationCommand.php:163](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:163); `Database-per-tenant mode is OFF`, zero tenants, missing tenant, `FAILED`, or `Done with errors` blocks the push.

The schema tests must prove:

- Exact nullable `VARCHAR(32)` column.
- Exact named CHECK.
- Null-team marker rejection.
- Exact partial unique index.
- One marker per tenant team.
- PostgreSQL and SQLite parity.
- No marked role before Push 4.
- Idempotent migration behavior.

Rollback is forward-only.

### 11.5 Push 3 dormant runtime and legacy reseed gate

Set `LOT_ACTION_PERMISSIONS_ENFORCE=false` in worker, API, and scheduler. Redeploy and run this exact read-back independently in each container:

```bash
set -Eeuo pipefail
SERVICE='worker' # repeat with api, then scheduler

value="$(
  php artisan tinker --no-ansi \
    --execute="fwrite(STDOUT, config('lot_action_permissions.enforce') ? 'ON' : 'OFF');" \
    2>&1
)"

test "$value" = 'OFF'
printf 'WLOTA1A-CONFIG service=%s expected=OFF actual=%s outcome=PASS\n' \
  "$SERVICE" "$value"
```

Require exactly these three lines and no others in the activation evidence:

```text
WLOTA1A-CONFIG service=worker expected=OFF actual=OFF outcome=PASS
WLOTA1A-CONFIG service=api expected=OFF actual=OFF outcome=PASS
WLOTA1A-CONFIG service=scheduler expected=OFF actual=OFF outcome=PASS
```

Run the focused flag-off tests. Then prove the production seeder marker only on a named disposable tenant:

```bash
set -Eeuo pipefail

DISPOSABLE_LEGACY_TENANT_ID='<captured-disposable-tenant-uuid>'
printf '%s\n' "$DISPOSABLE_LEGACY_TENANT_ID" \
  > "/tmp/${SLICE}-p3-reseed-expected.txt"

grep -Eq "^${UUID_RE}$" "/tmp/${SLICE}-p3-reseed-expected.txt"
grep -Fx "$DISPOSABLE_LEGACY_TENANT_ID" "$EXPECTED_TENANTS"

if ! php artisan tenants:seed \
  --tenants="$DISPOSABLE_LEGACY_TENANT_ID" \
  --force \
  --class='Database\Seeders\RolesAndPermissionsSeeder' \
  --no-ansi \
  2>&1 | tee "/tmp/${SLICE}-p3-reseed.log"; then
  fail_gate 'legacy_reseed_exit'
fi

test "$(grep -Ec "^Tenant: ${DISPOSABLE_LEGACY_TENANT_ID}$" "/tmp/${SLICE}-p3-reseed.log" || true)" -eq 1

assert_exact_marker_set \
  "/tmp/${SLICE}-p3-reseed-expected.txt" \
  "/tmp/${SLICE}-p3-reseed.log" \
  'WLOTA1A-RESEED' \
  'mode=LEGACY outcome=ALREADY_APPLIED reason=enforcement_off'
```

Exit zero additionally requires tests proving:

- Pre-activation authorization and payload fixtures remain unchanged.
- Restricted reads retain legacy semantics.
- Duplicate response retains its old payload.
- No `general_manager` is created.
- Manager retains legacy recall.
- Viewer/operator are unchanged by generic flag-off seeding.

No fleet-wide generic reseed is authorized.

### 11.6 Push 4 delta, backup, and permission cache

1. Capture the `p4` manifest.
2. Copy it to the host.
3. Complete §11.2 with `PHASE=p4` immediately before the delta.
4. Run apply:

```bash
set -Eeuo pipefail
: > "/tmp/${SLICE}-delta-apply.log"

while IFS= read -r tenant_id; do
  tenant_log="/tmp/${SLICE}-delta-apply-${tenant_id}.log"

  if ! php artisan tenants:run permissions:apply-lot-action-delta \
    --tenants="$tenant_id" \
    --option='apply=1' \
    --no-ansi \
    2>&1 | tee "$tenant_log"; then
    fail_gate "delta_parent_exit:${tenant_id}"
  fi

  test "$(grep -Ec "^Tenant: ${tenant_id}$" "$tenant_log" || true)" -eq 1 \
    || fail_gate "delta_parent_marker:${tenant_id}"

  reject_bad_output "$tenant_log"

  grep "^WLOTA1A-PERMISSIONS tenant=${tenant_id} " "$tenant_log" \
    >> "/tmp/${SLICE}-delta-apply.log"
done < "$EXPECTED_TENANTS"

assert_exact_marker_set \
  "$EXPECTED_TENANTS" \
  "/tmp/${SLICE}-delta-apply.log" \
  'WLOTA1A-PERMISSIONS' \
  'mode=APPLY outcome=(APPLIED|ALREADY_APPLIED) reason=[a-z0-9_]+'
```

Apply-mode exit rules:

- `APPLIED` or `ALREADY_APPLIED`: command exit 0 and valid.
- `SKIPPED`: command exit 1 and invalid.
- `FAILED`: command exit 1 and invalid.
- Missing marker: invalid.
- Two markers for one UUID: invalid.
- UUID outside the captured manifest: invalid.

Reset permission cache:

```bash
set -Eeuo pipefail

if ! php artisan permission:cache-reset --no-ansi \
  2>&1 | tee "/tmp/${SLICE}-permission-cache.log"; then
  fail_gate 'permission_cache_reset_exit'
fi

reject_bad_output "/tmp/${SLICE}-permission-cache.log"
printf 'WLOTA1A-PERMISSION-CACHE outcome=PASS\n' \
  | tee -a "/tmp/${SLICE}-permission-cache.log"

test "$(grep -Ec '^WLOTA1A-PERMISSION-CACHE outcome=PASS$' "/tmp/${SLICE}-permission-cache.log" || true)" -eq 1
```

Run read-only verify:

```bash
set -Eeuo pipefail
: > "/tmp/${SLICE}-delta-verify.log"

while IFS= read -r tenant_id; do
  tenant_log="/tmp/${SLICE}-delta-verify-${tenant_id}.log"

  if ! php artisan tenants:run permissions:apply-lot-action-delta \
    --tenants="$tenant_id" \
    --option='verify=1' \
    --no-ansi \
    2>&1 | tee "$tenant_log"; then
    fail_gate "verify_parent_exit:${tenant_id}"
  fi

  test "$(grep -Ec "^Tenant: ${tenant_id}$" "$tenant_log" || true)" -eq 1 \
    || fail_gate "verify_parent_marker:${tenant_id}"

  reject_bad_output "$tenant_log"

  grep "^WLOTA1A-PERMISSIONS tenant=${tenant_id} " "$tenant_log" \
    >> "/tmp/${SLICE}-delta-verify.log"
done < "$EXPECTED_TENANTS"

assert_exact_marker_set \
  "$EXPECTED_TENANTS" \
  "/tmp/${SLICE}-delta-verify.log" \
  'WLOTA1A-PERMISSIONS' \
  'mode=VERIFY outcome=ALREADY_APPLIED reason=[a-z0-9_]+'
```

Do not run `tenants:seed` here.

### 11.7 Push 4 census gates

Run lot drift directly per expected tenant:

```bash
set -Eeuo pipefail
: > "/tmp/${SLICE}-lot-census.log"

while IFS= read -r tenant_id; do
  tenant_log="/tmp/${SLICE}-lot-census-${tenant_id}.log"

  if ! php artisan inventory:lot-drift-census \
    --tenant="$tenant_id" \
    --fail-on-drift \
    --no-ansi \
    2>&1 | tee "$tenant_log"; then
    fail_gate "lot_census_exit:${tenant_id}"
  fi

  reject_bad_output "$tenant_log"

  test "$(grep -Ec '^Tuples drifted: 0$' "$tenant_log" || true)" -eq 1
  test "$(grep -Ec '^Net drift: 0(\\.0+)?$' "$tenant_log" || true)" -eq 1
  test "$(grep -Ec '^Absolute drift: 0(\\.0+)?$' "$tenant_log" || true)" -eq 1
  test "$(grep -Ec '^Read-only census: nothing was written\\.$' "$tenant_log" || true)" -eq 1
  test "$(grep -Ec '^[[:space:]]*DRIFT ' "$tenant_log" || true)" -eq 0

  printf 'WLOTA1A-LOT-CENSUS tenant=%s outcome=PASS\n' "$tenant_id" \
    | tee -a "/tmp/${SLICE}-lot-census.log"
done < "$EXPECTED_TENANTS"

assert_exact_marker_set \
  "$EXPECTED_TENANTS" \
  "/tmp/${SLICE}-lot-census.log" \
  'WLOTA1A-LOT-CENSUS' \
  'outcome=PASS'
```

Run phantom DEFAULT dry-run directly per expected tenant:

```bash
set -Eeuo pipefail
: > "/tmp/${SLICE}-phantom-census.log"

while IFS= read -r tenant_id; do
  tenant_log="/tmp/${SLICE}-phantom-census-${tenant_id}.log"

  if ! php artisan inventory:repair-phantom-default-batches \
    --tenant="$tenant_id" \
    --dry-run \
    --no-ansi \
    2>&1 | tee "$tenant_log"; then
    fail_gate "phantom_census_exit:${tenant_id}"
  fi

  reject_bad_output "$tenant_log"

  test "$(grep -Ec '^Phantom DEFAULT lots: 0$' "$tenant_log" || true)" -eq 1
  test "$(grep -Ec '^Total phantom quantity: 0(\\.0+)?$' "$tenant_log" || true)" -eq 1
  test "$(grep -Ec '^Reservations re-pointed to real lots: 0$' "$tenant_log" || true)" -eq 1
  test "$(grep -Ec '^Reservations left on the DEFAULT lot: 0$' "$tenant_log" || true)" -eq 1
  test "$(grep -Ec '^Lots only partially reduced: 0$' "$tenant_log" || true)" -eq 1
  test "$(grep -Ec '^Lots skipped \\(excess changed under the lock\\): 0$' "$tenant_log" || true)" -eq 1
  test "$(grep -Ec '^Tuples still drifted: 0$' "$tenant_log" || true)" -eq 1
  test "$(grep -Ec '^Dry run: nothing was written\\. Re-run with --execute to apply\\.$' "$tenant_log" || true)" -eq 1

  printf 'WLOTA1A-PHANTOM-CENSUS tenant=%s outcome=PASS\n' "$tenant_id" \
    | tee -a "/tmp/${SLICE}-phantom-census.log"
done < "$EXPECTED_TENANTS"

assert_exact_marker_set \
  "$EXPECTED_TENANTS" \
  "/tmp/${SLICE}-phantom-census.log" \
  'WLOTA1A-PHANTOM-CENSUS' \
  'outcome=PASS'
```

Run day-one using the §11.3 loop with output prefix and aggregate file changed from `P1` to `P4`:

```text
WLOTA1A-CENSUS-P4 tenant=<uuid> outcome=PASS
```

Validate it with:

```bash
assert_exact_marker_set \
  "$EXPECTED_TENANTS" \
  "/tmp/${SLICE}-census-p4.log" \
  'WLOTA1A-CENSUS-P4' \
  'outcome=PASS'
```

All three census marker sets must cover the same non-empty `p4` manifest exactly once. A direct lot/phantom command must exit zero. A `tenants:run` day-one parent exit is insufficient without exact child verdict parsing.

### 11.8 Permission-map gate

Run before committing Push 5 and repeat against the committed candidate:

```bash
set -Eeuo pipefail
export LC_ALL=C

cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api

FIRST='/tmp/wlota1a-permissions-map-first.ts'
SECOND='/tmp/wlota1a-permissions-map-second.ts'
COMMITTED='../web/src/hooks/permissionsMap.generated.ts'
LOG1='/tmp/wlota1a-permissions-map-first.log'
LOG2='/tmp/wlota1a-permissions-map-second.log'

if ! php artisan permissions:export-frontend-map \
  --path="$FIRST" \
  --no-ansi \
  2>&1 | tee "$LOG1"; then
  exit 1
fi

test "$(grep -Fxc "Exported frontend permission map to ${FIRST}." "$LOG1" || true)" -eq 1
test "$(grep -Ec 'FAILED|SKIPPED|ERRORED' "$LOG1" || true)" -eq 0
test -s "$FIRST"

if ! php artisan permissions:export-frontend-map \
  --path="$SECOND" \
  --no-ansi \
  2>&1 | tee "$LOG2"; then
  exit 1
fi

test "$(grep -Fxc "Exported frontend permission map to ${SECOND}." "$LOG2" || true)" -eq 1
test "$(grep -Ec 'FAILED|SKIPPED|ERRORED' "$LOG2" || true)" -eq 0
test -s "$SECOND"

cmp -s "$FIRST" "$SECOND"
cmp -s "$FIRST" "$COMMITTED"

MAP_SHA="$(sha256sum "$FIRST" | awk '{print $1}')"
test -n "$MAP_SHA"

printf 'WLOTA1A-PERMISSION-MAP generations=2 committed_match=1 sha256=%s outcome=PASS\n' \
  "$MAP_SHA"
```

Exit rules:

- Each exporter command must exit 0.
- Each exact exporter stdout line occurs once.
- Both generated files are non-empty.
- Generation 1 equals generation 2 byte-for-byte.
- The committed map equals the fresh output byte-for-byte.
- Any `FAILED`, `SKIPPED`, `ERRORED`, missing output, or comparison difference exits non-zero.

### 11.9 Rollout-window recensus

Immediately before activation:

```bash
set -Eeuo pipefail

P4_EXPECTED="/tmp/${SLICE}-p4-expected-tenants.txt"
capture_tenant_manifest 'preactivation'
PRE_EXPECTED="$EXPECTED_TENANTS"

comm -23 "$P4_EXPECTED" "$PRE_EXPECTED" \
  > "/tmp/${SLICE}-preactivation-missing-from-directory.txt"
comm -13 "$P4_EXPECTED" "$PRE_EXPECTED" \
  > "/tmp/${SLICE}-preactivation-new-tenants.txt"

test ! -s "/tmp/${SLICE}-preactivation-missing-from-directory.txt" \
  || fail_gate 'tenant_disappeared_before_activation'
```

1. Pause registration before this capture.
2. If `preactivation-new-tenants.txt` is non-empty, use that file as the expected manifest and run the exact apply, verify, day-one, lot, and phantom loops from §§11.6–11.7.
3. Run verify and all three census gates again against the full `PRE_EXPECTED`.
4. Keep registration paused through worker → API → scheduler activation.
5. After API activation, call `capture_tenant_manifest 'postactivation'`.
6. Require no UUID disappeared and no unexpected UUID appeared while registration was paused:

```bash
comm -23 "$PRE_EXPECTED" "$EXPECTED_TENANTS" \
  > "/tmp/${SLICE}-postactivation-missing.txt"
comm -13 "$PRE_EXPECTED" "$EXPECTED_TENANTS" \
  > "/tmp/${SLICE}-postactivation-unexpected.txt"

test ! -s "/tmp/${SLICE}-postactivation-missing.txt" \
  || fail_gate 'tenant_disappeared_during_activation'
test ! -s "/tmp/${SLICE}-postactivation-unexpected.txt" \
  || fail_gate 'unexpected_tenant_during_activation'
```

7. Verify the full current fleet again before registration resumes.
8. Register one disposable tenant after activation.
9. Capture that exact UUID in a one-line non-empty expected manifest.
10. Run the activated reseed gate:

```bash
set -Eeuo pipefail

DISPOSABLE_ACTIVATED_TENANT_ID='<captured-disposable-tenant-uuid>'
ACTIVATED_EXPECTED="/tmp/${SLICE}-activated-reseed-expected.txt"

printf '%s\n' "$DISPOSABLE_ACTIVATED_TENANT_ID" > "$ACTIVATED_EXPECTED"
grep -Eq "^${UUID_RE}$" "$ACTIVATED_EXPECTED"

if ! php artisan tenants:seed \
  --tenants="$DISPOSABLE_ACTIVATED_TENANT_ID" \
  --force \
  --class='Database\Seeders\RolesAndPermissionsSeeder' \
  --no-ansi \
  2>&1 | tee "/tmp/${SLICE}-activated-reseed.log"; then
  fail_gate 'activated_reseed_exit'
fi

test "$(grep -Ec "^Tenant: ${DISPOSABLE_ACTIVATED_TENANT_ID}$" "/tmp/${SLICE}-activated-reseed.log" || true)" -eq 1

assert_exact_marker_set \
  "$ACTIVATED_EXPECTED" \
  "/tmp/${SLICE}-activated-reseed.log" \
  'WLOTA1A-RESEED' \
  'mode=ACTIVATED outcome=ALREADY_APPLIED reason=[a-z0-9_]+'
```

The initial registration seed must have emitted `mode=ACTIVATED outcome=APPLIED`; the explicit rerun must emit exactly one `ALREADY_APPLIED`. Resume registration only after the disposable tenant has the canonical matrix and no global marked role.

### 11.10 Push 5 web deploy and activation

Before web deployment:

```bash
set -Eeuo pipefail
export LC_ALL=C

cd /Users/houssamr/Projects/syneriva/apps/erp

CANDIDATE_SHA="$(git rev-parse HEAD)"
test -n "$CANDIDATE_SHA"
export CANDIDATE_SHA

BEFORE='/tmp/wlota1a-web-before.txt'
AFTER='/tmp/wlota1a-web-after.txt'
FINGERPRINT_JSON='/tmp/wlota1a-build-fingerprint.json'

curl --fail --silent --show-error https://erp.otospex.dev/ \
  | grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' \
  | sort -u \
  > "$BEFORE"

test -s "$BEFORE"
test "$(wc -l < "$BEFORE" | tr -d ' ')" -eq 1

printf 'WLOTA1A-WEB-BEFORE asset=%s outcome=PASS\n' "$(head -n 1 "$BEFORE")"
```

Exact deploy action:

```text
mcp__dokploy-mcp__application-deploy
applicationId=mY6P_PHb4pw-2LdG1Y7Ml
```

After Dokploy reports success:

```bash
set -Eeuo pipefail
export LC_ALL=C

curl --fail --silent --show-error https://erp.otospex.dev/ \
  | grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' \
  | sort -u \
  > "$AFTER"

test -s "$AFTER"
test "$(wc -l < "$AFTER" | tr -d ' ')" -eq 1

if cmp -s "$BEFORE" "$AFTER"; then
  printf 'WLOTA1A-WEB-DEPLOY outcome=FAILED reason=unchanged_asset\n' >&2
  exit 1
fi

NEW_ASSET="$(head -n 1 "$AFTER")"
test -n "$NEW_ASSET"

FEATURE_COUNT="$(
  curl --fail --silent --show-error "https://erp.otospex.dev${NEW_ASSET}" \
    | grep -Fc 'wlota1a-batch-permission-gating-v1' || true
)"
test "$FEATURE_COUNT" -ge 1

curl --fail --silent --show-error \
  https://erp.otospex.dev/build-fingerprint.json \
  | tee "$FINGERPRINT_JSON"

jq -e \
  '.build_sha == env.CANDIDATE_SHA and .build_sha != "unknown"' \
  "$FINGERPRINT_JSON" > /dev/null

EXPECTED_FEATURE_FINGERPRINT="$(
  node apps/web/tools/write-build-fingerprint.mjs --print-fingerprint
)"
test -n "$EXPECTED_FEATURE_FINGERPRINT"
export EXPECTED_FEATURE_FINGERPRINT

jq -e \
  '.feature_fingerprint == env.EXPECTED_FEATURE_FINGERPRINT' \
  "$FINGERPRINT_JSON" > /dev/null

printf 'WLOTA1A-WEB-DEPLOY asset_changed=1 feature_count=%s build_sha=%s feature_fingerprint=%s outcome=PASS\n' \
  "$FEATURE_COUNT" "$CANDIDATE_SHA" "$EXPECTED_FEATURE_FINGERPRINT"
```

Exit rules:

- Before and after asset manifests must each contain exactly one non-empty asset path.
- The asset path must change.
- The served asset must contain `wlota1a-batch-permission-gating-v1` at least once.
- `/build-fingerprint.json` must be valid JSON.
- `build_sha` must equal the promoted `CANDIDATE_SHA` and must not be `unknown`.
- `feature_fingerprint` must equal the local generator output.
- Any curl, grep, `jq`, comparison, or deploy failure blocks activation.

Run only the pre-activation Playwright test while the backend flag remains false.

Activate sequentially—never concurrently—in this exact order:

1. Worker.
2. API.
3. Scheduler.

For each service, set `LOT_ACTION_PERMISSIONS_ENFORCE=true`, redeploy that service, and run:

```bash
set -Eeuo pipefail
SERVICE='worker' # then api, then scheduler

value="$(
  php artisan tinker --no-ansi \
    --execute="fwrite(STDOUT, config('lot_action_permissions.enforce') ? 'ON' : 'OFF');" \
    2>&1
)"

test "$value" = 'ON'

printf 'WLOTA1A-ACTIVATION service=%s expected=ON actual=%s outcome=PASS\n' \
  "$SERVICE" "$value"
```

The captured activation log must equal:

```text
WLOTA1A-ACTIVATION service=worker expected=ON actual=ON outcome=PASS
WLOTA1A-ACTIVATION service=api expected=ON actual=ON outcome=PASS
WLOTA1A-ACTIVATION service=scheduler expected=ON actual=ON outcome=PASS
```

Any missing, duplicate, reordered, failed, or skipped service marker blocks continuation.

After scheduler read-back:

```bash
set -Eeuo pipefail

php artisan config:clear
php artisan config:cache
php artisan permission:cache-reset
php artisan horizon:terminate
php artisan horizon:status \
  2>&1 | tee "/tmp/${SLICE}-horizon-status.log"

test "$(grep -Eci 'running' "/tmp/${SLICE}-horizon-status.log" || true)" -ge 1
test "$(grep -Eci 'FAILED|SKIPPED|ERRORED|inactive|stopped' "/tmp/${SLICE}-horizon-status.log" || true)" -eq 0

printf 'WLOTA1A-HORIZON outcome=PASS\n'
```

Only then:

- Run §11.9 post-activation recensus.
- Prove activated fresh provisioning and activated reseed markers.
- Resume registration.
- Run the post-activation Playwright test.
- Run API authorization smoke.
- Run the manifest checklist.

Rollback order remains worker → API → scheduler with the flag set false. Schema and data are forward-only.

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

./vendor/bin/phpunit -c phpunit.xml \
  tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php
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

The tenancy reviewer must explicitly cite:

- No Task-1 middleware on create/update/transfer/write-off.
- Null-team marked roles rejected on PostgreSQL and SQLite.
- One marker per tenant team.
- Exact A-1b/A-1c boundary.

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

## 15. W-LOT-A-1b and W-LOT-A-1c boundaries

W-LOT-A-1b begins only after this slice is accepted and activated. It owns only:

- Recall request and branch-hold schema.
- Initial status enum/CHECK accepting `requested`, `recalled`, `released`, and `rejected`.
- Request/hold behavior.
- `requested → recalled`.
- Append-only request and recalled evidence.
- Operation UUID, replay, and concurrency for those actions.
- POS and transfer eligibility.
- Company-wide recall service.
- History/capability endpoints needed for request/hold/recalled.
- Request/history UI.
- The existing recall payload defect.

W-LOT-A-1b must not implement:

- `requested → released`.
- `requested → rejected`.
- Release or reject permissions.
- Release or reject authority checks.
- Release or reject reasons/evidence.
- Release or reject routes.
- Release or reject UI.

W-LOT-A-1c begins only after W-LOT-A-1b is accepted. It is the exact and sole owner of:

- `requested → released`.
- `requested → rejected`.
- General-manager-only release and reject authority.
- Mandatory release/reject reason.
- Append-only release/reject evidence.
- Requesting-branch self-release prohibition.
- Release/reject routes.
- Release/reject UI.

A-1a supplies only the prerequisite permission split, unrestricted general-manager invariant, safe batch reads, and trace contracts.

## 16. Dispatch order

1. Record actual `DISPATCH_SHA`; run the repin procedure on drift.
2. Preserve unrelated untracked files.
3. Capture the non-empty Push-1 tenant manifest with raw and normalized evidence.
4. Run exact-once Push-1 day-one census gates.
5. Prove boot permission synchronization is off.
6. Add Task-1 red tests and capture failures.
7. Implement Task 1 behind the false flag.
8. Add middleware only to reads, traceability, delete, and recall.
9. Prove existing create/update/transfer/write-off authorization is unchanged.
10. Run Task-1 tests and both reviewers.
11. Add Task-2 red tests and capture failures.
12. Implement/test the additive migration, null-team constraint, SQLite triggers, and team-scoped partial unique index.
13. Implement legacy-off seeder behavior, canonical delta, team boundaries, and assignment transactions.
14. Run Task-2 PostgreSQL concurrency, command, team, and PostgreSQL/SQLite schema tests.
15. Obtain preliminary reviewer ACCEPT.
16. Capture Push-2 tenants and take one verified non-zero host backup per UUID.
17. Promote Push 2 and verify two exact migration passes per UUID.
18. Promote Push 3 with the flag false in worker/API/scheduler.
19. Prove generic fresh/disposable seeding remains legacy and Push 3 is inert.
20. Recapture the Push-4 tenant manifest.
21. Take one verified non-zero host backup per Push-4 UUID.
22. Apply the explicit delta once per expected tenant.
23. Enforce exact-once apply marker gates.
24. Reset permission cache through the executable gate.
25. Verify once per expected tenant.
26. Run and gate day-one, lot-drift, and phantom summaries per expected tenant.
27. Add Task-6 red tests with exact symbols.
28. Implement routes, navigation, both create links, detail actions, and server-authoritative permissions.
29. Regenerate the map twice and require byte identity with the committed map.
30. Run Vitest, real typecheck scripts, lint, and reviewers.
31. Capture the current web asset and candidate SHA.
32. Deploy Push 5 with the backend flag false.
33. Require changed served asset, served feature string, exact build SHA, and exact feature fingerprint.
34. Run only the pre-activation browser test.
35. Pause registration.
36. Recapture tenants; delta, verify, and census rollout-window additions.
37. Activate worker and require exact `ON` marker.
38. Activate API and require exact `ON` marker.
39. Activate scheduler and require exact `ON` marker.
40. Clear caches and restart Horizon.
41. Recapture tenants and reject any missing or unexpected UUID.
42. Prove activated fresh provisioning and exact activated reseed marker.
43. Resume registration.
44. Run post-activation API and Playwright duplicate/B2/location gates.
45. Run the manifest §4 checklist.
46. Archive SHA, raw/normalized manifests, backups, markers, censuses, snapshots, permission-map hashes, asset evidence, and reviewer verdicts.
47. Dispatch W-LOT-A-1b separately.
48. Dispatch W-LOT-A-1c only after W-LOT-A-1b acceptance.

## 17. Final verification checklist

- [ ] Planning SHA `f7647264caeacd8e6bbb9ee513d41b8d7feca238` is recorded.
- [ ] Actual `DISPATCH_SHA` is recorded and all named seams were re-censused after drift.
- [ ] Only Tasks 1, 2, and 6 were implemented.
- [ ] RD2, Q4, and Q10 remain quoted verbatim.
- [ ] A-1a retains its no-hold boundary.
- [ ] A-1b implements only request/hold and `requested → recalled`.
- [ ] A-1b’s initial enum/CHECK accepts `requested`, `recalled`, `released`, and `rejected`.
- [ ] A-1c is the sole release/reject transition, authority, reason, evidence, route, and UI owner.
- [ ] No A-1b/A-1c schema, behavior, route, or UI leaked into A-1a.
- [ ] Every changed constructor uses the correct FQCN and `private readonly` promotion.
- [ ] NEW command constructors call `parent::__construct()`.
- [ ] Batch write-off services use `Domain\Services`.
- [ ] Identity index/signer use `Modules\Tenant\Application\Services`.
- [ ] Company config uses `App\Services\CompanyConfigService`.
- [ ] `BatchActionAccess` accepts only view, traceability, delete, and recall.
- [ ] No Task-1 middleware was added to create, update, transfer, or write-off.
- [ ] Existing FormRequest authorization for create/update/transfer/write-off is unchanged.
- [ ] Unknown middleware arguments fail closed.
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
- [ ] A marked role cannot have a null team ID.
- [ ] PostgreSQL and SQLite reject global marked roles.
- [ ] The partial unique index permits one marker per tenant team.
- [ ] Unmarked `general_manager` collision fails closed.
- [ ] Role delta lock/read/write/verify is one transaction.
- [ ] Delta captures, sets, and restores Spatie team context.
- [ ] Every assignment writer uses the same transaction/team boundary.
- [ ] Barrier-controlled assignment/narrowing race is green.
- [ ] Marked role cannot be renamed or deleted.
- [ ] Rerun preserves role ID, custom grants, and pivot cardinality.
- [ ] `LotActionPermissionDelta` is the sole role-definition writer.
- [ ] Settings → Users is the sole assignment surface.
- [ ] Settings → Roles is read-only inspection for the protected role.
- [ ] CLI rejects neither/both options with exit 2.
- [ ] Collision/schema failures emit `FAILED` and exit 1.
- [ ] Every fleet phase captures anchored `[Tenant] …: <UUID>` records.
- [ ] Raw and normalized tenant manifests are preserved separately.
- [ ] Every captured tenant UUID is valid.
- [ ] Every expected tenant manifest is non-empty.
- [ ] Raw tenant count equals unique tenant count equals expected count.
- [ ] Executable `comm` checks find no missing or unexpected UUID.
- [ ] Every expected UUID has exactly one success marker.
- [ ] No missing, duplicate, unexpected, `FAILED`, `SKIPPED`, or `ERRORED` marker passed.
- [ ] Push 2 has a verified non-zero backup for every expected UUID.
- [ ] Push 4 has a verified non-zero backup for every expected UUID.
- [ ] Every migration command exits zero with the exact success summary.
- [ ] Database-per-tenant no-op and zero-tenant migration outputs are rejected.
- [ ] Delta apply and verify have exact per-tenant markers and exit rules.
- [ ] No fleet-wide generic reseed ran while enforcement was false.
- [ ] Disposable legacy and activated reseeds have exact markers.
- [ ] Every day-one, lot-drift, and phantom summary is executable and explicitly gated.
- [ ] Manifest §4 appears verbatim.
- [ ] Both existing create affordances use one `canCreate`.
- [ ] Every Task-6 exact Vitest/Playwright symbol exists.
- [ ] Generated map matches the canonical seeder helpers.
- [ ] Two independent map generations are byte-identical.
- [ ] Fresh generated map equals the committed map.
- [ ] `pnpm typecheck` and `pnpm typecheck:e2e` pass.
- [ ] Pre-activation browser evidence contains no flag-on API expectation.
- [ ] Post-activation browser evidence runs only after API activation.
- [ ] Served asset hash changed.
- [ ] Served bundle contains `wlota1a-batch-permission-gating-v1`.
- [ ] Served `build_sha` exactly equals promoted `CANDIDATE_SHA`.
- [ ] Served feature fingerprint equals local generator output.
- [ ] Activation evidence is exactly worker → API → scheduler.
- [ ] `tenancy-authz-reviewer` returned ACCEPT.
- [ ] `inventory-costing-reviewer` returned ACCEPT.
- [ ] `frontend-conventions-reviewer` returned ACCEPT.
- [ ] Reviewer BLOCKER and MAJOR counts are zero.
- [ ] No device build or queue was added.
- [ ] No unrelated untracked file was staged.