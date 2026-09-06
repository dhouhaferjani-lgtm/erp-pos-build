<!-- Rev 2, authored by Codex CLI (gpt-5.6-sol, high, read-only) on 2026-09-06 from rev 1 + plan gate r2; filed verbatim by the orchestrator. Status: awaiting plan gate r3. Rev 1 is in git history (67c0805d4). -->
# W-LOT Batch Management Execution Plan — Revision 2

**Status:** Dispatch-ready plan; implementation not started  
**Plan date:** 2026-09-06  
**Verified repository:** `/Users/houssamr/Projects/syneriva/apps/erp`  
**Verified local `dev` HEAD:** `6e17a76022c5ccd8864afdf98cd5302e5264176b`  
**Supersedes:** `docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md` revision 1  
**Authority:** `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md` v4  
**Owner register:** `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md`  
**Gate:** `docs/superpowers/reviews/2026-09-06-w-lot-plan-codex-gate-r2.md`

---

## 0. Revision-2 change log and gate closure

No gate-r2 finding is rejected.

| Finding | Rev-2 closure |
|---|---|
| BLOCKER — owner register | §2 copies Q10–Q13 verbatim from `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:138-143`, marks all four OPEN, and makes every dependent branch conditional. |
| BLOCKER — aggregate movement linkage | §6.8 makes `aggregate_stock_movement_id` mandatory on every projection lot obligation. Tasks 18 and 21 persist and verify the exact `stock_movements.id` created for that receipt line. |
| BLOCKER — canonical line key | §5 defines versioned grammar, shared vectors, PHP/TypeScript implementations, and duplicate-product, evidence-reorder, retry, and mixed-version tests. |
| BLOCKER — writer/lock census | §8 contains the pre-dispatch census, including `StockReservationService.php:507,631`, all `BatchStock` mutators at `BatchStock.php:54-84`, transaction ownership, predicates, order, scope, and hold-time limits. Task 1 only ratchets this completed census. |
| BLOCKER — deployment order | §11 is an explicit five-push manifest: preflight command first, all additive migrations second, dormant code third, backfill/verification fourth, and activation fifth, with exact commands and rollback points. |
| BLOCKER — dispatch packets | §10 gives all 24 tasks exact files, signatures/schema impact, red-first file/class/case/assertion/command/lane, implementation instructions, Convention-09 disposition, and reviewer gate. |
| MAJOR — L9 permissions | §4 and Tasks 7–9 seed `batches.identify` and `batches.correct-identity` to `admin` only. Additional assignment uses ordinary role management. |
| MAJOR — float retirement | Task 6 includes reservation mutation lines and every live transitive reservation consumer found at HEAD. |
| MAJOR — provenance seams | Tasks 13–14 include both delivery-note conversion branches at `SalesOrderToDeliveryNoteConverter.php:363-379,501-517`, `DeliveryNoteFromDocumentFactory.php:137-153`, reservation propagation, POS, transfers, receipts, and returns. |
| MAJOR — POS renderer census | Task 17 covers the active `ProductCard`, `ProductListRow`, `ProductTable`, `ProductDetailDrawer`, `BarcodeChooserModal`, and `NearExpirySlot` paths, and removes the unreferenced legacy grid/card pair. |
| MAJOR — schedule safety | Task 15 schedules at 03:20, avoiding the existing 02:15, 02:30, 02:45, and 03:00 jobs in `apps/api/routes/console.php:118-120,250-259,277-280`; overlap expiry is 180 minutes, with failure notification and stale-run handling. |
| MAJOR — vocabulary | §3 distinguishes Branch lot hold, Company lot recall, and Lot identity correction and assigns each one canonical writer and operator surface. |
| MINOR — reproducible baseline | This plan declares full HEAD `6e17a76022c5ccd8864afdf98cd5302e5264176b` and cites the live batch uniqueness successor at `apps/api/database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php:27-31`. |

### Gate-r1 closure table carried through gate r2

| # | R1 finding | Rev-2 status | Closure |
|---|---|---|---|
| 1 | Mechanical dispatch gate | **CLOSED** | Every task has a complete dispatch contract in §10. |
| 2 | Recall escalation and roles | **CLOSED** | Local branch hold and company recall are separate; Q10 release remains conditional and unimplemented. |
| 3 | POS core versus lot arm; late evidence | **CLOSED** | Always-on aggregate posting, mandatory aggregate movement linkage, deterministic line keys, and durable recovery are specified. |
| 4 | Lock census too late | **CLOSED** | Complete census is in §8 before dispatch. |
| 5 | Unsafe deployment order | **CLOSED** | Five-push manifest in §11 separates preflight, schema, dormant code, backfill, and activation. |
| 6 | Freeze before replacement | **CLOSED** | Existing writers remain active until replacement tests pass; activation is one final gate. |
| 7 | Cross-layer float retirement | **CLOSED** | Task 6 includes entity mutators, reservations, interfaces, commands, and transitive callers. |
| 8 | Incomplete L9 identity correction | **CLOSED** | Full schema/service/API/audit/UI slice; permissions are administrator-only by default. |
| 9 | Flat-row counting model | **CLOSED** | Counting retains flat aggregate rows plus explicit lot observations and reconciliation legs. |
| 10 | Producer provenance and health | **CLOSED** | Complete producer seams and safe durable health schedule are specified. |
| 11 | Refresh before POS open | **CLOSED** | Opening a shift requires an acknowledged current entitlement/snapshot revision. |
| 12 | Durable outbox | **CLOSED** | SQLite and server states, retry, dead-letter, replay, and deduplication are explicit. |
| 13 | L8/L9 completion | **CLOSED** | L8 and L9 have concrete acceptance tasks; oversell override remains deferred. |
| 14 | Citation hygiene | **CLOSED** | Full current SHA, live migration, actual module paths, and current renderer paths are used. |
| 15 | Oversized S5/S6 units | **CLOSED** | Work remains split into independently reviewable schema, writer, API/UI, device, recovery, and activation tasks. |

---

## 1. Non-negotiable outcome and scope

W-LOT delivers:

1. A branch request that immediately places the named lot under a branch-local hold.
2. A general-manager action that escalates a request into a company-wide recall.
3. Evidence-backed lot identity correction and DEFAULT/unknown-lot identification.
4. Exact-decimal lot quantities through backend, API, web, reservation, and POS paths.
5. Lot-grain physical counting reconciled to the existing flat aggregate count.
6. Explicit provenance for all three allocation producer families.
7. Durable aggregate-versus-lot health monitoring.
8. POS FEFO guidance and captured lot evidence without changing sealed fiscal payload versions.
9. An always-on POS aggregate stock effect plus an entitlement-gated, durable lot obligation linked to the exact aggregate movement.
10. Complete tenant/company/location isolation, idempotency, and PostgreSQL concurrency proof.

Out of scope:

- The separately approved oversell override.
- A new standalone lot catalogue.
- A fiscal-event version bump.
- Treating FEFO estimates as captured physical evidence.
- Q10 release/rejection behavior until the owner rules.
- Q11–Q13 cash/drawer behavior.
- Automatic repair of lot-ledger drift.
- Retroactive mutation of sealed fiscal payloads.

---

## 2. Owner register

All four rows are **OPEN verbatim; no task implements them**.

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

Consequences:

- Implemented recall transitions are only `requested → recalled`.
- A requested row continues imposing its branch-local hold until company recall.
- No `released` or `rejected` endpoint, button, service method, transition, permission, or migration enum value is activated.
- A future Q10-approved change may add `requested → released` exactly as ruled, but is conditional and is not part of the five pushes below.
- Q11–Q13 remain W-CASH dependencies and do not alter W-LOT code, schema, or activation.
- The oversell override remains OPEN/deferred and requires a separate approval.

---

## 3. Canonical vocabulary — Convention 11

`docs/glossary.md:41-43,58` already defines Lot (batch), Lot evidence, Lot identification, and Projection lot obligation. Task 1 reconciles them with these exact canonical additions or expansions:

| Canonical term | Meaning | Canonical table/module | Primary writer | One operator surface | Forbidden ambiguity |
|---|---|---|---|---|---|
| **Branch lot hold** | A location-scoped issue prohibition created immediately by a branch recall request. | `batch_recall_requests` / BatchExpiry | `BatchRecallWorkflowService::request()` | Recall panel in existing batch detail | Never call this a company recall. |
| **Company lot recall** | A company-wide prohibition created when a general manager executes a requested recall. | `batch_recall_transitions`; compatibility projection on `product_batches.is_recalled` | `BatchRecallWorkflowService::recall()` | Existing batch detail/worklist | Never call the branch-local state a recall. |
| **Lot identity correction** | An audited metadata correction that does not change quantity, value, custody, or the physical lot represented. | `batch_identity_corrections` / BatchExpiry | `BatchIdentityCorrectionService::correct()` | Existing batch detail correction dialog | Not a lot identification/split. |
| **Lot identification** | Evidence-backed reattribution of DEFAULT/unknown holding into identified lots while conserving aggregate quantity and value. | `lot_identifications`, `lot_identification_lines` | `LotIdentificationService::identify()` | Existing batch detail identify dialog | Not ordinary relabeling. |
| **Lot count observation** | An explicit physical quantity for one lot in one counting phase; `0.0000` differs from missing. | `inventory_counting_item_lots` | `InventoryCountingService::submitLotObservations()` | Existing count detail | Not a second count document. |
| **Lot eligibility snapshot** | Replace-all terminal/location cache used for FEFO guidance; never stock authority. | Server DTO and SQLite `branch_lot_snapshots`, `branch_lot_eligibility` | Server `PosLotEligibilityService`; device `replaceLotEligibilitySnapshot()` | Existing POS product/cart/detail surfaces | Not captured lot evidence. |
| **Lot evidence** | Captured or inferred provenance for a specific allocation. | Existing allocation tables plus server/device sidecars | Declared producer or `LotEvidenceIngressService` | Existing trace/detail/export surfaces | `system_fefo_estimate` may never render as captured. |
| **Projection lot obligation** | Durable pending/blocked/applied record for the lot arm of an accepted fiscal line, linked to its aggregate movement. | `fiscal_projection_lot_obligations` | `PosReceiptLotProjectionService` | Existing projection/receipt health surface | Not a second aggregate stock effect. |
| **Lot-ledger census run** | Durable non-repairing comparison of aggregate stock and summed lots. | `lot_ledger_census_runs` | `LotLedgerDriftMonitorService` | Existing batches health banner | Not an inventory adjustment. |

No second list, catalogue, import type, inventory authority, or recovery workflow is introduced.

---

## 4. Permissions, authority, and entitlement

### 4.1 Permissions

