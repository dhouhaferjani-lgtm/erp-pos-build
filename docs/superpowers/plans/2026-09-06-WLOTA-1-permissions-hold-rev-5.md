<!-- W-LOT-A-1 rev 5, Codex CLI fix round 4 (union of rev 3 packets + rev 4 design, gpt-5.6-sol, read-only) on 2026-09-06, saved verbatim by the orchestrator (owner away). Rev 4 = df7708e65. Status: awaiting gate r5. -->
# Slice plan W-LOT-A-1 — lot permissions, general-manager role, policy-neutral branch hold (rev 5)

<!-- Rev 5 is the content-preserving union of rev 3's dispatch packets and rev 4's accepted design corrections, plus the complete r4 gate closure. -->

Planning baseline: **`22baed678d57851b35ba7194e300f2fdec4a8c89`** on local `dev`.

All existing-code claims and `path:line` citations were checked at that SHA.

The only changes between the r4 reviewer’s `5a241c7604e8ec5d050560916a29c29935e56d60` baseline and this baseline are documentation files:

- `docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md`
- `docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r4.md`

Therefore, the cited production and test seams are unchanged.

This is a read-only implementation plan.

No code, tests, migrations, generated files, environment variables, deployments, or Git state were changed.

---

## Round-four gate change log

Review closed in full:

`docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r4.md`.

| Gate item | Status | Rev-5 disposition |
|---|---|---|
| B1 — lock protocol | **CLOSED — plan line R4-B1** | One lock hierarchy is defined for request, recall, FEFO, delivery, direct transfer, StockTransfer automatic allocation, StockTransfer explicit allocation, and POS projection. Recall locks the batch and every company batch-stock row in stable ID order before changing global state or scanning roots. Barrier-driven PostgreSQL tests cover request-versus-recall and recall-versus every issue path, with bounded completion and no lost hold. |
| B2 — direct recall evidence | **CLOSED — plan line R4-B2** | Direct company-wide recall writes append-only `global_recall` evidence with a dedicated idempotency namespace, actor, reason, time, and operation UUID. Legacy recalled rows are preserved but cannot have invented evidence. |
| B3 — dispatch packets | **CLOSED — plan line R4-B3** | All six tasks retain exact production/test files, complete signatures, schema contracts, assertion-red tests, commands, lanes, implementation sequence, convention-09 mapping, both reviewer gates, and task-local rollback. |
| M1 — delivery-note explicit lot bypass | **CLOSED — plan line R4-M1** | Both explicit `batch_id` delivery and automatic delivery FEFO call the same post-lock eligibility decision. Write-off and count-correction callers are separately enumerated disposition paths. |
| M2 — refusal/capability contracts | **CLOSED — plan line R4-M2** | `BatchHeldException`, exact 422 envelopes, POS projection conflict mapping, `BatchRecallCapabilitiesData(bool $enabled)`, the literal capability route, permission behavior, and backend/frontend assertion-red tests are restored. |
| M3 — evidence correlation | **CLOSED — plan line R4-M3** | Evidence has direct company, batch, location, actor, and parent FKs where applicable. Insert triggers validate tenant/company/batch/location/actor/stock-tuple correlation before accepting evidence. |
| M4 — zero-stock company lots | **CLOSED — plan line R4-M4** | Unrestricted company users retain metadata visibility for valid lots with no stock/history. Restricted users see only stock or attributable history in their allowed locations. Nullable-location traces remain company-visible only to unrestricted users. |
| M5 — traceability module boundary | **CLOSED — plan line R4-M5** | BatchExpiry stops importing Document and POS models. Narrow Shared trace-reader contracts are implemented by Document and POS adapters. |
| M6 — conventions 09/11 | **CLOSED — plan line R4-M6** | All six tasks carry explicit convention-09 applicability and evidence. Complete glossary rows name the table/module, sole writer, canonical batch-detail surface, and synonyms. |
| M7 — Push 3 and census handoff | **CLOSED — plan line R4-M7** | U-6 false/readback is mandatory before Push 3. Exact files per push are listed. P1 baselines are copied to verified host-owned storage, checksummed, and restored into the P4 container. Exact commands, markers, cardinality checks, and exit rules are supplied. |
| N1 — current SHA | **CLOSED — plan line R4-N1** | Baseline is `22baed678d57851b35ba7194e300f2fdec4a8c89`; the intervening source diff is documentation-only. |
| N2 — benchmark provenance | **CLOSED — plan line R4-N2** | OCA, ERPNext, and eCFR claims link directly to sources. Dolibarr comparisons that were not verified are labelled `NV`, not asserted from memory. |

<a id="R4-N1"></a>

**Plan line R4-N1:** implementation dispatch must record its starting SHA.

If it differs from `22baed678d57851b35ba7194e300f2fdec4a8c89`, the implementer must re-read every cited seam and update the packet before writing the first test.

### Rejected false positives retained from r4

- **REJECTED:** Q10 is not reopened.

  The accepted ruling is recorded at [OWNER-RULINGS:147](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:147), with the exact Q10 text at [OWNER-RULINGS:151](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151).

- **REJECTED:** release/reject does not belong in this slice.

  The ruling allows `requested → recalled` or `requested → released`, but the policy-neutral already-issued slice remains valid at [OWNER-RULINGS:158](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:158).

- **REJECTED:** terminal uniqueness must not be status-specific.

  Rev 5 preserves one terminal child per request independently of whether the later terminal kind is `recalled` or `released`.

- **REJECTED:** a root/transition UUID collision is not prohibited globally.

  Root, request-transition, and global-recall operation UUIDs occupy distinct partial-index namespaces.

- **REJECTED:** device-immediate knowledge is not promised.

  W-LOT-B owns cache refresh and session-open enforcement; this slice owns fresh server-side issue exclusion.

- **REJECTED:** `product_batches` may be omitted from convention-09 registration.

  It is an operator-edited catalogue entity under [convention 09:30](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:30).

- **REJECTED:** the new evidence table is a catalogue table.

  It is immutable workflow evidence, explicitly classified outside catalogue identity.

- **REJECTED:** public BatchExpiry services may import the Identity `User` model.

  Public boundaries use `App\Shared\Contracts\Identity\ActorIdentityScopeData`.

- **REJECTED:** a test may count a missing class or SQL exception as assertion-red.

  Every red test named below reaches and fails its stated assertion.

---

## Scope and non-goals

This slice contains six dispatchable tasks.

It implements:

- Action-specific BatchExpiry permissions.

- Location-safe batch reads.

- Shared-contract traceability reads.

- The `general_manager` seeded role.

- Restricted-assignment prevention.

- Immutable branch recall requests.

- Append-only request transitions.

- Append-only direct global-recall evidence.

- Branch-local server issue holds.

- Company-wide recall.

- Canonical issue eligibility.

- Company-aware atomic FEFO.

- Exact held-lot refusal mappings.

- Existing batch-detail request/history UI.

- Generated frontend permissions and DTO types.

It does not implement:

- Release.

- Reject.

- Branch self-release.

- A second recall-management page.

- A second recall writer.

- A device/Tauri build.

- Instant mid-session device push.

- Retrospective rejection of a sealed fiscal receipt.

- Stock quantity mutation merely because a hold exists.

- Reservation mutation merely because a hold exists.

- Valuation or GL mutation merely because a hold exists.

---

## Industry baseline — benchmark-first, convention 10

<a id="R4-N2"></a>

**Plan line R4-N2:** every supported comparator statement below is linked.

Flow: permissioned lot reads and recall, location-specific recall requests, append-only evidence, and immediate fresh-server issue/transfer exclusion.

Sources:

