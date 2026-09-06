<!-- W-LOT-A-1 rev 4, Codex CLI fix round 3 (gpt-5.6-sol, read-only) on 2026-09-06, saved verbatim by the orchestrator (owner away). Rev 3 = 5a33e1ae1. Status: awaiting gate r4. -->
# Slice plan W-LOT-A-1 — lot permissions, general-manager role, policy-neutral branch hold (rev 4)

Planning baseline: **`3641298ad798df6b9244471594933aae4147eb7b`**, read from `.git/refs/heads/dev:1` without invoking Git. All production/test seams and line citations below were re-read at that baseline. This planning revision performs no code changes, migrations, tests, or Git commands.

## Round-three change log

Review read in full: `docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r3.md:1-135`.

| Gate item | Status | Rev-4 closure |
|---|---|---|
| B1 — shared root/transition idempotency namespace | **CLOSED — plan line R3-B1** | Replace the single company-operation unique with separate root and transition partial unique indexes; define kind-specific replay lookups; retain the status-independent terminal-child invariant and add a status-independent one-open-request guard. Task 3 adds the PostgreSQL adversarial pre-computed-transition collision test. |
| B2 — incomplete recall-writer census | **CLOSED — plan line R3-B2** | Task 5 lists every current direct historical recall setup, including the four omissions at `CountingVarianceAppliedTest.php:606,628`, `StockAdjustmentBatchDispositionTest.php:845`, and `BatchEntityTest.php:116-119`, plus all other constructor/create/mutator sites found at this HEAD. Each has an exact factory-state or service replacement. A separate test-source AST census carries an explicit allowlist; the production ratchet remains strict. |
| B3 — missing canonical W-LOT-B eligibility interface | **CLOSED — plan line R3-B3** | Task 4 defines typed query, decision, reason, source-revision, capture-time, and snapshot-age contracts. POS FEFO consumption, direct batch transfer, StockTransferService, and W-LOT-B’s snapshot generator use the same service. Atomic FEFO gains `companyId`, company predicates, and the named `consumeBatchesAtomicallyForProductCompany()` compatibility shim. |
| M1 — Push 3 not inert / unsafe rollback | **CLOSED — plan line R3-M1** | Push 3 contains only unwired services and flag-disabled authoring and does not rewire existing routes. Existing-route read/action hardening lands in the Push-5 artifact. Once Push 5 is active, read/location scoping and existing mutation permissions are unconditional under both authoring-flag states; only the new recall-request mutation is flag-controlled. Flag rollback cannot restore legacy reads. |
| M2 — convention-09 registration missing | **CLOSED — plan line R3-M2** | Task 3 adds `product_batches` to both catalogue constants and classifies the new immutable `batch_recall_requests` table in the exact architecture manifest file. No baseline waiver is added. |
| M3 — Identity model crosses public recall boundary | **CLOSED — plan line R3-M3** | Public recall methods take `App\Shared\Contracts\Identity\ActorIdentityScopeData`; no BatchExpiry public contract imports `App\Modules\Identity\Domain\User`. |
| M4 — incorrect convention-10 decisions | **CLOSED — plan line R3-M4** | Duplicate, edit/evidence, rerun, and audit are now `MATCH — W-LOT-A-1`; only release/reject remains `DEFER — W-LOT-A-1b`. |
| M5 — census variable not executable | **CLOSED — plan line R3-M5** | Deployment supplies one executable, fail-fast Bash gate with exact commands, normalized baselines, tenant cardinality, stdout PASS markers, and exit rules for all four named censuses. |
| N1 — stale planning baseline | **CLOSED — plan line R3-N1** | Repinned above to metadata HEAD `3641298ad798df6b9244471594933aae4147eb7b`; every cited seam was re-read after the checkout advanced. |
| N2 — false Task-5 exact-file claim | **CLOSED — plan line R3-N2** | Task 5 contains regenerated production, factory, architecture-test, feature-test, and affected existing-test lists. Every newly listed regression has a named method, assertion, command, and lane. |

The review’s rejected false positives remain rejected: release/reject belongs to W-LOT-A-1b, terminal uniqueness remains status-independent, no device-immediate guarantee is made, and this plan remains at six tasks (`docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r3.md:111-119`).

## Industry baseline (benchmark-first — convention 10)

Flow: permissioned lot reads and recall, location-specific recall requests, and immediate server issue/transfer exclusion.

Reference systems: Odoo/OCA 18.0 `stock_lock_lot`; ERPNext Batch and User Permissions documentation; Dolibarr remains NV where no equivalent documented workflow was verified. OCA documents blocked-lot authority and destination exceptions; ERPNext documents batch disabling and warehouse/user restrictions. Neither establishes this exact append-only branch-request lifecycle.

| # | Guarantee the baseline gives the user | Odoo | ERPNext | Dolibarr | AutoERP today (path:line) | Gap | Decision |
|---|---|---|---|---|---|---|---|
| B1 | An authorized operator can prevent unsafe lot issue | Blocked-lot flag | Batch disabled flag | NV | Global recall mutator only (`apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134-140`) | No branch-local request/hold | **MATCH — W-LOT-A-1** |
| B2 | Retrying a request cannot create duplicate effects | Exact operation identity NV | Exact operation identity NV | NV | Recall route accepts only a reason (`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193-204`) | No idempotency key or replay | **MATCH — W-LOT-A-1** |
| B3 | Submitted safety evidence remains attributable and immutable | Editable blocking state; immutable request evidence NV | Immutable hold evidence NV | NV | Recall fields are overwritten in place (`apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134-140`) | Reason/time/actor history can be replaced | **MATCH — W-LOT-A-1** |
| B4 | Release/reject authority is explicit | Dedicated block/unblock authority | Exact approval chain NV | NV | No branch-request disposition workflow | Owner ruled release/reject, but it is outside this slice | **DEFER — W-LOT-A-1b** |
| B5 | A rerun preserves the original outcome | Retry contract NV | Retry contract NV | NV | Recalling again rewrites `recalled_at` (`apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:136-140`) | Replay changes evidence | **MATCH — W-LOT-A-1** |
| B6 | Company B cannot observe or affect company A’s lot | Exact request workflow NV | User Permissions provide document restrictions | NV | Existing batch lookup rejects another company (`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:57-75`) | New request and eligibility storage need equivalent isolation | **MATCH — W-LOT-A-1** |
| B7 | A branch hold affects only its source location | Destination exceptions | Warehouse restrictions | NV | FEFO filters location and global recall only (`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:262-272`) | No local hold predicate | **DIVERGE — W-LOT-A-1 deliberately adds branch-local hold while retaining company-wide recall** |
| B8 | Each safety action has separate authority | Dedicated block/unblock authority | Role permissions | NV | Affected routes currently inherit module/auth only (`apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12-49`) | Read, trace, request, delete, and recall permissions are not consistently enforced | **MATCH — W-LOT-A-1** |
| B9 | Safety decisions retain actor, reason, time, and operation identity | Exact immutable audit NV | Exact request audit NV | NV | Current entity stores only replaceable reason/time (`apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134-140`) | Missing append-only request/transition audit | **MATCH — W-LOT-A-1** |

Second-of-everything: Tasks 1–5 use a real second company, second `pos_enabled` location, and explicit replay/rerun cases. `product_batches` is an operator catalogue entity under convention 09’s definition (`docs/conventions/09-SECOND-OF-EVERYTHING.md:28-47`).

<a id="R3-M4"></a>
**Plan line R3-M4:** no guarantee implemented by this slice is labelled `DEFER` or `ALREADY`. Only ruled release/reject remains deferred.

## Vocabulary and ruled boundary

Retain **Lot (batch)**. Add:

| Term | Definition |
|---|---|
| Recall request | Immutable root evidence submitted for a company lot and one accessible location. |
| Branch hold | Server issue/transfer exclusion derived from an unresolved root at that location; it changes eligibility, not physical quantity, reservations, valuation, or GL. |
| Recall transition | Immutable terminal child recording company-wide recall disposition against a root. |
| General manager | Seeded role with the revised manager grants plus company-wide recall and Treasury all-location authority; assignment requires active unrestricted membership. |

Q10 is ruled as `requested → recalled` or `requested → released`, with release/reject restricted to the general manager and mandatory append-only reasons (`docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:147-154`). This slice implements only `requested → recalled`. W-LOT-A-1b owns release/reject, its permission, route, UI, enum extension, and tests.

The requesting branch never lifts its own hold. A root has at most one terminal child regardless of terminal status.

## Shared invariants

- Authorization failures are 403 before mutation.
- Foreign-company, inaccessible-location, absent-location, or absent-lot request targets are scoped 404.
- Malformed UUIDs and blank reasons are 422.
- An empty allowed-location list means no access; it never becomes unrestricted.
- Company-wide recall requires `batches.recall`, an active membership, and SQL `NULL` unrestricted location scope.
- Physical quantity, reservations, costing, movements, and GL do not change when a hold is requested.
- After hold commit, all fresh server issue/transfer paths must re-evaluate eligibility after their stock locks.
- A sealed POS event is retained if lot projection is refused; it emits a typed operational conflict instead of rewriting fiscal evidence.
- Device knowledge remains bounded by D4 refresh/session semantics; there is no instant mid-session push claim.
- Release/reject and device cache implementation remain outside this slice.

<a id="R3-M3"></a>
**Plan line R3-M3 — actor boundary:** add `apps/api/app/Shared/Contracts/Identity/ActorIdentityScopeData.php`:

```php
namespace App\Shared\Contracts\Identity;

final readonly class ActorIdentityScopeData
{
    /**
     * @param list<string> $abilities
     * @param list<string>|null $allowedLocationIds
     */
    public function __construct(
        public string $userId,
        public string $tenantId,
        public string $companyId,
        public bool $activeMembership,
        public array $abilities,
        public ?array $allowedLocationIds,
    ) {}
}
```

`null` means unrestricted; `[]` means no locations. Controllers derive this DTO from the authenticated request, the existing ability authorizer, and the Company location-scope service. BatchExpiry public services accept this DTO only. This follows the repository rule that cross-module communication uses `Shared/Contracts`, events, or public services (`CLAUDE.md:30-31`).

## Task 1 — Stage action permissions and make every batch read location-safe

**Dependencies:** none.

**Production files**

- Modify `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`.
- Modify `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`.
- Modify `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php`.
- Modify `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php`.
- Modify `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php`.
- Modify `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php`.
- Modify `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php` for scoped read methods.
- Add `apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php`.
- Add `apps/api/config/batch_recall.php`.
- Add `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallActivation.php`.

Current routes have module/auth middleware but no action gates on most batch surfaces (`apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12-49`). Current detail eagerly loads all batch-stock rows (`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:105-116`); current stock returns all locations (`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:264-281`); forward trace queries document and POS rows without location predicates (`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:60-102`).

**Final Push-5 route matrix**

| Surface | Required permission |
|---|---|
| List, detail, expiring, expired, batch stock, product batch stock, POS suggestions | `batches.view` |
| Forward/backward trace | `batches.traceability` |
| Create | existing `batches.create` |
| Update | existing `batches.update` |
| Delete/deactivate | `batches.delete` |
| Company-wide recall | `batches.recall` |
| Request recall | `batches.recall.request` |
| Existing write-off/reversal | retain `batches.write-off` |

Retain `api`, Sanctum, team permission, tenant-claim, module admission, literal-route ordering, and existing FormRequest gates.

**Read contracts**

```php
public function getByCompany(
    string $companyId,
    array $filters = [],
    ?array $locationIds = null,
): Collection;

public function getByProduct(
    string $tenantId,
    string $companyId,
    string $productId,
    bool $activeOnly = true,
    ?array $locationIds = null,
): Collection;

public function getExpiringProducts(
    string $companyId,
    int $daysThreshold = 30,
    string|array|null $locationId = null,
): Collection;
```

At every activated HTTP boundary:

1. Validate requested location UUIDs.
2. Resolve scope through `LocationScopeResolver`; no Treasury bypass.
3. Apply the same explicit IDs to the existence predicate and eager-loaded relation.
4. Treat `[]` as no visible rows.
5. For detail/stock, return 404 if no stock or trace occurrence exists in the allowed scope.
6. Scope document trace by document company/location and POS trace by receipt company/location.
7. Derive resource totals from the already-scoped loaded rows; current model accessors perform fresh unscoped sums (`apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:143-148`).
8. Preserve historical trace visibility for depleted lots when an authorized document/receipt occurrence exists at an allowed location.

**Red-first tests**

| Test | First assertion | Command | Lane |
|---|---|---|---|
| `BatchReadLocationScopeTest::test_detail_excludes_other_branch_stock` | `assertNotContains($locationA2, $response->json('data.batch_stock.*.location_id'))` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_detail_excludes_other_branch_stock')` | PostgreSQL |
| `BatchReadLocationScopeTest::test_every_read_filters_other_branch_and_empty_scope` | scoped IDs equal A1-only expectation | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_every_read_filters_other_branch_and_empty_scope')` | PostgreSQL |
| `BatchActionPermissionsTest::test_each_route_requires_its_action_permission` | denied response is 403 and snapshots are unchanged | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchActionPermissionsTest::test_each_route_requires_its_action_permission')` | PostgreSQL |

Second-company, second-location, malformed-location, empty-scope, NULL-document-location, depleted-history, and repeated-read cases are mandatory in those classes.

<a id="R3-M1"></a>
**Plan line R3-M1 — inert deployment and irreversible safety hardening**

Implementation commits are partitioned from deployment pushes:

| Stage | Existing reads/actions | New request mutation |
|---|---|---|
| Push 3 artifact, flag OFF | No existing route is rewired; responses and mutations remain byte/status compatible with the pre-Push-3 artifact. New services/classes are not selected by existing routes. | Unregistered or hard 404; no root can be created. |
| Push 4, flag OFF | Same inert artifact while permission delta/reseed/cache verification runs. | 404. |
| Push 5 hardening artifact, flag still OFF during settle | Existing reads become permissioned and location-scoped unconditionally. Existing delete/recall become permissioned and canonical unconditionally. Hold-aware issue code is selected. | 404. |
| Push 5 after verification, flag ON | Same scoped reads and existing-action enforcement. | Enabled with `batches.recall.request`. |
| Post-activation flag rollback, flag OFF | Reads remain permissioned/location-scoped; existing recall/delete remain permissioned; hold/global-recall issue safety remains active; history remains readable. | New root creation disabled with 404. |

Only `POST /batches/{uuid}/recall-requests` and its web authoring control use `batch_recall.enabled`. GET history, capability reporting, existing batch reads, existing action permissions, and issue safety do not revert when this flag changes.

Push-3 inertness is staging evidence, not a final-code PHPUnit fiction: capture the same authenticated A1/A2/company-B GET and existing authorized recall/delete response status/body projections before Push 3 and after Push 3; any delta fails Push 3. After Push 5, run the same matrix with the flag ON and OFF and require identical scoped reads under both states.

**Reviewer gates:** tenancy/authz reviewer for every route and precedence; inventory/costing reviewer for unchanged quantities and correctly scoped aggregates.

## Task 2 — Apply the role delta and guard general-manager assignment

**Dependencies:** Task 1 contracts.

**Production files**

- Modify `apps/api/database/seeders/RolesAndPermissionsSeeder.php`.
- Modify `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php`.
- Modify `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`.
- Modify relevant create/update user requests.
- Add `apps/api/app/Console/Commands/ApplyLotRecallPermissionDelta.php`.
- Add `apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php`.
- Add `apps/api/app/Modules/Identity/Domain/Enums/RoleProvisioningSource.php`.
- Add `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php`.