| Permission | Default roles | Scope |
|---|---|---|
| `batches.view` | Existing grants | Tenant/company/location policy unchanged. |
| `batches.request-recall` | Branch manager and general manager | Requesting user must have active membership and custody of requesting location. |
| `batches.recall` | General manager and admin | Company-wide recall; API rechecks company membership. |
| `batches.identify` | **Admin only** | L9 identification/split. |
| `batches.correct-identity` | **Admin only** | Identity-only correction. |
| `batches.count-lots` | Existing inventory count authority | Location custody and count assignment required. |
| `batches.traceability` | Existing grants | Provenance viewing/export. |
| `batches.health` | Admin and general manager | Census health and unresolved obligations. |
| `pos.capture-lot-evidence` | Cashier roles already authorized to sell | Bound to terminal, company, and location. |

`apps/api/database/seeders/RolesAndPermissionsSeeder.php:403-409,547-561,631-632` remains the canonical role seed. The tenant permission migration grants `batches.identify` and `batches.correct-identity` only to `admin`. Additional staff may receive them only through existing role management; no `general_manager` default grant is added.

### 4.2 Tri-state entitlement

```php
enum CompanyEntitlementState: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Unresolved = 'unresolved';
}

interface CompanyEntitlementResolver
{
    public function resolve(string $tenantId, string $companyId, string $entitlement): CompanyEntitlementDecision;
}
```

Exact file: `apps/api/app/Shared/Contracts/Company/CompanyEntitlementResolver.php`.

Rules:

- `Enabled`: W-LOT authoring and lot projection may run.
- `Disabled`: no W-LOT authoring; always-on POS aggregate posting still runs.
- `Unresolved`: fail closed for authoring and create a blocked lot obligation where an accepted fiscal event needs a lot effect.
- No vertical fallback may silently convert `Unresolved` to `Disabled`.
- Decisions include `revision`, `resolved_at`, and source.
- A POS shift may open only after refresh and acknowledgement of the current revision.

Existing configuration seams verified at HEAD:

- `apps/api/app/Services/CompanyConfigService.php`
- `apps/api/app/Services/VerticalConfigService.php`
- `apps/api/app/Http/Controllers/Api/Admin/VerticalConfigController.php`
- `apps/api/app/Observers/TenantObserver.php`
- `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php:31,110-111,185`

### 4.3 Rollout switches

Exact new file: `apps/api/config/lot_rollout.php`.

```text
LOT_ROLLOUT_RECALL_WORKFLOW
LOT_ROLLOUT_IDENTITY_CORRECTION
LOT_ROLLOUT_LOT_COUNTING
LOT_ROLLOUT_PROVENANCE
LOT_ROLLOUT_CENSUS_SCHEDULER
LOT_ROLLOUT_POS_GUIDANCE
LOT_ROLLOUT_POS_EVIDENCE_AUTHORING
LOT_ROLLOUT_POS_EVIDENCE_PROJECTION
```

Every switch defaults to `false`. The POS aggregate receipt projection has no W-LOT switch and remains active.

---

## 5. Versioned canonical receipt-line identity

### 5.1 Grammar

Shared vector file:

`packages/shared/contracts/w-lot-canonical-line-key-v1.json`

Implementations:

- `apps/api/app/Modules/Fiscal/Domain/Services/CanonicalReceiptLineKey.php`
- `apps/pos/src/lib/fiscal/canonicalReceiptLineKey.ts`

Signature:

```php
final class CanonicalReceiptLineKey
{
    public static function forPayloadIndex(int $eventVersion, int $zeroBasedIndex): string;
}
```

```ts
export function canonicalReceiptLineKey(
  eventVersion: number,
  zeroBasedIndex: number,
): string;
```

ASCII output:

```text
sr-line:v1:event-v<event_version>:i<zero-based-index-padded-to-six-digits>
```

Example:

```text
sr-line:v1:event-v5:i000003
```

The index is the exact immutable position in the canonical signed `payload.line_items[]` array after canonical payload construction and before append. Current positional behavior is visible at `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1175-1207`; `apps/pos/src/lib/fiscal/payloads/SaleReceiptV2Payload.ts:35-50` preserves positional line arrays.

The key must never derive from:

- product or variant ID;
- mutable cart order before payload canonicalization;
- database receipt-line order;
- evidence batch order;
- batch ID or number;
- discounts;
- server insertion order.

Server ingress recomputes the key from `FiscalEvent.event_version` and `canonical_line_index`, verifies that the referenced signed line contains the submitted product/variant identity, and rejects mismatches. Evidence reordering does not change a line key. Reordering signed payload lines creates a different fiscal event/hash and therefore cannot mutate an existing event.

Required vectors include:

- two identical product lines at indexes 0 and 1;
- the same evidence batches in reversed order;
- an exact retry;
- event versions 1 through 5;
- index boundaries 0, 9, 99, 999999;
- invalid negative and greater-than-999999 indexes.

---

## 6. Schema contract

All tenant migrations are additive and ship together in push 2. The current effective product-batch uniqueness is:

- non-variant: `(company_id, product_id, batch_number)` where `variant_id IS NULL`;
- variant: `(company_id, product_id, variant_id, batch_number)` where `variant_id IS NOT NULL`;

as implemented by `apps/api/database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php:27-31`.

SQLite currently ends at v67 in `apps/pos/src/lib/db/migrations.ts:2163`; v68 and v69 are available.

### 6.1 Recall

`2026_09_06_100001_create_batch_recall_workflow_tables.php`

`batch_recall_requests`:

- `id UUID PK`
- `tenant_id UUID NOT NULL`
- `company_id UUID NOT NULL`
- `batch_id BIGINT NOT NULL`
- `requesting_location_id UUID NOT NULL`
- `requested_by UUID NOT NULL`
- `status VARCHAR(20) NOT NULL CHECK status IN ('requested','recalled')`
- `reason TEXT NOT NULL`
- timestamps
- unique `(tenant_id, company_id, id)`
- open-request uniqueness on `(tenant_id, company_id, batch_id, requesting_location_id)` where status is `requested`

`batch_recall_transitions`:

- append-only UUID PK
- request/company/tenant/batch/location/actor IDs
- `from_status`, `to_status`, `reason`, `occurred_at`
- no update/delete production path

Q10-conditional future schema is not included.

### 6.2 Identity correction and identification

`2026_09_06_100002_create_lot_identity_correction_and_identification_tables.php`

- Add `identity_version BIGINT NOT NULL DEFAULT 1` to `product_batches`.
- `batch_identity_corrections`: old/new batch number and dates, evidence, reason, actor, expected/applied identity versions.
- `lot_identifications`: operation UUID, source batch/location, actor, evidence, status.
- `lot_identification_lines`: target batch identity and exact `quantity DECIMAL(20,4)`.
- Unique `(tenant_id, company_id, operation_uuid)`.
- Quantity-changing identification movements retain the current mandatory aggregate movement FK established at `apps/api/database/migrations/tenant/2026_01_05_150002_create_inventory_batch_movements_table.php:21-25`.

### 6.3 Counting

`2026_09_06_100003_create_inventory_counting_item_lots.php`

- counting/item/company/location/product/variant/batch identifiers;
- `observed_quantity DECIMAL(20,4) NOT NULL`;
- `submitted_by`, timestamps;
- unique `(tenant_id, company_id, counting_item_id, batch_id)`;
- explicit zero is stored; missing row remains unobserved.

### 6.4 Provenance

`2026_09_06_100004_add_lot_provenance_to_allocation_producers.php`

Add nullable `provenance VARCHAR(32)` and nullable evidence actor/time/reference columns to:

- `document_lines`;
- `stock_transfer_line_batch_allocations`;
- `pos_receipt_line_batch_allocations`.

Allowed provenance:

```text
operator_captured
system_fefo_estimate
unknown
```

Conservative backfill maps only rows whose source facts prove FEFO to `system_fefo_estimate`; all others become `unknown`. No historical row is inferred as `operator_captured`.

### 6.5 Census health

`2026_09_06_100005_create_lot_ledger_census_runs.php`

- tenant/company/run IDs;
- started/finished/heartbeat times;
- status `running|clean|drifted|failed|stale`;
- tuple counts and exact decimal net/absolute drift;
- error and notification metadata;
- unique active run per tenant/company.

### 6.6 POS evidence

`2026_09_06_100006_create_pos_receipt_line_lot_evidence.php`

- `id UUID PK`
- tenant/company/location/terminal/fiscal event IDs;
- `canonical_line_key VARCHAR(64)`
- `canonical_line_index SMALLINT`
- `canonical_key_version SMALLINT DEFAULT 1`
- product/variant/batch IDs;
- `quantity DECIMAL(20,4)`
- `provenance='operator_captured'`
- `client_operation_uuid`
- captured/received timestamps
- unique `(tenant_id, company_id, terminal_id, client_operation_uuid)`
- unique `(tenant_id, company_id, fiscal_event_id, canonical_line_key, batch_id)`

### 6.7 Lot obligation

`2026_09_06_100007_create_fiscal_projection_lot_obligations.php`

`fiscal_projection_lot_obligations` includes:

- tenant/company/location/terminal/fiscal-event/receipt-line identifiers;
- `canonical_line_key VARCHAR(64)`;
- `canonical_line_index SMALLINT`;
- `canonical_key_version SMALLINT DEFAULT 1`;
- `lot_operation VARCHAR(16)` with `consume|restore|scrap`;
- product/variant identifiers;
- exact quantity;
- entitlement state and revision;
- `aggregate_stock_movement_id UUID NOT NULL`;
- nullable `inventory_batch_movement_id BIGINT`;
- state `pending_evidence|ready|applying|blocked|applied|dead_letter`;
- reason, retry count, next attempt, lease owner/expiry, error, timestamps;
- unique `(tenant_id, company_id, fiscal_event_id, canonical_line_key, lot_operation)`;
- FK `aggregate_stock_movement_id → stock_movements.id ON DELETE RESTRICT`;
- FK `inventory_batch_movement_id → inventory_batch_movements.id ON DELETE RESTRICT`;
- unique non-null `inventory_batch_movement_id`.

The obligation and its `aggregate_stock_movement_id` are inserted in the same transaction as the aggregate movement. An applied obligation must reference exactly one batch movement whose `movement_id` equals that exact aggregate movement.

### 6.8 Permission migration

`2026_09_06_100008_seed_w_lot_permissions.php`

Creates all §4.1 permissions idempotently and grants identity correction/identification only to the tenant-scoped `admin` role.

### 6.9 Device SQLite

v68:

- `branch_lot_snapshots`
- `branch_lot_eligibility`
- current entitlement revision/watermark
- replace-all transactional refresh

v69:

- `receipt_line_lot_evidence`
- `receipt_line_lot_evidence_outbox`
- canonical key/index/version
- states `pending|sending|acknowledged|retryable|dead_letter`
- attempt/lease/error fields
- unique client operation UUID

---

## 7. Canonical service contracts

```php
interface BatchStockMutationService
{
    public function receive(BatchStockMutation $mutation): InventoryBatchMovement;
    public function issue(BatchStockMutation $mutation): InventoryBatchMovement;
    public function transfer(BatchStockTransferMutation $mutation): BatchStockTransferResult;
    public function reserve(BatchReservationMutation $mutation): void;
    public function releaseReservation(BatchReservationMutation $mutation): void;
}
```

All quantities are `numeric-string`; no public float parameters or float return values remain.

```php
interface BatchRecallWorkflowService
{
    public function request(RequestBatchRecallData $data, User $actor): BatchRecallRequest;
    public function recall(string $requestId, string $reason, User $actor): BatchRecallRequest;
}
```

No release/reject signature exists while Q10 is OPEN.

```php
interface BatchIdentityCorrectionService
{
    public function correct(
        string $batchUuid,
        CorrectBatchIdentityData $data,
        int $expectedIdentityVersion,
        User $actor,
    ): Batch;
}
```

```php
interface LotIdentificationService
{
    public function identify(
        string $sourceBatchUuid,
        IdentifyLotData $data,
        string $operationUuid,
        User $actor,
    ): LotIdentification;
}
```

```php
interface PosReceiptLotProjectionService
{
    public function ensureObligation(
        FiscalEvent $event,
        ReceiptLine $receiptLine,
        StockMovement $aggregateMovement,
        string $canonicalLineKey,
        int $canonicalLineIndex,
        LotOperation $operation,
    ): FiscalProjectionLotObligation;

    public function apply(FiscalProjectionLotObligation $obligation): FiscalProjectionLotObligation;
}
```

```php
interface LotEvidenceIngressService
{
    public function ingest(
        FiscalEvent $event,
        LotEvidenceBatch $batch,
        Terminal $terminal,
    ): LotEvidenceIngressResult;
}
```

---

## 8. Complete writer and lock census

### 8.1 Canonical order

1. Lock lifecycle/document header when one exists.
2. Acquire `ProductCostLock::acquire(tenant, company, sorted product IDs)`. Its name remains for compatibility; its contract expands beyond cost writers to all aggregate-plus-lot writers.
3. Lock `stock_levels` ordered by product ID, null-variant ordering, variant ID, location ID.
4. Lock `inventory_batch_stock` ordered by batch ID, location ID.
5. Lock active reservations ordered by reservation ID.
6. Lock `product_batches` identity row only after quantity rows unless the operation is identity-only and never enters the quantity path.
7. Lock product last only for WAC/cost mutation.
8. Buffer GL work inside the root transaction; publish events and perform external I/O only after commit.

`FEFOInventoryService` retains `FOR UPDATE OF inventory_batch_stock SKIP LOCKED` at `FEFOInventoryService.php:260-272`, but it must first own the same product advisory lock. Therefore an in-repository writer cannot make the earliest lot appear temporarily unavailable to FEFO.

Hold-time classes:

- **H1:** single-product interactive operation; locks end at root commit, no external I/O, PostgreSQL gate requires p95 under 5 seconds.
- **H2:** multi-product document/projection; sorted product locks end at root commit, no external I/O, p95 under 10 seconds.
- **H3:** maintenance/census mutation; one tenant/company/product/variant/location tuple per transaction, under 5 seconds; never one fleet-wide transaction.
- **H4:** creation-only zero-row provisioning; transaction duration under 5 seconds and no lot quantity lock.

### 8.2 Writer census

| Writer path and method | Current write/predicate and transaction owner | Required order, scope, hold |
|---|---|---|
| `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:149` `recordPurchase()` | Service transaction; stock predicate tenant/company/product/variant/location; updates/creates `stock_levels`, movement and product cost. | Advisory → stock row → product; company/product scope; H1. |
| Same file `:384` `recordSale()` | Service transaction; locks aggregate stock and records movement. | For batch-tracked products advisory → aggregate → lot delegation; otherwise aggregate only; H1. |
| Same file `:540` `recordReturn()` | Service transaction; may create stock row and update cost. | Advisory → aggregate → lot → product; H1. |
| Same file `:745` `recordCostAdjustment()` | Service transaction; aggregate/cost writer. | Advisory → stock rows → product; H1/H2. |
| `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:104` `receive()` | Service transaction; aggregate row, movement; batch branch writes lot near `:2136-2152`. | Advisory → aggregate → lot through canonical mutation service; H1. |
| Same file `:242` `issue()` | Service transaction; currently aggregate write at `:282`; batch shortage path locks lots at `:1551-1569`. | Change to advisory-first → aggregate → lots; H1. |
| Same file `:372` `transfer()` | Service transaction; source/destination aggregate rows at `:413,436`, lot allocation when tracked. | Advisory → source/destination aggregate rows ordered by location → lots; H1. |
| Same file `:551` `reserve()` and `:626` `releaseReservation()` | Service transaction; aggregate reserved updates at `:579,643`. | Advisory for tracked product → aggregate → lot → reservation; H1. |
| Same file `:701` `adjust()`, `:788` `adjustByDelta()`, `:1297` `applyCountResult()` | Service transactions; aggregate and possible lot correction. | Count/document header → advisory → aggregate → lots; H1/H2. |
| Same file `:2136-2152` lot helper | Direct `BatchStock::firstOrCreate()` and quantity update by batch/location. | Replace with `BatchStockMutationService`; no direct production write remains. |
| `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:81` `ensureDefaultBatch()` and `:255` remainder helper | Direct lot creation/update; nested or service-owned transaction. | Advisory → aggregate existence → lot; zero creation H4, non-zero H1. |
| Same file `:418` `receiveBatchStock()`, `:457` `issueBatchStock()`, `:499` `transferBatchStock()` | Service transaction at `:425,464,508`; direct lot writes. | Delegate canonical mutation service; advisory → aggregate → ordered lots; H1. |
| Same file `:545` `recordBatchMovement()` | Batch movement insert currently admits conditional linkage. | Quantity-changing call requires non-null exact aggregate movement ID; H1. |
| `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchStock.php:54` `reserve()` | Direct float increment at `:60`, no transaction ownership. | Remove public mutation; canonical service only. |
| Same file `:63` `releaseReservation()` | Direct float decrement at `:69`. | Remove public mutation; canonical service only. |
| Same file `:72` `adjustQuantity()` | Float arithmetic and direct update at `:84`. | Remove public mutation; numeric-string canonical service only. |
| `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:234` `consumeBatchesAtomically()` | Service/nested transaction at `:243`; company/product/variant/location eligibility; lot locks `:260-272`, update `:307`. | Advisory → aggregate already owned by caller → FEFO lots → batch movement; H1. |
| Same file `:460` `restoreBatchesForReturn()` and `:714` `creditLot()` | Service/nested transaction; batch/location lock around `:725`, update `:747`. | Advisory → aggregate → lot; exact movement link; H1. |
| `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:77` `reserve()` | Service transaction; aggregate predicate tenant/company/product/variant/location then lot predicate batch/location; updates at `:266,271`. | Advisory → aggregate → lot → reservation; H1. |
| Same file `:482` `release()`/`releaseBySource()` | Service transaction; reservation, lot decrement at `:507`, aggregate decrement at `:515`. | Advisory → aggregate → lot → reservation state; replace floats; H1/H2. |
| Same file `:609` `expireReservations()` | Per-reservation transaction; lot decrement at `:631`, aggregate at `:639`. | Advisory → aggregate → lot → reservation ordered by ID; H1 per reservation. |
| Same file `:683` `recalculateAllReserved()` | Calls `StockLevel::recalculateReserved()`. | Replace with scoped service calculation under advisory and aggregate lock; H3. |
| `apps/api/app/Modules/Inventory/Domain/StockLevel.php:196` `recalculateReserved()` | Direct aggregate save at `:204-205`; predicates omit tenant/company in inner query. | Remove public writer; scoped reservation service only. |
| `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:83,133,221` | Outer transactions; delegates aggregate/WAC and batch receipt. | Root owns sorted advisories → aggregate → lots → product/GL; H2. |
| `apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:76` | Root transaction; direct aggregate write/create at `:143-145`, delegated lot/cost work. | Sorted advisories → aggregate → lot → product/GL; H2. |
| `apps/api/app/Modules/Inventory/Application/Services/ResetOpeningBalanceService.php:68` | Root transaction; direct aggregate update at `:137`, reversal lot work. | Sorted advisories → aggregate → lots → product/GL; H2. |
| `apps/api/app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php:133,176,316,342` | Document-owned transactions; delegates adjustment/count writes. | Header → sorted advisories → aggregate → lots; H2. |
| `apps/api/app/Modules/Inventory/Application/Services/SupplierGoodsReturnNoteService.php:331,415` | Root transaction; aggregate and FEFO lot issue through services. | Header → advisories → aggregate → lots → product/GL; H2. |
| `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:105,319,431` | Root transactions for create/complete/cancel; allocation rows at `:155-162`; delegates aggregate/lot changes. | Header → sorted advisories → source/destination aggregate → lots → allocation state; H2. |
| `apps/api/app/Modules/Inventory/Application/Services/StockLevelMigrationService.php:56` | Migration transaction moving aggregate rows to default variant. | Advisory → source/destination aggregate → corresponding lots; H3. |
| `apps/api/app/Modules/Inventory/Application/Services/StockThresholdService.php:42-49` | Creates zero aggregate row while setting thresholds. | Advisory before possible create; no lot mutation; H4. |
| `apps/api/app/Console/Commands/FixOrphanedProducts.php:128` | Creation-only zero aggregate row. | Tenant/company/product advisory → create zero row; H4. |
| `apps/api/app/Modules/BatchExpiry/Application/Services/GroupedWriteOffService.php:73-79` | Root transaction; stock then lot locks around `:250-271`; delegates movement. | Advisory → aggregate → sorted lots; H2. |
| `apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:44-59` | Root transaction; delegates adjustment and batch movement. | Advisory → aggregate → lot; H1. |
| `apps/api/app/Modules/BatchExpiry/Domain/Services/ReverseWriteOffService.php:70,167` | Root transaction; delegates reverse aggregate/lot effect. | Advisory → aggregate → lot; H1. |
| `apps/api/app/Console/Commands/RepairPhantomDefaultBatchesCommand.php:362` `repairLot()` | Per-row transaction at `:364`; direct batch lock/update `:367-453`. | Advisory → aggregate → lot; one tuple per transaction; H3. |
| Same file `:580` `repointReservations()` | Reservation and lot locks/updates through `:623`. | Advisory → aggregate → source/target lots → reservations ordered by ID; H3. |
| `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:95,297` | Document root transaction; aggregate issue and lot consumption; reservation release at `:218-239`. | Header → sorted advisories → aggregate → lots → reservations → GL; H2. |
| `apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:507,709,890` | Document root transaction; aggregate receipt and lot restoration. | Header → sorted advisories → aggregate → lots → GL; H2. |
| `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:122,142` | Receipt root transaction; direct aggregate decrement around `:927-995`, allocation writes `:1607-1634`. | Header → sorted advisories → aggregate → lots → allocations; H2. |
| `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:194,208` | Return root transaction; aggregate restore `:1536-1569`, locks `:1629-1643`, lot restore later. | Header → sorted advisories → aggregate → lots; H2. |
| `apps/api/app/Modules/POS/Application/Services/ReturnScrapWriteOffService.php:86-106` | Root transaction delegates adjustment/write-off. | Header → advisory → aggregate → lot; H1. |
| `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:231-253` | Projection transaction; aggregate movement per line `:1909-1974`; existing lot consume `:2016-2084`; other aggregate return/scrap branches `:2280-2349,2440+,2944-2997`. | Always create aggregate effect and obligation atomically; lot service uses advisory → aggregate movement validation → lots; H2. |
| New `BatchStockMutationService` | Sole direct lot quantity/reserved writer after migration. | Enforces canonical order and mandatory movement linkage; H1/H2. |
| New `LotIdentificationService` | Flat aggregate justification plus source-negative/target-positive lot legs. | Identification header → advisory → aggregate → ordered lots → reservations; H1. |
| New lot-count finalizer | One aggregate correction or flat justification plus lot deltas. | Count header → sorted advisories → aggregate → lots; H2. |
| New `PosReceiptLotProjectionService` | Sole POS lot-obligation/apply writer. | Obligation lease → advisory → aggregate movement validation → lots → obligation; H1. |
| Seeders and tenant provisioning | Zero-row/default identity creation only; no live quantity mutation permitted. | H4; architecture ratchet rejects non-zero direct writes. |

