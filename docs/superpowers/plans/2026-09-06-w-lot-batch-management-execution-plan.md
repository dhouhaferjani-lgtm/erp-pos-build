<!-- Rev 3, authored by Codex CLI (gpt-5.6-sol, high, read-only) on 2026-09-06 from rev 2 + plan gate r3; filed verbatim by the orchestrator. Status: awaiting plan gate r4. Rev 1 = 67c0805d4, rev 2 = 2d5268890. -->
<!-- Revision 3, produced read-only on 2026-09-06 from local dev HEAD 11e13eccd746241fb7546d84185310ce490d4464. This document replaces revision 2 verbatim. -->
# W-LOT Batch Management Execution Plan — Revision 3

**Status:** Dispatch-ready plan; implementation not started  
**Plan date:** 2026-09-06  
**Repository:** `/Users/houssamr/Projects/syneriva/apps/erp`  
**Verified local `dev` HEAD:** `11e13eccd746241fb7546d84185310ce490d4464`  
**Supersedes:** Revision 2 of this file  
**Authority:** `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md` v4  
**Owner register:** `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md`  
**Gate addressed:** `docs/superpowers/reviews/2026-09-06-w-lot-plan-codex-gate-r3.md`  
**Read-only revision:** No source, plan, Git, schema, or external state was changed while producing this revision.

---

## 0. Revision-3 change log and gate-r3 closure

No gate-r3 finding is rejected.

### 0.1 Carried PARTIAL/OPEN rows

| ID | Gate-r3 status | Revision-3 closure |
|---|---:|---|
| R1-1 | OPEN | §10 supplies exact existing and explicitly named new production files, full signatures, one red-first test with first assertion and command per task, lane, reviewer gate, and rollback. §11 supplies executable CI and staging commands. |
| R1-2 | PARTIAL | §§3, 8, and Tasks 4–5 use exact `batches.recall.request`; remove the live bypass at `BatchExpiry/Presentation/routes.php:29`; remove `batches.recall` from manager; create `general_manager` as manager permissions plus `batches.recall`, `batches.health`, and `treasury.manage_all_locations`; and block both sale and transfer at the requesting location. |
| R1-3 | PARTIAL | §§6.9, 7.6, and Tasks 19–21 make evidence atomic with the receipt and model one aggregate obligation with N lot-effect rows. Every effect links to an `inventory_batch_movements` row sharing the aggregate movement ID. |
| R1-4 | PARTIAL | §7.7 defines a claim-then-commit lease followed by advisory-first application. §9 includes `InventoryGlPostingBuffer`, `InventoryGlPostingService`, `GeneralLedgerService`, numbering and hash-chain advisory locks, and proves no obligation/advisory inversion. |
| R1-5 | PARTIAL | §11 provides five exact pushes, forwards all eight rollout flags through `docker-compose.staging.yml` and `apps/api/docker/entrypoint.sh`, uses `tenants:migrate-rolling --force`, uses tenant-aware permission seeding, and defines the provenance command before invocation. |
| R1-7 | PARTIAL | Task 6 covers `Batch`, `BatchStock`, `BatchResource`, FEFO totals/controllers, reservation consumers, generated DTOs, `BatchDetailPage`, `BatchListPage`, and removal of numeric quantity shadows. |
| R1-8 | PARTIAL | Tasks 7–9 assign `UpdateBatchRequest.php`, `BatchRepositoryInterface.php`, `BatchRepository.php`, and `Batch.php`; establish operation-UUID retry/conflict rules; freeze ordinary identity edits after first movement; and test no merge or value/quantity change. |
| R1-10 | PARTIAL | Tasks 13–15 name every producer, the provenance backfill command, and `LotLedgerDriftCensusTest`; the latter is added to the live PostgreSQL CI filter. |
| R1-11 | PARTIAL | Task 17 assigns `apps/pos/src/stores/terminalStore.ts:851`, makes refresh blocking before either shift-open branch, and tests disabled, unresolved, stale, wrong-company, and wrong-location states. |
| R1-12 | PARTIAL | Task 19 writes fiscal event, offline receipt, evidence submission, evidence legs, evidence outbox, and voucher changes through the same `withWriteTransaction('fiscal', ...)` handle. |
| R1-13 | PARTIAL | §12 maps every L1–L9 outcome to concrete tasks and withholds completion until live PostgreSQL, device crash, web, and staging acceptance all pass. |
| R2-B2 | PARTIAL | §6.10 replaces the single movement link with `fiscal_projection_lot_obligation_effects`; §7.6 requires N effects, common aggregate linkage, and exact signed reconciliation. |
| R2-B3 | PARTIAL | §5 and all PostgreSQL/SQLite schemas use `INTEGER` for canonical indexes, accept `0..999999`, and test `999999` and `1000000`. |
| R2-B4 | PARTIAL | §9 is the complete writer/lock census, including GL ownership and the no-row-lock lease claim. |
| R2-B5 | PARTIAL | §11 is executable under the current compose and tenant boot model and verifies per-tenant rolling migration output. |
| R2-B6 | OPEN | Every task packet in §10 contains concrete files, signatures, red assertion, command, lane, Convention-09 coverage, reviewer gate, and rollback. |
| R2-M4 | PARTIAL | Task 17 includes the active `TransactionCart.tsx`, `CartLineItem.tsx`, renderers, and actual `terminalStore.openShift()` owner. |
| R2-m1 | PARTIAL | This revision records and plans against HEAD `11e13eccd746241fb7546d84185310ce490d4464`; all source citations below were checked at that SHA. |

### 0.2 New gate-r3 findings

| Finding | Severity | Revision-3 closure |
|---|---:|---|
| Multi-lot obligation cardinality | BLOCKER | §§6.10 and 7.6 establish one obligation plus N ordered effects. Two-lot sale/refund, retry, and concurrent recovery are mandatory PostgreSQL tests. |
| Canonical index versus `SMALLINT` | BLOCKER | Canonical indexes are `INTEGER` in server and device schemas; shared boundaries are pinned at `999999`/`1000000`. |
| Recall authority and live bypass | BLOCKER | Exact permissions and roles are in §8; the direct endpoint at `routes.php:29` is removed, and only module-owned request/escalation endpoints remain. |
| Recall durable operation identity | BLOCKER | Both request and recall transition carry tenant/company-scoped client operation UUID and SHA-256 request fingerprint. Retry after transition returns the same request; conflicting reuse is 409. |
| Receipt/evidence transaction boundary | BLOCKER | Task 19 owns `receiptService.ts:558` and uses the same write transaction as `FiscalEventEngine.append()` at `FiscalEventEngine.ts:530`. Every insert boundary receives a crash test. |
| Concrete company entitlement boundary | BLOCKER | §7.2 and Task 2 name the interface, decision DTO, tenant-bound adapter, provider binding, central revision, cache fencing, HTTP/worker/console consumers, product requests, and ProductForm gate. |
| Executable five-push manifest | BLOCKER | §11 forwards flags, separates live compatibility refactors from guarded paths, defines all commands first, uses tenant-aware seeding, captures revision/cutover IDs, and verifies every tenant. |
| Executable dispatch and CI | BLOCKER | Task 10 uses the real `SubmitCountRequest.php`; Task 12 uses exact controller/web paths; Task 23 modifies the live `backend-test-pgsql` filter in `.github/workflows/ci.yml:1116`. |
| GL lock ownership and inversion | BLOCKER | §9 places GL advisory acquisition after inventory/obligation writes; recovery never retains an obligation row lock while acquiring `ProductCostLock`. |
| Float API/UI surfaces | MAJOR | Task 6 contains every named backend/API/web/generated surface and exact-string boundary tests. |
| Count as-of marker | MAJOR | §6.6 stores phase, observed instant, estimated server instant, and UUIDv7 movement marker per lot observation. Task 11 pins every required scenario. |
| Used-lot freeze | MAJOR | Tasks 8–9 guard the live request/repository/entity path and provide correction-specific operation identity and optimistic versioning. |
| L1 authorization census | MAJOR | §8 and Task 5 cover all batch routes and viewer/cashier/manager/general-manager/admin UI/API combinations. |
| Active cart and pre-open path | MAJOR | Task 17 assigns active cart components and both `openShift()` branches. |
| Generated types and enums | MAJOR | §7.1 names every enum/DTO and generated target; Task 6/23 run `typescript:transform`, drift, typecheck, and contract tests. |
| Stale verified HEAD | MINOR | Corrected in the document header and verification checklist. |

---

## 1. Outcome, invariants, and exclusions

W-LOT delivers one coherent lot-management package:

1. A branch recall request immediately holds that lot at the requesting location for sale and transfer.
2. A general manager or administrator may escalate the request to a company-wide recall.
3. Recall requests and their transitions are durable, append-only, scope-safe, and idempotent.
4. Ordinary used-lot identity mutation is frozen; audited identity correction remains possible.
5. DEFAULT/unknown holdings can be identified into physical lots without changing aggregate quantity, value, or WAC.
6. Lot quantities are numeric strings across PHP, JSON, generated TypeScript, web, POS, and SQLite boundaries.
7. Physical counting records explicit lot observations with observation-time movement markers.
8. All document, transfer, and POS allocation producers record provenance.
9. The lot-ledger census is durable, entitlement-aware, non-repairing, scheduled, and visible.
10. POS displays FEFO guidance and captures editable lot evidence without changing sealed fiscal payloads.
11. One accepted fiscal line creates exactly one aggregate stock effect and one durable lot obligation with one or more lot-effect legs.
12. All relevant paths pass second-company, second-location, permission, rerun, crash, and PostgreSQL concurrency checks.

The following remain outside this plan:

- Any oversell override.
- A new standalone lot catalogue.
- Mutation of sealed fiscal payload versions.
- Treating FEFO estimates as operator-captured evidence.
- Automatic repair of lot-ledger drift.
- Any Q10 release/rejection branch.
- Any Q11–Q13 cash/drawer implementation.
- Independent company-level licensing beyond validating company ownership and applying inherited tenant configuration.

---

## 2. Owner decisions still required

The following block is reproduced verbatim from `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:138-143`. All four remain **OPEN**.

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

Binding consequence:

- No migration enum, nullable branch column, endpoint, permission, button, command, test expectation, rollout step, or deferred code path may encode `released`, `rejected`, shared-drawer attribution, typed cash-movement classification, or historical cash alignment.
- Recall state contains only `requested` and `recalled`.
- A requested branch hold remains until company recall. No W-LOT code releases it.
- A future owner ruling requires a separate plan and additive migration.
- Oversell override remains separately OPEN/deferred.

---

## 3. Canonical vocabulary and rollout flags

| Term | Exact meaning | Writer |
|---|---|---|
| Branch lot hold | Location-scoped sale and transfer prohibition created by a recall request. | `BatchRecallWorkflowService::request()` |
| Company lot recall | Company-wide issue/transfer prohibition after escalation. | `BatchRecallWorkflowService::recall()` |
| Lot identity correction | Audited metadata correction preserving the physical lot, quantity, value, and custody. | `BatchIdentityCorrectionService::correct()` |
| Lot identification | Reattribution of DEFAULT/unknown holdings into identified lots while aggregate quantity and value remain unchanged. | `LotIdentificationService::identify()` |
| Lot count observation | Explicit physical quantity for one lot in one count phase, including explicit zero. | `InventoryCountingService::submitCount()` |
| Lot eligibility snapshot | Replace-all device cache for guidance; never stock authority. | `PosLotEligibilityService` and device repository |
| Lot evidence | Operator-captured or system-estimated allocation provenance linked outside the fiscal hash chain. | Producer or `LotEvidenceIngressService` |
| Projection lot obligation | Durable lot arm of an accepted fiscal line, linked to exactly one aggregate movement and N lot effects. | `PosReceiptLotProjectionService` |
| Lot-ledger census run | Durable, non-repairing aggregate-versus-lot comparison. | `LotLedgerDriftMonitorService` |
| W-LOT rollout revision | Immutable artifact SHA plus exact eight-flag JSON snapshot. | `WLotRolloutRevisionService` |
| W-LOT company cutover | Per-company prepared/active/rolled-back activation record tied to one rollout revision. | `WLotCutoverService` |

Exact rollout variables in `apps/api/config/lot_rollout.php`, all default `false`:

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

The POS aggregate receipt projection has no W-LOT switch and remains active.

---

## 4. Benchmark-first guarantee matrix — Convention 10

| ID | Guarantee | Odoo | ERPNext | Dolibarr (or NV with reason) | AutoERP today path:line | Gap | Decision |
|---|---|---|---|---|---|---|---|
| G-CREATE | Creating a lot is company-scoped and requires module plus permission. | Lot creation is inventory-authority controlled. | Batch creation is stock-role controlled. | NV — no pinned Dolibarr version/source fixture exists in the repository. | `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:22-24` | Route has module middleware but lacks explicit create middleware and company-entitlement decision. | MATCH |
| G-DUPLICATE | The same product/lot number may exist in a second company but not twice in one company/product/variant. | Company-scoped lot identity. | Company-scoped batch identity. | NV — no pinned source fixture. | `apps/api/database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php:27-31` | Effective partial uniqueness already separates variant and non-variant rows by company. | ALREADY |
| G-EDIT | Identity fields freeze after first movement; correction is audited and versioned. | Used-lot identity changes require controlled correction. | Batch split/controlled correction is separate from ordinary edit. | NV — no pinned source fixture. | `apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:17-25` | Ordinary update accepts number and dates after use. | MATCH |
| G-CANCEL | Deactivation never discards stock or history and requires zero quantity/reservation after explicit disposition. | Archival cannot erase stock history. | Disabled batch preserves ledger history. | NV — no pinned source fixture. | `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:26` | Current destroy surface has no complete permission/disposition contract. | MATCH |
| G-RERUN | Every recall, correction, identification, evidence, obligation, and cutover operation is exact-retry idempotent. | Operational retries must not duplicate stock moves. | Stock transactions use stable document identities. | NV — no pinned source fixture. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193-210` | Direct recall has no stable operation UUID or fingerprint. | MATCH |
| G-COMPANY | A route, worker, command, or retry cannot use a lot owned by a second company. | Company record rules. | Company permission boundary. | NV — no pinned source fixture. | `apps/api/app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php:50-54` | Current resolver discards company ID. | MATCH |
| G-LOCATION | A branch user sees/holds/moves only authorized locations; recall escalation is separately company-wide. | Warehouse/location rules. | Warehouse User Permissions. | NV — no pinned source fixture. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:215-254` | Expiring input/scope differs from expired; branch hold does not exist. | MATCH |
| G-PERMISSION | Read, trace, create, edit, deactivate, request, recall, write-off, identify, correct, count, capture, and health surfaces have explicit permissions. | Role/group authorization. | Role Permission Manager. | NV — no pinned source fixture. | `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:14-48` | Most routes lack route-level permission middleware. | MATCH |
| G-AUDIT | Every lifecycle or identity mutation has actor, operation identity, before/after state, reason, and timestamp. | Stock/quality history. | Stock Ledger and version history. | NV — no pinned source fixture. | `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134-141` | Current recall mutates booleans without a dedicated transition record. | MATCH |
| G-COUNT | Count observations retain their physical instant and movement watermark. | Inventory adjustments preserve count context. | Stock reconciliation records counted quantity. | NV — no pinned source fixture. | `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php:1302-1318` | Aggregate count markers exist; lot observations do not. | MATCH |
| G-MULTILOT | One fiscal line may map to N lot movements whose signed sum equals its aggregate movement. | Stock move lines split one order line across lots. | One invoice/stock line can allocate several batches. | NV — no pinned source fixture. | `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:260-307` | FEFO emits one batch movement per consumed lot; no durable N-leg obligation model exists. | MATCH |
| G-CAPTURE | Captured evidence commits atomically with the offline receipt and outbox. | Lot selections are operational records linked to the order. | Batch is stored with stock/invoice detail. | NV — no pinned source fixture. | `apps/pos/src/lib/offline/receiptService.ts:558-759` | Current receipt transaction contains no evidence rows/outbox. | MATCH |
| G-ESTIMATE | System FEFO remains visibly estimated and never becomes captured evidence. | Suggested/removal-strategy lot is distinct from operator confirmation. | Batch choice is explicit. | NV — no pinned source fixture. | `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2016-2084` | Existing allocation lacks provenance. | MATCH |
| G-OVERSELL | Oversell override behavior is not decided inside W-LOT. | Configuration-dependent. | Configuration-dependent. | NV — no pinned source fixture. | `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:404-408` | Requires separate owner decision. | DEFER |
| G-Q10 | Release/reject of a requested hold is not encoded until owner ruling. | Quality release exists in some installations. | Quality-authority disposition is domain-specific. | NV — no pinned source fixture. | `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:138-143` | OPEN owner question. | DEFER |