Current manager grants include `batches.recall`, and current viewer/operator sets omit batch view (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:632`, `:704-769`). Current user creation assigns the role before membership creation (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:217-252`), and the dedicated role endpoint is another assignment path (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:350-408`).

**Resulting seeded matrix**

| Role | `batches.*` result |
|---|---|
| admin | Existing batch grants plus `batches.recall.request`; retain recall and Treasury all-location authority |
| general_manager | Revised manager set plus `batches.recall` and `treasury.manage_all_locations` |
| manager | view, create, update, delete, write-off, traceability, recall.request; no company-wide recall |
| cashier | view only |
| viewer | view only |
| operator | view only |
| technician/accountant | no new batch grant |

Use targeted grant/revoke operations for standard roles. Preserve unrelated custom grants. Never `syncPermissions()` an existing/custom role. A pre-existing unmarked `general_manager` name is `ROLE_NAME_COLLISION`, with no mutation.

`roles.provisioning_source` is nullable `VARCHAR(32)` with the single allowed marker `w-lot-a-1`. First application locks manager and general-manager roles, creates exactly one marked role if absent, and is transactionally idempotent. A second apply reports `ALREADY_APPLIED`.

Create/update/role-endpoint ordering is:

1. Lock target user and applicable active memberships in company-ID order.
2. Compute final roles and final location scope.
3. Require every applicable active membership to be unrestricted for general-manager.
4. Write membership before role assignment on create.
5. Apply role mutation inside the same transaction.
6. Reject later narrowing while the role remains.
7. Permit atomic demotion plus narrowing when the final role set no longer contains general-manager.

Command contract:

```text
permissions:apply-lot-recall-delta
  {--apply : Apply while runtime authoring is off}
  {--verify : Read-only verification}
```

Stable marker:

```text
WLOTA1-PERMISSIONS tenant=<uuid> outcome=APPLIED|ALREADY_APPLIED|SKIPPED|FAILED reason=<token>
```

Exit 0: APPLIED/ALREADY_APPLIED/SKIPPED; exit 1: conflict, schema, or invariant failure; exit 2: invalid option combination. `--verify` succeeds only as `ALREADY_APPLIED`. `--apply --verify` is invalid.

**Red-first tests**

- `LotRecallSeededRoleMatrixTest::test_canonical_viewer_operator_lot_grants`.
- `LotRecallRoleDeltaTest::test_canonical_manager_no_longer_has_company_recall`.
- `GeneralManagerAssignmentTest::test_create_update_and_role_endpoint_reject_restricted_assignment`.
- `LotRecallRoleDeltaTest::test_delta_and_reseed_preserve_custom_permissions_on_rerun`.
- `LotRecallRoleDeltaTest::test_concurrent_initial_delta_marks_exactly_one_role`.

Run each with `./vendor/bin/phpunit -c phpunit-pgsql.xml --filter '<Class>::<method>'`.

**Reviewer gates:** tenancy/authz reviewer for all assignment paths and custom-role preservation; inventory reviewer for the approved recall authority split.

## Task 3 — Add immutable recall evidence, separated idempotency namespaces, and the scoped request writer

**Dependencies:** Tasks 1–2.

**Production files**

- Add `apps/api/database/migrations/tenant/2026_09_06_210000_create_batch_recall_requests_table.php`.
- Add `apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchRecallRequestStatus.php`.
- Add `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchRecallRequest.php`.
- Add typed request/transition/capability DTOs under `apps/api/app/Modules/BatchExpiry/Application/DTOs/`.
- Add `apps/api/app/Shared/Contracts/Identity/ActorIdentityScopeData.php`.
- Add `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallService.php`.
- Add `apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRecallRequest.php`.
- Add `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchRecallRequestController.php`.
- Modify `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`, registered only in the Push-5 hardening artifact.
- Modify `apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php`.
- Modify `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php`.

### Schema

`batch_recall_requests`:

| Column | Contract |
|---|---|
| `id` | UUID primary key, application generated |
| `tenant_id` | UUID, non-null |
| `company_id` | UUID, non-null, companies FK RESTRICT |
| `batch_id` | BIGINT, non-null, product_batches FK RESTRICT |
| `location_id` | UUID, non-null, locations FK RESTRICT |
| `request_id` | UUID nullable, self-FK RESTRICT |
| `operation_uuid` | UUID non-null |
| `status` | `VARCHAR(16)` non-null |
| `reason` | `VARCHAR(255)` non-null and trimmed non-empty |
| `actor_user_id` | UUID non-null, users FK RESTRICT |
| `created_at` | `TIMESTAMPTZ` non-null, default current timestamp |

No `updated_at`, soft delete, quantity, value, payload, or JSONB.

Entry kind is immutable and derived:

- Root: `request_id IS NULL` and `status='requested'`.
- Transition: `request_id IS NOT NULL` and `status='recalled'`.
- Future `released` is another transition status, never another root kind.

<a id="R3-B1"></a>
**Plan line R3-B1 — idempotency namespaces and open/terminal constraints**

Do not create `brq_company_operation_uq`. Create:

```sql
CREATE UNIQUE INDEX brq_root_operation_uq
ON batch_recall_requests (company_id, operation_uuid)
WHERE request_id IS NULL;

CREATE UNIQUE INDEX brq_transition_operation_uq
ON batch_recall_requests (company_id, operation_uuid)
WHERE request_id IS NOT NULL;

CREATE UNIQUE INDEX brq_company_terminal_uq
ON batch_recall_requests (company_id, request_id)
WHERE request_id IS NOT NULL;
```

The first two indexes deliberately permit the same UUID once in the root namespace and once in the transition namespace. The terminal index is independent of status and reserves one terminal child for recalled or future released.

Add `brq_open_scope_idx(company_id,batch_id,location_id,request_id)` plus PostgreSQL trigger `brq_one_open_scope_guard`. On root insert it takes the same batch-stock tuple lock used by the service and rejects another root for `(company_id,batch_id,location_id)` that has no child with `request_id = root.id`. It does not test terminal status, so any terminal child closes the open slot. The service performs the same check for a typed 409; the trigger is the direct-SQL/concurrency backstop.

Replay lookup is kind-specific:

```php
// Client root replay only:
WHERE company_id = :company
  AND operation_uuid = :operation
  AND request_id IS NULL

// Deterministic transition replay only:
WHERE company_id = :company
  AND operation_uuid = :operation
  AND request_id IS NOT NULL
```

A root replay validates actor, lot, location, and normalized reason. A transition replay validates its parent, transition kind, actor, and reason. No lookup may omit `request_id IS NULL/IS NOT NULL`.

Other constraints:

- Status check: requested/recalled only in this slice.
- Requested roots require NULL `request_id`; recalled transitions require non-NULL.
- `request_id <> id`.
- Parent must be a requested root with identical tenant/company/batch/location.
- UPDATE and DELETE rejection triggers make evidence append-only.
- PostgreSQL is authoritative; SQLite receives equivalent checks/triggers for schema portability.
- `down()` refuses when evidence exists.

### Deterministic transition UUID

Use RFC UUIDv5/SHA-1, namespace `4f1708cb-1f52-4e19-92e1-f25df3536e0f`.

Name bytes are compact UTF-8 JSON:

```text
[lowercase-company-uuid,lowercase-root-uuid,transition-kind,1]
```

Fixture:

```text
["11111111-1111-4111-8111-111111111111","22222222-2222-4222-8222-222222222222","recalled",1]
→ 463d9ce3-793e-53c0-bdc5-10e2fd6e90c9
```

### Public request contracts

```php
public function request(
    ActorIdentityScopeData $actor,
    string $batchUuid,
    CreateBatchRecallRequestData $data,
): BatchRecallRequestData;

/** @return list<BatchRecallRequestData> */
public function requests(
    ActorIdentityScopeData $actor,
    string $batchUuid,
): array;
```

Request behavior:

1. Validate operation/location UUID and normalized non-empty reason.
2. Resolve company lot and actor-visible location.
3. Require positive physical quantity, even if fully reserved.
4. Lock the batch-stock tuple.
5. Perform root-kind replay lookup first.
6. Reject mismatched replay as 409.
7. Reject a different operation while an unresolved root occupies the tuple as `OPEN_REQUEST_EXISTS`.
8. Reject a new request for an already globally recalled lot.
9. Insert one root; commit is hold activation.
10. Fresh response is 201; matching replay is 200 with original ID and `replayed=true`.
11. A historical operation UUID never bypasses current authorization.

Routes registered in Push 5:

- `GET /api/v1/batches/recall-capabilities`, literal before `{uuid}`.
- `GET /api/v1/batches/{uuid}/recall-requests`, view-permission and location scoped under both flag states.
- `POST /api/v1/batches/{uuid}/recall-requests`, request permission and authoring flag.

### PostgreSQL adversarial namespace test

Add:

```text
BatchRecallRequestSchemaTest::
test_root_operation_cannot_burn_deterministic_transition_namespace
```

Fixture:

1. Create A1 root R1.
2. Compute R1’s future recalled UUIDv5 T1.
3. Create an independent A2 root whose client operation UUID is T1.
4. Recall the company lot.
5. Assert both roots receive their own transition, R1’s transition uses T1, and the A2 root with the same UUID remains intact.
6. Assert a duplicate root UUID still fails root uniqueness, a duplicate transition UUID still fails transition uniqueness, and each root still has one terminal child.

Literal assertions:

```php
self::assertSame(1, $rootNamespaceCount);
self::assertSame(1, $transitionNamespaceCount);
self::assertSame(2, $terminalChildCount);
```

TDD order:

1. Add schema-presence and namespace tests first.
2. Capture HEAD red for missing schema.
3. After the table skeleton exists but before operation indexes, rerun the namespace test and capture the duplicate-root assertion red.
4. Add both partial indexes and triggers.
5. Rerun green in PostgreSQL.

Command:

```bash
(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml \
  --filter 'BatchRecallRequestSchemaTest::test_root_operation_cannot_burn_deterministic_transition_namespace')
```

### Convention-09 catalogue registration

<a id="R3-M2"></a>
**Plan line R3-M2:** current `CATALOGUE_TABLES` omits `product_batches` (`apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:30-54`), and the pinned subset is at `:196-200`.

In `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php`:

- Add `product_batches` to `CATALOGUE_TABLES`.
- Add `product_batches` to `PINNED_CATALOGUE_TABLES`.
- Add `batch_recall_requests` to `EXCLUDED_TABLES` with: `Immutable recall workflow evidence; operation and terminal keys are company-qualified and are not operator catalogue identities.`
- Do not add either table to both sets.
- Do not add a waiver or raise either ceiling.
- Do not add a baseline JSON entry because every new recall unique contains `company_id`; the existing product-batch business unique is company-scoped (`apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:45-46`).

Required command:

```bash
(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml \
  --filter 'TenantOnlyUniqueOnCatalogueTablesRatchetTest')
```

Require the live-index baseline test, pinned-catalogue test, and explicit-classification test to pass; those methods currently live at `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:211-269`.

**Reviewer gates:** tenancy/authz for replay/scope/namespace isolation; inventory/costing for append-only evidence and no stock/value mutation.

## Task 4 — Introduce the canonical issue-eligibility contract and route every server consumer through it

**Dependencies:** Task 3 schema.

Current atomic FEFO signature lacks `companyId` (`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:234-242`). Its `FOR UPDATE ... SKIP LOCKED` query filters product/location but has no tenant/company predicate (`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:260-273`).

Current direct transfer locks stock and mutates immediately after quantity validation (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:499-537`). StockTransferService computes FEFO at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:882-915` and validates explicit allocations at `:993-1008`.

<a id="R3-B3"></a>
**Plan line R3-B3 — canonical typed contract**

Add:

- `BatchIssueEligibilityReason.php`
- `BatchIssueEligibilityQueryData.php`
- `BatchIssueEligibilityDecisionData.php`
- `BatchIssueEligibilityDecisionSetData.php`
- `BatchIssueEligibilityService.php`
- `App\Shared\Contracts\ProductCompanyLookup.php` and its Product-module adapter for the compatibility shim.

```php
enum BatchIssueEligibilityReason: string
{
    case Inactive = 'inactive';
    case GloballyRecalled = 'globally_recalled';
    case Expired = 'expired';
    case Reserved = 'reserved';
    case LocallyHeld = 'locally_held';
}

