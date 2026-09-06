# Slice plan W-LOT-A-1 — lot permissions, general-manager role, policy-neutral branch hold (rev 3)

Planning baseline: **`d64675d9e50b1ea77aa7f0a647c666ca30c58dba`** (current HEAD read from repository metadata without invoking Git). Existing-code citations were re-read at this baseline. Only this rev-3 plan is written; no code, tests, migrations, or Git commands are executed. All proposed files and signatures below are implementation contracts.

## Round-two change log

Review read in full: `docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r2.md`. All items are accepted with the scope clarification mandated by the round-two handoff for B-R2-1; no owner decision is reopened. This document is the only file written. Tests are not run: red expectations below are source-checked predictions, and the implementation handback must record actual red/green output.

| Gate item | Closure in rev 3 |
|---|---|
| B-R2-1 | Live POS path is device-authored fiscal ingest and subsequent projection, not the retired online create endpoint. A-1 guarantees immediate **server-consumption** exclusion; W-LOT-B owns device cache distribution and refresh-at-session-open blocking. Separate acceptance rows and explicit held payload fields define the interface. No device-immediate claim or device build in A-1. |
| B-R2-2 | Task 5 enumerates controller/entity/repository/generic mass-assignment/demo seeder/default-lot/factory/schema writers. Remove alternate recall mutators, harden generic updates, stop demo recall resets, and add a writer ratchet plus liveness fixture. |
| M-R2-1 | Task 1 applies resolver-derived location scope to list/detail/stock/product-stock/POS suggestions and both traceability arms, including scalar totals and empty membership. |
| M-R2-2 | Task 2 adds viewer/operator batches.view only, retains cashier view, manager view/traceability/request and other approved operational grants, general-manager recall, and custom-role preservation. Seeded API/map and rerun acceptance added. |
| M-R2-3 | Task 3 owns complete capability route/controller/generated DTO; Task 6 owns fetch/hook/key/loading/error contracts and direct assertions. |
| M-R2-4 | Exact flag-aware middleware and OFF-before-delta/OFF-after-delta/ON-after-reset matrix. Read gates activate in Push 5; dormant recall/deactivate are unavailable, never unguarded. Existing FormRequest gates remain. |
| M-R2-5 | Source-rechecked red entry points separated from supplemental green/dependency tests. Schema existence is asserted before schema rows; no missing-table exception accepted as red. Retired online 422 case removed. |
| N-R2-1 | Repinned to current metadata HEAD above; code citations re-read. |
| N-R2-2 | Push 1 collapsed into pre-Push-2 census evidence, no empty tooling push. |
| N-R2-3 | Exact `Permission cache flushed.` marker pinned, with entrypoint invocation and emitting command distinguished. |
| N-R2-4 | UUIDv5 namespace, canonical JSON framing, fixed sequence and expected fixture output specified. |
| N-R2-5 | General-manager operational mapping is ruled; benchmark prose no longer asks for another role decision. |

Prior r1 closures retained: Q10 ruled/later-slice boundary, single terminal child, typed transition evidence, atomic assignment order, durable role provenance, generated types, scoped selector, canonical manifest and six-task ceiling. Prior M5/M6 now follow the reachable POS path and honest test classifications below.

## Industry baseline — convention 10

Flow: permissioned lot reads and recall, location-specific recall requests, and immediate server-consumption/transfer exclusion.

References: Odoo/OCA 18.0 `stock_lock_lot`; ERPNext Batch and User Permissions documentation, unversioned pages retrieved 2026-09-06. `NV` means the specific guarantee was not verified; it does not mean the product lacks it.

