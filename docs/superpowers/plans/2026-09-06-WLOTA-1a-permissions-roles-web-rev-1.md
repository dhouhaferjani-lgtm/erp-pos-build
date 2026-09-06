<!-- W-LOT-A-1a rev 1 (split from W-LOT-A-1 rev 5 at c91cbd151: Tasks 1/2/6 only), Codex CLI (gpt-5.6-sol, read-only) 2026-09-06, saved verbatim by the orchestrator. Status: awaiting gate r1 (A-1a). Hold/eligibility/company recall → W-LOT-A-1b. -->
# Slice plan W-LOT-A-1a — lot action permissions, general-manager role delta, web gating (rev 1, split from W-LOT-A-1 rev 5)

## 0. Plan identity

- Plan date: 2026-09-06.

- Repository: `/Users/houssamr/Projects/syneriva/apps/erp`.

- Planning baseline: `0c7bd49fb2ef2138d30786927954b16418fcf99c`.

- The baseline is the repository’s local `dev` HEAD observed immediately before this plan was issued.

- No repository files were changed while preparing this plan.

- No tests were run because the planning sandbox was read-only.

- Existing untracked files are outside this slice and must remain untouched:

  - `apps/api/docs/sessions/2026-08-04-cross-tenant-scheduled-jobs-plan.md`

  - `docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md`