final readonly class BatchIssueEligibilityQueryData
{
    /**
     * @param list<int>|null $batchIds null means every candidate in scope
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $productId,
        public ?string $variantId,
        public string $locationId,
        public string $quantity,
        public CarbonImmutable $asOf,
        public ?array $batchIds = null,
    ) {}
}

final readonly class BatchIssueEligibilityDecisionData
{
    /** @param list<BatchIssueEligibilityReason> $reasons */
    public function __construct(
        public int $batchId,
        public bool $eligible,
        public array $reasons,
        public string $physicalQuantity,
        public string $reservedQuantity,
        public string $eligibleQuantity,
    ) {}
}

final readonly class BatchIssueEligibilityDecisionSetData
{
    /** @param list<BatchIssueEligibilityDecisionData> $decisions */
    public function __construct(
        public string $sourceRevision,
        public CarbonImmutable $sourceCapturedAt,
        public int $snapshotAgeSeconds,
        public array $decisions,
    ) {}
}

final class BatchIssueEligibilityService
{
    public function decide(
        BatchIssueEligibilityQueryData $query,
    ): BatchIssueEligibilityDecisionSetData;

    /** @param list<BatchIssueEligibilityQueryData> $queries */
    public function decideMany(array $queries): array;
}
```

Rules:

- Validate tenant/company/product/variant/location as one tuple.
- Compare expiry to the supplied `asOf`, never an implicit per-row `now()`.
- Return the complete unique reason set in fixed enum order.
- `locally_held` means a requested root exists at the tuple with no terminal child of any status.
- `reserved` means physical stock exists but reservation leaves no issuable quantity for that candidate.
- Genuine aggregate shortage remains the caller’s existing shortage outcome; it is not given a fabricated eligibility reason.
- Blocked rows remain in decision output with `eligibleQuantity='0.0000'`.
- `eligible=true` only when the reason set is empty and eligible quantity is positive.
- Decimal quantities remain scale-4 strings.
- Server mutation paths call the decision service again after their existing locks and before their first movement/allocation write.

`sourceRevision` is an opaque lowercase SHA-256 of canonical JSON containing the query tuple, UTC `asOf` date, and sorted candidate facts: batch ID, active/global-recall/expiry state, stock/reserved quantities, relevant row timestamps, and unresolved-root/terminal IDs and timestamps. It changes when any eligibility source or the effective expiry day changes. `sourceCapturedAt` is one database-clock timestamp captured for the read transaction. `snapshotAgeSeconds=max(0, asOf-sourceCapturedAt)`.

W-LOT-B owns its positive monotonic `snapshotRevision`; it increments that revision when this `sourceRevision` changes, copies `sourceCapturedAt` as `serverCapturedAt`, and recomputes visible age from that timestamp. It must not infer eligibility separately.

### Atomic FEFO signature and SQL

Change to:

```php
public function consumeBatchesAtomically(
    string $tenantId,
    string $companyId,
    string $productId,
    string $locationId,
    string $quantity,
    string $movementId,
    ?string $variantId = null,
    bool $strictFulfillment = true,
): BatchConsumptionResultDTO;
```

The locked candidate SQL must include:

```sql
WHERE b.tenant_id = ?
  AND b.company_id = ?
  AND ibs.tenant_id = ?
  AND b.product_id = ?
  AND <variant predicate>
  AND ibs.location_id = ?