- [OCA 18.0 Stock Lock Lot](https://github.com/OCA/stock-logistics-workflow/tree/18.0/stock_lock_lot) documents a blocked-lot state, authority to block/unblock, and destination locations that may accept locked lots.

- [ERPNext Batch](https://docs.frappe.io/erpnext/batch) documents the batch entity and disabled-batch mechanism.

- [ERPNext User Permissions](https://docs.frappe.io/erpnext/user-permissions) documents record restrictions such as warehouse-scoped access.

- [21 CFR 211.22](https://www.ecfr.gov/current/title-21/chapter-I/subchapter-C/part-211/subpart-B/section-211.22) assigns approval/rejection responsibility to the quality-control unit; it does not establish that AutoERP’s `general_manager` is a legally qualified quality authority.

- Dolibarr comparisons are `NV` because no equivalent documented request/hold workflow was verified for this plan.

| ID | Guarantee | Odoo | ERPNext | Dolibarr (or NV + reason) | AutoERP today path:line | Gap | Decision |
|---|---|---|---|---|---|---|---|
| create | An authorized operator can prevent unsafe lot issue | OCA blocked-lot flag and restricted movement | Disabled batch | NV — equivalent branch-request workflow not verified | Global recall mutator only at [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134) | No branch-local request/hold | **MATCH — W-LOT-A-1** |
| duplicate | Retrying the same safety request does not duplicate effects | Exact request identity not documented | Exact request identity not documented | NV | Current recall accepts a reason only at [BatchController.php:193](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193) | No stable operation identity | **MATCH — W-LOT-A-1** |
| edit | Submitted safety evidence remains immutable and attributable | Blocked state exists; this append-only evidence contract is not documented | Exact immutable request evidence not verified | NV | Current entity overwrites fields at [Batch.php:136](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:136) | Actor/reason/time can be replaced | **MATCH — W-LOT-A-1** |
| cancel | Disposition authority is explicit | Dedicated block/unblock authority | Exact approval chain not verified | NV | No request disposition workflow | Release/reject is ruled but outside this slice | **DEFER — W-LOT-A-1b** |
| rerun | A rerun preserves the original evidence and outcome | Exact retry contract not documented | Exact retry contract not documented | NV | Current call rewrites `recalled_at` at [Batch.php:139](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:139) | Retry changes evidence | **MATCH — W-LOT-A-1** |
| second company | Company B cannot observe or affect company A’s lot | Exact request workflow not documented | User Permissions restrict records | NV | Company mismatch returns 404 at [BatchController.php:68](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:68) | New evidence and eligibility need equivalent isolation | **MATCH — W-LOT-A-1** |
| second location | A branch hold affects only its source location | OCA supports location exceptions, not this branch request | Warehouse restrictions exist separately from disabling | NV | FEFO filters location/global state but not a local hold at [FEFOInventoryService.php:260](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:260) | No local hold predicate | **DIVERGE — branch-local hold plus retained global recall** |
| permission | Each action requires explicit authority | Dedicated block/unblock grant | Role and User Permissions | NV | Batch routes mostly inherit only auth/module middleware at [routes.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12) | Read/trace/delete/recall gates are incomplete | **MATCH — W-LOT-A-1** |
| audit | Every safety action retains actor, reason, time, and operation UUID | Exact immutable audit not documented | Exact request audit not verified | NV | Current projection has mutable reason/time and no actor/UUID at [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134) | Missing request, transition, and direct-recall evidence | **MATCH — W-LOT-A-1** |

No guarantee implemented by this slice is labelled `DEFER`.

Only release/reject remains deferred to W-LOT-A-1b.

---

## Vocabulary — convention 11

<a id="R4-M6"></a>

**Plan line R4-M6:** the lane modifies [docs/glossary.md](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md), where the current lot row is at [line 41](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:41).

The lane adds these complete rows:

| Term | Definition | Table / module | Primary writer | Canonical operator surface | Synonyms |
|---|---|---|---|---|---|
| **Recall request** | Immutable, reasoned request to recall one company lot from one accessible source location. Effective state is requested until its single terminal transition exists. | `batch_recall_requests` / BatchExpiry | `BatchRecallService::request()` only | Existing batch detail → Request recall and Recall history | branch recall request |
| **Branch hold** | Server-side issue and transfer exclusion derived from an unresolved recall-request root at its source location. It changes eligibility, never physical quantity, reservations, valuation, movements, or GL. | Derived from `batch_recall_requests`; no separate table | No independent writer; created only as the consequence of `BatchRecallService::request()` | Existing batch detail → location stock and recall history | local lot hold |
| **Recall transition** | Immutable terminal evidence connecting a request root to company-wide recall. | `batch_recall_requests` / BatchExpiry | `BatchRecallService::recall()` only | Existing batch detail → Recall history | disposition evidence |
| **Global recall evidence** | Immutable evidence for direct company-wide recall where no request root existed, retaining operation UUID, actor, reason, and time. | `batch_recall_requests` with `kind=global_recall` / BatchExpiry | `BatchRecallService::recall()` only | Existing batch detail → Recall history | direct recall evidence |
| **General manager** | Seeded role containing revised manager grants plus company-wide recall and Treasury all-location authority. Assignment requires active unrestricted membership in every applicable company. | Existing Spatie `roles`; marker in `roles.provisioning_source` | Permission-delta service and guarded Identity role assignment | Existing user and role administration | `general_manager` |

One concept has one surface:

- No separate recall dashboard.

- No alternate hold table.

- No alternate lifecycle service.

- No handwritten frontend evidence interface.

- No BatchExpiry query of Document or POS models.

- Existing batch detail is the single operator surface.

---

## Owner ruling and deferred boundary

The exact accepted Q10 ruling is:

> Hold lifecycle `requested → recalled` or `requested → released`; only the general manager may release or reject; mandatory reason and append-only evidence; the requesting branch never lifts its own hold.

Source: [OWNER-RULINGS:151](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151).

This slice implements only:

```text
requested → recalled
```

W-LOT-A-1b owns:

- `released` as another terminal status.

- Reject/release permission.

- Reject/release request.

- Reject/release service action.

- Reject/release route.

- Reject/release UI.

- Reject/release replay tests.

- Reject/release race tests.

The requesting branch never lifts its own hold.

A request root has at most one terminal child, regardless of terminal status.

The terminal unique must remain status-independent.

---

## Shared actor contract

Add:

`apps/api/app/Shared/Contracts/Identity/ActorIdentityScopeData.php`

```php
<?php

declare(strict_types=1);

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

Semantic contract:

- `allowedLocationIds === null` means unrestricted.

- `allowedLocationIds === []` means no location access.

- Public BatchExpiry service signatures never import `App\Modules\Identity\Domain\User`.

- Controllers build this immutable DTO from authenticated context.

- A caller-supplied company or tenant ID is never trusted.

- Abilities are re-evaluated before replay.

- Historic operation UUID ownership never bypasses current authorization.

This follows the cross-module rule in [CLAUDE.md:30](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:30).

---

## Shared activation contract

Add:

`apps/api/config/batch_recall.php`

```php
<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('BATCH_RECALL_ENABLED', false),
];
```

Add:

`apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallActivation.php`

```php
final readonly class BatchRecallActivation
{
    public function enabled(): bool;
}
```

Activation controls only:

```text
POST /api/v1/batches/{uuid}/recall-requests
```

Activation does not control:

- Existing read permission enforcement after Push 5.

- Location scoping after Push 5.

- Existing delete permission enforcement.

- Existing company-wide recall permission enforcement.

- GET recall history.

- GET capability reporting.

- Local-hold issue enforcement.

- Global-recall issue enforcement.

- Append-only evidence triggers.

- Writer ratchets.

Flag rollback disables new request creation only.

---

## Shared authorization and response precedence

- Missing authentication remains 401.

- Missing BatchExpiry module admission remains the existing module response.

- Missing action permission is 403 before mutation.

- Foreign-company batch is 404.

- Inaccessible location for recall request is 404.

- Absent location is 404.

- Malformed batch UUID remains scoped 404 where that is the existing contract.

- Malformed request operation/location UUID is 422.

- Blank or overlength reason is 422.

- Request replay conflict is 409.

- Direct recall replay conflict is 409.

- Existing custom roles remain permission-based.

- No endpoint checks a hardcoded role name for authorization.

- Company-wide recall additionally requires active unrestricted membership.

- `treasury.manage_all_locations` is not a recall-request location bypass.

---

## Server/device boundary

The live new-sale authoring endpoint is retired before controller work at [POS routes:168](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/routes.php:168).

The live device-authored sale reaches server lot projection through `PosCoreReceiptProjection`, whose FEFO call is around [PosCoreReceiptProjection.php:2046](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2046).

This slice guarantees:

- After a branch hold commits, fresh server issue work cannot consume that batch at that location.

- After global recall commits, fresh server issue work cannot consume that batch in any company location.

- A waiting issue transaction rechecks eligibility after acquiring locks.

- A sealed fiscal event is preserved if lot projection is refused.

- The refused lot leg produces no held-lot movement or allocation.

- A later eligible lot may satisfy FEFO.

- A hold does not rewrite physical stock.

This slice does not guarantee:

- Instant terminal knowledge.

- Mid-session device invalidation.

- Device push delivery.

- Retroactive rejection of a sealed receipt.

W-LOT-B must consume the decision contract defined in Task 4.

W-LOT-B snapshot rows must include:

```ts
isHeld: boolean
isRecalled: boolean
eligibleForIssue: boolean
physicalQuantity: string
eligibleQuantity: string
eligibilityReasons: BatchIssueEligibilityReason[]
sourceRevision: string
serverCapturedAt: string
```

For a held row:

```text
physicalQuantity = unchanged physical stock
eligibleQuantity = "0.0000"
eligibleForIssue = false
```

Blocked rows or explicit tombstones must survive atomic full-snapshot replacement.

Omission must never leave a formerly eligible cached row alive.

---

## Canonical lock protocol

<a id="R4-B1"></a>

**Plan line R4-B1 — one lock hierarchy**

The relative lock order is:

```text
0. stock_levels aggregate rows, if the existing operation requires them
1. product_batches rows, ascending product_batches.id
2. inventory_batch_stock rows, ascending inventory_batch_stock.id
3. requested-root rows, ascending batch_recall_requests.id
4. existing terminal/global evidence rows, ascending batch_recall_requests.id
5. movement/allocation append rows
```

Rules:

- A path that does not need an earlier class of row skips it.

- It never acquires an earlier class after a later class.

- Multi-batch work locks every batch row in ascending numeric ID order.

- It then locks all relevant batch-stock rows in ascending numeric ID order.

- Root/evidence scans happen only after batch and batch-stock locks.

- No path locks batch stock and subsequently tries to acquire its batch.

- `SKIP LOCKED` may be used for candidate discovery, but not as the final safety decision.

- Final eligibility is evaluated after the required batch and stock locks.

### Recall-request order

Within one transaction:

1. Resolve the scoped batch UUID without mutation.

2. Lock the `product_batches` row.

3. Re-read tenant, company, active state, and `is_recalled`.

4. Run root-namespace replay lookup.

5. Resolve the requested location through actor scope.

6. Lock the exact `inventory_batch_stock` tuple.

7. Re-read physical quantity.

8. Re-read global recall state.

9. Re-read unresolved request state.

10. Insert the request root.

11. Commit.

If recall already committed, a new request is rejected.

A matching historic replay may return its original evidence only after authorization is revalidated.

### Company-wide recall order

Within one transaction:

1. Resolve the batch in actor company.

2. Lock the `product_batches` row.

3. Re-read the projection and global-evidence replay state.

4. Query every `inventory_batch_stock` row for that company batch.

5. Lock all such stock rows in ascending `inventory_batch_stock.id`.

6. Re-read the batch state.

7. Insert or replay the append-only `global_recall` evidence.

8. Change the global projection only for a fresh operation.

9. Scan unresolved request roots.

10. Lock roots in ascending request UUID/ID order.

11. Insert deterministic recalled transitions.

12. Commit.

Recall must not change global state before all batch-stock rows are locked.

Recall must not scan roots before all batch-stock rows are locked.

### Issue-path order

POS projection FEFO, direct batch transfer, StockTransfer automatic allocation, StockTransfer explicit allocation, automatic delivery FEFO, and explicit delivery lot issue all follow the same batch-before-batch-stock order.

For a single explicit batch:

1. Lock any required aggregate `stock_levels` row.

2. Lock the batch.

3. Re-read company/product/variant/global state.

4. Lock the source batch-stock tuple.

5. Re-read physical/reserved quantity.

6. Call the canonical eligibility decision.

7. Write movements/allocations only if eligible.

For multi-candidate FEFO:

1. Discover candidate IDs without treating discovery as authorization.

2. Sort batch IDs.

3. Lock batch rows in ascending ID.

4. Discover corresponding stock-row IDs.

5. Lock stock rows in ascending ID.

6. Call the decision service using the locked facts.

7. Allocate in FEFO order among eligible rows.

8. Write movements.

### PostgreSQL bounded-completion contract

Every barrier race:

- Uses two independent PostgreSQL connections/processes.

- Sets a local `lock_timeout`.

- Sets a local `statement_timeout`.

- Records both worker completion timestamps.

- Joins workers within a fixed test deadline.

- Asserts neither worker remains alive.

- Asserts no deadlock exception.

- Asserts one serializable outcome.

- Asserts no late request root.

- Asserts no post-recall held-lot movement.

- Asserts no lost hold.

SQLite is not accepted for lock correctness.

---

## Append-only evidence model

<a id="R4-B2"></a>

**Plan line R4-B2 — direct recall evidence choice**

Rev 5 chooses an explicit append-only `global_recall` evidence kind.

Every new company-wide recall, including one with no prior request, carries:

- Actor.

- Reason.

- Created time.

- Operation UUID.

- Tenant.

- Company.

- Batch.

Direct recall does not manufacture a branch request.

Request transitions remain parented to their roots.

Global recall evidence has no parent and no location.

The three idempotency namespaces are independent:

```text
request_root
request_transition
global_recall
```

A UUID may legally exist once in each namespace.

Within one namespace it is unique per company.

A batch may have at most one `global_recall` evidence row.

The global projection and its evidence are written atomically.

Legacy rows where `product_batches.is_recalled=true` before this migration:

- Are not rewritten.

- Are not backfilled with a fabricated actor.

- Are not backfilled with a fabricated operation UUID.

- Retain their original projection reason/time.

- Return `409 LEGACY_RECALL_EVIDENCE_ABSENT` if an operator tries to replay or replace them through the new recall action.

- Remain ineligible for issue.

---

# Task 1 — Stage action permissions, scope every batch read, and remove traceability model coupling

<a id="R4-B3"></a>

**Plan line R4-B3:** this task is a complete dispatch packet.

## Task 1 dependencies

None.

## Task 1 production files — modify

- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php`

- `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`

- `apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php`

- `apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php`

- `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`

## Task 1 production files — add

- `apps/api/config/batch_recall.php`

- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallActivation.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php`

- `apps/api/app/Shared/Contracts/BatchTraceability/BatchTraceOccurrenceData.php`

- `apps/api/app/Shared/Contracts/BatchTraceability/DocumentBatchTraceReader.php`

- `apps/api/app/Shared/Contracts/BatchTraceability/PosBatchTraceReader.php`

- `apps/api/app/Modules/Document/Infrastructure/BatchTraceability/DocumentBatchTraceReaderAdapter.php`

- `apps/api/app/Modules/POS/Infrastructure/BatchTraceability/PosBatchTraceReaderAdapter.php`

## Task 1 source facts

Current body-less routes have no action middleware at [routes.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:14), [routes.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:22), and [routes.php:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:29).

Current detail eagerly loads every batch-stock row at [BatchController.php:112](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:112).

Current stock returns every location at [BatchController.php:264](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:264).

Current `expiring()` accepts an unvalidated `location_id` at [BatchController.php:215](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:215).

Current `expired()` already validates UUIDs and resolves location scope at [BatchController.php:241](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:241).

Current traceability directly imports Document and POS models at [BatchTraceabilityController.php:11](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:11).

<a id="R4-M5"></a>

**Plan line R4-M5:** BatchExpiry must not retain those cross-module imports.

## Task 1 route matrix after Push 5

| Surface | Permission |
|---|---|
| List | `batches.view` |
| Detail | `batches.view` |
| Expiring | `batches.view` |
| Expired | `batches.view` |
| Batch stock | `batches.view` |
| Product batch stock | `batches.view` |
| POS suggestions | `batches.view` |
| Capability | `batches.view` |
| Recall history | `batches.view` |
| Forward trace | `batches.traceability` |
| Backward trace | `batches.traceability` |
| Create | existing `batches.create` |
| Update | existing `batches.update` |
| Destroy/deactivate | `batches.delete` |
| Company-wide recall | `batches.recall` |
| Request recall | `batches.recall.request` |
| Write-off/reversal | existing `batches.write-off` |

Retain:

- `api`.

- `auth:sanctum`.

- `SetPermissionsTeam`.

- `EnforceTokenTenantClaim`.

- `module:BatchExpiry`.

- Literal routes before `{uuid}`.

- Existing FormRequest authorization.

## Task 1 middleware signature

```php
final readonly class BatchActionAccess
{
    public function handle(
        Request $request,
        Closure $next,
        string $permission,
    ): Response;
}
```

Allowed permission arguments are explicit:

```php
[
    'batches.view',
    'batches.traceability',
    'batches.create',
    'batches.update',
    'batches.delete',
    'batches.recall',
    'batches.recall.request',
    'batches.write-off',
]
```

Unknown arguments fail closed.

## Task 1 repository signatures

```php
/** @return Collection<int, Batch> */
public function getByCompany(
    string $companyId,
    array $filters = [],
    ?array $locationIds = null,
    bool $includeCompanyMetadataWithoutStock = false,
): Collection;

/** @return Collection<int, Batch> */
public function getByProduct(
    string $tenantId,
    string $companyId,
    string $productId,
    bool $activeOnly = true,
    ?array $locationIds = null,
): Collection;

/** @return Collection<int, Batch> */
public function getExpiringProducts(
    string $companyId,
    int $daysThreshold = 30,
    string|array|null $locationId = null,
): Collection;

/** @return Collection<int, BatchStock> */
public function getBatchStockByLocation(
    int $batchId,
    array $locationIds,
): Collection;
```

`locationIds === []` means no visible stock rows.

No implementation may reinterpret it as “all”.

## Task 1 Shared trace DTO

```php
final readonly class BatchTraceOccurrenceData
{
    public function __construct(
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
    ) {}
}
```

## Task 1 Shared trace interfaces

```php
interface DocumentBatchTraceReader
{
    /**
     * @param list<string>|null $locationIds
     * @return list<BatchTraceOccurrenceData>
     */
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
    ): array;

    /**
     * @param list<string>|null $locationIds
     * @return list<BatchTraceOccurrenceData>
     */
    public function backwardForPartner(
        string $tenantId,
        string $companyId,
        string $partnerId,
        ?array $locationIds,
        ?string $productId,
        ?string $dateFrom,
        ?string $dateTo,
    ): array;
}

interface PosBatchTraceReader
{
    /**
     * @param list<string>|null $locationIds
     * @return list<BatchTraceOccurrenceData>
     */
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
    ): array;
}
```

`null` here means an unrestricted company-level reader.

`[]` means no attributable occurrence.

Document and POS adapters own their model queries.

BatchExpiry owns response composition only.

## Task 1 visibility contract

<a id="R4-M4"></a>

**Plan line R4-M4 — zero-stock lots**

Unrestricted active membership:

- May list every company lot.

- May open a newly created company lot with no stock.

- Receives 200 detail with `batch_stock=[]`.

- May edit or deactivate that zero-stock lot subject to action permission.

- May see depleted historical traces.

- May see a company trace whose historical location is NULL.

Restricted active membership:

- May list a lot when it has stock at an allowed location.

- May list a depleted lot when it has attributable trace history at an allowed location.

- May open the same scoped lot.

- Receives only allowed stock rows.

- Does not see a zero-stock/no-history lot.

- Does not see a history row whose location is NULL.

- Receives 404 when neither scoped stock nor attributable scoped history exists.

Empty membership scope:

- Produces no list rows.

- Produces no stock rows.

- Produces no traces.

- Produces 404 for detail.

Company mismatch:

- Always produces 404.

## Task 1 aggregate contract

`BatchResource` must not use the unscoped float accessors at [Batch.php:143](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:143).

Instead:

- Sum the already-loaded scoped rows.

- Use decimal strings.

- Use scale four.

- Do not execute a fresh relation query from the resource.

- Keep physical quantity separate from eligible quantity.

## Task 1 implementation sequence

1. Write the assertion-red route, read-scope, zero-stock, and architecture tests.

2. Capture named assertion failures.

3. Add the activation config and reader.

4. Add `BatchActionAccess`.

5. Preserve the current outer middleware group.

6. Add literal capability route ownership in Task 3 but reserve its ordering now.

7. Apply the final route matrix only in the Push-5 hardening artifact.

8. Validate `expiring.location_id` as UUID.

9. Resolve requested IDs through `LocationScopeResolver`.

10. Do not pass a Treasury bypass permission.

11. Thread resolved IDs into repository reads.

12. Filter eager-loaded stock using the same IDs.

13. Implement unrestricted zero-stock metadata behavior.

14. Implement restricted stock/history existence behavior.

15. Replace float totals with scoped decimal-string totals.

16. Add Shared trace contracts.

17. Implement Document adapter in Document.

18. Implement POS adapter in POS.

19. Bind adapters in their owning service providers.

20. Remove Document/POS imports from BatchTraceabilityController.

21. Compose trace results in BatchExpiry.

22. Treat nullable historical locations according to the visibility contract.

23. Run focused PostgreSQL tests.

24. Run architecture tests.

25. Run both reviewer gates.

## Task 1 assertion-red tests

| Exact file | Class::method | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | `BatchReadLocationScopeTest::test_detail_excludes_other_branch_stock` | `self::assertNotContains($this->locationA2->id, array_column($response->json('data.batch_stock'), 'location_id'));` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_detail_excludes_other_branch_stock')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchGateActivationTest.php` | `BatchGateActivationTest::test_dormant_recall_is_unavailable_before_role_delta` | `$response->assertNotFound();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchGateActivationTest::test_dormant_recall_is_unavailable_before_role_delta')` | phpunit PG |
| `apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php` | `BatchTraceabilityModuleBoundaryTest::test_batch_expiry_traceability_imports_only_shared_contracts` | `self::assertSame([], $violations, implode(PHP_EOL, $violations));` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchTraceabilityModuleBoundaryTest::test_batch_expiry_traceability_imports_only_shared_contracts')` | phpunit sqlite/no DB |

These tests are assertion-red at the baseline:

- Detail currently contains A2 stock.

- Dormant recall currently executes instead of returning 404.

- The architecture scanner currently finds explicit Document/POS imports.

No missing class or missing table is counted as the red.

## Task 1 supplemental verification tests

| Exact file | Class::method | Required assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | `test_every_read_filters_other_branch_and_empty_scope` | `self::assertSame($expectedVisibleIds, $actualVisibleIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_every_read_filters_other_branch_and_empty_scope')` | phpunit PG |
| same | `test_zero_stock_company_lot_is_visible_to_unrestricted_actor` | `$response->assertOk()->assertJsonPath('data.batch_stock', []);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_zero_stock_company_lot_is_visible_to_unrestricted_actor')` | phpunit PG |
| same | `test_zero_stock_company_lot_is_hidden_from_restricted_actor` | `$response->assertNotFound();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_zero_stock_company_lot_is_hidden_from_restricted_actor')` | phpunit PG |
| same | `test_restricted_actor_sees_depleted_lot_with_allowed_history` | `$response->assertOk();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_restricted_actor_sees_depleted_lot_with_allowed_history')` | phpunit PG |
| same | `test_nullable_location_history_is_company_only` | `self::assertSame([], $restrictedTraceRows);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_nullable_location_history_is_company_only')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | `test_each_route_requires_its_action_permission` | `$response->assertForbidden();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchActionPermissionsTest::test_each_route_requires_its_action_permission')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php` | `test_expiring_validates_uuid_and_filters_loaded_stock_to_allowed_locations` | `$invalid->assertUnprocessable();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchExpiringLocationScopeTest::test_expiring_validates_uuid_and_filters_loaded_stock_to_allowed_locations')` | phpunit PG |
| same | `test_empty_membership_scope_returns_no_expiring_lots` | `$response->assertJsonPath('data', []);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchExpiringLocationScopeTest::test_empty_membership_scope_returns_no_expiring_lots')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php` | `test_document_and_pos_adapters_apply_company_and_location_scope` | `self::assertSame([$expectedA1Id], $occurrenceIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchTraceReaderContractTest::test_document_and_pos_adapters_apply_company_and_location_scope')` | phpunit PG |
| same | `test_second_company_and_nullable_location_do_not_leak` | `self::assertNotContains($companyBOccurrenceId, $occurrenceIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchTraceReaderContractTest::test_second_company_and_nullable_location_do_not_leak')` | phpunit PG |

## Task 1 convention-09 mapping

Second company:

- Register company B through the real company-creation path.

- Create the same lot label in company A and company B.

- Assert company A reads never return company B.

Second location:

- Create A1 and a second `pos_enabled` location A2.

- Assert an A1-only actor never receives A2 stock or trace rows.

Rerun:

- Repeat every read.

- Assert byte-equivalent scoped IDs and decimal totals.

- Assert no stock/history/evidence changes.

Catalogue registration:

- Task 3 adds `product_batches` to both catalogue constants.

## Task 1 reviewer gates

**tenancy-authz-reviewer**

Must approve:

- Route matrix.

- Middleware precedence.

- Empty-scope behavior.

- Zero-stock unrestricted behavior.

- Restricted detail existence rule.

- Nullable trace rule.

- Shared-contract module boundary.

**inventory-costing-reviewer**

Must approve:

- Scoped decimal totals.

- No physical quantity mutation.

- No hidden company metadata for unrestricted actors.

- No eligibility/physical quantity conflation.

## Task 1 rollback

Before activation:

- Push-3 unwired files may be reverted if no later task depends on them.

After Push 5:

- Do not restore unscoped reads.

- Do not restore body-less mutation routes without action permissions.

- Do not restore cross-module trace model imports.

- If the new request writer must be disabled, set the authoring flag false.

- Existing location scoping and action enforcement remain active.

---

# Task 2 — Apply the role delta and guard general-manager assignment

## Task 2 dependencies

Task 1 contracts.

## Task 2 production files — modify

- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`

- `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php`

- `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`

- `apps/api/app/Modules/Identity/Presentation/Requests/CreateUserRequest.php`

- `apps/api/app/Modules/Identity/Presentation/Requests/UpdateUserRequest.php`

## Task 2 production files — add

- `apps/api/app/Console/Commands/ApplyLotRecallPermissionDelta.php`

- `apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php`

- `apps/api/app/Modules/Identity/Domain/Enums/RoleProvisioningSource.php`

- `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php`

## Task 2 source facts

Current role creation uses `syncPermissions()` at [RolesAndPermissionsSeeder.php:543](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:543).

Current manager grants company-wide recall at [RolesAndPermissionsSeeder.php:631](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:631).

Current viewer grants omit batch view at [RolesAndPermissionsSeeder.php:704](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:704).

Current operator grants begin at [RolesAndPermissionsSeeder.php:769](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:769).

Current role identity is bigint at [permission migration:35](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:35).

Unrestricted membership is represented by SQL NULL at [LocationContext.php:194](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationContext.php:194).

## Task 2 resulting seeded matrix

| Seeded role | Resulting batch grants |
|---|---|
| admin | All existing batch permissions plus `batches.recall.request`; retains `batches.recall` and Treasury all-location authority |
| general_manager | Revised manager set plus `batches.recall` and `treasury.manage_all_locations` |
| manager | `view`, `create`, `update`, `delete`, `write-off`, `traceability`, `recall.request`; no `recall` |
| cashier | `view` only |
| viewer | `view` only |
| operator | `view` only |
| technician | No new batch permission |
| accountant | No new batch permission |

## Task 2 public signatures

```php
public function run(): void;

/** @return list<string> */
public static function permissionNames(): array;

/** @return array<string, list<string>> */
public static function rolePermissionGrants(): array;
```

```php
final readonly class GeneralManagerAssignmentGuard
{
    /**
     * @param list<string>|null $effectiveAllowedLocationIds
     * @param list<string> $effectiveRoleNames
     */
    public function assertAssignable(
        ActorIdentityScopeData $actor,
        string $targetUserId,
        string $companyId,
        string $roleName,
        ?array $effectiveAllowedLocationIds,
        array $effectiveRoleNames,
    ): void;

    /**
     * @param list<string>|null $effectiveAllowedLocationIds
     * @param list<string> $effectiveRoleNames
     */
    public function assertLocationChangeAllowed(
        ActorIdentityScopeData $actor,
        string $targetUserId,
        string $companyId,
        ?array $effectiveAllowedLocationIds,
        array $effectiveRoleNames,
    ): void;
}
```

Command:

```php
protected $signature =
    'permissions:apply-lot-recall-delta
     {--apply : Apply while runtime authoring is off}
     {--verify : Read-only verification}';

public function handle(): int;
```

## Task 2 role enum

```php
#[TypeScript]
enum RoleProvisioningSource: string
{
    case LotRecallV1 = 'w-lot-a-1';
}
```

## Task 2 role migration schema

Table:

```text
roles
```

New column:

| Column | Type | Null | Default | FK | Index |
|---|---|---:|---|---|---|
| `provisioning_source` | `VARCHAR(32)` | yes | SQL NULL | none | none |

Named check:

```sql
CONSTRAINT roles_provisioning_source_check
CHECK (
    provisioning_source IS NULL
    OR provisioning_source = 'w-lot-a-1'
)
```

No JSONB.

No company-scoped unique.

Roles are tenant authorization identities, not company catalogue rows.

### Role migration `up()` contract

1. Assert `roles` exists.

2. If the column is absent, add it.

3. If present, verify:

   - type is character varying;

   - maximum length is 32;

   - nullable is true;

   - default is NULL.

4. On PostgreSQL, inspect the named check.

5. Create it only if absent.

6. If present with a different normalized definition, fail.

7. On SQLite, install equivalent insert/update rejection triggers.

8. Re-running the migration is a no-op only when definitions match exactly.

### Role migration `down()` contract

1. Refuse if any row has non-NULL `provisioning_source`.

2. Drop only the SQLite triggers or named PostgreSQL check created here.

3. Drop only `provisioning_source`.

4. Never drop roles or permissions.

## Task 2 command outcomes

Stable marker:

```text
WLOTA1-PERMISSIONS tenant=<uuid> outcome=APPLIED|ALREADY_APPLIED|SKIPPED|FAILED reason=<token>
```

Exit codes:

| Exit | Meaning |
|---:|---|
| 0 | APPLIED, ALREADY_APPLIED, or intentional SKIPPED |
| 1 | collision, missing schema, or invariant failure |
| 2 | invalid option combination |

Rules:

- `--apply --verify` is invalid.

- `--verify` never writes.

- `--verify` succeeds only as `ALREADY_APPLIED`.

- Flag OFF without `--apply` reports SKIPPED.

- Existing unmarked `general_manager` reports `FAILED reason=ROLE_NAME_COLLISION`.

- No adoption option.

- No force option.

- A marked role is reused.

- Rerun preserves role ID.

- Rerun preserves unrelated custom grants.

- Rerun creates no duplicate pivots.

## Task 2 transactional assignment order

Create:

1. Create the user inside the existing transaction.

2. Create/write the active membership.

3. Lock the target user.

4. Lock all applicable active memberships in company-ID order.

5. Compute the final role set.

6. Compute effective location scope.

7. Validate actor grant authority.

8. Validate general-manager unrestricted scope.

9. Set the permission team.

10. Assign the role.

11. Write audit records.

12. Commit.

13. Send invitation after commit.

Update:

1. Lock target user.

2. Lock applicable active memberships in company-ID order.

3. Merge omitted fields with persisted state.

4. Compute final roles.

5. Compute final location scope.

6. Validate the merged state.

7. Write membership.

8. Synchronize final role set in the same transaction.

9. Commit.

Dedicated role endpoint:

- Uses the same target-user and membership lock order.

- Does not manufacture an absent membership.

- Runs the same guard before assignment.

- Preserves existing self-escalation protections.

Atomic demotion plus narrowing is allowed only when the final role set no longer contains `general_manager`.

## Task 2 implementation sequence

1. Write static seeded-matrix assertion-red tests.

2. Write assignment-flow tests.

3. Add the marker migration.

4. Add the enum.

5. Extract targeted permission-delta logic.

6. Replace blanket existing-role sync behavior for this delta.

7. Add `batches.recall.request`.

8. Remove `batches.recall` from manager.

9. Add viewer/operator view.

10. Provision marked general manager.

11. Preserve custom grants.

12. Reject unmarked name collisions.

13. Add command markers and exit codes.

14. Add assignment guard.

15. Reorder user creation.

16. Reorder user update.

17. Guard dedicated role assignment.

18. Guard later location narrowing.

19. Test concurrent first application.

20. Test reseed rerun.

21. Run reviewer gates.

## Task 2 assertion-red tests

| Exact file | Class::method | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/Identity/LotRecallSeededRoleMatrixTest.php` | `test_canonical_viewer_operator_lot_grants` | `self::assertContains('batches.view', $grants['viewer']);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallSeededRoleMatrixTest::test_canonical_viewer_operator_lot_grants')` | phpunit PG |
| `apps/api/tests/Feature/Identity/LotRecallRoleDeltaTest.php` | `test_canonical_manager_no_longer_has_company_recall` | `self::assertNotContains('batches.recall', $grants['manager']);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest::test_canonical_manager_no_longer_has_company_recall')` | phpunit PG |

Both fail named assertions against current `rolePermissionGrants()`.

Neither depends on the new migration or command class.

## Task 2 supplemental verification tests

| Exact file | Class::method | Required assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/Identity/LotRecallRoleDeltaTest.php` | `test_manager_loses_recall_and_gains_request` | `self::assertFalse($manager->hasPermissionTo('batches.recall'));` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest::test_manager_loses_recall_and_gains_request')` | phpunit PG |
| same | `test_general_manager_has_exact_new_seeded_grants` | `self::assertSame($expected, $actual);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest::test_general_manager_has_exact_new_seeded_grants')` | phpunit PG |
| same | `test_delta_and_reseed_preserve_custom_permissions_on_rerun` | `self::assertSame('ALREADY_APPLIED', $secondOutcome);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest::test_delta_and_reseed_preserve_custom_permissions_on_rerun')` | phpunit PG |
| same | `test_existing_general_manager_name_collision_is_reported_without_escalation` | `self::assertSame(1, $exitCode);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest::test_existing_general_manager_name_collision_is_reported_without_escalation')` | phpunit PG |
| same | `test_concurrent_initial_delta_marks_exactly_one_role` | `self::assertSame(1, $markedRoleCount);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotRecallRoleDeltaTest::test_concurrent_initial_delta_marks_exactly_one_role')` | phpunit PG |
| `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php` | `test_create_update_and_role_endpoint_reject_restricted_assignment` | `$response->assertUnprocessable();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerAssignmentTest::test_create_update_and_role_endpoint_reject_restricted_assignment')` | phpunit PG |
| same | `test_new_unrestricted_general_manager_is_created_after_membership` | `$response->assertCreated();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerAssignmentTest::test_new_unrestricted_general_manager_is_created_after_membership')` | phpunit PG |
| same | `test_unrestricted_assignment_cannot_later_be_narrowed` | `$narrow->assertUnprocessable();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerAssignmentTest::test_unrestricted_assignment_cannot_later_be_narrowed')` | phpunit PG |
| same | `test_second_company_restriction_prevents_tenant_role_assignment` | `$response->assertUnprocessable();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerAssignmentTest::test_second_company_restriction_prevents_tenant_role_assignment')` | phpunit PG |
| same | `test_assignment_rerun_preserves_single_role_pivot` | `self::assertSame(1, $pivotCount);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerAssignmentTest::test_assignment_rerun_preserves_single_role_pivot')` | phpunit PG |
| `apps/api/tests/Feature/Identity/RoleProvisioningSourceSchemaTest.php` | `test_role_marker_schema_is_exact_and_rerunnable` | `self::assertSame('character varying', $dataType);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'RoleProvisioningSourceSchemaTest::test_role_marker_schema_is_exact_and_rerunnable')` | phpunit PG |

## Task 2 convention-09 mapping

Role provisioning is tenant authorization state, not a company catalogue entity.

Assignment behavior is location/company-sensitive and therefore carries:

Second company:

- Company A unrestricted.

- Company B restricted.

- General-manager assignment fails tenant-wide.

Second location:

- `[]`, `[A1]`, and `[A1,A2]` are all restricted.

- SQL NULL alone is unrestricted.

Rerun:

- Delta reports `ALREADY_APPLIED`.

- Reseed reports `ALREADY_APPLIED`.

- Role ID and marker remain unchanged.

- Custom grants remain unchanged.

## Task 2 reviewer gates

**tenancy-authz-reviewer**

Must approve:

- All three assignment paths.

- Effective-state validation.

- Target-user lock.

- Membership lock order.

- Cross-company scope.

- Collision behavior.

- Custom-role preservation.

**inventory-costing-reviewer**

Must approve:

- Manager cannot recall company-wide.

- General manager can recall only when unrestricted.

- No stock or accounting data is touched.

## Task 2 rollback

- Do not blanket-sync role grants.

- Preserve the stricter manager split.

- Restore unrelated grants only from recorded before-state.

- Do not automatically restore company-wide recall to manager.

- Do not clear `provisioning_source` after a marked role has been used.

- If assignment UI must roll back, keep backend guard active.

---

# Task 3 — Persist immutable request, transition, and global-recall evidence

## Task 3 dependencies

Tasks 1 and 2.

The request writer remains unactivated until Tasks 4 and 5 are deployed and verified.

## Task 3 production files — add

- `apps/api/database/migrations/tenant/2026_09_06_210000_create_batch_recall_requests_table.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchRecallEvidenceKind.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchRecallRequestStatus.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchRecallRequest.php`

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/CreateBatchRecallRequestData.php`

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/RecallBatchData.php`

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchRecallTransitionData.php`

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchGlobalRecallEvidenceData.php`

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchRecallRequestData.php`

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchRecallHistoryData.php`

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchRecallCapabilitiesData.php`

- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallService.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRecallRequest.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/RecallBatchRequest.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchRecallRequestController.php`

## Task 3 production files — modify

- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`

- `apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php`

- `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php`

- `packages/shared/types/generated.d.ts`

## Task 3 source facts

`product_batches.id` is bigint and its UUID is separately unique at [product-batch migration:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:14).

The company FK exists at [product-batch migration:19](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:19).

The batch-stock tuple is unique by batch/location at [inventory-batch-stock migration:36](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_05_150001_create_inventory_batch_stock_table.php:36).

Locations carry company ID at [location migration:25](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_105000_create_locations_table.php:25).

The current catalogue ratchet list begins at [TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:30](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:30).

Its pinned set is at [line 200](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:200).

## Task 3 evidence schema

Table:

```text
batch_recall_requests
```

Despite the historical table name, it stores three immutable evidence kinds:

```text
request_root
request_transition
global_recall
```

### Columns

| Column | Type | Nullable | Default | FK | On delete |
|---|---|---:|---|---|---|
| `id` | UUID | no | none; application generated | primary key | n/a |
| `tenant_id` | UUID | no | none | no cross-database FK | n/a |
| `company_id` | UUID | no | none | `companies.id` | RESTRICT |
| `batch_id` | BIGINT | no | none | `product_batches.id` | RESTRICT |
| `location_id` | UUID | yes | NULL | `locations.id` | RESTRICT |
| `request_id` | UUID | yes | NULL | `batch_recall_requests.id` | RESTRICT |
| `operation_uuid` | UUID | no | none | none | n/a |
| `kind` | VARCHAR(24) | no | none | enum-backed | n/a |
| `status` | VARCHAR(16) | yes | NULL | enum-backed | n/a |
| `reason` | VARCHAR(255) | no | none | none | n/a |
| `actor_user_id` | UUID | no | none | `users.id` | RESTRICT |
| `created_at` | TIMESTAMPTZ | no | database current timestamp | none | n/a |

No:

- `updated_at`.

- Soft delete.

- Quantity.

- Monetary value.

- JSONB.

- Mutable payload.

No JSONB DTO is required because there is no JSONB column.

<a id="R4-M3"></a>

**Plan line R4-M3 — relational correlation**

Independent FKs are necessary but insufficient.

The insert trigger validates the complete tuple.

### Evidence kind enum

```php
#[TypeScript]
enum BatchRecallEvidenceKind: string
{
    case RequestRoot = 'request_root';
    case RequestTransition = 'request_transition';
    case GlobalRecall = 'global_recall';
}
```

### Request status enum

```php
#[TypeScript]
enum BatchRecallRequestStatus: string
{
    case Requested = 'requested';
    case Recalled = 'recalled';
}
```

`released` is not added here.

W-LOT-A-1b extends the enum and check additively.

### Kind/status shape checks

Request root:

```text
kind = request_root
status = requested
request_id IS NULL
location_id IS NOT NULL
```

Request transition:

```text
kind = request_transition
status = recalled
request_id IS NOT NULL
location_id IS NOT NULL
```

Global recall:

```text
kind = global_recall
status IS NULL
request_id IS NULL
location_id IS NULL
```

Additional checks:

```sql
length(trim(reason)) > 0
```

```sql
request_id IS NULL OR request_id <> id
```

No other kind/status combination is accepted.

## Task 3 indexes and unique contracts

Primary key:

```sql
PRIMARY KEY (id)
```

Root operation namespace:

```sql
CREATE UNIQUE INDEX brq_root_operation_uq
ON batch_recall_requests (company_id, operation_uuid)
WHERE kind = 'request_root';
```

Transition operation namespace:

```sql
CREATE UNIQUE INDEX brq_transition_operation_uq
ON batch_recall_requests (company_id, operation_uuid)
WHERE kind = 'request_transition';
```

Global-recall operation namespace:

```sql
CREATE UNIQUE INDEX brq_global_operation_uq
ON batch_recall_requests (company_id, operation_uuid)
WHERE kind = 'global_recall';
```

Single terminal child:

```sql
CREATE UNIQUE INDEX brq_company_terminal_uq
ON batch_recall_requests (company_id, request_id)
WHERE kind = 'request_transition';
```

One direct global evidence per batch:

```sql
CREATE UNIQUE INDEX brq_global_batch_uq
ON batch_recall_requests (company_id, batch_id)
WHERE kind = 'global_recall';
```

Lookup indexes:

```sql
CREATE INDEX brq_scope_status_idx
ON batch_recall_requests
(company_id, batch_id, location_id, status);
```

```sql
CREATE INDEX brq_company_created_idx
ON batch_recall_requests
(company_id, created_at, id);
```

```sql
CREATE INDEX brq_actor_idx
ON batch_recall_requests (actor_user_id);
```

```sql
CREATE INDEX brq_open_scope_idx
ON batch_recall_requests
(company_id, batch_id, location_id, request_id)
WHERE kind = 'request_root';
```

There is no single `company_id,operation_uuid` unique spanning all kinds.

## Task 3 insert-trigger contract

PostgreSQL function:

```text
batch_recall_evidence_validate_insert()
```

Trigger:

```text
brq_validate_insert
```

For every row, the trigger:

1. Loads `product_batches` by `batch_id`.

2. Locks the batch when the service has not already done so.

3. Rejects a missing batch.

4. Requires `NEW.tenant_id = batch.tenant_id`.

5. Requires `NEW.company_id = batch.company_id`.

6. Loads the company.

7. Requires company tenant to equal `NEW.tenant_id`.

8. Loads actor user.

9. Requires actor tenant to equal `NEW.tenant_id`.

For a request root:

10. Loads the location.

11. Requires location company to equal `NEW.company_id`.

12. Loads `inventory_batch_stock` by batch/location.

13. Requires the tuple to exist.

14. Requires tuple tenant to equal `NEW.tenant_id`.

15. Rejects if another unresolved root exists for the same company/batch/location.

For a transition:

16. Loads and locks the parent request root.

17. Requires parent kind `request_root`.

18. Requires parent status `requested`.

19. Requires identical tenant.

20. Requires identical company.

21. Requires identical batch.

22. Requires identical location.

23. Rejects if a terminal child already exists.

For global recall:

24. Requires NULL location.

25. Requires NULL request parent.

26. Requires no existing global evidence for the batch except namespace replay handled by the service.

The partial unique indexes remain concurrency backstops.

The trigger produces stable constraint/error tokens for typed service mapping.

## Task 3 append-only triggers

PostgreSQL functions/triggers reject:

- UPDATE of any evidence row.

- DELETE of any evidence row.

- Parent rewrites.

- Reason rewrites.

- Actor rewrites.

- Timestamp rewrites.

- Operation UUID rewrites.

Equivalent SQLite triggers exist for portability tests.

PostgreSQL remains authoritative.

## Task 3 migration self-guard

`up()`:

1. Assert prerequisite tables exist.

2. Create the table if absent.

3. If present, verify every column.

4. Verify type.

5. Verify nullability.

6. Verify default.

7. Verify FK target and delete rule.

8. Verify every named check.

9. Verify every named index predicate and column order.

10. Verify every trigger.

11. Fail on incompatible drift.

12. Do not treat `Schema::hasTable()` alone as success.

`down()`:

1. Refuse when any evidence row exists.

2. Drop only migration-owned triggers.

3. Drop only migration-owned functions.

4. Drop only this table.

5. Never clear `product_batches.is_recalled`.

## Task 3 deterministic transition UUID

Namespace:

```text
4f1708cb-1f52-4e19-92e1-f25df3536e0f
```

Algorithm:

- RFC UUID version 5.

- SHA-1 as specified by UUIDv5.

- Lowercase company UUID.

- Lowercase root UUID.

- Compact UTF-8 JSON.

- Fixed sequence integer `1`.

Name:

```json
["11111111-1111-4111-8111-111111111111","22222222-2222-4222-8222-222222222222","recalled",1]
```

Expected UUID:

```text
463d9ce3-793e-53c0-bdc5-10e2fd6e90c9
```

## Task 3 DTO declarations

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
```

```php
#[TypeScript]
final class RecallBatchData extends Data
{
    public function __construct(
        public string $operation_uuid,
        public string $reason,
    ) {}
}
```

```php
#[TypeScript]
final class BatchRecallTransitionData extends Data
{
    public function __construct(
        public string $id,
        public string $operation_uuid,
        public BatchRecallRequestStatus $status,
        public string $reason,
        public string $actor_user_id,
        public string $created_at,
    ) {}
}
```

```php
#[TypeScript]
final class BatchGlobalRecallEvidenceData extends Data
{
    public function __construct(
        public string $id,
        public string $operation_uuid,
        public string $batch_uuid,
        public string $reason,
        public string $actor_user_id,
        public string $created_at,
        public bool $replayed,
    ) {}
}
```

```php
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

```php
#[TypeScript]
final class BatchRecallHistoryData extends Data
{
    /**
     * @param list<BatchRecallRequestData> $requests
     */
    public function __construct(
        public array $requests,
        public ?BatchGlobalRecallEvidenceData $global_recall,
    ) {}
}
```

<a id="R4-M2"></a>

**Plan line R4-M2 — capability DTO**

```php
#[TypeScript]
final class BatchRecallCapabilitiesData extends Data
{
    public function __construct(
        public bool $enabled,
    ) {}
}
```

## Task 3 public service signatures

```php
public function request(
    ActorIdentityScopeData $actor,
    string $batchUuid,
    CreateBatchRecallRequestData $data,
): BatchRecallRequestData;
```

```php
public function history(
    ActorIdentityScopeData $actor,
    string $batchUuid,
): BatchRecallHistoryData;
```

Task 5 adds:

```php
public function recall(
    ActorIdentityScopeData $actor,
    string $batchUuid,
    RecallBatchData $data,
): Batch;
```

## Task 3 controller signatures

```php
public function store(
    CreateBatchRecallRequest $request,
    string $uuid,
): JsonResponse;
```

```php
public function index(
    Request $request,
    string $uuid,
): JsonResponse;
```

```php
public function capabilities(
    Request $request,
): JsonResponse;
```

## Task 3 FormRequest signatures

```php
public function authorize(): bool;

public function rules(): array;
```

Request rules:

```php
[
    'operation_uuid' => ['required', 'string', 'uuid'],
    'location_id' => ['required', 'string', 'uuid'],
    'reason' => ['required', 'string', 'max:255'],
]
```

Trimmed reason must remain non-empty after normalization.

## Task 3 routes

Literal capability route must precede `{uuid}`:

```text
GET /api/v1/batches/recall-capabilities
```

Contract:

- Existing auth/team/tenant/module middleware.

- `batches.view`.

- 200 when flag OFF.

- 200 when flag ON.

- Exact OFF JSON:

```json
{"data":{"enabled":false}}
```

- Exact ON JSON:

```json
{"data":{"enabled":true}}
```

- 403 for authenticated actor without `batches.view`.

- No request-table query required.

History route:

```text
GET /api/v1/batches/{uuid}/recall-requests
```

Contract:

- `batches.view`.

- Available under flag OFF and ON after Push 5.

- Location-scoped request history.

- Unrestricted actor may see all company roots.

- Restricted actor sees roots only for allowed locations.

- Global recall evidence is company-level and visible to any actor who can view that batch metadata.

Writer route:

```text
POST /api/v1/batches/{uuid}/recall-requests
```

Contract:

- Flag OFF: 404.

- Flag ON: `batches.recall.request`.

- Inaccessible location: 404.

- Fresh: 201.

- Matching replay: 200.

- Payload conflict: 409.

- Open request conflict: 409 `OPEN_REQUEST_EXISTS`.

## Task 3 request behavior

1. Validate actor.

2. Validate UUIDs.

3. Normalize reason.

4. Resolve company batch.

5. Follow R4-B1 lock order.

6. Re-read batch.

7. Run root-only replay lookup.

8. Require replay actor, batch, location, and reason to match.

9. Revalidate current permission/scope on replay.

10. Lock the exact stock tuple.

11. Require physical quantity greater than zero.

12. Fully reserved positive physical stock remains requestable.

13. Reject globally recalled batch.

14. Reject another unresolved root at the tuple.

15. Insert one root.

16. Commit is hold activation.

17. Do not alter physical quantity.

18. Do not alter reserved quantity.

19. Do not write movement.

20. Do not write GL.

## Task 3 namespace adversarial scenario

1. Create A1 root R1.

2. Compute R1 future transition UUID T1.

3. Create independent A2 root using T1 as its client operation UUID.

4. Recall the company batch.

5. R1 transition uses T1.

6. A2 root with operation T1 remains intact.

7. A2 receives its own deterministic transition.

Assertions:

```php
self::assertSame(1, $rootNamespaceCount);
self::assertSame(1, $transitionNamespaceCount);
self::assertSame(2, $terminalChildCount);
```

Add the same adversarial collision with `global_recall` operation UUID.

It must coexist with root and transition rows using that UUID.

## Task 3 catalogue registration

Add `product_batches` to:

```php
TenantOnlyUniqueOnCatalogueTablesRatchetTest::CATALOGUE_TABLES
```

Add `product_batches` to:

```php
PINNED_CATALOGUE_TABLES
```

Add `batch_recall_requests` to `EXCLUDED_TABLES` with:

```text
Immutable recall workflow evidence; root, transition, global-recall,
and terminal identities are company-qualified and are not operator
catalogue identities.
```

Do not:

- Add a waiver.

- Raise legacy ceiling.

- Raise waiver ceiling.

- Put the evidence table in both sets.

- Add a baseline entry for its company-qualified uniques.

## Task 3 generated declarations

Run:

```bash
(cd apps/api && php artisan typescript:transform)
```

Commit:

```text
packages/shared/types/generated.d.ts
```

Do not hand-edit it.

Frontend aliases:

```ts
export type CreateBatchRecallRequestData =
  App.Modules.BatchExpiry.Application.DTOs.CreateBatchRecallRequestData

export type RecallBatchData =
  App.Modules.BatchExpiry.Application.DTOs.RecallBatchData

export type BatchRecallRequestData =
  App.Modules.BatchExpiry.Application.DTOs.BatchRecallRequestData

export type BatchRecallHistoryData =
  App.Modules.BatchExpiry.Application.DTOs.BatchRecallHistoryData

export type BatchGlobalRecallEvidenceData =
  App.Modules.BatchExpiry.Application.DTOs.BatchGlobalRecallEvidenceData

export type BatchRecallCapabilitiesData =
  App.Modules.BatchExpiry.Application.DTOs.BatchRecallCapabilitiesData
```

## Task 3 implementation sequence

1. Write schema-presence assertion-red test.

2. Write capability assertion-red test.

3. Capture named assertion failures.

4. Add enum files.

5. Add migration skeleton.

6. Add exact columns.

7. Add FKs.

8. Add checks.

9. Add three idempotency namespaces.

10. Add terminal/global uniques.

11. Add insert-correlation trigger.

12. Add append-only triggers.

13. Add SQLite equivalents.

14. Add migration self-guards.

15. Add entity.

16. Add DTOs.

17. Add actor contract.

18. Add request service.

19. Add controller/FormRequest.

20. Add literal capability route.

21. Keep POST authoring dormant.

22. Add history route for Push 5.

23. Add catalogue classifications.

24. Run type generation twice.

25. Assert byte-identical generated output.

26. Run PostgreSQL adversarial namespace tests.

27. Run both reviewer gates.

## Task 3 assertion-red tests

| Exact file | Class::method | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchRecallSchemaPresenceTest.php` | `test_real_tenant_migrations_install_recall_request_schema` | `self::assertTrue(Schema::hasTable('batch_recall_requests'));` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallSchemaPresenceTest::test_real_tenant_migrations_install_recall_request_schema')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallCapabilitiesTest.php` | `test_dormant_capabilities_return_false` | `$response->assertOk();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallCapabilitiesTest::test_dormant_capabilities_return_false')` | phpunit PG |

The schema test calls `Schema::hasTable()` and fails the boolean assertion.

It does not attempt an insert into a missing table.

The capability test reaches the existing router and fails `assertOk()` because the literal route is absent.

It does not fail from a missing DTO import.

## Task 3 supplemental verification tests

| Exact file | Class::method | Required assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestSchemaTest.php` | `test_request_evidence_rejects_update_delete_and_cross_company_links` | `self::assertTrue($databaseRejectedMutation);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestSchemaTest::test_request_evidence_rejects_update_delete_and_cross_company_links')` | phpunit PG |
| same | `test_insert_trigger_rejects_mismatched_batch_location_actor_and_stock_tuple` | `self::assertSame($expectedTokens, $actualTokens);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestSchemaTest::test_insert_trigger_rejects_mismatched_batch_location_actor_and_stock_tuple')` | phpunit PG |
| same | `test_only_supported_kind_status_shapes_are_valid` | `self::assertTrue($databaseRejectedInvalidShape);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestSchemaTest::test_only_supported_kind_status_shapes_are_valid')` | phpunit PG |
| same | `test_a_request_has_only_one_terminal_child` | `self::assertTrue($databaseRejectedSecondTerminal);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestSchemaTest::test_a_request_has_only_one_terminal_child')` | phpunit PG |
| same | `test_root_operation_cannot_burn_deterministic_transition_namespace` | `self::assertSame(2, $terminalChildCount);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestSchemaTest::test_root_operation_cannot_burn_deterministic_transition_namespace')` | phpunit PG |
| same | `test_global_operation_namespace_is_independent` | `self::assertSame(3, $sameUuidAcrossKindCount);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestSchemaTest::test_global_operation_namespace_is_independent')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestTest.php` | `test_manager_requests_recall_at_allowed_location` | `$response->assertCreated();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestTest::test_manager_requests_recall_at_allowed_location')` | phpunit PG |
| same | `test_non_allowed_location_is_scoped_not_found` | `$response->assertNotFound();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestTest::test_non_allowed_location_is_scoped_not_found')` | phpunit PG |
| same | `test_retry_returns_original_request_without_duplicate_evidence` | `$retry->assertJsonPath('data.replayed', true);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestTest::test_retry_returns_original_request_without_duplicate_evidence')` | phpunit PG |
| same | `test_operation_uuid_payload_conflict_returns_409` | `$response->assertConflict();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestTest::test_operation_uuid_payload_conflict_returns_409')` | phpunit PG |
| same | `test_second_company_reuses_operation_uuid_independently` | `$companyB->assertCreated();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallRequestTest::test_second_company_reuses_operation_uuid_independently')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallCapabilitiesTest.php` | `test_on_capabilities_enforce_view_and_company_module_admission` | `$response->assertJsonPath('data.enabled', true);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallCapabilitiesTest::test_on_capabilities_enforce_view_and_company_module_admission')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallGeneratedTypesTest.php` | `test_transform_exports_all_recall_contracts` | `self::assertStringContainsString('BatchRecallHistoryData', $generated);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallGeneratedTypesTest::test_transform_exports_all_recall_contracts')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallRequestSqliteSchemaTest.php` | `test_append_only_namespace_and_role_marker_guards` | `self::assertTrue($databaseRejectedMutation);` | `(cd apps/api && DB_CONNECTION=sqlite DB_DATABASE=:memory: ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchRecallRequestSqliteSchemaTest::test_append_only_namespace_and_role_marker_guards')` | phpunit sqlite |
| `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php` | entire class | `self::assertSame([], $report->violations());` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TenantOnlyUniqueOnCatalogueTablesRatchetTest')` | phpunit PG |