- Before dispatch, run:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
test "$(git rev-parse HEAD)" = "0c7bd49fb2ef2138d30786927954b16418fcf99c"
```

- A failed comparison invalidates the code citations.

- On drift, reread every named source seam and publish a new plan revision before implementation.

- All local `path:line` links below describe the source at baseline `0c7bd49fb2ef2138d30786927954b16418fcf99c`.

## 1. Outcome

This slice delivers only three packets extracted from W-LOT-A-1 rev 5:

1. API action permissions and location-safe batch reads.

2. The seeded role delta and guarded `general_manager` assignment.

3. Web route/action gating and deterministic permission-map regeneration.

The finished slice guarantees:

- Every existing BatchExpiry HTTP action has its intended permission boundary.

- Permission enforcement can ship dormant with `LOT_ACTION_PERMISSIONS_ENFORCE=false`.

- Batch list, detail, stock, expiry, product-stock, POS-suggestion, and trace reads respect company and membership-location scope after activation.

- An unrestricted active membership retains company-wide lot metadata visibility, including zero-stock lots.

- A restricted membership sees only lots attributable to allowed stock or allowed historical trace locations.

- Empty membership scope never means all locations.

- BatchExpiry no longer imports Document or POS persistence models for traceability.

- `manager` loses company-wide `batches.recall`.

- `manager` gains the dormant catalogue permission `batches.recall.request`.

- A marked seeded `general_manager` role gains the revised manager permission set, `batches.recall`, and `treasury.manage_all_locations`.

- `general_manager` assignment is rejected unless every effective active company membership is unrestricted.

- A marked `general_manager` role cannot be renamed or deleted.

- Web batch routes and visible actions use the same permission names as the API.

- The generated permission map is deterministic and current before web deployment.

## 2. Hard scope boundary

### 2.1 Included

- Existing batch action authorization.

- Existing batch read scoping.

- Trace query isolation through Shared contracts.

- Scoped decimal totals in batch responses.

- Permission catalogue additions.

- Seeded role grant changes.

- Marked seeded-role provisioning.

- General-manager assignment and later-location-narrowing guards.

- General-manager rename/delete protection.

- Permission-cache reset and reseed marker production.

- Batch route gating in React.

- Batch navigation and action visibility.

- Permission-map regeneration.

- Five-push staging activation.

### 2.2 Explicitly deferred to W-LOT-A-1b

The following W-LOT-A-1 rev 5 packets are not implemented here:

- Task 3: recall request persistence.

- Task 3: branch hold creation.

- Task 3: recall transition evidence.

- Task 3: global recall evidence.

- Task 4: issue eligibility contracts.

- Task 4: sale exclusion caused by a hold.

- Task 4: transfer exclusion caused by a hold.

- Task 4: company-wide recall eligibility integration.

- Task 5: company-wide recall service rewrite.

- Task 5: recall operation UUID handling.

- Task 5: recall replay handling.

- Task 5: recall concurrency handling.

- Recall request routes.

- Recall history routes.

- Recall capability routes.

- Recall request buttons.

- Recall history UI.

- Hold badges.

- Release behavior.

- Reject behavior.

- Append-only recall evidence.

- Delivery behavior.

- Write-off eligibility behavior.

- Stock-count eligibility behavior.

- Return-scrap eligibility behavior.

- Device/Tauri behavior.

### 2.3 Prohibited incidental behavior

This slice must not:

- Create a recall-request table.

- Create a hold table.

- Add a hold status to a batch.

- Change `Batch::recall()` semantics.

- Change recall request payloads.

- Fix the existing web recall payload key.

- Change inventory quantity.

- Change reservations.

- Change valuation.

- Create stock movements.

- Create journal entries.

- Block sale or transfer because of a hold.

- Add an eligibility response envelope.

- Add a company-wide recall service.

- Add release/reject permissions.

- Add a recall dashboard.

- Add a handwritten web trace DTO.

The existing web recall call currently sends `recall_reason` at [BatchDetailPage.tsx:49](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:49).

That payload defect remains deferred because changing recall behavior belongs to W-LOT-A-1b.

## 3. Owner rulings

### 3.1 RD2, quoted verbatim

> A manager is branch-linked, or is a **general manager** (explicit company-wide role). Recall is a safety action: a branch manager **initiates** a recall for a lot present in their branch; the recall **escalates** to the general manager, who executes it company-wide. Permissions stay tight; escalation, not denial.

Source: [OWNER-RULINGS:9](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:9).

This slice implements only the role and permission split.

The branch request, escalation workflow, and local hold are W-LOT-A-1b.

### 3.2 Q4, quoted verbatim

> CONFIRMED: new seeded `general_manager` role (manager set, no location restriction, `batches.recall`, `treasury.manage_all_locations`).

Source: [OWNER-RULINGS:113](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:113).

This slice implements this ruling completely.

### 3.3 Q10, quoted verbatim

> Hold lifecycle `requested → recalled` or `requested → released`; only the general manager may release or reject; mandatory reason and append-only evidence; the requesting branch never lifts its own hold.

Source: [OWNER-RULINGS:151](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151).

This slice creates no hold lifecycle.

All Q10 behavior remains assigned to W-LOT-A-1b.

## 4. Gate-r5 closure

The governing review is [2026-09-06-w-lot-a-1-slice-codex-gate-r5.md](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r5.md:1).

| Gate item | Closure in this plan |
|---|---|
| B2 | Every task has an exact production/test file inventory and every changed or new symbol signature. |
| B3 | The push ledger is mechanically derived from those inventories; every runtime file occurs in exactly one source push before the flag flip. |
| M3 | Marked `general_manager` roles are protected from update-time rename and deletion. |
| M4 | Tasks 1 and 6 name second-company, second-location, and repeated-mutation evidence with explicit `already_exists`. |
| M5 | The literal convention-11 `Concepts:` line appears below. |
| M6 | `RolesAndPermissionsSeeder::emitWlota1aReseedMarker()` produces exactly one tenant-qualified marker per seeder run. |
| N1 | The plan is pinned to `0c7bd49fb2ef2138d30786927954b16418fcf99c`. |
| N2 | Operative citations point to the statement performing the behavior, not only the enclosing method declaration. |

The review’s B2 requirement is stated at [gate-r5:23](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r5.md:23).

The review’s B3 requirement is stated at [gate-r5:36](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r5.md:36).

The review’s M3 requirement is stated at [gate-r5:78](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r5.md:78).

The review’s M4 requirement is stated at [gate-r5:85](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r5.md:85).

The review’s M5 requirement is stated at [gate-r5:92](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r5.md:92).

The review’s M6 requirement is stated at [gate-r5:99](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r5.md:99).

## 5. Current-state evidence

The outer batch route group already enforces API authentication, permission-team setup, tenant-claim enforcement, and the BatchExpiry module at [routes.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12).

The expiring route lacks an action permission at [routes.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:14).

The list/create/detail/update/delete routes lack route middleware at [routes.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:22).

The recall route lacks route middleware at [routes.php:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:29).

Forward and backward trace routes lack traceability permission middleware at [routes.php:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:38).

Grouped write-off already uses `batches.write-off` at [routes.php:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:18).

Write-off reversal already uses `batches.write-off` at [routes.php:34](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:34).

Company mismatch already becomes 404 at [BatchController.php:68](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:68).

List currently calls an unscoped company repository read at [BatchController.php:97](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:97).

Detail currently loads every location’s batch stock at [BatchController.php:112](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:112).

Duplicate batch creation currently returns no explicit rerun outcome at [BatchController.php:138](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:138).

The exact unvalidated `expiring.location_id` read is [BatchController.php:219](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:219).

The expired endpoint already validates UUID inputs at [BatchController.php:241](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:241).

The expired endpoint already resolves location scope at [BatchController.php:252](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:252).

The exact all-location stock read is [BatchController.php:271](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:271).

Forward trace directly imports Document and POS models at [BatchTraceabilityController.php:11](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:11).

Forward document trace currently queries by batch without an explicit company/location predicate at [BatchTraceabilityController.php:61](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:61).

Forward POS trace currently queries by batch without a location predicate at [BatchTraceabilityController.php:77](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:77).

The repository eagerly loads unfiltered batch stock for product reads at [BatchRepository.php:79](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:79).

The repository eagerly loads unfiltered batch stock for company reads at [BatchRepository.php:116](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:116).

`BatchResource` currently uses potentially unscoped aggregate accessors at [BatchResource.php:39](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:39).

The expiring query filters its existence relation but eagerly loads all stock at [FEFOInventoryService.php:858](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:858).

`LocationContext::getAllowedLocationIds()` returns `[]` when no active membership exists at [LocationContext.php:198](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationContext.php:198).

The same method returns `null` for unrestricted membership at [LocationContext.php:202](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationContext.php:202).

`LocationScopeResolver` rejects requested IDs outside the actor’s allowed set at [LocationScopeResolver.php:41](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationScopeResolver.php:41).

The exact current blanket seeded-role synchronization is [RolesAndPermissionsSeeder.php:547](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:547).

Current manager grants include `batches.recall` at [RolesAndPermissionsSeeder.php:631](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:631).

Current viewer grants begin at [RolesAndPermissionsSeeder.php:705](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:705) and omit `batches.view`.

Role names are tenant-team scoped by the existing unique key at [2025_11_29_231806_create_permission_tables.php:44](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:44).

Role rename protection currently covers only three hard-coded names at [RoleController.php:228](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:228).

Role deletion uses the same incomplete hard-coded set at [RoleController.php:277](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:277).

User creation currently assigns the role before writing the membership at [UserController.php:232](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:232).

User update currently synchronizes roles before writing location scope at [UserController.php:380](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:380).

The exact generated-map `batches.view` entry is [permissionsMap.generated.ts:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/permissionsMap.generated.ts:18).

Current batch routes use the broad `inventory` module-key permission at [routes/index.tsx:1185](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/routes/index.tsx:1185).

The batch navigation item has no permission property at [Sidebar.tsx:222](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/Sidebar/Sidebar.tsx:222).

The batch list create link is unconditional at [BatchListPage.tsx:86](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchListPage.tsx:86).

Batch detail action booleans currently consider state but not authorization at [BatchDetailPage.tsx:75](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:75).

`RequirePermission` performs an exact permission check at [RequirePermission.tsx:55](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/auth/components/RequirePermission.tsx:55).

## 6. Industry baseline — convention 10

Flow under comparison: permissioned lot reads, location-restricted visibility, central recall authority, and deterministic role provisioning.

Sources:

- [Odoo 18 lots documentation](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/product_management/product_tracking/lots.html).

- [Odoo access-rights documentation](https://www.odoo.com/documentation/18.0/applications/general/users/access_rights.html).

- [OCA Stock Lock Lot 18.0](https://github.com/OCA/stock-logistics-workflow/tree/18.0/stock_lock_lot).

- [ERPNext Batch documentation](https://docs.frappe.io/erpnext/batch).

- [ERPNext User Permissions documentation](https://docs.frappe.io/erpnext/user-permissions).

- Dolibarr is marked `NV` where no directly verified equivalent contract supports a claim.

| ID | Guarantee | Odoo | ERPNext | Dolibarr/NV | AutoERP today path:line | Gap | Decision MATCH/DEFER/DIVERGE/ALREADY |
|---|---|---|---|---|---|---|---|
| create | Creating a lot requires explicit action authority | Access rights and groups restrict model operations | Roles and permissions restrict document operations | NV — exact batch-create authority not verified | Route is exposed without route middleware at [routes.php:23](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:23) | Route-level action policy is not explicit | MATCH |
| duplicate | Retrying lot creation returns a meaningful duplicate outcome | Lot identity is controlled by tracked-product configuration | Batch identity is explicit | NV | Duplicate returns only an error at [BatchController.php:138](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:138) | No `already_exists` outcome | MATCH |
| edit | Lot metadata changes require update authority | Model write access is group-controlled | Batch edits follow role permissions | NV | Update uses a FormRequest but the route has no explicit action middleware at [routes.php:25](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:25) | Route matrix is incomplete | MATCH |
| cancel | Lot deactivation requires delete authority | Unlink/write authority is explicit | Delete/cancel authority is role-controlled | NV | Delete route has no explicit middleware at [routes.php:26](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:26) | Body-less delete bypasses FormRequest authorization | MATCH |
| rerun | Permission provisioning is deterministic and preserves unrelated tenant grants | Module updates preserve configured access records | Role configuration is persisted | NV | Existing roles are blindly synchronized at [RolesAndPermissionsSeeder.php:547](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:547) | Rerun can erase custom grants and has no stable result marker | DIVERGE |
| second company | Company B cannot observe company A’s lot | Company/database record rules isolate stock | Company and User Permission filters isolate records | NV | Detail already rejects company mismatch at [BatchController.php:68](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:68) | Trace adapters require the same explicit company predicate | MATCH |
| second location | Branch users see only attributable branch stock and trace | Warehouse/location record rules can restrict stock | Warehouse User Permissions restrict records | NV | Detail loads all location rows at [BatchController.php:112](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:112) | Membership scope is not threaded through all batch reads | MATCH |
| permission | Company-wide recall belongs to central authority | OCA lock authority is assigned through security groups | Role permission plus unrestricted warehouse access models central authority | NV | Manager currently receives global recall at [RolesAndPermissionsSeeder.php:631](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:631) | No distinct unrestricted general-manager role | MATCH |
| audit | Role assignment retains actor, target, company, role, and time | Administrative changes are access-controlled | Role changes are administrative operations | NV | Dedicated assignment emits `RoleAssigned` at [RoleController.php:365](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:365) | Role-side audit exists; recall evidence does not | ALREADY |

Recall-request, hold, eligibility, and recall-evidence comparator decisions are DEFER to W-LOT-A-1b.

No deferred guarantee is represented as implemented by W-LOT-A-1a.

## 7. Vocabulary — convention 11

The glossary schema is defined at [docs/glossary.md:10](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:10).

The canonical lot row is [docs/glossary.md:41](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:41).

The canonical Location row is [docs/glossary.md:19](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:19).

The canonical Membership row is [docs/glossary.md:20](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:20).

The required literal line is:

Concepts: Lot (batch) (glossary ✅), Location (glossary ✅), Membership (glossary ✅), General manager (NEW — glossary row added in this lane).

Add this exact five-column glossary row:

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| **General manager** | A seeded tenant role containing the revised manager grants plus company-wide lot recall and all-location Treasury authority; a holder must have unrestricted active company memberships. | Existing Spatie `roles`, marked by `roles.provisioning_source` / Identity | Existing Settings → Users and Settings → Roles surfaces | `general_manager`, central manager |

Do not add Recall request, Branch hold, Recall transition, or Global recall evidence rows in this slice.

Those concepts first become real in W-LOT-A-1b.

## 8. Shared behavioral contracts

### 8.1 Activation contract

- Config key: `lot_action_permissions.enforce`.

- Environment key: `LOT_ACTION_PERMISSIONS_ENFORCE`.

- Default: `false`.

- Push 3 must read `false` in API, worker, and scheduler containers.

- Push 3 route middleware must pass through without evaluating the new permission.

- Push 3 batch reads must preserve their pre-activation location semantics.

- Push 3 duplicate responses must preserve their old payload.

- Push 5 deploys the gated web bundle before changing the flag.

- Push 5 activates worker configuration first.

- Push 5 activates API configuration second.

- The flag is read through Laravel configuration only.

- Application code must not call `env()` directly.

### 8.2 Location cardinality contract

- `null` means an unrestricted active company membership.

- `[]` means no visible locations.

- A non-empty list means exactly those allowed locations.

- `[]` must never be translated into an omitted query predicate.

- User-supplied location IDs are UUID-validated.

- Requested location IDs pass through `LocationScopeResolver`.

- No Treasury bypass permission is passed into `LocationScopeResolver`.

- Company mismatch remains indistinguishable from absence.

### 8.3 Lot visibility contract

For an unrestricted active membership:

- Company lot metadata remains visible with zero stock.

- Detail for a new zero-stock lot returns 200.

- `batch_stock` is an empty list for that lot.

- Depleted historical trace remains visible.

- Historical records with a null location remain visible.

For a restricted active membership:

- A lot is visible when current stock exists at an allowed location.

- A depleted lot is visible when trace history is attributable to an allowed location.

- Only allowed stock rows are serialized.

- A zero-stock lot with no attributable history returns 404.

- A trace record with null location is excluded.

For empty membership scope:

- List returns no lots.

- Detail returns 404.

- Stock returns no rows.

- Expiring and expired return no lots.

- Product batch stock returns no lots.

- POS suggestions reject the requested location.

- Forward and backward traces return no occurrences.

### 8.4 Decimal aggregate contract

- `BatchResource` sums only its already-loaded `batchStock` relation.

- It does not execute a fresh relation query.

- It returns decimal strings at scale four.

- It does not cast quantities to float.

- `total_quantity` is physical on-hand quantity.

- `available_quantity` is on-hand minus reserved quantity.

- Neither field becomes hold-aware in this slice.

### 8.5 Trace ownership contract

- BatchExpiry owns endpoint orchestration and response shape.

- Document owns Document persistence queries.

- POS owns receipt-allocation persistence queries.

- Cross-module data crosses only through `App\Shared\Contracts\BatchTraceability`.

- BatchExpiry must not import `App\Modules\Document\Domain\Document`.

- BatchExpiry must not import `App\Modules\Document\Domain\DocumentLine`.

- BatchExpiry must not import `App\Modules\POS\Domain\ReceiptLineBatchAllocation`.

### 8.6 Permission route matrix

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
| POST `/api/v1/batches` | existing `CreateBatchRequest` authorization plus `batches.create` middleware |
| PATCH `/api/v1/batches/{uuid}` | existing `UpdateBatchRequest` authorization plus `batches.update` middleware |
| DELETE `/api/v1/batches/{uuid}` | `batches.delete` |
| POST `/api/v1/batches/{uuid}/recall` | `batches.recall` |
| POST `/api/v1/batches/{uuid}/transfer` | existing `TransferBatchStockRequest` authorization |
| POST `/api/v1/batches/{uuid}/write-off` | existing write-off request authorization plus `batches.write-off` |
| POST `/api/v1/batches/write-off-grouped` | existing `batches.write-off` |
| POST `/api/v1/stock-movements/{movementId}/reverse-write-off` | existing `batches.write-off` |

No `batches.recall.request` route is added.

## 9. Task 1 — API action permissions and location-safe reads

### 9.1 Dependencies

- No schema dependency.

- Existing company context.

- Existing membership location semantics.

- Existing BatchExpiry module gate.

### 9.2 Production files to add

- `apps/api/config/lot_action_permissions.php`

- `apps/api/app/Modules/BatchExpiry/Application/Services/LotActionPermissionActivation.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php`

- `apps/api/app/Shared/Contracts/BatchTraceability/BatchTraceOccurrenceData.php`

- `apps/api/app/Shared/Contracts/BatchTraceability/DocumentBatchTraceReader.php`

- `apps/api/app/Shared/Contracts/BatchTraceability/PosBatchTraceReader.php`

- `apps/api/app/Modules/Document/Infrastructure/BatchTraceability/DocumentBatchTraceReaderAdapter.php`

- `apps/api/app/Modules/POS/Infrastructure/BatchTraceability/PosBatchTraceReaderAdapter.php`

### 9.3 Production files to modify

- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php`

- `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`

- `apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php`

- `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`

### 9.4 New config file

`apps/api/config/lot_action_permissions.php` has no namespace and returns exactly:

```php
<?php

declare(strict_types=1);

return [
    'enforce' => (bool) env('LOT_ACTION_PERMISSIONS_ENFORCE', false),
];
```