...
FOR UPDATE OF ibs SKIP LOCKED
```

After selection/lock, FEFO delegates candidate decisions to `BatchIssueEligibilityService`; it does not duplicate inactive, recall, expiry, reservation, or hold logic.

Compatibility shim:

```php
/** @deprecated Use consumeBatchesAtomically() with explicit companyId. */
public function consumeBatchesAtomicallyForProductCompany(
    string $tenantId,
    string $productId,
    string $locationId,
    string $quantity,
    string $movementId,
    ?string $variantId = null,
    bool $strictFulfillment = true,
): BatchConsumptionResultDTO;
```

`consumeBatchesAtomicallyForProductCompany()` resolves exactly one company through `App\Shared\Contracts\ProductCompanyLookup::companyIdForTenantProduct($tenantId,$productId)`, rejects absent/mismatched products, and delegates to the new signature. It never derives company from location, ambient HTTP state, or an unscoped model lookup.

Update the in-scope production callers to pass explicit company ID. Existing out-of-slice callers may use the named shim for one compatibility window, but still reach the canonical decision service. Current production call sites requiring compilation coverage are DeliveryNote (`apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:389-396`), ReceiptCreation (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:1438-1445`, `:1617-1625`), and POS projection (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2046-2060`).

### Required server routing

1. **POS FEFO consumption:** projection calls atomic FEFO with `event->company_id`; held conflicts emit `BATCH_HELD_PROJECTION_CONFLICT`, preserve sealed evidence, and create no held-lot movement/allocation.
2. **Direct batch transfer:** add `companyId` to `BatchStockService::transferBatchStock`; call the canonical decision after source lock and before either movement.
3. **StockTransferService:** both automatic `computeFefoSplit()` and explicit `assertBatchCanIssue()` consume the canonical decisions; neither calls `Batch::canBeSold()` as a competing predicate.
4. **W-LOT-B snapshot:** planned `PosLotEligibilitySnapshotService::snapshot()` injects `BatchIssueEligibilityService`, calls `decideMany()` for its company/location product-variant scopes, and maps the returned decisions/revision/capture time. It must not query `Batch`, `BatchStock`, or recall-request entities directly. This replaces the older plan’s direct FEFO query requirement at `docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:784-846`.

### Tests

| Test | Required result | Command | Lane |
|---|---|---|---|
| `BatchIssueEligibilityContractTest::test_reason_set_covers_every_canonical_dimension` | exact five-reason enum and deterministic order | PHPUnit filter | PostgreSQL |
| `BatchIssueEligibilityContractTest::test_source_revision_changes_for_stock_reservation_recall_hold_and_expiry_day` | every source mutation changes revision; identical rerun does not | PHPUnit filter | PostgreSQL |
| `BatchRecallHoldEligibilityTest::test_all_three_server_issue_paths_use_the_same_decision` | A1 held; POS/direct/StockTransfer reject or choose fallback; A2/B unaffected | PHPUnit filter | PostgreSQL |
| `BatchRecallHoldConcurrencyTest::test_waiting_consumers_recheck_after_committed_hold` | no held-lot movements after barrier release | PHPUnit filter | PostgreSQL |
| `AtomicFefoCompanyScopeTest::test_skip_locked_query_cannot_select_same_product_from_another_company` | B rows never selected | PHPUnit filter | PostgreSQL |
| `AtomicFefoCompanyScopeTest::test_legacy_shim_derives_exact_product_company` | shim delegates to same decision and rejects mismatch | PHPUnit filter | PostgreSQL |
| `BatchIssueEligibilityArchitectureTest::test_issue_consumers_do_not_reimplement_predicate` | no competing active/recall/expiry/hold checks in the four consumers | PHPUnit filter | no DB |

Exact command form:

```bash
(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter '<Class>::<method>')
```

**Reviewer gates:** tenancy/authz for tuple correlation and company derivation; inventory/costing for lock order, FEFO fallback, decimal precision, reservation semantics, and unchanged valuation.

## Task 5 — Make BatchRecallService the sole lifecycle writer and append company-wide transitions

**Dependencies:** Tasks 2–4.

<a id="R3-B2"></a>
**Plan line R3-B2 — complete current writer/setup census**

Production lifecycle writers/exposures at this baseline:

| Current path | Disposition |
|---|---|
| Controller calls entity mutator (`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193-205`) | Replace with `BatchRecallService::recall()`. |
| Entity overwrites fields (`apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134-140`) | Remove public `recall()`. |
| Repository overwrite (`apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:149-155`) | Remove repository `recall()` interface/implementation. |
| Recall fields are fillable (`apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:34-46`) | Remove from `$fillable`; generic update rejects all three keys. |
| Demo seeder resets recall fields (`apps/api/database/seeders/DemoPharmacySeeder.php:576-578`) | Remove reset keys; skip recalled/evidenced fixture refresh and emit `WLOTA1-DEMO outcome=SKIPPED_RECALL_EVIDENCE batch=<uuid>`. |
| BatchStockService/FEFO false-only new-lot defaults (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:387-400`; `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:811-828`) | Classify as insert-only false defaults; prefer DB default and never allow update/reset. |
| Historical migration defaults (`apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:35-38`) | Preserve and classify as schema declarations. |
| Batch factory definition/recalled state (`apps/api/database/factories/BatchExpiry/BatchFactory.php:19-50`) | Retain as the sole explicitly allowlisted historical test-fixture writer; production code/seeders may not call `recalled()`. |
| Daily-check and phantom-repair recall predicates (`apps/api/app/Modules/BatchExpiry/Infrastructure/Commands/BatchExpiryDailyCheckCommand.php:165`; `apps/api/app/Console/Commands/RepairPhantomDefaultBatchesCommand.php:656`) | Read-only predicates, not writers. |
| POS HeldOrder `recalled_at` | Different `pos_held_orders` concept; table-qualified exclusion only. |

Every current direct test historical-state setup:

| Current path | Required replacement |
|---|---|
| `apps/api/tests/Unit/BatchExpiry/GetExpiredBatchesWithStockTest.php:193-203` | `Batch::factory()->recalled()->create(...)`; no direct recall keys. |
| `apps/api/tests/Unit/BatchExpiry/FEFOInventoryServiceTest.php:196-209`, invoked recalled at `:84-98` and `:183-193` | Helper selects `Batch::factory()->recalled()` when requested; remove `is_recalled` from create payload. |
| `apps/api/tests/Unit/BatchExpiry/BatchEntityTest.php:114-122` | `Batch::factory()->recalled()->make(...)`; no constructor recall key. |
| `apps/api/tests/Unit/BatchExpiry/FEFOSuggestionPrecisionTest.php:282-295` | Remove unused `isRecalled` parameter and direct false key; rely on DB/factory default. |
| `apps/api/tests/Feature/BatchExpiry/AtomicFEFOConsumptionTest.php:254-276` | Remove unused `isRecalled` parameter and direct false key. |
| `apps/api/tests/Feature/BatchExpiry/FEFOInventoryServiceVariantTest.php:221-241` | Remove unused `isRecalled` parameter and direct false key. |
| `apps/api/tests/Feature/Replenishment/ReplenishmentActionsTest.php:155-159` | Create recalled lot through `Batch::factory()->recalled()` before stock setup. |
| `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php:507-512` | Use recalled factory state; do not call removed entity method. |
| `apps/api/tests/Feature/Inventory/StockTransferAutoAllocateFefoTest.php:170-176` | Use recalled factory state. |
| `apps/api/tests/Feature/Inventory/CountingVarianceAppliedTest.php:599-628` | Call canonical `BatchRecallService::recall()` with an unrestricted test actor DTO because these tests mutate an already-created lot. |
| `apps/api/tests/Feature/Inventory/StockAdjustmentBatchDispositionTest.php:833-845` | Call canonical service after the contra draft is created; rename method to `test_a_contra_stays_postable_when_its_lot_is_recalled_mid_draft`. |
| `apps/api/database/factories/BatchExpiry/BatchFactory.php:31,44-50` | Explicit allowlist entry: false insert default plus `recalled()` historical factory state. |