OCA documents a blocked-lot flag, dedicated block/unblock authority, and destination exceptions; its documented workflow does not describe a request approval chain. ERPNext supplies batch disabling and document restrictions through User Permissions. These are useful comparators, but neither establishes the exact branch-request lifecycle proposed here. [OCA Stock Lock Lot](https://github.com/OCA/stock-logistics-workflow/tree/18.0/stock_lock_lot), [ERPNext Batch](https://docs.frappe.io/erpnext/batch), [ERPNext User Permissions](https://docs.frappe.io/erpnext/user-permissions), [ERPNext Batch schema](https://github.com/frappe/erpnext/blob/develop/erpnext/stock/doctype/batch/batch.json).

| ID | Guarantee | Odoo | ERPNext | Dolibarr (or NV + reason) | AutoERP today path:line | Gap | Decision (MATCH/DEFER/DIVERGE/ALREADY) |
|---|---|---|---|---|---|---|---|
| create | An authorized operator can prevent unsafe lot issue | OCA blocked-lot flag | Batch disabled flag | NV — hold workflow not verified | [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134) writes company-wide recall | No branch-request writer in this path | MATCH — add immediate branch hold |
| duplicate | A repeated request cannot create repeated effects | NV — request identity undocumented | NV — request identity undocumented | NV — request identity undocumented | [BatchController.php:193](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193) accepts reason without operation identity | No stable request operation UUID | DEFER — comparator verification in W-LOT-A-1b; company-scoped idempotency is required here |
| edit | Submitted safety evidence remains attributable | OCA documents editable blocking state, not this immutable request contract | NV — immutable hold evidence not verified | NV — immutable hold evidence not verified | [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134) replaces recall fields | Recall reason/time can be overwritten | DEFER — immutable-evidence comparator verification in W-LOT-A-1b; append-only evidence is required here |
| cancel | Disposition authority is explicit | Dedicated block/unblock authority | NV — this disposition chain not verified | NV — disposition authority not verified | [Batch.php:121](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:121) checks global recall | Release/reject policy ruled; implementation belongs to W-LOT-A-1b | DEFER — W-LOT-A-1b under ruled Q10 |
| rerun | Retrying an operation preserves its original outcome | NV — retry contract undocumented | NV — retry contract undocumented | NV — retry contract undocumented | [Batch.php:136](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:136) updates timestamp on invocation | Retry changes evidence | DEFER — comparator verification in W-LOT-A-1b; explicit replay is required here |
| second company | A request cannot affect another company’s lot | NV — exact request workflow absent from cited module | User Permissions offer document restrictions; exact request isolation NV | NV — company isolation for holds not verified | [BatchTraceabilityController.php:49](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:49) rejects another company’s batch | New request storage must preserve isolation | MATCH — company-scoped storage, lookup and operation identity |
| second location | A branch hold affects only its location | OCA documents destination exceptions, not this source-location hold | Warehouse restrictions through User Permissions; batch disabling is a separate mechanism | NV — branch-local hold not verified | [FEFOInventoryService.php:267](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:267) filters location and global recall | No location-specific hold predicate | DIVERGE — branch-local hold, company-wide recall |
| permission | Each safety action requires its own authority | Dedicated block/unblock permission | Role permissions plus User Permissions | NV — exact action matrix not verified | [routes.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12) has module/auth middleware; affected routes lack action middleware | Seeded action permissions are not enforced on these routes | MATCH — enforce API and web permissions |
| audit | Safety decisions retain actor, reason and time | NV — immutable request audit not documented | NV — exact request audit not verified | NV — exact request audit not verified | [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134) records reason/time, not request history | Missing immutable branch-request evidence | DEFER — comparator verification in W-LOT-A-1b; actor/operation evidence is required here |

The GMP comparator assigns approval/rejection authority to the quality-control unit and requires written responsibilities and records. It does **not** independently establish that AutoERP’s general manager is a legally qualified quality authority. The operational general-manager mapping is already ruled in [OWNER-RULINGS:113](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:113); no further role decision is required. The benchmark alone does not establish pharmaceutical legal qualification. [21 CFR 211.22](https://www.ecfr.gov/current/title-21/chapter-I/subchapter-C/part-211/subpart-B/section-211.22).

## Vocabulary — convention 11

**Vocabulary:** retain **Lot (batch)** as the existing stock concept; introduce **Recall request**, **Branch hold**, and **General manager** as distinct concepts. The existing glossary identifies the lot and its stock tables at [docs/glossary.md:41](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:41).

Proposed glossary rows:

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| Recall request | Immutable, reasoned request to recall a lot, submitted for a location where the requester has access and the lot is present. Its effective state in this slice is requested or recalled; a later W-LOT-A-1b disposition occupies the same exclusive terminal slot. | `batch_recall_requests` / BatchExpiry; sole lifecycle writer `BatchRecallService` after Task 5 retires alternate mutators; insert-only defaults excluded | Existing batch detail → Request recall; history on the same detail | branch recall request |
| Branch hold | Server lot-consumption and transfer exclusion at the location named by an unresolved recall request. Devices learn eligibility through the W-LOT-B cache refresh contract. It changes eligibility, not physical quantity, reservation or value. | Derived from `batch_recall_requests`; no separate hold table or writer | Existing batch detail → location stock and request history | local lot hold |
| General manager | Seeded permission role containing the manager grants plus company-wide recall and Treasury all-location authority; assignment requires unrestricted active company membership. | Existing Spatie roles; `user_company_memberships.allowed_location_ids = NULL` | Existing user/role administration | general_manager |

Do not add a second recall-management page, alternate hold writer, or handwritten frontend domain DTO.

## Owner ruling — Q10 RULED

Verbatim from [OWNER-RULINGS:151](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151), section “Q10–Q13 RULED”:

| ID | Ruling |
|---|---|
| **Q10** | Hold lifecycle `requested → recalled` or `requested → released`; only the general manager may release or reject; mandatory reason and append-only evidence; the requesting branch never lifts its own hold. |

This slice implements **only requested → recalled**. Release/reject actions are **DEFERRED to W-LOT-A-1b**, under this accepted ruling. The owner explicitly retains the policy-neutral scope for already-issued slices at [OWNER-RULINGS:158](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:158). No owner decision is required before dispatch.

Forward compatibility means a root can have **at most one terminal child**, regardless of its status. W-LOT-A-1b will add the `released` enum/check alternative additively, using this same terminal slot and typed transition evidence. This slice does not expose a release/reject writer, permission, route, UI or behavioral test.

## Server/device guarantee and W-LOT-B interface — B-R2-1

The live new-sale HTTP route returns **410 NEW_SALE_AUTHORING_RETIRED** before controller validation at [POS/routes.php:168](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/routes.php:168) and [POS/routes.php:187](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/routes.php:187). Do not change that route or modify the retired ReceiptController/ReceiptCreationService new-sale path in this slice. Live fiscal receipt projection calls non-strict FEFO after sealing at [PosCoreReceiptProjection.php:2046](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2046); held-lot conflict must preserve sealed financial evidence.

**A-1 guarantee:** after a hold commits, no new server-side consumption of that held lot at that location may pass the eligibility check. This includes receipt-projection FEFO, direct batch transfer and StockTransferService allocation. Concurrency uses the shared stock locks: a consumption committed before hold creation wins before the hold; one waiting behind a committed hold must recheck and refuse. An eligible alternative lot may still satisfy server FEFO. Physical stock, reservations and valuation do not change merely because a hold is created.

**Device boundary:** a terminal sells against its cached branch lot snapshot. It learns a new hold on its next refresh, not at the instant the server creates it. D4 explicitly requires refresh before session open and permits the cached snapshot during that session with visible age ([OWNER-RULINGS:12](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:12)). A-1 does not claim push invalidation, immediate mid-session device blocking, or retroactive rejection of a sealed sale.

**W-LOT-B owns the device implementation.** Its included scope is eligibility cache/session-open acknowledgement at [W-LOT-B plan:89](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:89); it consumes A's canonical requested-hold predicate at [W-LOT-B plan:119](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:119). Those are interface/ownership references, not approval of that older package plan's stale governance sections.

W-LOT-B's proposed `PosLotEligibilityItemData` at [plan:606](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:606) currently lists no hold field. Its consuming slice **must add** explicit `isHeld: bool` and `isRecalled: bool` to each branch-scoped item, and `eligibleForIssue: bool` computed by the one server predicate; `serverAvailableQuantity` for a held lot is `0.0000` as eligible quantity, not a rewrite of physical quantity. Snapshot envelope retains tenant/company/location/terminal, revision and capture timestamp. Retain blocked items or explicit tombstones in an atomic full-snapshot replacement so a previously cached eligible lot cannot survive omission. A-1's `BatchIssueEligibilityService::isHeld(tenantId, companyId, batchId, locationId)` is the shared server read contract; W-LOT-B owns DTO/cache transport edits, terminal authentication, snapshot replacement and pre-seal checkout checks. No duplicate recall/hold writer is introduced in B.

| Acceptance ID | Owner / delivery | Required result |
|---|---|---|
| A-SERVER-1 | This slice, Task 4 | Commit A1 hold, then invoke fresh server FEFO/projection/transfer work: no A1 held-lot consumption or allocations; A2 and company B unaffected. |
| A-SERVER-2 | This slice, Task 4 | A device event already sealed before refresh is retained; projector refuses held-lot allocation and emits BATCH_HELD_PROJECTION_CONFLICT without rewriting fiscal evidence. |
| B-CACHE-1 | W-LOT-B delivery gate | After server hold, refresh branch snapshot: payload marks held and ineligible; atomic cache replacement removes any formerly eligible version. No new local checkout allocation of that lot after refresh. |
| B-CACHE-2 | W-LOT-B delivery gate | T1/T2 share A1. T1 refreshes after hold and blocks; T2 remains on an older open-session snapshot with visible age until its next refresh. After T2 refreshes/session opens it also blocks. No assertion of instant remote knowledge. |
| B-CACHE-3 | W-LOT-B delivery gate | Offline/stale snapshot behavior follows D4: mandatory refresh/acknowledgement before a new session, cached during current session with visible age; A2/company-B cache keys never inherit A1 hold. |

B-CACHE rows are integration acceptance owed by W-LOT-B, not claimed complete or executed by A-1. Device build remains **no** for this slice. Server A-SERVER rows are mandatory before A-1 activation; launch/device acceptance must also include B-CACHE rows in W-LOT-B.

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
- The API returns the root’s effective status from its single terminal child, if present. Request and transition reasons are separate fields; the root is never overwritten.
- Never update the root’s reason, actor, location, operation UUID or status.
- The global `product_batches.is_recalled` remains the company-wide recall projection.
- Each transition receives a UUIDv5 operation UUID under the exact algorithm below; it is stable across workers and reruns.

A successful hold does not decrement stock, change reservations, book inventory movements or write GL entries.

### Deterministic transition identity — N-R2-4

Use RFC UUID version 5 (SHA-1), fixed namespace `4f1708cb-1f52-4e19-92e1-f25df3536e0f`. Lowercase validated company/root UUID strings. The name bytes are UTF-8 of compact JSON `[company_id, request_id, transition_kind, sequence]`, no whitespace, no trailing newline; sequence is the JSON integer **1**, not a string, because this lifecycle has one terminal slot. Current transition kind is exactly `recalled` from the PHP enum. PHP implementation: `Uuid::uuid5($namespace, json_encode([$companyId, $requestId, $kind->value, 1], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->toString()` using `Ramsey\Uuid\Uuid`. Do not use a random UUID, delimiter concatenation or locale formatting.

Fixture name: `["11111111-1111-4111-8111-111111111111","22222222-2222-4222-8222-222222222222","recalled",1]` → **`463d9ce3-793e-53c0-bdc5-10e2fd6e90c9`**. Task 5 adds `public static function transitionOperationUuid(string $companyId, string $requestId, BatchRecallRequestStatus $kind): string` to BatchRecallService and a pure fixture assertion. Validate that kind is terminal before encoding. Future released uses its own kind in the same sequence-1 slot; status-independent terminal uniqueness still prevents both outcomes.

### Activation contract shared by Tasks 1–6

New `apps/api/config/batch_recall.php`:

```php
return ['enabled' => (bool) env('BATCH_RECALL_ENABLED', false)];
```

New `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallActivation.php`, constructor-injected, exposes `public function enabled(): bool`. Request creation, new role provisioning/delta application, changed batch action activation and all eligibility consumers use the same config key `batch_recall.enabled`. Task 2 adds an explicit CLI `--apply` override for Push 4 while runtime activation remains false. A safe seeder never creates an unmarked general-manager role while dormant. Exported static permission definitions remain available for build-time map generation.

When false, new recall-request POST returns 404 and the new web form stays hidden. Add `GET /api/v1/batches/recall-capabilities` (literal route before `{uuid}`, existing middleware, `batches.view`) with `{data:{enabled:boolean}}`; it remains callable while dormant. `BatchRecallRequestController::capabilities(): JsonResponse` reads the same flag. Task 6 caches it under `tenantScopedKey(['batches','recall-capabilities'])` and fails closed while loading/error.

False is **not** permission to sell previously held stock: if the table exists and unresolved roots persist, consumers retain their hold predicate as a data-safety latch even with the activation flag false. An OFF deployment with no schema must not query the absent table. A flag rollback disables creation and new workflow activation while preserving existing hold/global-recall safety. This is required for the manifest's flag-off rollback to be safe after real requests exist. Add ON/OFF-with-evidence/OFF-without-schema tests. No worker-local interpretation or separate flag is allowed.

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

Fixture properties: `Tenant $tenant`; `Company $companyA`, `$companyB`; `Location $locationA1`, `$locationA2`, `$locationB1`; `User $owner`, `$manager`, `$cashier`, `$viewer`, `$operator`; `Batch $batchA`, `$fallbackBatchA`, `$variantBatchA`, `$batchB`; `string $operationUuid`; typed/PHPDoc deterministic scalar snapshots. **No general-manager role, new enum/DTO/service, recall-request table or route is required by baseline setup.** Provision existing users/companies/locations through HEAD's real HTTP paths. Use an existing admin only for fixture provisioning, not the denied action. Authorize BatchExpiry through existing entitlement setup. Task-1 scope tests use an existing manager with A1 membership and existing batch view; Task-2 seeded-role tests do not inject missing view grants. New general-manager assignment is the act phase after its prerequisite delta, never a baseline setup requirement.

`setUp(): void` calls parent, configures real database-per-tenant testing, seeds central registration prerequisites and calls `buildRegisteredLotRecallFixture()`. Setting the new config key true in a test is harmless at HEAD even before a config file exists; it must not invoke a missing reader class. `tearDown(): void` ends tenancy, purges connections, drops only the fixture's physical tenant database and removes only its central registration/token rows, then calls parent in `finally`, matching [RegistrationResponseIsPureJsonTest.php:62](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php:62). No CREATE DATABASE inside a transaction. Race tests commit fixture setup before independent connections and join/close workers before cleanup.

Add test-only helper `apps/api/tests/Feature/BatchExpiry/Concerns/SeedsHeldLotAtBaseline.php`, `protected function seedHeldLotAtBaseline(): void`. For the Task-4 source-red eligibility test only, if the new table is absent the helper creates the exact planned recall table columns/FKs/indexes using test DDL, then inserts a requested root with primitive strings and existing actor/lot/location IDs. No new production model/enum/service is referenced, and no migration success is asserted by this helper. It does not install production triggers or implement eligibility behavior. Once Task 3 schema exists, the helper merely inserts the same row. The Task-3 schema-presence red test never calls this helper; this separation prevents test scaffolding from making a missing production migration look green.

Registered PG fixture consumers: all feature test classes in Tasks 1–5 except the pure static seeder-role test and pure UUID test, plus the architecture test's feature regression companion. `BatchRecallWriterRatchetTest` and `LotRecallSeededRoleMatrixTest::test_canonical_viewer_operator_lot_grants` need no DB fixture. `BatchRecallRequestSqliteSchemaTest` uses in-memory parent schema and installed slice migrations **after** Task 3 implementation; it is a supplemental portability test, never HEAD red. Generated-type checks likewise run after declarations exist. Vitest supplies known current auth/batch/stock mocks and adds new capability mocks only for the dependent integration cases.

For denied mutations and retries, compare deterministic before/after snapshots of:

- Batch recall fields.
- Lot and aggregate quantities/reservations.
- Batch and stock movements.
- Transfer records and allocations.
- Recall-request entries.
- Relevant audit records.

### Test commands, source-checked red entry points and supplemental checks

No tests were executed during this read-only revision. **Source-checked red** means the current code was inspected and the table names the observed contrary behavior; actual failure output is mandatory during implementation. Never report a runtime red without running it. Each task has at least one independently runnable test entry whose baseline-compatible setup reaches the literal assertion. Add tests first, run the exact red-entry command, capture that assertion failure, then implement and rerun. If setup fails or the assertion is already green, correct the fixture or reclassify it; do not count it as red.

The retained large acceptance tables are explicitly **supplemental**. In particular: company-scoped 404 already works at [BatchController.php:68](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:68); missing new route 404 is already true at HEAD; hold creation is intentionally non-mutating; company B unchanged is existing isolation. None proves a new red. Schema rejection tests run only after the table exists, and seed/delta/capability integration cases run after those dependencies. Test-only DDL for an eligibility red cannot be used by a schema-presence red. Retired POS create 410 remains a green preservation check.

## Task 1 — Stage API action permissions and scope every batch read

**Dependencies:** none. Task 1 creates the shared `apps/api/config/batch_recall.php` and `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallActivation.php` specified above. Tasks 2–6 consume this contract. Push 3 ships the complete dormant code set; each task remains buildable in order.

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

Mutation signatures below remain compatible; forward trace gains Request as specified in the complete read contracts below:

```php
public function destroy(string $uuid): JsonResponse;
public function recall(Request $request, string $uuid): JsonResponse;
public function forwardTrace(Request $request, string $uuid): JsonResponse;
public function backwardTrace(Request $request, string $partnerId): JsonResponse;
```

**Implementation:**

1. Add `BatchActionAccess` middleware with the exact activation policy below; do not add unconditional new `can:` gates during Push 3. Existing FormRequest and write-off guards stay unconditional and unchanged.
2. Validate `location_id` as nullable UUID, matching `expired()`.
3. Resolve allowed locations through `LocationScopeResolver`.
4. Pass the resulting explicit list into the service.
5. Apply identical location filtering to `whereHas('batchStock')` and eager-loaded `batchStock`; an empty list returns no lots.

Current `expiring()` accepts a raw location string, while `expired()` validates and resolves scope at [BatchController.php:215](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:215) and [BatchController.php:237](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:237).

### Complete read-scope and activation contracts — M-R2-1/M-R2-4

Task 1 additionally modifies `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php`, `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php`, and `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php`. New middleware file: `apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php`.

```php
// BatchActionAccess; inject BatchRecallActivation in constructor.
public function handle(Request $request, Closure $next, string $permission): Response;

// BatchController read methods:
public function index(Request $request): AnonymousResourceCollection;
public function show(Request $request, string $uuid): JsonResponse;
public function stock(Request $request, string $uuid): JsonResponse;
public function productBatchStock(Request $request, string $productId): JsonResponse;
public function posAvailableBatches(Request $request, string $productId): JsonResponse;
public function expiring(Request $request): JsonResponse;
public function expired(Request $request): JsonResponse;

// BatchTraceabilityController (inject LocationScopeResolver):
public function forwardTrace(Request $request, string $uuid): JsonResponse;
public function backwardTrace(Request $request, string $partnerId): JsonResponse;

// Repository interface AND implementation; PHPDoc list<string>|null.
public function getByCompany(string $companyId, array $filters = [], ?array $locationIds = null): Collection;
public function getByProduct(string $tenantId, string $companyId, string $productId, bool $activeOnly = true, ?array $locationIds = null): Collection;
```

At an activated HTTP read boundary call `LocationScopeResolver::resolve($request->user(), $validatedRequestedIds)` without Treasury bypass; pass the resolved explicit list to repository/query/loaded relations. Empty allowed scope yields empty lists and 404 for inaccessible detail. Explicit foreign/disallowed list/suggestion selections yield resolver 403; malformed UUID yields 422. A detail UUID with no lot stock or trace occurrence at any allowed location yields 404. For forward trace of depleted historical lots, authorized document/receipt occurrence at an allowed location can establish visibility; do not require positive current stock to read history. NULL membership means resolver supplies all company location IDs; an explicit empty array never becomes NULL. Console/worker services never invoke the HTTP-only resolver.

| Read endpoint | Required scoped predicates |
|---|---|
| List/index and product batch-stock | `whereHas(batchStock, location_id in resolved)` plus identically filtered eager load; historical-only rows can be requested through trace, not leaked through an unscoped relation. Preserve ordering, variant and tenant/company predicates. |
| Detail/show and stock | Scoped batch visibility first; load only batch-stock rows at allowed locations. Shared lot metadata may be shown, A2 stock cannot. |
| Expiring/expired | Same resolved IDs in both existence predicate and eager load, including []; preserve existing expired validation. |
| POS suggestions | Validate UUID and company existence, resolve `[location_id]`, then call FEFO; never let company ownership substitute for staff location authority. |
| Forward trace | Scoped batch visibility; document lines constrained by document company and allowed document location; POS allocations constrained by receipt company and allowed receipt location. Counts computed from scoped rows only. |
| Backward trace | Preserve company/partner filter and add document location in resolved IDs; return [] for no authorized history; filter all optional read selections consistently. |

Current unscoped seams: [BatchController.php:112](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:112), [BatchRepository.php:79](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:79), [BatchRepository.php:116](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:116), [BatchTraceabilityController.php:61](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:61) and [BatchTraceabilityController.php:116](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:116). Document location is nullable ([Document.php:45](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Domain/Document.php:45)); restricted users see no NULL-location document history, while unrestricted members may see company-scoped NULL-location history. Receipt location is persisted ([Receipt.php:139](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/Receipt.php:139)). Test both unknown-location and other-branch history.

**Scalar totals are also scoped.** BatchResource currently emits model total/availability at [BatchResource.php:39](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:39), whose accessors execute fresh unscoped sums at [Batch.php:143](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:143). In activated HTTP resources derive total_quantity/available_quantity from the already-scoped loaded collection with decimal-string BCMath; never invoke those unscoped accessors. Keep physical vs eligible quantity distinction. Do not widen this into unrelated float cleanup.

Middleware registration uses the class directly: `->middleware(BatchActionAccess::class.':batches.view')` (corresponding permission per route matrix); imports `Closure`, `Illuminate\Http\Request`, `Symfony\Component\HttpFoundation\Response`. It first preserves authenticated tenant/company/module admission from the enclosing group, then:

| Phase | Config | New read/trace gates and scopes | Existing recall/deactivate endpoints | New request writer |
|---|---|---|---|---|
| Push 3, before delta | OFF | Legacy read reach stays available; no new permission denial or new scope behavior until activation. Existing guards still apply. | Return 404 while dormant, even for admin; operation intentionally unavailable rather than executing an unguarded legacy mutation. | 404 |
| Push 4, after delta | OFF | Same legacy reads; viewer/operator now have view but new gate still dormant. | Same 404; seeding never activates routes. | 404 |
| Push 5, after per-tenant verification/cache reset | ON | Require route permission via `Gate::authorize($permission)`; apply resolver scopes. Viewer/operator authorized to view, not trace. | Require delete or recall permission; recall also requires unrestricted active membership and canonical service. | Require request permission and scoped location |
| Flag rollback after evidence | OFF | Restore dormant admission behavior only as documented; keep active evidence-safety latch in issue consumers. | 404; no recall bypass. | 404 |

The dormant 404 is an explicit temporary admission fence, not an early grant or partial activation of the new workflow. Read access is not taken away before the role delta. No unconditional can middleware on newly gated reads; no flag-off branch calls the legacy recall/deactivate handler. Existing create/update/transfer/write-off FormRequests remain enforced throughout. Middleware uses an explicit allowlist of permission arguments and fails closed for unknown arguments. API and worker activation read the same cached config; Push 5 requires readiness for every tenant before switching ON. No activation in either migration.

### Source-checked red entry points — execute before production change

| Exact test file | Class/suite::method/case | Literal target assertion | Baseline-compatible setup | Contrary behavior at HEAD | Exact command | Lane |
|---|---|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | `BatchReadLocationScopeTest::test_detail_excludes_other_branch_stock` | `self::assertNotContains($this->locationA2->id, array_column($response->json('data.batch_stock'), 'location_id'));` | Use registered fixture, A1-only manager, same lot physical stock at A1/A2, enabled=true; call existing GET detail. No new classes/table. | Current show eagerly loads both branches ([BatchController.php:112](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:112)); valid existing manager request returns data containing A2. | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_detail_excludes_other_branch_stock')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchGateActivationTest.php` | `BatchGateActivationTest::test_dormant_recall_is_unavailable_before_role_delta` | `$response->assertNotFound();` | Existing authorized admin/current manager fixture; config false; valid reason and own-company UUID. Existing route and payload, no new service. | Current recall executes entity update ([BatchController.php:204](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:204)) and returns 200, not dormant 404. | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchGateActivationTest::test_dormant_recall_is_unavailable_before_role_delta')` | phpunit PG |

After the target red is captured, implement this task and rerun it to green. The supplemental acceptance below has exact commands but is not evidence that HEAD was red.

**Supplemental regression/dependency acceptance — not claimed red at HEAD:**

Run these cases after their required schema/production dependencies exist. They are supplemental green regressions or downstream integration acceptance; none is counted as HEAD red evidence. Setup must finish before the listed expected assertion. The source-checked red-entry table immediately above is authoritative for TDD ordering. Commands run from repository root.

| Exact new test file | Class::method | Expected acceptance assertion (not red claim) | Exact command | Lane | Convention-09 / explicit rerun outcome |
|---|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | `BatchActionPermissionsTest::test_cashier_and_viewer_cannot_mutate_or_trace_without_permission` | `$response->assertForbidden();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchActionPermissionsTest::test_cashier_and_viewer_cannot_mutate_or_trace_without_permission')` | phpunit PG | A/B and A1/A2 snapshots unchanged; repeat denied call yields 403 with no effects |
| `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | `BatchActionPermissionsTest::test_reads_require_batches_view` | `$response->assertForbidden();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchActionPermissionsTest::test_reads_require_batches_view')` | phpunit PG | repeat read under same actor remains 403; grant-view control succeeds |
| `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | `BatchActionPermissionsTest::test_module_and_company_guards_remain_effective` | `$foreignBatchResponse->assertNotFound();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchActionPermissionsTest::test_module_and_company_guards_remain_effective')` | phpunit PG | company B foreign batch absent; repeat 404 unchanged |
| `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php` | `BatchExpiringLocationScopeTest::test_expiring_validates_uuid_and_filters_loaded_stock_to_allowed_locations` | `$invalidUuidResponse->assertUnprocessable();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchExpiringLocationScopeTest::test_expiring_validates_uuid_and_filters_loaded_stock_to_allowed_locations')` | phpunit PG | then assert loaded location IDs equal [A1]; A2 absent; repeat list equal |
| `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php` | `BatchExpiringLocationScopeTest::test_empty_membership_scope_returns_no_expiring_lots` | `$response->assertJsonPath('data', []);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchExpiringLocationScopeTest::test_empty_membership_scope_returns_no_expiring_lots')` | phpunit PG | empty scope in either company; rerun returns [] |
| `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php` | `BatchExpiringLocationScopeTest::test_registered_second_company_location_and_read_rerun` | `self::assertSame([$this->locationA2->id], $loadedLocationIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchExpiringLocationScopeTest::test_registered_second_company_location_and_read_rerun')` | phpunit PG | real registration; select A2 then switch B; repeat response equals original scoped data |

**Additional required supplemental cases introduced by round 2:**

| Exact file | Class::method | Expected assertion | Exact command | Lane | Coverage |
|---|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | `BatchReadLocationScopeTest::test_every_read_filters_other_branch_and_empty_scope` | `self::assertSame($expectedVisibleIds, $actualVisibleIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_every_read_filters_other_branch_and_empty_scope')` | phpunit PG | provider list/detail/stock/product-stock/expiring/expired/forward trace/backward trace; A1/A2/B data, scoped sums/counts, empty scope |
| `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | `BatchReadLocationScopeTest::test_pos_suggestions_reject_other_branch_location` | `$response->assertForbidden();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_pos_suggestions_reject_other_branch_location')` | phpunit PG | A1 manager requests A2; malformed 422; A1 valid succeeds; second company 403/404 contract; retry unchanged |
| `apps/api/tests/Feature/BatchExpiry/BatchGateActivationTest.php` | `BatchGateActivationTest::test_off_before_delta_off_after_delta_and_on_after_reset` | `self::assertSame($expectedStatus, $response->status());` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchGateActivationTest::test_off_before_delta_off_after_delta_and_on_after_reset')` | phpunit PG | phase/actor/route provider uses exact activation matrix; OFF reads 200 for intended readers, mutations404; ON viewer/operator view200 trace403, manager recall403, unrestricted general-manager recall200 |

**Task 1 convention-09 mapping:** second company: module/company guards and registered read-rerun test; second location: expiring scope; rerun: exact same read/403/404 responses and zero stock/history changes.

**Migration/schema:** none.

**Reviewer gate:** tenancy-authz-reviewer verifies every route and response precedence; inventory-costing-reviewer verifies unchanged stock/history and filtered relation data.

**Rollback:** follow the canonical per-push variables below; retain permissioned/hold-aware code once activated. Do not restore publicly callable recall as a production rollback.

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

- Consume Task 1’s `apps/api/config/batch_recall.php` and `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallActivation.php`; do not create duplicate flags/readers.
- `apps/api/app/Console/Commands/ApplyLotRecallPermissionDelta.php`
- `apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php`
- `apps/api/app/Modules/Identity/Domain/Enums/RoleProvisioningSource.php`
- `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php`

**Signatures:**

```php
// Seeder: retain these public signatures.
public function run(): void;
public static function permissionNames(): array;
public static function rolePermissionGrants(): array;

// Command; executes within an initialized tenant.
protected $signature = 'permissions:apply-lot-recall-delta {--apply : Apply while runtime activation is off} {--verify : Read-only verification}';
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
    array $effectiveRoleNames,
): void;
```

Constructor-inject the guard into both controllers. Document `effectiveRoleNames` as `list<string>` and pass the merged final role set, including atomic demotion, into the location-change guard.

**Implementation:**

1. Add `batches.recall.request` and `treasury.manage_all_locations` to the canonical permission list; apply the complete seeded-role matrix below, including viewer/operator view-only grants.
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

### Approved seeded batch-role matrix — M-R2-2

Source of the read-only viewer/operator requirement: accepted L1 matrix at [remediation design:314](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:314); manager recall is superseded by the ruled escalation at [OWNER-RULINGS:9](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:9). Current viewer and operator lists start at [RolesAndPermissionsSeeder.php:704](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:704) and [line 769](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:769).

| Seeded role | Resulting batches.* grants |
|---|---|
| admin | All existing batch permissions plus recall.request; retains recall and Treasury all-location authority. |
| general_manager | Revised manager set plus recall; Treasury manage_all_locations; unrestricted membership invariant. |
| manager | view, create, update, delete, write-off, traceability, recall.request; no recall. |
| cashier | view only (retain). |
| viewer | view only (add). |
| operator | view only (add). |
| technician / accountant | None (no new grant). |

Task 2 must update both `permissionNames()`/`rolePermissionGrants()` and the targeted existing-tenant delta. Grant view to viewer/operator with `givePermissionTo`; remove any batches.* mutation/traceability grants from these **standard named roles** as an explicitly reported part of this approved delta, preserving their unrelated permissions. Manager recall removal remains explicit. Never `syncPermissions` a custom role; custom-named roles and unrelated custom grants remain untouched. If an existing standard-role grant is removed, list it in the before/after report for promotion review, never silently adopt it into the read-only contract. General-manager collision/provenance rules remain unchanged. This supersedes any earlier statement that this delta changes only manager/general_manager.

Add `LotRecallSeededRoleMatrixTest` covering canonical declarations, fresh registration, existing-tenant apply, real seeded viewer/operator API access after ON, denial of trace/mutation, and rerun ALREADY_APPLIED with unchanged grant sets. Task 6 tests the generated map for every row above (not hand-authored view-bearing mocks alone). Boot reseeding uses the same safe delta; no guard activation before Push 5.

**M3 — exact transaction ordering:** the current create path assigns at [UserController.php:234](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:234), creates membership at [UserController.php:241](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:241), then writes the effective grant at [UserController.php:252](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:252). Move the role assignment after the membership write, inside the transaction beginning at line 217: (1) create user; (2) create active membership with the computed effective grant, or perform the existing grant writer immediately; (3) lock target user, then all applicable active memberships in company-ID order; (4) validate their effective state and actor authority; (5) set the permission team and assign; (6) write identity/audit records; (7) commit; invitation remains after commit. Failure rolls back user and membership as well as role; no temporary published privilege.

Update currently synchronizes the role before writing locations ([UserController.php:381](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:381), [UserController.php:393](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:393)). Instead lock user/memberships, calculate final role set plus final membership scope, validate that merged state, write effective membership, then synchronize roles in the same transaction. An atomic demotion plus narrowing is allowed if the final state has no general-manager role. The dedicated role endpoint at [RoleController.php:350](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:350) uses the same lock order and guard before `assignRole`, without manufacturing an absent membership. All location-grant writers in this task take the same target-user lock so parallel grant/role edits cannot bypass the invariant. Roles are tenant-team-scoped in the current assignment at UserController line 233; validate all active company memberships in that tenant, not just current company.

**M4 — durable provenance and collision algorithm:** add exactly one nullable column to `roles`: `provisioning_source VARCHAR(32) NULL DEFAULT NULL`, no FK, no unique, no new index. Add named check `roles_provisioning_source_check`: `provisioning_source IS NULL OR provisioning_source = 'w-lot-a-1'`. No JSONB. PHP enum `RoleProvisioningSource: string { case LotRecallV1 = 'w-lot-a-1'; }` is the only writer value. Existing role identity is bigint at [permission-table migration:35](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:35). Column migration is additive/self-guarding: require roles table, add only if absent, validate the existing definition if present; create the named check idempotently. `down()` removes only its check/column and refuses when any marker exists. Include SQLite CHECK-equivalent triggers in the SQLite lane. Tenant-global role provisioning is not company catalogue data; no company-scoped unique is introduced.

Fresh registration creates the ordinary manager role before entering the delta, within the seeder outer transaction; an unexpected missing manager on an existing tenant is FAILED with reason MANAGER_ROLE_MISSING. Before changing *any delta* grant, command/seeder transaction locks the tenant's existing manager role and resolves `general_manager` in the same guard/team scope. If absent, create role, `provisioning_source=LotRecallV1->value`, and initial grants atomically. If present with NULL/unknown marker, fail with no mutation, even if grants happen to match. If marked, apply only this slice's delta; preserve later custom grants. The seeder uses this same implementation, never its own `firstOrCreate` for general manager. Concurrent first runs serialize on the manager role lock and both converge on one marked role. When flag OFF without `--apply`, report SKIPPED and do not mutate. `--verify` never writes; `--apply --verify` is invalid usage. Fresh registrations with flag ON follow the same marked provisioning path.

Stable stdout: `WLOTA1-PERMISSIONS tenant=<uuid> outcome=APPLIED|ALREADY_APPLIED|SKIPPED|FAILED reason=<token>`. Exit codes: 0 for the first three successful outcomes; 1 for conflict/schema/invariant failure; 2 for invalid options. Collision reason is `ROLE_NAME_COLLISION`. `--verify` succeeds with `ALREADY_APPLIED` only when marker and required grant delta are present; otherwise FAILED. A second apply **must** report `ALREADY_APPLIED`, preserve role ID/marker/custom grants, and add no grant rows. No adoption or force option. Ordinary safe reseeding emits `WLOTA1-RESEED tenant=<uuid> outcome=APPLIED|ALREADY_APPLIED|SKIPPED|FAILED`; with flag OFF it preserves the marked role and all already-applied safety deltas.

### Source-checked red entry points — execute before production change

| Exact test file | Class/suite::method/case | Literal target assertion | Baseline-compatible setup | Contrary behavior at HEAD | Exact command | Lane |
|---|---|---|---|---|---|---|
| `apps/api/tests/Feature/Identity/LotRecallSeededRoleMatrixTest.php` | `LotRecallSeededRoleMatrixTest::test_canonical_viewer_operator_lot_grants` | `self::assertContains('batches.view', $grants['viewer']);` | Pure test: $grants = RolesAndPermissionsSeeder::rolePermissionGrants(); no provisioning or new marker schema. Then assert operator view, view-only sets, manager request/no recall. | Current viewer list omits batch view ([RolesAndPermissionsSeeder.php:705](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:705)); operator likewise. | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallSeededRoleMatrixTest::test_canonical_viewer_operator_lot_grants')` | phpunit PG |
| `apps/api/tests/Feature/Identity/LotRecallRoleDeltaTest.php` | `LotRecallRoleDeltaTest::test_canonical_manager_no_longer_has_company_recall` | `self::assertNotContains('batches.recall', $grants['manager']);` | Pure static role-grants call; no missing-role or migration-dependent setup. | Current manager includes recall ([RolesAndPermissionsSeeder.php:632](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:632)). | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest::test_canonical_manager_no_longer_has_company_recall')` | phpunit PG |

After the target red is captured, implement this task and rerun it to green. The supplemental acceptance below has exact commands but is not evidence that HEAD was red.

**Supplemental regression/dependency acceptance — not claimed red at HEAD:**

Run these cases after their required schema/production dependencies exist. They are supplemental green regressions or downstream integration acceptance; none is counted as HEAD red evidence. Setup must finish before the listed expected assertion. The source-checked red-entry table immediately above is authoritative for TDD ordering. Commands run from repository root.

| Exact new test file | Class::method | Expected acceptance assertion (not red claim) | Exact command | Lane | Convention-09 / explicit rerun outcome |
|---|---|---|---|---|---|
| `apps/api/tests/Feature/Identity/LotRecallRoleDeltaTest.php` | `LotRecallRoleDeltaTest::test_manager_loses_recall_and_gains_request` | `self::assertFalse($managerRole->hasPermissionTo('batches.recall'));` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest::test_manager_loses_recall_and_gains_request')` | phpunit PG | then request grant true; A/B authorization controls |
| `apps/api/tests/Feature/Identity/LotRecallRoleDeltaTest.php` | `LotRecallRoleDeltaTest::test_general_manager_has_exact_new_seeded_grants` | `self::assertSame($expectedSortedGrants, $actualSortedGrants);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest::test_general_manager_has_exact_new_seeded_grants')` | phpunit PG | fresh marked role; scoped membership controls |
| `apps/api/tests/Feature/Identity/LotRecallRoleDeltaTest.php` | `LotRecallRoleDeltaTest::test_delta_and_reseed_preserve_custom_permissions_on_rerun` | `self::assertSame('ALREADY_APPLIED', $secondOutcome);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest::test_delta_and_reseed_preserve_custom_permissions_on_rerun')` | phpunit PG | explicit rerun; role ID/marker/custom grant set unchanged |
| `apps/api/tests/Feature/Identity/LotRecallRoleDeltaTest.php` | `LotRecallRoleDeltaTest::test_existing_general_manager_name_collision_is_reported_without_escalation` | `self::assertSame(1, $exitCode);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest::test_existing_general_manager_name_collision_is_reported_without_escalation')` | phpunit PG | then FAILED/ROLE_NAME_COLLISION marker and whole grant snapshot equality; rerun same failure |
| `apps/api/tests/Feature/Identity/LotRecallRoleDeltaTest.php` | `LotRecallRoleDeltaTest::test_concurrent_initial_delta_marks_exactly_one_role` | `self::assertSame(1, $markedGeneralManagerCount);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest::test_concurrent_initial_delta_marks_exactly_one_role')` | phpunit PG | two processes: one APPLIED, one ALREADY_APPLIED; no duplicate role/grants |
| `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php` | `GeneralManagerAssignmentTest::test_create_update_and_role_endpoint_reject_restricted_assignment` | `$response->assertUnprocessable();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerAssignmentTest::test_create_update_and_role_endpoint_reject_restricted_assignment')` | phpunit PG | provider covers create/update/role endpoint and []/[A1]; no persisted partial assignment |
| `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php` | `GeneralManagerAssignmentTest::test_new_unrestricted_general_manager_is_created_after_membership` | `$response->assertCreated();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerAssignmentTest::test_new_unrestricted_general_manager_is_created_after_membership')` | phpunit PG | positive regression for membership-before-role ordering; assert NULL effective scope |
| `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php` | `GeneralManagerAssignmentTest::test_unrestricted_assignment_succeeds_and_cannot_be_narrowed` | `$narrowResponse->assertUnprocessable();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerAssignmentTest::test_unrestricted_assignment_succeeds_and_cannot_be_narrowed')` | phpunit PG | repeated narrowing rejected; membership remains NULL |
| `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php` | `GeneralManagerAssignmentTest::test_actor_cannot_use_general_manager_assignment_to_expand_own_authority` | `self::assertSame($beforeGrants, $afterGrants);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerAssignmentTest::test_actor_cannot_use_general_manager_assignment_to_expand_own_authority')` | phpunit PG | denied self-escalation; repeat same unchanged grants |
| `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php` | `GeneralManagerAssignmentTest::test_second_company_restricted_membership_prevents_tenant_role_assignment` | `$response->assertUnprocessable();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerAssignmentTest::test_second_company_restricted_membership_prevents_tenant_role_assignment')` | phpunit PG | real registration A/B; B restricted to B1; general-manager assignment refused despite A unrestricted |
| `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php` | `GeneralManagerAssignmentTest::test_assignment_rerun_preserves_memberships_and_single_role_pivot` | `self::assertSame(1, $generalManagerPivotCount);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerAssignmentTest::test_assignment_rerun_preserves_memberships_and_single_role_pivot')` | phpunit PG | A and B unrestricted; repeated assignment idempotent success; no widened location grants |

**Additional required supplemental cases introduced by round 2:**

| Exact file | Class::method | Expected assertion | Exact command | Lane | Coverage |
|---|---|---|---|---|---|
| `apps/api/tests/Feature/Identity/LotRecallSeededRoleMatrixTest.php` | `LotRecallSeededRoleMatrixTest::test_fresh_and_existing_tenants_get_approved_batch_grants` | `self::assertSame($expectedBatchGrants, $actualBatchGrants);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallSeededRoleMatrixTest::test_fresh_and_existing_tenants_get_approved_batch_grants')` | phpunit PG | every seeded-role provider; real registration then existing apply; custom-named roles unchanged; view-only viewer/operator with real API under ON |
| `apps/api/tests/Feature/Identity/LotRecallSeededRoleMatrixTest.php` | `LotRecallSeededRoleMatrixTest::test_reseed_and_delta_rerun_report_already_applied_for_full_role_matrix` | `self::assertSame('ALREADY_APPLIED', $secondOutcome);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallSeededRoleMatrixTest::test_reseed_and_delta_rerun_report_already_applied_for_full_role_matrix')` | phpunit PG | viewer/operator grants present and no mutation/trace; no duplicate pivots; A/B scoped views independent |

**Task 2 convention-09 mapping:** role provisioning itself is tenant-wide (no company catalogue unique), but assignment is in scope: second company restricted-membership test; A1/A2 restriction provider; explicit ALREADY_APPLIED delta/reseed rerun and one role pivot on assignment rerun.

**Migration/schema:** the complete `roles.provisioning_source` additive schema and enum are specified above; existing membership JSON is unchanged. No JSONB is introduced.

**Reviewer gate:** tenancy-authz-reviewer checks all three assignment paths, role scope and custom-role preservation; inventory-costing-reviewer checks manager recall denial and general-manager capability.

**Rollback:** retain the stricter manager permission and revoke new assignments only through an audited targeted operation if necessary. Restore unrelated grants from the manifest’s before-state; never blanket-sync or automatically restore manager company-wide recall.

## Task 3 — Persist immutable recall requests and expose the scoped writer

**Dependencies:** Task 2. Do not activate the writer until Tasks 4 and 5 are deployed and the manifest Push-4 verification is complete.

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
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchRecallTransitionData.php`
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
- Partial unique `brq_company_terminal_uq(company_id, request_id) WHERE request_id IS NOT NULL`, independent of status. This is the one-terminal-outcome invariant for both recalled and future released entries.
- Index `brq_scope_status_idx(company_id, batch_id, location_id, status)`.
- Index `brq_company_created_idx(company_id, created_at, id)`.
- Index `brq_actor_idx(actor_user_id)`.
- Check `status IN ('requested', 'recalled')`.
- Check `length(trim(reason)) > 0`.
- Check requested entries have NULL `request_id`; recalled entries have non-NULL `request_id`.
- Check `request_id IS NULL OR request_id <> id`.
- Insert validation trigger verifies company/tenant/batch/location/actor consistency and, for a transition, that the parent is a requested root with identical tenant/company/batch/location. Lock that root and reject insertion if any terminal child already exists; the partial unique is the concurrency backstop. No status-specific terminal uniqueness is permitted.
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

### Generated declarations — required Task 3 step

Current discovery includes Modules/Shared at [typescript-transformer.php:17](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/typescript-transformer.php:17); output is [typescript-transformer.php:53](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/typescript-transformer.php:53). Use the established annotated Data pattern at [UnitTextMappingResultData.php:10](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Uom/Application/DTOs/UnitTextMappingResultData.php:10).

Each DTO file uses the following namespace/imports (omit unused enum imports from the create DTO):

```php
namespace App\Modules\BatchExpiry\Application\DTOs;

use App\Modules\BatchExpiry\Domain\Enums\BatchRecallRequestStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
```

Exact declarations, one named class per corresponding file:

```php
#[TypeScript]
final class CreateBatchRecallRequestData extends Data
{
    public function __construct(
        public string $operation_uuid,
        public string $location_id,
        public string $reason,
    ) {}
}

#[TypeScript]
final class BatchRecallTransitionData extends Data
{
    public function __construct(
        public BatchRecallRequestStatus $status,
        public string $reason,
        public string $actor_user_id,
        public string $created_at,
    ) {}
}

#[TypeScript]
final class BatchRecallRequestData extends Data
{
    public function __construct(
        public string $id,
        public string $operation_uuid,
        public string $batch_uuid,
        public string $location_id,
        public BatchRecallRequestStatus $status,
        public string $request_reason,
        public string $requested_by,
        public string $requested_at,
        public ?BatchRecallTransitionData $transition,
        public bool $replayed,
    ) {}
}
```

The enum file declares `namespace App\Modules\BatchExpiry\Domain\Enums;`, imports `Spatie\TypeScriptTransformer\Attributes\TypeScript`, and uses:

```php
#[TypeScript]
enum BatchRecallRequestStatus: string
{
    case Requested = 'requested';
    case Recalled = 'recalled';
}
```

The service constructs `BatchRecallTransitionData` only from a persisted terminal child (currently Recalled). Requested is never valid inside `transition`. Emit request and transition timestamps as ISO-8601 strings and retain actor identity separately for each.

**Task 3 implementation step:** execute `(cd apps/api && php artisan typescript:transform)` after DTO creation; commit `packages/shared/types/generated.d.ts`. Add a generated-output PHPUnit assertion on the declarations, both reason fields and nullable transition. **Task 6 step:** run the transform again with `permissions:export-frontend-map`; commit `apps/web/src/hooks/permissionsMap.generated.ts` together with any changed `packages/shared/types/generated.d.ts`. Do not hand-edit generated declarations.

In `apps/web/src/features/batches/types.ts`, use aliases only:

```ts
export type CreateBatchRecallRequestData = App.Modules.BatchExpiry.Application.DTOs.CreateBatchRecallRequestData
export type BatchRecallRequestData = App.Modules.BatchExpiry.Application.DTOs.BatchRecallRequestData
export type BatchRecallTransitionData = App.Modules.BatchExpiry.Application.DTOs.BatchRecallTransitionData
export type BatchRecallRequestStatus = App.Modules.BatchExpiry.Domain.Enums.BatchRecallRequestStatus
```

### Capability DTO — owned by Task 3, consumed by Task 6

New `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchRecallCapabilitiesData.php`:

```php
namespace App\Modules\BatchExpiry\Application\DTOs;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class BatchRecallCapabilitiesData extends Data
{
    public function __construct(public bool $enabled) {}
}
```

`capabilities()` returns `response()->json(['data' => new BatchRecallCapabilitiesData($this->activation->enabled())])` with exact JSON `{ "data": { "enabled": false } }` or true, no duplicate unwrap. Constructor-inject BatchRecallActivation into the controller. Schema-free OFF endpoint is callable before request-table activation. Task 3 generation writes this class into `packages/shared/types/generated.d.ts` along with the other DTOs; generated-output assertion includes its namespace and boolean field. API acceptance: OFF with a current seeded viewer yields 200 false; ON after role rollout yields 200 true; ON actor lacking view yields 403; module/tenant/company protections remain. The red endpoint case uses the OFF seeded viewer and asserts **200** first, not the already-green missing-route 404.

### Full public contracts

```php
// CreateBatchRecallRequestData constructor:
public function __construct(
    public string $operation_uuid,
    public string $location_id,
    public string $reason,
);

// BatchRecallTransitionData constructor:
public function __construct(
    public BatchRecallRequestStatus $status,
    public string $reason,
    public string $actor_user_id,
    public string $created_at,
);

// BatchRecallRequestData constructor:
public function __construct(
    public string $id,
    public string $operation_uuid,
    public string $batch_uuid,
    public string $location_id,
    public BatchRecallRequestStatus $status,
    public string $request_reason,
    public string $requested_by,
    public string $requested_at,
    public ?BatchRecallTransitionData $transition,
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
public function capabilities(): JsonResponse;

// FormRequest:
public function authorize(): bool;
public function rules(): array;
```

**Routes:**

- `POST /api/v1/batches/{uuid}/recall-requests`: workflow OFF → 404, ON → `can:batches.recall.request`.
- `GET /api/v1/batches/{uuid}/recall-requests`: BatchActionAccess with `batches.view`, and workflow OFF → 404 for this new history surface.
- **Literal first:** `GET /api/v1/batches/recall-capabilities` → `BatchRecallRequestController::capabilities(): JsonResponse`; BatchActionAccess with `batches.view`, existing api/auth/team/claim/module group. It returns 200 when OFF (legacy read admission) and ON for view-authorized callers; ON without view returns 403. Resolve active company; never accept tenant/company from query payload. No request-table query is needed.

Both inherit the existing middleware group.

**Implementation:**

1. Validate operation/location UUIDs and trimmed mandatory reason.
2. Resolve batch and location through the actor’s company and allowed locations; return 404 outside scope.
3. Require positive physical lot quantity at the requested location, including fully reserved stock.
4. Lock the location’s batch-stock row and recheck presence before insertion. The unresolved-hold query uses requested roots with no terminal child of any status, so the future released alternative uses the same predicate.
5. Insert the requested root within the transaction; commit is the hold’s activation point.
6. Replay the same company/operation UUID only when actor, lot, location and normalized reason match. Return 200 with the original ID and `replayed=true`; fresh creation returns 201.
7. Changed payload under the same operation UUID returns 409.
8. Revalidate authorization on retry. A historic operation UUID does not bypass revoked access.
9. Reject a new request for an already globally recalled lot; a retry of an existing request returns its effective recalled state.
10. Preserve independent evidence from separate operation UUIDs. Multiple reports create one effective exclusion, not multiplied stock effects.

### Source-checked red entry points — execute before production change

| Exact test file | Class/suite::method/case | Literal target assertion | Baseline-compatible setup | Contrary behavior at HEAD | Exact command | Lane |
|---|---|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchRecallSchemaPresenceTest.php` | `BatchRecallSchemaPresenceTest::test_real_tenant_migrations_install_recall_request_schema` | `self::assertTrue(Schema::hasTable('batch_recall_requests'));` | Real registration migrates existing tenant normally. Assert table existence before inserting request rows; never call SeedsHeldLotAtBaseline. | Current tenant migrations create product_batches but no request table; existing schema source [product_batches migration:13](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:13), missing-table feature introduced only by this planned migration. Schema existence returns false, not SQL exception. | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallSchemaPresenceTest::test_real_tenant_migrations_install_recall_request_schema')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallCapabilitiesTest.php` | `BatchRecallCapabilitiesTest::test_dormant_capabilities_return_false` | `$response->assertOk();` | Baseline registered viewer, module enabled, flag false; GET /api/v1/batches/recall-capabilities; after first assertion assertJsonPath(data.enabled,false). Do not assert missing-route 404 as red. | Current route group has no capability route ([routes.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12)); literal is captured as invalid batch UUID and returns 404. | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallCapabilitiesTest::test_dormant_capabilities_return_false')` | phpunit PG |

After the target red is captured, implement this task and rerun it to green. The supplemental acceptance below has exact commands but is not evidence that HEAD was red.

**Supplemental regression/dependency acceptance — not claimed red at HEAD:**

Run these cases after their required schema/production dependencies exist. They are supplemental green regressions or downstream integration acceptance; none is counted as HEAD red evidence. Setup must finish before the listed expected assertion. The source-checked red-entry table immediately above is authoritative for TDD ordering. Commands run from repository root.

| Exact new test file | Class::method | Expected acceptance assertion (not red claim) | Exact command | Lane | Convention-09 / explicit rerun outcome |
|---|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestSchemaTest.php` | `BatchRecallRequestSchemaTest::test_request_evidence_rejects_update_delete_and_cross_company_links` | `self::assertTrue($databaseRejectedMutation);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestSchemaTest::test_request_evidence_rejects_update_delete_and_cross_company_links')` | phpunit PG | provider executes UPDATE/DELETE/foreign-link SQL in savepoints; table snapshot unchanged |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestSchemaTest.php` | `BatchRecallRequestSchemaTest::test_only_requested_and_recalled_entries_are_valid` | `self::assertTrue($databaseRejectedInvalidStatus);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestSchemaTest::test_only_requested_and_recalled_entries_are_valid')` | phpunit PG | invalid arbitrary status string, no release/reject behavior test |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestSchemaTest.php` | `BatchRecallRequestSchemaTest::test_a_request_has_only_one_terminal_child` | `self::assertTrue($databaseRejectedSecondTerminal);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestSchemaTest::test_a_request_has_only_one_terminal_child')` | phpunit PG | duplicate recalled child with fresh op UUID rejected; assert partial index excludes status |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestTest.php` | `BatchRecallRequestTest::test_manager_requests_recall_at_allowed_location` | `$response->assertCreated();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestTest::test_manager_requests_recall_at_allowed_location')` | phpunit PG | real A1-only manager; on-hand positive even if fully reserved |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestTest.php` | `BatchRecallRequestTest::test_non_allowed_location_is_scoped_not_found` | `$response->assertNotFound();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestTest::test_non_allowed_location_is_scoped_not_found')` | phpunit PG | A2 and company B location providers; stock/history unchanged |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestTest.php` | `BatchRecallRequestTest::test_retry_returns_original_request_without_duplicate_evidence` | `$retry->assertJsonPath('data.replayed', true);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestTest::test_retry_returns_original_request_without_duplicate_evidence')` | phpunit PG | same ID, count 1, 200, unchanged root evidence |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestTest.php` | `BatchRecallRequestTest::test_operation_uuid_payload_conflict_returns_409` | `$response->assertConflict();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestTest::test_operation_uuid_payload_conflict_returns_409')` | phpunit PG | changed lot/location/reason/actor provider; no extra root |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestTest.php` | `BatchRecallRequestTest::test_second_company_can_reuse_operation_uuid_without_cross_company_effects` | `$companyBResponse->assertCreated();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestTest::test_second_company_can_reuse_operation_uuid_without_cross_company_effects')` | phpunit PG | same operation UUID in A/B creates independent root; repeat B gives replayed=true |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestTest.php` | `BatchRecallRequestTest::test_capability_and_request_writer_are_dormant_when_flag_is_off` | `$response->assertNotFound();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestTest::test_capability_and_request_writer_are_dormant_when_flag_is_off')` | phpunit PG | OFF with schema/no evidence; capability false; rerun no new root |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallGeneratedTypesTest.php` | `BatchRecallGeneratedTypesTest::test_transform_exports_request_transition_reason_and_enum` | `self::assertStringContainsString('request_reason: string;', $generated);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallGeneratedTypesTest::test_transform_exports_request_transition_reason_and_enum')` | phpunit PG | Task 3 transform run first during test; assert transition namespace/reason/nullable field and no any; run transform twice, identical bytes |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestSqliteSchemaTest.php` | `BatchRecallRequestSqliteSchemaTest::test_append_only_terminal_and_role_marker_guards` | `self::assertTrue($databaseRejectedMutation);` | `(cd apps/api && DB_CONNECTION=sqlite DB_DATABASE=:memory: ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchRecallRequestSqliteSchemaTest::test_append_only_terminal_and_role_marker_guards')` | phpunit sqlite | Schema-only portability, not real-registration substitute; provider covers update/delete/terminal/FK consistency/role marker; migration rerun yields unchanged schema |

**Additional required supplemental cases introduced by round 2:**

| Exact file | Class::method | Expected assertion | Exact command | Lane | Coverage |
|---|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchRecallCapabilitiesTest.php` | `BatchRecallCapabilitiesTest::test_on_capabilities_enforce_view_and_company_module_admission` | `$response->assertJsonPath('data.enabled', true);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallCapabilitiesTest::test_on_capabilities_enforce_view_and_company_module_admission')` | phpunit PG | ON seeded viewer/operator gets200; actor without view403; wrong company/module guard unchanged; A/B tenant scoped context; OFF false200 |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallGeneratedTypesTest.php` | `BatchRecallGeneratedTypesTest::test_capability_dto_is_generated` | `self::assertStringContainsString('BatchRecallCapabilitiesData', $generated);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallGeneratedTypesTest::test_capability_dto_is_generated')` | phpunit PG | after Task3 declarations and transform; bool enabled field; repeated generation identical |

**Task 3 convention-09 mapping:** second company: operation UUID reuse; second location: allowed A1/disallowed A2; rerun: 200 + replayed=true + original root ID, payload mismatch 409. Schema-only SQLite and generated-output checks are supplemental, not substitutes.

**Reviewer gate:** tenancy-authz-reviewer verifies scope, UUID replay and composite consistency; inventory-costing-reviewer verifies no quantity/value effects and append-only lifecycle projection.

**Rollback:** leave additive staging schema in place under the canonical manifest. After evidence exists retain it and its eligibility enforcement; flag OFF disables new submissions while preserving the data-safety latch.

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

**M5 — refusal contract and exact additional production files:**

- New `apps/api/app/Modules/BatchExpiry/Domain/Exceptions/BatchHeldException.php`.
- Modify `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php` (POS suggestion and direct-transfer catches).
- Modify `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php` (store/complete catches).
- Modify `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` (typed safety-conflict containment).

```php
final class BatchHeldException extends \DomainException
{
    public const ERROR_CODE = 'BATCH_HELD';
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly int $batchId,
        public readonly string $locationId,
        public readonly string $shortfall = '0.0000',
    );
}
```

Constructor sets a stable, non-sensitive message: `Batch is held at the source location.` All decimal quantities remain strings. Throw for an explicit held-lot issue, or when eligible stock cannot satisfy the request and positive held stock at that scoped tuple accounts for at least some shortfall. Skip a held candidate and succeed when other eligible stock fully covers the request. Genuine shortage without held stock keeps its existing shortage exception. Recheck after locks before any issue mutation; `strictFulfillment=false` never authorizes held-stock consumption.

| Consumer | Mapping |
|---|---|
| `BatchController::posAvailableBatches(Request $request, string $productId): JsonResponse` | Catch before generic handling; 422 `{error:{code:"BATCH_HELD",message:"Batch is held at the source location."}}` when held stock causes insufficiency; otherwise retain successful suggestion DTO with held lots excluded. |
| `BatchController::transfer(TransferBatchStockRequest $request, string $uuid): JsonResponse` | Catch BatchHeldException before DomainException; same 422 envelope. Existing DomainException handling is at [BatchController.php:385](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:385). |
| `StockTransferController::store(StoreStockTransferRequest $request): JsonResponse` and `::complete(Request $request, string $transfer): JsonResponse` | Catch BatchHeldException before InsufficientStockException/InvalidArgumentException/state catches; same 422 envelope. Current store catches are at [StockTransferController.php:187](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:187). Service initiates/allocations roll back fully. |
| `PosCoreReceiptProjection::containLotWork(FiscalEvent $event, string $receiptId, string $productId, string $arm, Closure $work): void` | Catch BatchHeldException separately before Throwable at [PosCoreReceiptProjection.php:2169](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2169). Roll back the lot savepoint and emit structured `BATCH_HELD_PROJECTION_CONFLICT` with event/receipt/company/location/batch IDs and decimal shortfall. No HTTP response: this is asynchronous projection of already-sealed evidence. Preserve the signed event and existing financial projection contract; produce no held-lot consumption/allocation and expose the conflict to the lot-drift census/operator logs. Do not classify it as an incidental generic exception or invent physical allocation evidence. |

**Scope limit:** this slice blocks new server held-lot consumption/transfer allocation. It does not add an online receipt-authoring path or promise immediate device blocking; W-LOT-B supplies the D4 cache interface and acceptance above. Existing projection deliberately retains sealed receipts despite lot-leg failure at [PosCoreReceiptProjection.php:2183](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2183). Device/offline prevention is not claimed; device build remains no. The projection test asserts preserved canonical evidence plus explicit conflict, not the unchanged stock/allocation snapshot required for a rejected direct or stock-transfer request. The retired online receipt endpoint stays 410 and is a supplemental preservation test, never a proposed 422 test.

**Concurrency contract:**

The existing transfer code documents aggregate-stock-before-batch-stock ordering at [StockTransferService.php:794](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:794). Preserve it.

- Hold creation locks its batch-stock row, then checks the batch’s global recall state under a shared parent-row lock before inserting.
- Consumers lock batch-stock rows through their existing flow, then acquire shared parent-batch locks in ascending batch ID order.
- After locks are acquired, re-read recall and request eligibility in a fresh statement before mutation.
- Company-wide recall takes the exclusive parent-batch lock; it must not subsequently acquire batch-stock locks.
- No path acquires an upstream aggregate lock after these new locks.
- Keep PostgreSQL `SKIP LOCKED` behavior, with a fresh eligibility check after selection.

The fresh check matters because a statement started before a competing hold commits can otherwise retain stale eligibility after waiting for a row lock. The atomic query is at [FEFOInventoryService.php:260](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:260).

### Source-checked red entry points — execute before production change

| Exact test file | Class/suite::method/case | Literal target assertion | Baseline-compatible setup | Contrary behavior at HEAD | Exact command | Lane |
|---|---|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldEligibilityTest.php` | `BatchRecallHoldEligibilityTest::test_current_fefo_excludes_persisted_branch_hold` | `self::assertNotContains((int) $this->batchA->id, $suggestedBatchIds);` | Registered fixture plus SeedsHeldLotAtBaseline only; call existing FEFO suggest method requesting 1.0000; $suggestedBatchIds = array_map(static fn($item): int => (int) $item->batch->id, $result->suggestions). DTO batch field exists at [BatchSuggestionDTO.php:20](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionDTO.php:20). | Current suggestion query excludes global recall but not branch request ([FEFOInventoryService.php:92](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:92)); it selects held oldest A1 lot. | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_current_fefo_excludes_persisted_branch_hold')` | phpunit PG |

