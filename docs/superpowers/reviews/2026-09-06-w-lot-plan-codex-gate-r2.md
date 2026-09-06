# Codex plan gate r2 — W-LOT execution plan rev 1 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md at local dev 67c0805d4. Verbatim Codex output.

---
Reviewed the plan against local `dev` HEAD `67c0805d4a179acf56d8c788ab038a799a2f3a8a`, the brief, gate r1, spec v4, owner rulings, conventions 09–11, glossary, migrations, services, routes, CI, and active POS components. No files were changed and no tests were run.

## Gate-r1 closure table

| # | R1 finding | Status | Plan closure and evidence |
|---|---|---|---|
| 1 | Mechanical dispatch gate | **PARTIAL** | The plan adds benchmark, vocabulary, named Convention-09 cases, and reviewer gates at [plan §3–§5](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:80). It still lacks file+class+case+assertion+lane for many task tests; Task 24 has no red-first test; several file lists contain placeholders or nonexistent paths. This does not meet the r1 closure criterion or [Convention 09](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37). |
| 2 | Recall escalation and roles | **PARTIAL** | The plan now distinguishes company recall from local holds and adds membership checks at [plan 168](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:168). However, its O-LOT-1 lifecycle and authority at [plan 103](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:103) contradict and prematurely decide Q10 in the later owner register. |
| 3 | POS core versus lot arm; late evidence | **PARTIAL** | The plan correctly keeps the core stock effect active and places lot evidence behind the entitlement at [plan 193](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:193). The proposed obligation lacks the aggregate movement link required by the spec, and `canonical_line_key` has no deterministic definition, so late recovery cannot reliably attach the lot movement to the original aggregate effect. |
| 4 | Lock census too late | **PARTIAL** | A lock table and canonical order were moved before implementation at [plan 517](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:517), but it is not the complete writer census required by [spec L6](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:342): it lacks exact writer paths, hold times, entity mutators, reservation writers, and several document producers. Task 1 still defers completion until execution. |
| 5 | Unsafe deployment order | **PARTIAL** | Additive-first schemas, feature flags, and rollback concepts exist at [plan 1854](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1854). The first preflight depends on a command not created until Task 3, while Task 2 already contains a migration; migration-bearing tasks remain interleaved with behavior under push-to-staging auto-deploy. |
| 6 | Freeze before replacement | **CLOSED** | Legacy writes remain active until Tasks 7–9 complete, followed by a single activation gate at [plan 1090](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1090) and rollout sequencing at [plan 1896](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1896). |
| 7 | Cross-layer float retirement | **PARTIAL** | Task 6 covers the main batch DTO/API/web surfaces at [plan 878](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:878), but omits live reservation callers that cast lot quantities to float and does not name the liveness test file/case. |
| 8 | Incomplete L9 identity correction | **PARTIAL** | The plan adds schema, service, permissions, audit evidence, idempotency, and UI at [plan 337](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:337). It nevertheless seeds `general_manager` with `batches.identify`, contrary to the spec’s default-admin-only rule. |
| 9 | Flat-row counting model | **CLOSED** | Task 10 defines flat counted rows, lot legs, aggregate reconciliation, legacy handling, and replay behavior at [plan 1173](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1173). |
| 10 | Producer provenance and health | **PARTIAL** | The provenance schema, backfill, health model, and command are present at [plan 1206](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1206), but the producer list misses active delivery-note conversion/factory seams and the scheduled health job has an unsafe collision/lock-expiry specification. |
| 11 | Refresh before POS open | **CLOSED** | Task 17 makes pre-open refresh mandatory, stores the acknowledged entitlement revision, and blocks opening on stale/failed refresh at [plan 1471](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1471). |
| 12 | Durable outbox | **CLOSED** | Tasks 19–21 define durable obligation/outbox states, retries, dead-letter handling, replay, and operational recovery at [plan 1573](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1573). The aggregate-link defect below affects correctness, not the presence of the durable mechanism. |
| 13 | L8/L9 completion | **CLOSED** | L8 is explicitly split between always-on POS core and W-LOT evidence, and L9 receives a concrete implementation slice. The oversell override remains a separately gated follow-up as required. |
| 14 | Citation hygiene | **OPEN** | The plan’s declared dispatch SHA is stale, B1 cites the original uniqueness migration rather than its live successor, and benchmark rows use ellipsis paths or omit required AutoERP `path:line` evidence at [plan 82](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:82). This fails [Convention 10](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37). |
| 15 | Oversized S5/S6 units | **CLOSED** | The prior broad units were divided into discrete schema, service, UI, POS, outbox, deployment, and documentation tasks with intervening reviewer gates. |

## New findings

### BLOCKER — Latest owner register is not carried forward

- **Plan:** [O-LOT-1/O-LOT-2](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:101)
- **Authority:** [Owner rulings Q10–Q13](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:136)
- **Failure:** Q10 is replaced with a different lifecycle and authority model; Q11–Q13 are absent. The plan thereby silently decides or loses unresolved owner rows. In particular, it introduces `rejected` and optional evidence, while Q10’s recommended default requires `requested → recalled` or `requested → released`, GM-only release, mandatory reason plus append-only evidence, and requester/releaser separation.
- **Minimum correction:** Copy Q10–Q13 into the plan verbatim as **OPEN**, preserving their exact recommended defaults. Remove the contradictory O-LOT-1 resolution and prevent implementation of unresolved branches.