Literal false/null keys in fresh `Batch::create()` calls are classified as insert defaults, not historical recalled state. The test-source scanner rejects any true/non-null recall state outside the exact factory method and rejects all test calls to removed entity/repository mutators.

### Production and test-source ratchets

Add `apps/api/tests/Architecture/BatchRecallWriterRatchetTest.php`:

- Scan `apps/api/app` and `apps/api/database`.
- Detect update, updateOrCreate, upsert, fill, forceFill, raw attribute assignment, model assignment/save, query updates, and recall-mutator calls.
- Only `BatchRecallService::recall()` may update existing `product_batches` recall fields.
- Classify exact schema/false-insert/BatchFactory/HeldOrder exclusions by table, method, and write mode.
- No directory-wide allowlist.
- Liveness fixture containing `DB::table('product_batches')->update(['is_recalled'=>true])` must be reported.

Add `apps/api/tests/Architecture/BatchRecallTestFixtureCensusTest.php`:

```php
private const ALLOWED_HISTORICAL_FIXTURES = [
    'apps/api/database/factories/BatchExpiry/BatchFactory.php::recalled'
        => 'single historical global-recall factory state',
];
```

It scans `apps/api/tests` plus the Batch factory. Allowed setup mechanisms are:

- `Batch::factory()->recalled()->make/create`;
- `BatchRecallService::recall(ActorIdentityScopeData, ...)`;
- literal false/null values on a provably fresh insert.

Any direct true/non-null constructor, update, raw assignment, or removed mutator call fails with `path:line` and requires explicit classification. Its liveness fixture injects the omitted `update(['is_recalled'=>true])` form.

The production ratchet remains stricter: factory permission never authorizes production/direct lifecycle updates.

### Company-wide recall contract

```php
public function recall(
    ActorIdentityScopeData $actor,
    string $batchUuid,
    string $reason,
): Batch;
```

Behavior:

1. Require `batches.recall`, active membership, and unrestricted location scope.
2. Normalize/validate reason.
3. Resolve batch within actor company.
4. Lock the parent batch exclusively.
5. On first execution, update global recall fields through an explicit scoped query.
6. Lock unresolved roots in UUID order.
7. Append one recalled transition per root, using transition-kind replay lookup and deterministic UUIDv5.
8. Never overwrite root or existing terminal evidence.
9. Same-reason retry returns original timestamp/evidence.
10. Different-reason retry returns 409.
11. Direct company-wide recall without a root remains valid and does not invent one.
12. Request-vs-recall race either transitions the committed request or rejects request creation after recall.

### Exact Task-5 added/modified files

<a id="R3-N2"></a>
**Plan line R3-N2:** this list was regenerated against the planning baseline.

Production/factory:

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/RecallBatchRequest.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallService.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php`
- `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php`
- `apps/api/database/seeders/DemoPharmacySeeder.php`
- `apps/api/database/factories/BatchExpiry/BatchFactory.php`

New tests:

- `apps/api/tests/Architecture/BatchRecallWriterRatchetTest.php`
- `apps/api/tests/Architecture/BatchRecallTestFixtureCensusTest.php`
- `apps/api/tests/Feature/BatchExpiry/BatchRecallWriterRegressionTest.php`
- `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php`
- `apps/api/tests/Feature/BatchExpiry/BatchRecallTransitionUuidTest.php`

Existing tests modified:

- `apps/api/tests/Unit/BatchExpiry/GetExpiredBatchesWithStockTest.php`
- `apps/api/tests/Unit/BatchExpiry/FEFOInventoryServiceTest.php`
- `apps/api/tests/Unit/BatchExpiry/BatchEntityTest.php`
- `apps/api/tests/Unit/BatchExpiry/FEFOSuggestionPrecisionTest.php`
- `apps/api/tests/Feature/BatchExpiry/AtomicFEFOConsumptionTest.php`
- `apps/api/tests/Feature/BatchExpiry/FEFOInventoryServiceVariantTest.php`
- `apps/api/tests/Feature/Replenishment/ReplenishmentActionsTest.php`
- `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php`
- `apps/api/tests/Feature/Inventory/StockTransferAutoAllocateFefoTest.php`
- `apps/api/tests/Feature/Inventory/CountingVarianceAppliedTest.php`
- `apps/api/tests/Feature/Inventory/StockAdjustmentBatchDispositionTest.php`

### Exact Task-5 verification matrix

| File / method | Literal assertion | Command | Lane |
|---|---|---|---|
| `BatchRecallWriterRatchetTest::test_existing_recall_writers_use_canonical_service` | `assertSame([], $violations)` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchRecallWriterRatchetTest::test_existing_recall_writers_use_canonical_service')` | no DB |
| `BatchRecallTestFixtureCensusTest::test_every_direct_historical_setup_is_classified` | `assertSame([], $violations)` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchRecallTestFixtureCensusTest::test_every_direct_historical_setup_is_classified')` | no DB |
| `GetExpiredBatchesWithStockTest::test_recalled_expired_lot_with_available_stock_is_excluded` | count remains zero (`apps/api/tests/Unit/BatchExpiry/GetExpiredBatchesWithStockTest.php:213-217`) | PHPUnit PG filter | PostgreSQL |
| `FEFOInventoryServiceTest::test_fefo_skips_recalled_batches` | selected ID is active lot (`apps/api/tests/Unit/BatchExpiry/FEFOInventoryServiceTest.php:90-98`) | PHPUnit PG filter | PostgreSQL |
| `FEFOInventoryServiceTest::test_get_total_available_quantity_excludes_expired_and_recalled` | total equals 15 (`apps/api/tests/Unit/BatchExpiry/FEFOInventoryServiceTest.php:183-193`) | PHPUnit PG filter | PostgreSQL |
| `BatchEntityTest::test_batch_cannot_be_sold_when_recalled` | `assertFalse($batch->canBeSold())` (`apps/api/tests/Unit/BatchExpiry/BatchEntityTest.php:114-122`) | PHPUnit PG filter | PostgreSQL |
| `ReplenishmentActionsTest::test_create_transfer_returns_422_when_sellable_batch_stock_is_insufficient` | 422/INSUFFICIENT_STOCK and zero transfer (`apps/api/tests/Feature/Replenishment/ReplenishmentActionsTest.php:148-176`) | PHPUnit PG filter | PostgreSQL |
| `InventoryTransferServiceTest::test_batch_tracked_transfer_blocks_recalled_or_expired_batches` | existing exception contract (`apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php:507-515`) | PHPUnit PG filter | PostgreSQL |
| `StockTransferAutoAllocateFefoTest::test_flag_true_raises_insufficient_stock_when_sellable_batches_short` | `InsufficientStockException` (`apps/api/tests/Feature/Inventory/StockTransferAutoAllocateFefoTest.php:170-182`) | PHPUnit PG filter | PostgreSQL |
| `CountingVarianceAppliedTest::test_a_shortage_reaches_a_recalled_lot` | aggregate/lot both 28 (`apps/api/tests/Feature/Inventory/CountingVarianceAppliedTest.php:599-614`) | PHPUnit PG filter | PostgreSQL |
| `CountingVarianceAppliedTest::test_a_shortage_prefers_saleable_lots_over_recalled_ones` | saleable 27, recalled 20 (`apps/api/tests/Feature/Inventory/CountingVarianceAppliedTest.php:620-638`) | PHPUnit PG filter | PostgreSQL |
| `StockAdjustmentBatchDispositionTest::test_a_contra_stays_postable_when_its_lot_is_recalled_mid_draft` | aggregate and lot remain 10 (`apps/api/tests/Feature/Inventory/StockAdjustmentBatchDispositionTest.php:833-850`) | PHPUnit PG filter | PostgreSQL |
| `CompanyWideBatchRecallTest::test_general_manager_recall_spans_branches_and_transitions_requests` | A1/A2 recalled; B unchanged | PHPUnit PG filter | PostgreSQL |
| `CompanyWideBatchRecallTest::test_recall_retry_preserves_timestamp_reason_and_transition_count` | exact original timestamp and transition count | PHPUnit PG filter | PostgreSQL |
| `BatchRecallTransitionUuidTest::test_uuidv5_fixture_is_stable` | exact UUID `463d9ce3-793e-53c0-bdc5-10e2fd6e90c9` | PHPUnit PG filter | PostgreSQL |