### 8.3 Reservation consumers included in float retirement

- `apps/api/app/Modules/Marketplace/Application/Services/MarketplaceOrderService.php:66,195`
- `apps/api/app/Modules/Document/Domain/Services/SalesOrderService.php:155`
- `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:239`
- `apps/api/app/Modules/Cart/Application/Services/CartService.php:118`
- `apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/InventoryReservationAdapter.php:40,76`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockReservationController.php`
- `apps/api/app/Modules/Inventory/Infrastructure/Commands/ExpireStockReservationsCommand.php:25,47`

The architecture ratchet fails any production quantity/reserved write not listed above, any direct `BatchStock` mutation outside `BatchStockMutationService`, any float quantity contract, and any quantity-changing batch movement without an aggregate movement link.

---

## 9. Benchmark-first repository baseline — Convention 10

| Concern | Current AutoERP evidence at verified HEAD | Required delta |
|---|---|---|
| Batch identity uniqueness | `apps/api/database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php:27-31` | Preserve live partial uniqueness during corrections/identification. |
| Batch movement linkage | `apps/api/database/migrations/tenant/2026_01_05_150002_create_inventory_batch_movements_table.php:21-25` | Every new quantity-changing lot movement supplies the exact aggregate movement ID. |
| POS aggregate/lot posting | `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1909-2084` | Separate always-on aggregate effect from recoverable lot obligation. |
| Fiscal line identity | `PosCoreReceiptProjection.php:1175-1207`; `apps/pos/src/lib/fiscal/payloads/SaleReceiptV2Payload.ts:35-50` | Shared versioned canonical key from signed payload index. |
| Lot arithmetic | `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchStock.php:44-84` | Replace float accessor/mutators with numeric-string service operations. |
| Reservation arithmetic | `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:266,271,507,515,631,639` | Exact decimal conservation and canonical lock order. |
| Delivery-note provenance | `SalesOrderToDeliveryNoteConverter.php:363-379,501-517`; `DeliveryNoteFromDocumentFactory.php:137-153` | Explicit provenance at all creation seams. |
| Active POS chooser | `apps/pos/src/pages/HomePage.tsx:1863`; `BarcodeChooserModal.tsx:146-152` | Same eligibility/guidance as other active renderers. |
| Active POS grid | `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:759-773`; `ProductCard.tsx:566-567` | One eligibility pipeline through every active presentation. |
| Scheduler | `apps/api/routes/console.php:118-120,250-259,277-280` | Add the daily monitor at non-colliding 03:20 with 180-minute expiry. |

---

## 10. Task dispatch packets

### Task 1 — Land preflight, vocabulary, and architecture ratchets

**Production files**

- `apps/api/app/Console/Commands/WLotPreflightCommand.php` — new
- `apps/api/app/Modules/Inventory/Domain/Services/InventoryWriterLockManifest.php` — new
- `docs/glossary.md`
- `docs/architecture/w-lot-lock-and-writer-census.md` — new

**Contract/schema:** `inventory:w-lot-preflight {--tenant=} {--all-tenants} {--format=text|json} {--fail-on-drift}`. Read-only; schema delta none.

**Red first:** `apps/api/tests/Architecture/InventoryWriterLockManifestTest.php`, class `InventoryWriterLockManifestTest`, case `test_every_inventory_writer_is_classified`; first failure: `assertSame([], $unclassifiedWriters)` reports the current direct writers. Command/lane: `cd apps/api && php artisan test tests/Architecture/InventoryWriterLockManifestTest.php --filter=test_every_inventory_writer_is_classified` — **SQLite**.

Also add `LotVocabularyContractTest::test_w_lot_terms_have_one_writer_and_surface` and `WLotPreflightCommandTest::test_preflight_is_read_only_and_fails_on_manifest_drift`.

**Implementation**

1. Encode §8 verbatim in the manifest.
2. Scan production PHP for aggregate/lot quantity or reserved writes.
3. Reject public float quantity contracts and missing movement linkage.
4. Add §3 terms to the glossary.
5. Make preflight report schema readiness, entitlement state, writer-manifest hash, drift, unresolved obligations, and permission state without mutation.

**Convention 09:** `WLotPreflightCommandTest::test_second_company_is_reported_independently`, `::test_second_location_is_reported_independently`, `::test_rerun_is_identical`.

**Reviewer gate:** No migration or writer task starts until the ratchet recognizes every §8 row and preflight is demonstrably read-only.

---

### Task 2 — Add entitlement, revision fencing, and dormant rollout flags

**Production files**

- `apps/api/app/Shared/Contracts/Company/CompanyEntitlementResolver.php` — new
- `apps/api/app/Services/CompanyConfigService.php`
- `apps/api/app/Services/VerticalConfigService.php`
- `apps/api/app/Http/Controllers/Api/Admin/VerticalConfigController.php`
- `apps/api/app/Observers/TenantObserver.php`
- `apps/api/config/lot_rollout.php` — new

**Contract/schema:** §4.2 resolver and §4.3 flags; no schema in this task.

**Red first:** `apps/api/tests/Feature/Company/WLotEntitlementResolutionTest.php`, class `WLotEntitlementResolutionTest`, case `test_missing_company_resolution_is_unresolved_not_disabled`; first failure: `assertSame(CompanyEntitlementState::Unresolved, $decision->state)`. Command/lane: `cd apps/api && php artisan test tests/Feature/Company/WLotEntitlementResolutionTest.php --filter=test_missing_company_resolution_is_unresolved_not_disabled` — **SQLite**.

**Implementation:** centralize resolution; increment revision on effective changes; invalidate caches through `TenantObserver`; advertise state/revision; keep all flags false.

**Convention 09:** `test_second_company_has_independent_revision`, `test_second_location_cannot_override_company_entitlement`, `test_rerun_returns_same_revision_without_change`.

**Reviewer gate:** No boolean fallback or cross-company cache key; all switches remain false.

---

### Task 3 — Make role/membership checks atomic

**Production files**

- `apps/api/app/Modules/Identity/Application/Services/RoleMembershipScopeService.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php`

**Contract/schema:** `assign(User $user, Role $role, Company $company, User $actor): void`; no schema.

**Red first:** `apps/api/tests/Feature/Identity/WLotRoleMembershipScopeTest.php`, class `WLotRoleMembershipScopeTest`, case `test_cross_company_permission_assignment_rolls_back`; first failure: `assertDatabaseMissing('model_has_roles', [...])`. Command/lane: `cd apps/api && php artisan test tests/Feature/Identity/WLotRoleMembershipScopeTest.php --filter=test_cross_company_permission_assignment_rolls_back` — **SQLite**.

**Implementation:** one transaction; lock membership and role; validate tenant/company; assign only after validation; audit after commit.

**Convention 09:** `test_second_company_membership_is_required`, `test_second_location_does_not_expand_company_role`, `test_assignment_rerun_is_idempotent`.

**Reviewer gate:** Cross-company assignment leaves no role or audit residue.

---

### Task 4 — Add recall schema and domain workflow

**Production files**

- `apps/api/database/migrations/tenant/2026_09_06_100001_create_batch_recall_workflow_tables.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchRecallRequest.php` — new
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchRecallTransition.php` — new
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallWorkflowService.php` — new

**Contract/schema:** §6.1 and §7 recall contract.

**Red first:** `apps/api/tests/Feature/BatchExpiry/BatchRecallWorkflowTest.php`, class `BatchRecallWorkflowTest`, case `test_branch_request_holds_only_requesting_location`; first failure: `assertFalse($eligibility->mayIssue($batch, $requestingLocation))`. Command/lane: `cd apps/api && DB_CONNECTION=pgsql php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry/BatchRecallWorkflowTest.php --filter=test_branch_request_holds_only_requesting_location` — **PG**.

**Implementation:** request creates append-only transition and local hold; recall requires general-manager/admin company authority and projects company recall; exact-operation retry returns existing result.

**Convention 09:** `test_second_company_same_batch_cannot_be_held`, `test_second_location_remains_issuable_before_company_recall`, `test_request_and_recall_reruns_are_idempotent`.

**Reviewer gate:** No release/reject code exists; requester cannot bypass company/location boundaries.

---

### Task 5 — Expose recall API and existing batch-detail surface

**Production files**

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`
- `apps/api/routes/api.php`
- `apps/web/src/features/batches/api/batches.ts`
- `apps/web/src/features/batches/types.ts`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`

**Contract/schema:** `POST /api/batches/{uuid}/recall-requests`; `POST /api/batch-recall-requests/{uuid}/recall`; no schema beyond Task 4/8 migrations.

**Red first:** `apps/api/tests/Feature/BatchExpiry/BatchRecallApiTest.php`, class `BatchRecallApiTest`, case `test_requester_without_location_custody_receives_403`; first failure: `assertForbidden()`. Command/lane: `cd apps/api && php artisan test tests/Feature/BatchExpiry/BatchRecallApiTest.php --filter=test_requester_without_location_custody_receives_403` — **SQLite**.

**Implementation:** policy checks at API boundary and service; display Branch lot hold separately from Company lot recall; no release button; hide dormant feature when flag false.

**Convention 09:** `test_second_company_batch_returns_404`, `test_second_location_hold_is_labelled_local`, `test_duplicate_request_returns_existing_request`.

**Reviewer gate:** UX and API use canonical vocabulary and expose no Q10-dependent action.

---

### Task 6 — Retire the float lot/reservation contract

**Production files**

- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchStock.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php`
- `apps/api/app/Modules/Inventory/Application/Contracts/InventoryReservationServiceInterface.php`
- `apps/api/app/Modules/Inventory/Domain/StockLevel.php`
- all reservation consumers listed in §8.3