## Task 3 convention-09 mapping

Second company:

- Same root operation UUID in A and B.

- Both roots are independent.

- Neither history response leaks.

Second location:

- A1 request succeeds.

- A2 inaccessible request returns 404.

- A1 and A2 may each have one independent unresolved root when authorized.

Rerun:

- Matching root replay returns original ID with `replayed=true`.

- Mismatch returns 409.

- Migration rerun preserves exact schema.

Catalogue:

- `product_batches` is registered.

- Evidence is explicitly excluded for a reviewed reason.

## Task 3 reviewer gates

**tenancy-authz-reviewer**

Must approve:

- Actor boundary.

- FKs.

- Trigger correlations.

- Namespace predicates.

- Scoped replay.

- Capability permission behavior.

**inventory-costing-reviewer**

Must approve:

- Append-only evidence.

- No quantity/value fields.

- No stock mutation.

- Status-independent terminal invariant.

- Direct global evidence shape.

## Task 3 rollback

Before evidence exists:

- A faulty migration may be corrected or rolled down after confirming zero rows.

After evidence exists:

- Do not drop the table.

- Do not delete evidence.

- Do not weaken append-only triggers.

- Keep issue enforcement active.

- Flag OFF disables new roots only.

- Correct schema forward.

---

# Task 4 — Introduce canonical issue eligibility and route every issue consumer through it