---

## 5. Canonical receipt-line identity

Shared vectors:

`packages/shared/contracts/w-lot-canonical-line-key-v1.json`

Implementations:

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

Exact grammar:

```text
sr-line:v1:event-v<event-version>:i<zero-based-index-padded-to-six-digits>
```

Example:

```text
sr-line:v1:event-v5:i000003
```

Rules:

- `eventVersion` is a positive integer.
- `zeroBasedIndex` is `0..999999`.
- Server and SQLite persist the index as `INTEGER`.
- The index is the immutable position in signed `payload.line_items[]`.
- Product, variant, lot, evidence order, database line order, discounts, and server insertion order never contribute to the key.
- Server ingress recomputes the key, verifies signed-line product/variant identity, and rejects mismatches.
- Required vectors cover versions 1–5; indexes 0, 9, 99, 32767, 32768, and 999999; two identical product lines; reversed evidence order; retry; `-1`; and `1000000`.

---

## 6. Complete schema contract

All PostgreSQL tenant uniques include `tenant_id` and `company_id` where the row is company-owned, even though database-per-tenant deployment already partitions tenants. All UUIDs are application-generated UUIDv7 unless a referenced existing row supplies them. All timestamps are `TIMESTAMPTZ`. Audit/history FKs use `ON DELETE RESTRICT`; disposable child/outbox rows use the explicitly stated action. No Q10–Q13 branch appears in any schema.

### 6.1 Central migration: immutable rollout revisions

**File:** `apps/api/database/migrations/2026_09_06_100000_create_w_lot_rollout_revisions.php`

`w_lot_rollout_revisions`:

| Column | Contract |
|---|---|
| `id` | UUID PK, NOT NULL, no database default |
| `artifact_sha` | CHAR(40), NOT NULL, no default, CHECK lowercase hexadecimal |
| `flags` | JSONB, NOT NULL, no default |
| `flags_fingerprint` | CHAR(64), NOT NULL, no default, CHECK lowercase hexadecimal |
| `created_by` | VARCHAR(255), NOT NULL, no default |
| `created_at` | TIMESTAMPTZ, NOT NULL, DEFAULT `CURRENT_TIMESTAMP` |

Constraints and indexes:

- UNIQUE `(artifact_sha, flags_fingerprint)`.
- CHECK `jsonb_typeof(flags) = 'object'`.
- CHECK the JSON object has exactly the eight keys in §3 and every value has JSON type `boolean`.
- `flags_fingerprint = sha256(canonical-json(flags))` is enforced by the service and PostgreSQL contract test.
- A PostgreSQL trigger rejects UPDATE and DELETE, making revisions immutable.
- Index `(created_at DESC)`.

JSONB is never exposed as an untyped array. Exact DTO:

```php
#[TypeScript]
final class WLotRolloutFlagsData extends Data
{
    public function __construct(
        public bool $recallWorkflow,
        public bool $identityCorrection,
        public bool $lotCounting,
        public bool $provenance,
        public bool $censusScheduler,
        public bool $posGuidance,
        public bool $posEvidenceAuthoring,
        public bool $posEvidenceProjection,
    ) {}
}
```

File: `apps/api/app/Modules/Company/Application/DTOs/WLotRolloutFlagsData.php`.

Rollback: leave the immutable table in place after any deployed revision. `down()` may drop it only in an offline development rollback after proving no company cutover references any revision ID.

### 6.2 Central migration: entitlement revision

**File:** `apps/api/database/migrations/2026_09_06_100001_add_module_entitlement_revision_to_tenants.php`

Add to `tenants`:

- `module_entitlement_revision BIGINT NOT NULL DEFAULT 1`.
- CHECK `module_entitlement_revision >= 1`.
- Index `idx_tenants_module_entitlement_revision`.

Every `vertical`, `enabled_extras`, or applicable vertical-config mutation increments the revision atomically with the mutation. Rollback leaves the column in place while any W-LOT revision/cutover exists.

### 6.3 Tenant migration: company cutovers

**File:** `apps/api/database/migrations/tenant/2026_09_06_100000_create_w_lot_company_cutovers.php`

`w_lot_company_cutovers`:

| Column | Contract |
|---|---|
| `id` | UUID PK, NOT NULL, no default |
| `tenant_id` | UUID, NOT NULL, no FK to central DB |
| `company_id` | UUID, NOT NULL, FK `companies.id`, ON DELETE RESTRICT |
| `rollout_revision_id` | UUID, NOT NULL, no cross-database FK |
| `artifact_sha` | CHAR(40), NOT NULL, CHECK lowercase hexadecimal |
| `flags_fingerprint` | CHAR(64), NOT NULL, CHECK lowercase hexadecimal |
| `entitlement_revision` | BIGINT, NOT NULL, CHECK `>= 1` |
| `state` | VARCHAR(16), NOT NULL, DEFAULT `'prepared'`, CHECK `prepared|active|rolled_back` |
| `client_operation_uuid` | UUID, NOT NULL |
| `prepared_by` | VARCHAR(255), NOT NULL |
| `prepared_at` | TIMESTAMPTZ, NOT NULL |
| `activated_at` | TIMESTAMPTZ, NULL, DEFAULT NULL |
| `rolled_back_at` | TIMESTAMPTZ, NULL, DEFAULT NULL |
| `created_at` | TIMESTAMPTZ, NOT NULL, DEFAULT `CURRENT_TIMESTAMP` |
| `updated_at` | TIMESTAMPTZ, NOT NULL, DEFAULT `CURRENT_TIMESTAMP` |

Constraints/indexes:

- UNIQUE `(tenant_id, company_id, client_operation_uuid)`.
- UNIQUE `(tenant_id, company_id, rollout_revision_id)`.
- Partial UNIQUE `(tenant_id, company_id)` WHERE `state='active'`.
- Index `(tenant_id, company_id, state)`.
- CHECK timestamps match state:
  - prepared: both terminal timestamps NULL;
  - active: `activated_at` NOT NULL and `rolled_back_at` NULL;
  - rolled_back: both NOT NULL.

Rollback: active or referenced cutovers are never deleted. Behavioral rollback transitions `active → rolled_back`.

### 6.4 Tenant migration: recall workflow

**File:** `apps/api/database/migrations/tenant/2026_09_06_100001_create_batch_recall_workflow_tables.php`

`batch_recall_requests`:

| Column | Contract |
|---|---|
| `id` | UUID PK, NOT NULL |
| `tenant_id` | UUID, NOT NULL |
| `company_id` | UUID, NOT NULL, FK companies, RESTRICT |
| `batch_id` | BIGINT, NOT NULL, FK product_batches, RESTRICT |
| `requesting_location_id` | UUID, NOT NULL, FK locations, RESTRICT |
| `requested_by` | UUID, NOT NULL, FK users, RESTRICT |
| `client_operation_uuid` | UUID, NOT NULL |
| `operation_fingerprint` | CHAR(64), NOT NULL, CHECK lowercase hexadecimal |
| `status` | VARCHAR(16), NOT NULL, DEFAULT `'requested'`, CHECK `requested|recalled` |
| `reason` | TEXT, NOT NULL, CHECK trimmed length `1..2000` |
| `requested_at` | TIMESTAMPTZ, NOT NULL |
| `recalled_at` | TIMESTAMPTZ, NULL |
| `recalled_by` | UUID, NULL, FK users, RESTRICT |
| `created_at` | TIMESTAMPTZ, NOT NULL, DEFAULT current timestamp |
| `updated_at` | TIMESTAMPTZ, NOT NULL, DEFAULT current timestamp |

Constraints/indexes:

- UNIQUE `(tenant_id, company_id, client_operation_uuid)`.
- Partial UNIQUE `(tenant_id, company_id, batch_id, requesting_location_id)` WHERE `status='requested'`.
- Index `(tenant_id, company_id, status, requested_at)`.
- Index `(tenant_id, company_id, batch_id, requesting_location_id, status)`.
- CHECK requested has null recall fields; recalled has both non-null and `recalled_at >= requested_at`.

`batch_recall_transitions`:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `tenant_id` | UUID NOT NULL |
| `company_id` | UUID NOT NULL, FK companies, RESTRICT |
| `request_id` | UUID NOT NULL, FK batch_recall_requests, RESTRICT |
| `batch_id` | BIGINT NOT NULL, FK product_batches, RESTRICT |
| `location_id` | UUID NOT NULL, FK locations, RESTRICT |
| `actor_id` | UUID NOT NULL, FK users, RESTRICT |
| `client_operation_uuid` | UUID NOT NULL |
| `operation_fingerprint` | CHAR(64) NOT NULL, lowercase hexadecimal |
| `from_status` | VARCHAR(16) NULL |
| `to_status` | VARCHAR(16) NOT NULL, CHECK `requested|recalled` |
| `reason` | TEXT NOT NULL, trimmed length `1..2000` |
| `occurred_at` | TIMESTAMPTZ NOT NULL |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT current timestamp |

Constraints/indexes:

- UNIQUE `(tenant_id, company_id, client_operation_uuid)`.
- UNIQUE `(tenant_id, company_id, request_id, to_status)`.
- CHECK only `NULL → requested` or `requested → recalled`.
- Index `(tenant_id, company_id, request_id, occurred_at)`.
- PostgreSQL trigger rejects UPDATE/DELETE.

Rollback: flags off, routes hidden, tables retained. No destructive down migration while rows exist.

### 6.5 Tenant migration: identity correction and identification

**File:** `apps/api/database/migrations/tenant/2026_09_06_100002_create_lot_identity_correction_and_identification_tables.php`

Add to `product_batches`:

- `identity_version BIGINT NOT NULL DEFAULT 1 CHECK identity_version >= 1`.
- Index `(company_id, identity_version)`.

`batch_identity_corrections`:

- `id UUID PK NOT NULL`.
- `tenant_id UUID NOT NULL`.
- `company_id UUID NOT NULL FK companies RESTRICT`.
- `batch_id BIGINT NOT NULL FK product_batches RESTRICT`.
- `client_operation_uuid UUID NOT NULL`.
- `operation_fingerprint CHAR(64) NOT NULL CHECK hex`.
- `expected_identity_version BIGINT NOT NULL CHECK >=1`.
- `applied_identity_version BIGINT NOT NULL CHECK = expected_identity_version + 1`.
- `old_batch_number VARCHAR(100) NOT NULL`.
- `new_batch_number VARCHAR(100) NOT NULL`.
- `old_manufacturing_date DATE NULL`.
- `new_manufacturing_date DATE NULL`.
- `old_expiry_date DATE NULL`.
- `new_expiry_date DATE NULL`.
- `reason TEXT NOT NULL CHECK trimmed length 1..2000`.
- `evidence_reference VARCHAR(255) NOT NULL CHECK trimmed non-empty`.
- `actor_id UUID NOT NULL FK users RESTRICT`.
- `occurred_at TIMESTAMPTZ NOT NULL`.
- `created_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- UNIQUE `(tenant_id, company_id, client_operation_uuid)`.
- UNIQUE `(tenant_id, company_id, batch_id, applied_identity_version)`.
- CHECK at least one identity field changed.
- Index `(tenant_id, company_id, batch_id, occurred_at)`.
- UPDATE/DELETE rejected by trigger.

`lot_identifications`:

- `id UUID PK`.
- `tenant_id UUID NOT NULL`.
- `company_id UUID NOT NULL FK companies RESTRICT`.
- `source_batch_id BIGINT NOT NULL FK product_batches RESTRICT`.
- `product_id UUID NOT NULL FK products RESTRICT`.
- `variant_id UUID NULL FK product_variants RESTRICT`.
- `location_id UUID NOT NULL FK locations RESTRICT`.
- `client_operation_uuid UUID NOT NULL`.
- `operation_fingerprint CHAR(64) NOT NULL CHECK hex`.
- `source_identity_version BIGINT NOT NULL CHECK >=1`.
- `aggregate_stock_movement_id UUID NOT NULL FK stock_movements RESTRICT`.
- `source_quantity_before DECIMAL(20,4) NOT NULL CHECK >=0`.
- `source_quantity_after DECIMAL(20,4) NOT NULL CHECK >=0`.
- `reason TEXT NOT NULL CHECK trimmed length 1..2000`.
- `evidence_reference VARCHAR(255) NOT NULL`.
- `actor_id UUID NOT NULL FK users RESTRICT`.
- `applied_at TIMESTAMPTZ NOT NULL`.
- `created_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- UNIQUE `(tenant_id, company_id, client_operation_uuid)`.
- UNIQUE `(tenant_id, company_id, aggregate_stock_movement_id)`.
- Index `(tenant_id, company_id, source_batch_id, location_id)`.

`lot_identification_lines`:

- `id UUID PK`.
- `tenant_id UUID NOT NULL`.
- `company_id UUID NOT NULL FK companies RESTRICT`.
- `identification_id UUID NOT NULL FK lot_identifications RESTRICT`.
- `ordinal INTEGER NOT NULL CHECK ordinal >= 0`.
- `target_batch_id BIGINT NOT NULL FK product_batches RESTRICT`.
- `quantity DECIMAL(20,4) NOT NULL CHECK quantity > 0`.
- `inventory_batch_movement_id BIGINT NOT NULL FK inventory_batch_movements RESTRICT`.
- `created_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- UNIQUE `(tenant_id, company_id, identification_id, ordinal)`.
- UNIQUE `(tenant_id, company_id, identification_id, target_batch_id)`.
- UNIQUE `(tenant_id, company_id, inventory_batch_movement_id)`.
- Index `(tenant_id, company_id, target_batch_id)`.

Application invariants verify source-negative plus target-positive lot movements sum to zero and all reference the one aggregate zero-value justification movement. Rollback retains lineage and disables authoring.

### 6.6 Tenant migration: lot count observations

**File:** `apps/api/database/migrations/tenant/2026_09_06_100003_create_inventory_counting_item_lots.php`

`inventory_counting_item_lots`:

- `id UUID PK`.
- `tenant_id UUID NOT NULL`.
- `company_id UUID NOT NULL FK companies RESTRICT`.
- `counting_id UUID NOT NULL FK inventory_countings CASCADE`.
- `counting_item_id UUID NOT NULL FK inventory_counting_items CASCADE`.
- `count_number SMALLINT NOT NULL CHECK 1..3`.
- `product_id UUID NOT NULL FK products RESTRICT`.
- `variant_id UUID NULL FK product_variants RESTRICT`.
- `location_id UUID NOT NULL FK locations RESTRICT`.
- `batch_id BIGINT NOT NULL FK product_batches RESTRICT`.
- `observed_quantity DECIMAL(20,4) NOT NULL CHECK >=0`.
- `observed_at_device TIMESTAMPTZ NULL`.
- `observed_at_estimate TIMESTAMPTZ NOT NULL`.
- `observed_movement_marker UUID NULL`, deliberately not an FK so archival cannot destroy the ordering token.
- `submitted_by UUID NOT NULL FK users RESTRICT`.
- `created_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- `updated_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- UNIQUE `(tenant_id, company_id, counting_item_id, count_number, batch_id)`.
- Index `(tenant_id, company_id, counting_id, count_number)`.
- Index `(tenant_id, company_id, batch_id, location_id)`.
- CHECK device instant, when supplied, is within the existing `SubmitCountRequest` drift envelope.

Missing row means unobserved. `0.0000` means explicitly observed empty. No “DEFAULT remainder” is inferred.

Rollback retains count evidence; authoring is disabled by flag.

### 6.7 Tenant migration: provenance

**File:** `apps/api/database/migrations/tenant/2026_09_06_100004_add_lot_provenance_to_allocation_producers.php`

Add identically to `document_lines`, `stock_transfer_line_batch_allocations`, and `pos_receipt_line_batch_allocations`:

- `lot_provenance VARCHAR(32) NOT NULL DEFAULT 'unknown' CHECK operator_captured|system_fefo_estimate|unknown`.
- `lot_evidence_actor_id UUID NULL FK users RESTRICT`.
- `lot_evidence_captured_at TIMESTAMPTZ NULL`.
- `lot_evidence_reference VARCHAR(255) NULL`.
- Index `lot_provenance`.
- Compound index appropriate to each parent plus `lot_provenance`.

Checks:

- `operator_captured`: actor, captured time, and reference all non-null.
- `system_fefo_estimate`: captured time and reference non-null; actor null.
- `unknown`: all evidence fields null.

Historical migration assigns only `unknown`. The backfill command may promote provable FEFO rows to `system_fefo_estimate`; it never produces `operator_captured`.

Rollback leaves columns/data and turns presentation off.

### 6.8 Tenant migration: durable census runs

**File:** `apps/api/database/migrations/tenant/2026_09_06_100005_create_lot_ledger_census_runs.php`

`lot_ledger_census_runs`:

- `id UUID PK`.
- `tenant_id UUID NOT NULL`.
- `company_id UUID NOT NULL FK companies RESTRICT`.
- `entitlement_state VARCHAR(24) NOT NULL CHECK enabled|disabled|unresolved`.
- `entitlement_revision BIGINT NULL CHECK >=1 when non-null`.
- `status VARCHAR(16) NOT NULL DEFAULT running CHECK running|clean|drifted|failed|stale`.
- `started_at TIMESTAMPTZ NOT NULL`.
- `heartbeat_at TIMESTAMPTZ NOT NULL`.
- `finished_at TIMESTAMPTZ NULL`.
- `cohort_tuple_count BIGINT NOT NULL DEFAULT 0 CHECK >=0`.
- `compared_tuple_count BIGINT NOT NULL DEFAULT 0 CHECK >=0`.
- `drift_tuple_count BIGINT NOT NULL DEFAULT 0 CHECK >=0`.
- `net_drift DECIMAL(20,4) NOT NULL DEFAULT 0`.
- `absolute_drift DECIMAL(20,4) NOT NULL DEFAULT 0 CHECK >=0`.
- `last_error TEXT NULL`.
- `notified_at TIMESTAMPTZ NULL`.
- `created_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- `updated_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- Partial UNIQUE `(tenant_id, company_id)` WHERE status=`running`.
- Index `(tenant_id, company_id, finished_at DESC)`.
- Index `(tenant_id, status, heartbeat_at)`.
- State/timestamp checks described in §7.4.

No JSONB is added here; exact drift rows remain command output/log evidence rather than an unbounded JSON document.

### 6.9 Tenant migration: POS evidence submissions

**File:** `apps/api/database/migrations/tenant/2026_09_06_100006_create_pos_receipt_line_lot_evidence.php`

`pos_receipt_lot_evidence_submissions`:

- `id UUID PK`.
- `tenant_id UUID NOT NULL`.
- `company_id UUID NOT NULL FK companies RESTRICT`.
- `location_id UUID NOT NULL FK locations RESTRICT`.
- `terminal_id UUID NOT NULL FK pos_terminals RESTRICT`.
- `fiscal_event_id UUID NOT NULL FK fiscal_events RESTRICT`.
- `w_lot_cutover_id UUID NOT NULL FK w_lot_company_cutovers RESTRICT`.
- `client_operation_uuid UUID NOT NULL`.
- `operation_fingerprint CHAR(64) NOT NULL CHECK hex`.
- `receipt_hash CHAR(64) NOT NULL CHECK hex`.
- `canonical_key_version SMALLINT NOT NULL DEFAULT 1 CHECK =1`.
- `entitlement_revision BIGINT NOT NULL CHECK >=1`.
- `snapshot_revision BIGINT NOT NULL CHECK >=1`.
- `snapshot_watermark VARCHAR(128) NOT NULL`.
- `captured_by UUID NOT NULL FK users RESTRICT`.
- `captured_at_device TIMESTAMPTZ NOT NULL`.
- `received_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- `created_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- UNIQUE `(tenant_id, company_id, terminal_id, client_operation_uuid)`.
- UNIQUE `(tenant_id, company_id, fiscal_event_id, id)`.
- Index `(tenant_id, company_id, fiscal_event_id)`.
- Index `(tenant_id, company_id, location_id, captured_at_device)`.

`pos_receipt_line_lot_evidence`:

- `id UUID PK`.
- `tenant_id UUID NOT NULL`.
- `company_id UUID NOT NULL FK companies RESTRICT`.
- `submission_id UUID NOT NULL FK pos_receipt_lot_evidence_submissions RESTRICT`.
- `fiscal_event_id UUID NOT NULL FK fiscal_events RESTRICT`.
- `canonical_line_key VARCHAR(64) NOT NULL`.
- `canonical_line_index INTEGER NOT NULL CHECK 0..999999`.
- `product_id UUID NOT NULL FK products RESTRICT`.
- `variant_id UUID NULL FK product_variants RESTRICT`.
- `batch_id BIGINT NOT NULL FK product_batches RESTRICT`.
- `quantity DECIMAL(20,4) NOT NULL CHECK >0`.
- `provenance VARCHAR(32) NOT NULL DEFAULT 'operator_captured' CHECK = 'operator_captured'`.
- `ordinal INTEGER NOT NULL CHECK >=0`.
- `created_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- UNIQUE `(tenant_id, company_id, submission_id, ordinal)`.
- UNIQUE `(tenant_id, company_id, fiscal_event_id, canonical_line_key, batch_id)`.
- Index `(tenant_id, company_id, batch_id, fiscal_event_id)`.

One operation UUID belongs to the submission header, allowing N evidence legs. The service validates hash, terminal/company/location, signed product/variant, positive quantities, and exact line totals.

### 6.10 Tenant migration: aggregate obligations and N lot effects

**File:** `apps/api/database/migrations/tenant/2026_09_06_100007_create_fiscal_projection_lot_obligations.php`

`fiscal_projection_lot_obligations`:

- `id UUID PK`.
- `tenant_id UUID NOT NULL`.
- `company_id UUID NOT NULL FK companies RESTRICT`.
- `location_id UUID NOT NULL FK locations RESTRICT`.
- `terminal_id UUID NOT NULL FK pos_terminals RESTRICT`.
- `fiscal_event_id UUID NOT NULL FK fiscal_events RESTRICT`.
- `receipt_line_id UUID NOT NULL FK pos_receipt_lines RESTRICT`.
- `w_lot_cutover_id UUID NOT NULL FK w_lot_company_cutovers RESTRICT`.
- `canonical_line_key VARCHAR(64) NOT NULL`.
- `canonical_line_index INTEGER NOT NULL CHECK 0..999999`.
- `canonical_key_version SMALLINT NOT NULL DEFAULT 1 CHECK =1`.
- `lot_operation VARCHAR(16) NOT NULL CHECK consume|restore|scrap`.
- `product_id UUID NOT NULL FK products RESTRICT`.
- `variant_id UUID NULL FK product_variants RESTRICT`.
- `expected_quantity DECIMAL(20,4) NOT NULL CHECK >0`.
- `entitlement_state VARCHAR(24) NOT NULL CHECK enabled|disabled|unresolved`.
- `entitlement_revision BIGINT NULL CHECK >=1 when non-null`.
- `aggregate_stock_movement_id UUID NOT NULL FK stock_movements RESTRICT`.
- `state VARCHAR(24) NOT NULL CHECK pending_evidence|ready|applying|blocked|applied|dead_letter`.
- `block_reason VARCHAR(64) NULL`.
- `attempt_count INTEGER NOT NULL DEFAULT 0 CHECK >=0`.
- `next_attempt_at TIMESTAMPTZ NULL`.
- `lease_token UUID NULL`.
- `lease_owner VARCHAR(128) NULL`.
- `lease_expires_at TIMESTAMPTZ NULL`.
- `last_error TEXT NULL`.
- `created_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- `updated_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- `applied_at TIMESTAMPTZ NULL`.
- `dead_lettered_at TIMESTAMPTZ NULL`.
- UNIQUE `(tenant_id, company_id, fiscal_event_id, canonical_line_key, lot_operation)`.
- UNIQUE `(tenant_id, company_id, aggregate_stock_movement_id, canonical_line_key, lot_operation)`.
- Index `(tenant_id, company_id, state, next_attempt_at)`.
- Index `(tenant_id, lease_expires_at)`.
- State/lease/timestamp checks follow §7.5.

`fiscal_projection_lot_obligation_effects`:

- `id UUID PK`.
- `tenant_id UUID NOT NULL`.
- `company_id UUID NOT NULL FK companies RESTRICT`.
- `obligation_id UUID NOT NULL FK fiscal_projection_lot_obligations RESTRICT`.
- `ordinal INTEGER NOT NULL CHECK >=0`.
- `batch_id BIGINT NOT NULL FK product_batches RESTRICT`.
- `evidence_id UUID NULL FK pos_receipt_line_lot_evidence RESTRICT`.
- `signed_quantity DECIMAL(20,4) NOT NULL CHECK <>0`.
- `state VARCHAR(16) NOT NULL DEFAULT planned CHECK planned|applied`.
- `inventory_batch_movement_id BIGINT NULL FK inventory_batch_movements RESTRICT`.
- `created_at TIMESTAMPTZ NOT NULL DEFAULT current timestamp`.
- `applied_at TIMESTAMPTZ NULL`.
- UNIQUE `(tenant_id, company_id, obligation_id, ordinal)`.
- UNIQUE `(tenant_id, company_id, obligation_id, batch_id)`.
- Partial UNIQUE `(tenant_id, company_id, inventory_batch_movement_id)` WHERE movement ID is not null.
- Index `(tenant_id, company_id, obligation_id, state)`.
- CHECK planned has no movement/applied time; applied has both.

An obligation may reach `applied` only when:

1. At least one effect exists.
2. Every effect is applied.
3. Every linked `inventory_batch_movement.movement_id` equals the obligation’s `aggregate_stock_movement_id`.
4. Every linked movement quantity equals its effect’s `signed_quantity`.
5. Sum of effect signed quantities equals the aggregate movement quantity.
6. Absolute sum equals `expected_quantity`.
7. Consume/scrap effects are negative; restore effects are positive.

These invariants are checked under the same root transaction and by a PostgreSQL deferred constraint trigger. A two-lot FEFO consumption therefore creates two effect and two inventory-batch-movement rows under one obligation.

### 6.11 Tenant permission migration

**File:** `apps/api/database/migrations/tenant/2026_09_06_100008_seed_w_lot_permissions.php`

Idempotently creates:

```text
batches.recall.request
batches.identify
batches.correct-identity
batches.count-lots
batches.health
pos.capture-lot-evidence
```

It does not silently overwrite customized role grants. Canonical built-in-role reconciliation is owned by `RolesAndPermissionsSeeder` and the tenant-aware staging command. `down()` does not delete permissions that have assignments or audit history.

### 6.12 Device SQLite v68

**File:** `apps/pos/src/lib/db/migrations.ts`

`branch_lot_snapshots`:

- `id TEXT PRIMARY KEY`.
- `tenant_id`, `company_id`, `location_id`, `terminal_id`, `cutover_id` TEXT NOT NULL.
- `entitlement_state TEXT NOT NULL CHECK enabled|disabled|unresolved`.
- `entitlement_revision INTEGER NOT NULL CHECK >=1`.
- `snapshot_revision INTEGER NOT NULL CHECK >=1`.
- `watermark TEXT NOT NULL`.
- `captured_at TEXT NOT NULL`.
- UNIQUE `(tenant_id, company_id, location_id, terminal_id)`.
- Index `(company_id, location_id, snapshot_revision)`.

`branch_lot_eligibility`:

- `id TEXT PRIMARY KEY`.
- `snapshot_id TEXT NOT NULL FK branch_lot_snapshots ON DELETE CASCADE`.
- `tenant_id`, `company_id`, `location_id`, `product_id`, `batch_uuid`, `batch_number` TEXT NOT NULL.
- `variant_id TEXT NULL`; `variant_key TEXT NOT NULL DEFAULT ''`.
- `batch_id INTEGER NOT NULL`.
- `expiry_date TEXT NULL`.
- `available_quantity TEXT NOT NULL CHECK decimal-string grammar`.
- `position INTEGER NOT NULL CHECK >=0`.
- `is_active INTEGER NOT NULL CHECK 0|1`.
- `is_recalled INTEGER NOT NULL CHECK 0|1`.
- UNIQUE `(snapshot_id, product_id, variant_key, batch_id)`.
- Index `(tenant_id, company_id, location_id, product_id, variant_key, position)`.

Replacement deletes/inserts eligibility and updates the snapshot in one SQLite transaction.

### 6.13 Device SQLite v69

`receipt_lot_evidence_submissions`:

- `id`, `tenant_id`, `company_id`, `location_id`, `terminal_id`, `fiscal_event_id`, `cutover_id`, `client_operation_uuid`, `operation_fingerprint`, `receipt_hash`, `snapshot_watermark`, `captured_by`, `captured_at_device`, `created_at` TEXT NOT NULL.
- `canonical_key_version INTEGER NOT NULL DEFAULT 1`.
- `entitlement_revision`, `snapshot_revision` INTEGER NOT NULL.
- PK `id`.
- UNIQUE `(tenant_id, company_id, terminal_id, client_operation_uuid)`.

`receipt_line_lot_evidence`:

- `id`, `submission_id`, `fiscal_event_id`, `canonical_line_key`, `product_id`, `batch_uuid`, `quantity`, `created_at` TEXT NOT NULL.
- `variant_id TEXT NULL`.
- `canonical_line_index INTEGER NOT NULL CHECK 0..999999`.
- `batch_id INTEGER NOT NULL`.
- `ordinal INTEGER NOT NULL CHECK >=0`.
- `provenance TEXT NOT NULL CHECK = operator_captured`.
- PK `id`.
- FK submission ON DELETE RESTRICT.
- UNIQUE `(submission_id, ordinal)`.
- UNIQUE `(fiscal_event_id, canonical_line_key, batch_id)`.

`receipt_lot_evidence_outbox`:

- `id TEXT PRIMARY KEY`.
- `submission_id TEXT NOT NULL FK receipt_lot_evidence_submissions RESTRICT`.
- `state TEXT NOT NULL DEFAULT pending CHECK pending|sending|acknowledged|retryable|dead_letter`.
- `attempt_count INTEGER NOT NULL DEFAULT 0 CHECK >=0`.
- `lease_token TEXT NULL`.
- `lease_owner TEXT NULL`.
- `lease_expires_at TEXT NULL`.
- `next_attempt_at TEXT NULL`.
- `last_error TEXT NULL`.
- `created_at`, `updated_at` TEXT NOT NULL.
- `acknowledged_at`, `dead_lettered_at` TEXT NULL.
- UNIQUE `(submission_id)`.
- Index `(state, next_attempt_at)`.
- State/lease checks mirror §7.5.

---

## 7. Enums, DTOs, state machines, and service contracts

### 7.1 Typed enum and DTO inventory

Create these backend enums and generate their TypeScript definitions into `packages/shared/types/generated.d.ts`:

- `CompanyEntitlementState`: `enabled|disabled|unresolved`
- `WLotCutoverState`: `prepared|active|rolled_back`
- `BatchRecallRequestStatus`: `requested|recalled`
- `LotProvenance`: `operator_captured|system_fefo_estimate|unknown`
- `LotOperation`: `consume|restore|scrap`
- `LotLedgerCensusStatus`: `running|clean|drifted|failed|stale`
- `LotObligationState`: `pending_evidence|ready|applying|blocked|applied|dead_letter`
- `LotObligationEffectState`: `planned|applied`
- `LotEvidenceOutboxState`: `pending|sending|acknowledged|retryable|dead_letter`

Exact DTO files:

- `apps/api/app/Modules/Company/Application/DTOs/CompanyEntitlementDecisionData.php`
- `apps/api/app/Modules/Company/Application/DTOs/WLotRolloutFlagsData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchStockData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchMovementData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/RequestBatchRecallData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/CorrectBatchIdentityData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/IdentifyLotData.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/LotCountObservationData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/LotEligibilitySnapshotData.php`
- `apps/api/app/Modules/Fiscal/Application/DTOs/LotEvidenceSubmissionData.php`
- `apps/api/app/Modules/Fiscal/Application/DTOs/LotObligationData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/LotLedgerCensusRunData.php`

All quantity properties are `numeric-string` in PHPDoc and `string` in generated TypeScript. Hand-written batch-domain interfaces in `apps/web/src/features/batches/types.ts:14-83,235-281` are removed or reduced to UI-only view composition over generated DTOs.

Generation command:

```bash
cd apps/api
php artisan typescript:transform
cd ../..
pnpm typecheck
```

### 7.2 Company entitlement contract

```php
interface CompanyEntitlementResolver
{
    public function resolve(
        string $tenantId,
        string $companyId,
        string $module,
    ): CompanyEntitlementDecisionData;
}
```

```php
#[TypeScript]
final class CompanyEntitlementDecisionData extends Data
{
    public function __construct(
        public CompanyEntitlementState $state,
        public string $tenantId,
        public string $companyId,
        public string $module,
        public ?int $revision,
        public ?string $cutoverId,
        public string $source,
        public CarbonImmutable $resolvedAt,
        public ?string $reason,
    ) {}
}
```

Files:

- Contract: `apps/api/app/Shared/Contracts/Company/CompanyEntitlementResolver.php`
- Adapter: `apps/api/app/Modules/Company/Application/Services/TenantBoundCompanyEntitlementResolver.php`
- Binding: `apps/api/app/Modules/Company/CompanyServiceProvider.php`
- Legacy adapter update: `apps/api/app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php`
- Cache owner: `apps/api/app/Services/CompanyConfigService.php`
- Mutation owners: `apps/api/app/Http/Controllers/Api/Admin/VerticalConfigController.php`, `apps/api/app/Observers/TenantObserver.php`
- Tenancy boundary: `apps/api/config/tenancy.php:38-43`

Algorithm:

1. Require or initialize the persisted tenant context.
2. Query the tenant-local company by both `tenant_id` and `company_id`.
3. Read the central tenant’s configuration and monotonic revision.
4. Resolve the inherited `BatchExpiry` module state.
5. Re-read revision before publishing/caching the result.
6. Cache under `tenant_config:{tenant}:{revision}`. A stale computation can only populate an old, unreachable key.
7. Missing company, failed central lookup, tenancy failure, or revision race after bounded retry produces `unresolved`.
8. Product create/update, count, correction, identification, transfer, POS eligibility, evidence ingress, projection, recovery, census, HTTP, queue, and console consumers all use the same decision.
9. A concurrent revision change causes the transactional lot write to block/retry; it never proceeds under an obsolete revision.

`CreateProductRequest` and `UpdateProductRequest` may accept `requires_batch_tracking=true` only under `enabled`. Existing unentitled flagged data may be turned off or left untouched during unrelated updates but cannot authorize a lot write. `ProductForm.tsx:173` shows batch tracking only when both Inventory and `BatchExpiry` entitlement/read authority pass.

### 7.3 Recall state machine and signatures

```text
create request: ∅ → requested
escalate:       requested → recalled
```

Enforcing fields:

- `status`
- `requested_at`, `requested_by`
- `recalled_at`, `recalled_by`
- request and transition operation UUID/fingerprint
- append-only transition rows
- `product_batches.is_recalled` as compatibility projection for `recalled`

No other state or transition exists.

```php
interface BatchRecallWorkflowService
{
    public function request(
        RequestBatchRecallData $data,
        User $actor,
    ): BatchRecallRequest;

    public function recall(
        string $requestId,
        string $clientOperationUuid,
        string $reason,
        User $actor,
    ): BatchRecallRequest;
}
```

`RequestBatchRecallData` contains batch UUID, requesting location UUID, reason, and client operation UUID.

Retry rules:

- Same tenant/company/operation UUID and same canonical fingerprint: return the existing request in its current state, including after recall.
- Same operation UUID with different payload: 409.
- Concurrent identical requests: one insert; loser selects and returns it.
- Concurrent distinct operations on the same open batch/location: one open request; the loser returns 409 with the winning request ID.
- Both request and recall recheck membership/role/company/location within the write transaction.

### 7.4 Census state machine

```text
∅ → running
running → clean | drifted | failed | stale
```

- `running`: `finished_at` and terminal fields null.
- `clean`: compared count equals cohort count, drift count zero, absolute/net zero.
- `drifted`: drift count positive and `finished_at` non-null.
- `failed`: `last_error` and `finished_at` non-null.
- `stale`: heartbeat older than 180 minutes, `last_error` and `finished_at` non-null.
- Disabled companies are recorded as `entitlement_state=disabled` but excluded from the entitled comparison.
- Unresolved entitlement produces an actionable failed/unresolved run; it never reports clean.

### 7.5 Evidence outbox and obligation machines

Evidence outbox:

```text
pending → sending → acknowledged
sending → retryable → sending
sending|retryable → dead_letter
expired sending lease → retryable
```

Obligation:

```text
pending_evidence → ready
pending_evidence|ready|applying → blocked
blocked → ready
ready → applying → applied
applying → ready             on retryable rollback
blocked|applying → dead_letter
expired applying lease → ready|blocked
```

Enforcing fields are state, attempt count, next attempt, lease token/owner/expiry, error, applied/dead-letter timestamps, entitlement revision/cutover, effect state, and linked movements.

Lease claim is a short committed transaction. It sets lease fields with `FOR UPDATE SKIP LOCKED` and commits before inventory advisory locks are requested. The persisted lease, not a retained row lock, gives ownership.

### 7.6 POS lot obligation contract

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
        CompanyEntitlementDecisionData $entitlement,
    ): FiscalProjectionLotObligation;

    public function apply(
        string $obligationId,
        string $leaseToken,
    ): FiscalProjectionLotObligation;
}
```

```php
interface LotEvidenceIngressService
{
    public function ingest(
        FiscalEvent $event,
        LotEvidenceSubmissionData $submission,
        Terminal $terminal,
    ): LotEvidenceIngressResultData;
}
```

For captured evidence, effects derive from every submitted evidence leg. Without captured evidence, enabled projection may generate N FEFO-estimated effects. One obligation is never reduced to one movement. Parent `applied` is written only after the complete child set satisfies §6.10.

### 7.7 Inventory mutation, correction, identification, and counting

```php
interface BatchStockMutationService
{
    public function receive(BatchStockMutationData $mutation): InventoryBatchMovement;

    public function issue(BatchStockMutationData $mutation): InventoryBatchMovement;

    public function transfer(
        BatchStockTransferMutationData $mutation,
    ): BatchStockTransferResultData;

    public function reserve(BatchReservationMutationData $mutation): void;

    public function releaseReservation(BatchReservationMutationData $mutation): void;
}
```

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
        string $clientOperationUuid,
        User $actor,
    ): LotIdentification;
}
```

Existing counting signature becomes:

```php
public function submitCount(
    InventoryCountingItem $item,
    int $countNumber,
    string $quantity,
    array $lotObservations,
    ?string $notes,
    User $user,
    ?CarbonInterface $countedAtDevice = null,
    ?CarbonInterface $deviceNow = null,
): void;
```

`$lotObservations` is `list<LotCountObservationData>`.

Identity freeze:

- Ordinary update may change notes and non-identity presentation fields.
- Batch number, manufacturing date, and expiry date fail with 409 after any `inventory_batch_movements` history.
- A correction requires `batches.correct-identity`, expected identity version, operation UUID, reason, evidence, company entitlement, and uniqueness under the live partial indexes.
- Correction changes no stock, reservation, movement, cost, WAC, document allocation, evidence, or obligation ownership.
- Identification requires source DEFAULT/unknown status, sufficient available source quantity, one aggregate zero-value justification movement, exact source-negative and target-positive lot legs, and no history merge.

### 7.8 Full Artisan command signatures

```text
inventory:w-lot-preflight
    {--tenant= : Restrict to one tenant UUID}
    {--all-tenants : Deliberate fleet-wide execution}
    {--company= : Restrict to one company UUID}
    {--format=text : text|json}
    {--expected-revision= : Required rollout revision UUID}
    {--expected-cutover-manifest= : JSON file containing expected company cutover IDs}
    {--fail-on-drift : Exit non-zero on quantitative drift}
    {--fail-on-unresolved : Exit non-zero on entitlement, migration, permission, obligation, or manifest uncertainty}
```

```text
wlot:create-rollout-revision
    {--artifact-sha= : Exact 40-character Git SHA}
    {--flags-json= : File containing the exact eight boolean flags}
    {--actor= : Required deployment actor identifier}
    {--format=text : text|json}
```

```text
wlot:cutover
    {action : prepare|activate|rollback}
    {--tenant= : Restrict to one tenant UUID}
    {--all-tenants : Deliberate fleet-wide execution}
    {--company= : Restrict to one company UUID}
    {--revision= : Rollout revision UUID; required for prepare}
    {--manifest= : Prepare-output JSON; required for fleet activate/rollback}
    {--cutover=* : Explicit cutover UUID; repeatable}
    {--operation= : Stable operation UUID}
    {--actor= : Required deployment actor identifier}
    {--format=text : text|json}
    {--force : Required for activate/rollback}
```

```text
inventory:backfill-lot-provenance
    {--tenant= : Restrict to one tenant UUID}
    {--all-tenants : Deliberate fleet-wide execution}
    {--company= : Restrict to one company UUID}
    {--table=* : document_lines|stock_transfer_line_batch_allocations|pos_receipt_line_batch_allocations}
    {--chunk=500 : Positive chunk size}
    {--operation= : Stable operation UUID}
    {--dry-run : Report only}
    {--execute : Persist; mutually exclusive with dry-run}
```

```text
inventory:lot-drift-monitor
    {--tenant= : Restrict to one tenant UUID}
    {--all-tenants : Deliberate fleet-wide execution}
    {--company= : Restrict to one company UUID}
    {--stale-after=180 : Minutes before a running record becomes stale}
    {--fail-on-drift : Exit non-zero on drift}
    {--fail-on-unresolved : Exit non-zero on unresolved entitlement/coverage}
```

```text
fiscal:recover-lot-obligations
    {--tenant= : Restrict to one tenant UUID}
    {--all-tenants : Deliberate fleet-wide execution}
    {--company= : Restrict to one company UUID}
    {--obligation= : Restrict to one obligation UUID}
    {--limit=100 : Maximum claims per tenant}
    {--lease-seconds=60 : Positive lease duration}
    {--max-attempts=12 : Dead-letter threshold}
    {--dry-run : Report eligible rows without leasing}
    {--execute : Claim/apply; mutually exclusive with dry-run}
```

Existing `tenants:migrate-rolling` remains exactly:

```text
tenants:migrate-rolling
    {--tenant= : Restrict the rollout to a single tenant UUID (default: every tenant)}
    {--force : Run migrations without the interactive confirmation prompt}
    {--pretend : Dump the SQL queries that would be run without executing them}
```

---

## 8. Authorization and visibility contract

### 8.1 Built-in roles

- `admin`: all permissions.
- `manager`: current manager set, but remove `batches.recall`; add `batches.recall.request`. Its location constraints remain.
- `general_manager`: exact manager set plus `batches.recall`, `batches.health`, and `treasury.manage_all_locations`; it is company-wide but still tenant/company scoped. Identity correction and identification are not granted.
- `cashier`: retain `batches.view`; add `pos.capture-lot-evidence`; no recall, delete, traceability, health, correction, or identification.
- `viewer`: add `batches.view`; no traceability/customer-history or mutations.
- `operator`: add `batches.view`; no traceability or mutation.
- `admin` only by default: `batches.identify`, `batches.correct-identity`.
- Existing inventory-count roles receive `batches.count-lots`.
- Custom roles are reported, never rewritten by the permission migration.

### 8.2 API route matrix

All routes retain `api`, `auth:sanctum`, `SetPermissionsTeam`, `EnforceTokenTenantClaim`, and `module:BatchExpiry`.

- GET list/show/expiring/expired/stock/product-stock: `can:batches.view`.
- GET traceability and partner batch history: `can:batches.traceability`.
- POST create: `can:batches.create`.
- PATCH update: `can:batches.update`.
- DELETE/deactivate: `can:batches.delete`.
- POST branch request: `can:batches.recall.request`.
- POST company recall: `can:batches.recall`.
- POST transfer: existing inventory-transfer authority plus company/location policy.
- POST write-off/reversal/grouped write-off: `can:batches.write-off`.
- POST correct identity: `can:batches.correct-identity`.
- POST identify: `can:batches.identify`.
- Count submission lot rows: existing count authority plus `batches.count-lots`.
- POS evidence ingress: terminal authentication plus `pos.capture-lot-evidence`.
- Health/census/obligation reads and retries: `can:batches.health`.

The current direct route `POST /api/v1/batches/{uuid}/recall` at `BatchExpiry/Presentation/routes.php:29` is removed. Canonical routes are:

```text
POST /api/v1/batches/{uuid}/recall-requests
POST /api/v1/batch-recall-requests/{request}/recall
```

No release/reject route exists.

### 8.3 Web visibility

Exact files:

- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- `apps/web/src/routes/index.tsx`
- `apps/web/src/features/batches/pages/BatchListPage.tsx`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/web/src/features/batches/pages/CreateBatchPage.tsx`
- `apps/web/src/features/batches/pages/EditBatchPage.tsx`
- `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx`
- `apps/web/src/hooks/usePermissions.ts`
- `apps/web/src/hooks/permissionsMap.generated.ts`

The sidebar and route require `BatchExpiry` plus `batches.view`. Each action requires its exact mutation permission. A cashier/viewer sees quantity and expiry but no traceability or mutation. A manager sees branch request but not company recall. General manager sees company recall. Admin additionally sees correction/identification.

---

## 9. Complete writer and lock census

### 9.1 Canonical lock order

1. Lifecycle/document/receipt/count header, if one exists.
2. `ProductCostLock::acquire(tenant, company, sorted product IDs)`.
3. `stock_levels`, ordered by product, null-variant ordering, variant, location.
4. `inventory_batch_stock`, ordered by batch ID then location ID.
5. Active reservations, ordered by reservation ID.
6. `product_batches` identity rows.
7. Product cost row if WAC changes.
8. Lot evidence/identification/obligation rows and effects.
9. Flush `InventoryGlPostingBuffer`.
10. GL tenant-numbering advisory lock.
11. GL company hash-chain advisory lock.
12. Journal entry header and lines.
13. Commit; only then publish events or perform external I/O.

Recovery lease proof:

- Claim transaction locks an eligible obligation only long enough to set lease token/owner/expiry, then commits.
- Application transaction starts fresh, acquires `ProductCostLock` and inventory rows first, then locks the leased obligation/effects.
- Therefore recovery never holds an obligation row while waiting for the advisory lock.
- No GL path calls inventory/obligation writers after taking a GL advisory lock.
- `InventoryGlPostingBuffer` performs its work after inventory and obligation changes are prepared but before the root commit.

Hold classes:

- H1 single product/location: under 5 seconds.
- H2 multi-product document/projection: under 10 seconds, sorted products.
- H3 maintenance: one company/product/variant/location tuple per transaction, under 5 seconds.
- H4 zero-row provisioning: no non-zero quantity write.
- No fleet-wide write transaction.

### 9.2 Writer census

| Writer | Current ownership | Required order/result |
|---|---|---|
| `WeightedAverageCostService::recordPurchase()` | Service transaction | advisory → aggregate → lot → product → buffered GL |
| `WeightedAverageCostService::recordSale()` | Service transaction | advisory → aggregate → lot; no float |
| `WeightedAverageCostService::recordReturn()` | Service transaction | advisory → aggregate → lot → product → buffered GL |
| `WeightedAverageCostService::recordCostAdjustment()` | Service transaction | sorted advisory → stock rows → product → buffered GL |
| `StockAdjustmentService::receive()` | Service transaction | canonical mutation service only |
| `StockAdjustmentService::issue()` | Service transaction | advisory → aggregate → lot; branch/company hold checked under lock |
| `StockAdjustmentService::transfer()` | Service transaction | advisory → source/destination aggregate by location → lots; holds checked for both |
| `StockAdjustmentService::reserve()` | Service transaction | advisory → aggregate → lot → reservation |
| `StockAdjustmentService::releaseReservation()` | Service transaction | advisory → aggregate → lot → reservation |
| `StockAdjustmentService::adjust()` | Service transaction | header → advisory → aggregate → lot |
| `StockAdjustmentService::adjustByDelta()` | Service transaction | header → advisory → aggregate → lot |
| `StockAdjustmentService::applyCountResult()` | Service transaction | count header → sorted advisory → aggregate → lots |
| `BatchStockService::ensureDefaultBatch()` | Nested/service transaction | H4 for zero row; non-zero path delegates canonical service |
| `BatchStockService::receiveBatchStock()` | Service transaction | delegates canonical service |
| `BatchStockService::issueBatchStock()` | Service transaction | delegates canonical service |
| `BatchStockService::transferBatchStock()` | Service transaction | delegates canonical service |
| `BatchStockService::recordBatchMovement()` | Current movement insert | requires exact non-null aggregate movement ID |
| `BatchStock::reserve()` | Direct entity writer | removed as public mutation |
| `BatchStock::releaseReservation()` | Direct entity writer | removed as public mutation |
| `BatchStock::adjustQuantity()` | Direct float writer | removed as public mutation |
| `FEFOInventoryService::consumeBatchesAtomically()` | Nested transaction, `SKIP LOCKED` | caller already owns advisory/aggregate; emits N movements/effects |
| `FEFOInventoryService::restoreBatchesForReturn()` | Nested transaction | advisory → aggregate → ordered lots |
| `FEFOInventoryService::creditLot()` | Nested transaction | advisory → aggregate → lot |
| `StockReservationService::reserve()` | Service transaction | advisory → aggregate → lot → reservation |
| `StockReservationService::release()` | Service transaction | advisory → aggregate → lot → reservation state |
| `StockReservationService::releaseBySource()` | Service transaction | sorted products; same order |
| `StockReservationService::expireReservations()` | Per-reservation transaction | advisory → aggregate → lot → reservation |
| `StockReservationService::recalculateAllReserved()` | Maintenance | one tuple per H3 transaction |
| `StockLevel::recalculateReserved()` | Direct aggregate writer | made non-public/delegated |
| `GoodsReceiptService` | Root transaction | header → sorted advisory → aggregate → lots → product → buffered GL |
| `OpeningBalancePostingService` | Root transaction | header → sorted advisory → aggregate → lots → product → buffered GL |
| `ResetOpeningBalanceService` | Root transaction | same order |
| `StockAdjustmentDocumentService` | Document transaction | header → sorted advisory → aggregate → lots → buffered GL |
| `SupplierGoodsReturnNoteService` | Root transaction | header → sorted advisory → aggregate → lots → product → buffered GL |
| `StockTransferService` | Root transaction | header → sorted advisory → source/destination aggregate → lots → allocation |
| `StockLevelMigrationService` | Maintenance | one tuple; advisory before source/destination rows |
| `StockThresholdService` | Zero-row creation | advisory → create zero row, H4 |
| `FixOrphanedProducts` | Zero-row creation | tenant/company/product advisory, H4 |
| `GroupedWriteOffService` | Root transaction | advisory → aggregate → sorted lots → buffered GL |
| `BatchWriteOffService` | Root transaction | advisory → aggregate → lot → buffered GL |
| `ReverseWriteOffService` | Root transaction | advisory → aggregate → lot → buffered GL |
| `RepairPhantomDefaultBatchesCommand::repairLot()` | Per-row maintenance | advisory → aggregate → source/target lots |
| `RepairPhantomDefaultBatchesCommand::repointReservations()` | Maintenance | advisory → aggregate → source/target lots → ordered reservations |
| `DeliveryNoteService` | Document root transaction | header → sorted advisory → aggregate → lots → reservations → buffered GL |
| `ReturnNoteService` | Document root transaction | header → sorted advisory → aggregate → lots → buffered GL |
| `ReceiptCreationService` | Receipt root transaction | header → sorted advisory → aggregate → lots → allocations |
| `ReceiptReturnService` | Return root transaction | header → sorted advisory → aggregate → lots |
| `ReturnScrapWriteOffService` | Root transaction | header → advisory → aggregate → lot → buffered GL |
| `PosCoreReceiptProjection` | Projection transaction | receipt line → advisory → aggregate movement + obligation → lot effects |
| `BatchStockMutationService` | New sole direct lot stock/reserved writer | enforces exact decimal and movement linkage |
| `BatchIdentityCorrectionService` | Identity-only transaction | batch identity row only; never quantity/GL |
| `LotIdentificationService` | Root transaction | header → advisory → aggregate justification → lots → lineage |
| Lot-count finalizer | Count root transaction | count header → sorted advisory → aggregate → lots |
| `PosReceiptLotProjectionService` live path | Existing projection root transaction | advisory/aggregate first → obligation/effects → buffered GL |
| Obligation recovery | Two transactions | committed lease, then advisory → inventory → obligation/effects → GL |
| Seeders/provisioning | Identity/permission writes | never non-zero quantity |
| `InventoryGlPostingBuffer::flushIfOutermost()` | Root inventory transaction at `InventoryGlPostingBuffer.php:56-92` | executes only after inventory/obligation rows are prepared |
| `InventoryGlPostingService` | Called by buffer | delegates to `GeneralLedgerService`; no reverse inventory call |
| `GeneralLedgerService::createInventoryMovementEntry()` | Same root transaction | GL numbering advisory → company-chain advisory → entry/lines |
| `GeneralLedgerService::createInventoryWriteOffEntry()` | Same root transaction | same GL order |
| `GeneralLedgerService::postEntryNow()` | Root transaction required | never calls W-LOT writers after GL locks |
| `GeneralLedgerService::generateEntryNumber()` | GL numbering owner | tenant-numbering advisory before company-chain advisory |

### 9.3 Float retirement census

The architecture ratchet rejects:

- Public float quantity/reservation parameters or returns.
- `(float)` casts on batch/lot quantity.
- JavaScript `number` for domain quantity.
- Arithmetic using `+`, `-`, or `reduce` over lot quantities.
- Direct `BatchStock` writes outside `BatchStockMutationService`.
- Quantity-changing batch movements without an aggregate movement.
- Aggregate-plus-lot writers absent from §9.2.

Mandatory consumers include:

- `Batch.php:143-151`
- `BatchStock.php:44-84`
- `BatchResource.php:39-59`
- `FEFOInventoryService.php:940-957`
- `BatchController.php:264-323`
- `StockReservationService.php:266,271,507,515,631,639`
- Marketplace, SalesOrder, DeliveryNote, Cart, Workshop reservation adapters/controllers/expiry command
- `apps/web/src/features/batches/types.ts`
- `BatchListPage.tsx`
- `BatchDetailPage.tsx`
- `apps/pos/src/types/cart.ts`

---

## 10. Task dispatch packets

### Task 1 — Preflight, vocabulary, and writer ratchet

**Production files:** create `apps/api/app/Console/Commands/WLotPreflightCommand.php`, `apps/api/app/Modules/Inventory/Domain/Services/InventoryWriterLockManifest.php`, `docs/architecture/w-lot-lock-and-writer-census.md`; update `docs/glossary.md`.

**Contract:** exact `inventory:w-lot-preflight` signature from §7.8. It is read-only.

**Red first:** `apps/api/tests/Architecture/InventoryWriterLockManifestTest.php`, class `InventoryWriterLockManifestTest`, method `test_every_inventory_writer_is_classified`; first failing assertion:

```php
self::assertSame([], $manifest->unclassifiedWriters());
```

Command/lane:

```bash
cd apps/api
php artisan test tests/Architecture/InventoryWriterLockManifestTest.php --filter=test_every_inventory_writer_is_classified
```

Lane: SQLite architecture.

Add `LotVocabularyContractTest::test_w_lot_terms_have_one_writer_and_surface`, `WLotPreflightCommandTest::test_preflight_is_read_only`, and second-company/location/rerun cases.

**Reviewer gate:** inventory architecture plus tenancy reviewers approve the census hash before writer work.

**Rollback:** remove command registration/ratchet only; no data change.

### Task 2 — Company entitlement, immutable revisions, cutovers, and environment forwarding

**Production files:** create the two central migrations, tenant cutover migration, config, DTOs, resolver contract/adapter, rollout services and commands; update `CompanyServiceProvider.php`, `DefaultModuleActivationResolver.php`, `CompanyConfigService.php`, `VerticalConfigController.php`, `TenantObserver.php`, `CreateProductRequest.php`, `UpdateProductRequest.php`, `ProductForm.tsx`, `docker-compose.staging.yml`, and `apps/api/docker/entrypoint.sh`.

**Contracts:** `CompanyEntitlementResolver::resolve()`, `CompanyEntitlementDecisionData`, `WLotRolloutFlagsData`, `wlot:create-rollout-revision`, and `wlot:cutover` exactly as §§6.1–6.3 and 7.2/7.8.

`docker-compose.staging.yml` forwards all eight variables to the shared `x-api-env`; `entrypoint.sh` validates each as literal `true|false`, prints them, and fails before migration if malformed.

**Red first:** `apps/api/tests/Feature/Company/TenantBoundCompanyEntitlementResolverTest.php`, method `test_stale_inflight_cache_cannot_repopulate_revoked_entitlement`; first assertion:

```php
self::assertSame(CompanyEntitlementState::Disabled, $afterRace->state);
```

Command/lane:

```bash
cd apps/api
DB_CONNECTION=pgsql php artisan test -c phpunit-pgsql.xml tests/Feature/Company/TenantBoundCompanyEntitlementResolverTest.php
```

Lane: live PostgreSQL CI.

Cover second tenant/company, module-off retail, missing company, tenancy failure, revision race, queue/scheduler initialization, product create/update, UI visibility, exact JSON fingerprint, cutover retry/conflict.

**Reviewer gate:** tenancy/security reviewer approves adapter binding, revision fencing, compose, and entrypoint.

**Rollback:** flags false; active cutovers transition to rolled_back; retain immutable revisions and additive schema.

### Task 3 — Atomic membership and role enforcement

**Production files:** update `apps/api/database/seeders/RolesAndPermissionsSeeder.php`, membership/policy services used by BatchExpiry, and `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`.

**Contract:**

```php
public function assertBatchMutationAuthority(
    User $actor,
    string $companyId,
    ?string $locationId,
    string $permission,
): void;
```

The check runs inside the mutation transaction and locks the relevant active membership when necessary.

**Red first:** `apps/api/tests/Feature/BatchExpiry/BatchAuthorityRaceTest.php`, method `test_revoked_membership_cannot_win_against_inflight_recall_request`; first assertion:

```php
$response->assertForbidden();
```

Command/lane: PostgreSQL using that file.

**Reviewer gate:** tenancy/authz reviewer validates second-company, second-location, revoked membership, custom role, and permission-cache tests.

**Rollback:** disable W-LOT mutation flags; retain safe route middleware and role correction.

### Task 4 — Recall schema and workflow

**Production files:** create recall migration, enums, models, DTO, `BatchRecallWorkflowService.php`, and repository; update `Batch.php`, issue and transfer services.

**Contract:** exact `request()` and `recall()` signatures from §7.3.

**Red first:** `apps/api/tests/Feature/BatchExpiry/BatchRecallWorkflowTest.php`, method `test_request_immediately_blocks_sale_and_transfer_only_at_requesting_location`; first assertion:

```php
self::assertThrows(BranchLotHeldException::class, $issueAtRequestingLocation);
```

Command/lane: PostgreSQL.

Required cases: request retry before recall, retry after recall, conflicting operation reuse, concurrent duplicates, branch-local sale and transfer block, second location allowed, company recall blocks all company locations, second company unaffected, transition append-only, permission revocation race.

**Reviewer gate:** inventory plus authz.

**Rollback:** `LOT_ROLLOUT_RECALL_WORKFLOW=false`; retain requests/holds for audit and do not synthesize release.

### Task 5 — Recall API, complete batch-route auth, and web visibility

**Production files:** update BatchExpiry routes/controllers/requests/resources; create `RequestBatchRecallRequest.php`, `RecallBatchRequest.php`, `BatchRecallRequestController.php`; update sidebar, routes, batch list/detail pages, permission hooks/map, locale files.

**HTTP contract:**

```text
POST /api/v1/batches/{uuid}/recall-requests
POST /api/v1/batch-recall-requests/{request}/recall
GET  /api/v1/batch-recall-requests
```

The old direct recall route is absent.

**Red first:** `apps/api/tests/Feature/Security/BatchExpiryModuleAccessControlTest.php`, method `test_manager_cannot_company_recall_but_can_request_branch_hold`; first assertion:

```php
$recallResponse->assertForbidden();
```

Command/lane: PostgreSQL.

Web red: `apps/web/src/features/batches/pages/BatchDetailPage.permissions.test.tsx`, method `manager sees request but not recall`; first assertion checks the recall button is absent.

Cover viewer, cashier, manager, GM, admin, no-view role, malformed expiring location returning 422, foreign location/company, expired and expiring independently, direct removed route, trace permission.

**Reviewer gate:** security and frontend.

**Rollback:** hide new panels/requests by flag; route hardening and removed bypass remain.

### Task 6 — Exact-decimal backend/API/web/device contract

**Production files:** update `Batch.php`, `BatchStock.php`, `BatchResource.php`, `BatchController.php`, `BatchStockService.php`, `FEFOInventoryService.php`, `StockReservationService.php`, transitive reservation consumers, batch DTOs, generated output, `apps/web/src/features/batches/types.ts`, `BatchListPage.tsx`, `BatchDetailPage.tsx`, and POS cart types.

**Contract:** every domain/API quantity is a scale-4 numeric string. Formatting parses only at final display and never feeds domain arithmetic.

**Red first:** `apps/api/tests/Feature/BatchExpiry/BatchQuantityStringContractTest.php`, method `test_batch_resource_preserves_quantity_beyond_binary_float_precision`; first assertion:

```php
$response->assertJsonPath('data.stock.0.quantity', '900719925474.1234');
```

Command/lane: PostgreSQL.

Add web test asserting exact string summation through decimal helper and architecture test rejecting float signatures/casts.

**Reviewer gate:** inventory precision plus frontend.

**Rollback:** compatibility parsing may accept legacy number input during one release, but output remains string; never reintroduce float writers.

### Task 7 — Identity/identification schema and admin-only permissions

**Production files:** create migration, enums/models/DTOs, permission migration; update seeder and generated permission map.

**Contract:** schemas in §6.5 and default grants in §8.

**Red first:** `apps/api/tests/Feature/BatchExpiry/LotIdentitySchemaTest.php`, method `test_identity_and_identification_uniques_are_company_scoped`; first assertion verifies the same operation UUID succeeds in a second company but conflicts inside the first.

Command/lane: PostgreSQL.

**Reviewer gate:** schema plus authz.

**Rollback:** flag off; retain lineage tables and permissions.

### Task 8 — Canonical mutation service and used-lot freeze

**Production files:** create mutation DTOs/service; update `BatchStockService`, FEFO, stock adjustment/reservation writers, `Batch.php`, `UpdateBatchRequest.php`, `BatchRepositoryInterface.php`, and `BatchRepository.php`.

**Contract:** §7.7; repository ordinary update becomes:

```php
public function updateMetadata(
    Batch $batch,
    UpdateBatchMetadataData $data,
): Batch;
```

Identity fields are excluded after use.

**Red first:** `apps/api/tests/Feature/BatchExpiry/UsedBatchIdentityFreezeTest.php`, method `test_ordinary_update_refuses_batch_number_or_expiry_after_first_movement`; first assertion:

```php
$response->assertConflict();
```

Command/lane: PostgreSQL.

Cover unused edit, used notes edit, rename/expiry/manufacturing failures, second company, exact retry, direct repository guard, and movement-link requirement.

**Reviewer gate:** inventory architecture.

**Rollback:** retain canonical service; disable identity feature flag only.

### Task 9 — Correction/identification services and UI

**Production files:** create services/controllers/requests/resources; update BatchExpiry routes, `BatchDetailPage.tsx`, batch API/hooks, locales.

**Contracts:** exact `correct()` and `identify()` signatures from §7.7.

**Red first:** `apps/api/tests/Feature/BatchExpiry/LotIdentificationTest.php`, method `test_identification_preserves_aggregate_quantity_value_and_wac`; first assertion:

```php
self::assertSame('0.0000', $aggregateQuantityDelta);
```

Command/lane: PostgreSQL.

Cases: operation retry, conflict, expected-version conflict, uniqueness conflict, insufficient source, reservation handling, no history merge, source/targets sum, no WAC/GL, second company/location, admin-only, ordinary edit remains frozen.

**Reviewer gate:** inventory/accounting/authz.

**Rollback:** flag off; retain completed lineage and movements.

### Task 10 — Lot-count schema and typed submission

**Production files:** create count migration and DTO; update the real `apps/api/app/Modules/Inventory/Presentation/Requests/SubmitCountRequest.php`, `InventoryCountingItem.php`, and `CountingItemController.php`.

**Contract:** exact extended `InventoryCountingService::submitCount()` signature from §7.7. Request accepts:

```text
lots.*.batch_uuid
lots.*.quantity
lots.*.observed_at_device
```

The server stamps `observed_at_estimate` and current UUIDv7 movement marker.

**Red first:** `apps/api/tests/Feature/Inventory/LotCountSubmissionTest.php`, method `test_lot_observation_records_as_of_marker_for_the_same_count_phase`; first assertion:

```php
self::assertSame($lastMovementId, $observation->observed_movement_marker);
```

Command/lane: PostgreSQL.

**Reviewer gate:** inventory count specialist.

**Rollback:** flag off; retain observations.

### Task 11 — Lot-count reconciliation and replay

**Production files:** update `InventoryCountingService.php`, `FinalQuantityAsOfResolver.php`, `MovementReplayService.php`, `CountingReplayPreviewService.php`, and `ApplyStockAdjustmentsOnCountingCompleted.php`.

**Contract:**

```php
public function reconcileLotObservations(
    InventoryCountingItem $item,
    int $winningCountNumber,
): LotCountReconciliationResultData;
```

Exactly one aggregate correction remains. Lot legs merely explain/reallocate that result.

**Red first:** `apps/api/tests/Feature/Inventory/LotCountReconciliationTest.php`, method `test_twenty_aggregate_observed_as_ten_plus_five_creates_five_loss_without_default_remainder`; first assertion:

```php
self::assertSame('-5.0000', $result->aggregateDelta);
```

Command/lane: PostgreSQL.

Mandatory scenarios:

- 10+10 aggregate/lot becomes 10+5.
- 15+5 is reattributed without aggregate change.
- +5 identified surplus.
- Sale during count.
- Transfer during count.
- Late receipt before observation.
- Late receipt after observation.
- Same-second marker ordering.
- Explicit zero versus missing.
- Replay/rerun.
- No WAC or GL for pure reattribution.
- Second company/location.

**Reviewer gate:** inventory/accounting.

**Rollback:** flag off; do not reverse completed count movements automatically.

### Task 12 — Exact lot-count API and existing UI

**Production files:** update `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php`, `apps/api/app/Modules/Inventory/Presentation/routes.php`, `apps/web/src/features/inventory-counting/pages/CountingDetailPage.tsx`, `CountingReviewPage.tsx`, `api/countingApi.ts`, and `types.ts`.

**Contract:** existing count submission route accepts the Task 10 shape; responses use generated `LotCountObservationData[]`.

**Red first:** `apps/web/src/features/inventory-counting/pages/CountingDetailPage.lots.test.tsx`, method `submits explicit zero lot and preserves missing lot`; first assertion:

```ts
expect(mockSubmit).toHaveBeenCalledWith(expect.objectContaining({
  lots: [{ batch_uuid: 'batch-a', quantity: '0.0000' }],
}))
```

Command/lane:

```bash
pnpm --filter @autoerp/web test --run src/features/inventory-counting/pages/CountingDetailPage.lots.test.tsx
```

Lane: Vitest.

**Reviewer gate:** inventory UI/accessibility.

**Rollback:** hide lot editor by flag; flat aggregate counting remains.

### Task 13 — Provenance schema, every producer, and backfill command

**Production files:** create provenance migration, enum, command; update delivery-note converter’s two branches, delivery-note factory, receipt/return, transfer, reservation, POS projection, and allocation models/resources.

**Contract:** exact provenance values/checks from §6.7 and command signature from §7.8.

**Red first:** `apps/api/tests/Feature/Inventory/LotProvenanceProducerTest.php`, method `test_every_fefo_producer_writes_system_estimate_with_reference`; first assertion:

```php
self::assertSame([], $this->allocationsMissingProvenance());
```

Command/lane: PostgreSQL.

Backfill uses stable operation UUID, bounded chunks, per-table/per-company progress, exact retry, no captured inference, and no stock write.

**Reviewer gate:** inventory/data migration.

**Rollback:** flag presentation off; retain classifications.

### Task 14 — Provenance read/export surfaces

**Production files:** update `BatchTraceabilityController.php`, batch resource, document/transfer/receipt resources, CSV exporters, batch detail and related web pages.

**Contract:** every displayed allocation returns:

```text
lot_provenance
lot_evidence_actor_id
lot_evidence_captured_at
lot_evidence_reference
```

`system_fefo_estimate` is visibly labelled “System FEFO estimate”; `unknown` is not labelled captured.

**Red first:** `apps/api/tests/Feature/BatchExpiry/LotProvenanceTraceabilityTest.php`, method `test_trace_never_presents_estimate_as_captured`; first assertion:

```php
$response->assertJsonPath('data.0.lot_provenance', 'system_fefo_estimate');
```

Command/lane: PostgreSQL.

**Reviewer gate:** inventory UX/compliance.

**Rollback:** hide columns, retain stored provenance.

### Task 15 — Durable entitlement-aware census

**Production files:** create census migration, monitor service/command/notification; update existing `LotLedgerDriftCensus.php`, existing `LotLedgerDriftCensusCommand.php`, `routes/console.php`, and BatchExpiry provider/health surface.

**Contract:** existing read-only command remains read-only; new durable monitor uses §7.8. Schedule daily at 03:20 with `withoutOverlapping(180)`, failure notification, and stale recovery.

**Red first:** `apps/api/tests/Feature/BatchExpiry/LotLedgerDriftCensusTest.php`, method `test_unresolved_entitlement_is_actionable_and_never_clean`; first assertion:

```php
self::assertSame(LotLedgerCensusStatus::Failed, $run->status);
```

Command/lane: PostgreSQL; add this exact class to live `backend-test-pgsql`.

Cover clean, drift, disabled exclusion, unresolved, failure notification, stale run, overlap, second company, no repair, exact decimals.

**Reviewer gate:** inventory/operations.

**Rollback:** scheduler flag false; retain run history.

### Task 16 — Server lot eligibility and terminal capability

**Production files:** create eligibility DTO/service/controller/request/resource; update POS/product sync routes and terminal config/capability responses.

**Contract:**

```php
public function snapshot(
    string $tenantId,
    string $companyId,
    string $locationId,
    string $terminalId,
): LotEligibilitySnapshotData;
```

Only enabled, active, non-recalled, non-expired, available lots appear, ordered FEFO with deterministic tie-break.

**Red first:** `apps/api/tests/Feature/POS/PosLotEligibilitySnapshotTest.php`, method `test_snapshot_is_company_location_and_revision_bound`; first assertion:

```php
self::assertSame(['batch-local'], $response->json('data.lots.*.batch_uuid'));
```

Command/lane: PostgreSQL.

**Reviewer gate:** POS/inventory/tenancy.

**Rollback:** guidance flag off; no device deletion required.

### Task 17 — SQLite v68, blocking pre-open refresh, and active renderers

**Production files:** update `apps/pos/src/lib/db/migrations.ts`, product store/types, `apps/pos/src/stores/terminalStore.ts`, `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx`, `apps/pos/src/components/molecules/CartLineItem/CartLineItem.tsx`, ProductGrid/ProductCard/ProductListRow/ProductTable/ProductDetailDrawer/BarcodeChooser/NearExpirySlot owners; create lot snapshot repository.

**Contract:**

```ts
export async function replaceLotEligibilitySnapshot(
  tx: SqlSurface,
  snapshot: LotEligibilitySnapshot,
): Promise<void>;