**Contract/schema:** all quantity parameters/returns become `numeric-string`; no schema.

**Red first:** `apps/api/tests/Feature/Inventory/ReservationDecimalConservationTest.php`, class `ReservationDecimalConservationTest`, case `test_fractional_reserve_release_and_expiry_conserve_four_decimal_places`; first failure: `assertSame('0.0000', bcsub($before, $after, 4))`. Command/lane: `cd apps/api && DB_CONNECTION=pgsql php artisan test -c phpunit-pgsql.xml tests/Feature/Inventory/ReservationDecimalConservationTest.php --filter=test_fractional_reserve_release_and_expiry_conserve_four_decimal_places` — **PG**.

**Implementation:** replace float arithmetic/casts at reservation lines `507,631`; remove entity write methods; update interfaces and all callers; use `bc*`/quantity value objects; add an architecture scan for floats.

**Convention 09:** `test_fractional_reservation_is_company_scoped`, `test_fractional_release_is_location_scoped`, `test_fractional_release_rerun_does_not_double_decrement`.

**Reviewer gate:** `rg` and architecture tests find no production float lot/reservation mutation.

---

### Task 7 — Add identity correction, identification, and admin-only permission schema

**Production files**

- `apps/api/database/migrations/tenant/2026_09_06_100002_create_lot_identity_correction_and_identification_tables.php`
- `apps/api/database/migrations/tenant/2026_09_06_100008_seed_w_lot_permissions.php`
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`

**Contract/schema:** §6.2 and §6.8.

**Red first:** `apps/api/tests/Feature/BatchExpiry/WLotPermissionSeedTest.php`, class `WLotPermissionSeedTest`, case `test_identity_permissions_are_seeded_to_admin_only`; first failure: `assertFalse($generalManager->hasPermissionTo('batches.identify'))`. Command/lane: `cd apps/api && php artisan test tests/Feature/BatchExpiry/WLotPermissionSeedTest.php --filter=test_identity_permissions_are_seeded_to_admin_only` — **SQLite**.

**Implementation:** additive tables/version column; idempotent permissions; preserve live batch uniqueness; no non-admin default grants.

**Convention 09:** `test_permission_seed_covers_second_company_team`, `test_location_role_does_not_gain_identity_permission`, `test_permission_seed_rerun_is_idempotent`.

**Reviewer gate:** migration rollback is schema-only; admin-only assertion passes for old and newly provisioned tenants.

---

### Task 8 — Implement canonical mutation, identity correction, and L9 identification

**Production files**

- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockMutationService.php` — new
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchIdentityCorrectionService.php` — new
- `apps/api/app/Modules/BatchExpiry/Application/Services/LotIdentificationService.php` — new
- all direct writer files in §8
- `apps/api/app/Modules/Inventory/Domain/Services/ProductCostLock.php`

**Contract/schema:** §7 mutation/correction/identification contracts; no new schema.

**Red first:** `apps/api/tests/Feature/BatchExpiry/LotIdentificationConcurrencyTest.php`, class `LotIdentificationConcurrencyTest`, case `test_two_identifications_cannot_consume_the_same_default_quantity`; first failure: `assertSame('0.0000', $sourceAfter)` and exactly one competing operation succeeds. Command/lane: `cd apps/api && DB_CONNECTION=pgsql php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry/LotIdentificationConcurrencyTest.php --filter=test_two_identifications_cannot_consume_the_same_default_quantity` — **PG**.

**Implementation:** make canonical service the sole lot writer; apply §8 lock order; require movement linkage; correction uses optimistic `identity_version`; identification conserves aggregate quantity/value and rejects active-reservation conflicts.

**Convention 09:** `test_identification_cannot_cross_company`, `test_identification_cannot_cross_location`, `test_operation_uuid_rerun_returns_same_identification`.

**Reviewer gate:** writer ratchet passes; PG race proves conservation and no deadlock.

---

### Task 9 — Expose identity correction/identification and activate freeze atomically

**Production files**

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`
- `apps/api/routes/api.php`
- `apps/web/src/features/batches/api/batches.ts`
- `apps/web/src/features/batches/types.ts`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`

**Contract/schema:** `POST /api/batches/{uuid}/correct-identity`; `POST /api/batches/{uuid}/identify`; no schema.

**Red first:** `apps/api/tests/Feature/BatchExpiry/LotIdentificationApiTest.php`, class `LotIdentificationApiTest`, case `test_general_manager_without_explicit_permission_cannot_identify`; first failure: `assertForbidden()`. Command/lane: `cd apps/api && php artisan test tests/Feature/BatchExpiry/LotIdentificationApiTest.php --filter=test_general_manager_without_explicit_permission_cannot_identify` — **SQLite**.

**Implementation:** exact-decimal request DTOs; expected identity version; operation UUID; evidence required; replace legacy mutation only after Task 8 passes.

**Convention 09:** `test_second_company_batch_returns_404`, `test_target_location_must_equal_source_location`, `test_duplicate_operation_uuid_returns_original_response`.

**Reviewer gate:** L9 works end-to-end for admin; stale version is 409; general manager defaults to 403.

---

### Task 10 — Add lot-count schema and typed submission contract

**Production files**

- `apps/api/database/migrations/tenant/2026_09_06_100003_create_inventory_counting_item_lots.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/LotCountObservationData.php` — new
- `apps/api/app/Modules/Inventory/Presentation/Requests/SubmitInventoryCountRequest.php`
- `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php`

**Contract/schema:** §6.3; `submitLotObservations(string $countId, string $itemId, array $observations, User $actor): void`.

**Red first:** `apps/api/tests/Feature/Inventory/LotCountSubmissionTest.php`, class `LotCountSubmissionTest`, case `test_explicit_zero_lot_is_distinct_from_missing_observation`; first failure: `assertDatabaseHas('inventory_counting_item_lots', ['observed_quantity' => '0.0000'])`. Command/lane: `cd apps/api && php artisan test tests/Feature/Inventory/LotCountSubmissionTest.php --filter=test_explicit_zero_lot_is_distinct_from_missing_observation` — **SQLite**.

**Implementation:** validate count phase, assignment, product/variant/location, uniqueness, exact decimals; retain aggregate count item as the parent authority.

**Convention 09:** `test_second_company_lot_is_rejected`, `test_second_location_lot_is_rejected`, `test_submission_rerun_replaces_identical_observations`.

**Reviewer gate:** no nested JSON stock authority and no silent zero-for-missing behavior.

---

### Task 11 — Reconcile lot counts safely

**Production files**

- `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php`
- `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php`
- `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockMutationService.php`

**Contract/schema:** finalize aggregate once, then apply explicit lot deltas linked to that movement; no schema.

**Red first:** `apps/api/tests/Feature/Inventory/LotCountReconciliationPostgresTest.php`, class `LotCountReconciliationPostgresTest`, case `test_concurrent_finalize_applies_one_aggregate_and_one_set_of_lot_legs`; first failure: `assertSame(1, $aggregateMovementCount)`. Command/lane: `cd apps/api && DB_CONNECTION=pgsql php artisan test -c phpunit-pgsql.xml tests/Feature/Inventory/LotCountReconciliationPostgresTest.php --filter=test_concurrent_finalize_applies_one_aggregate_and_one_set_of_lot_legs` — **PG**.

**Implementation:** count header lock; sorted product locks; aggregate correction; known-lot deltas; DEFAULT remainder only where policy allows; idempotent replay.

**Convention 09:** `test_finalize_does_not_touch_second_company`, `test_finalize_does_not_touch_second_location`, `test_finalize_rerun_has_no_second_movement`.

**Reviewer gate:** aggregate equals sum of lots after finalize; concurrent finalize has one winner and no drift.

---

### Task 12 — Add lot-count API and existing count UI

**Production files**

- `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php`
- `apps/api/routes/api.php`
- `apps/web/src/features/inventory-counting` existing feature files
- `packages/shared/types` generated count DTO output

**Contract/schema:** submit and read lot observations under existing count resource; no schema.

**Red first:** `apps/web/src/features/inventory-counting/LotCountEditor.test.tsx`, case `LotCountEditor shows explicit zero and unsupplied separately`; first failure: `expect(screen.getByText('Not counted')).toBeInTheDocument()`. Command/lane: `pnpm --filter @autoerp/web test -- src/features/inventory-counting/LotCountEditor.test.tsx` — **vitest**.

**Implementation:** add per-lot editor inside existing count detail; exact strings; permission and entitlement gating; regenerate shared types.

**Convention 09:** backend `InventoryCountingApiTest::test_second_company_count_returns_404`, `::test_second_location_lots_are_not_listed`, `::test_identical_submission_rerun_is_stable`.

**Reviewer gate:** API and UI distinguish `0.0000` from absent and introduce no new count catalogue.

---

### Task 13 — Add provenance schema and every producer write

**Production files**

- `apps/api/database/migrations/tenant/2026_09_06_100004_add_lot_provenance_to_allocation_producers.php`
- `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToDeliveryNoteConverter.php`
- `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteFromDocumentFactory.php`
- `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php`
- `apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`
- `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php`
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`

**Contract/schema:** §6.4; producer must supply provenance explicitly.

**Red first:** `apps/api/tests/Architecture/LotProvenanceWriterContractTest.php`, class `LotProvenanceWriterContractTest`, case `test_every_allocation_producer_sets_provenance`; first failure: `assertSame([], $writersWithoutProvenance)` identifies both converter branches and factory. Command/lane: `cd apps/api && php artisan test tests/Architecture/LotProvenanceWriterContractTest.php --filter=test_every_allocation_producer_sets_provenance` — **SQLite**.

Specific feature assertions cover converter lines `363-379`, `501-517`, factory `137-153`, reservation propagation, receipt, transfer, return, and POS projection.