## Task 4 dependencies

Task 3 schema and evidence contracts.

## Task 4 production files — add

- `apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchIssueEligibilityReason.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchStockIssueIntent.php`

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchIssueEligibilityQueryData.php`

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchIssueEligibilityDecisionData.php`

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchIssueEligibilityDecisionSetData.php`

- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchIssueEligibilityService.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Exceptions/BatchHeldException.php`

- `apps/api/app/Shared/Contracts/Product/ProductCompanyLookup.php`

- `apps/api/app/Modules/Product/Infrastructure/ProductCompanyLookupAdapter.php`

## Task 4 production files — modify

- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`

- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php`

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`

- `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`

- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php`

- `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php`

- `apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php`

- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`

- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php`

- `apps/api/app/Modules/BatchExpiry/Application/Services/GroupedWriteOffService.php`

- `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php`

- `apps/api/app/Modules/POS/Application/Services/ReturnScrapWriteOffService.php`

- The Product module service provider that binds `ProductCompanyLookup`.

## Task 4 source facts

Current atomic FEFO signature omits company ID at [FEFOInventoryService.php:234](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:234).

Current atomic selection locks batch stock at [FEFOInventoryService.php:260](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:260).

Current direct issue locks only batch stock and checks quantity at [BatchStockService.php:457](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:457).

Current direct transfer locks batch stock first at [BatchStockService.php:499](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:499).

Current StockTransfer FEFO queries Batch directly at [StockTransferService.php:882](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:882).

Current explicit StockTransfer validation calls `canBeSold()` at [StockTransferService.php:993](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:993).

Current delivery explicit-lot path calls `issueBatchStock()` at [DeliveryNoteService.php:380](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:380).

Current automatic delivery path calls FEFO at [DeliveryNoteService.php:388](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:388).

<a id="R4-M1"></a>

**Plan line R4-M1:** both delivery arms must reach the same post-lock decision.

## Task 4 eligibility reason enum

```php
#[TypeScript]
enum BatchIssueEligibilityReason: string
{
    case Inactive = 'inactive';
    case GloballyRecalled = 'globally_recalled';
    case Expired = 'expired';
    case Reserved = 'reserved';
    case LocallyHeld = 'locally_held';
}
```

Reason order is exactly the declaration order.

No caller may reorder it.

## Task 4 issue-intent enum

```php
enum BatchStockIssueIntent: string
{
    case PosProjection = 'pos_projection';
    case CustomerDelivery = 'customer_delivery';
    case DirectTransfer = 'direct_transfer';
    case StockTransfer = 'stock_transfer';
    case WriteOffDisposition = 'write_off_disposition';
    case CountCorrection = 'count_correction';
}
```

Eligibility-required intents:

- PosProjection.

- CustomerDelivery.

- DirectTransfer.

- StockTransfer.

Disposition bypass intents:

- WriteOffDisposition.

- CountCorrection.

No default intent is allowed.

Every caller must choose explicitly.

## Task 4 query DTO

```php
final readonly class BatchIssueEligibilityQueryData
{
    /**
     * @param list<int>|null $batchIds
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
```

`batchIds=null` means all candidates in the correlated tuple.

## Task 4 decision DTO

```php
final readonly class BatchIssueEligibilityDecisionData
{
    /**
     * @param list<BatchIssueEligibilityReason> $reasons
     */
    public function __construct(
        public int $batchId,
        public bool $eligible,
        public array $reasons,
        public string $physicalQuantity,
        public string $reservedQuantity,
        public string $eligibleQuantity,
    ) {}
}
```

## Task 4 decision-set DTO

```php
final readonly class BatchIssueEligibilityDecisionSetData
{
    /**
     * @param list<BatchIssueEligibilityDecisionData> $decisions
     */
    public function __construct(
        public string $sourceRevision,
        public CarbonImmutable $sourceCapturedAt,
        public int $snapshotAgeSeconds,
        public array $decisions,
    ) {}
}
```

## Task 4 service signatures

```php
final readonly class BatchIssueEligibilityService
{
    public function decide(
        BatchIssueEligibilityQueryData $query,
    ): BatchIssueEligibilityDecisionSetData;

    /**
     * @param list<BatchIssueEligibilityQueryData> $queries
     * @return list<BatchIssueEligibilityDecisionSetData>
     */
    public function decideMany(
        array $queries,
    ): array;

    public function assertCanIssue(
        BatchIssueEligibilityQueryData $query,
        int $batchId,
    ): BatchIssueEligibilityDecisionData;
}
```

## Task 4 decision rules

- Validate tenant/company/product/variant/location as one tuple.

- Use supplied `asOf`.

- Do not call per-row implicit `now()`.

- Include every applicable reason.

- Return reasons in fixed enum order.

- `LocallyHeld` means a request root exists at the tuple with no terminal child of any status.

- `GloballyRecalled` comes from the locked batch projection.

- `Reserved` means physical stock exists but reservation leaves no issuable quantity.

- Aggregate shortage remains the caller’s existing shortage outcome.

- Blocked rows remain in output.

- Blocked `eligibleQuantity` is `"0.0000"`.

- Quantities are scale-four strings.

- `eligible=true` only when reason list is empty and eligible quantity is positive.

- A caller cannot independently reimplement active/recall/expiry/reservation/hold logic.

## Task 4 source revision

`sourceRevision` is a lowercase SHA-256 over canonical compact JSON containing:

- Tenant ID.

- Company ID.

- Product ID.

- Variant ID.

- Location ID.

- Requested quantity.

- UTC effective date from `asOf`.

- Sorted batch facts.

For each candidate:

- Batch ID.

- Active state.

- Global recall state.

- Expiry date.

- Physical quantity.

- Reserved quantity.

- Relevant row timestamps.

- Unresolved root IDs/timestamps.

- Terminal IDs/timestamps.

- Global recall evidence ID/timestamp.

`sourceCapturedAt` comes from one database-clock read.