### BLOCKER — Late lot recovery cannot prove or link the aggregate stock effect

- **Plan:** [obligation schema](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:458), [`ensureObligation` contract](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1685)
- **Source:** [batch movements require `movement_id`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_05_150002_create_inventory_batch_movements_table.php:21), [current POS lot write supplies it](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/PosCore/Application/Services/PosCoreReceiptProjection.php:2052), [aggregate movement creation](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/PosCore/Application/Services/PosCoreReceiptProjection.php:2355)
- **Failure:** The sidecar records receipt/line/product but not the aggregate `stock_movements.id`. Multiple receipt lines can reference the same product, while the aggregate reference is receipt-level. Recovery can select the wrong movement or cannot satisfy the mandatory FK, breaking the “one aggregate effect, one eventual lot effect” guarantee.
- **Minimum correction:** Persist `aggregate_stock_movement_id` in the obligation transaction, require it in creation/recovery contracts, and assert that replay creates exactly one batch movement referencing that exact aggregate movement.

### BLOCKER — `canonical_line_key` is undefined across server and device

- **Plan:** [receipt-line and obligation keys](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:441)
- **Source:** [current projection uses positional line mapping](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/PosCore/Application/Services/PosCoreReceiptProjection.php:1184), [V2 payload remains positional](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/types/SaleReceiptV2Payload.ts:63)
- **Failure:** Server ingress, retry, and device outbox may derive different keys after ordering, duplicate products, discounts, or payload-version changes. The purported uniqueness guarantee then either duplicates an obligation or suppresses a legitimate line.
- **Minimum correction:** Specify a versioned, deterministic key algorithm and serialization shared by POS and API. Name duplicate-product, reordered-retry, and mixed-version contract tests.

### BLOCKER — The writer/lock census remains incomplete before dispatch

- **Plan:** [lock census](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:517), [Task 1 deferred census](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:631)
- **Authority:** [spec requires the complete census before the plan](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:350)
- **Source:** [reservation mutation](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:507), [second reservation mutation](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:631), [entity mutators](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchStock.php:60)
- **Failure:** An omitted writer can lock aggregate and lot rows in the old order while Task 8 uses the new order, producing a production deadlock despite the plan’s lock-order proof.
- **Minimum correction:** Expand §9 now with every concrete writer path/method, transaction owner, row predicates, lock order, scope, and maximum hold time. Task 1 may ratchet the completed census but cannot create it after dispatch.

### BLOCKER — The deployment preflight is impossible in the prescribed order

- **Plan:** [preflight before first migration push](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1866), [command created in Task 3](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:722), [Task 2 first migration](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:675)
- **Failure:** Staging auto-deploys Task 2’s migration before the required preflight command exists. Later schema migrations are also interleaved with service activation, so “schema-only push” is not a reproducible commit/push sequence.
- **Minimum correction:** Provide an explicit push manifest. Land the preflight command first, then a schema-only push containing every additive migration, then dormant code, then backfill/verification, and finally activation. Include exact flag/env changes, cache/restart commands, and rollback point per push.

### BLOCKER — Task dispatch packets do not satisfy the required test contract

- **Plan examples:** [Task 1](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:631), [Task 9 unspecified corresponding tests](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1054), [Task 24](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1826)
- **Failure:** Many red bullets have no test file/class/case or execution lane; Task 24 has no red-first test. Placeholder file language appears in Tasks 8, 9, 14, 18, 19, and 22. Task 2 names four nonexistent paths, and Task 18 mislocates `FiscalEvent`.
- **Minimum correction:** Add a per-task matrix containing exact test file, test class/case, first failing assertion, command, confirmed CI lane, exact production files, explicit contract, and reviewer gate. Resolve every placeholder to a current path before dispatch.

### MAJOR — L9 permission seed exceeds the approved default

- **Plan:** [`general_manager` permission set](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:162)
- **Authority:** [spec L9 default-admin-only rule](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:332)
- **Failure:** Every general manager can identify, split, or relabel company-wide lots without being explicitly designated.
- **Minimum correction:** Seed `batches.identify` and identity correction to administrators only. Additional staff must receive the permission through ordinary role management.

### MAJOR — Float retirement omits live reservation consumers

- **Plan:** [Task 6 file list](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:886)
- **Source:** [float cast during release](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:507), [float cast during consumption](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:631)
- **Failure:** Fractional reserved quantities may still round across reserve/release/consume even after BatchExpiry DTOs become strings.
- **Minimum correction:** Add the reservation service and all transitive callers to Task 6, with exact fractional-boundary and reserve/release conservation tests.

### MAJOR — Provenance producer inventory misses active document seams