After the target red is captured, implement this task and rerun it to green. The supplemental acceptance below has exact commands but is not evidence that HEAD was red.

**Supplemental regression/dependency acceptance — not claimed red at HEAD:**

Run these cases after their required schema/production dependencies exist. They are supplemental green regressions or downstream integration acceptance; none is counted as HEAD red evidence. Setup must finish before the listed expected assertion. The source-checked red-entry table immediately above is authoritative for TDD ordering. Commands run from repository root.

| Exact new test file | Class::method | Expected acceptance assertion (not red claim) | Exact command | Lane | Convention-09 / explicit rerun outcome |
|---|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldEligibilityTest.php` | `BatchRecallHoldEligibilityTest::test_hold_blocks_sale_and_direct_transfer_at_held_location_only` | `$response->assertStatus(422)->assertJsonPath('error.code', 'BATCH_HELD');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_hold_blocks_sale_and_direct_transfer_at_held_location_only')` | phpunit PG | provider POS FEFO suggestion/direct transfer at A1; A2 succeeds; repeat A1 same 422 and snapshot |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldEligibilityTest.php` | `BatchRecallHoldEligibilityTest::test_stock_transfer_manual_and_auto_allocation_exclude_held_lot` | `$response->assertStatus(422)->assertJsonPath('error.code', 'BATCH_HELD');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_stock_transfer_manual_and_auto_allocation_exclude_held_lot')` | phpunit PG | manual held allocation and auto held-shortfall providers; allocations/stock unchanged |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldEligibilityTest.php` | `BatchRecallHoldEligibilityTest::test_fefo_selects_next_eligible_lot_without_false_order_violation` | `self::assertSame([$this->fallbackBatchA->id], $allocatedBatchIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_fefo_selects_next_eligible_lot_without_false_order_violation')` | phpunit PG | A1 fallback; A2 original oldest lot remains eligible; rerun rejected operations add no effects |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldEligibilityTest.php` | `BatchRecallHoldEligibilityTest::test_hold_preserves_physical_reserved_stock_and_value` | `self::assertSame($beforeStockAndValue, $afterStockAndValue);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_hold_preserves_physical_reserved_stock_and_value')` | phpunit PG | A/B and A1/A2 snapshot after request; repeated request replay preserves balances |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldEligibilityTest.php` | `BatchRecallHoldEligibilityTest::test_variant_and_company_scopes_do_not_share_holds` | `self::assertContains($this->batchB->id, $companyBEligibleBatchIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_variant_and_company_scopes_do_not_share_holds')` | phpunit PG | same batch label in B; variant isolated; repeated suggestions equal |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldEligibilityTest.php` | `BatchRecallHoldEligibilityTest::test_flag_off_preserves_existing_holds_and_works_without_schema` | `$heldResponse->assertJsonPath('error.code', 'BATCH_HELD');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_flag_off_preserves_existing_holds_and_works_without_schema')` | phpunit PG | OFF-with-evidence blocked; separate no-schema isolated case remains dormant without SQL error |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldConcurrencyTest.php` | `BatchRecallHoldConcurrencyTest::test_committed_hold_wins_against_waiting_direct_transfer` | `self::assertSame('BATCH_HELD', $transferWorkerErrorCode);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldConcurrencyTest::test_committed_hold_wins_against_waiting_direct_transfer')` | phpunit PG | barrier-driven PG process test; no movements; repeated transfer same refusal |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldConcurrencyTest.php` | `BatchRecallHoldConcurrencyTest::test_committed_hold_wins_against_waiting_stock_transfer` | `self::assertSame('BATCH_HELD', $allocationWorkerErrorCode);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldConcurrencyTest::test_committed_hold_wins_against_waiting_stock_transfer')` | phpunit PG | manual/auto provider; no allocation or aggregate mutation |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldConcurrencyTest.php` | `BatchRecallHoldConcurrencyTest::test_sale_started_after_hold_commit_cannot_consume_held_lot` | `self::assertSame([], $heldLotMovementIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldConcurrencyTest::test_sale_started_after_hold_commit_cannot_consume_held_lot')` | phpunit PG | separate connection after hold commit; A2 control succeeds |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldConcurrencyTest.php` | `BatchRecallHoldConcurrencyTest::test_concurrent_request_retries_create_one_root` | `self::assertSame(1, $rootCount);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldConcurrencyTest::test_concurrent_request_retries_create_one_root')` | phpunit PG | two submitters same UUID; one 201, one 200 replayed=true |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldProjectionTest.php` | `BatchRecallHoldProjectionTest::test_sealed_receipt_hold_conflict_is_explicit_without_held_lot_allocation` | `self::assertSame('BATCH_HELD_PROJECTION_CONFLICT', $conflictCode);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldProjectionTest::test_sealed_receipt_hold_conflict_is_explicit_without_held_lot_allocation')` | phpunit PG | signed event retained; no held lot movement/allocation; projection rerun creates no duplicate effect |

**Additional required supplemental cases introduced by round 2:**

| Exact file | Class::method | Expected assertion | Exact command | Lane | Coverage |
|---|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldProjectionTest.php` | `BatchRecallHoldProjectionTest::test_retired_new_sale_route_remains_410` | `$response->assertStatus(410)->assertJsonPath('error.code', 'NEW_SALE_AUTHORING_RETIRED');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldProjectionTest::test_retired_new_sale_route_remains_410')` | phpunit PG | existing route already green; authenticated valid payload; do not call retired controller |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldProjectionTest.php` | `BatchRecallHoldProjectionTest::test_server_consumption_after_hold_preserves_device_evidence` | `self::assertSame([], $newHeldLotMovementIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldProjectionTest::test_server_consumption_after_hold_preserves_device_evidence')` | phpunit PG | A-SERVER-1/2; sealed event/payment/GL retained; B-CACHE acceptance remains owned by W-LOT-B |

**Task 4 convention-09 mapping:** second company/variant: isolated eligibility; second location: A1 blocked/A2 usable; rerun: identical 422 BATCH_HELD with zero movements/allocations, repeated same request replay, and concurrent root count one. Successful transfers are not blindly reissued as an idempotency claim.

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

### Existing recall writer census and disposition — B-R2-2

Census covers `apps/api/app`, `apps/api/database` and existing test call sites for `is_recalled`, `recall_reason`, `recalled_at`, `recall()` and Batch create/update/mass-assignment. The table distinguishes lifecycle changes from insert-only defaults and unrelated POS held-order terminology; read predicates are not writers.

| Current writer / exposure at baseline | Task 5 disposition |
|---|---|
| [BatchController.php:204](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:204) calls entity recall | Replace with canonical BatchRecallService::recall; preserve permission/scoped intent at boundary. |
| [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134) directly overwrites projection fields | **Remove** public `recall(string $reason): void`; no compatibility mutator without actor/transaction/evidence. Service alone performs the locked projection update. |
| [BatchRepositoryInterface.php:52](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php:52), [BatchRepository.php:149](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:149) expose a second overwrite writer | **Remove** recall method from interface/implementation; no delegation without explicit actor/scope. No production caller other than controller-to-entity was found in the enumerated census. Ratchet prevents a new alternate writer. |
| [Batch.php:42](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:42) fillable recall fields; [BatchRepository.php:123](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:123) generic create and [line 133](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:133) generic update | Remove recall fields from fillable; generic update explicitly rejects any recall field with DomainException before save. Generic create permits only insert defaults false/null and strips them to DB defaults; true/non-null rejects. Canonical service uses an explicit scoped query update under lock, not mass assignment. |
| [DemoPharmacySeeder.php:563](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/DemoPharmacySeeder.php:563), reset values [line 576](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/DemoPharmacySeeder.php:576) | Remove recall fields from the updateOrCreate update payload. Fresh insert uses DB defaults. If fixture lot is recalled or has any recall-request evidence, skip that fixture's batch/stock refresh and stale-zeroing so reseed cannot reset recall or invalidate evidence. Report `WLOTA1-DEMO outcome=SKIPPED_RECALL_EVIDENCE batch=<uuid>`. Include selected fixtures and stale fixture path at [line 614](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/DemoPharmacySeeder.php:614). No service call to “unrecall”; there is no such operation. |
| [BatchStockService.php:387](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:387), insert default [line 400](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:400) | Excluded from lifecycle writer count **only** as false initialization of a new lot after existing-lot return. Remove explicit recall key if convenient; ratchet allows false-only insert and forbids update/reset. |
| [FEFOInventoryService.php:811](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:811), insert default [line 828](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:828) | Excluded only as insertGetId false default for new return/default lot; never an existing-row recall mutation. |
| [product_batches migration:36](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:36) | Schema defaults false/null, not a lifecycle action. Preserve historic migration; scanner classifies schema declarations separately. |
| [BatchFactory.php:31](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/factories/BatchExpiry/BatchFactory.php:31), recalled fixture [line 44](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/factories/BatchExpiry/BatchFactory.php:44) | Test-fixture initialization only; exclude exact factory from production ratchet with reason. Do not allow production seeders to call recalled factory state. Fixtures create initial historical state; command behavior tests must invoke canonical service. |
| [BatchExpiryDailyCheckCommand.php:165](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Commands/BatchExpiryDailyCheckCommand.php:165) and [RepairPhantomDefaultBatchesCommand.php:656](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/RepairPhantomDefaultBatchesCommand.php:656) | Recall **read predicates**, not recall-state writers; excluded for that reason. No recall-changing console command was found in the census. Any future command must call the canonical service through a separately authorized explicit intent, not directly set projection fields. |
| Import transport through generic batch creation/defaults | Create/Update HTTP fields at [CreateBatchRequest.php:33](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRequest.php:33) and [UpdateBatchRequest.php:19](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:19) do not admit recall fields; repository mass-assignment guard closes internal array-based bypasses. No independent recall-state import writer found under app/Modules/Import; initialization paths above are the only permitted defaults. Ratchet scans future import payload sinks too. |
| [HeldOrderService.php:227](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/HeldOrderService.php:227) writes `recalled_at` | Excluded: resuming a POS held order in `pos_held_orders`, not lot recall in product_batches. Do not conflate the concepts. |

**Task 5 exact added/modified files:** `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php`, `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php`, `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php`, `apps/api/database/seeders/DemoPharmacySeeder.php`, plus controller/service/request files already listed. Update existing test fixtures that call the removed entity mutator: [ReplenishmentActionsTest.php:159](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Replenishment/ReplenishmentActionsTest.php:159), [InventoryTransferServiceTest.php:511](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php:511), [StockTransferAutoAllocateFefoTest.php:176](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Inventory/StockTransferAutoAllocateFefoTest.php:176); replace their historical setup with factory state, not a second production mutator.

New `apps/api/tests/Architecture/BatchRecallWriterRatchetTest.php` scans production PHP ASTs for mutation sinks affecting the three recall fields: update/updateOrCreate/upsert/fill/forceFill/attribute assignments/save and Batch recall invocations, including constant-key arrays flowing into generic writes. Only `BatchRecallService::recall` may change existing projection fields; exact insert-default/schema/factory/held-order exclusions above are classified by table and write mode, never a whole-directory allowlist. Reject dynamic/unclassified recall-key mutations for reviewer classification. The scanner itself stays test code. `test_existing_recall_writers_use_canonical_service` has current violations at entity, repository and demo reset; `test_detector_flags_new_direct_writer` injects a test source string containing direct `DB::table('product_batches')->update(['is_recalled'=>true])` and expects its location to be reported. That liveness case is a supplemental test of the ratchet, not a claim about HEAD production behavior.

New `apps/api/tests/Feature/BatchExpiry/BatchRecallWriterRegressionTest.php`: canonical recall plus request transitions, generic update rejection, removed public methods, demo reseed preserves first reason/timestamp/terminal evidence and fixture stock on second run. Test snapshot includes both A1/A2 and B. The glossary's **sole request/lifecycle writer** is a target achieved only when this census ratchet and service regression pass; insert-only defaults and historical test fixtures are explicitly excluded, not hidden alternate business actions.

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
5. On first execution, set the existing global recall fields and append one `recalled` transition for each requested root for that batch across company locations **having no terminal child of any status**. Lock roots in UUID order before insertion. Never append a second disposition or replace an existing terminal reason.
6. Copy each root’s tenant/company/batch/location, record the recalling actor and mandatory disposition reason, and link `request_id`.
7. Use the deterministic transition operation UUID so replay cannot duplicate transitions.
8. Return the batch through the existing response shape.
9. An already-recalled batch with the same normalized reason returns the original outcome without rewriting timestamp or evidence; a different reason returns 409.
10. A direct company-wide recall remains possible without a prior request. Preserve its first reason/time; do not invent a branch request.
11. Request creation racing with recall either commits first and is transitioned, or observes recalled state and is rejected.

The company-wide block remains the existing `is_recalled` path; the handoff’s cited FEFO lines 930–934 now identify `productRequiresBatchTracking()`, while the current total-availability recall predicate is at [FEFOInventoryService.php:940](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:940).

### Source-checked red entry points — execute before production change

| Exact test file | Class/suite::method/case | Literal target assertion | Baseline-compatible setup | Contrary behavior at HEAD | Exact command | Lane |
|---|---|---|---|---|---|---|
| `apps/api/tests/Architecture/BatchRecallWriterRatchetTest.php` | `BatchRecallWriterRatchetTest::test_existing_recall_writers_use_canonical_service` | `self::assertSame([], $violations);` | Pure AST census test implemented first, no new production class/table required. Its liveness test is supplemental. Implementation removes alternate writers and routes lifecycle through Task-3 service. | Current entity/repository/demo seeder directly change recall fields ([Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134), [BatchRepository.php:149](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:149), [DemoPharmacySeeder.php:576](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/DemoPharmacySeeder.php:576)); violation list nonempty. | `(cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchRecallWriterRatchetTest::test_existing_recall_writers_use_canonical_service')` | phpunit sqlite (pure architecture; no DB) |

After the target red is captured, implement this task and rerun it to green. The supplemental acceptance below has exact commands but is not evidence that HEAD was red.

**Supplemental regression/dependency acceptance — not claimed red at HEAD:**

Run these cases after their required schema/production dependencies exist. They are supplemental green regressions or downstream integration acceptance; none is counted as HEAD red evidence. Setup must finish before the listed expected assertion. The source-checked red-entry table immediately above is authoritative for TDD ordering. Commands run from repository root.

| Exact new test file | Class::method | Expected acceptance assertion (not red claim) | Exact command | Lane | Convention-09 / explicit rerun outcome |
|---|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php` | `CompanyWideBatchRecallTest::test_general_manager_recall_spans_branches_and_transitions_requests` | `$history->assertJsonPath('data.0.status', 'recalled');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_general_manager_recall_spans_branches_and_transitions_requests')` | phpunit PG | A1/A2 roots transitioned; both blocked; B unchanged |
| `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php` | `CompanyWideBatchRecallTest::test_manager_cannot_recall_company_wide` | `$response->assertForbidden();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_manager_cannot_recall_company_wide')` | phpunit PG | repeat 403; snapshot identical in both locations |
| `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php` | `CompanyWideBatchRecallTest::test_restricted_custom_recall_permission_cannot_execute_company_wide` | `$response->assertForbidden();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_restricted_custom_recall_permission_cannot_execute_company_wide')` | phpunit PG | custom-role action still permission-based plus scope guard |
| `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php` | `CompanyWideBatchRecallTest::test_recall_retry_preserves_timestamp_reason_and_transition_count` | `self::assertSame($firstRecalledAt, $batch->fresh()->recalled_at->toISOString());` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_recall_retry_preserves_timestamp_reason_and_transition_count')` | phpunit PG | repeat same reason 200 original result; same transition IDs/count; changed reason 409 |
| `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php` | `CompanyWideBatchRecallTest::test_second_company_is_unchanged` | `self::assertSame($companyBBefore, $companyBAfter);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_second_company_is_unchanged')` | phpunit PG | real company B matching lot unaffected |
| `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php` | `CompanyWideBatchRecallTest::test_concurrent_request_and_recall_leave_no_untransitioned_request` | `self::assertSame(0, $unresolvedRootsAfterRecall);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_concurrent_request_and_recall_leave_no_untransitioned_request')` | phpunit PG | barrier race; request precedes recall or returns conflict; no phantom root |
| `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php` | `CompanyWideBatchRecallTest::test_history_preserves_request_and_transition_reasons_on_retry` | `$history->assertJsonPath('data.0.transition.reason', 'Company-wide safety recall');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_history_preserves_request_and_transition_reasons_on_retry')` | phpunit PG | also request_reason equals original branch reason and actor/time unchanged after retry |
| `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php` | `CompanyWideBatchRecallTest::test_recall_does_not_append_to_an_already_terminal_root` | `self::assertSame(1, $terminalChildCount);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_recall_does_not_append_to_an_already_terminal_root')` | phpunit PG | existing recalled child retained; no new terminal child on service rerun |

**Additional required supplemental cases introduced by round 2:**

| Exact file | Class::method | Expected assertion | Exact command | Lane | Coverage |
|---|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchRecallWriterRegressionTest.php` | `BatchRecallWriterRegressionTest::test_demo_reseed_cannot_clear_recall_or_request_evidence` | `self::assertSame($beforeRecallEvidence, $afterRecallEvidence);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallWriterRegressionTest::test_demo_reseed_cannot_clear_recall_or_request_evidence')` | phpunit PG | recalled fixture and requested fixture; reseed twice; scoped stock and reasons unchanged, SKIPPED_RECALL_EVIDENCE marker |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallWriterRegressionTest.php` | `BatchRecallWriterRegressionTest::test_generic_update_and_removed_mutators_cannot_bypass_service` | `self::assertFalse(method_exists(Batch::class, 'recall'));` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallWriterRegressionTest::test_generic_update_and_removed_mutators_cannot_bypass_service')` | phpunit PG | repository recall absent; generic updates reject each recall field; service appends one terminal child per unresolved root |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallTransitionUuidTest.php` | `BatchRecallTransitionUuidTest::test_uuidv5_fixture_is_stable` | `self::assertSame('463d9ce3-793e-53c0-bdc5-10e2fd6e90c9', $actual);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallTransitionUuidTest::test_uuidv5_fixture_is_stable')` | phpunit PG | pure fixture after helper exists; call twice; canonical sequence integer1, cross-company yields different UUID |

