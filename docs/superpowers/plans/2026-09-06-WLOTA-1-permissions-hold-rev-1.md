# Slice plan W-LOT-A-1 — lot permissions, general-manager role, policy-neutral branch hold (rev 1)

Read-only planning baseline: **`3d27e356bb5bc72ab708751d480548040516c146`**.

No files edited, tests executed, or Git writes performed. Existing-code citations below refer to that SHA. New files, signatures, schemas, and tests are proposed implementation contracts, not claims that they already exist.

**Baseline discrepancy:** during inspection, the working copy of the owner-rulings document gained an uncommitted section accepting Q10–Q13. This revision follows the requested handoff and pinned SHA: **Q10 remains OPEN for this revision; only `requested` and `recalled` are planned.** Before implementation dispatch, reconcile that newer ruling with the plan revision. Do not silently expand this slice.

## Industry baseline — convention 10

Flow: permissioned lot reads and recall, location-specific recall requests, and immediate sale/transfer exclusion.

References: Odoo/OCA 18.0 `stock_lock_lot`; ERPNext Batch and User Permissions documentation, unversioned pages retrieved 2026-09-06. `NV` means the specific guarantee was not verified; it does not mean the product lacks it.

OCA documents a blocked-lot flag, dedicated block/unblock authority, and destination exceptions; its documented workflow does not describe a request approval chain. ERPNext supplies batch disabling and document restrictions through User Permissions. These are useful comparators, but neither establishes the exact branch-request lifecycle proposed here. [OCA Stock Lock Lot](https://github.com/OCA/stock-logistics-workflow/tree/18.0/stock_lock_lot), [ERPNext Batch](https://docs.frappe.io/erpnext/batch), [ERPNext User Permissions](https://docs.frappe.io/erpnext/user-permissions), [ERPNext Batch schema](https://github.com/frappe/erpnext/blob/develop/erpnext/stock/doctype/batch/batch.json).

| ID | Guarantee | Odoo | ERPNext | Dolibarr (or NV + reason) | AutoERP today path:line | Gap | Decision (MATCH/DEFER/DIVERGE/ALREADY) |
|---|---|---|---|---|---|---|---|
| create | An authorized operator can prevent unsafe lot issue | OCA blocked-lot flag | Batch disabled flag | NV — hold workflow not verified | [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134) writes company-wide recall | No branch-request writer in this path | MATCH — add immediate branch hold |
| duplicate | A repeated request cannot create repeated effects | NV — request identity undocumented | NV — request identity undocumented | NV — request identity undocumented | [BatchController.php:193](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193) accepts reason without operation identity | No stable request operation UUID | DIVERGE — explicit company-scoped idempotency contract |
| edit | Submitted safety evidence remains attributable | OCA documents editable blocking state, not this immutable request contract | NV — immutable hold evidence not verified | NV — immutable hold evidence not verified | [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134) replaces recall fields | Recall reason/time can be overwritten | DIVERGE — append-only request evidence; preserve first recall |
| cancel | Disposition authority is explicit | Dedicated block/unblock authority | NV — this disposition chain not verified | NV — disposition authority not verified | [Batch.php:121](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:121) checks global recall | Branch disposition policy unresolved at baseline | DEFER — Q10 |
| rerun | Retrying an operation preserves its original outcome | NV — retry contract undocumented | NV — retry contract undocumented | NV — retry contract undocumented | [Batch.php:136](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:136) updates timestamp on invocation | Retry changes evidence | DIVERGE — explicit replay response without new effects |
| second company | A request cannot affect another company’s lot | NV — exact request workflow absent from cited module | User Permissions offer document restrictions; exact request isolation NV | NV — company isolation for holds not verified | [BatchTraceabilityController.php:49](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:49) rejects another company’s batch | New request storage must preserve isolation | MATCH — company-scoped storage, lookup and operation identity |
| second location | A branch hold affects only its location | OCA documents destination exceptions, not this source-location hold | Warehouse restrictions through User Permissions; batch disabling is a separate mechanism | NV — branch-local hold not verified | [FEFOInventoryService.php:267](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:267) filters location and global recall | No location-specific hold predicate | DIVERGE — branch-local hold, company-wide recall |
| permission | Each safety action requires its own authority | Dedicated block/unblock permission | Role permissions plus User Permissions | NV — exact action matrix not verified | [routes.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12) has module/auth middleware; affected routes lack action middleware | Seeded action permissions are not enforced on these routes | MATCH — enforce API and web permissions |
| audit | Safety decisions retain actor, reason and time | NV — immutable request audit not documented | NV — exact request audit not verified | NV — exact request audit not verified | [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134) records reason/time, not request history | Missing immutable branch-request evidence | DIVERGE — append-only evidence with actor and operation UUID |

The GMP comparator assigns approval/rejection authority to the quality-control unit and requires written responsibilities and records. It does **not** independently establish that AutoERP’s general manager is a legally qualified quality authority. The application-role mapping remains an owner policy decision. [21 CFR 211.22](https://www.ecfr.gov/current/title-21/chapter-I/subchapter-C/part-211/subpart-B/section-211.22).

## Vocabulary — convention 11

**Vocabulary:** retain **Lot (batch)** as the existing stock concept; introduce **Recall request**, **Branch hold**, and **General manager** as distinct concepts. The existing glossary identifies the lot and its stock tables at [docs/glossary.md:41](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:41).

Proposed glossary rows:

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| Recall request | Immutable, reasoned request to recall a lot, submitted for a location where the requester has access and the lot is present. Its effective state is requested or recalled. | `batch_recall_requests` / BatchExpiry; sole writer `BatchRecallService` | Existing batch detail → Request recall; history on the same detail | branch recall request |
| Branch hold | Sale and transfer exclusion at the location named by an unresolved recall request. It changes eligibility, not physical quantity, reservation or value. | Derived from `batch_recall_requests`; no separate hold table or writer | Existing batch detail → location stock and request history | local lot hold |
| General manager | Seeded permission role containing the manager grants plus company-wide recall and Treasury all-location authority; assignment requires unrestricted active company membership. | Existing Spatie roles; `user_company_memberships.allowed_location_ids = NULL` | Existing user/role administration | general_manager |

Do not add a second recall-management page, alternate hold writer, or handwritten frontend domain DTO.

## Owner ruling — Q10 OPEN at the planning baseline

Verbatim Q10 row from the pinned document’s “Q10–Q13 — decisions surfaced…” section:

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |

The recommendation is not implemented by this revision.

## Shared implementation and test contracts

### Authorization and scope

- Permission failures return **403 before mutation**.
- A permitted caller requesting a foreign-company, inaccessible-location or absent-location lot receives **404** from the new request endpoint.
- Malformed UUIDs and blank reasons return **422**.
- Request location scope never uses `treasury.manage_all_locations` as a bypass.
- Company-wide recall requires `batches.recall` **and** an active unrestricted membership. The seeded manager does not receive that permission.
- Existing custom roles remain permission-based; do not replace authorization with a hardcoded role-name check.
- An empty location list means no access. It must never collapse to unrestricted access.

The membership distinction is explicit in [LocationContext.php:194](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationContext.php:194). The existing resolver throws 403 for an explicitly disallowed location at [LocationScopeResolver.php:31](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationScopeResolver.php:31); preserve that behavior for `expiring()`, while implementing scoped-not-found specifically for recall requests.

### Append-only representation

Use immutable request entries and immutable recall-transition entries in **one table**:

- A root entry has `status=requested` and no `request_id`.
- A transition entry has `status=recalled` and references its requested root.
- The API returns the root’s effective status from the existence of its transition.
- Never update the root’s reason, actor, location, operation UUID or status.
- The global `product_batches.is_recalled` remains the company-wide recall projection.
- Each transition receives a deterministic UUID derived from its root request UUID and the fixed `recalled` transition discriminator.

A successful hold does not decrement stock, change reservations, book inventory movements or write GL entries.

### Real onboarding fixture

Add:

`apps/api/tests/Feature/BatchExpiry/Concerns/BuildsRegisteredLotRecallFixture.php`

Proposed signature:

```php
protected function buildRegisteredLotRecallFixture(): void;
```

Register the tenant through `/api/v1/auth/register`, then create company B through `/api/v1/companies` and a second `pos_enabled` location through `/api/v1/locations`.

Use the physical-tenant setup/cleanup pattern from [RegistrationResponseIsPureJsonTest.php:103](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php:103). Reuse the company/location HTTP payload pattern from [BuildsFreshTenantCensusFixture.php:69](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Tenant/Concerns/BuildsFreshTenantCensusFixture.php:69), but **do not substitute that trait’s manually constructed initial tenant for real registration**.

Fixture stock: the same lot at company A locations A1/A2, another eligible lot for FEFO fallback, a variant lot, and an independently created matching lot number in company B.

For denied mutations and retries, compare deterministic before/after snapshots of:

- Batch recall fields.
- Lot and aggregate quantities/reservations.
- Batch and stock movements.
- Transfer records and allocations.
- Recall-request entries.
- Relevant audit records.

### Test commands

Commands below run from the repository root on dedicated test databases:

```bash
(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchActionPermissionsTest|BatchExpiringLocationScopeTest')
(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest|GeneralManagerAssignmentTest')
(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestSchemaTest|BatchRecallRequestTest')
(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest|BatchRecallHoldConcurrencyTest')
(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest')
pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchPermissions.test.tsx src/features/batches/pages/BatchRecallRequest.test.tsx src/routes/__tests__/BatchRoutePermissions.test.tsx src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx
```

Each task starts by adding its tests and capturing the first meaningful failure before production changes. A missing class is not the desired red evidence: scaffold only enough to reach the specified behavioral assertion.

## Task 1 — Enforce API action permissions and expiring-location scope

**Dependencies:** none.

**Verified production files:**

- [BatchExpiry routes.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12).
- [BatchController.php:193](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193).
- [FEFOInventoryService.php:839](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:839).
- [BatchTraceabilityController.php:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:38).

**Route matrix:**

| Routes | Required permission |
|---|---|
| GET batches, batch detail, expiring, expired, batch stock, product batch stock, POS available batches | `batches.view` |
| GET forward traceability and partner batch history | `batches.traceability` |
| DELETE batch | `batches.delete` |
| POST batch recall | `batches.recall` |

Retain `api`, `auth:sanctum`, `SetPermissionsTeam`, `EnforceTokenTenantClaim`, `module:BatchExpiry`, literal-route ordering and existing company checks. Preserve the existing write-off permission gate.

**Signatures:**

```php
public function expiring(Request $request): JsonResponse;

// Widen the existing optional location argument compatibly.
// PHPDoc: string|list<string>|null.
public function getExpiringProducts(
    string $companyId,
    int $daysThreshold = 30,
    string|array|null $locationId = null,
): Collection;
```

All other controller signatures remain unchanged, including:

```php
public function destroy(string $uuid): JsonResponse;
public function recall(Request $request, string $uuid): JsonResponse;
public function forwardTrace(string $uuid): JsonResponse;
public function backwardTrace(Request $request, string $partnerId): JsonResponse;
```

**Implementation:**

1. Add `can:` action middleware according to the matrix.
2. Validate `location_id` as nullable UUID, matching `expired()`.
3. Resolve allowed locations through `LocationScopeResolver`.
4. Pass the resulting explicit list into the service.
5. Apply identical location filtering to `whereHas('batchStock')` and eager-loaded `batchStock`; an empty list returns no lots.

Current `expiring()` accepts a raw location string, while `expired()` validates and resolves scope at [BatchController.php:215](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:215) and [BatchController.php:237](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:237).

**Red-first tests — PHPUnit PG:**

| New file and class::method | First failing assertion |
|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` — `BatchActionPermissionsTest::test_cashier_and_viewer_cannot_mutate_or_trace_without_permission` | `assertForbidden()` for recall, deactivate and traceability; then unchanged snapshot |
| Same — `::test_reads_require_batches_view` | `assertForbidden()` for a caller lacking view; a caller explicitly granted view succeeds |
| Same — `::test_module_and_company_guards_remain_effective` | Foreign-company batch remains 404 for an action-authorized caller |
| `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php` — `::test_expiring_validates_uuid_and_filters_loaded_stock_to_allowed_locations` | Malformed UUID returns 422; A2 stock is absent from A1-scoped response |
| Same — `::test_empty_membership_scope_returns_no_expiring_lots` | Response `data` equals `[]` |

Run the first command in the shared command block.

**Migration/schema:** none.

**Reviewer gate:** tenancy-authz-reviewer verifies every route and response precedence; inventory-costing-reviewer verifies unchanged stock/history and filtered relation data.

**Rollback:** revert only before activation, or replace with a corrected permissioned implementation. Do not restore publicly callable recall as a production rollback.

## Task 2 — Seed the role delta and enforce unrestricted general-manager assignment

**Dependencies:** Task 1.

**Verified production files:**

- [RolesAndPermissionsSeeder.php:543](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:543).
- [UserController.php:192](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:192), including membership creation at line 241, role update at line 381 and location write at line 952.
- [RoleController.php:350](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:350).
- [CreateUserRequest.php:50](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Requests/CreateUserRequest.php:50).
- [UpdateUserRequest.php:56](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Requests/UpdateUserRequest.php:56).

The seeder currently calls `syncPermissions()` on existing named roles. The separate `RoleController::assignRole()` is another assignment path and must receive the same membership guard.

**New production files:**

- `apps/api/app/Console/Commands/ApplyLotRecallPermissionDelta.php`
- `apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php`

**Signatures:**

```php
// Seeder: retain these public signatures.
public function run(): void;
public static function permissionNames(): array;
public static function rolePermissionGrants(): array;

// Command; executes within an initialized tenant.
protected $signature = 'permissions:apply-lot-recall-delta';
public function handle(): int;

// Proposed guard. PHPDoc: list<string>|null for effective locations.
public function assertAssignable(
    User $actor,
    User $target,
    string $companyId,
    string $roleName,
    ?array $effectiveAllowedLocationIds,
): void;

public function assertLocationChangeAllowed(
    User $target,
    string $companyId,
    ?array $effectiveAllowedLocationIds,
): void;
```

Constructor-inject the guard into both controllers.

**Implementation:**

1. Add `batches.recall.request` and `treasury.manage_all_locations` to the canonical permission list.
2. Remove `batches.recall` from the manager definition; add `batches.recall.request`.
3. Derive `general_manager` from the revised canonical manager grants, plus `batches.recall` and `treasury.manage_all_locations`.
4. Keep existing admin semantics.
5. For existing tenants, use targeted `givePermissionTo()`/`revokePermissionTo()` operations. Never synchronize all permissions on an existing role.
6. Make ordinary reseeding safe for existing roles: initialize missing standard roles, preserve existing unrelated/custom grants, and apply this explicit delta. A pre-existing custom `general_manager` name must produce an actionable conflict instead of silently broadening it.
7. Run the delta transactionally and reset permission cache after commit. Repeat execution reports no new changes.
8. Check the **effective post-update membership**, not merely the incoming field. Omitted scope on a restricted existing membership does not mean NULL.
9. Reject assignment with `[]` or a restricted list; require an active membership with SQL NULL.
10. Reject later narrowing while the user retains `general_manager`. Preserve existing self-escalation and actor-grant restrictions.
11. Do not add a new `MembershipRole` value merely to mirror the Spatie role.
12. Where a role applies beyond one company, verify every active membership in its applicable scope; never silently clear another company’s restrictions.

**Red-first tests — PHPUnit PG:**

New `apps/api/tests/Feature/Identity/LotRecallRoleDeltaTest.php`:

- `LotRecallRoleDeltaTest::test_manager_loses_recall_and_gains_request`: first failure is `assertFalse($manager->hasPermissionTo('batches.recall'))`.
- `::test_general_manager_has_exact_new_seeded_grants`: first failure is role existence, then grant-set equality.
- `::test_delta_and_reseed_preserve_custom_permissions_on_rerun`: first failure is custom grant-set equality after reseed.
- `::test_existing_general_manager_name_collision_is_reported_without_escalation`: first failure is nonzero command outcome with unchanged grants.

New `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php`:

- `::test_create_update_and_role_endpoint_reject_restricted_assignment`: first failure is 422 with unchanged role/membership.
- `::test_unrestricted_assignment_succeeds_and_cannot_be_narrowed`: first failure is rejection of a later restricted scope.
- `::test_actor_cannot_use_general_manager_assignment_to_expand_own_authority`: first failure is denied escalation with unchanged grants.

Run the second shared command.

**Migration/schema:** none; existing membership JSON is unchanged. No new JSONB column or status/type column.

**Reviewer gate:** tenancy-authz-reviewer checks all three assignment paths, role scope and custom-role preservation; inventory-costing-reviewer checks manager recall denial and general-manager capability.

**Rollback:** retain the stricter manager permission and revoke new assignments only through an audited targeted operation if necessary. Restore unrelated grants from the manifest’s before-state; never blanket-sync or automatically restore manager company-wide recall.

## Task 3 — Persist immutable recall requests and expose the scoped writer

**Dependencies:** Task 2. Do not activate the writer until Task 4 is deployed.

**Verified integration files:**

- [BatchExpiry routes.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12).
- [BatchExpiryServiceProvider.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php:14).
- [product_batches migration:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:14): bigint batch identity.
- [inventory_batch_stock migration:21](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_05_150001_create_inventory_batch_stock_table.php:21): batch/location stock references.

**New production files:**

- `apps/api/database/migrations/tenant/2026_09_06_210000_create_batch_recall_requests_table.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchRecallRequestStatus.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchRecallRequest.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/CreateBatchRecallRequestData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchRecallRequestData.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallService.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRecallRequest.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchRecallRequestController.php`

### Complete migration schema

Table: `batch_recall_requests`.

| Column | Type | Nullable | Default | FK / on-delete |
|---|---|---|---|---|
| `id` | UUID | no | application-generated UUID | primary key |
| `tenant_id` | UUID | no | none | no cross-database central FK |
| `company_id` | UUID | no | none | `companies.id`, RESTRICT |
| `batch_id` | BIGINT | no | none | `product_batches.id`, RESTRICT |
| `location_id` | UUID | no | none | `locations.id`, RESTRICT |
| `request_id` | UUID | yes | NULL | requested root in same table, RESTRICT |
| `operation_uuid` | UUID | no | none | none |
| `status` | VARCHAR(16) | no | none | PHP enum/check below |
| `reason` | VARCHAR(255) | no | none | none |
| `actor_user_id` | UUID | no | none | `users.id`, RESTRICT |
| `created_at` | TIMESTAMP WITH TIME ZONE | no | `CURRENT_TIMESTAMP` | none |

No `updated_at`, soft-delete column, quantity, value, generic payload, or JSONB column. **JSONB DTO: not applicable; no JSONB is introduced.** Both transport DTOs are typed Spatie Data classes.

Constraints and indexes:

- Primary key `id`.
- Unique `brq_company_operation_uq(company_id, operation_uuid)`.
- Unique `brq_company_request_status_uq(company_id, request_id, status)`.
- Index `brq_scope_status_idx(company_id, batch_id, location_id, status)`.
- Index `brq_company_created_idx(company_id, created_at, id)`.
- Index `brq_actor_idx(actor_user_id)`.
- Check `status IN ('requested', 'recalled')`.
- Check `length(trim(reason)) > 0`.
- Check requested entries have NULL `request_id`; recalled entries have non-NULL `request_id`.
- Check `request_id IS NULL OR request_id <> id`.
- Insert validation trigger verifies company/tenant/batch/location/actor consistency and, for a transition, that the parent is a requested root with identical tenant/company/batch/location.
- UPDATE and DELETE rejection triggers enforce append-only evidence, including direct SQL.
- Provide equivalent SQLite triggers for schema portability; PostgreSQL is the authoritative integration lane.
- `down()` removes only this migration’s triggers/functions/table and refuses destructive rollback when evidence exists.

```php
enum BatchRecallRequestStatus: string
{
    case Requested = 'requested';
    case Recalled = 'recalled';
}
```

### Full public contracts

```php
// CreateBatchRecallRequestData constructor:
public function __construct(
    public string $operation_uuid,
    public string $location_id,
    public string $reason,
);

// BatchRecallRequestData constructor:
public function __construct(
    public string $id,
    public string $operation_uuid,
    public string $batch_uuid,
    public string $location_id,
    public BatchRecallRequestStatus $status,
    public string $reason,
    public string $requested_by,
    public string $requested_at,
    public ?string $recalled_by,
    public ?string $recalled_at,
    public bool $replayed,
);

// Service:
public function request(
    User $actor,
    string $batchUuid,
    CreateBatchRecallRequestData $data,
): BatchRecallRequestData;

/** @return list<BatchRecallRequestData> */
public function requests(User $actor, string $batchUuid): array;

// Controller:
public function store(
    CreateBatchRecallRequest $request,
    string $uuid,
): JsonResponse;

public function index(Request $request, string $uuid): JsonResponse;

// FormRequest:
public function authorize(): bool;
public function rules(): array;
```

**Routes:**

- `POST /api/v1/batches/{uuid}/recall-requests`: `can:batches.recall.request`.
- `GET /api/v1/batches/{uuid}/recall-requests`: `can:batches.view`.

Both inherit the existing middleware group.

**Implementation:**

1. Validate operation/location UUIDs and trimmed mandatory reason.
2. Resolve batch and location through the actor’s company and allowed locations; return 404 outside scope.
3. Require positive physical lot quantity at the requested location, including fully reserved stock.
4. Lock the location’s batch-stock row and recheck presence before insertion.
5. Insert the requested root within the transaction; commit is the hold’s activation point.
6. Replay the same company/operation UUID only when actor, lot, location and normalized reason match. Return 200 with the original ID and `replayed=true`; fresh creation returns 201.
7. Changed payload under the same operation UUID returns 409.
8. Revalidate authorization on retry. A historic operation UUID does not bypass revoked access.
9. Reject a new request for an already globally recalled lot; a retry of an existing request returns its effective recalled state.
10. Preserve independent evidence from separate operation UUIDs. Multiple reports create one effective exclusion, not multiplied stock effects.

**Red-first tests — PHPUnit PG:**

New `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestSchemaTest.php`:

- `::test_request_evidence_rejects_update_delete_and_cross_company_links`: first failure is successful prohibited SQL.
- `::test_only_requested_and_recalled_entries_are_valid`: first failure is an invalid status insert succeeding.

New `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestTest.php`:

- `::test_manager_requests_recall_at_allowed_location`: first failure is missing 201/request entry.
- `::test_non_allowed_location_is_scoped_not_found`: first failure is response other than 404.
- `::test_retry_returns_original_request_without_duplicate_evidence`: first failure is changed ID/count.
- `::test_operation_uuid_payload_conflict_returns_409`: first failure is accepted changed payload.
- `::test_second_company_can_reuse_operation_uuid_without_cross_company_effects`: first failure is company B isolation or independence.

Run the third shared command.

**Reviewer gate:** tenancy-authz-reviewer verifies scope, UUID replay and composite consistency; inventory-costing-reviewer verifies no quantity/value effects and append-only lifecycle projection.

**Rollback:** before activation, remove the unused schema only if empty. After evidence exists, retain it and its eligibility enforcement; disable new submissions if necessary.

## Task 4 — Block held stock in sale and both transfer paths

**Dependencies:** Task 3 schema and service contracts.

**Verified production files:**

- [FEFOInventoryService.php:74](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:74), line 234 atomic consumption and line 940 availability.
- [TransferBatchStockRequest.php:19](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/TransferBatchStockRequest.php:19).
- [BatchStockService.php:499](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:499).
- [StockTransferService.php:807](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:807), lines 882 and 993 allocation/issue validation.

**New production file:**

`apps/api/app/Modules/BatchExpiry/Application/Services/BatchIssueEligibilityService.php`

**Signatures:**

```php
public function isHeld(
    string $tenantId,
    string $companyId,
    int $batchId,
    string $locationId,
): bool;

public function assertCanIssue(
    string $tenantId,
    string $companyId,
    int $batchId,
    string $locationId,
): void;
```

Use this public service across module boundaries; do not import the new request entity into Inventory.

Preserve the complete existing operation contracts:

```php
public function suggestBatchesForSale(
    string $productId,
    string $locationId,
    string $quantity,
    bool $includeExpired = false,
    ?string $variantId = null,
): BatchSuggestionResultDTO;

public function consumeBatchesAtomically(
    string $tenantId,
    string $productId,
    string $locationId,
    string $quantity,
    string $movementId,
    ?string $variantId = null,
    bool $strictFulfillment = true,
): BatchConsumptionResultDTO;

public function transferBatchStock(
    string $tenantId,
    int $batchId,
    string $fromLocationId,
    string $toLocationId,
    string $quantity,
    string $reference,
    string $userId,
): void;
```

**Implementation:**

1. Exclude unresolved requested roots correlated by tenant, company, batch and **source location**.
2. Apply the predicate to suggestions, atomic consumption, total eligible availability, automatic transfer FEFO allocation and explicit transfer allocation validation.
3. `includeExpired=true` must not bypass a hold.
4. Direct transfer validates source/destination UUIDs and existing company/permission requirements; enforce the hold again inside `BatchStockService` before either movement.
5. In `computeFefoSplit()`, skip held lots before deciding canonical FEFO. A later eligible lot must be usable when the earlier lot is held.
6. In explicit allocation validation, reject the held lot before stock mutation.
7. Preserve existing reservations, quantity precision, variant matching, costing and transfer accounting.
8. Do not hide held physical stock from administrative stock/history views or turn the hold into a stock adjustment.

**Concurrency contract:**

The existing transfer code documents aggregate-stock-before-batch-stock ordering at [StockTransferService.php:794](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:794). Preserve it.

- Hold creation locks its batch-stock row, then checks the batch’s global recall state under a shared parent-row lock before inserting.
- Consumers lock batch-stock rows through their existing flow, then acquire shared parent-batch locks in ascending batch ID order.
- After locks are acquired, re-read recall and request eligibility in a fresh statement before mutation.
- Company-wide recall takes the exclusive parent-batch lock; it must not subsequently acquire batch-stock locks.
- No path acquires an upstream aggregate lock after these new locks.
- Keep PostgreSQL `SKIP LOCKED` behavior, with a fresh eligibility check after selection.

The fresh check matters because a statement started before a competing hold commits can otherwise retain stale eligibility after waiting for a row lock. The atomic query is at [FEFOInventoryService.php:260](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:260).

**Red-first tests — PHPUnit PG:**

New `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldEligibilityTest.php`:

- `::test_hold_blocks_sale_and_direct_transfer_at_held_location_only`: first failure is held-lot consumption or transfer succeeding.
- `::test_stock_transfer_manual_and_auto_allocation_exclude_held_lot`: first failure is held lot in allocation.
- `::test_fefo_selects_next_eligible_lot_without_false_order_violation`: first failure is wrong split or false FEFO rejection.
- `::test_hold_preserves_physical_reserved_stock_and_value`: first failure is snapshot difference.
- `::test_variant_and_company_scopes_do_not_share_holds`: first failure is unrelated stock excluded.

New `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldConcurrencyTest.php`:

- `::test_committed_hold_wins_against_waiting_direct_transfer`.
- `::test_committed_hold_wins_against_waiting_stock_transfer`.
- `::test_sale_started_after_hold_commit_cannot_consume_held_lot`.
- `::test_concurrent_request_retries_create_one_root`.

First failures: a forbidden movement/consumption appears, or root count exceeds one. Use independent PostgreSQL connections/processes and explicit synchronization barriers, not sleep-only timing.

Run the fourth shared command.

**Migration/schema:** none beyond Task 3.

**Reviewer gate:** tenancy-authz-reviewer verifies correlated scope and service-call protection; inventory-costing-reviewer verifies lock ordering, both transfer modes, FEFO fallback and unchanged valuation.

**Rollback:** once a hold exists, these checks cannot be rolled back to code that ignores it. Disable affected issue surfaces or deploy a corrected enforcement implementation while retaining evidence.

## Task 5 — Execute company-wide recall and append transitions

**Dependencies:** Tasks 2–4.

**Verified production files:**

- [BatchController.php:193](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193).
- [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134).

Extend Task 3’s `BatchRecallService.php`; add:

`apps/api/app/Modules/BatchExpiry/Presentation/Requests/RecallBatchRequest.php`

**Signatures:**

```php
public function recall(
    User $actor,
    string $batchUuid,
    string $reason,
): Batch;

public function recall(
    RecallBatchRequest $request,
    string $uuid,
): JsonResponse;

// RecallBatchRequest:
public function authorize(): bool;
public function rules(): array;
```

**Implementation:**

1. Require `batches.recall` and active unrestricted membership.
2. Validate trimmed mandatory `reason`, maximum 255 characters.
3. Resolve the batch within the current company.
4. Acquire its exclusive parent-row lock within the transaction.
5. On first execution, set the existing global recall fields and append one `recalled` transition for every requested root for that batch across company locations.
6. Copy each root’s tenant/company/batch/location, record the recalling actor and mandatory disposition reason, and link `request_id`.
7. Use the deterministic transition operation UUID so replay cannot duplicate transitions.
8. Return the batch through the existing response shape.
9. An already-recalled batch with the same normalized reason returns the original outcome without rewriting timestamp or evidence; a different reason returns 409.
10. A direct company-wide recall remains possible without a prior request. Preserve its first reason/time; do not invent a branch request.
11. Request creation racing with recall either commits first and is transitioned, or observes recalled state and is rejected.

The company-wide block remains the existing `is_recalled` path; the handoff’s cited FEFO lines 930–934 now identify `productRequiresBatchTracking()`, while the current total-availability recall predicate is at [FEFOInventoryService.php:940](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:940).

**Red-first tests — PHPUnit PG:**

New `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php`:

- `::test_general_manager_recall_spans_branches_and_transitions_requests`: first failure is a requested effective status after recall; assert A1/A2 sale and transfer exclusion.
- `::test_manager_cannot_recall_company_wide`: first failure is response other than 403; assert unchanged snapshot.
- `::test_restricted_custom_recall_permission_cannot_execute_company_wide`: first failure is accepted recall despite restricted membership.
- `::test_recall_retry_preserves_timestamp_reason_and_transition_count`: first failure is changed timestamp or evidence count.
- `::test_second_company_is_unchanged`: first failure is company B snapshot difference.
- `::test_concurrent_request_and_recall_leave_no_untransitioned_request`: first failure is a request committed after recall without correct effective state.

Run the fifth shared command.

**Migration/schema:** no additional columns; use Task 3’s recalled transition schema and enum.

**Reviewer gate:** tenancy-authz-reviewer verifies unrestricted membership and cross-company isolation; inventory-costing-reviewer verifies all-branch exclusion, atomic transitions and zero stock/value movement.

**Rollback:** never undo global recall or delete transitions as a software rollback. Retain recall-aware enforcement and disable the action if correction is required.

## Task 6 — Gate web routes/actions and expose the request on batch detail

**Dependencies:** Tasks 1–5.

**Verified production files:**

- [routes/index.tsx:1180](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/routes/index.tsx:1180).
- [organisms/Sidebar.tsx:222](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/Sidebar/Sidebar.tsx:222).
- [usePermissions.ts:128](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/usePermissions.ts:128).
- [BatchDetailPage.tsx:24](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:24).
- [batches API:103](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/api/batches.ts:103).
- [ExportFrontendPermissionsMap.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:14).

Also modify these verified existing files:

- `apps/web/src/features/batches/pages/BatchListPage.tsx`
- `apps/web/src/features/batches/hooks/useBatches.ts`
- `apps/web/src/features/batches/types.ts`
- `apps/web/src/hooks/permissionsMap.generated.ts`
- `apps/web/src/locales/en/batches.json`
- `apps/web/src/locales/fr/batches.json`
- `docs/glossary.md`

**Proposed frontend signatures, using generated DTO types:**

```ts
export function createBatchRecallRequest(
  uuid: string,
  input: CreateBatchRecallRequestData,
): Promise<BatchRecallRequestData>

export function getBatchRecallRequests(
  uuid: string,
): Promise<BatchRecallRequestData[]>
```

**Implementation:**

1. Retain `ModuleGuard module="BatchExpiry"` and compose the existing `RequirePermission` component:
   - List/detail: `batches.view`.
   - New: `batches.create`.
   - Edit: `batches.update`.
   - Write-off: retain `batches.write-off`.
2. Add the `batches.view` navigation mapping and gate the batches sidebar entry with it.
3. Combine action-state conditions with `usePermissions()`:
   - Edit → update.
   - Deactivate → delete.
   - Recall → recall.
   - Request recall → recall.request.
   - Traceability fetch/action → traceability.
4. Keep unauthorized traceability queries disabled; hiding an action alone is insufficient.
5. Add the request form and immutable request history to existing batch detail.
6. Offer only allowed locations where the lot has stock. Backend scope remains authoritative.
7. Generate the operation UUID once per submission intent; retain it across network retries. Reset only after success or a deliberately new submission.
8. Use tenant/company-scoped query keys and invalidate relevant request, batch and availability caches after success.
9. Use generated PHP DTO types; no new handwritten domain interfaces.
10. Correct the recall payload while wiring the action: the page submits `recall_reason`, the API forwards it unchanged, and the backend validates `reason`. Evidence: [BatchDetailPage.tsx:48](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:48), [batches.ts:103](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/api/batches.ts:103), [BatchController.php:195](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:195).
11. Add translated labels and messages in both existing locales; use design tokens in touched UI.
12. Add the three glossary rows from this plan.

Generation commands:

```bash
(cd apps/api && php artisan typescript:transform)
(cd apps/api && php artisan permissions:export-frontend-map)
```

**Red-first tests — Vitest:**

| File / suite::case | First failing assertion |
|---|---|
| `apps/web/src/routes/__tests__/BatchRoutePermissions.test.tsx` — `BatchRoutePermissions::denies each direct route without its action permission` | Restricted page is rendered |
| `apps/web/src/features/batches/pages/BatchPermissions.test.tsx` — `BatchPermissions::manager sees request but not company recall` | Recall button exists for manager |
| Same — `::viewer cannot mutate and trace query remains disabled` | Mutation control exists or trace API spy is called |
| `apps/web/src/features/batches/pages/BatchRecallRequest.test.tsx` — `BatchRecallRequest::retry retains operation UUID` | Retry payload UUID differs |
| Same — `::submits reason and displays location-specific hold` | Wrong request payload or missing held-location status |
| Existing Sidebar test file — `Sidebar::hides batches without view permission` | Batches link is present |

Run the sixth shared command. Then run the repository’s required React diagnostics workflow, typecheck, lint and applicable browser coverage during implementation.

**Migration/schema:** none; generated DTO/map output only.

**Reviewer gate:** tenancy-authz-reviewer verifies server-authoritative permission behavior and query gating; inventory-costing-reviewer verifies that physical quantity remains visible and hold status is clearly separate from stock quantity.

**Rollback:** roll back the new form/navigation as needed while retaining backend action permissions and hold-aware issue enforcement. Do not deploy a permission map generated from an older role definition.

## Withheld behind Q10

This revision adds no release/reject state, route, permission, UI action or test.

The reconciled owner ruling will determine a subsequent revision’s:

- Disposition states and transition rules.
- Release/rejection authority and membership requirements.
- Mandatory disposition evidence and actor attribution.
- Effective-hold predicate after disposition.
- Replay, concurrency and UI behavior for those transitions.
- Treatment of multiple independent requests for the same lot/location.

The uncommitted accepted ruling observed during planning must be incorporated explicitly into that revision; it does not silently change this two-state schema.

## Deployment and rollback points

Shared staging push manifest:

`docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`

**Absent when checked.** Fable’s manifest must provide these exact operational steps and evidence before promotion:

1. **Additive migration push:** record commit SHA, tenant inventory, migration order and per-tenant migration results. Deploy schema before code reads it.
2. **Permission delta and reseed:** within each initialized tenant, run the safe revised `RolesAndPermissionsSeeder` and `permissions:apply-lot-recall-delta`; capture before/after grants and any role-name conflicts.
3. **Per-tenant cache reset:** run `permission:cache-reset` in each tenant context, including the correct permission-team context. A central-only reset is insufficient.
4. **Permission-map regeneration:** run `permissions:export-frontend-map` and `typescript:transform`; commit generated outputs with the matching backend revision.
5. **Hold enforcement activation:** deploy Task 4 enforcement before enabling Task 3 submissions. Schema-only staging is safe; writable requests without enforcement are not.
6. **Web deployment and fingerprint:** record backend SHA, web artifact SHA/fingerprint and a browser check showing the expected manager/general-manager controls.
7. **Tenant smoke journey:** manager request at A1, sale/transfer denied at A1, A2 unaffected, general-manager recall, both branches denied, retry unchanged, company B unaffected.
8. **Rollback point:** preserve the last compatible permissioned, hold-aware backend/web artifacts plus pre-delta grant exports. After evidence exists, retain the table and enforcement; disable submissions or issue surfaces if necessary.
9. **Promotion checks:** required preflight, backend suites/static analysis, web build/lint/typecheck, applicable E2E, onboarding campaign and day-one census results. Record actual results and any blockers.

No staging push, reseed, cache reset or deployment was performed in this planning task.

## Dispatch order

1. Task 1 — API permission gates and expiring scope.
2. Task 2 — safe role delta and all assignment guards.
3. Task 3 — immutable request schema and writer, unactivated.
4. Task 4 — sale/direct-transfer/stock-transfer enforcement.
5. Task 5 — company-wide recall and transitions.
6. Task 6 — generated permissions/types and existing-detail UI.

Dispatch each task with its dependency commits and both reviewer gates. Activate request creation only after Tasks 3–5 pass the PostgreSQL lane.

## Verification checklist

- [ ] Reconcile the newer Q10 owner-ruling edit before implementation dispatch.
- [ ] Record implementation SHA and revalidate cited seams if HEAD changed.
- [ ] Capture meaningful red results before production edits.
- [ ] Every listed API route enforces its action permission and retains module/company checks.
- [ ] `expiring()` validates UUIDs and scopes both selected lots and loaded stock.
- [ ] Manager loses company-wide recall and gains recall-request permission.
- [ ] General-manager grants match the prescribed set; restricted assignment and later narrowing fail.
- [ ] Reseed/delta reruns preserve custom grants.
- [ ] Request evidence is append-only, company-scoped and idempotent.
- [ ] Unauthorized mutations return 403 with unchanged snapshots.
- [ ] Inaccessible request locations return 404.
- [ ] A1 hold blocks sale and both transfer paths; A2 and company B remain independent.
- [ ] PostgreSQL races prove post-lock eligibility and duplicate-request protection.
- [ ] Company-wide recall transitions all requests and preserves evidence on retry.
- [ ] No release/reject implementation enters this revision.
- [ ] Web routes, actions and queries follow matching permissions.
- [ ] Generated DTOs/map, translations and glossary are included.
- [ ] Both reviewers approve each task.
- [ ] Shared staging manifest records per-tenant migration, reseed, cache reset, deployed fingerprints and rollback artifacts.