export async function requireCurrentLotSnapshotBeforeShiftOpen(
  terminal: TerminalContext,
): Promise<LotEligibilitySnapshot>;
```

Both v3 and legacy shift-open branches call the blocking function before creating the shift. Disabled omits lot behavior; unresolved, stale revision, wrong scope, or refresh failure refuses open with a recoverable message.

**Red first:** `apps/pos/src/stores/__tests__/terminalStore.lotPreOpen.test.ts`, method `refuses both shift paths when lot snapshot acknowledgement fails`; first assertion:

```ts
expect(createShift).not.toHaveBeenCalled()
```

Command/lane:

```bash
pnpm --filter @autoerp/pos test --run src/stores/__tests__/terminalStore.lotPreOpen.test.ts
```

Lane: Vitest/SQLite.

**Reviewer gate:** POS offline/accessibility.

**Rollback:** guidance flag off; v68 tables remain.

### Task 18 — Canonical keys and server evidence ingress

**Production files:** create shared vectors, PHP/TS canonical-key implementations, evidence migration/models/DTO/service/controller/request/routes; update fiscal/receipt sync ingress.

**Contract:** §5 and `LotEvidenceIngressService::ingest()`.

**Red first:** `apps/api/tests/Feature/Fiscal/LotEvidenceIngressTest.php`, method `test_index_999999_persists_and_1000000_is_rejected`; first assertions verify 201 for maximum and 422 for maximum+1.

Command/lane: PostgreSQL.

Cover duplicate products, reversed evidence order, exact retry, operation conflict, hash mismatch, product/variant mismatch, terminal/company/location mismatch, stale cutover/revision, N evidence legs, second company.

**Reviewer gate:** fiscal/inventory/security.

**Rollback:** ingress flag off; retain evidence.

### Task 19 — SQLite v69 and receipt-atomic evidence authoring

**Production files:** update `migrations.ts`, `receiptService.ts`, `FiscalEventEngine.ts` only if signature threading is required, cart types/components; create `receiptLineLotEvidenceRepository.ts`.

**Contracts:**

```ts
export async function insertLotEvidenceSubmission(
  tx: SqlSurface,
  submission: ReceiptLotEvidenceSubmission,
): Promise<void>;

export async function insertLotEvidenceLines(
  tx: SqlSurface,
  lines: readonly ReceiptLineLotEvidence[],
): Promise<void>;

export async function enqueueLotEvidence(
  tx: SqlSurface,
  submissionId: string,
): Promise<void>;
```

Inside the existing `withWriteTransaction('fiscal', async tx => ...)`, order is:

1. `FiscalEventEngine.append(tx, ...)`.
2. Insert offline receipt and lines/payments.
3. Insert evidence submission.
4. Insert all evidence lines.
5. Insert evidence outbox row.
6. Apply voucher changes.
7. Commit.

Any failure rolls back all seven categories. Retry with the same operation UUID produces one complete set.

**Red first:** `apps/pos/src/lib/offline/__tests__/receiptService.lotEvidenceAtomicity.test.ts`, method `rolls_back_receipt_when_evidence_outbox_insert_fails`; first assertion:

```ts
expect(await countOfflineReceipts(db)).toBe(0)
```

Command/lane: POS Vitest/SQLite.

Inject failure after fiscal append, receipt insert, evidence header, first/middle/final evidence leg, outbox, and voucher update. Verify exact retry and two-lot line.

**Reviewer gate:** POS offline/fiscal.

**Rollback:** authoring flag off; retain acknowledged evidence and unsent outbox rows.

### Task 20 — Durable evidence delivery and crash recovery

**Production files:** create device outbox worker/API client; update POS sync coordinator, diagnostics UI, app startup/online listeners.

**Contract:**

```ts
export async function claimLotEvidenceOutbox(
  db: SqlSurface,
  owner: string,
  limit: number,
  leaseSeconds: number,
): Promise<readonly ClaimedLotEvidence[]>;

export async function deliverClaimedLotEvidence(
  claim: ClaimedLotEvidence,
): Promise<void>;
```

**Red first:** `apps/pos/src/lib/offline/__tests__/lotEvidenceOutbox.test.ts`, method `recovers_crash_after_server_accept_before_local_ack`; first assertion:

```ts
expect(await countServerSubmissions(operationUuid)).toBe(1)
```

Command/lane: POS integration Vitest.

Cover offline, retry/backoff, expired lease, duplicate worker, conflicting operation, 4xx dead letter, 5xx retry, crash before send/after accept/before ack, second terminal/company.

**Reviewer gate:** POS sync/operations.

**Rollback:** stop worker through authoring flag; do not delete queued evidence.

### Task 21 — Aggregate obligation plus N lot-effect writer

**Production files:** create obligation migration/models/enums/service; update `PosCoreReceiptProjection.php`, FEFO service, fiscal projection job/registry/dispatcher.

**Contract:** exact `ensureObligation()` and `apply()` signatures in §7.6.

**Red first:** `apps/api/tests/Feature/Fiscal/PosLotObligationMultiLotTest.php`, method `test_one_sale_obligation_links_two_fefo_batch_movements_to_one_aggregate_movement`; first assertion:

```php
self::assertCount(2, $obligation->effects);
```

Command/lane:

```bash
cd apps/api
DB_CONNECTION=pgsql php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/PosLotObligationMultiLotTest.php
```

Lane: live PostgreSQL CI.

Cover two-lot consume, two-lot refund restore, scrap, captured evidence priority, FEFO estimate fallback/provenance, signed sum, common aggregate movement, contained failure, aggregate remains one, retry, concurrent delivery, second company/location, entitlement disabled/unresolved.

**Reviewer gate:** fiscal/inventory/accounting.

**Rollback:** projection flag off; aggregate posting remains active; obligations remain visible and unapplied.

### Task 22 — Obligation recovery, leases, health UI, and no-inversion proof

**Production files:** create recovery command/job/service/notification/controllers/resources; update routes/console, Horizon config if required, fiscal projection detail and batch health UI.

**Contract:** exact recovery CLI in §7.8 and claim/application order in §§7.5 and 9.1.

**Red first:** `apps/api/tests/Feature/Fiscal/LotObligationRecoveryDeadlockTest.php`, method `test_recovery_does_not_hold_obligation_lock_while_waiting_for_product_advisory`; first assertion:

```php
self::assertFalse($probe->deadlockDetected());
```

Command/lane: PostgreSQL.

Cover duplicate workers, expired lease, retry before/after effect commit, crash before status update, max attempts, entitlement revision change, two-lot effects, GL flush, and reverse-direction concurrency with live projection.

**Reviewer gate:** database concurrency/fiscal/accounting.

**Rollback:** stop recovery scheduler/command invocation; retain obligations and leases until expiry.

### Task 23 — Generated types, live CI, second-of-everything, and E2E

**Production files:** update `.github/workflows/ci.yml`, generated types, test fixtures, and create `apps/web/e2e/smoke/w-lot.smoke.ts`.

**Contract:** every PostgreSQL-only W-LOT class is explicitly added to the live `backend-test-pgsql` filter at `.github/workflows/ci.yml:1116`; the parked inventory feature lane is not cited as execution evidence.

Required live classes include:

```text
TenantBoundCompanyEntitlementResolverTest
BatchAuthorityRaceTest
BatchRecallWorkflowTest
BatchQuantityStringContractTest
LotIdentitySchemaTest
UsedBatchIdentityFreezeTest
LotIdentificationTest
LotCountSubmissionTest
LotCountReconciliationTest
LotProvenanceProducerTest
LotProvenanceTraceabilityTest
LotLedgerDriftCensusTest
PosLotEligibilitySnapshotTest
LotEvidenceIngressTest
PosLotObligationMultiLotTest
LotObligationRecoveryDeadlockTest
WLotSecondOfEverythingPostgresTest
```

**Red first:** `apps/api/tests/Feature/WLot/WLotSecondOfEverythingPostgresTest.php`, method `test_complete_flow_is_isolated_across_two_companies_locations_lots_terminals_and_retries`; first assertion:

```php
self::assertSame('0.0000', $crossScopeMutation);
```

Command/lane: live PostgreSQL CI.

Also run `php artisan typescript:transform`, fail on generated diff, web/pos typecheck, and Playwright.

**Reviewer gate:** general integrator plus CI owner.

**Rollback:** revert CI-only changes if malformed; no production rollback.

### Task 24 — Rollout commands, activation evidence, and handback

**Production files:** finalize rollout services/commands, preflight output, deploy note, `docs/PRODUCT-BIBLE.md`, and W-LOT handback evidence document.

**Contract:** revision creation outputs `revision_id`; cutover prepare outputs every tenant/company `cutover_id`; activate/rollback consumes only captured output. No operator invents IDs.

**Red first:** `apps/api/tests/Feature/Console/WLotCutoverCommandTest.php`, method `test_prepare_output_ids_are_required_for_activation_and_rerun_is_idempotent`; first assertion:

```php
self::assertSame($preparedIds, $retriedIds);
```

Command/lane: PostgreSQL.

**Reviewer gate:** release/operations plus product owner for evidence only; Q10–Q13 remain OPEN.

**Rollback:** execute `wlot:cutover rollback` for captured IDs and disable flags in reverse order; never reverse aggregate stock automatically.

---

## 11. Executable five-push staging manifest

Every push is independently deployable. Additive migrations are never destructively rolled back during an incident. The staging web application has `autoDeploy=false` at `docs/factory/WORKFLOW.md:206-209`; every push touching `apps/web` therefore performs the explicit Dokploy deployment below. Dokploy’s official API operation is `POST /api/application.redeploy`.

Common explicit web deployment:

```bash
curl --fail-with-body --silent --show-error \
  -X POST "${DOKPLOY_URL:?}/api/application.redeploy" \
  -H "x-api-key: ${DOKPLOY_API_KEY:?}" \
  -H "Content-Type: application/json" \
  --data '{"applicationId":"mY6P_PHb4pw-2LdG1Y7Ml","title":"W-LOT staging verification"}'
```

After deployment succeeds:

```bash
WEB_INDEX="$(curl -fsS https://erp.otospex.dev/)"
WEB_ASSET="$(printf '%s' "$WEB_INDEX" | rg -o 'assets/[^\" ]+\\.js' -m1)"
curl -fsS "https://erp.otospex.dev/${WEB_ASSET}" | shasum -a 256
curl -fsS "https://erp.otospex.dev/${WEB_ASSET}" | rg -F 'wlot-canonical-line-key-v1'
BASE_URL=https://erp.otospex.dev \
  pnpm --filter @autoerp/web exec playwright test \
  e2e/smoke/w-lot.smoke.ts \
  --config=playwright.smoke.config.ts
```

Record the prior asset hash, new asset hash, feature-fingerprint match, Dokploy deployment ID/status, and Playwright transcript.

### Push 1 — Preflight, rollout config, environment plumbing

Contents:

- Task 1.
- Task 2 config/command scaffolding that tolerates missing additive schema.
- `docker-compose.staging.yml`.
- `apps/api/docker/entrypoint.sh`.
- No migration and no business activation.
- All eight flags false.

Pre-push:

```bash
git rev-parse HEAD
pnpm lint
pnpm typecheck
cd apps/api
php artisan test tests/Architecture/InventoryWriterLockManifestTest.php
php artisan test tests/Architecture/LotVocabularyContractTest.php
php artisan test tests/Feature/Console/WLotPreflightCommandTest.php
```

Post-deploy:

```bash
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:w-lot-preflight \
  --all-tenants --format=json --fail-on-unresolved
```

Verify each API/worker/scheduler container prints the same eight literal false values.

**Rollback point P1:** redeploy the preceding image. No DB action.

### Push 2 — Additive central, tenant, and device schema

Contents only:

- All central/tenant migrations in §6.
- SQLite v68/v69 definitions with no callers.
- Schema/model/enum declarations required for migration tests.
- Flags remain false.

Run central migration:

```bash
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan migrate --force
```

Run and capture tenant rolling migration:

```bash
docker compose -f docker-compose.staging.yml exec -T api sh -c \
  'php artisan tenants:migrate-rolling --force > /tmp/wlot-tenants-migrate.log 2>&1'
docker compose -f docker-compose.staging.yml exec -T api \
  cat /tmp/wlot-tenants-migrate.log
```

Verify:

```bash
docker compose -f docker-compose.staging.yml exec -T api sh -c \
  "rg '^→ ' /tmp/wlot-tenants-migrate.log &&
   rg '^Done\\. [0-9]+ tenant\\(s\\) migrated, 0 failed\\.$' /tmp/wlot-tenants-migrate.log &&
   ! rg 'FAILED:|Done with errors' /tmp/wlot-tenants-migrate.log"
```

The number of `→ tenant` lines must equal the declared `Rolling tenant migrations across N tenant(s).` count. In shared-database mode, accept only the command’s explicit documented no-op text.

Then:

```bash
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan migrate:status
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:w-lot-preflight \
  --all-tenants --format=json --fail-on-unresolved
```

**Rollback point P2:** redeploy P1 and leave additive schema installed.

### Push 3 — Live compatibility refactor plus guarded W-LOT implementation

Contents:

- Task 3 membership/permission hardening.
- Task 6 exact-decimal writer/API compatibility refactor, which is a live change and is explicitly tested as such.
- Guarded Tasks 4–22 implementation.
- Web and POS integration.
- All feature flags false.
- Existing POS aggregate posting remains active.
- No provenance backfill, cutover, or scheduler activation.

Pre-push:

```bash
pnpm build
pnpm lint
pnpm typecheck
pnpm test
cd apps/api
composer test
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
php artisan typescript:transform
git diff --exit-code -- ../../packages/shared/types/generated.d.ts
DB_CONNECTION=pgsql php artisan test -c phpunit-pgsql.xml \
  tests/Feature/BatchExpiry \
  tests/Feature/Inventory \
  tests/Feature/Fiscal \
  tests/Feature/POS \
  tests/Feature/Company
```

Post-deploy:

```bash
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:w-lot-preflight \
  --all-tenants --format=json --fail-on-drift --fail-on-unresolved