`snapshotAgeSeconds` is:

```text
max(0, asOf - sourceCapturedAt)
```

W-LOT-B increments its own monotonic snapshot revision only when `sourceRevision` changes.

## Task 4 company-aware FEFO signature

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

Compatibility shim:

```php
/**
 * @deprecated Use consumeBatchesAtomically() with explicit companyId.
 */
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

Shared lookup:

```php
interface ProductCompanyLookup
{
    public function companyIdForTenantProduct(
        string $tenantId,
        string $productId,
    ): string;
}
```

The shim:

- Resolves exactly one company.

- Rejects absent product.

- Rejects tenant mismatch.

- Never derives company from location.

- Never uses ambient HTTP state.

- Delegates to the explicit signature.

## Task 4 atomic SQL tuple

Candidate SQL includes:

```sql
WHERE b.tenant_id = ?
  AND b.company_id = ?
  AND ibs.tenant_id = ?
  AND b.product_id = ?
  AND <variant predicate>
  AND ibs.location_id = ?
```

Final locking follows R4-B1.

Candidate discovery may use `SKIP LOCKED`.

Mutation authority comes only from the post-lock decision.

## Task 4 BatchStock signatures

```php
public function issueBatchStock(
    string $tenantId,
    string $companyId,
    int $batchId,
    string $locationId,
    string $quantity,
    ?string $movementId,
    BatchStockIssueIntent $intent,
): void;
```

```php
public function transferBatchStock(
    string $tenantId,
    string $companyId,
    int $batchId,
    string $fromLocationId,
    string $toLocationId,
    string $quantity,
    string $reference,
    string $userId,
): void;
```

Both lock batch before batch stock.

## Task 4 explicit disposition census

These callers intentionally bypass sale eligibility while retaining quantity and authorization checks:

| Caller | Intent | Reason |
|---|---|---|
| `BatchWriteOffService` | `WriteOffDisposition` | Removes unsafe/expired stock |
| `GroupedWriteOffService` | `WriteOffDisposition` | Multi-lot disposition |
| `ReturnScrapWriteOffService` | `WriteOffDisposition` | Scraps returned stock |
| `StockAdjustmentService` shortage/correction arms | `CountCorrection` | Reconciles counted physical stock |

These callers:

- Still validate company/location/batch tuple.

- Still follow batch-before-stock locks.

- Still require their existing permissions.

- Do not become eligible sales.

- Do not call the eligibility assertion.

No other caller may use a bypass intent.

An architecture test pins this allowlist.

## Task 4 delivery-note routing

Explicit `document_lines.batch_id` path:

1. Lock delivery/document state as today.

2. Lock required aggregate stock row.

3. Lock named batch.

4. Lock named source batch-stock tuple.

5. Call canonical `assertCanIssue()`.

6. Refuse held/recalled/expired/inactive lot.

7. Issue only after the decision passes.

Automatic path:

1. Pass `deliveryNote.company_id` to atomic FEFO.

2. Use the same decision service.

3. Choose the next eligible lot when the earliest lot is held.

Delivery confirmation remains atomic.

A held exception rolls the entire confirmation transaction back.

## Task 4 held exception

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

Stable message:

```text
Batch is held at the source location.
```

Decimal shortfall remains a string.

Throw when:

- An explicitly selected lot is locally held.

- An explicitly selected lot is globally recalled.

- Held physical stock explains some or all of an otherwise unsatisfied quantity.

Do not throw when:

- Another eligible lot fully satisfies FEFO.

- The failure is a genuine shortage with no held/recalled stock.

## Task 4 exact HTTP mappings

POS suggestions:

```json
{
  "error": {
    "code": "BATCH_HELD",
    "message": "Batch is held at the source location."
  }
}
```

Status:

```text
422
```

Direct batch transfer:

- Catch `BatchHeldException` before generic `DomainException`.

- Return the same 422 envelope.

Current generic mapping is at [BatchController.php:385](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:385).

StockTransfer store/complete:

- Catch `BatchHeldException` before shortage/state/invalid-argument catches.

- Return the same 422 envelope.

Current store catches begin at [StockTransferController.php:187](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:187).

Delivery-note confirmation:

- Catch `BatchHeldException` before generic `DomainException`.

- Return the same 422 envelope.

Current generic delivery catch is at [DeliveryNoteController.php:589](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php:589).

POS projection:

- No HTTP response.

- Catch `BatchHeldException` before generic throwable containment.

- Roll back the lot savepoint.

- Preserve sealed event, payment, tax, and financial projection.

- Emit:

```text
BATCH_HELD_PROJECTION_CONFLICT
```

Conflict carries:

- Event ID.

- Receipt ID.

- Company ID.

- Location ID.

- Batch ID.

- Decimal shortfall.

- Eligibility reasons.

- Source revision.

- Capture time.

No held-lot movement or allocation is written.

## Task 4 implementation sequence

1. Write persisted-hold assertion-red FEFO test.

2. Capture the named assertion failure.

3. Add reason and intent enums.

4. Add query/decision DTOs.

5. Add canonical service.

6. Add source revision.

7. Add ProductCompanyLookup.

8. Add company ID to atomic FEFO.

9. Update every current caller.

10. Refactor final locks to R4-B1.

11. Route POS projection through decision.

12. Route direct transfer through decision.

13. Route StockTransfer auto allocation through decision.

14. Route StockTransfer explicit allocation through decision.

15. Route delivery automatic FEFO through decision.

16. Route delivery explicit lot through decision.

17. Add held exception.

18. Add exact controller mappings.

19. Add projection containment mapping.

20. Mark write-off/count intents explicitly.

21. Add architecture ratchet for predicate duplication.

22. Add architecture ratchet for bypass allowlist.

23. Add barrier-driven recall/issue tests.

24. Run PostgreSQL tests.

25. Run both reviewer gates.

## Task 4 assertion-red test

| Exact file | Class::method | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldEligibilityTest.php` | `test_current_fefo_excludes_persisted_branch_hold` | `self::assertNotContains((int) $this->batchA->id, $suggestedBatchIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_current_fefo_excludes_persisted_branch_hold')` | phpunit PG |

Task 3 schema is installed before this test.

The test inserts a valid unresolved root fixture.

Current FEFO returns the held lot.

The assertion fails with a concrete unexpected batch ID.

## Task 4 supplemental eligibility tests

| Exact file | Class::method | Required assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchIssueEligibilityContractTest.php` | `test_reason_set_covers_every_canonical_dimension` | `self::assertSame($expectedReasons, $decision->reasons);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchIssueEligibilityContractTest::test_reason_set_covers_every_canonical_dimension')` | phpunit PG |
| same | `test_source_revision_changes_for_each_source_fact` | `self::assertNotSame($before, $after);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchIssueEligibilityContractTest::test_source_revision_changes_for_each_source_fact')` | phpunit PG |
| same | `test_identical_rerun_preserves_source_revision` | `self::assertSame($first, $second);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchIssueEligibilityContractTest::test_identical_rerun_preserves_source_revision')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldEligibilityTest.php` | `test_hold_blocks_sale_and_direct_transfer_at_held_location_only` | `$response->assertStatus(422)->assertJsonPath('error.code', 'BATCH_HELD');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_hold_blocks_sale_and_direct_transfer_at_held_location_only')` | phpunit PG |
| same | `test_stock_transfer_manual_and_auto_allocation_exclude_held_lot` | `$response->assertStatus(422)->assertJsonPath('error.code', 'BATCH_HELD');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_stock_transfer_manual_and_auto_allocation_exclude_held_lot')` | phpunit PG |
| same | `test_explicit_and_automatic_delivery_use_same_decision` | `$explicit->assertStatus(422)->assertJsonPath('error.code', 'BATCH_HELD');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_explicit_and_automatic_delivery_use_same_decision')` | phpunit PG |
| same | `test_fefo_selects_next_eligible_lot` | `self::assertSame([$fallbackId], $allocatedBatchIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_fefo_selects_next_eligible_lot')` | phpunit PG |
| same | `test_hold_preserves_physical_reserved_stock_and_value` | `self::assertSame($before, $after);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_hold_preserves_physical_reserved_stock_and_value')` | phpunit PG |
| same | `test_variant_and_company_scopes_do_not_share_holds` | `self::assertContains($companyBBatchId, $companyBEligibleIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_variant_and_company_scopes_do_not_share_holds')` | phpunit PG |
| same | `test_flag_off_preserves_existing_hold_enforcement` | `$response->assertJsonPath('error.code', 'BATCH_HELD');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldEligibilityTest::test_flag_off_preserves_existing_hold_enforcement')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/AtomicFefoCompanyScopeTest.php` | `test_skip_locked_query_cannot_select_same_product_from_another_company` | `self::assertNotContains($companyBBatchId, $consumedIds);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'AtomicFefoCompanyScopeTest::test_skip_locked_query_cannot_select_same_product_from_another_company')` | phpunit PG |
| same | `test_legacy_shim_derives_exact_product_company` | `self::assertSame($explicitResult, $shimResult);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'AtomicFefoCompanyScopeTest::test_legacy_shim_derives_exact_product_company')` | phpunit PG |
| `apps/api/tests/Architecture/BatchIssueEligibilityArchitectureTest.php` | `test_issue_consumers_do_not_reimplement_predicate` | `self::assertSame([], $violations);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchIssueEligibilityArchitectureTest::test_issue_consumers_do_not_reimplement_predicate')` | phpunit sqlite/no DB |
| same | `test_only_disposition_allowlist_can_bypass_eligibility` | `self::assertSame($expectedAllowlist, $actualBypasses);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchIssueEligibilityArchitectureTest::test_only_disposition_allowlist_can_bypass_eligibility')` | phpunit sqlite/no DB |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldProjectionTest.php` | `test_sealed_receipt_hold_conflict_is_explicit_without_allocation` | `self::assertSame('BATCH_HELD_PROJECTION_CONFLICT', $conflictCode);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldProjectionTest::test_sealed_receipt_hold_conflict_is_explicit_without_allocation')` | phpunit PG |
| same | `test_retired_new_sale_route_remains_410` | `$response->assertStatus(410)->assertJsonPath('error.code', 'NEW_SALE_AUTHORING_RETIRED');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldProjectionTest::test_retired_new_sale_route_remains_410')` | phpunit PG |

## Task 4 recall-versus-issue barrier tests

| Exact file | Class::method | Bounded-completion assertion | Safety assertion | Command |
|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/BatchRecallHoldConcurrencyTest.php` | `test_recall_vs_pos_projection_has_no_post_recall_lot_movement` | `self::assertTrue($workersCompletedWithinDeadline);` | `self::assertSame([], $movementsCommittedAfterRecall);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldConcurrencyTest::test_recall_vs_pos_projection_has_no_post_recall_lot_movement')` |
| same | `test_recall_vs_direct_transfer_has_no_lost_hold` | `self::assertTrue($workersCompletedWithinDeadline);` | `self::assertSame([], $postRecallTransferMovements);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldConcurrencyTest::test_recall_vs_direct_transfer_has_no_lost_hold')` |
| same | `test_recall_vs_stock_transfer_auto_allocation_has_no_lost_hold` | `self::assertTrue($workersCompletedWithinDeadline);` | `self::assertSame([], $postRecallAllocations);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldConcurrencyTest::test_recall_vs_stock_transfer_auto_allocation_has_no_lost_hold')` |
| same | `test_recall_vs_stock_transfer_explicit_allocation_has_no_lost_hold` | `self::assertTrue($workersCompletedWithinDeadline);` | `self::assertSame([], $postRecallAllocations);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldConcurrencyTest::test_recall_vs_stock_transfer_explicit_allocation_has_no_lost_hold')` |
| same | `test_recall_vs_delivery_auto_fefo_has_no_post_recall_issue` | `self::assertTrue($workersCompletedWithinDeadline);` | `self::assertSame([], $postRecallDeliveryMovements);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldConcurrencyTest::test_recall_vs_delivery_auto_fefo_has_no_post_recall_issue')` |
| same | `test_recall_vs_delivery_explicit_batch_has_no_post_recall_issue` | `self::assertTrue($workersCompletedWithinDeadline);` | `self::assertSame([], $postRecallDeliveryMovements);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallHoldConcurrencyTest::test_recall_vs_delivery_explicit_batch_has_no_post_recall_issue')` |

Lane for every row:

```text
phpunit PG
```

Each test additionally asserts:

```php
self::assertNull($deadlockError);
```

and either:

```php
self::assertSame('BATCH_HELD', $losingWorkerErrorCode);
```

or:

```php
self::assertLessThan($recallCommitAt, $winningMovementCommitAt);
```

A movement committed before recall may win.

A movement committed after recall may not contain the recalled batch.

## Task 4 convention-09 mapping

Second company:

- Same product and lot label in company B.

- Company A hold never changes B decision.

Second location:

- A1 held.

- A2 eligible until global recall.

- Global recall blocks both.

Rerun:

- Repeated rejected operation produces the same error.

- No duplicate movement/allocation.

- Same decision inputs produce the same source revision.

Task 4 modifies lot behavior and is explicitly in convention-09 scope.

## Task 4 reviewer gates

**tenancy-authz-reviewer**

Must approve:

- Company-aware FEFO.

- Product-company adapter.

- Tuple correlation.

- Delivery and transfer permissions.

- No ambient company derivation.

**inventory-costing-reviewer**

Must approve:

- R4-B1 lock order.

- Every barrier race.

- FEFO fallback.

- Reservation semantics.

- Decimal strings.

- Disposition bypass allowlist.

- No stock/value mutation from hold creation.

## Task 4 rollback

After any hold/global evidence exists:

- Never deploy code that ignores it.

- Never roll back to stock-before-batch locking.

- Never roll back delivery explicit-lot enforcement.

- If an issue path is faulty, disable that issue surface or correct forward.

- Flag OFF does not disable enforcement.

- UI rollback is allowed independently.

---

# Task 5 — Make BatchRecallService the sole lifecycle writer and execute company-wide recall

## Task 5 dependencies

Tasks 2 through 4.

## Task 5 production files — modify

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`

- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallService.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php`

- `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php`

- `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php`

- `apps/api/database/seeders/DemoPharmacySeeder.php`

- `apps/api/database/factories/BatchExpiry/BatchFactory.php`

## Task 5 production files — add

- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/RecallBatchRequest.php` if not already created with Task 3.

## Task 5 new tests

- `apps/api/tests/Architecture/BatchRecallWriterRatchetTest.php`

- `apps/api/tests/Architecture/BatchRecallTestFixtureCensusTest.php`

- `apps/api/tests/Feature/BatchExpiry/BatchRecallWriterRegressionTest.php`

- `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php`

- `apps/api/tests/Feature/BatchExpiry/BatchRecallTransitionUuidTest.php`

## Task 5 existing tests — modify

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

## Task 5 production writer census

| Current writer/exposure | Disposition |
|---|---|
| Controller calls entity recall at [BatchController.php:204](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:204) | Replace with canonical service |
| Entity overwrites fields at [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134) | Remove public `recall()` |
| Repository recall overwrite | Remove interface and implementation method |
| Recall fields are fillable at [Batch.php:31](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:31) | Remove three recall keys |
| Generic repository update | Reject all recall keys |
| Generic repository create | Strip false/null recall defaults; reject true/non-null values |
| DemoPharmacySeeder reset | Remove reset; skip evidenced/recalled fixture |
| BatchStockService false-only new-lot default | Classify insert-only default |
| FEFO false-only new-lot default | Classify insert-only default |
| Historical migration defaults at [product-batch migration:35](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:35) | Preserve schema declaration |
| BatchFactory recalled state | Sole allowlisted historical test fixture |
| Daily-check recall predicate | Read-only; not writer |
| Phantom-repair recall predicate | Read-only; not writer |
| POS HeldOrder `recalled_at` | Different table/concept; table-qualified exclusion |

## Task 5 test-fixture writer census

<a id="R3-B2"></a>

Allowed historical fixture:

```php
private const ALLOWED_HISTORICAL_FIXTURES = [
    'apps/api/database/factories/BatchExpiry/BatchFactory.php::recalled'
        => 'single historical global-recall factory state',
];
```

Allowed mechanisms:

- `Batch::factory()->recalled()->make()`.

- `Batch::factory()->recalled()->create()`.

- `BatchRecallService::recall(...)`.

- Literal false/null recall values on a provably fresh insert.

Forbidden test mechanisms:

- Direct true constructor value.

- Direct non-null reason/time constructor value.

- Raw update.

- Raw assignment.

- `forceFill`.

- `updateOrCreate` recall reset.

- Removed entity mutator.

- Removed repository mutator.

- Unclassified helper hiding a direct write.

Exact current replacements:

| Existing test path | Replacement |
|---|---|
| `tests/Unit/BatchExpiry/GetExpiredBatchesWithStockTest.php` | recalled factory |
| `tests/Unit/BatchExpiry/FEFOInventoryServiceTest.php` | recalled factory branch in helper |
| `tests/Unit/BatchExpiry/BatchEntityTest.php` | recalled factory `make()` |
| `tests/Unit/BatchExpiry/FEFOSuggestionPrecisionTest.php` | remove unused recall parameter |
| `tests/Feature/BatchExpiry/AtomicFEFOConsumptionTest.php` | remove unused false key |
| `tests/Feature/BatchExpiry/FEFOInventoryServiceVariantTest.php` | remove unused false key |
| `tests/Feature/Replenishment/ReplenishmentActionsTest.php` | recalled factory |
| `tests/Feature/Inventory/InventoryTransferServiceTest.php` | recalled factory |
| `tests/Feature/Inventory/StockTransferAutoAllocateFefoTest.php` | recalled factory |
| `tests/Feature/Inventory/CountingVarianceAppliedTest.php` | canonical service with actor DTO |
| `tests/Feature/Inventory/StockAdjustmentBatchDispositionTest.php` | canonical service after draft setup |

The test scanner includes a liveness source fixture containing:

```php
DB::table('product_batches')
    ->update(['is_recalled' => true]);
```

The scanner must report its synthetic path and line.

The liveness fixture is not counted as baseline red.

## Task 5 public recall signature

```php
public function recall(
    ActorIdentityScopeData $actor,
    string $batchUuid,
    RecallBatchData $data,
): Batch;
```

Controller:

```php
public function recall(
    RecallBatchRequest $request,
    string $uuid,
): JsonResponse;
```

FormRequest:

```php
public function authorize(): bool;