### 9.5 New activation symbol

File: `apps/api/app/Modules/BatchExpiry/Application/Services/LotActionPermissionActivation.php`.

Namespace: `App\Modules\BatchExpiry\Application\Services`.

Full declarations:

```php
final readonly class LotActionPermissionActivation
{
    public function __construct(
        private \Illuminate\Contracts\Config\Repository $config,
    );

    public function enforced(): bool;
}
```

Binding: Laravel constructor auto-resolution.

### 9.6 New middleware symbol

File: `apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php`.

Namespace: `App\Modules\BatchExpiry\Presentation\Middleware`.

Full declarations:

```php
final readonly class BatchActionAccess
{
    public function __construct(
        private \App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation $activation,
    );

    public function handle(
        \Illuminate\Http\Request $request,
        \Closure $next,
        string $permission,
    ): \Symfony\Component\HttpFoundation\Response;
}
```

Binding: the route declarations use the middleware’s class name directly.

Allowed middleware arguments are exactly:

```text
batches.view
batches.traceability
batches.create
batches.update
batches.delete
batches.recall
batches.write-off
```

Unknown arguments return 403.

When enforcement is false, the middleware immediately invokes `$next($request)`.

When enforcement is true, it returns 403 unless the authenticated user has the exact permission.

### 9.7 New trace DTO

File: `apps/api/app/Shared/Contracts/BatchTraceability/BatchTraceOccurrenceData.php`.

Namespace: `App\Shared\Contracts\BatchTraceability`.

Full declaration:

```php
final readonly class BatchTraceOccurrenceData
{
    public function __construct(
        public int $batchId,
        public string $source,
        public string $sourceId,
        public string $sourceNumber,
        public string $occurredOn,
        public ?string $locationId,
        public ?string $partnerId,
        public ?string $partnerName,
        public string $productId,
        public string $productName,
        public string $quantity,
        public ?string $batchNumber,
    );
}
```

This DTO is PHP-only.

It is not transformed into a web type.

### 9.8 New Document trace contract

File: `apps/api/app/Shared/Contracts/BatchTraceability/DocumentBatchTraceReader.php`.

Namespace: `App\Shared\Contracts\BatchTraceability`.

Full declaration:

```php
interface DocumentBatchTraceReader
{
    /** @return list<BatchTraceOccurrenceData> */
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
    ): array;

    /** @return list<BatchTraceOccurrenceData> */
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

### 9.9 New POS trace contract

File: `apps/api/app/Shared/Contracts/BatchTraceability/PosBatchTraceReader.php`.

Namespace: `App\Shared\Contracts\BatchTraceability`.

Full declaration:

```php
interface PosBatchTraceReader
{
    /** @return list<BatchTraceOccurrenceData> */
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

### 9.10 New Document adapter

File: `apps/api/app/Modules/Document/Infrastructure/BatchTraceability/DocumentBatchTraceReaderAdapter.php`.

Namespace: `App\Modules\Document\Infrastructure\BatchTraceability`.

Full declaration:

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

The adapter applies tenant and company predicates before reading lines.

Its location predicate implements `DocumentLine::effectiveLocationId()` semantics.

A line-level location takes precedence.

The document location is used only when the line location is null.

A null effective location is included only when `$locationIds === null`.

### 9.11 New POS adapter

File: `apps/api/app/Modules/POS/Infrastructure/BatchTraceability/PosBatchTraceReaderAdapter.php`.

Namespace: `App\Modules\POS\Infrastructure\BatchTraceability`.

Full declaration:

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

The adapter applies receipt tenant, company, and persisted location predicates.

A receipt with null location is included only for unrestricted reads.

### 9.12 Provider binding changes

In `DocumentServiceProvider`, modify:

```php
public function register(): void;
```

Add exactly this binding:

```php
$this->app->bind(
    \App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader::class,
    \App\Modules\Document\Infrastructure\BatchTraceability\DocumentBatchTraceReaderAdapter::class,
);
```

In `POSServiceProvider`, modify:

```php
public function register(): void;
```

Add exactly this binding:

```php
$this->app->bind(
    \App\Shared\Contracts\BatchTraceability\PosBatchTraceReader::class,
    \App\Modules\POS\Infrastructure\BatchTraceability\PosBatchTraceReaderAdapter::class,
);
```

### 9.13 Repository interface changes

Modify these signatures in `BatchRepositoryInterface`:

```php
public function findVisibleByUuid(
    string $uuid,
    string $companyId,
    ?array $locationIds,
    array $historicallyVisibleBatchIds = [],
): ?\App\Modules\BatchExpiry\Domain\Entities\Batch;

/** @return \Illuminate\Support\Collection<int, Batch> */
public function getByProduct(
    string $tenantId,
    string $companyId,
    string $productId,
    bool $activeOnly = true,
    ?array $locationIds = null,
): \Illuminate\Support\Collection;

/** @return \Illuminate\Support\Collection<int, Batch> */
public function getByCompany(
    string $companyId,
    array $filters = [],
    ?array $locationIds = null,
    array $historicallyVisibleBatchIds = [],
): \Illuminate\Support\Collection;
```

Existing mutation signatures remain unchanged.

### 9.14 Repository implementation changes

Modify the same three signatures in `BatchRepository`.

`findVisibleByUuid()` must:

- Require UUID and company match.

- Include every company lot when `$locationIds === null`.

- Require allowed current stock or a historical-visible ID when `$locationIds` is a list.

- Return null when `$locationIds === []` and no historical ID matches.

- Eager-load only permitted stock rows.

`getByCompany()` must:

- Preserve existing filters.

- Include zero-stock company lots for unrestricted readers.

- For restricted readers, require allowed stock or an ID supplied in `$historicallyVisibleBatchIds`.

- Filter the eager-loaded `batchStock` relation with the same location list.

`getByProduct()` must:

- Preserve tenant, company, product, active, FEFO, and deterministic ID ordering.

- Include unrestricted zero-stock metadata.

- Require permitted stock for restricted reads.

- Filter eager-loaded stock using the same location list.

### 9.15 Batch controller changes

Modify the constructor signature:

```php
public function __construct(
    \App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface $batchRepository,
    \App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService $fefoService,
    \App\Modules\Company\Services\CompanyContext $companyContext,
    \App\Modules\BatchExpiry\Application\Services\BatchStockService $batchStockService,
    \App\Modules\BatchExpiry\Application\Services\BatchWriteOffService $batchWriteOffService,
    \App\Modules\BatchExpiry\Application\Services\ReverseWriteOffService $reverseWriteOffService,
    \App\Modules\Company\Services\LocationScopeResolver $locationScopeResolver,
    \App\Modules\Company\Services\LocationContext $locationContext,
    \App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation $activation,
    \App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader $documentTraceReader,
    \App\Shared\Contracts\BatchTraceability\PosBatchTraceReader $posTraceReader,
);
```

Add these private methods:

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

Modify these public symbols:

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

When activation is false:

- `resolvedReadLocationIds()` returns null for newly scoped endpoints.

- Existing expired-endpoint behavior remains unchanged.

- Duplicate creation keeps the existing response.

When activation is true:

- Duplicate creation keeps HTTP 422 and adds `meta.outcome = "already_exists"`.

- `expiring.location_id` is validated as a UUID.

- Requested locations are resolved without a bypass permission.

- Restricted list/detail includes historically attributable depleted lots.

- POS suggestions reject an inaccessible location with 403.

### 9.16 Trace controller changes

Modify the constructor:

```php
public function __construct(
    \App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface $batchRepository,
    \App\Modules\Company\Services\CompanyContext $companyContext,
    \App\Modules\Company\Services\LocationContext $locationContext,
    \App\Modules\Company\Services\LocationScopeResolver $locationScopeResolver,
    \App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation $activation,
    \App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader $documentTraceReader,
    \App\Shared\Contracts\BatchTraceability\PosBatchTraceReader $posTraceReader,
);
```

Modify the endpoint signatures:

```php
public function forwardTrace(
    \Illuminate\Http\Request $request,
    string $uuid,
): \Illuminate\Http\JsonResponse;

public function backwardTrace(
    \Illuminate\Http\Request $request,
    string $partnerId,
): \Illuminate\Http\JsonResponse;
```

Add:

```php
private function resolvedTraceLocationIds(
    \Illuminate\Http\Request $request,
): ?array;
```

The controller composes DTOs only.

Remove every Document/POS domain-model import from this controller.

### 9.17 Resource changes

Modify:

```php
public function toArray(
    \Illuminate\Http\Request $request,
): array;
```

Add:

```php
private function scopedTotalQuantity(): string;

private function scopedAvailableQuantity(): string;
```

Both helpers operate only on the loaded relation.

Both helpers use four-decimal string arithmetic.

### 9.18 FEFO service changes

Modify:

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

`getExpiredBatchesWithStock()` retains its current signature.

Its existing `[]` fail-closed behavior remains.

The expiring existence predicate and eager-load predicate must use the same resolved location list.

### 9.19 Test files to add

- `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php`

- `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php`

- `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php`

- `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php`

- `apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php`

### 9.20 New test symbols

Namespace `Tests\Feature\BatchExpiry`:

```php
final class BatchActionPermissionsTest extends \Tests\TestCase
{
    public function test_each_existing_batch_route_requires_its_exact_action_permission(): void;

    public function test_flag_off_preserves_pre_activation_access(): void;
}
```

```php
final class BatchReadLocationScopeTest extends \Tests\TestCase
{
    public function test_detail_excludes_other_branch_stock(): void;

    public function test_every_read_filters_other_branch_and_empty_scope(): void;

    public function test_zero_stock_company_lot_is_visible_only_to_unrestricted_actor(): void;

    public function test_depleted_lot_is_visible_only_through_attributable_history(): void;

    public function test_second_company_second_location_and_duplicate_create_are_isolated(): void;
}
```

```php
final class BatchExpiringLocationScopeTest extends \Tests\TestCase
{
    public function test_expiring_validates_uuid_and_filters_loaded_stock(): void;

    public function test_empty_membership_scope_returns_no_expiring_lots(): void;
}
```

```php
final class BatchTraceReaderContractTest extends \Tests\TestCase
{
    public function test_document_and_pos_adapters_apply_company_and_location_scope(): void;

    public function test_nullable_location_is_visible_only_to_unrestricted_membership(): void;
}
```

Namespace `Tests\Architecture`:

```php
final class BatchTraceabilityModuleBoundaryTest extends \PHPUnit\Framework\TestCase
{
    public function test_batch_expiry_traceability_imports_only_shared_contracts(): void;
}
```

### 9.21 Red-first assertions

| Exact file | Class::method | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | `BatchActionPermissionsTest::test_each_existing_batch_route_requires_its_exact_action_permission` | `$response->assertForbidden();` for recall by a user lacking `batches.recall` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchActionPermissionsTest::test_each_existing_batch_route_requires_its_exact_action_permission'` | phpunit PostgreSQL |
| `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | `BatchReadLocationScopeTest::test_detail_excludes_other_branch_stock` | `self::assertNotContains($locationA2Id, array_column($response->json('data.batch_stock'), 'location_id'));` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_detail_excludes_other_branch_stock'` | phpunit PostgreSQL |
| `apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php` | `BatchTraceabilityModuleBoundaryTest::test_batch_expiry_traceability_imports_only_shared_contracts` | `self::assertSame([], $violations, implode(PHP_EOL, $violations));` | `cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchTraceabilityModuleBoundaryTest::test_batch_expiry_traceability_imports_only_shared_contracts'` | phpunit no-DB |

Each first red runs against existing classes and routes.

None relies on a missing new class, missing migration, or missing table.

### 9.22 Convention-09 evidence

Convention 09 requires real second-company creation, a second POS-enabled location, and a repeated mutation with explicit outcome at [09-SECOND-OF-EVERYTHING.md:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37).

The test `BatchReadLocationScopeTest::test_second_company_second_location_and_duplicate_create_are_isolated()` must:

1. Create company A through the existing test setup.

2. Register company B through `POST /api/v1/companies`.

3. Use the real payload shape demonstrated at [CreateCompanyTest.php:75](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Company/CreateCompanyTest.php:75).

4. Create location A1 through `POST /api/v1/locations`.

5. Create location A2 through `POST /api/v1/locations`.

6. Set `pos_enabled=true` on A2.

7. Create the same product SKU and lot number independently in companies A and B.

8. Assert company A never reads company B’s lot.

9. Give a restricted actor only A1.

10. Assert no A2 stock or trace identifier appears.

11. POST the same company-A lot creation a second time.

12. Assert the second response is 422.

13. Assert `meta.outcome` is `already_exists`.

14. Assert exactly one company-A lot row exists.

15. Assert no quantity, reservation, or trace row changed on rerun.

This closes M4 for Task 1.

### 9.23 Task 1 implementation order

1. Add the three assertion-red tests.

2. Capture the named assertion output.

3. Add the activation config and reader.

4. Add `BatchActionAccess`.

5. Apply the exact middleware matrix.

6. Add Shared trace contracts.

7. Add Document and POS adapters.

8. Bind each adapter in its owning module.

9. Remove cross-module model imports.

10. Add repository scope parameters.

11. Thread resolved locations through list/detail/stock/expiry/product/POS reads.

12. Preserve unrestricted zero-stock visibility.

13. Add restricted historical visibility.

14. Filter eager-loaded stock.

15. Replace unscoped resource totals.

16. Add the explicit `already_exists` outcome behind the flag.

17. Run focused PostgreSQL tests.

18. Run the architecture test.

19. Run Task 1 convention-09 evidence.

20. Submit to `tenancy-authz-reviewer`.

### 9.24 Task 1 reviewer gate

`tenancy-authz-reviewer` must return ACCEPT with no BLOCKER or MAJOR for:

- Middleware argument allowlist.

- Flag-off pass-through.

- Company isolation.

- Empty-scope behavior.

- Unrestricted zero-stock behavior.

- Restricted historical visibility.

- Nullable trace locations.

- DTO boundary.

- Provider bindings.

- Scoped decimal totals.

- Absence of hold or eligibility behavior.

### 9.25 Task 1 rollback

Before activation:

- Revert the Task 1 Push-3 commit if needed.

- Leave the flag false.

After activation:

- Emergency-disable with `LOT_ACTION_PERMISSIONS_ENFORCE=false`.

- Redeploy worker, scheduler, and API with the false value.

- Clear and rebuild configuration cache.

- Reset the permission cache.

- Do not roll back location-safe code with a destructive database operation.

- Record that disabling the flag temporarily restores legacy authorization/read behavior.

## 10. Task 2 — role delta and guarded general-manager assignment

### 10.1 Dependencies

- The additive role marker migration.

- The existing Spatie role tables.

- Existing `AssignableRole` validation, whose subset check occurs at [AssignableRole.php:46](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Rules/AssignableRole.php:46).

### 10.2 Production file to add — migration

- `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php`

### 10.3 Other production files to add

- `apps/api/app/Modules/Identity/Domain/Enums/SystemRoleName.php`

- `apps/api/app/Modules/Identity/Domain/Enums/RoleProvisioningSource.php`

- `apps/api/app/Modules/Identity/Domain/Enums/LotActionPermissionDeltaOutcome.php`

- `apps/api/app/Modules/Identity/Application/DTOs/LotActionPermissionDeltaResult.php`

- `apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php`

- `apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php`

- `apps/api/app/Console/Commands/ApplyLotActionPermissionDelta.php`

### 10.4 Production files to modify

- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`

- `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php`

- `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`

- `apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php`

- `docs/glossary.md`

### 10.5 Complete migration schema

Migration class form: anonymous migration, no namespace.

Full symbols:

```php
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void;