```

This push touches `apps/web`: perform explicit Dokploy redeploy, asset-hash comparison, feature fingerprint, and Playwright smoke using the common commands.

**Rollback point P3:** redeploy P2. Additive schema stays. If the exact-decimal compatibility refactor itself fails, rollback the image; no stored quantity conversion is involved.

### Push 4 — Permission sync, immutable revision, conservative backfill, and clean evidence

Create the exact false-flags JSON file:

```json
{
  "recallWorkflow": false,
  "identityCorrection": false,
  "lotCounting": false,
  "provenance": false,
  "censusScheduler": false,
  "posGuidance": false,
  "posEvidenceAuthoring": false,
  "posEvidenceProjection": false
}
```

Create and capture the immutable rollout revision:

```bash
REVISION_JSON="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php artisan wlot:create-rollout-revision \
    --artifact-sha="$(git rev-parse HEAD)" \
    --flags-json=/run/wlot/flags-false.json \
    --actor="${DEPLOY_ACTOR:?}" \
    --format=json
)"
REVISION_ID="$(printf '%s' "$REVISION_JSON" | jq -er '.revision_id')"
printf '%s\n' "$REVISION_JSON" > wlot-push4-revision.json
```

The captured `REVISION_ID`, never a hand-entered UUID, is used below.

Sync permissions in every tenant:

```bash
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan tenants:seed \
  --force \
  --class='Database\Seeders\RolesAndPermissionsSeeder'
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan permission:cache-reset
```

Capture and review provenance dry run:

```bash
PROVENANCE_OPERATION="$(uuidgen | tr '[:upper:]' '[:lower:]')"
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:backfill-lot-provenance \
  --all-tenants --operation="$PROVENANCE_OPERATION" --dry-run
```

Execute using the same operation UUID:

```bash
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:backfill-lot-provenance \
  --all-tenants --operation="$PROVENANCE_OPERATION" --execute
```

Run durable census:

```bash
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:lot-drift-monitor \
  --all-tenants --stale-after=180 \
  --fail-on-drift --fail-on-unresolved
```

Prepare cutovers and capture all generated IDs:

```bash
CUTOVER_OPERATION="$(uuidgen | tr '[:upper:]' '[:lower:]')"
CUTOVER_JSON="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php artisan wlot:cutover prepare \
    --all-tenants \
    --revision="$REVISION_ID" \
    --operation="$CUTOVER_OPERATION" \
    --actor="${DEPLOY_ACTOR:?}" \
    --format=json
)"
printf '%s\n' "$CUTOVER_JSON" > wlot-push4-cutovers.json
jq -e --arg revision "$REVISION_ID" \
  '.revision_id == $revision and (.cutovers | length > 0) and all(.cutovers[]; .cutover_id and .tenant_id and .company_id)' \
  wlot-push4-cutovers.json
```

Copy the captured manifest into the API container and verify it:

```bash
docker compose -f docker-compose.staging.yml cp \
  wlot-push4-cutovers.json api:/tmp/wlot-push4-cutovers.json
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:w-lot-preflight \
  --all-tenants \
  --format=json \
  --expected-revision="$REVISION_ID" \
  --expected-cutover-manifest=/tmp/wlot-push4-cutovers.json \
  --fail-on-drift \
  --fail-on-unresolved
```

Acceptance:

- Every tenant migration and permission seed succeeded.
- Manager lacks `batches.recall` and has `batches.recall.request`.
- General manager has the exact delta.
- Admin-only identity permissions remain admin-only.
- Backfill created zero `operator_captured` rows.
- Census is clean or deployment stops.
- Obligation table is empty before activation.
- Revision and every cutover ID are saved in the release evidence.

**Rollback point P4:** redeploy P3. Prepared cutovers remain prepared; backfilled `unknown`/provable estimates remain. No stock reversal.

### Push 5 — Ordered activation

Create a new immutable revision whose flags reflect the intended final enabled state and capture its generated ID. Prepare a fresh cutover manifest for that revision exactly as in Push 4. Never reuse the false-flags revision.

Activate in checkpoints by changing only the corresponding Dokploy environment variables:

1. `LOT_ROLLOUT_PROVENANCE=true`
2. `LOT_ROLLOUT_CENSUS_SCHEDULER=true`
3. `LOT_ROLLOUT_RECALL_WORKFLOW=true`
4. `LOT_ROLLOUT_IDENTITY_CORRECTION=true`
5. `LOT_ROLLOUT_LOT_COUNTING=true`
6. `LOT_ROLLOUT_POS_GUIDANCE=true`
7. `LOT_ROLLOUT_POS_EVIDENCE_AUTHORING=true`
8. `LOT_ROLLOUT_POS_EVIDENCE_PROJECTION=true`

After every checkpoint:

```bash
docker compose -f docker-compose.staging.yml exec -T api php artisan config:clear
docker compose -f docker-compose.staging.yml exec -T api php artisan config:cache
docker compose -f docker-compose.staging.yml exec -T api php artisan horizon:terminate
docker compose -f docker-compose.staging.yml exec -T api php artisan queue:restart
docker compose -f docker-compose.staging.yml restart api worker scheduler websocket
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:w-lot-preflight \
  --all-tenants \
  --format=json \
  --expected-revision="$ACTIVE_REVISION_ID" \
  --expected-cutover-manifest=/tmp/wlot-push5-cutovers.json \
  --fail-on-drift \
  --fail-on-unresolved
```

Only after all eight flags report the captured revision does activation consume the captured cutover IDs:

```bash
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan wlot:cutover activate \
  --all-tenants \
  --manifest=/tmp/wlot-push5-cutovers.json \
  --operation="$ACTIVATE_OPERATION" \
  --actor="${DEPLOY_ACTOR:?}" \
  --format=json \
  --force
```

Run final verification:

```bash
cd apps/api
DB_CONNECTION=pgsql php artisan test -c phpunit-pgsql.xml \
  tests/Feature/WLot/WLotSecondOfEverythingPostgresTest.php
cd ../..
pnpm --filter @autoerp/pos test
pnpm --filter @autoerp/web test
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:lot-drift-monitor \
  --all-tenants --stale-after=180 \
  --fail-on-drift --fail-on-unresolved
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan fiscal:recover-lot-obligations \
  --all-tenants --dry-run
```

Push 5 changes web-visible behavior: perform explicit Dokploy web redeploy, verify asset hash and `wlot-canonical-line-key-v1` fingerprint, then run Playwright smoke.

**Rollback point P5:**

1. Disable flags in reverse order, projection first.
2. Clear/cache config and restart API/workers/scheduler.
3. Run:

```bash
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan wlot:cutover rollback \
  --all-tenants \
  --manifest=/tmp/wlot-push5-cutovers.json \
  --operation="$ROLLBACK_OPERATION" \
  --actor="${DEPLOY_ACTOR:?}" \
  --format=json \
  --force
```

4. Redeploy P4.
5. Explicitly redeploy the web image for P4 and repeat asset/fingerprint/smoke checks.
6. Keep additive tables, evidence, audit, and obligations.
7. Never disable or reverse the always-on POS aggregate projection.
8. Never automatically undo stock movements or delete pending obligations.

---

## 12. Completion map

| Slice | Completion evidence |
|---|---|
| L1 authorization/recall | Tasks 3–5; exact roles, all routes, web visibility, branch sale+transfer hold, company escalation |
| L2 used-lot identity freeze | Tasks 7–9 |
| L3 exact decimal | Task 6 plus architecture/type-generation gate |
| L4 lot counting | Tasks 10–12 and complete scenario matrix |
| L5 provenance | Tasks 13–14 |
| L6 durable health | Task 15 |
| L7 POS display/guidance | Tasks 16–17 |
| L8 captured evidence | Tasks 18–20, receipt-atomic crash matrix |
| L9 identification/correction | Tasks 7–9 |
| Durable POS projection | Tasks 21–22 |
| Second of everything/live CI | Task 23 |
| Activation/handback | Task 24 and five-push manifest |

No slice is complete merely because its schema exists. W-LOT is complete only after Push 5 acceptance.

---

## 13. Dispatch order and reviewer stop points

1. Task 1 — architecture/tenancy stop.
2. Task 2 — tenancy/security/operations stop.
3. Task 3 — authorization stop.
4. Tasks 4–5 — recall domain/API/web stop.
5. Task 6 — precision stop.
6. Task 7 — schema/authz stop.
7. Tasks 8–9 — inventory/accounting stop.
8. Tasks 10–12 — counting stop.
9. Tasks 13–15 — provenance/operations stop.
10. Tasks 16–17 — POS guidance/pre-open stop.
11. Tasks 18–20 — fiscal evidence/offline durability stop.
12. Tasks 21–22 — PostgreSQL projection/deadlock stop.
13. Task 23 — general integration/CI stop.
14. Pushes 1–4 — release stop; no activation before evidence is archived.
15. Task 24 and Push 5 — activation and handback.

Produce-before-consume order is binding:

```text
entitlement/revision
→ additive schema
→ exact mutation and permissions
→ correction/identification
→ count/provenance/census
→ eligibility cache
→ captured evidence
→ durable obligation effects
→ recovery
→ CI/E2E
→ activation
```

The ordinary used-lot edit freeze is activated only after correction/identification replacement paths are green.

---

## 14. Verification checklist

### Baseline and authority

- [ ] Implementation is based on HEAD `11e13eccd746241fb7546d84185310ce490d4464`, or the plan is re-verified after rebase.
- [ ] `CLAUDE.md`, conventions 09–11, spec v4, owner rulings, revision 2, and gate r3 remain represented.
- [ ] Q10–Q13 remain verbatim OPEN.
- [ ] No schema, enum, route, task, rollout flag, or test encodes a Q10–Q13 branch.
- [ ] Oversell override remains deferred.

### Schema and generated contracts

- [ ] Every migration matches §6 exactly.
- [ ] Every company-owned unique contains tenant and company scope.
- [ ] Every FK and ON DELETE action matches §6.
- [ ] Every check/index/partial unique is present on PostgreSQL.
- [ ] Canonical line index is INTEGER everywhere.
- [ ] JSONB rollout flags use `WLotRolloutFlagsData`; no untyped new JSONB exists.
- [ ] `php artisan typescript:transform` produces no uncommitted drift.
- [ ] Batch/status/quantity web code uses generated types and string quantities.

### Entitlement and permissions

- [ ] Company ownership is validated inside initialized tenant context.
- [ ] Central tenant config is read only on the central connection.
- [ ] Stale cache repopulation cannot revive an old revision.
- [ ] HTTP, worker, scheduler, console, product form, POS, and recovery share one decision contract.
- [ ] Manager lacks `batches.recall`.
- [ ] Manager has `batches.recall.request`.
- [ ] General manager equals manager set plus `batches.recall`, `batches.health`, and `treasury.manage_all_locations`.
- [ ] Identity correction/identification default to admin only.
- [ ] All batch routes have explicit permission middleware.
- [ ] Old direct recall route is absent.
- [ ] Viewer/cashier/manager/GM/admin UI and direct-HTTP cases pass.

### Recall, correction, identification, and counting

- [ ] Recall retry before/after transition returns the same request.
- [ ] Conflicting operation UUID reuse returns 409.
- [ ] Branch hold blocks both sale and transfer only at that location.
- [ ] Company recall blocks every company location but not a second company.
- [ ] No release/reject path exists.
- [ ] Used batch identity cannot change through ordinary update/repository calls.
- [ ] Correction preserves quantity, reservation, value, WAC, and history ownership.
- [ ] Identification conserves aggregate quantity/value and does not merge histories.
- [ ] Lot observations retain phase, observation time, estimate, and movement marker.
- [ ] All required count scenarios pass with no DEFAULT remainder heuristic.

### Precision, writers, locks, and GL

- [ ] No public lot quantity/reservation float remains.
- [ ] Batch API/web/POS quantities are exact strings.
- [ ] Only `BatchStockMutationService` directly changes lot quantity/reservation.
- [ ] Every quantity-changing lot movement has the exact aggregate movement ID.
- [ ] Every §9 writer is classified by the ratchet.
- [ ] Recovery commits its lease claim before advisory acquisition.
- [ ] Live projection and recovery use the same advisory-first order.
- [ ] `InventoryGlPostingBuffer` flushes after inventory/obligation preparation.
- [ ] GL numbering advisory precedes company-chain advisory.
- [ ] No GL owner calls inventory/obligation writers after taking GL locks.
- [ ] PostgreSQL deadlock probes pass.

### Provenance, census, and POS guidance

- [ ] Every producer writes one valid provenance value and evidence tuple.
- [ ] Backfill produces no historical `operator_captured`.
- [ ] Estimate never renders as captured.
- [ ] Census filters by enabled company entitlement and batch-tracked product.
- [ ] Disabled and unresolved are distinct.
- [ ] Missed/failed/stale census never appears clean.
- [ ] Schedule is 03:20 with 180-minute overlap expiry.
- [ ] Snapshot replacement is transactional and company/location/terminal/revision bound.
- [ ] Both shift-open branches block until refresh acknowledgement.
- [ ] Active `TransactionCart` and `CartLineItem` render/edit captured lot evidence.
- [ ] Product detail, grid, list, table, barcode chooser, and near-expiry surfaces share the same eligibility pipeline.

### Evidence and obligation durability

- [ ] Receipt, fiscal event, evidence header, all evidence legs, outbox, and voucher changes use one SQLite transaction.
- [ ] Every insert boundary rolls back the complete receipt.
- [ ] Exact retry produces one complete set.
- [ ] Offline/outbox lease/crash/dead-letter cases pass.
- [ ] One aggregate obligation supports N lot effects.
- [ ] Two-lot sale and refund cases pass.
- [ ] Every effect movement shares the aggregate movement ID.
- [ ] Effect signed sum exactly equals aggregate movement quantity.
- [ ] Aggregate stock is never posted twice.
- [ ] Entitlement unresolved/contained failure remains visible and retryable.
- [ ] Recovery never reruns the whole receipt projection.

### CI and five-push rollout

- [ ] Every PostgreSQL-only W-LOT class is in live `backend-test-pgsql`.
- [ ] No parked lane is cited as CI evidence.
- [ ] Every task’s named red test fails first for the stated assertion.
- [ ] Every reviewer stop is recorded.
- [ ] All eight rollout env vars are forwarded to API, worker, and scheduler.
- [ ] Entrypoint rejects malformed flag values.
- [ ] `tenants:migrate-rolling --force` output has one successful entry per tenant and zero failures.
- [ ] Existing-tenant permission sync uses `tenants:seed`, not central `db:seed`.
- [ ] Rollout revision ID is captured from command JSON.
- [ ] Every company cutover ID is captured from prepare-command JSON before activation.
- [ ] Artifact SHA, flags fingerprint, entitlement revision, and cutover manifest agree.
- [ ] Push 3 is treated as a live precision compatibility change, not falsely described as wholly dormant.
- [ ] Every push touching `apps/web` has explicit Dokploy redeploy evidence.
- [ ] Asset hash changed as expected.
- [ ] Served bundle contains the W-LOT feature fingerprint.
- [ ] Playwright staging smoke passes.
- [ ] Every push has its recorded rollback point.
- [ ] Push 5 rollback disables lot projection first and never reverses aggregate stock automatically.
- [ ] Final handback includes SHA, CI URLs/transcripts, migration logs, revision/cutover JSON, census run IDs, asset hashes, feature fingerprint, Playwright results, known OPEN owner questions, and rollback evidence.