public function rules(): array;
```

Rules:

```php
[
    'operation_uuid' => ['required', 'string', 'uuid'],
    'reason' => ['required', 'string', 'max:255'],
]
```

## Task 5 direct global-recall behavior

1. Require `batches.recall`.

2. Require active membership.

3. Require `allowedLocationIds === null`.

4. Normalize reason.

5. Resolve batch inside actor company.

6. Follow R4-B1 recall lock order.

7. Run global-only operation replay lookup.

8. Matching replay requires same actor, batch, and normalized reason.

9. Mismatched replay returns 409.

10. A fresh operation inserts one `global_recall` evidence row.

11. Fresh operation changes the projection.

12. Evidence and projection are atomic.

13. Lock unresolved roots in stable order.

14. Append one deterministic transition per root.

15. Never overwrite roots.

16. Never append a second terminal child.

17. Same operation replay preserves timestamp.

18. A different operation against already recalled batch returns 409.

19. Legacy recalled projection without global evidence returns `LEGACY_RECALL_EVIDENCE_ABSENT`.

20. Company B remains unchanged.

## Task 5 response contract

Success retains existing batch response shape:

```json
{
  "data": {
    "...": "existing BatchResource fields"
  },
  "meta": {
    "operation_uuid": "uuid",
    "replayed": false
  }
}
```

Matching replay:

```json
{
  "data": {
    "...": "same batch state"
  },
  "meta": {
    "operation_uuid": "same uuid",
    "replayed": true
  }
}
```

`apiPost<Batch>` continues to unwrap `data` once.

No double unwrap is introduced.

## Task 5 request-versus-recall race

Barrier scenario A — request wins locks:

1. Request locks batch.

2. Request locks stock tuple.

3. Request inserts root.

4. Request commits.

5. Recall proceeds.

6. Recall inserts global evidence.

7. Recall transitions the root.

Required result:

```text
root exists
terminal child exists
global evidence exists
no unresolved root
```

Barrier scenario B — recall wins locks:

1. Recall locks batch.

2. Recall locks all stock rows.

3. Recall inserts global evidence.

4. Recall sets projection.

5. Recall commits.

6. Request re-reads recalled state.

7. Request rejects.

Required result:

```text
no late root
global evidence exists
batch recalled
no lost hold
```

Both scenarios assert bounded completion.

## Task 5 implementation sequence

1. Write writer-ratchet assertion-red test.

2. Write direct-global-evidence assertion-red test.

3. Capture named assertion failures.

4. Add RecallBatchRequest operation UUID.

5. Implement global namespace replay.

6. Implement R4-B1 recall locks.

7. Insert global evidence.

8. Update projection atomically.

9. Lock roots in stable order.

10. Append transitions.

11. Handle same-operation replay.

12. Handle mismatch.

13. Handle legacy projection.

14. Replace controller entity call.

15. Remove entity mutator.

16. Remove repository mutator.

17. Remove recall fields from fillable.

18. Guard generic create/update.

19. Harden demo seeder.

20. Add production AST ratchet.

21. Add test-fixture census.

22. Replace direct test writers.

23. Add request-versus-recall barriers.

24. Run PostgreSQL tests.

25. Run both reviewer gates.

## Task 5 assertion-red tests

| Exact file | Class::method | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Architecture/BatchRecallWriterRatchetTest.php` | `test_existing_recall_writers_use_canonical_service` | `self::assertSame([], $violations, implode(PHP_EOL, $violations));` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchRecallWriterRatchetTest::test_existing_recall_writers_use_canonical_service')` | phpunit sqlite/no DB |
| `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php` | `test_direct_recall_appends_global_recall_evidence` | `self::assertSame(1, $globalRecallEvidenceCount);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_direct_recall_appends_global_recall_evidence')` | phpunit PG |

The architecture scanner runs against existing source and returns concrete violations.

The evidence table exists from Task 3.

Current recall updates the projection but inserts zero global evidence rows.

The second test therefore fails `assertSame(1, 0)` rather than setup.

## Task 5 supplemental writer tests

| Exact file | Class::method | Required assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Architecture/BatchRecallTestFixtureCensusTest.php` | `test_every_direct_historical_setup_is_classified` | `self::assertSame([], $violations);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchRecallTestFixtureCensusTest::test_every_direct_historical_setup_is_classified')` | phpunit sqlite/no DB |
| `apps/api/tests/Architecture/BatchRecallWriterRatchetTest.php` | `test_detector_flags_new_direct_writer` | `self::assertStringContainsString('SyntheticWriter.php:7', $report);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchRecallWriterRatchetTest::test_detector_flags_new_direct_writer')` | phpunit sqlite/no DB |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallWriterRegressionTest.php` | `test_demo_reseed_cannot_clear_recall_or_request_evidence` | `self::assertSame($beforeEvidence, $afterEvidence);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallWriterRegressionTest::test_demo_reseed_cannot_clear_recall_or_request_evidence')` | phpunit PG |
| same | `test_generic_update_and_removed_mutators_cannot_bypass_service` | `self::assertFalse(method_exists(Batch::class, 'recall'));` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallWriterRegressionTest::test_generic_update_and_removed_mutators_cannot_bypass_service')` | phpunit PG |
| `apps/api/tests/Feature/BatchExpiry/BatchRecallTransitionUuidTest.php` | `test_uuidv5_fixture_is_stable` | `self::assertSame('463d9ce3-793e-53c0-bdc5-10e2fd6e90c9', $actual);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchRecallTransitionUuidTest::test_uuidv5_fixture_is_stable')` | phpunit PG |

## Task 5 supplemental company-recall tests

| Exact file | Class::method | Required assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallTest.php` | `test_general_manager_recall_spans_branches_and_transitions_requests` | `$history->assertJsonPath('data.requests.0.status', 'recalled');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_general_manager_recall_spans_branches_and_transitions_requests')` | phpunit PG |
| same | `test_manager_cannot_recall_company_wide` | `$response->assertForbidden();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_manager_cannot_recall_company_wide')` | phpunit PG |
| same | `test_restricted_custom_recall_permission_cannot_execute_company_wide` | `$response->assertForbidden();` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_restricted_custom_recall_permission_cannot_execute_company_wide')` | phpunit PG |
| same | `test_recall_retry_preserves_evidence_timestamp_and_transition_count` | `self::assertSame($firstCreatedAt, $replayedCreatedAt);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_recall_retry_preserves_evidence_timestamp_and_transition_count')` | phpunit PG |
| same | `test_second_company_is_unchanged` | `self::assertSame($beforeB, $afterB);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_second_company_is_unchanged')` | phpunit PG |
| same | `test_history_preserves_request_transition_and_global_reasons` | `$history->assertJsonPath('data.global_recall.reason', 'Company-wide safety recall');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_history_preserves_request_transition_and_global_reasons')` | phpunit PG |
| same | `test_recall_does_not_append_to_already_terminal_root` | `self::assertSame(1, $terminalChildCount);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_recall_does_not_append_to_already_terminal_root')` | phpunit PG |
| same | `test_legacy_recalled_projection_cannot_receive_invented_evidence` | `$response->assertConflict()->assertJsonPath('error.code', 'LEGACY_RECALL_EVIDENCE_ABSENT');` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallTest::test_legacy_recalled_projection_cannot_receive_invented_evidence')` | phpunit PG |

## Task 5 request-versus-recall barrier tests

| Exact file | Class::method | Bounded assertion | Safety assertion | Command |
|---|---|---|---|---|
| `apps/api/tests/Feature/BatchExpiry/CompanyWideBatchRecallConcurrencyTest.php` | `test_request_committing_first_is_transitioned_before_recall_commit` | `self::assertTrue($workersCompletedWithinDeadline);` | `self::assertSame(0, $unresolvedRoots);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallConcurrencyTest::test_request_committing_first_is_transitioned_before_recall_commit')` |
| same | `test_recall_committing_first_rejects_late_request` | `self::assertTrue($workersCompletedWithinDeadline);` | `self::assertSame(0, $lateRootCount);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallConcurrencyTest::test_recall_committing_first_rejects_late_request')` |
| same | `test_request_and_recall_never_lose_the_hold` | `self::assertNull($deadlockError);` | `self::assertTrue($batch->fresh()->is_recalled);` | `(cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'CompanyWideBatchRecallConcurrencyTest::test_request_and_recall_never_lose_the_hold')` |

Lane:

```text
phpunit PG
```

## Task 5 convention-09 mapping

Second company:

- Matching lot in B remains unchanged.

- Same operation UUID may be used independently in B.

Second location:

- A1 and A2 roots transition.

- Global recall blocks both.

Rerun:

- Same operation returns original evidence.

- Different payload returns 409.

- Transition count remains stable.

- Demo reseed preserves evidence and emits the skip marker.

Task 5 modifies catalogue lifecycle behavior and is in scope.

## Task 5 reviewer gates

**tenancy-authz-reviewer**

Must approve:

- Unrestricted membership check.

- Company isolation.

- Actor evidence.

- Replay ownership.

- Request-versus-recall outcomes.

**inventory-costing-reviewer**

Must approve:

- R4-B1 recall locks.

- Writer exclusivity.

- Global evidence atomicity.

- Transition atomicity.

- Zero physical/value mutation.

## Task 5 rollback

- Never clear global recall as software rollback.

- Never delete global evidence.

- Never delete roots or transitions.

- Never restore public entity/repository recall mutators.

- Never restore demo recall resets.

- Disable the action route if necessary.

- Keep issue enforcement active.

- Correct forward.

---

# Task 6 — Generate frontend contracts and expose request/history on existing batch detail

## Task 6 dependencies

Tasks 1 through 5.

## Task 6 production files — modify

- `apps/web/src/routes/index.tsx`

- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`

- `apps/web/src/features/batches/pages/BatchListPage.tsx`

- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`

- `apps/web/src/features/batches/api/batches.ts`

- `apps/web/src/features/batches/hooks/useBatches.ts`

- `apps/web/src/features/batches/types.ts`

- `apps/web/src/hooks/permissionsMap.generated.ts`

- `packages/shared/types/generated.d.ts`

- `apps/web/src/locales/en/batches.json`

- `apps/web/src/locales/fr/batches.json`

- `docs/glossary.md`

## Task 6 production files — add

- No new domain-type file.

- New hooks/tests may be added under existing batch directories.

- No second recall page.

## Task 6 source facts

Current batch routes use generic inventory permission at [routes/index.tsx:1180](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/routes/index.tsx:1180).

Current sidebar gates batches only by module at [Sidebar.tsx:222](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/Sidebar/Sidebar.tsx:222).

Current generated map grants batch view to admin/cashier/manager only at [permissionsMap.generated.ts:13](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/permissionsMap.generated.ts:13).

Current detail action booleans ignore permissions at [BatchDetailPage.tsx:75](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:75).

Current page sends `recall_reason` at [BatchDetailPage.tsx:48](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:48).

Current API forwards that object at [batches.ts:103](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/api/batches.ts:103).

## Task 6 frontend signatures

```ts
export function createBatchRecallRequest(
  uuid: string,
  input: CreateBatchRecallRequestData,
): Promise<BatchRecallRequestData>
```

```ts
export function getBatchRecallRequests(
  uuid: string,
): Promise<BatchRecallHistoryData>
```

```ts
export function getBatchRecallCapabilities(
): Promise<BatchRecallCapabilitiesData>
```

```ts
export function recallBatch(
  uuid: string,
  input: RecallBatchData,
): Promise<Batch>
```

```ts
export function useBatchRecallCapabilities(
): UseQueryResult<BatchRecallCapabilitiesData>
```

```ts
export function useBatchRecallHistory(
  uuid: string,
  enabled: boolean,
): UseQueryResult<BatchRecallHistoryData>
```

## Task 6 API paths

```text
GET /batches/recall-capabilities
```

```text
GET /batches/{uuid}/recall-requests
```

```text
POST /batches/{uuid}/recall-requests
```

```text
POST /batches/{uuid}/recall
```

`apiGet`/`apiPost` already unwrap `data`.

Return their values directly.

Do not double unwrap.

## Task 6 query keys

Capability:

```ts
tenantScopedKey(['batches', 'recall-capabilities'])
```

History:

```ts
tenantScopedKey(['batches', uuid, 'recall-requests'])
```

Batch stock:

- Retain the existing tenant-scoped key.

- Invalidate after successful request only where eligibility/history consumers require refresh.

Company switch:

- Produces new keys.

- Clears pending request state.

- Clears pending recall state.

## Task 6 route/action permissions

Routes:

| UI surface | Permission |
|---|---|
| Batch list | `batches.view` |
| Batch detail | `batches.view` |
| Create | `batches.create` |
| Edit | `batches.update` |
| Write-off | `batches.write-off` |

Actions:

| Action | Permission |
|---|---|
| Edit | `batches.update` |
| Deactivate | `batches.delete` |
| Company recall | `batches.recall` |
| Request recall | `batches.recall.request` |
| Fetch/open traceability | `batches.traceability` |

ModuleGuard remains required.

Hiding trace UI is insufficient.

The trace query must remain disabled without permission.

## Task 6 capability behavior

Show request authoring only when all are true:

- BatchExpiry module enabled.

- `batches.recall.request` granted.

- Capability query succeeded.

- `enabled === true`.

- Batch is not globally recalled.

- At least one scoped positive-physical-stock location exists.

Fail closed when:

- Loading.

- Error.

- Missing data.

- `enabled=false`.

- Company switch is pending.

- Permission absent.

- Module absent.

Capability response is not inferred from role name.

## Task 6 location selector

Use:

```ts
useScopedLocations()
```

Existing hook source is [useScopedLocations.ts:8](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/locations/hooks/useScopedLocations.ts:8).

Intersect:

- Active scoped locations.

- Batch-stock rows.

- Physical quantity greater than zero.

Quantity comparison:

- Decimal string library.

- No `parseFloat`.

- No `Number(...)`.

Fully reserved positive physical stock remains selectable.

Do not use available quantity for request eligibility.

No options until both queries succeed.

If selected location disappears:

- Clear selection.

- Clear pending operation UUID.

- Require a new explicit choice.

- Never silently pick another location.

## Task 6 request retry state

Generate operation UUID once per submission intent.

Preserve across:

- Network error.

- Timeout.

- Safe client retry.

Capture together:

- Batch UUID.

- Company identity.

- Location ID.

- Normalized reason.

- Operation UUID.

Reset after:

- Successful response.

- Explicitly starting a new submission.

- Company change.

- Batch change.

- Scope change invalidating location.

## Task 6 company recall retry state

Correct payload to:

```ts
{
  operation_uuid: string
  reason: string
}
```

Generate UUID once per company-recall intent.

Preserve it across network retries.

Reset only after success or explicit new intent.

Do not continue sending `recall_reason`.

## Task 6 history presentation

For request root show:

- Requested status.

- Request reason.

- Request actor.

- Request timestamp.

- Location.

For transition show separately:

- Recalled status.

- Transition reason.

- Transition actor.

- Transition timestamp.

For direct global evidence show:

- Company-wide recall.

- Global reason.

- Global actor.

- Global timestamp.

Do not merge request and transition reasons.

Do not manufacture a request row for direct recall.

## Task 6 translations

Add exact translation keys in both English and French for:

- Request recall.

- Recall batch.

- Recall reason.

- Request reason.

- Company-wide recall reason.

- Location.

- Requested by.

- Recalled by.

- Request pending.

- Request recalled.

- No request history.

- Capability unavailable.

- Loading locations.

- Location load error.

- No eligible location.

- Request replayed.

- Request conflict.

- Open request exists.

- Batch held error.

- Legacy recall evidence unavailable.

All user-facing strings use `t()`.

Touched UI uses design tokens.

## Task 6 feature fingerprint

Eager marker:

```text
wlota1-recall-request-v5
```

It must appear in eagerly loaded batch-route metadata.

It must survive minification in the served entry asset.

A lazy-only occurrence does not satisfy deployment verification.

## Task 6 generation commands

```bash
(cd apps/api && php artisan typescript:transform)
```

```bash
(cd apps/api && php artisan permissions:export-frontend-map)
```

Run both twice.

Assert generated bytes are identical.

Commit both generated files.

## Task 6 implementation sequence

1. Write seeded-map assertion-red test.

2. Write request-button assertion-red test.

3. Capture named assertion failures.

4. Regenerate backend DTO declarations.

5. Regenerate permission map.

6. Replace generic route permissions.

7. Gate sidebar with `batches.view`.

8. Add API functions.

9. Add capability hook.

10. Add history hook.

11. Add permission-derived action booleans.

12. Disable unauthorized trace query.

13. Add request form to existing detail.

14. Add scoped physical-stock selector.

15. Add stable request operation UUID state.

16. Correct company recall payload.

17. Add stable company-recall operation UUID state.

18. Add immutable history.

19. Add direct global evidence view.

20. Add translations.

21. Add glossary rows.

22. Add eager marker.

23. Run React diagnostics workflow.

24. Run Vitest.

25. Run typecheck.

26. Run lint.

27. Run applicable Playwright batch journey.

28. Run both reviewer gates.

## Task 6 assertion-red tests

| Exact file | Suite::case | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/web/src/features/batches/pages/BatchSeededPermissionMap.test.ts` | `BatchSeededPermissionMap::includes seeded viewers in batch view` | `expect(PERMISSIONS['batches.view']).toContain('viewer')` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchSeededPermissionMap.test.ts -t "includes seeded viewers in batch view"` | vitest |
| `apps/web/src/features/batches/pages/BatchRecallRequest.test.tsx` | `BatchRecallRequest::shows request action for eligible manager` | `expect(screen.queryByRole('button', { name: 'Request recall' })).toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "shows request action for eligible manager"` | vitest |

The map test imports the current generated map and fails its containment assertion.

The UI test renders the existing detail page with HTTP-level mocks and fails because the button is absent.

Neither test imports a missing planned hook as its red mechanism.

## Task 6 supplemental Vitest matrix