    public function down(): void;
};
```

Target table:

```text
roles
```

Column:

| Column | SQL type | Nullable | Default | Foreign key | Index |
|---|---|---:|---|---|---|
| `provisioning_source` | `VARCHAR(32)` | yes | SQL NULL | none | none |

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

SQLite trigger names:

```text
roles_provisioning_source_insert_guard
roles_provisioning_source_update_guard
```

`up()` must:

1. Assert `roles` exists.

2. Add the nullable column only when absent.

3. If present, verify type, length, nullability, and default.

4. Inspect the PostgreSQL constraint by exact name.

5. Fail if an existing constraint has a different normalized definition.

6. Install the two SQLite triggers with equivalent checks.

7. Be a no-op only when the exact schema already exists.

`down()` must:

1. Refuse while any row has non-null `provisioning_source`.

2. Drop only the named PostgreSQL constraint or two named SQLite triggers.

3. Drop only `provisioning_source`.

4. Never delete a role.

5. Never delete a permission.

6. Never delete a role-permission pivot.

### 10.6 PHP role-name enum

File: `apps/api/app/Modules/Identity/Domain/Enums/SystemRoleName.php`.

Namespace: `App\Modules\Identity\Domain\Enums`.

Full declaration:

```php
enum SystemRoleName: string
{
    case GeneralManager = 'general_manager';
}
```

This enum is the only new code-level spelling source for the role name.

It is not transformed to TypeScript.

### 10.7 Provisioning-source enum

File: `apps/api/app/Modules/Identity/Domain/Enums/RoleProvisioningSource.php`.

Namespace: `App\Modules\Identity\Domain\Enums`.

Full declaration:

```php
enum RoleProvisioningSource: string
{
    case Wlota1a = 'w-lot-a-1a';
}
```

### 10.8 Delta-outcome enum

File: `apps/api/app/Modules/Identity/Domain/Enums/LotActionPermissionDeltaOutcome.php`.

Namespace: `App\Modules\Identity\Domain\Enums`.

Full declaration:

```php
enum LotActionPermissionDeltaOutcome: string
{
    case Applied = 'APPLIED';
    case AlreadyApplied = 'ALREADY_APPLIED';
    case Skipped = 'SKIPPED';
    case Failed = 'FAILED';
}
```

### 10.9 Delta result DTO

File: `apps/api/app/Modules/Identity/Application/DTOs/LotActionPermissionDeltaResult.php`.

Namespace: `App\Modules\Identity\Application\DTOs`.

Full declaration:

```php
final readonly class LotActionPermissionDeltaResult
{
    public function __construct(
        public \App\Modules\Identity\Domain\Enums\LotActionPermissionDeltaOutcome $outcome,
        public string $reason,
    );
}
```

### 10.10 Permission delta service

File: `apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php`.

Namespace: `App\Modules\Identity\Application\Services`.

Full declaration:

```php
final readonly class LotActionPermissionDelta
{
    public function __construct(
        private \Spatie\Permission\PermissionRegistrar $permissionRegistrar,
    );

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
    ): \Spatie\Permission\Models\Role;

    private function synchronizeSeededRole(
        \Spatie\Permission\Models\Role $role,
        array $permissions,
        bool $newRole,
    ): void;
}
```

Binding: Laravel constructor auto-resolution.

`acquireTenantLock()` uses a tenant-derived PostgreSQL transaction advisory lock.

The SQLite test path relies on its transaction lock.

An unmarked existing `general_manager` is a collision.

The service must not adopt it.

The service must not expose a force option.

A marked role is reused with the same bigint ID.

Existing seeded roles receive missing canonical permissions.

Existing unrelated custom permissions are preserved.

The one explicit revocation is `manager` losing `batches.recall`.

### 10.11 Resulting permission catalogue

Add exactly:

```text
batches.recall.request
treasury.manage_all_locations
```

`batches.recall.request` has no route or hold effect in this slice.

`treasury.manage_all_locations` has no new Treasury route behavior in this slice.

### 10.12 Resulting seeded matrix

| Seeded role | Resulting lot/Treasury delta |
|---|---|
| `admin` | All permission names, including request, global recall, and all-location Treasury |
| `general_manager` | Revised manager set plus `batches.recall` and `treasury.manage_all_locations` |
| `manager` | `batches.view`, create, update, delete, write-off, traceability, request; no global recall |
| `cashier` | `batches.view` |
| `viewer` | `batches.view` |
| `operator` | `batches.view` |
| `technician` | No new batch grant |
| `accountant` | No new batch grant |

### 10.13 Seeder changes

Modify:

```php
public function __construct(
    \Spatie\Permission\PermissionRegistrar $permissionRegistrar,
    \App\Modules\Identity\Application\Services\LotActionPermissionDelta $lotActionPermissionDelta,
);

public function run(): void;

private function createPermissions(): void;

private function createRoles(
    string $tenantId,
): \App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult;

/** @return list<string> */
public static function permissionNames(): array;

/** @return array<string, list<string>> */
public static function rolePermissionGrants(): array;

private function emitWlota1aReseedMarker(
    string $tenantId,
    \App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult $result,
): void;
```

`run()` must emit exactly one marker:

```text
WLOTA1A-RESEED tenant=<uuid> outcome=APPLIED|ALREADY_APPLIED reason=<token>
```

The producer is `RolesAndPermissionsSeeder::emitWlota1aReseedMarker()`.

A failed application throws after emitting no success marker.

The deployment gate requires one success marker per tenant.

### 10.14 Delta command

File: `apps/api/app/Console/Commands/ApplyLotActionPermissionDelta.php`.

Namespace: `App\Console\Commands`.

Full declaration:

```php
final class ApplyLotActionPermissionDelta extends \Illuminate\Console\Command
{
    protected $signature = 'permissions:apply-lot-action-delta
        {--apply : Apply the permission and role delta}
        {--verify : Verify without writing}';