**Task 5 convention-09 mapping:** second company snapshot unchanged; second location both A1/A2 recalled; rerun same reason returns original 200 state/evidence while changed reason returns 409.

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

export function getBatchRecallCapabilities(): Promise<BatchRecallCapabilitiesData>;
export function useBatchRecallCapabilities(): UseQueryResult<BatchRecallCapabilitiesData>;
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

**Capability client/hook — Task 6 owns the complete consumer:** in existing `apps/web/src/features/batches/api/batches.ts`, `getBatchRecallCapabilities()` returns `apiGet<BatchRecallCapabilitiesData>('/batches/recall-capabilities')` directly. Add `useBatchRecallCapabilities()` to existing `apps/web/src/features/batches/hooks/useBatches.ts`; use `tenantScopedKey(['batches','recall-capabilities'])`, enable only with current tenant/company, and refetch on company switch. Alias `BatchRecallCapabilitiesData = App.Modules.BatchExpiry.Application.DTOs.BatchRecallCapabilitiesData` in the existing types file; no handwritten interface. Form visible only when query success has `enabled===true` AND action permission/module pass. Loading, error, missing data and false all disable/hide submission; do not fall back to true or a role-derived capability. Assert API URL/no double unwrap, key changes for company B, and OFF/loading/error cases. The entry fingerprint changes to `wlota1-recall-request-v3` and remains eagerly emitted under manifest §3.