**Implementation:** add enum/value object; annotate each producer from source facts; propagate reservation provenance; conservative historical backfill is deferred to push 4.

**Convention 09:** `LotProvenanceIsolationTest::test_second_company_provenance_is_not_read`, `::test_second_location_allocation_keeps_own_provenance`, `::test_projection_rerun_does_not_duplicate_provenance`.

**Reviewer gate:** every producer passes the architecture scan; no historical row is labelled captured without evidence.

---

### Task 14 — Expose provenance on trace, document, transfer, receipt, and CSV surfaces

**Production files**

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchTraceabilityCsvExporter.php` — new
- `apps/api/app/Modules/POS/Application/DTOs/ReceiptDetailData.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php`
- `apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/web/src/features/pos/pages/ReceiptDetailPage/ReceiptDetailPage.tsx`
- `apps/web/src/features/documents/delivery-notes/DeliveryNoteDetailPage.tsx`
- `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx`

**Contract/schema:** response/export field `lot_provenance`; no schema.

**Red first:** `apps/web/src/features/batches/pages/BatchDetailPage.provenance.test.tsx`, case `renders FEFO estimate without captured wording`; first failure: `expect(screen.getByText('System FEFO estimate')).toBeInTheDocument()`. Command/lane: `pnpm --filter @autoerp/web test -- src/features/batches/pages/BatchDetailPage.provenance.test.tsx` — **vitest**.

**Implementation:** expose identical vocabulary on every surface and CSV; unknown remains visibly unknown; permission-check export.

**Convention 09:** API `BatchTraceabilityProvenanceTest::test_second_company_rows_are_excluded`, `::test_second_location_is_explicit`, `::test_export_rerun_is_byte_stable`.

**Reviewer gate:** no surface describes inferred evidence as scanned, captured, or operator-confirmed.

---

### Task 15 — Add durable drift health and safe schedule

**Production files**

- `apps/api/database/migrations/tenant/2026_09_06_100005_create_lot_ledger_census_runs.php`
- `apps/api/app/Console/Commands/LotLedgerDriftCensusCommand.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/LotLedgerDriftCensus.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/LotLedgerDriftMonitorService.php` — new
- `apps/api/app/Notifications/LotLedgerDriftFailedNotification.php` — new
- `apps/api/routes/console.php`

**Contract/schema:** §6.5. Extend existing `inventory:lot-drift-census` with `--record-run`, `--recover-stale-run`, and `--stale-after=180`.

**Red first:** `apps/api/tests/Feature/BatchExpiry/LotLedgerDriftScheduleTest.php`, class `LotLedgerDriftScheduleTest`, case `test_schedule_uses_0320_and_180_minute_overlap_expiry`; first failure checks the scheduled event expression and mutex expiry. Command/lane: `cd apps/api && php artisan test tests/Feature/BatchExpiry/LotLedgerDriftScheduleTest.php --filter=test_schedule_uses_0320_and_180_minute_overlap_expiry` — **SQLite**.

**Implementation**

```php
Schedule::command('inventory:lot-drift-census --all-tenants --record-run --fail-on-drift --recover-stale-run --stale-after=180')
    ->dailyAt('03:20')
    ->withoutOverlapping(180)
    ->onOneServer()
    ->onFailure(/* dispatch LotLedgerDriftFailedNotification */);