    protected $description =
        'Apply or verify W-LOT-A-1a lot-action permissions and the seeded general-manager role.';

    public function __construct(
        private readonly \App\Modules\Identity\Application\Services\LotActionPermissionDelta $delta,
    );

    public function handle(): int;
}
```

Register this command by modifying:

```php
\App\Modules\BatchExpiry\BatchExpiryServiceProvider::boot(): void;
```

Add `ApplyLotActionPermissionDelta::class` to the existing explicit command list.

Command marker:

```text
WLOTA1A-PERMISSIONS tenant=<uuid> outcome=APPLIED|ALREADY_APPLIED|SKIPPED|FAILED reason=<token>
```

Exit codes:

| Exit | Meaning |
|---:|---|
| 0 | APPLIED, ALREADY_APPLIED, or intentional SKIPPED |
| 1 | Collision, missing schema, or invariant failure |
| 2 | Invalid option combination |

`--apply --verify` returns 2.

`--verify` never writes.

No option with the word `force` is added.

### 10.15 General-manager assignment guard

File: `apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php`.

Namespace: `App\Modules\Identity\Application\Services`.

Full declaration:

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

Binding: Laravel constructor auto-resolution.

The guard throws validation errors with HTTP 422 semantics.

The role field error code is `GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP`.

The location field error uses the same code.

The guard locks the target user first.

It locks active memberships in ascending company-ID order.

It evaluates the merged final state.

An omitted request field uses persisted state.

An explicit null location list means unrestricted.

An empty list is restricted and is rejected for a general manager.

Atomic demotion plus narrowing is allowed when the final role set no longer includes `general_manager`.

### 10.16 User controller changes

Modify the constructor:

```php
public function __construct(
    \App\Modules\Company\Services\CompanyContext $companyContext,
    \App\Modules\Company\Services\LocationContext $locationContext,
    \App\Modules\Identity\Application\Services\IdentityIndexService $identityIndexService,
    \App\Modules\Identity\Application\Services\TenantLinkSigner $tenantLinkSigner,
    \App\Modules\Identity\Application\Services\GeneralManagerAssignmentGuard $generalManagerAssignmentGuard,
);
```

Modify:

```php
public function store(
    \App\Modules\Identity\Presentation\Requests\CreateUserRequest $request,
): \Illuminate\Http\JsonResponse;

public function update(
    \App\Modules\Identity\Presentation\Requests\UpdateUserRequest $request,
    string $id,
): \Illuminate\Http\JsonResponse;
```

Create order:

1. Create the target user inside the transaction.

2. Create the active company membership.

3. Write its effective location scope.

4. Lock the user and active memberships.

5. Calculate the final role set.

6. Invoke `assertAssignable()`.

7. Set the permission team.

8. Assign the role.

9. Write existing audit data.

10. Commit.

11. Send invitation work after commit.

Update order:

1. Lock the target user.

2. Lock active memberships by company ID.

3. Merge omitted fields with persisted state.

4. Compute final roles and location scope.

5. Invoke `assertAssignable()` or `assertLocationChangeAllowed()`.

6. Write membership changes.

7. Synchronize roles in the same transaction.

8. Write existing audit data.

9. Commit.

### 10.17 Role controller changes

Modify the constructor:

```php
public function __construct(
    \App\Modules\Company\Services\CompanyContext $companyContext,
    \App\Modules\Company\Services\CompanyConfigService $configService,
    \App\Modules\Identity\Application\Services\GeneralManagerAssignmentGuard $generalManagerAssignmentGuard,
);
```

Modify:

```php
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
```

Add:

```php
private function isProtectedSystemRole(
    \Spatie\Permission\Models\Role $role,
): bool;
```

`isProtectedSystemRole()` returns true for:

- Existing protected names `super-admin`, `admin`, and `owner`.

- `name=general_manager`, `guard_name=sanctum`, and `provisioning_source=w-lot-a-1a`.

Update rejects any rename of a protected role.

Delete rejects a protected role even when it has zero users.

Dedicated assignment:

- Requires an existing active membership in the current company.

- Locks the target and memberships.

- Computes the final role set.

- Calls `GeneralManagerAssignmentGuard::assertAssignable()`.

- Retains the existing `AssignableRole` permission-subset rule.

- Retains the existing `RoleAssigned` event.

### 10.18 Task 2 test files

Add:

- `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php`

- `apps/api/tests/Feature/Identity/LotActionPermissionDeltaTest.php`

- `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php`

- `apps/api/tests/Feature/Identity/GeneralManagerRoleProtectionTest.php`

- `apps/api/tests/Feature/Identity/GeneralManagerAssignmentWriterCensusTest.php`

- `apps/api/tests/Feature/Console/LotActionReseedMarkerTest.php`

- `apps/api/tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php`

### 10.19 New Task 2 test symbols

Namespace `Tests\Feature\Identity`:

```php
final class LotActionSeededRoleMatrixTest extends \Tests\TestCase
{
    public function test_viewer_and_operator_gain_batch_view(): void;

    public function test_manager_loses_global_recall_and_gains_request(): void;

    public function test_general_manager_has_the_ruled_permission_delta(): void;
}
```

```php
final class LotActionPermissionDeltaTest extends \Tests\TestCase
{
    public function test_first_apply_and_second_apply_have_explicit_outcomes(): void;

    public function test_rerun_preserves_role_id_and_custom_permissions(): void;

    public function test_unmarked_general_manager_collision_fails_closed(): void;

    public function test_concurrent_first_apply_creates_one_marked_role(): void;

    public function test_verify_is_read_only(): void;
}
```

```php
final class GeneralManagerAssignmentTest extends \Tests\TestCase
{
    public function test_create_update_and_dedicated_assignment_reject_restricted_membership(): void;

    public function test_unrestricted_assignment_succeeds_after_membership_creation(): void;

    public function test_general_manager_cannot_later_be_location_narrowed(): void;

    public function test_atomic_demotion_and_location_narrowing_succeeds(): void;

    public function test_second_company_membership_must_also_be_unrestricted(): void;
}
```

```php
final class GeneralManagerRoleProtectionTest extends \Tests\TestCase
{
    public function test_marked_general_manager_cannot_be_renamed(): void;

    public function test_marked_general_manager_cannot_be_deleted(): void;

    public function test_delta_rerun_after_protection_preserves_role_identity(): void;
}
```

```php
final class GeneralManagerAssignmentWriterCensusTest extends \Tests\TestCase
{
    public function test_runtime_general_manager_assignment_sites_use_the_guard(): void;
}
```

Namespace `Tests\Feature\Console`:

```php
final class LotActionReseedMarkerTest extends \Tests\TestCase
{
    public function test_seeder_emits_one_tenant_qualified_applied_marker(): void;

    public function test_reseed_emits_one_already_applied_marker(): void;
}
```

Namespace `Tests\Feature\Migrations`:

```php
final class RoleProvisioningSourceSchemaTest extends \Tests\TestCase
{
    public function test_postgresql_schema_matches_exact_contract(): void;

    public function test_sqlite_triggers_reject_invalid_markers(): void;

    public function test_down_refuses_while_a_marked_role_exists(): void;
}
```

### 10.20 Task 2 red-first assertions

| Exact file | Class::method | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php` | `LotActionSeededRoleMatrixTest::test_viewer_and_operator_gain_batch_view` | `self::assertContains('batches.view', $grants['viewer']);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotActionSeededRoleMatrixTest::test_viewer_and_operator_gain_batch_view'` | phpunit PostgreSQL |
| `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php` | `LotActionSeededRoleMatrixTest::test_manager_loses_global_recall_and_gains_request` | `self::assertNotContains('batches.recall', $grants['manager']);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotActionSeededRoleMatrixTest::test_manager_loses_global_recall_and_gains_request'` | phpunit PostgreSQL |

Both assertions inspect the existing static seeder matrix.

Neither red depends on a missing migration, enum, service, command, or table.

### 10.21 Task 2 convention-09 evidence

`GeneralManagerAssignmentTest::test_second_company_membership_must_also_be_unrestricted()` must:

1. Register company B using `POST /api/v1/companies`.

2. Create a second `pos_enabled` location in company B.

3. Give the target unrestricted membership in company A.

4. Give the target restricted membership in company B.

5. Attempt `general_manager` assignment.

6. Assert 422 and `GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP`.

`LotActionPermissionDeltaTest::test_first_apply_and_second_apply_have_explicit_outcomes()` must:

1. Apply the delta once.

2. Assert `APPLIED`.

3. Apply the identical delta again.

4. Assert `ALREADY_APPLIED`.

5. Assert one marked role.

6. Assert the same role ID.

7. Assert no duplicate permission pivots.

8. Assert unrelated custom grants remain.

This supplies Task 2’s second-company and rerun proof.

### 10.22 Task 2 implementation order

1. Add static matrix red tests.

2. Capture the two assertion failures.

3. Add the self-guarding migration.

4. Add the three enums.

5. Add the result DTO.

6. Add the permission delta service.

7. Add permission names.

8. Change the manager grant set.

9. Add viewer/operator batch view.

10. Add the general-manager role grant set.

11. Add collision detection.

12. Add role marker writes.

13. Add deterministic rerun behavior.

14. Add the command.

15. Register the command.

16. Add the seeder marker producer.

17. Add the assignment guard.

18. Reorder user creation.

19. Reorder user update.

20. Guard dedicated role assignment.

21. Protect marked role rename.

22. Protect marked role deletion.

23. Add writer census.

24. Run PostgreSQL concurrency coverage.

25. Run SQLite schema-trigger coverage.

26. Submit to `tenancy-authz-reviewer`.

### 10.23 Task 2 reviewer gate

`tenancy-authz-reviewer` must return ACCEPT with no BLOCKER or MAJOR for:

- Tenant-team role identity.

- Unmarked collision handling.

- Advisory lock identity.

- Rerun preservation of custom grants.

- Explicit manager recall revocation.

- General-manager marker integrity.

- Every assignment path.

- Later membership narrowing.

- Lock order.

- Rename protection.

- Delete protection.

- Seeder marker cardinality.

- Absence of recall/hold behavior.

### 10.24 Task 2 rollback