**M8 — selector data-source contract:** consume `useScopedLocations(): UseQueryResult<ScopedLocation[]>` from `apps/web/src/features/locations/hooks/useScopedLocations.ts` ([line 8](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/locations/hooks/useScopedLocations.ts:8)). It calls `getScopedLocations()` → `/company/locations` ([scopedLocations.ts:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/locations/api/scopedLocations.ts:22)), backed by `LocationController::scopedIndex()` ([line 36](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:36)). Do not use the management/destination list. Task 1 now scopes the stock endpoint; retain this defensive intersection and test an overbroad mocked response because the baseline at [BatchController.php:264](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:264) returns the unfiltered stock list.

Options = scoped locations with `isActive=true` whose IDs occur in `useBatchStock(uuid)` with **physical quantity > 0**, compared using decimal strings/Big, not float coercion. Fully reserved physical lots remain requestable. No options before both queries succeed; show translated loading/error/empty state and disable submit. The chosen location and operation UUID are captured together in pending submission state and remain unchanged on network retry. Company/batch/scope changes invalidate pending state; if a selected location disappears, clear it and require a new valid selection, never silently switch to a default. Rendering A1/A2 stock in administrative detail does not make A2 a selectable request location.

Display `request_reason` under request evidence and `transition.reason`, actor and timestamp under disposition evidence. The transition object is null for requested roots. A stable feature marker `wlota1-recall-request-v3` must appear in the eagerly loaded batch route declaration metadata in `apps/web/src/routes/index.tsx`, be passed into the rendered request UI, and survive minification in the served entry bundle; lazy-chunk-only occurrence is insufficient for manifest §3.