```

A run heartbeat older than 180 minutes becomes `stale` before a replacement run. Laravel’s overlap mutex expires after 180 minutes; no broad manual cache deletion is prescribed. Alert if no successful fleet run exists within 26 hours.

**Convention 09:** `test_second_company_gets_separate_run`, `test_second_location_drift_is_separate_tuple`, `test_monitor_rerun_does_not_duplicate_active_run`.

**Reviewer gate:** command never repairs stock; simulated crash recovers; schedule does not collide.

---

### Task 16 — Add server lot eligibility and terminal capability contracts

**Production files**

- `apps/api/app/Modules/POS/Application/Services/PosLotEligibilityService.php` — new
- `apps/api/app/Modules/POS/Application/DTOs/PosLotEligibilitySnapshotData.php` — new
- `apps/api/app/Modules/POS/Presentation/Controllers/PosStockLevelController.php`
- `apps/api/app/Modules/POS/Domain/Terminal.php`
- `apps/api/routes/api.php`

**Contract/schema:** `snapshot(tenant, company, location, terminal, watermark): PosLotEligibilitySnapshotData`; terminal capabilities advertise guidance/evidence versions; no schema unless stored in existing terminal capability JSON.

**Red first:** `apps/api/tests/Feature/POS/PosLotEligibilityApiTest.php`, class `PosLotEligibilityApiTest`, case `test_requested_recall_holds_only_requesting_location`; first failure: `assertFalse($row['issuable'])`. Command/lane: `cd apps/api && php artisan test tests/Feature/POS/PosLotEligibilityApiTest.php --filter=test_requested_recall_holds_only_requesting_location` — **SQLite**.

**Implementation:** filter expiry, company recall, branch hold, available quantity, product/variant/location; include entitlement revision and watermark; enforce terminal binding.

**Convention 09:** `test_second_company_lots_are_absent`, `test_second_location_has_independent_branch_hold`, `test_same_watermark_retry_is_stable`.

**Reviewer gate:** snapshot is guidance only and cannot mutate inventory.

---

### Task 17 — Add SQLite v68, mandatory pre-open refresh, and complete renderer integration

**Production files**

- `apps/pos/src/lib/db/migrations.ts`
- `apps/pos/src/lib/db` repository files for lot snapshot persistence
- `apps/pos/src/pages/HomePage.tsx`
- `apps/pos/src/components/molecules/BarcodeChooserModal/BarcodeChooserModal.tsx`
- `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx`
- `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx`
- `apps/pos/src/components/organisms/ProductGrid/ProductListRow.tsx`
- `apps/pos/src/components/organisms/ProductGrid/ProductTable.tsx`
- `apps/pos/src/components/organisms/ProductGrid/NearExpirySlot.tsx`
- `apps/pos/src/components/pos/ProductDetailDrawer.tsx`
- remove `apps/pos/src/components/pos/ProductGrid.tsx`
- remove `apps/pos/src/components/pos/ProductCard.tsx`

**Contract/schema:** SQLite v68; `replaceLotEligibilitySnapshot(snapshot): Promise<void>` is transactional and replace-all.

**Red first:** `apps/pos/src/lib/db/__tests__/lotEligibilityMigration.test.ts`, case `v68 replaces location snapshot atomically and records revision`; first failure checks schema version 68 and revision. Command/lane: `pnpm --filter @autoerp/pos test -- src/lib/db/__tests__/lotEligibilityMigration.test.ts` — **vitest**.

Renderer red tests include `BarcodeChooserModal.test.tsx`, `ProductCard.test.tsx`, `ProductListRow.test.tsx`, `ProductTable.test.tsx`, and `ProductDetailDrawer.test.tsx`. An import-census test fails while the legacy grid/card pair is referenced or duplicated.

**Implementation:** refresh entitlement and snapshot before shift open; refuse opening on stale/failed acknowledgement; route all active renderers through one selector; delete dead legacy pair.

**Convention 09:** device cases `uses_company_partition_in_snapshot_key`, `uses_location_partition_in_snapshot_key`, `identical_snapshot_rerun_is_atomic`.

**Reviewer gate:** active-renderer census is complete, legacy imports are zero, and offline guidance never claims stock authority.

---

### Task 18 — Add canonical keys and server evidence ingress

**Production files**

- `packages/shared/contracts/w-lot-canonical-line-key-v1.json`
- `apps/api/app/Modules/Fiscal/Domain/Services/CanonicalReceiptLineKey.php`
- `apps/pos/src/lib/fiscal/canonicalReceiptLineKey.ts`
- `apps/api/database/migrations/tenant/2026_09_06_100006_create_pos_receipt_line_lot_evidence.php`
- `apps/api/app/Modules/POS/Application/Services/LotEvidenceIngressService.php` — new
- `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php`
- `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php`
- `apps/api/routes/api.php`

**Contract/schema:** §5, §6.6, and §7 ingress.

**Red first:** `apps/api/tests/Unit/Fiscal/CanonicalReceiptLineKeyTest.php`, class `CanonicalReceiptLineKeyTest`, case `test_duplicate_products_receive_distinct_index_keys`; first failure: `assertNotSame($firstKey, $secondKey)`. Command/lane: `cd apps/api && php artisan test tests/Unit/Fiscal/CanonicalReceiptLineKeyTest.php --filter=test_duplicate_products_receive_distinct_index_keys` — **SQLite**.

Cross-language vector command/lane: `pnpm --filter @autoerp/pos test -- src/lib/fiscal/canonicalReceiptLineKey.test.ts` — **vitest**.

Required named cases:

- `test_duplicate_products_receive_distinct_index_keys`
- `test_evidence_batch_reordering_preserves_line_key`
- `test_retry_reuses_identical_key`
- `test_mixed_event_versions_match_shared_vectors`
- `test_server_rejects_key_product_mismatch`

**Implementation:** compute from event version/index only; verify signed payload row; accept evidence-before-projection and projection-before-evidence; deduplicate by client operation UUID.

**Convention 09:** `LotEvidenceIngressIsolationTest::test_second_company_event_is_rejected`, `::test_second_location_terminal_is_rejected`, `::test_ingress_rerun_returns_existing_evidence`.

**Reviewer gate:** PHP and TypeScript consume the same vectors byte-for-byte.

---

### Task 19 — Add SQLite v69 and atomically author evidence

**Production files**

- `apps/pos/src/lib/db/migrations.ts`
- `apps/pos/src/lib/db` evidence repository files
- `apps/pos/src/lib/fiscal/canonicalReceiptLineKey.ts`
- `apps/pos/src/lib/fiscal/payloads/SaleReceiptV2Payload.ts`
- `apps/pos/src` cart/checkout lot-evidence authoring files selected by existing checkout imports

**Contract/schema:** SQLite v69; `recordReceiptLotEvidence(input): Promise<OutboxRecord>` inserts evidence and outbox atomically.

**Red first:** `apps/pos/src/lib/db/__tests__/lotEvidenceAtomicity.test.ts`, case `recordReceiptLotEvidence rolls back evidence when outbox insert fails`; first failure: `expect(await evidenceCount()).toBe(0)`. Command/lane: `pnpm --filter @autoerp/pos test -- src/lib/db/__tests__/lotEvidenceAtomicity.test.ts` — **vitest**.

**Implementation:** author sidecar only; retain sealed fiscal payload; exact decimals; editable FEFO prefill defaults to `system_fefo_estimate` until cashier confirms captured evidence.

**Convention 09:** `partitions_evidence_by_company`, `partitions_evidence_by_location`, `operation_uuid_rerun_creates_one_outbox_row`.

**Reviewer gate:** no event-version change and no evidence without its outbox row.

---

### Task 20 — Deliver evidence outbox with crash recovery

**Production files**

- `apps/pos/src/lib/sync` existing sync orchestration files
- `apps/pos/src/lib/db` evidence outbox repository
- `apps/pos/src` sync-status UI
- `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php`

**Contract/schema:** lease pending/retryable rows; acknowledge only server-confirmed operation UUID; exponential retry; dead-letter after configured attempts; no schema.

**Red first:** `apps/pos/src/lib/sync/__tests__/lotEvidenceOutbox.test.ts`, case `crash_after_server_commit_retries_without_duplicate`; first failure: `expect(serverEvidenceCount).toBe(1)`. Command/lane: `pnpm --filter @autoerp/pos test -- src/lib/sync/__tests__/lotEvidenceOutbox.test.ts` — **vitest**.

**Implementation:** transactional lease; reclaim expired sending rows; classify 4xx permanent versus network/5xx retryable; expose pending/dead-letter status.

**Convention 09:** `does_not_send_other_company_rows`, `does_not_send_other_location_rows`, `retry_after_commit_is_idempotent`.

**Reviewer gate:** forced crashes at every boundary yield one server evidence row.

---

### Task 21 — Add child obligations and the sole POS lot writer

**Production files**

- `apps/api/database/migrations/tenant/2026_09_06_100007_create_fiscal_projection_lot_obligations.php`
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`
- `apps/api/app/Modules/POS/Application/Services/PosReceiptLotProjectionService.php` — new
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockMutationService.php`

**Contract/schema:** §6.7 and §7 obligation contract.

**Red first:** `apps/api/tests/Feature/Fiscal/PosReceiptLotObligationTest.php`, class `PosReceiptLotObligationTest`, case `test_obligation_persists_exact_aggregate_movement_and_replay_links_one_batch_movement`; first failure:

```php
$this->assertSame(
    $aggregateMovement->id,
    $obligation->aggregate_stock_movement_id,
);
```

Command/lane: `cd apps/api && DB_CONNECTION=pgsql php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/PosReceiptLotObligationTest.php --filter=test_obligation_persists_exact_aggregate_movement_and_replay_links_one_batch_movement` — **PG**.

**Implementation**

1. Create aggregate movement exactly once.
2. Insert obligation in the same transaction with exact movement ID and canonical key.
3. If entitlement/evidence permits, apply lot work through the sole service.
4. Otherwise commit aggregate plus pending/blocked obligation.
5. Recovery leases the obligation and validates tenant/company/product/variant/quantity and aggregate movement.
6. Applied state stores exactly one batch movement whose `movement_id` matches the obligation.
7. Retry never recreates aggregate stock movement.

**Convention 09:** `test_second_company_cannot_claim_obligation`, `test_second_location_evidence_cannot_apply_obligation`, `test_projection_and_recovery_reruns_create_one_lot_effect`.

**Reviewer gate:** PG duplicate-product, late-evidence, mixed-version, and concurrent-recovery tests all prove one aggregate and one linked eventual lot effect.

---

### Task 22 — Add obligation recovery and health UI

**Production files**

- `apps/api/app/Modules/Fiscal/Presentation/Controllers/DeadLetteredProjectionsController.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php`
- `apps/api/app/Modules/POS/Application/DTOs/ReceiptDetailData.php`
- `apps/web/src/features/pos/pages/ReceiptDetailPage/ReceiptDetailPage.tsx`
- `apps/web/src/features/pos/components/LotProjectionStatus.tsx` — new

**Contract/schema:** receipt/projection DTO exposes obligation state, reason, age, aggregate movement ID, linked batch movement ID, and retry eligibility; no schema.

**Red first:** `apps/web/src/features/pos/pages/ReceiptDetailPage/LotProjectionStatus.test.tsx`, case `blocked lot obligation displays reason and aggregate movement reference`; first failure: `expect(screen.getByText(/Blocked lot work/)).toBeInTheDocument()`. Command/lane: `pnpm --filter @autoerp/web test -- src/features/pos/pages/ReceiptDetailPage/LotProjectionStatus.test.tsx` — **vitest**.

**Implementation:** integrate into existing projection recovery; retry calls the sole lot service; no second stock writer or workflow; permission-gate operator retry.

**Convention 09:** API `LotObligationRecoveryApiTest::test_second_company_obligation_returns_404`, `::test_second_location_is_not_retryable_by_branch_user`, `::test_recovery_rerun_returns_applied_result`.

**Reviewer gate:** operator can distinguish missing evidence, unresolved entitlement, lock contention, and permanent dead letter without editing stock directly.

---

### Task 23 — Execute second-of-everything and end-to-end acceptance

**Production files:** none; verification-only.

**Contract/schema:** no changes.

**Red first:** `apps/api/tests/Feature/WLot/WLotSecondOfEverythingPostgresTest.php`, class `WLotSecondOfEverythingPostgresTest`, case `test_full_workflow_isolated_across_two_companies_and_two_locations`; first failure: second company/location state differs from expected untouched values. Command/lane: `cd apps/api && DB_CONNECTION=pgsql php artisan test -c phpunit-pgsql.xml tests/Feature/WLot/WLotSecondOfEverythingPostgresTest.php` — **PG**.

**Implementation/verification:** execute recall, identification, correction, count, provenance, eligibility, evidence, projection, recovery, and census with two tenants, two companies per tenant, two locations per company, duplicate products, fractional quantities, and retries.

**Convention 09:** the class contains named `test_second_company_*`, `test_second_location_*`, and `test_rerun_*` cases for every W-LOT lane.

**Reviewer gate:** zero cross-boundary writes, exact decimal conservation, no deadlock, and one effect per operation.

---

### Task 24 — Produce handback and activation evidence

**Production files:** none; evidence/documentation only.

**Documentation files**

- `docs/handoff/HANDBACK-w-lot-batch-management-2026-09-06.md` — new
- `docs/architecture/w-lot-lock-and-writer-census.md`
- `docs/glossary.md`

**Contract/schema:** handback must record deployed commit SHAs, migration status, flags, command transcripts, screenshots, test results, census result, rollback rehearsal, and Q10–Q13 as OPEN.

**Red first:** `apps/api/tests/Architecture/WLotHandbackCompletenessTest.php`, class `WLotHandbackCompletenessTest`, case `test_handback_contains_every_activation_evidence_marker`; first failure: `assertSame([], $missingMarkers)`. Command/lane: `cd apps/api && php artisan test tests/Architecture/WLotHandbackCompletenessTest.php --filter=test_handback_contains_every_activation_evidence_marker` — **SQLite**.

**Implementation:** populate evidence only after the relevant commands have run; include exact staging outputs, not templates.

**Convention 09:** handback test requires named second-company, second-location, and rerun transcript markers.

**Reviewer gate:** no flag activation is considered complete until the handback ratchet passes.

---

## 11. Explicit five-push staging auto-deploy manifest

Each push is independently deployable. No push combines its declared class with a later class.

### Push 1 — Preflight command and contracts first

**Contents**

- Task 1 preflight command, writer manifest, architecture tests, glossary, and census document.
- Task 2 rollout config with every flag defaulting to false.
- No migrations and no business behavior activation.

**Before push**

```bash
git rev-parse HEAD
pnpm lint
pnpm typecheck
cd apps/api && php artisan test tests/Architecture/InventoryWriterLockManifestTest.php
cd apps/api && php artisan test tests/Architecture/LotVocabularyContractTest.php
```

**After auto-deploy**

```bash
docker compose -f docker-compose.staging.yml exec -T api php artisan inventory:w-lot-preflight --all-tenants --format=json
docker compose -f docker-compose.staging.yml exec -T api php artisan migrate:status
```

**Acceptance:** preflight runs before any W-LOT migration, reports all flags false, and performs no writes.

**Rollback point P1:** redeploy the immediately preceding image. No database rollback is required.

---

### Push 2 — Schema-only additive migrations

**Contents only**

- `2026_09_06_100001_create_batch_recall_workflow_tables.php`
- `2026_09_06_100002_create_lot_identity_correction_and_identification_tables.php`
- `2026_09_06_100003_create_inventory_counting_item_lots.php`
- `2026_09_06_100004_add_lot_provenance_to_allocation_producers.php`
- `2026_09_06_100005_create_lot_ledger_census_runs.php`
- `2026_09_06_100006_create_pos_receipt_line_lot_evidence.php`
- `2026_09_06_100007_create_fiscal_projection_lot_obligations.php`
- `2026_09_06_100008_seed_w_lot_permissions.php`
- SQLite v68/v69 table migrations, with no callers yet.

**Migration commands**

The staging API startup may run central migrations. Tenant rollout must use the repository’s rolling command at `apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48`.

```bash
docker compose -f docker-compose.staging.yml exec -T api php artisan migrate --force
docker compose -f docker-compose.staging.yml exec -T api php artisan tenants:migrate-rolling --force
docker compose -f docker-compose.staging.yml exec -T api php artisan migrate:status
docker compose -f docker-compose.staging.yml exec -T api php artisan inventory:w-lot-preflight --all-tenants --format=json
```

**Acceptance:** every migration is additive; old application instances continue operating; all flags remain false.

**Rollback point P2:** redeploy P1. Leave additive tables/columns in place; do not run destructive down migrations during incident rollback.

---

### Push 3 — Dormant implementation

**Contents**

- Tasks 2–22 production code and tests.
- Canonical mutation service and retired float writers.
- APIs and UI compiled but guarded.
- Existing POS aggregate posting remains active.
- Scheduler registration is guarded by `LOT_ROLLOUT_CENSUS_SCHEDULER=false`.
- All W-LOT flags remain false.

**Commands**

```bash
pnpm build
pnpm lint
pnpm typecheck
pnpm test
cd apps/api && composer test
cd apps/api && ./vendor/bin/phpstan analyse
cd apps/api && ./vendor/bin/pint --test
cd apps/api && DB_CONNECTION=pgsql php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry tests/Feature/Inventory tests/Feature/Fiscal tests/Feature/POS
docker compose -f docker-compose.staging.yml exec -T api php artisan inventory:w-lot-preflight --all-tenants --format=json --fail-on-drift
```

**Acceptance:** dormant paths pass tests; flags false; current staging behavior unchanged.

**Rollback point P3:** redeploy P2. Additive schema stays.

---

### Push 4 — Conservative backfill and verification

**Contents**

- Provenance backfill command.
- Permission reseed invocation support.
- No behavior flag change.
- No historical `operator_captured` inference.
- No stock repair.

**Commands**

```bash
docker compose -f docker-compose.staging.yml exec -T api php artisan inventory:w-lot-preflight --all-tenants --format=json
docker compose -f docker-compose.staging.yml exec -T api php artisan db:seed --class=RolesAndPermissionsSeeder --force
docker compose -f docker-compose.staging.yml exec -T api php artisan inventory:backfill-lot-provenance --all-tenants --dry-run
docker compose -f docker-compose.staging.yml exec -T api php artisan inventory:backfill-lot-provenance --all-tenants --execute
docker compose -f docker-compose.staging.yml exec -T api php artisan inventory:lot-drift-census --all-tenants --record-run --fail-on-drift --recover-stale-run --stale-after=180
docker compose -f docker-compose.staging.yml exec -T api php artisan inventory:w-lot-preflight --all-tenants --format=json --fail-on-drift
```

**Verification queries/commands**

- zero `operator_captured` rows produced by historical backfill;
- no non-admin default grant for identity permissions;
- no unresolved tenant migration;
- aggregate/lot census clean or every pre-existing drift tuple explicitly accepted as a rollout blocker;
- obligation table empty before activation.

**Acceptance:** clean preflight and signed backfill transcript.

**Rollback point P4:** redeploy P3. Backfilled `unknown`/proven FEFO metadata may remain because it is additive and non-authoritative.

---

### Push 5 — Activation

Set flags in the staging deployment environment in this order:

```text
LOT_ROLLOUT_PROVENANCE=true
LOT_ROLLOUT_CENSUS_SCHEDULER=true
LOT_ROLLOUT_RECALL_WORKFLOW=true
LOT_ROLLOUT_IDENTITY_CORRECTION=true
LOT_ROLLOUT_LOT_COUNTING=true
LOT_ROLLOUT_POS_GUIDANCE=true
LOT_ROLLOUT_POS_EVIDENCE_AUTHORING=true
LOT_ROLLOUT_POS_EVIDENCE_PROJECTION=true
```

After each logical group, rebuild configuration and restart the actual staging services verified in `docker-compose.staging.yml:178-263`:

```bash
docker compose -f docker-compose.staging.yml exec -T api php artisan config:clear
docker compose -f docker-compose.staging.yml exec -T api php artisan config:cache
docker compose -f docker-compose.staging.yml exec -T api php artisan horizon:terminate
docker compose -f docker-compose.staging.yml exec -T api php artisan queue:restart
docker compose -f docker-compose.staging.yml restart api worker scheduler websocket web
docker compose -f docker-compose.staging.yml exec -T api php artisan inventory:w-lot-preflight --all-tenants --format=json --fail-on-drift
```

Activation checkpoints:

1. Provenance and census.
2. Recall.
3. Identity correction/identification and counting.
4. POS guidance.
5. POS evidence authoring.
6. POS evidence projection last.

Run after the last checkpoint:

```bash
cd apps/api && DB_CONNECTION=pgsql php artisan test -c phpunit-pgsql.xml tests/Feature/WLot/WLotSecondOfEverythingPostgresTest.php
pnpm --filter @autoerp/pos test
pnpm --filter @autoerp/web test
docker compose -f docker-compose.staging.yml exec -T api php artisan inventory:lot-drift-census --all-tenants --record-run --fail-on-drift --recover-stale-run --stale-after=180
```

**Rollback point P5:** first set the affected flag back to `false`, then repeat `config:clear`, `config:cache`, Horizon/queue termination, and service restart. Roll back in reverse activation order. Never disable or roll back the always-on POS aggregate projection. Pending obligations remain durable for inspection/retry; do not delete them or reverse aggregate stock automatically.

---

## 12. L1–L9 completion map

| Acceptance | Tasks | Proof |
|---|---|---|
| L1 — branch hold and company recall | 4–5 | Location-local request hold, GM/admin company recall, Q10 branches absent. |
| L2 — exact quantity contracts | 6, 8 | No float lot/reservation mutation; fractional conservation. |
| L3 — identity correction | 7–9 | Optimistic version, audit evidence, unchanged quantity/value. |
| L4 — lot identification | 7–9 | Admin-only, evidence-backed split, aggregate conservation. |
| L5 — provenance | 13–14 | All producer seams, conservative backfill, consistent UI/export. |
| L6 — health and writer proof | 1, 8, 15, 23 | Complete census, lock ratchet, durable monitor, PG concurrency. |
| L7 — lot counting | 10–12 | Flat parent count plus explicit lot observations and linked reconciliation. |
| L8 — POS guidance/evidence/projection | 16–22 | Pre-open refresh, full renderer census, durable outbox/obligation, exact aggregate link. |
| L9 — identify unknown holdings before correction workflows | 7–9 before L4 activation | Admin-only identification and canonical locking deployed before downstream reliance. |

L9 is deployed and verified before L4-dependent operational activation.

---

## 13. Dispatch order and reviewer stop points

1. Task 1 — preflight, completed census ratchet, vocabulary.
2. Task 2 — entitlement and dormant flags.
3. Task 3 — atomic role/membership scope.
4. **Push 1 and reviewer stop.**
5. Tasks 4, 7, 10, 13, 15, 18, 21 migration portions plus SQLite v68/v69.
6. **Push 2 and schema compatibility stop.**
7. Task 6 — exact decimal retirement.
8. Task 8 — canonical mutation and identification.
9. Task 4 service behavior, then Task 5.
10. Task 9.
11. Tasks 10–12.
12. Tasks 13–14.
13. Task 15 behavior.
14. Tasks 16–17.
15. Tasks 18–20.
16. Tasks 21–22.
17. **Push 3 and dormant full-suite stop.**
18. Task 23 dry-run fixtures and backfill verification.
19. **Push 4 and data-readiness stop.**
20. Activate in §11 Push-5 order.
21. Task 23 full PG/vitest acceptance.
22. Task 24 handback evidence.
23. **Final reviewer sign-off.**

No new lot writer is dispatched before Task 1 passes. No legacy writer is frozen before Task 8’s replacement and concurrency tests pass. POS evidence projection is the final flag activated.

---

## 14. Verification checklist

### Baseline and citations

- [ ] Recorded implementation base is `6e17a76022c5ccd8864afdf98cd5302e5264176b` or a reviewer-approved descendant with refreshed citations.
- [ ] Live batch uniqueness still matches `2026_06_02_100008_add_variant_id_to_product_batches.php:27-31`.
- [ ] SQLite v68/v69 remain unused before migration implementation.
- [ ] Every path in each task exists at dispatch or is the exact named new file under an existing verified parent.

### Owner and vocabulary

- [ ] Q10–Q13 remain OPEN verbatim.
- [ ] No release/reject recall code exists.
- [ ] Branch lot hold and Company lot recall are distinct in schema, code, API, and UI.
- [ ] Lot identity correction and Lot identification remain distinct.
- [ ] `docs/glossary.md` names one writer and one operator surface per concept.

### Permissions and tenancy

- [ ] `batches.identify` and `batches.correct-identity` are admin-only defaults.
- [ ] Additional grants flow through ordinary role management.
- [ ] Tenant/company/location predicates are applied before every write.
- [ ] Every relevant task passes named second-company, second-location, and rerun tests.

### Quantity, writers, and locking

- [ ] No public float lot/reservation mutation remains.
- [ ] `BatchStockMutationService` is the sole direct live lot quantity/reserved writer.
- [ ] `StockReservationService.php:507,631` no longer perform float decrements.
- [ ] `BatchStock.php:54-84` no longer exposes direct mutation.
- [ ] Every §8 writer is present in `InventoryWriterLockManifest`.
- [ ] Advisory locks precede aggregate and lot row locks for tracked products.
- [ ] Product IDs and row keys are locked in deterministic order.
- [ ] No lock is held over external I/O.
- [ ] PG hold-time gates satisfy H1–H4.
- [ ] Every quantity-changing batch movement has a mandatory aggregate movement link.

### Recall, identity, and count

- [ ] Requested recall blocks only the requesting location.
- [ ] Company recall blocks all company locations.
- [ ] Identity correction changes no quantity or value.
- [ ] Identification conserves source/target quantity and value.
- [ ] Active reservations cannot be stranded by identification.
- [ ] Explicit lot zero differs from missing count.
- [ ] Count replay produces no duplicate movement.

### Provenance and health

- [ ] All converter, factory, reservation, receipt, transfer, return, and POS producers set provenance.
- [ ] Historical backfill never invents captured evidence.
- [ ] UI and CSV use the same provenance vocabulary.
- [ ] Census remains non-repairing.
- [ ] Scheduler is 03:20 with `withoutOverlapping(180)`.
- [ ] Crash/stale-run recovery and failure notification are demonstrated.
- [ ] No successful-run age exceeds 26 hours unnoticed.

### POS canonical identity and renderer coverage

- [ ] PHP and TypeScript pass the same canonical-key vectors.
- [ ] Duplicate product lines receive distinct keys.
- [ ] Reordered evidence preserves the line key.
- [ ] Exact retries reuse the key.
- [ ] Event versions 1–5 are distinguished deterministically.
- [ ] ProductCard, ProductListRow, ProductTable, ProductDetailDrawer, BarcodeChooserModal, and NearExpirySlot share one guidance selector.
- [ ] Legacy `components/pos/ProductGrid.tsx` and `ProductCard.tsx` have zero imports and are removed.
- [ ] Shift open requires current entitlement/snapshot acknowledgement.
- [ ] Sealed fiscal payload/version remains unchanged.

### Evidence and obligation durability

- [ ] Device evidence and outbox insert atomically.
- [ ] Evidence-before-projection and projection-before-evidence both converge.
- [ ] Crash after server commit produces one evidence row.
- [ ] Aggregate posting remains active regardless of W-LOT entitlement.
- [ ] Every obligation stores the exact `aggregate_stock_movement_id`.
- [ ] Every applied obligation stores one linked batch movement.
- [ ] Linked batch movement `movement_id` equals the obligation’s aggregate movement ID.
- [ ] Retry never recreates aggregate movement.
- [ ] Unresolved entitlement creates actionable blocked work.
- [ ] Dead-letter recovery calls the sole lot writer.

### Deployment and handback

- [ ] Push 1 lands and runs preflight before any migration.
- [ ] Push 2 contains additive schema only.
- [ ] Push 3 contains dormant code with all flags false.
- [ ] Push 4 records conservative backfill and clean verification.
- [ ] Push 5 activates flags in the prescribed order.
- [ ] Cache, Horizon, queues, API, worker, scheduler, websocket, and web are restarted.
- [ ] Each push has a recorded rollback point.
- [ ] Always-on POS aggregate posting is never disabled during rollback.
- [ ] Task 23 passes on PostgreSQL and vitest.
- [ ] Task 24 handback ratchet passes with exact command transcripts and Q10–Q13 still OPEN.