- **Plan:** [Task 13 producers](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1246)
- **Source:** [sales-order conversion](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/SalesOrderToDeliveryNoteConverter.php:363), [second conversion path](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/SalesOrderToDeliveryNoteConverter.php:501), [document factory](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Domain/Services/DeliveryNoteFromDocumentFactory.php:153)
- **Failure:** A delivery note can create stock effects without the planned provenance seam, leaving health checks green while provenance is incomplete.
- **Minimum correction:** Add every active document producer and reservation propagation seam, with one red provenance assertion per path.

### MAJOR — POS active-renderer census is incomplete

- **Plan:** [Task 17 renderer files](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1453)
- **Authority:** [spec active renderer census](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:370)
- **Source:** [active chooser invocation](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/pages/HomePage.tsx:1863), [chooser stock rendering](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/components/molecules/BarcodeChooserModal/BarcodeChooserModal.tsx:146)
- **Failure:** Lot guidance can be correct in the planned grid while the active barcode chooser continues presenting stock without the same guidance or explicit retirement evidence.
- **Minimum correction:** Gate or retire every renderer named by the spec, including `ProductCard.tsx` and `BarcodeChooserModal.tsx`, and add renderer-specific tests.

### MAJOR — Health schedule collides with an existing 02:15 job and has unsafe overlap expiry

- **Plan:** [Task 15 schedule](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1369)
- **Source:** [existing 02:15 reconciliation](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/routes/console.php:95), [repository warning about default overlap expiry](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/routes/console.php:71)
- **Failure:** Both fleet-wide jobs start together. A crashed daily job may retain Laravel’s default 24-hour overlap lock and suppress the following run.
- **Minimum correction:** Select a non-colliding time, give `withoutOverlapping()` an explicit expiry shorter than the cadence, and specify failure notification and stale-lock recovery.

### MAJOR — Vocabulary introduces an unregistered surface and overloads “recall hold”

- **Plan:** [vocabulary table](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:112)
- **Authority:** [Convention 11](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:30), [current glossary Lot entry](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:41)
- **Failure:** “Batch identity correction” becomes a new table/service/dialog without a canonical glossary term, while “recall hold” is used for both company-wide recall and branch-local requested quarantine.
- **Minimum correction:** Update the glossary and vocabulary map with one canonical term and surface for identity correction. Give local quarantine and company recall distinct canonical names.

### MINOR — Baseline is not reproducible at current HEAD

- **Plan:** [declared dispatch base](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:7), [B1 citation](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:82)
- **Source:** [live successor uniqueness migration](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php:27)
- **Failure:** A reviewer following the declared SHA or original migration sees a different effective schema from local `dev`.
- **Minimum correction:** Refresh the dispatch SHA and cite the migration that defines the current effective unique key.

## Rejected false positives

- Using `BIGINT` for internal `batch_id` is correct; the public UUID does not require UUID foreign keys in the inventory tables.
- Central and tenant migrations may share timestamps because they execute from separate migration paths.
- SQLite migration versions `v68` and `v69` remain available at current HEAD.
- A sidecar obligation is not inherently a second stock effect; it is valid if inserted atomically with the aggregate movement and linked to that exact movement.
- Expanding `ProductCostLock` to cover decrement writers is compatible with SKIP-LOCKED allocation, provided the completed census proves one lock order.
- Q11–Q13 do not need implementation inside W-LOT. They do need to remain visibly OPEN in the plan’s owner register.
- The oversell override need not be pulled into this slice; the spec explicitly permits it as a separately approved follow-up.

## Preserve

- Always-on POS aggregate stock posting, independent of W-LOT entitlement.
- Additive schemas before behavior activation.
- One canonical `BatchStockMutationService`.
- One aggregate stock movement plus one linked lot movement.
- Durable sidecar/outbox for late evidence.
- Pre-open entitlement refresh with acknowledged revision.
- Flat counting rows with explicit lot legs.
- Separate company recalls from branch-local quarantine requests.
- Exact decimal strings across API and UI boundaries.
- The three named Convention-09 tests: second company, second location, and rerun/idempotency.
- Single activation gate after replacement paths are complete.
- Deferred oversell override as a separately approved follow-up.

## Owner decisions still required

| ID | Status | Recommended default that must be preserved |
|---|---|---|
| Q10 — Recall lifecycle | **OPEN** | `requested → recalled` or `requested → released`; only GM may release; mandatory reason and append-only evidence; requester cannot release. |
| Q11 — Shared drawer across two terminals | **OPEN** | One cash-bearing shift per drawer; a second terminal joins that drawer session or is refused; the drawer session is the custody unit. |
| Q12 — Typed cash in/out | **OPEN** | `SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`; map each to its destination; block `OTHER` until classified; retain v2 mapping. |
| Q13 — Historical alignment | **OPEN** | One dated per-repository cutover alignment; physical count; opening adjustment document, movement, and journal entry for cash difference; no retroactive rebooking; disable close during the variance window. |
| WLOT-FOLLOWUP — Oversell override | **OPEN/deferred** | Keep outside this slice until separately approved; do not silently enable or forbid it through W-LOT implementation. |

VERDICT: CHANGES-REQUIRED