| Exact file | Suite::case | Required assertion | Exact command |
|---|---|---|---|
| `apps/web/src/routes/__tests__/BatchRoutePermissions.test.tsx` | `BatchRoutePermissions::denies each direct route without action permission` | `expect(screen.queryByTestId('batch-page')).not.toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/routes/__tests__/BatchRoutePermissions.test.tsx -t "denies each direct route without action permission"` |
| `apps/web/src/features/batches/pages/BatchPermissions.test.tsx` | `BatchPermissions::manager sees request but not company recall` | `expect(screen.queryByRole('button', { name: 'Recall batch' })).not.toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchPermissions.test.tsx -t "manager sees request but not company recall"` |
| same | `BatchPermissions::viewer cannot mutate and trace query stays disabled` | `expect(traceApi).not.toHaveBeenCalled()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchPermissions.test.tsx -t "viewer cannot mutate and trace query stays disabled"` |
| `apps/web/src/features/batches/pages/BatchRecallRequest.test.tsx` | `BatchRecallRequest::retry retains operation UUID and selected location` | `expect(secondPayload).toEqual(firstPayload)` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "retry retains operation UUID and selected location"` |
| same | `BatchRecallRequest::shows scoped positive physical stock options only` | `expect(screen.queryByRole('option', { name: 'Branch A2' })).not.toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "shows scoped positive physical stock options only"` |
| same | `BatchRecallRequest::fully reserved positive stock remains requestable` | `expect(screen.getByRole('option', { name: 'Branch A1' })).toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "fully reserved positive stock remains requestable"` |
| same | `BatchRecallRequest::company switch discards stale selector and pending request` | `expect(screen.queryByRole('option', { name: 'Branch A1' })).not.toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "company switch discards stale selector and pending request"` |
| same | `BatchRecallRequest::history distinguishes request transition and global reasons` | `expect(screen.getByText('Company-wide safety recall')).toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "history distinguishes request transition and global reasons"` |
| same | `BatchRecallRequest::dormant capability hides submission` | `expect(screen.queryByRole('button', { name: 'Request recall' })).not.toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchRecallRequest.test.tsx -t "dormant capability hides submission"` |
| `apps/web/src/features/batches/pages/BatchCompanyRecall.test.tsx` | `BatchCompanyRecall::retry retains global operation UUID` | `expect(secondPayload.operation_uuid).toBe(firstPayload.operation_uuid)` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchCompanyRecall.test.tsx -t "retry retains global operation UUID"` |
| `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx` | `Sidebar::hides batches without view permission` | `expect(screen.queryByRole('link', { name: 'Batches' })).not.toBeInTheDocument()` | `pnpm --filter @autoerp/web exec vitest run src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx -t "hides batches without view permission"` |
| `apps/web/src/features/batches/pages/BatchSeededPermissionMap.test.ts` | `BatchSeededPermissionMap::matches all approved seeded batch roles` | `expect(PERMISSIONS['batches.traceability']).not.toContain('operator')` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchSeededPermissionMap.test.ts -t "matches all approved seeded batch roles"` |
| `apps/web/src/features/batches/hooks/__tests__/useBatchRecallCapabilities.test.tsx` | `useBatchRecallCapabilities::uses tenant scoped key and fails closed` | `expect(result.current.data?.enabled ?? false).toBe(false)` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/hooks/__tests__/useBatchRecallCapabilities.test.tsx -t "uses tenant scoped key and fails closed"` |
| `apps/web/src/features/batches/api/__tests__/capabilities.test.ts` | `capabilities::fetches and unwraps once` | `expect(result).toEqual({ enabled: true })` | `pnpm --filter @autoerp/web exec vitest run src/features/batches/api/__tests__/capabilities.test.ts -t "fetches and unwraps once"` |

Lane for all rows:

```text
vitest
```

## Task 6 browser acceptance

Exact command:

```bash
pnpm --filter @autoerp/web test:e2e --grep "batch recall request"
```

Required journey:

1. Manager opens A batch detail.

2. Manager sees request action.

3. Selector contains A1 only.

4. Request succeeds.

5. History shows request evidence.

6. General manager opens same detail.

7. General manager sees company recall action.

8. Manager does not.

9. General manager recalls with operation UUID.

10. History shows transition and global evidence.

11. Viewer sees read-only history.

12. Viewer sees no mutation controls.

13. Company B detail is unaffected.

## Task 6 convention-09 mapping

Second company:

- Switch A to B.

- A location and operation state disappear.

- B query key differs.

- B history contains no A evidence.

Second location:

- Overbroad stock mock includes A2.

- Scoped-locations mock includes A1 only.

- UI offers A1 only.

Rerun:

- Request retry retains identical UUID/location/reason.

- Global recall retry retains identical UUID/reason.

- History contains one evidence row per operation.

Task 6 changes lot UI and is explicitly in convention-09 scope.

## Task 6 reviewer gates

**tenancy-authz-reviewer**

Must approve:

- Route permission composition.

- Sidebar permission.

- Query disabling.

- Tenant-scoped keys.

- Company-switch invalidation.

- Capability fail-closed behavior.

**inventory-costing-reviewer**

Must approve:

- Physical quantity remains visible.

- Eligible quantity is not substituted for physical stock.

- Fully reserved physical stock remains requestable.

- Held status is not shown as a stock decrement.

## Task 6 rollback

- Web form/history presentation may roll back independently.

- Backend permission and safety enforcement remain.

- Do not deploy an older generated permission map.

- Do not restore `recall_reason`.

- If UI rollback occurs, retain the eager backend capability and history contracts.

---

# Deployment and rollback

<a id="R4-M7"></a>

**Plan line R4-M7**

> Deployment follows `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`
> (five-push sequence §2, web block §3, gate checklist §4). This slice supplies only the variables
> below; it does not restate deploy mechanics.

The required reference sentence appears at [manifest:267](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:267).

The ten required variables begin at [manifest:273](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:273).

## Deployment variables

| Variable | W-LOT-A-1 rev-5 value |
|---|---|
| `<slice>` | `wlota1-permissions-hold` |
| Migrations list | `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php`, then `apps/api/database/migrations/tenant/2026_09_06_210000_create_batch_recall_requests_table.php`; additive, self-guarding, exact-definition validating |
| Flags | `batch_recall.enabled` / `BATCH_RECALL_ENABLED` / `apps/api/config/batch_recall.php` / default false |
| Commands | Exact permission delta, reseed, cache reset, verification, TypeScript transform, and frontend permission-map export below |
| Censuses | Day-one, POS VAT legs, lot drift, phantom DEFAULT dry run; exact host-persisted gate below |
| Web changes | yes; fingerprint `wlota1-recall-request-v5`; execute canonical web block |
| Device build | no |
| Queues | none new |
| Collapsed pushes | Push 1 is read-only pre-Push-2 evidence; Push 4 has no new source artifact and is the operational permission/census gate |
| Env path | Dokploy Environment tab only after U-1 confirms separate-app topology; otherwise compose `x-api-env` wiring in a flag-false preparatory push |

## Mandatory promotion preconditions

Before Push 3:

- U-1 topology/env path is closed.

- U-2 database-per-tenant mode is confirmed ON.

- U-6 boot permission sync is read back and confirmed false.

Before Push 2:

- Host-owned backups exist for every tenant.

- P1 census artifacts have been copied out of the API container.

- Artifact checksums are recorded.

Before Push 4:

- P1 artifacts are restored into the current API container.

- Tenant cardinality matches.

- Permission before-state is captured.

Before Push 5:

- Permission delta is verified for every tenant.

- Cache-reset marker exists for every tenant.

- P4 census gate passes.

- Both reviewers approve all tasks.

## U-6 exact readback gate

Run inside the current API container before Push 3:

```bash
bash -euo pipefail <<'BASH'
raw="$(printenv SYNC_PERMISSIONS_ON_BOOT || true)"
normalized="$(printf '%s' "$raw" | tr '[:upper:]' '[:lower:]' | tr -d '[:space:]')"

case "$normalized" in
  ''|'0'|'false'|'off'|'no')
    echo "WLOTA1-U6 sync_permissions_on_boot=false outcome=PASS"
    ;;
  *)
    echo "WLOTA1-U6 sync_permissions_on_boot=true outcome=FAIL" >&2
    exit 1
    ;;
esac
BASH
```

Pass contract:

- Exit code is zero.

- Exact marker appears once:

```text
WLOTA1-U6 sync_permissions_on_boot=false outcome=PASS
```

- No FAIL marker.

- Save stdout to host-owned deployment evidence.

Push 3 must not proceed when this gate fails.

The manifest confirms permission sync is opt-in and currently unverified at [manifest:32](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:32).

## Exact files per push

### Pre-Push-2 / collapsed Push 1

Source files:

```text
none
```

Operational outputs only:

- Tenant ID baseline.

- Day-one census logs.

- VAT-leg census logs.

- Lot-drift census logs.

- Phantom DEFAULT dry-run logs.

- Host backups.

- SHA-256 manifest.

### Push 2 — schema only

Exact files:

```text
apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php
apps/api/database/migrations/tenant/2026_09_06_210000_create_batch_recall_requests_table.php
```

No route wiring.

No permission grant.

No evidence insert.

No activation.

### Push 3 — dormant implementation

Exact files:

```text
apps/api/config/batch_recall.php
apps/api/app/Shared/Contracts/Identity/ActorIdentityScopeData.php
apps/api/app/Shared/Contracts/BatchTraceability/BatchTraceOccurrenceData.php
apps/api/app/Shared/Contracts/BatchTraceability/DocumentBatchTraceReader.php
apps/api/app/Shared/Contracts/BatchTraceability/PosBatchTraceReader.php
apps/api/app/Shared/Contracts/Product/ProductCompanyLookup.php
apps/api/app/Modules/BatchExpiry/Application/DTOs/CreateBatchRecallRequestData.php
apps/api/app/Modules/BatchExpiry/Application/DTOs/RecallBatchData.php
apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchRecallTransitionData.php
apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchGlobalRecallEvidenceData.php
apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchRecallRequestData.php
apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchRecallHistoryData.php
apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchRecallCapabilitiesData.php
apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchRecallEvidenceKind.php
apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchRecallRequestStatus.php
apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchIssueEligibilityReason.php
apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchStockIssueIntent.php
apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchRecallRequest.php
apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallActivation.php
apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallService.php
apps/api/app/Modules/BatchExpiry/Application/Services/BatchIssueEligibilityService.php
apps/api/app/Modules/BatchExpiry/Domain/Exceptions/BatchHeldException.php
apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php
apps/api/app/Modules/Identity/Domain/Enums/RoleProvisioningSource.php
apps/api/app/Console/Commands/ApplyLotRecallPermissionDelta.php
apps/api/database/seeders/RolesAndPermissionsSeeder.php
packages/shared/types/generated.d.ts
```

Constraints:

- U-6 false gate passed first.

- Existing routes do not select new services.

- Seeder cannot run automatically.

- Flag is false.

- Existing response captures remain identical.

### Push 4 — operational permission gate

New source files:

```text
none
```

Operational actions:

- Apply targeted permission delta.

- Run safe reseed.

- Reset permission cache.

- Verify delta.

- Rerun apply for ALREADY_APPLIED.

- Run P4 censuses.

- Compare P1 artifacts restored from host.

Flag remains false.

### Push 5 — hardening and web activation artifact

Exact backend wiring files:

```text
apps/api/app/Modules/BatchExpiry/Presentation/routes.php
apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php
apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php
apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php
apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchRecallRequestController.php
apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRecallRequest.php
apps/api/app/Modules/BatchExpiry/Presentation/Requests/RecallBatchRequest.php
apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php
apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php
apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php
apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php
apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php
apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php
apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php
apps/api/app/Modules/Document/Infrastructure/BatchTraceability/DocumentBatchTraceReaderAdapter.php
apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php
apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php
apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php
apps/api/app/Modules/POS/Infrastructure/BatchTraceability/PosBatchTraceReaderAdapter.php
apps/api/app/Modules/POS/Providers/POSServiceProvider.php
apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php
apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php
apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php
apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php
apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php
apps/api/app/Modules/Identity/Presentation/Requests/CreateUserRequest.php
apps/api/app/Modules/Identity/Presentation/Requests/UpdateUserRequest.php
apps/api/database/seeders/DemoPharmacySeeder.php
apps/api/database/factories/BatchExpiry/BatchFactory.php
```

Exact frontend/docs files:

```text
apps/web/src/routes/index.tsx
apps/web/src/components/organisms/Sidebar/Sidebar.tsx
apps/web/src/features/batches/pages/BatchListPage.tsx
apps/web/src/features/batches/pages/BatchDetailPage.tsx
apps/web/src/features/batches/api/batches.ts
apps/web/src/features/batches/hooks/useBatches.ts
apps/web/src/features/batches/types.ts
apps/web/src/hooks/permissionsMap.generated.ts
apps/web/src/locales/en/batches.json
apps/web/src/locales/fr/batches.json
packages/shared/types/generated.d.ts
docs/glossary.md
```

Push-5 settle order:

1. Deploy with flag false.

2. Verify read scoping.

3. Verify action permissions.

4. Verify issue enforcement.

5. Verify capability false.

6. Deploy web explicitly.

7. Verify served marker.

8. Enable flag in worker/API consistent order.

9. Clear config cache/restart as required by manifest.

10. Read flag back.

11. Verify capability true.

12. Run API/browser smoke.

## Push-3 inertness capture

Before and after Push 3, capture the same authenticated requests for:

- A1 manager list.

- A1 manager detail.

- A2-scoped detail.

- Company-B foreign detail.

- Existing authorized recall.

- Existing authorized delete.

Normalize only volatile headers/timestamps.

Compare:

```bash
cmp -s pre-push3.json post-push3.json
```

Emit only after every comparison passes:

```text
WLOTA1-PUSH3-INERT outcome=PASS
```

Any body/status/stock/evidence delta fails Push 3.

## Exact Push-4 commands

Inside API container:

```bash
php artisan tenants:run permissions:apply-lot-recall-delta \
  --option='apply=1'
```

```bash
php artisan tenants:run db:seed \
  --option='class=Database\Seeders\RolesAndPermissionsSeeder' \
  --option='force=1'
```

```bash
php artisan tenants:run permission:cache-reset
```

```bash
php artisan tenants:run permissions:apply-lot-recall-delta \
  --option='verify=1'
```

```bash
php artisan tenants:run permissions:apply-lot-recall-delta \
  --option='apply=1'
```

Required markers:

```text
WLOTA1-PERMISSIONS tenant=<uuid> outcome=APPLIED ...
```

or first-run already state:

```text
WLOTA1-PERMISSIONS tenant=<uuid> outcome=ALREADY_APPLIED ...
```

Reseed:

```text
WLOTA1-RESEED tenant=<uuid> outcome=APPLIED|ALREADY_APPLIED ...
```

Cache reset:

```text
Permission cache flushed.
```

Verify:

```text
WLOTA1-PERMISSIONS tenant=<uuid> outcome=ALREADY_APPLIED ...
```

Second apply:

```text
WLOTA1-PERMISSIONS tenant=<uuid> outcome=ALREADY_APPLIED ...
```

Reject:

- FAILED.

- Missing tenant.

- Duplicate unexpected tenant verdict.

- SKIPPED during apply/verify.

- Cache marker cardinality mismatch.

- Nonzero direct per-tenant child exit.

Because `tenants:run` discards child exit status, stdout cardinality is mandatory; the manifest documents this at [manifest:36](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:36).

## Local generation commands

Not under `tenants:run`:

```bash
(cd apps/api && php artisan typescript:transform)
```

```bash
(cd apps/api && php artisan permissions:export-frontend-map)
```

Expected generated files:

```text
packages/shared/types/generated.d.ts
apps/web/src/hooks/permissionsMap.generated.ts
```

Run twice and compare bytes.

## Host-owned P1 census artifact contract

Set on the staging host:

```bash
export WLOTA1_ARTIFACT_ROOT=/root/wlota1-permissions-hold
```

Create immutable run directory:

```bash
export WLOTA1_P1_DIR="$WLOTA1_ARTIFACT_ROOT/p1-$CANDIDATE_SHA"
mkdir -p "$WLOTA1_P1_DIR"
test -d "$WLOTA1_P1_DIR"
```

After running P1 in the API container:

```bash
docker cp "$API_CTR:/tmp/wlota1-permissions-hold-tenant-ids-baseline.txt" \
  "$WLOTA1_P1_DIR/tenant-ids-baseline.txt"
```

```bash
docker cp "$API_CTR:/tmp/wlota1-permissions-hold-vat-p1.tsv" \
  "$WLOTA1_P1_DIR/vat-p1.tsv"
```

```bash
docker cp "$API_CTR:/tmp/wlota1-permissions-hold-phantom-p1.txt" \
  "$WLOTA1_P1_DIR/phantom-p1.txt"
```

Copy all logs:

```bash
docker cp "$API_CTR:/tmp/." "$WLOTA1_P1_DIR/container-tmp"
```

Create checksums:

```bash
(
  cd "$WLOTA1_P1_DIR"
  find . -type f -print0 |
    sort -z |
    xargs -0 sha256sum > SHA256SUMS
)
```

Verify:

```bash
(
  cd "$WLOTA1_P1_DIR"
  sha256sum -c SHA256SUMS
)
```

Required host marker:

```text
WLOTA1-P1-ARTIFACT outcome=PASS sha=<candidate-sha>
```

P1 files left only in container `/tmp` do not satisfy the gate.

## Restore P1 artifacts before P4

Verify checksums again:

```bash
(
  cd "$WLOTA1_P1_DIR"
  sha256sum -c SHA256SUMS
)
```

Restore:

```bash
docker cp "$WLOTA1_P1_DIR/tenant-ids-baseline.txt" \
  "$API_CTR:/tmp/wlota1-permissions-hold-tenant-ids-baseline.txt"
```

```bash
docker cp "$WLOTA1_P1_DIR/vat-p1.tsv" \
  "$API_CTR:/tmp/wlota1-permissions-hold-vat-p1.tsv"
```

```bash
docker cp "$WLOTA1_P1_DIR/phantom-p1.txt" \
  "$API_CTR:/tmp/wlota1-permissions-hold-phantom-p1.txt"
```