Generation commands:

```bash
(cd apps/api && php artisan typescript:transform)
(cd apps/api && php artisan permissions:export-frontend-map)
```

### Source-checked red entry points — execute before production change

| Exact test file | Class/suite::method/case | Literal target assertion | Baseline-compatible setup | Contrary behavior at HEAD | Exact command | Lane |
|---|---|---|---|---|---|---|
| `apps/web/src/features/batches/pages/BatchSeededPermissionMap.test.ts` | `BatchSeededPermissionMap::includes seeded viewers in batch view` | `expect(PERMISSIONS['batches.view']).toContain('viewer')` | Import existing generated PERMISSIONS directly; no new capability hook/DTO. Run against baseline before Task-6 permission-map regeneration, then assert operator inclusion and all seeded-role exclusions. | Current generated map contains only admin/cashier/manager ([permissionsMap.generated.ts:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/permissionsMap.generated.ts:18)). | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchSeededPermissionMap.test.ts -t "includes seeded viewers in batch view"` | vitest |
| `apps/web/src/features/batches/pages/BatchRecallRequest.test.tsx` | `BatchRecallRequest::shows request action for eligible manager` | `expect(screen.queryByRole('button', { name: 'Request recall' })).toBeInTheDocument()` | Render existing BatchDetailPage with valid auth, batch/stock/query mocks and English test translations; successful capability response mock is intercepted at HTTP, not imported from a missing hook. Existing page ignores it until implemented. | Current batch detail renders recall/delete/edit without request action ([BatchDetailPage.tsx:75](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:75)). | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "shows request action for eligible manager"` | vitest |