Before applying the role delta:

- Revert Task 2 runtime code if required.

- The nullable marker column may remain.

After applying the role delta:

- Do not run migration `down()` while a marked role exists.

- Do not delete the marked role.

- Correct permission grants with a forward delta.

- Preserve the marked role ID.

- Reset the permission cache after any corrective delta.

- If web activation must be delayed, leave `LOT_ACTION_PERMISSIONS_ENFORCE=false`.

## 11. Task 6 — web routes/actions gating and permission-map regeneration

### 11.1 Dependencies

- Task 2’s final permission catalogue and role grant matrix.

- A freshly regenerated `permissionsMap.generated.ts`.

- No Task 3, Task 4, or Task 5 artifact.

### 11.2 Web files to modify

- `apps/web/src/routes/index.tsx`

- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`

- `apps/web/src/features/batches/pages/BatchListPage.tsx`

- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`

- `apps/web/src/hooks/usePermissions.ts`

- `apps/web/src/hooks/permissionsMap.generated.ts`

- `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx`

- `apps/web/src/hooks/__tests__/usePermissions.moduleAccess.test.tsx`

### 11.3 Web files to add

- `apps/web/src/routes/__tests__/BatchRoutePermissions.test.tsx`

- `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx`

- `apps/web/src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts`

- `apps/web/e2e/batch-permissions.spec.ts`

No additional hook file is permitted.

No handwritten API type is permitted.

No locale file changes are needed.

No recall-history component is added.

### 11.4 Route symbol changes

In `apps/web/src/routes/index.tsx`, add:

```ts
export const WLOTA1A_WEB_FINGERPRINT =
  'wlota1a-batch-permission-gating-v1' as const
```

Modify:

```ts
export function AppRoutes()
```

Use exact route gates:

| Route | Existing module gate | New permission |
|---|---|---|
| `/inventory/batches` | `BatchExpiry` | `batches.view` |
| `/inventory/batches/:uuid` | `BatchExpiry` | `batches.view` |
| `/inventory/batches/new` | `BatchExpiry` | `batches.create` |
| `/inventory/batches/:uuid/edit` | `BatchExpiry` | `batches.update` |
| `/inventory/expiry-write-off` | `BatchExpiry` | retain `batches.write-off` |

Attach the fingerprint to the batch route through:

```tsx
handle={{ featureFingerprint: WLOTA1A_WEB_FINGERPRINT }}
```

This ensures the unique string is retained in the built asset.

### 11.5 Sidebar symbol changes

Modify:

```ts
function buildNavigation(isAutomotiveVertical: boolean): NavModule[]
```

The existing batch child becomes:

```ts
{
  key: 'batches',
  href: '/inventory/batches',
  icon: Pill,
  module: 'BatchExpiry',
  permission: 'batches.view',
}
```

`Sidebar()` and its filtering logic remain structurally unchanged.

### 11.6 Batch list symbol changes

Modify:

```ts
export function BatchListPage()
```

Use the existing `usePermissions()` hook.

Render the create link only when:

```ts
hasPermission('batches.create')
```

Do not disable the link while leaving it focusable.

Do not add another create surface.

### 11.7 Batch detail symbol changes

Modify:

```ts
export function BatchDetailPage()
```

Use the existing `usePermissions()` hook.

The final booleans are:

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

Do not add a request-recall button for managers.

Do not modify `handleRecall()`.

Do not add hold or eligibility state.

### 11.8 Permission-hook symbol changes

Modify:

```ts
const SERVER_AUTHORITATIVE_PERMISSIONS: Set<Permission>
```

Add all batch action permissions to that set:

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

Modify:

```ts
export const MODULE_PERMISSIONS
```

Add:

```ts
'batches.view': ['batches.view']
```

Modify:

```ts
export function usePermissions()
```

The server permission payload remains authoritative.

A missing batch permission in a present server payload must not be restored from a role-name fallback.

### 11.9 Generated permission map

Modify only through the exporter:

```ts
export const PERMISSIONS
export type Permission = keyof typeof PERMISSIONS
```

Expected relevant entries:

| Permission | Generated roles |
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

Regenerate with:

```bash
cd apps/api
php artisan permissions:export-frontend-map
```

Run it twice and require byte-identical output:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
cp apps/web/src/hooks/permissionsMap.generated.ts /tmp/wlota1a-permissions-map-first.ts
cd apps/api
php artisan permissions:export-frontend-map
cd ..
diff /tmp/wlota1a-permissions-map-first.ts web/src/hooks/permissionsMap.generated.ts
```

The existing exporter derives its data from the seeder at [ExportFrontendPermissionsMap.php:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:29).

### 11.10 Task 6 test cases

`BatchRoutePermissions.test.tsx` covers:

- A user with `inventory.view` but without `batches.view` cannot open list or detail.

- A user with `batches.view` can open list and detail.

- `batches.view` alone cannot open create.

- `batches.create` can open create.

- `batches.view` alone cannot open edit.

- `batches.update` can open edit.

- A disabled BatchExpiry module hides every batch route regardless of permissions.

`BatchPermissions.test.tsx` covers:

- Create link hidden without `batches.create`.

- Edit hidden without `batches.update`.

- Delete hidden without `batches.delete`.

- Recall hidden from the revised manager payload.

- Recall visible to general manager with `batches.recall`.

- State predicates still hide actions even when permission exists.

`BatchSeededPermissionMap.test.ts` covers the exact generated-role table above.

Modify `Sidebar.test.tsx` to cover:

- Batch navigation hidden without `batches.view`.

- Batch navigation visible with `batches.view` and BatchExpiry enabled.

- Batch navigation hidden when BatchExpiry is disabled.

Modify `usePermissions.moduleAccess.test.tsx` to cover:

- `batches.view` is a valid `ModuleKey`.

- A present server permission list is authoritative.

- Role fallback does not restore a missing batch permission.

### 11.11 Task 6 red-first assertions

| Exact file | Test | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/web/src/routes/__tests__/BatchRoutePermissions.test.tsx` | direct list route without `batches.view` | `expect(screen.queryByText(/product batches/i)).not.toBeInTheDocument()` | `cd apps/web && pnpm vitest run src/routes/__tests__/BatchRoutePermissions.test.tsx` | Vitest |
| `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx` | revised manager cannot recall | `expect(screen.queryByRole('button', { name: /recall/i })).not.toBeInTheDocument()` | `cd apps/web && pnpm vitest run src/features/batches/pages/__tests__/BatchPermissions.test.tsx` | Vitest |
| `apps/web/src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts` | viewer receives batch view | `expect(PERMISSIONS['batches.view']).toContain('viewer')` | `cd apps/web && pnpm vitest run src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts` | Vitest |

The route red fails because the current route uses broad `inventory` permission.

The action red fails because current page actions ignore authorization.

The map red fails against the existing generated map.

None fails because a new component or hook is missing.

### 11.12 Task 6 convention-09 browser evidence

File: `apps/web/e2e/batch-permissions.spec.ts`.

The Playwright file contains one describe block named:

```text
W-LOT-A-1a batch permissions
```

Its journey must:

1. Authenticate as a tenant administrator.

2. Register company B through `POST /api/v1/companies`.

3. Create location B1 through `POST /api/v1/locations`.

4. Create a second `pos_enabled` location B2 through the same API.

5. Create the same lot label already used in company A.

6. Repeat the company-B create request.

7. Assert the second response has `meta.outcome=already_exists`.

8. Assert company B contains one matching lot.

9. Switch to an A1-restricted viewer.

10. Assert A2 stock is absent.

11. Assert batch list remains reachable because viewer has `batches.view`.

12. Assert create, edit, delete, and recall actions are absent.

13. Switch to unrestricted general manager.

14. Assert recall is visible.

15. Assert no request-recall or hold surface exists.

This closes the requested Task 6 convention-09 evidence.

### 11.13 Task 6 implementation order

1. Add the three web assertion-red tests.

2. Capture their existing-state failures.

3. Add the exact route permissions.

4. Add the route fingerprint.

5. Gate Sidebar batch navigation.

6. Gate list creation.

7. Gate detail actions.

8. Make batch permissions server-authoritative.

9. Regenerate the permission map.

10. Regenerate it again and compare bytes.

11. Run focused Vitest coverage.

12. Run TypeScript checking.

13. Run lint.

14. Run the Playwright convention-09 journey.

15. Submit to `frontend-conventions-reviewer`.

16. Submit the combined API/web boundary to `tenancy-authz-reviewer`.

### 11.14 Task 6 reviewer gate

`frontend-conventions-reviewer` must return ACCEPT with no BLOCKER or MAJOR for:

- Exact route permissions.

- Module and permission gates remaining independent.

- Server-authoritative permission behavior.

- No duplicate permission helper.

- No new handwritten API type.

- No inaccessible disabled action.

- No recall-request or hold surface.

- Generated map provenance.

- Served-bundle fingerprint.

`tenancy-authz-reviewer` must also approve:

- API/web permission-name parity.

- Revised manager behavior.

- General-manager behavior.

- Direct-route denial.

### 11.15 Task 6 rollback

Before the backend flag flip:

- Revert the web commit and redeploy only if the deployed bundle is defective.

After the backend flag flip:

- Prefer a forward web correction.

- If emergency backend rollback is required, set `LOT_ACTION_PERMISSIONS_ENFORCE=false`.

- Do not restore manager’s `batches.recall` merely to compensate for a web defect.

- Do not hand-edit `permissionsMap.generated.ts`.

## 12. Complete source-push ledger

This ledger is mechanically derived from Tasks 1, 2, and 6.

Each listed file appears in exactly one source push.

Push 1 contains no source file.

### 12.1 Push 2 — additive schema

- `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php`

- `apps/api/tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php`

### 12.2 Push 3 — dormant backend runtime and backend verification

- `apps/api/config/lot_action_permissions.php`

- `apps/api/app/Modules/BatchExpiry/Application/Services/LotActionPermissionActivation.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php`

- `apps/api/app/Shared/Contracts/BatchTraceability/BatchTraceOccurrenceData.php`

- `apps/api/app/Shared/Contracts/BatchTraceability/DocumentBatchTraceReader.php`

- `apps/api/app/Shared/Contracts/BatchTraceability/PosBatchTraceReader.php`

- `apps/api/app/Modules/Document/Infrastructure/BatchTraceability/DocumentBatchTraceReaderAdapter.php`

- `apps/api/app/Modules/POS/Infrastructure/BatchTraceability/PosBatchTraceReaderAdapter.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php`

- `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`

- `apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php`

- `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`

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

- `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php`

- `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php`