For every row labelled “PHPUnit PG filter”, use:

```bash
(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter '<Class>::<method>')
```

**Reviewer gates:** tenancy/authz for unrestricted recall authority and company isolation; inventory/costing for writer exclusivity, transition atomicity, and unchanged stock/value.

## Task 6 — Generate types/permissions and expose request/history on existing batch detail

**Dependencies:** Tasks 1–5.

**Files**

- Modify `apps/web/src/routes/index.tsx`.
- Modify `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`.
- Modify `apps/web/src/features/batches/pages/BatchListPage.tsx`.
- Modify `apps/web/src/features/batches/pages/BatchDetailPage.tsx`.
- Modify `apps/web/src/features/batches/api/batches.ts`.
- Modify `apps/web/src/features/batches/hooks/useBatches.ts`.
- Modify `apps/web/src/features/batches/types.ts`.
- Regenerate `apps/web/src/hooks/permissionsMap.generated.ts`.
- Regenerate `packages/shared/types/generated.d.ts`.
- Modify English/French batch locale files.
- Modify `docs/glossary.md`.

Current batch routes are guarded only by the generic inventory key (`apps/web/src/routes/index.tsx:1180-1190`). Current detail decides actions only from batch state (`apps/web/src/features/batches/pages/BatchDetailPage.tsx:75-77`) and sends `recall_reason` while the API validates `reason` (`apps/web/src/features/batches/pages/BatchDetailPage.tsx:43-55`; `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193-197`). Current generated batch-view mapping lacks viewer/operator (`apps/web/src/hooks/permissionsMap.generated.ts:13-19`).

Requirements:

- Route permissions: list/detail view, create, update, write-off.
- Action permissions: edit/update, deactivate/delete, recall/recall, request/recall.request, trace/traceability.
- Unauthorized trace queries remain disabled.
- Correct the existing recall payload to `reason`.
- Capability hook uses `tenantScopedKey(['batches','recall-capabilities'])`.
- OFF/loading/error/missing capability fails closed.
- Request location options are the intersection of scoped active locations and positive physical batch-stock locations.
- Fully reserved positive physical stock remains requestable.
- Operation UUID is generated once per submission intent and retained across network retries.
- Company/batch/scope change clears pending UUID/location state.
- Display request and transition reasons separately.
- No handwritten domain DTOs; aliases point to generated PHP DTO declarations.
- Add eager feature marker `wlota1-recall-request-v4` to the batch route metadata so it survives in the served entry bundle.

Generation:

```bash
(cd apps/api && php artisan typescript:transform)
(cd apps/api && php artisan permissions:export-frontend-map)
```

Required Vitest cases:

- seeded viewer/operator map;
- direct route permission denial;
- manager sees request but not company recall;
- viewer cannot mutate or fetch trace;
- request retry retains UUID/location/payload;
- selector excludes A2;
- company switch discards stale state;
- history separates request/transition reason;
- dormant capability hides submission;
- capability hook key changes A→B and fails closed;
- sidebar hides batches without view.

Run the repository React diagnostics workflow, then web typecheck, lint, Vitest, and the applicable Playwright batch journey.

**Reviewer gates:** tenancy/authz for client gating/query behavior; inventory/costing for physical-versus-eligible quantity presentation.

## Deployment and rollback

Deployment follows `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`
(five-push sequence §2, web block §3, gate checklist §4). This slice supplies only the variables
below; it does not restate deploy mechanics.

| Variable | W-LOT-A-1 value |
|---|---|
| `<slice>` | `wlota1-permissions-hold` |
| Migrations list | `2026_09_06_205000_add_provisioning_source_to_roles.php`, then `2026_09_06_210000_create_batch_recall_requests_table.php`; additive, self-guarding, exact-definition validating |
| Flags | `batch_recall.enabled` / `BATCH_RECALL_ENABLED` / `apps/api/config/batch_recall.php` / default false; controls only new request authoring after Push 5 |
| Commands | permission delta under `tenants:run`, safe role reseed, per-tenant cache reset, generated type/map commands |
| Censuses | day-one, POS VAT legs, inventory lot drift, phantom DEFAULT dry run; exact gate below |
| Web changes | yes; marker `wlota1-recall-request-v4`; execute the canonical web deploy block |
| Device build | no |
| Queues | none new |
| Collapsed pushes | Push 1 is the pre-Push-2 read-only evidence step; no empty tooling push |
| Env path | Dokploy Environment tab unless U-1 proves compose wiring is authoritative |

Push sequence:

1. **Pre-Push-2/P1:** capture tenant set, backups, and all four census baselines.
2. **Push 2:** additive schemas only.
3. **Push 3:** dormant/unwired service artifact; flag false; verify existing behavior is unchanged.
4. **Push 4:** manual targeted permission delta, safe reseed, cache reset, verification, and repeat censuses; flag false.
5. **Push 5:** deploy existing-route hardening and web artifact while flag remains false; verify scoped reads/issue safety; then enable authoring and run browser/API smoke.

Rollback:

- P2 schemas remain; correct forward.
- P3 can be reverted because no existing route was rewired and no evidence can exist.
- P4 preserves role provenance and recorded before-state; correct targeted grants forward and never blanket-sync or restore manager recall automatically.
- P5 flag rollback disables only new request creation. It must not roll back location-scoped reads, existing action permissions, canonical eligibility, global-recall safety, or evidence tables.
- Never delete roots/transitions or clear global recall as software rollback.
- Web UI may roll back independently while backend safety remains.

<a id="R3-M5"></a>
## Plan line R3-M5 — executable census gate

The manifest requires grep-gated censuses because `tenants:run` discards child exit codes (`docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:36`, `:151-158`). Marker sources are:

- Day-one: `DAY-ONE CENSUS ... CLEAN|DRIFT(n)` (`apps/api/app/Console/Commands/DayOneCensusCommand.php:72-86`).
- VAT clean/drift summaries (`apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:333-364`).
- Lot drift summary (`apps/api/app/Console/Commands/LotLedgerDriftCensusCommand.php:129-138`).
- Phantom stable summary and dry-run marker (`apps/api/app/Console/Commands/RepairPhantomDefaultBatchesCommand.php:285-298`).

Run this exact Bash gate inside the API container with `PHASE=p1` before Push 2 and `PHASE=p4` after Push 4:

```bash
export SLICE=wlota1-permissions-hold
export PHASE=p1
# Change only PHASE to p4 for the post-Push-4 execution.

bash -euo pipefail <<'BASH'
tenant_current="/tmp/${SLICE}-tenant-ids-${PHASE}.txt"
php artisan tenants:list 2>&1 |
  tee "/tmp/${SLICE}-tenants-${PHASE}.log" |
  grep -oE '[0-9a-f-]{36}' |
  sort -u > "$tenant_current"

test -s "$tenant_current"
expected="$(wc -l < "$tenant_current" | tr -d ' ')"
test "$expected" -gt 0

if [ "$PHASE" = p1 ]; then
  cp "$tenant_current" "/tmp/${SLICE}-tenant-ids-baseline.txt"
else
  cmp -s "/tmp/${SLICE}-tenant-ids-baseline.txt" "$tenant_current"
fi

day_pass=0
vat_pass=0
lot_pass=0
phantom_pass=0
: > "/tmp/${SLICE}-vat-${PHASE}.tsv"
: > "/tmp/${SLICE}-phantom-${PHASE}.txt"

while IFS= read -r tenant; do
  day_log="/tmp/${SLICE}-dayone-${PHASE}-${tenant}.log"
  php artisan tenants:run tenant:census-day-one \
    --tenants="$tenant" \
    --option='fail-on-drift=1' 2>&1 | tee "$day_log"

  test "$(grep -Ec "^Tenant: ${tenant}$" "$day_log")" -eq 1
  test "$(grep -Ec "^DAY-ONE CENSUS ${tenant} [0-9a-f-]{36}: CLEAN$" "$day_log")" -ge 1
  ! grep -Eq 'DRIFT\(|NO-COMPANY|FAILED|Exception|Error' "$day_log"
  day_pass=$((day_pass + 1))

  vat_log="/tmp/${SLICE}-vat-${PHASE}-${tenant}.log"
  php artisan tenants:run pos:census-vat-legs \
    --tenants="$tenant" 2>&1 | tee "$vat_log"

  test "$(grep -Ec "^Tenant: ${tenant}$" "$vat_log")" -eq 1
  ! grep -Fq 'Chart of accounts is not provisioned for POS:' "$vat_log"

  vat_clean="$(grep -Fc 'POS output-VAT leg census: none — every POS receipt carries its sealed output VAT in the ledger.' "$vat_log" || true)"
  vat_drift_lines="$(grep -Ec '^POS output-VAT leg census: [0-9]+ receipt\\(s\\)' "$vat_log" || true)"
  test $((vat_clean + vat_drift_lines)) -eq 1

  if [ "$vat_clean" -eq 1 ]; then
    vat_count=0
  else
    vat_count="$(sed -nE 's/^POS output-VAT leg census: ([0-9]+) receipt\\(s\\).*/\1/p' "$vat_log")"
    test -n "$vat_count"
  fi
  printf '%s\t%s\n' "$tenant" "$vat_count" >> "/tmp/${SLICE}-vat-${PHASE}.tsv"
  vat_pass=$((vat_pass + 1))

  lot_log="/tmp/${SLICE}-lot-${PHASE}-${tenant}.log"
  php artisan inventory:lot-drift-census \
    --tenant="$tenant" \
    --fail-on-drift 2>&1 | tee "$lot_log"

  test "$(grep -Fc 'Tuples drifted: 0' "$lot_log")" -eq 1
  test "$(grep -Fc 'Read-only census: nothing was written.' "$lot_log")" -eq 1
  ! grep -Eq '^[[:space:]]+DRIFT ' "$lot_log"
  lot_pass=$((lot_pass + 1))

  phantom_log="/tmp/${SLICE}-phantom-${PHASE}-${tenant}.log"
  php artisan inventory:repair-phantom-default-batches \
    --tenant="$tenant" \
    --dry-run 2>&1 | tee "$phantom_log"

  test "$(grep -Fc 'Dry run: nothing was written. Re-run with --execute to apply.' "$phantom_log")" -eq 1
  summary_count="$(grep -Ec '^(DEFAULT lots inspected|Phantom DEFAULT lots|Total phantom quantity|Reservations re-pointed to real lots|Reservations left on the DEFAULT lot|Lots only partially reduced|Lots skipped \\(excess changed under the lock\\)|Tuples still drifted): ' "$phantom_log")"
  test "$summary_count" -eq 8

  printf 'tenant=%s\n' "$tenant" >> "/tmp/${SLICE}-phantom-${PHASE}.txt"
  grep -E '^(DEFAULT lots inspected|Phantom DEFAULT lots|Total phantom quantity|Reservations re-pointed to real lots|Reservations left on the DEFAULT lot|Lots only partially reduced|Lots skipped \\(excess changed under the lock\\)|Tuples still drifted): ' "$phantom_log" \
    >> "/tmp/${SLICE}-phantom-${PHASE}.txt"
  phantom_pass=$((phantom_pass + 1))
done < "$tenant_current"

test "$day_pass" -eq "$expected"
test "$vat_pass" -eq "$expected"
test "$lot_pass" -eq "$expected"
test "$phantom_pass" -eq "$expected"

sort -o "/tmp/${SLICE}-vat-${PHASE}.tsv" "/tmp/${SLICE}-vat-${PHASE}.tsv"

if [ "$PHASE" = p4 ]; then
  cmp -s "/tmp/${SLICE}-vat-p1.tsv" "/tmp/${SLICE}-vat-p4.tsv"
  cmp -s "/tmp/${SLICE}-phantom-p1.txt" "/tmp/${SLICE}-phantom-p4.txt"
fi

echo "WLOTA1-CENSUS kind=day-one phase=${PHASE} outcome=PASS tenants=${expected}"
echo "WLOTA1-CENSUS kind=vat-legs phase=${PHASE} outcome=PASS tenants=${expected}"
echo "WLOTA1-CENSUS kind=lot-drift phase=${PHASE} outcome=PASS tenants=${expected}"
echo "WLOTA1-CENSUS kind=phantom-default phase=${PHASE} outcome=PASS tenants=${expected}"
BASH
```

Pass/fail contract:

- The script exits 0 only after emitting all four exact PASS markers.
- Each marker’s `tenants=N` must equal the non-empty captured tenant-ID cardinality.
- A missing/duplicate tenant header or census marker fails.
- Day-one accepts only CLEAN.
- Lot drift accepts only zero tuples and no `DRIFT` row; any `BATCH_HELD_PROJECTION_CONFLICT` reflected as lot drift is actionable failure.
- VAT accepted delta is exactly zero normalized count change between P1 and P4.
- Phantom accepted delta is exactly zero across the eight normalized summary fields per tenant.
- Any VAT/phantom change requires investigation and a separately reviewed baseline decision; the operator may not overwrite the P1 files and rerun.
- Because `set -euo pipefail`, any command, grep, count, tenant-set comparison, or baseline comparison failure exits non-zero before PASS markers.

## Final verification checklist

- [ ] Planning SHA still matches implementation start; if not, re-read and repin all cited seams.
- [ ] Convention-10 rows use MATCH/DIVERGE for this slice’s work.
- [ ] Push 3 existing-route before/after evidence is identical.
- [ ] Push 5 reads remain scoped with the authoring flag ON and OFF.
- [ ] Viewer/operator receive view only; manager loses company recall and gains request.
- [ ] General-manager assignment requires active unrestricted membership in every applicable company.
- [ ] `product_batches` and `batch_recall_requests` are classified in convention-09 manifests.
- [ ] Root and transition operation UUIDs use separate partial unique indexes.
- [ ] Adversarial pre-computed transition collision passes in PostgreSQL.
- [ ] One unresolved root per company/batch/location is enforced without terminal-status coupling.
- [ ] Public recall contracts contain no Identity model import.
- [ ] Request/transition evidence is append-only and replay-safe.
- [ ] Production recall writer ratchet is strict and live.
- [ ] Test-source recall setup census is exhaustive and explicitly allowlisted.
- [ ] Canonical eligibility returns the exact five-reason set, source revision, capture time, and age.
- [ ] Atomic FEFO SQL includes tenant and company predicates.
- [ ] POS projection, direct transfer, StockTransferService, and W-LOT-B snapshot use the canonical decision service.
- [ ] A1 hold blocks all fresh server issues; A2/company B remain independent.
- [ ] No hold changes physical/reserved stock, valuation, movements, or GL.
- [ ] Global recall transitions every unresolved root exactly once and preserves existing terminal evidence.
- [ ] Generated PHP→TypeScript declarations and permission map are reproducible.
- [ ] All four census gates emit exact PASS markers for the full tenant set.
- [ ] Both reviewers approve every task before Push 5.