Confirm inside container:

```bash
docker exec "$API_CTR" test -s \
  /tmp/wlota1-permissions-hold-tenant-ids-baseline.txt
```

```bash
docker exec "$API_CTR" test -s \
  /tmp/wlota1-permissions-hold-vat-p1.tsv
```

```bash
docker exec "$API_CTR" test -s \
  /tmp/wlota1-permissions-hold-phantom-p1.txt
```

Emit:

```text
WLOTA1-P1-RESTORE outcome=PASS
```

## Executable census gate

Run inside API container with:

```bash
export SLICE=wlota1-permissions-hold
export PHASE=p1
```

Change only `PHASE=p4` after Push 4.

```bash
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
    --option='fail-on-drift=1' 2>&1 |
    tee "$day_log"

  test "$(grep -Ec "^Tenant: ${tenant}$" "$day_log")" -eq 1

  test "$(grep -Ec "^DAY-ONE CENSUS ${tenant} [0-9a-f-]{36}: CLEAN$" "$day_log")" -ge 1

  ! grep -Eq 'DRIFT\(|NO-COMPANY|FAILED|Exception|Error' "$day_log"

  day_pass=$((day_pass + 1))

  vat_log="/tmp/${SLICE}-vat-${PHASE}-${tenant}.log"

  php artisan tenants:run pos:census-vat-legs \
    --tenants="$tenant" 2>&1 |
    tee "$vat_log"

  test "$(grep -Ec "^Tenant: ${tenant}$" "$vat_log")" -eq 1

  ! grep -Fq 'Chart of accounts is not provisioned for POS:' "$vat_log"

  vat_clean="$(grep -Fc \
    'POS output-VAT leg census: none — every POS receipt carries its sealed output VAT in the ledger.' \
    "$vat_log" || true)"

  vat_drift_lines="$(grep -Ec \
    '^POS output-VAT leg census: [0-9]+ receipt\(s\)' \
    "$vat_log" || true)"

  test $((vat_clean + vat_drift_lines)) -eq 1

  if [ "$vat_clean" -eq 1 ]; then
    vat_count=0
  else
    vat_count="$(sed -nE \
      's/^POS output-VAT leg census: ([0-9]+) receipt\(s\).*/\1/p' \
      "$vat_log")"

    test -n "$vat_count"
  fi

  printf '%s\t%s\n' "$tenant" "$vat_count" \
    >> "/tmp/${SLICE}-vat-${PHASE}.tsv"

  vat_pass=$((vat_pass + 1))

  lot_log="/tmp/${SLICE}-lot-${PHASE}-${tenant}.log"

  php artisan inventory:lot-drift-census \
    --tenant="$tenant" \
    --fail-on-drift 2>&1 |
    tee "$lot_log"

  test "$(grep -Fc 'Tuples drifted: 0' "$lot_log")" -eq 1

  test "$(grep -Fc 'Read-only census: nothing was written.' "$lot_log")" -eq 1

  ! grep -Eq '^[[:space:]]+DRIFT ' "$lot_log"

  ! grep -Fq 'BATCH_HELD_PROJECTION_CONFLICT' "$lot_log"

  lot_pass=$((lot_pass + 1))

  phantom_log="/tmp/${SLICE}-phantom-${PHASE}-${tenant}.log"

  php artisan inventory:repair-phantom-default-batches \
    --tenant="$tenant" \
    --dry-run 2>&1 |
    tee "$phantom_log"

  test "$(grep -Fc \
    'Dry run: nothing was written. Re-run with --execute to apply.' \
    "$phantom_log")" -eq 1

  summary_count="$(grep -Ec \
    '^(DEFAULT lots inspected|Phantom DEFAULT lots|Total phantom quantity|Reservations re-pointed to real lots|Reservations left on the DEFAULT lot|Lots only partially reduced|Lots skipped \(excess changed under the lock\)|Tuples still drifted): ' \
    "$phantom_log")"

  test "$summary_count" -eq 8

  printf 'tenant=%s\n' "$tenant" \
    >> "/tmp/${SLICE}-phantom-${PHASE}.txt"

  grep -E \
    '^(DEFAULT lots inspected|Phantom DEFAULT lots|Total phantom quantity|Reservations re-pointed to real lots|Reservations left on the DEFAULT lot|Lots only partially reduced|Lots skipped \(excess changed under the lock\)|Tuples still drifted): ' \
    "$phantom_log" \
    >> "/tmp/${SLICE}-phantom-${PHASE}.txt"

  phantom_pass=$((phantom_pass + 1))
done < "$tenant_current"

test "$day_pass" -eq "$expected"
test "$vat_pass" -eq "$expected"
test "$lot_pass" -eq "$expected"
test "$phantom_pass" -eq "$expected"

sort -o "/tmp/${SLICE}-vat-${PHASE}.tsv" \
  "/tmp/${SLICE}-vat-${PHASE}.tsv"

if [ "$PHASE" = p4 ]; then
  cmp -s \
    "/tmp/${SLICE}-vat-p1.tsv" \
    "/tmp/${SLICE}-vat-p4.tsv"

  cmp -s \
    "/tmp/${SLICE}-phantom-p1.txt" \
    "/tmp/${SLICE}-phantom-p4.txt"
fi

echo "WLOTA1-CENSUS kind=day-one phase=${PHASE} outcome=PASS tenants=${expected}"

echo "WLOTA1-CENSUS kind=vat-legs phase=${PHASE} outcome=PASS tenants=${expected}"

echo "WLOTA1-CENSUS kind=lot-drift phase=${PHASE} outcome=PASS tenants=${expected}"

echo "WLOTA1-CENSUS kind=phantom-default phase=${PHASE} outcome=PASS tenants=${expected}"
BASH
```

## Census exit rule

The gate passes only when:

- Script exit is zero.

- All four PASS markers exist exactly once.

- Every marker carries the same nonzero tenant cardinality.

- Current tenant IDs equal the restored P1 baseline.

- Every tenant header appears once per command log.

- Day-one is CLEAN.

- Lot drift reports zero tuples.

- No drift row exists.

- No held-projection conflict exists.

- VAT normalized counts equal P1.

- Phantom normalized summaries equal P1.

The operator may not overwrite P1 artifacts to make P4 pass.

A changed baseline requires a separately reviewed decision.

## Host backup contract

Before Push 2 and Push 4, for every affected tenant:

```bash
docker exec "$PG_CTR" pg_dump \
  -Fc \
  -U autoerp \
  -d "tenant_${tenant_uuid}" \
  > "/root/backup-wlota1-permissions-hold-${tenant_uuid}-$(date -u +%Y%m%dT%H%M%SZ).dump"
```

Verify:

```bash
test -s "/root/backup-wlota1-permissions-hold-${tenant_uuid}-${timestamp}.dump"
```

Container-only backup is invalid because the API container has no durable backup volume; the manifest warns about this at [manifest:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:37).

## Web deployment verification

Web requires explicit deployment because its staging app is not auto-deployed, as documented at [manifest:38](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:38).

Capture before asset hash.

Deploy the web application explicitly.

Capture after asset hash.

Require hash change.

Fetch served entry asset.

Require:

```bash
grep -Fc 'wlota1-recall-request-v5' served-entry.js
```

Expected:

```text
count >= 1
```

Browser smoke:

- Viewer can view batches.

- Viewer cannot mutate or trace.

- Operator can view batches.

- Manager can request recall.

- Manager cannot company-recall.

- Unrestricted general manager can company-recall.

- Restricted custom recall actor cannot company-recall.

## Per-push rollback

P1:

- No commit to revert.

- Retain host artifacts.

P2:

- Leave additive schemas.

- Correct forward.

- Roll down only if both tables contain no new marker/evidence.

P3:

- Flag remains false.

- U-6 remains false.

- Unwired code may be reverted before Push 5.

- Do not remove schemas needed by later evidence.

P4:

- Preserve role marker.

- Preserve before-state report.

- Correct targeted grants forward.

- Never blanket-sync.

- Never automatically regrant manager recall.

P5:

- Set flag false using the manifest’s worker-before-API/config-settle sequence.

- Roll back web artifact if needed.

- Keep permissioned/location-scoped reads.

- Keep canonical issue eligibility.

- Keep global recall safety.

- Keep evidence schema/triggers.

- Never deploy an image that ignores existing holds.

---

# Dispatch order

1. Task 1 — API permissions, read visibility, and Shared trace readers.

2. Task 2 — role delta, provenance, and guarded assignment.

3. Task 3 — immutable request/transition/global evidence and capability contract.

4. Task 4 — canonical eligibility, company-aware FEFO, delivery/transfer/POS enforcement.

5. Task 5 — sole lifecycle writer, global recall evidence, transitions, and race proof.

6. Task 6 — generated contracts, permissions, and existing-detail UI.

Dispatch rules:

- One task at a time.

- Start from the recorded dependency SHA.

- Capture assertion-red output before production edits.

- Do not count setup exceptions as red.

- Run both reviewer gates per task.

- Do not activate request authoring before Tasks 3–5 pass PostgreSQL.

---

# Final verification checklist

## Baseline and scope

- [ ] Implementation start SHA recorded.

- [ ] Cited seams re-read if SHA changed.

- [ ] Six tasks or fewer.

- [ ] No release/reject implementation.

- [ ] No device build.

- [ ] No second recall surface.

## Permissions

- [ ] Every batch route has its action permission.

- [ ] Existing module/company checks remain.

- [ ] Viewer/operator receive batch view only.

- [ ] Manager loses company-wide recall.

- [ ] Manager gains recall request.

- [ ] General manager receives recall and Treasury all-location authority.

- [ ] Custom roles remain permission-based.

## Read scope

- [ ] `expiring.location_id` validates UUID.

- [ ] List/detail/stock/product-stock/POS suggestions are scoped.

- [ ] Forward/backward trace is scoped.

- [ ] Empty scope returns no data.

- [ ] Unrestricted zero-stock company lot remains visible.

- [ ] Restricted zero-stock/no-history lot is hidden.

- [ ] Nullable-location trace is unrestricted-company only.

- [ ] Resource totals use scoped decimal strings.

## Module boundaries

- [ ] BatchExpiry imports no Document model.

- [ ] BatchExpiry imports no POS model.

- [ ] Document adapter implements Shared contract.

- [ ] POS adapter implements Shared contract.

- [ ] Architecture boundary test is live.

## Role provisioning

- [ ] Role marker schema is exact.

- [ ] Role collision fails without mutation.

- [ ] First run creates one marked role.

- [ ] Second run is ALREADY_APPLIED.

- [ ] Custom grants survive.

- [ ] Create writes membership before role.

- [ ] Update validates merged final state.

- [ ] Dedicated role endpoint uses same guard.

- [ ] Later narrowing is rejected.

- [ ] Atomic demotion plus narrowing succeeds.

## Evidence schema

- [ ] Root namespace is separate.

- [ ] Transition namespace is separate.

- [ ] Global namespace is separate.

- [ ] Terminal unique is status-independent.

- [ ] Global batch evidence is unique.

- [ ] Actor FK exists.

- [ ] Company FK exists.

- [ ] Batch FK exists.

- [ ] Location FK exists where applicable.

- [ ] Parent FK exists for transitions.

- [ ] Trigger verifies batch/company/tenant.

- [ ] Trigger verifies location/company.

- [ ] Trigger verifies actor/tenant.

- [ ] Trigger verifies actual stock tuple.

- [ ] Trigger verifies parent tuple.

- [ ] UPDATE is rejected.

- [ ] DELETE is rejected.

- [ ] `down()` refuses with evidence.

## Idempotency

- [ ] Matching request replay returns original ID.

- [ ] Request mismatch returns 409.

- [ ] Matching global recall replay preserves evidence.

- [ ] Global mismatch returns 409.

- [ ] Same UUID may exist once in each namespace.

- [ ] Adversarial precomputed-transition collision passes.

- [ ] One unresolved root per tuple is enforced.

- [ ] One terminal child per root is enforced.

## Canonical eligibility

- [ ] Exact five reasons exist.

- [ ] Reason order is deterministic.

- [ ] Company ID is explicit in atomic FEFO.

- [ ] Tenant/company predicates are in SQL.

- [ ] Source revision covers every fact.

- [ ] Identical rerun preserves revision.

- [ ] Physical/reserved/eligible quantities are strings.

- [ ] Held row remains visible with eligible quantity zero.

- [ ] No consumer reimplements the predicate.

## Issue paths

- [ ] POS projection uses canonical decision.

- [ ] Direct transfer uses canonical decision.

- [ ] StockTransfer automatic allocation uses canonical decision.

- [ ] StockTransfer explicit allocation uses canonical decision.

- [ ] Delivery automatic FEFO uses canonical decision.

- [ ] Delivery explicit batch uses canonical decision.

- [ ] Write-off bypass is explicitly typed.

- [ ] Count correction bypass is explicitly typed.

- [ ] No other bypass exists.

## Exception contracts

- [ ] `BatchHeldException` exists.

- [ ] POS suggestion maps 422 `BATCH_HELD`.

- [ ] Direct transfer maps 422 `BATCH_HELD`.

- [ ] StockTransfer maps 422 `BATCH_HELD`.

- [ ] Delivery confirmation maps 422 `BATCH_HELD`.

- [ ] POS projection emits `BATCH_HELD_PROJECTION_CONFLICT`.

- [ ] Sealed fiscal evidence is retained.

- [ ] No held-lot movement is written.

## Locking and races

- [ ] Request locks batch before stock.

- [ ] Recall locks batch before all company stock rows.

- [ ] Recall stock rows are stable-ID ordered.

- [ ] Recall changes projection only after stock locks.

- [ ] Recall scans roots only after stock locks.

- [ ] Every issue path locks batch before batch stock.

- [ ] Multi-batch locks are stable.

- [ ] Request-first race transitions the root.

- [ ] Recall-first race rejects the late request.

- [ ] No race loses the hold.

- [ ] Every barrier test completes within deadline.

- [ ] No deadlock error.

- [ ] No post-recall movement.

## Writer census

- [ ] Entity recall mutator removed.

- [ ] Repository recall mutator removed.

- [ ] Recall fields removed from fillable.

- [ ] Generic update rejects recall fields.

- [ ] Generic create accepts defaults only.

- [ ] Demo reseed cannot clear evidence.

- [ ] Production writer ratchet passes.

- [ ] Test-fixture census passes.

- [ ] Liveness fixtures prove both scanners.

## Frontend

- [ ] Generated DTO declarations are current.

- [ ] Generated permission map is current.

- [ ] Generation rerun is byte-identical.

- [ ] Batch routes use exact permissions.

- [ ] Sidebar requires batch view.

- [ ] Unauthorized trace query is disabled.

- [ ] Capability key is tenant scoped.

- [ ] Capability failure is closed.

- [ ] Selector uses scoped active locations.

- [ ] Selector uses positive physical quantity.

- [ ] Fully reserved physical stock remains requestable.

- [ ] Request UUID persists through retry.

- [ ] Global recall UUID persists through retry.

- [ ] Company switch clears pending state.

- [ ] Request/transition/global reasons render separately.

- [ ] English translations exist.

- [ ] French translations exist.

- [ ] Feature marker exists in served entry.

## Convention 09

- [ ] Task 1 second-company evidence.

- [ ] Task 1 second-location evidence.

- [ ] Task 1 rerun evidence.

- [ ] Task 2 second-company evidence.

- [ ] Task 2 second-location evidence.

- [ ] Task 2 rerun evidence.

- [ ] Task 3 second-company evidence.

- [ ] Task 3 second-location evidence.

- [ ] Task 3 rerun evidence.

- [ ] Task 4 second-company evidence.

- [ ] Task 4 second-location evidence.

- [ ] Task 4 rerun evidence.

- [ ] Task 5 second-company evidence.

- [ ] Task 5 second-location evidence.

- [ ] Task 5 rerun evidence.

- [ ] Task 6 second-company evidence.

- [ ] Task 6 second-location evidence.

- [ ] Task 6 rerun evidence.

- [ ] `product_batches` added to catalogue constants.

- [ ] Evidence table explicitly classified.

- [ ] No waiver or ceiling increase.

## Convention 11

- [ ] Recall request glossary row added.

- [ ] Branch hold glossary row added.

- [ ] Recall transition glossary row added.

- [ ] Global recall evidence glossary row added.

- [ ] General manager glossary row added.

- [ ] `BatchRecallService` named as sole writer.

- [ ] Existing batch detail named as sole surface.

- [ ] Synonyms declared.

- [ ] No handwritten shadow DTO.

## Deployment

- [ ] U-1 closed.

- [ ] U-2 closed.

- [ ] U-6 false marker captured before Push 3.

- [ ] Push-3 exact file list respected.

- [ ] Push-3 inert comparison passes.

- [ ] P1 artifacts copied to host.

- [ ] P1 checksums pass.

- [ ] P1 artifacts restored before P4.

- [ ] Tenant cardinality unchanged.

- [ ] Permission apply markers complete.

- [ ] Reseed markers complete.

- [ ] Cache-reset markers complete.

- [ ] Verify markers complete.

- [ ] Second apply is ALREADY_APPLIED.

- [ ] Day-one census PASS.

- [ ] VAT census PASS.

- [ ] Lot-drift census PASS.

- [ ] Phantom census PASS.

- [ ] Host backups are nonzero.

- [ ] Web deployed explicitly.

- [ ] Served marker count is at least one.

- [ ] Flag false settle verified.

- [ ] Flag true readback verified.

- [ ] Viewer smoke passes.

- [ ] Manager smoke passes.

- [ ] General-manager smoke passes.

## Final reviewer gate

- [ ] tenancy-authz-reviewer approves every task.

- [ ] inventory-costing-reviewer approves every task.

- [ ] PostgreSQL lane is green.

- [ ] SQLite portability lane is green where specified.

- [ ] Vitest is green.

- [ ] React diagnostics are green.

- [ ] Web typecheck is green.

- [ ] Web lint is green.

- [ ] Applicable Playwright journey is green.

- [ ] `./scripts/preflight.sh` is green with nonempty covering paths.

- [ ] No required test is skipped without recorded reason.

- [ ] No migration, permission, evidence, stock, or deployment claim is marked complete without captured evidence.