- `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php`

- `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php`

- `apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php`

- `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php`

- `apps/api/tests/Feature/Identity/LotActionPermissionDeltaTest.php`

- `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php`

- `apps/api/tests/Feature/Identity/GeneralManagerRoleProtectionTest.php`

- `apps/api/tests/Feature/Identity/GeneralManagerAssignmentWriterCensusTest.php`

- `apps/api/tests/Feature/Console/LotActionReseedMarkerTest.php`

### 12.3 Push 4 — operations only

- No source file.

- Run the delta, reseed, cache reset, marker checks, and censuses.

### 12.4 Push 5 — web bundle before the flag flip

- `apps/web/src/routes/index.tsx`

- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`

- `apps/web/src/features/batches/pages/BatchListPage.tsx`

- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`

- `apps/web/src/hooks/usePermissions.ts`

- `apps/web/src/hooks/permissionsMap.generated.ts`

- `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx`

- `apps/web/src/hooks/__tests__/usePermissions.moduleAccess.test.tsx`

- `apps/web/src/routes/__tests__/BatchRoutePermissions.test.tsx`

- `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx`

- `apps/web/src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts`

- `apps/web/e2e/batch-permissions.spec.ts`

The flag flip occurs only after this web artifact is deployed and fingerprinted.

No runtime file occurs in two pushes.

No runtime file is absent from the ledger.

## 13. Deployment variables

> Deployment follows `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`
> (five-push sequence §2, web block §3, gate checklist §4). This slice supplies only the variables
> below; it does not restate deploy mechanics.

| Variable | W-LOT-A-1a value |
|---|---|
| `<slice>` | `wlota1a-permissions-roles-web` |
| Migrations list | `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php` — additive, self-guarding, prerequisite |
| Flags | `lot_action_permissions.enforce` / `LOT_ACTION_PERMISSIONS_ENFORCE` / `apps/api/config/lot_action_permissions.php` / `false` |
| Commands | `permissions:apply-lot-action-delta` under `tenants:run`, marker `WLOTA1A-PERMISSIONS`; `tenants:seed --class='Database\Seeders\RolesAndPermissionsSeeder'`, marker `WLOTA1A-RESEED`; existing `permission:cache-reset`; existing `permissions:export-frontend-map`, marker `Exported frontend permission map` |
| Censuses | Push 1 and Push 4: `tenant:census-day-one --option='fail-on-drift=1'`; Push 4: `inventory:lot-drift-census --all-tenants --fail-on-drift` and `inventory:repair-phantom-default-batches --all-tenants --dry-run`; fail on `DRIFT(` or non-zero lot/phantom findings |
| Web changes | yes — `wlota1a-batch-permission-gating-v1` |
| Device build | no |
| Queues | none |
| Collapsed pushes | none; Push 1 and Push 4 are operational pushes with no source artifact |
| Env path | Dokploy Environment tab |

The manifest’s variable contract is at [staging manifest:273](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:273).

The Dokploy Environment tab path is selected for this plan.

Verify that topology before Push 3 using the manifest’s U-1 check at [staging manifest:294](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:294).

Do not modify `docker-compose.staging.yml` in this slice.

## 14. Push-specific acceptance

### 14.1 Push 1

Run the day-one census through every tenant:

```bash
php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1' \
  2>&1 | tee /tmp/wlota1a-permissions-roles-web-census-p1.log
```

Gate:

```bash
test "$(grep -c 'DRIFT(' /tmp/wlota1a-permissions-roles-web-census-p1.log)" = "0"
```

Persist the output outside the deployment container.

Confirm `SYNC_PERMISSIONS_ON_BOOT` is false:

```bash
php artisan tinker --execute="echo getenv('SYNC_PERMISSIONS_ON_BOOT') ?: 'UNSET';"
```

Accepted values:

```text
false
0
UNSET
```

Any truthy value blocks Push 2.

This closes U-6 from [staging manifest:299](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:299).

### 14.2 Push 2

Take and verify a non-zero host-side backup before promotion.

Deploy only the migration and its schema test.

Run:

```bash
php artisan tenants:migrate-rolling --force
```

Then rerun for one captured tenant ID:

```bash
php artisan tenants:migrate-rolling --force --tenant="$TENANT_ID"
```

Verify:

- The nullable column exists in every tenant.

- The PostgreSQL constraint has the exact definition.

- No role row is marked yet.

- A rerun is a no-op.

- There is no role/permission behavior change.

Rollback is forward-only.

### 14.3 Push 3

Set in the Dokploy worker, scheduler, and API environments:

```text
LOT_ACTION_PERMISSIONS_ENFORCE=false
```

Read back in worker:

```bash
php artisan tinker --execute="echo config('lot_action_permissions.enforce') ? 'ON' : 'OFF';"
```

Expected:

```text
OFF
```

Read back in API with the same command.

Expected:

```text
OFF
```

Read back in scheduler with the same command.

Expected:

```text
OFF
```

Prove inertness:

- A pre-activation authorization fixture receives the same status and payload.

- A restricted detail fixture retains the pre-activation response.

- A duplicate create retains the pre-activation payload.

- No seeded role changes before Push 4.

- No `general_manager` role exists because of application boot.

### 14.4 Push 4

Apply the delta per tenant:

```bash
php artisan tenants:run permissions:apply-lot-action-delta --option='apply=1' \
  2>&1 | tee /tmp/wlota1a-permissions-roles-web-delta.log
```

Require one successful delta marker per captured tenant:

```bash
grep 'WLOTA1A-PERMISSIONS tenant=' \
  /tmp/wlota1a-permissions-roles-web-delta.log
```

Run the real reseed:

```bash
php artisan tenants:seed --force \
  --class='Database\Seeders\RolesAndPermissionsSeeder' \
  2>&1 | tee /tmp/wlota1a-permissions-roles-web-reseed.log
```

Require one reseed marker per tenant:

```bash
grep 'WLOTA1A-RESEED tenant=' \
  /tmp/wlota1a-permissions-roles-web-reseed.log
```

Require only:

```text
outcome=APPLIED
outcome=ALREADY_APPLIED
```

Reset cached permissions:

```bash
php artisan permission:cache-reset
```

Verify the delta read-only:

```bash
php artisan tenants:run permissions:apply-lot-action-delta --option='verify=1' \
  2>&1 | tee /tmp/wlota1a-permissions-roles-web-verify.log
```

Require every verification marker to contain:

```text
outcome=ALREADY_APPLIED
```

Rerun the seeder once more.

Require every marker to contain:

```text
outcome=ALREADY_APPLIED
```

Run censuses:

```bash
php artisan inventory:lot-drift-census --all-tenants --fail-on-drift \
  2>&1 | tee /tmp/wlota1a-permissions-roles-web-lotdrift.log
```

```bash
php artisan inventory:repair-phantom-default-batches --all-tenants --dry-run \
  2>&1 | tee /tmp/wlota1a-permissions-roles-web-phantom.log
```

```bash
php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1' \
  2>&1 | tee /tmp/wlota1a-permissions-roles-web-census-p4.log
```

Compare the Push-1 and Push-4 day-one census outputs.

The permission delta may differ.

Catalogue, quantities, balances, and lot-drift findings may not differ.

### 14.5 Permission-map build gate

Before committing Push 5:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
php artisan permissions:export-frontend-map
```

Copy the first output:

```bash
cp ../web/src/hooks/permissionsMap.generated.ts \
  /tmp/wlota1a-permissions-map-first.ts
```

Regenerate:

```bash
php artisan permissions:export-frontend-map
```

Compare:

```bash
diff /tmp/wlota1a-permissions-map-first.ts \
  ../web/src/hooks/permissionsMap.generated.ts
```

The diff must be empty.

Run the existing freshness test:

```bash
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php
```

### 14.6 Push 5 web deployment block

Keep the backend flag false while deploying web.

Capture the current asset:

```bash
curl -s https://erp.otospex.dev/ \
  | grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' \
  | sort -u \
  | tee /tmp/wlota1a-permissions-roles-web-web-before.txt
```

Deploy the staging web application explicitly.

Staging web application ID:

```text
mY6P_PHb4pw-2LdG1Y7Ml
```

Capture the new asset:

```bash
curl -s https://erp.otospex.dev/ \
  | grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' \
  | sort -u \
  | tee /tmp/wlota1a-permissions-roles-web-web-after.txt
```

Require a non-empty asset difference:

```bash
test -n "$(
  diff \
    /tmp/wlota1a-permissions-roles-web-web-before.txt \
    /tmp/wlota1a-permissions-roles-web-web-after.txt
)"
```

Capture the served asset path:

```bash
NEW_ASSET="$(
  head -1 /tmp/wlota1a-permissions-roles-web-web-after.txt
)"
export NEW_ASSET
```

Require the fingerprint in the served bundle:

```bash
test "$(
  curl -s "https://erp.otospex.dev${NEW_ASSET}" \
    | grep -c 'wlota1a-batch-permission-gating-v1'
)" -ge 1
```

Run the staging Playwright file:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/web
pnpm exec playwright test e2e/batch-permissions.spec.ts --project=chromium
```

Only after the web checks pass, set in the worker environment:

```text
LOT_ACTION_PERMISSIONS_ENFORCE=true
```

Redeploy worker and verify `ON`.

Set the same value in the scheduler environment.

Redeploy scheduler and verify `ON`.

Set the same value in the API environment.

Redeploy API and verify `ON`.

Then run:

```bash
php artisan config:clear
php artisan config:cache
php artisan permission:cache-reset
php artisan horizon:terminate
php artisan horizon:status
```

Run API authorization smoke for:

- Viewer list success.

- Viewer create failure.

- Manager request permission present.

- Manager global recall failure.

- General-manager global recall success.

- Restricted A1 actor not seeing A2 stock.

- Company A actor not seeing company B lots.

No recall mutation is executed during smoke against production-like data.

Use disposable fixtures only.

## 15. Complete verification commands

### 15.1 Task 1 API suite

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/BatchExpiry/BatchActionPermissionsTest.php \
  tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php \
  tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php \
  tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php
```

```bash
./vendor/bin/phpunit -c phpunit.xml \
  tests/Architecture/BatchTraceabilityModuleBoundaryTest.php
```

### 15.2 Task 2 API suite

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/Identity/LotActionSeededRoleMatrixTest.php \
  tests/Feature/Identity/LotActionPermissionDeltaTest.php \
  tests/Feature/Identity/GeneralManagerAssignmentTest.php \
  tests/Feature/Identity/GeneralManagerRoleProtectionTest.php \
  tests/Feature/Identity/GeneralManagerAssignmentWriterCensusTest.php \
  tests/Feature/Console/LotActionReseedMarkerTest.php \
  tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php
```