After the target red is captured, implement this task and rerun it to green. The supplemental acceptance below has exact commands but is not evidence that HEAD was red.

**Supplemental Vitest acceptance — not claimed red at HEAD:**

Suite/case strings below are exact `describe`/`it` names (Vitest has no PHP class methods). Test dictionaries supply the explicit English accessible names in the assertions; production labels remain translation keys. No DOM class selectors.

| Exact file | Suite::case | Expected acceptance assertion (not red claim) | Exact command | Lane | Convention-09 / rerun outcome |
|---|---|---|---|---|---|
| `apps/web/src/routes/__tests__/BatchRoutePermissions.test.tsx` | `BatchRoutePermissions::denies each direct route without its action permission` | `expect(screen.queryByTestId('batch-page')).not.toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/routes/__tests__/BatchRoutePermissions.test.tsx -t "denies each direct route without its action permission"` | vitest | A/B auth payload provider; rerender stays denied |
| `apps/web/src/features/batches/pages/BatchPermissions.test.tsx` | `BatchPermissions::manager sees request but not company recall` | `expect(screen.queryByRole('button', { name: 'Recall batch' })).not.toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchPermissions.test.tsx -t "manager sees request but not company recall"` | vitest | translated en fixture; request action visible at allowed A1 |
| `apps/web/src/features/batches/pages/BatchPermissions.test.tsx` | `BatchPermissions::viewer cannot mutate and trace query remains disabled` | `expect(traceApi).not.toHaveBeenCalled()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchPermissions.test.tsx -t "viewer cannot mutate and trace query remains disabled"` | vitest | rerender with same view-only payload remains no call; denied controls absent |
| `apps/web/src/features/batches/pages/BatchRecallRequest.test.tsx` | `BatchRecallRequest::retry retains operation UUID and selected location` | `expect(secondPayload).toEqual(firstPayload)` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "retry retains operation UUID and selected location"` | vitest | same operation UUID/location/reason across network failure retry; response replayed=true retains one history row |
| `apps/web/src/features/batches/pages/BatchRecallRequest.test.tsx` | `BatchRecallRequest::shows scoped positive stock options only` | `expect(screen.queryByRole('option', { name: 'Branch A2' })).not.toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "shows scoped positive stock options only"` | vitest | scoped endpoint A1 only; stock endpoint A1/A2; A1 offered even fully reserved; A2 absent |
| `apps/web/src/features/batches/pages/BatchRecallRequest.test.tsx` | `BatchRecallRequest::company switch discards stale selector and pending request` | `expect(screen.queryByRole('option', { name: 'Branch A1' })).not.toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "company switch discards stale selector and pending request"` | vitest | switch A→B; B1 selectable, old UUID/location never submitted; rerender no stale options |
| `apps/web/src/features/batches/pages/BatchRecallRequest.test.tsx` | `BatchRecallRequest::history distinguishes request and transition reasons` | `expect(screen.getByText('Company-wide safety recall')).toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "history distinguishes request and transition reasons"` | vitest | also branch reason separately rendered; repeated history fetch no duplicate entry |
| `apps/web/src/features/batches/pages/BatchRecallRequest.test.tsx` | `BatchRecallRequest::dormant capability hides request submission` | `expect(screen.queryByRole('button', { name: 'Request recall' })).not.toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "dormant capability hides request submission"` | vitest | OFF/loading/error provider; no request mutation on rerender |
| `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx` | `Sidebar::hides batches without view permission` | `expect(screen.queryByRole('link', { name: 'Batches' })).not.toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx -t "hides batches without view permission"` | vitest | A/B viewer payloads; module alone insufficient; rerender stable |

**Additional capability/map supplemental cases:**

| Exact file | Suite::case | Expected assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/web/src/features/batches/pages/BatchSeededPermissionMap.test.ts` | `BatchSeededPermissionMap::matches all approved seeded batch roles` | `expect(PERMISSIONS['batches.traceability']).not.toContain('operator')` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchSeededPermissionMap.test.ts -t "matches all approved seeded batch roles"` | vitest |
| `apps/web/src/features/batches/hooks/__tests__/useBatchRecallCapabilities.test.tsx` | `useBatchRecallCapabilities::uses scoped key and fails closed` | `expect(result.current.data?.enabled ?? false).toBe(false)` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/hooks/__tests__/useBatchRecallCapabilities.test.tsx -t "uses scoped key and fails closed"` | vitest |
| `apps/web/src/features/batches/api/__tests__/capabilities.test.ts` | `capabilities::fetches and unwraps once` | `expect(result).toEqual({ enabled: true })` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/api/__tests__/capabilities.test.ts -t "fetches and unwraps once"` | vitest |

The hook case covers OFF, loading, error, ON and company-A→B key change; the map case enumerates every approved role including technician/accountant/cashier. These run after the new API/hook exists; their missing imports at HEAD are not red evidence.

**Task 6 convention-09 mapping:** company-switch case proves B cannot reuse A selector/pending state; scoped-options case proves A2 is not offered to A1-only manager; retry case proves same UUID/location/payload and a single replayed history entry. Backend registered-fixture cases remain authoritative for data isolation.

Run the repository React diagnostics workflow during implementation, then `pnpm --filter @autoerp/web typecheck` and applicable lint/browser checks. No tests are run for this plan-only revision.

**Migration/schema:** none; generated DTO/map output only.

**Reviewer gate:** tenancy-authz-reviewer verifies server-authoritative permission behavior and query gating; inventory-costing-reviewer verifies that physical quantity remains visible and hold status is clearly separate from stock quantity.

**Rollback:** roll back the new form/navigation as needed while retaining backend action permissions and hold-aware issue enforcement. Do not deploy a permission map generated from an older role definition.

## Deferred to W-LOT-A-1b under ruled Q10

W-LOT-A-1b adds the ruled release/reject action, permission and UI; a `released` terminal enum/check alternative; and its authorization, evidence, replay and concurrency tests. This slice reserves the same single terminal-child slot and already transports typed disposition reason/actor/time. It has no release/reject action or behavioral test. Recall transitions only roots without a terminal child; eligibility identifies unresolved roots by absence of **any** terminal child, not absence of a recalled child specifically. This makes the later ruled alternative mutually exclusive without rewriting request evidence.

## Deployment and rollback points

Deployment follows `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`
(five-push sequence §2, web block §3, gate checklist §4). This slice supplies only the variables
below; it does not restate deploy mechanics.

| Variable | W-LOT-A-1 value |
|---|---|
| `<slice>` | `wlota1-permissions-hold` |
| Migrations list | `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php` then `apps/api/database/migrations/tenant/2026_09_06_210000_create_batch_recall_requests_table.php`; both **additive / self-guarding**. Prerequisites: existing roles and company/user/location/batch tables; fail before partial DDL if absent. Marker precedes safe seeder. Request migration checks existing table/columns/checks/indexes/triggers for exact compatibility and rejects drift; it does not treat `hasTable` alone as success. Neither migration grants permissions or writes recall evidence. |
| Flags | `batch_recall.enabled` / `BATCH_RECALL_ENABLED` / new `apps/api/config/batch_recall.php` / default false; one config value in API, worker and scheduler. Existing-hold safety latch described above survives flag-off rollback. No separate web build-time activation flag; web reads capabilities. |
| Commands | Proposed `permissions:apply-lot-recall-delta {--apply} {--verify}` under `tenants:run`, marker `WLOTA1-PERMISSIONS`; revised `db:seed --class=Database\Seeders\RolesAndPermissionsSeeder --force` under `tenants:run`, marker `WLOTA1-RESEED`; `permission:cache-reset` under `tenants:run`, marker **`Permission cache flushed.`** (terminal period included); `typescript:transform` and `permissions:export-frontend-map` run locally in `apps/api`, **not** under `tenants:run`, generated-file assertions rather than fleet markers. No new queue command. |
| Censuses | Manifest §2 Push 1/4: `tenant:census-day-one` and `pos:census-vat-legs` under `tenants:run`; inventory lot-drift census and phantom-default dry run as named by manifest. Day-one: every `DAY-ONE CENSUS` verdict clean, no `DRIFT(`; lot drift: fail-on-drift exit must be zero and no new reported tuple. VAT/phantom outputs compared to captured Push-1 baseline. No Treasury or document repair writes in this slice. Projection safety conflicts count as actionable drift, never clean success. |
| Web changes | **yes** — execute canonical **§3 web deploy block in full**, explicit Dokploy web deploy, before/after served entry asset hash, served-entry grep marker `wlota1-recall-request-v3` count ≥1, then manager/general-manager browser smoke. No invented fingerprint endpoint. |
| Device build | **no** — no POS/Tauri device build in this slice; offline limitation is explicit in Task 4. |
| Queues | **none new**. Existing fiscal projection workers must receive identical config; retain existing Horizon coverage checks. |
| Collapsed pushes | **Push 1 collapsed into the pre-Push-2 baseline evidence step**: existing read-only censuses run and outputs are attached before the first schema push; no tooling commit or empty push. Push 2 schemas only; Push 3 dormant implementation and safe seeder; Push 4 manual delta/reseed/cache/verification; Push 5 activation and explicit web deployment. Task order is code dependency order, not permission to activate early. |
| Env path | Planned path: **Dokploy Environment tab for each separate application**, contingent on closing U-1. If U-1 proves compose, record the selected alternative and add `BATCH_RECALL_ENABLED` to `docker-compose.staging.yml` `x-api-env` in the manifest's additional env-wiring push before activation. Do not claim the topology is already verified. Read config back in all consumers. |

**Cache marker provenance:** entrypoint executes `php artisan permission:cache-reset 2>/dev/null || true` at [entrypoint.sh:176](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:176); it does not define a separate success string. The invoked installed command emits exactly **`Permission cache flushed.`** at [CacheReset.php:20](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/spatie/laravel-permission/src/Commands/CacheReset.php:20). Capture this exact stdout marker per tenant; entrypoint's swallowed exit status is not success evidence. The vendor citation describes the installed dependency at the planning checkout, not a tracked application source file.

**Push-4 exact command variables** (inside API container; all fleet outputs saved using canonical `<slice>` log names):

```bash
php artisan tenants:run permissions:apply-lot-recall-delta --option='apply=1'
php artisan tenants:run db:seed --option='class=Database\Seeders\RolesAndPermissionsSeeder' --option='force=1'
php artisan tenants:run permission:cache-reset
php artisan tenants:run permissions:apply-lot-recall-delta --option='verify=1'
```

Compare `tenant=<uuid>` success-marker sets from apply, reseed and verify with the manifest's captured `TENANT_IDS`. Reject FAILED, missing tenants, duplicate unexpected verdicts and SKIPPED in the apply/verify legs. For cache reset, require one `Permission cache flushed.` success per expected tenant and no error, correlated with each tenant header; if wrapper output does not provide unambiguous attribution, run `php artisan tenants:run permission:cache-reset --tenants=<tenant-uuid>` once per captured tenant and save a separately named log. The wrapper exit status is never sufficient. Rerun apply must produce ALREADY_APPLIED. Keep runtime flag false through this sequence. Resolve unknown role-name collisions before activation; do not auto-adopt.

**Promotion preconditions:** canonical manifest **U-1, U-2 and U-6 must be closed with staging evidence**: topology/env path, actual database-per-tenant mode ON, and actual boot permission-sync setting. For U-6, verify a boot reseed cannot mutate an unmarked custom general-manager role or prematurely activate this delta. Read-only plan work does not claim those checks happened. Also resolve the host/credential identification required by U-5 before the mandatory host backup; never substitute production AX42 coordinates.

**Host-side backup variable:** execute the manifest §2 backup-before step for **Push 2 and Push 4**, for every affected tenant, to host-owned files `/root/backup-wlota1-permissions-hold-<tenant-uuid>-<UTC timestamp>.dump`; verify nonzero size before promotion/application. Record grant/marker snapshots for Push 4 alongside the manifest evidence. Backups left only inside the replaced API container do not satisfy the gate.

**Rollback variable per push:** P1 collapsed: no commit to revert, retain pre-P2 census evidence; P2 leave additive schemas, correct forward; P3 keep flag false or revert dormant code only while no request evidence exists; P4 preserve marked provenance and evidence, correct targeted grants forward from recorded before-state, never blanket-sync or regrant manager recall; P5 set flag false using canonical worker-before-API/config-settle sequence and roll back the web artifact if needed. Retain hold-aware code and the data-safety latch after evidence exists; never return to an image that ignores holds. Additional compose env-wiring push, if U-1 requires it: flag remains false and rollback is removal of unused wiring only before activation.

### Canonical gate checklist — verbatim §4

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


## Dispatch order

1. Task 1 — dormant API permission gates and location scope on every batch read.
2. Task 2 — safe role delta and all assignment guards.
3. Task 3 — immutable request schema and writer, unactivated.
4. Task 4 — server projection/direct-transfer/stock-transfer enforcement.
5. Task 5 — company-wide recall and transitions.
6. Task 6 — generated permissions/types and existing-detail UI.

Dispatch each task with its dependency commits and both reviewer gates. Activate request creation only after Tasks 3–5 pass the PostgreSQL lane.

## Verification checklist

- [ ] Q10 RULED recorded; release/reject implementation assigned to W-LOT-A-1b.
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
- [ ] A1 hold blocks new server consumption and both transfer paths; A2/company B remain independent. Device blocking is W-LOT-B refresh/cache acceptance.
- [ ] PostgreSQL races prove post-lock eligibility and duplicate-request protection.
- [ ] Company-wide recall transitions only unresolved roots and preserves existing terminal evidence on retry.
- [ ] No release/reject writer enters this revision; terminal uniqueness is status-independent and transition evidence includes its own reason.
- [ ] Web routes, actions and queries follow matching permissions.
- [ ] Generated DTOs/map, translations and glossary are included.
- [ ] Both reviewers approve each task.
- [ ] Shared staging manifest records per-tenant migration, reseed, cache reset, deployed fingerprints and rollback artifacts.