### 15.3 Existing API regressions

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php
```

Run the repository’s complete API PostgreSQL lane after the focused suites.

### 15.4 Web unit suite

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/web
pnpm vitest run \
  src/routes/__tests__/BatchRoutePermissions.test.tsx \
  src/features/batches/pages/__tests__/BatchPermissions.test.tsx \
  src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts \
  src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx \
  src/hooks/__tests__/usePermissions.moduleAccess.test.tsx
```

### 15.5 Web static checks

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/web
pnpm type-check
```

```bash
pnpm lint
```

### 15.6 Web browser suite

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/web
pnpm exec playwright test e2e/batch-permissions.spec.ts --project=chromium
```

## 16. Cross-task reviewer gate

Dispatch `tenancy-authz-reviewer` after Tasks 1 and 2 are green.

Dispatch `frontend-conventions-reviewer` after Task 6 and map regeneration are green.

Then dispatch both reviewers against the combined diff.

Required combined verdict:

```text
ACCEPT
```

Required unresolved findings:

```text
BLOCKER=0
MAJOR=0
```

`tenancy-authz-reviewer` must explicitly cite:

- Route permission parity.

- Flag-off inertness.

- Flag-on location scoping.

- Empty membership behavior.

- Trace contract isolation.

- General-manager collision handling.

- Assignment writer census.

- Rename/delete protection.

- Reseed marker production.

`frontend-conventions-reviewer` must explicitly cite:

- Route guards.

- Navigation guard.

- Action guards.

- Server-authoritative permission behavior.

- Generated map.

- Fingerprint retention.

- Absence of hold UI.

A reviewer rejection returns the affected task to red/green/refactor.

It does not authorize adding W-LOT-A-1b behavior.

## 17. Slice-level rollback

### 17.1 Preferred rollback

- Correct forward.

- Preserve the additive schema.

- Preserve the marked general-manager role identity.

- Preserve permission-audit events.

- Regenerate the web permission map from the corrected seeder.

### 17.2 Emergency enforcement rollback

Set:

```text
LOT_ACTION_PERMISSIONS_ENFORCE=false
```

Then:

1. Redeploy worker.

2. Redeploy scheduler.

3. Redeploy API.

4. Clear and rebuild configuration cache.

5. Reset the permission cache.

6. Leave the web bundle gated.

7. Record the temporary legacy API exposure.

### 17.3 Role-delta rollback prohibition

Do not:

- Delete `general_manager`.

- Rename `general_manager`.

- Clear its `provisioning_source`.

- Restore manager global recall through an ad hoc database edit.

- Roll back the role-marker migration over marked rows.

- Hand-edit Spatie pivot rows in staging.

A required grant correction is a new forward delta with explicit verification.

## 18. Follow-up slice W-LOT-A-1b

W-LOT-A-1b begins only after this slice is accepted and activated.

Its starting dependencies are:

- `batches.recall.request` exists.

- Manager holds request permission but not global recall.

- General manager holds global recall.

- General-manager memberships are unrestricted.

- API batch reads are location-safe.

- Trace readers use Shared contracts.

W-LOT-A-1b owns:

- Recall request schema.

- Local branch hold.

- Append-only request evidence.

- Append-only transition evidence.

- Global recall evidence.

- Eligibility contracts.

- POS issue refusal.

- Transfer refusal.

- Company-wide recall service.

- Recall replay.

- Recall concurrency.

- Recall history and capability endpoints.

- Recall request/history web surfaces.

- Release and reject follow-up required by Q10.

## 19. Dispatch order

1. Verify HEAD equals `0c7bd49fb2ef2138d30786927954b16418fcf99c`.

2. Preserve the two unrelated untracked files.

3. Run Push-1 censuses.

4. Prove `SYNC_PERMISSIONS_ON_BOOT` is false.

5. Implement Task 1 red-first tests.

6. Implement Task 1 runtime packet behind the false flag.

7. Run Task 1 focused tests.

8. Implement Task 2 red-first matrix tests.

9. Implement and test the additive migration.

10. Implement Task 2 role delta and guard packet.

11. Run Task 2 focused and concurrency tests.

12. Obtain preliminary `tenancy-authz-reviewer` ACCEPT.

13. Promote Push 2.

14. Verify migration success and exact schema.

15. Promote Push 3.

16. Prove API, worker, and scheduler read the flag as OFF.

17. Prove Push-3 behavior is inert.

18. Run Task 2 delta and reseed operations in Push 4.

19. Reset permission caches.

20. Rerun delta and reseed.

21. Verify `ALREADY_APPLIED` and marker cardinality.

22. Run Push-4 censuses.

23. Implement Task 6 web red-first tests.

24. Implement route, navigation, and action gating.

25. Regenerate the permission map twice.

26. Run web unit, type, lint, and browser tests.

27. Obtain `frontend-conventions-reviewer` ACCEPT.

28. Obtain combined `tenancy-authz-reviewer` ACCEPT.

29. Promote Push 5 with the flag still false.

30. Deploy and fingerprint the web bundle.

31. Run web smoke.

32. Activate worker configuration.

33. Activate scheduler configuration.

34. Activate API configuration.

35. Clear caches and restart Horizon.

36. Run post-activation authorization and isolation smoke.

37. Archive deployment logs, markers, census outputs, and reviewer verdicts.

38. Dispatch W-LOT-A-1b separately.

## 20. Final verification checklist

- [ ] HEAD was exactly `0c7bd49fb2ef2138d30786927954b16418fcf99c` at dispatch.

- [ ] Every cited source seam was reread after any HEAD drift.

- [ ] Only Tasks 1, 2, and 6 from W-LOT-A-1 rev 5 were implemented.

- [ ] No recall-request schema exists in this slice.

- [ ] No hold behavior exists in this slice.

- [ ] No eligibility behavior exists in this slice.

- [ ] No company-wide recall service rewrite exists in this slice.

- [ ] The outer API/auth/tenant/module middleware stack remains.

- [ ] Every batch route has the exact intended action authorization.

- [ ] Unknown batch middleware permission arguments fail closed.

- [ ] Push 3 reads the enforcement flag as OFF in all three containers.

- [ ] Push 5 deploys web before the enforcement flag becomes ON.

- [ ] Empty location scope returns no records.

- [ ] Unrestricted location scope retains zero-stock company lots.

- [ ] Restricted detail excludes other-location stock.

- [ ] Restricted detail can retain an allowed-location depleted trace lot.

- [ ] Nullable historical locations are company-only.

- [ ] Company B never returns company A lots or trace rows.

- [ ] Expiring UUID validation returns 422 for invalid UUIDs.

- [ ] Expiring existence and eager-load predicates use the same scope.

- [ ] POS suggestions reject a location outside membership scope.

- [ ] Batch totals are decimal strings at scale four.

- [ ] Batch totals use only loaded scoped rows.

- [ ] BatchExpiry imports no Document persistence model.

- [ ] BatchExpiry imports no POS persistence model.

- [ ] Document adapter is bound in `DocumentServiceProvider`.

- [ ] POS adapter is bound in `POSServiceProvider`.

- [ ] Duplicate create returns `meta.outcome=already_exists` after activation.

- [ ] The duplicate create produces one lot row.

- [ ] `batches.recall.request` exists but has no route or behavior.

- [ ] `treasury.manage_all_locations` exists without unrelated Treasury changes.

- [ ] Manager no longer has `batches.recall`.

- [ ] Manager has `batches.recall.request`.

- [ ] Viewer and operator have `batches.view`.

- [ ] General manager has the revised manager set.

- [ ] General manager has `batches.recall`.

- [ ] General manager has `treasury.manage_all_locations`.

- [ ] General-manager role name is represented by the PHP enum.

- [ ] General-manager provisioning source is represented by the PHP enum.

- [ ] Unmarked `general_manager` collision fails closed.

- [ ] Marked role rerun preserves its bigint ID.

- [ ] Rerun preserves unrelated custom permissions.

- [ ] Rerun creates no duplicate pivots.

- [ ] Restricted general-manager assignment returns 422.

- [ ] Every active company membership is checked.

- [ ] Later location narrowing is rejected while the role remains.

- [ ] Atomic demotion plus narrowing succeeds.

- [ ] Dedicated assignment uses the same guard.

- [ ] Marked general manager cannot be renamed.

- [ ] Marked general manager cannot be deleted.

- [ ] Protection remains effective with zero assigned users.

- [ ] Migration down refuses while a marked role exists.

- [ ] Seeder emits one `WLOTA1A-RESEED` marker per tenant.

- [ ] First application reports APPLIED.

- [ ] Second application reports ALREADY_APPLIED.

- [ ] Permission cache reset ran after reseeding.

- [ ] Batch list/detail routes require `batches.view`.

- [ ] Batch create route requires `batches.create`.

- [ ] Batch edit route requires `batches.update`.

- [ ] Batch navigation requires module plus `batches.view`.

- [ ] Create link requires `batches.create`.

- [ ] Edit action requires `batches.update`.

- [ ] Delete action requires `batches.delete`.

- [ ] Recall action requires `batches.recall`.

- [ ] Revised manager cannot see the recall action.

- [ ] General manager can see the recall action.

- [ ] No request-recall action appears.

- [ ] Server permission payload is authoritative for batch permissions.

- [ ] Generated permission map matches the seeder.

- [ ] A second map generation is byte-identical.

- [ ] Served web asset hash changed.

- [ ] Served bundle contains `wlota1a-batch-permission-gating-v1`.

- [ ] Task 1 convention-09 test uses real company registration.

- [ ] Task 1 convention-09 test uses a second POS-enabled location.

- [ ] Task 1 convention-09 test repeats a mutation and asserts `already_exists`.

- [ ] Task 6 Playwright evidence repeats those three checks.

- [ ] `tenancy-authz-reviewer` returned ACCEPT.

- [ ] `frontend-conventions-reviewer` returned ACCEPT.

- [ ] Reviewer BLOCKER count is zero.

- [ ] Reviewer MAJOR count is zero.

- [ ] Host-side backup exists and is non-zero.

- [ ] Push-1 and Push-4 census artifacts are archived.

- [ ] Lot drift remains zero.

- [ ] Phantom DEFAULT dry-run finds no new repair caused by the slice.

- [ ] All deployment markers are tenant-qualified.

- [ ] No device build was produced.

- [ ] No queue name was added.

- [ ] No unrelated untracked file was staged.

- [ ] W-LOT-A-1b remains a separate dispatch.