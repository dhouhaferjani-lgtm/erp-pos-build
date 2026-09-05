# Codex plan gate r1 — W-LOT brief (gpt-5.6-sol, high effort, read-only, 2026-09-06)

Input: docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md at local dev cd8188d82. Verbatim Codex output below.

---
Reviewed against current `dev` HEAD `cd8188d82d11`. No files were modified and no tests were run.

## BLOCKER findings

1. **The brief fails the mechanical execution-plan gate.**  
   Brief: [lines 7–18](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:7>), [39–42](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:39>).

   - Convention 10 requires the prescribed ID/guarantee/Gap/Decision structure, versioned linked references, and a real AutoERP `path:line` in every row ([convention 10:37](</Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37>)). The FEFO row has no line; the POS row has no AutoERP citation; `BatchController.php:176-210` does not establish that recall is unpermissioned—the missing gate is in [routes.php:26](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:26>) and [routes.php:29](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:29>). The table also omits applicable duplicate/re-run, second-company, second-location and audit guarantees.
   - Convention 09 requires the three tests to be named before dispatch ([convention 09:85](</Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:85>)); line 39 names fixture dimensions, not test files/cases.
   - Convention 11 requires an exact vocabulary line verified against the glossary ([convention 11:63](</Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:63>)). “Recall request” is not registered, and the Lot evidence row does not declare `pos_receipt_line_lot_evidence`, its outbox, or its primary writer ([glossary:41](</Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:41>)).

   Failure scenario: a brief can pass while different implementers choose different evidence schemas, writers and test boundaries, defeating the mechanical precheck.

   Minimum correction: replace §0 with the convention-10 table, add exact sources and current-HEAD citations, add a per-slice red-test matrix naming file/class, failing assertion and PG/SQLite lane, and update the glossary before dispatch. The competitor facts are broadly supportable—Odoo documents lot assignment and FEFO, ERPNext documents batch split and lot-grain reconciliation, and Odoo 18’s hashed `LINE_FIELDS` omit lots—but the brief must actually link them. [Odoo lots](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/product_management/product_tracking/lots.html), [Odoo FEFO](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/shipping_receiving/removal_strategies/fefo.html), [ERPNext Batch](https://docs.frappe.io/erpnext/batch), [ERPNext Stock Reconciliation](https://docs.frappe.io/erpnext/stock-reconciliation), [Odoo hash source](https://github.com/odoo/odoo/blob/18.0/addons/l10n_fr_pos_cert/models/pos.py#L727).

2. **S1 does not define an implementable RD2 escalation model, and its role assumption is false.**  
   Brief: [line 24](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:24>). Authority: [owner ruling:9](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:9>).

   `RolesAndPermissionsSeeder` controls permission sets only ([seeder:543](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:543>)); unrestricted location access comes from `user_company_memberships.allowed_location_ids = null` ([LocationContext:194](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationContext.php:194>)). User creation currently defaults membership to `null` unless a grant is explicitly supplied ([UserController:241](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:241>)). Therefore a “manager set with no location restriction” cannot be implemented in the seeder, and ordinary managers can already be unrestricted.

   The slice also leaves undecided:

   - the recall-request/branch-hold table, statuses, unique/idempotency key and primary service;
   - exact request/execute/list/reject-or-release routes and permissions;
   - how the requesting location is selected and custody-authorized;
   - grants for `batches.recall.request`;
   - central visibility;
   - enforcement across both direct batch transfer and `StockTransferService`;
   - the spec’s viewer/operator `batches.view` deltas and location-scoped batch reads.

   Failure scenario: an unrestricted seeded manager requests a hold at any branch; or a local hold affects the controller transfer but `StockTransferService` still issues the lot. Conversely, setting the existing global `is_recalled` flag would wrongly stop every branch.

   Minimum correction: define the append-only request/hold schema and state machine, one service/write path, route-permission matrix, all issue/transfer enforcement points, and the membership invariant: branch managers require a non-null branch grant; `general_manager` assignments require an explicitly unrestricted membership. Use a delta migration/command that preserves custom roles rather than blindly relying on `syncPermissions()`.

3. **The S7 worker design can either disable all receipt projection or lose late lot evidence.**  
   Brief: [lines 32 and 38](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:32>). Spec: [lines 278–290](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:278>).

   `PosCoreReceiptProjection::requiresModule()` must remain `null` because ordinary receipt, aggregate stock and money effects are always active ([projection:221](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:221>)). “Fix … `requiresModule()` null” invites setting it to BatchExpiry, which would suppress the whole projector for a module-off tenant and contradict brief acceptance 48(f). Leaving it unchanged while retaining only the product check at [FEFOInventoryService:930](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:930>) permits unentitled lot writes.

   Late evidence also cannot converge through `apply()` because it immediately returns once the receipt exists ([PosCoreReceiptProjection:231](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:231>)). Merely adding `blocked` to the parent enum does not create the spec’s child obligation or recovery mechanism.

   Failure scenario: evidence arrives after the receipt, the fast path returns forever, no captured lot is consumed, and the aggregate cannot safely be replayed. Alternatively, gating the whole projector prevents the sale from reaching inventory and receipt reporting at all.

   Minimum correction: explicitly keep POS-core always active; introduce the spec’s tri-state company-entitlement adapter under initialized tenancy; persist `fiscal_projection_lot_obligations` with the specified company/event/line/operation unique key; persist the pending obligation alongside the aggregate effect; expose one idempotent lot-only orchestration for both initial projection and W4 recovery; trigger it when evidence arrives in either order. Parent receipt status and child lot status must remain distinct. Worker tests must clear `CompanyContext`.

4. **The required lock census is scheduled after code that depends on it.**  
   Brief: [lines 27–28](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:27>). Spec: [line 350](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:350>).

   The spec says the complete inventory-writer census is required before the plan. The brief postpones it to S5, after S4 has already added a writer. The real lock seams include WAC’s advisory → stock-level rows → product-last order ([WeightedAverageCostService:92](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:92>)) and FEFO’s `SKIP LOCKED` query ([FEFOInventoryService:260](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:260>)).

   Failure scenario: identification/counting locks an earliest-expiry lot; concurrent POS FEFO silently skips it and consumes a later lot, or stock→GL and WAC writers deadlock.

   Minimum correction: add a pre-S4 gate containing every writer, transaction owner, lock scope/order and hold time; define the common product orchestration lock before FEFO; reconcile the nested stock→GL order with W3; require PG concurrency tests for identification/count versus sale and transfer before S4 starts.

5. **There is no safe server/device/migration activation plan for an auto-deployed push.**  
   Brief: [line 52](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:52>). Spec: [lines 436–440](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:436>).

   “Additive and self-guarding” does not identify migrations, preflight censuses, backfills, compatibility readers, capability gates or rollback. The package needs tenant schemas for recall requests/holds, corrections, identifications/details, count observations, provenance, server evidence, lot obligations and census health, plus device migrations for eligibility, evidence and its outbox. Current device migrations already reach v67, so exact next versions must be reserved at dispatch.

   Failure scenario: a device emits evidence before staging has its ingress/storage; a new permission-backed route deploys before tenant grants; or the scheduler begins reporting false clean results before its PG proof and entitlement filter exist.

   Minimum correction: specify this deployment order:

   1. Add tenant schemas/enums/indexes and non-inventing backfills/censuses.
   2. Deploy compatible server readers, ingress and recovery.
   3. Apply reviewed tenant role deltas and reset caches; block activation on failures.
   4. Enable the census scheduler only after its live-PG gate.
   5. Release additive SQLite migrations and old-client-compatible sync.
   6. Enable captured-evidence authoring by server-advertised capability.
   7. Roll back by disabling authoring while retaining readers/ingress.

   Also classify `product_batches` and every new evidence table in the convention-09 manifests, and include backup/restore rehearsal.

## MAJOR findings

6. **S2 may ship the freeze before its required replacement path and is otherwise underspecified.**  
   Brief: [lines 25 and 27](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:25>). The spec requires L9 alongside L2 and says the freeze must not ship without it ([spec:340](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:340>), [spec:457](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:457>)).

   Failure scenario: S2 deploys and operators can neither edit nor identify an already-used DEFAULT lot.

   Minimum correction: combine S2+S4 into one deploy unit or implement S4 before activating the S2 freeze. Name the correction permission, history schema, optimistic-version field, evidence-reference contract and allowed deactivation dispositions. Use the existing public UUID convention—`/batches/{uuid}/corrections`, not `{id}`.

7. **S3 does not retire the actual cross-layer float contract.**  
   Brief: [line 26](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:26>). Spec: [lines 326–328](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:326>).

   The brief stops at backend models/resources. Current web types still declare quantity numbers ([types.ts:38](</Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/types.ts:38>)), and BatchDetail performs `parseFloat`, native sums and `toFixed` ([BatchDetailPage:271](</Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:271>)). Keeping PHPStan green does not detect this.

   Failure scenario: the backend emits exact strings but the UI loses precision or sends scientific notation after arithmetic.

   Minimum correction: include generated DTO changes, every list/detail/write-off/device consumer, string fallbacks in `BatchResource`, `formatQuantity`/unit precision, removal of the hand-written number shapes, ESLint/audit guard liveness, and quantity regex ceilings on every new FormRequest.

8. **S4 omits its required schema, permission rollout and cross-layer contract.**  
   Brief: [line 27](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:27>). Spec: [lines 332–340](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:332>).

   Missing are the typed allocation-detail storage, `(tenant_id, company_id, operation_uuid)` unique key, source version/reservation rules, same company/product/variant/location validation, `MovementReason::LotIdentification`, reference type, web action/link, default-admin permission grant, existing-tenant readiness and after-commit stock/channel event symmetry.

   Failure scenario: an implementer adds a tenant-only operation UUID unique or JSONB details without a DTO, blocking the same UUID in company B and violating conventions 09/strict typing.

   Minimum correction: carry the full schema and route DTO into the brief, use `/batches/{uuid}/identify`, name the sole `LotIdentificationService`, and list exact PG idempotency/conflict/custody tests.

9. **S5 asserts zero GL but does not pin the only safe flat-row path.**  
   Brief: [line 28](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:28>). Actual behavior: [StockMovement:183](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Domain/StockMovement.php:183>) and [InventoryGlPostingService:36](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:36>).

   A row is GL-free only when `quantity_before === quantity_after`, so `directionForRow()` returns `flat`. The existing zero-adjustment return at [StockAdjustmentService:1413](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1413>) must remain for a true no-op; reattribution needs a distinct branch.

   Failure scenario: implementing −5 and +5 as two aggregate adjustments posts shrinkage and gain journals and perturbs WAC despite a conserved aggregate.

   Minimum correction: name the new lot-only reattribution method, require exactly one aggregate row with quantity `0`, equal before/after, two lot legs, zero journals and unchanged WAC, and test replay. Also specify the child-table unique key, legacy active-count disposition and provisional/late-terminal behavior.

10. **S6 lacks provenance/backfill semantics and a concrete health surface.**  
    Brief: [line 29](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:29>).

    The three stores differ structurally, and the spec forbids blindly labelling every document row estimated ([spec:330](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:330>)). “Last-success + alert surface” does not name its table, writer, reader, retention or operator surface. The static scheduler also needs an exact command; the existing command already supports `--all-tenants` ([LotLedgerDriftCensusCommand:52](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/LotLedgerDriftCensusCommand.php:52>)).

    Failure scenario: a migration labels operator-selected document lots as estimates, or a missed scheduled run leaves yesterday’s “clean” display indefinitely.

    Minimum correction: define per-store enum columns and census-based backfill rules, preserve known operator evidence, specify the durable run-health record and UI/alert owner, and name `LotLedgerDriftCensusTest` plus scheduler missed/failure/wrong-tenant tests on live PG.

11. **S7a says “session open,” but the ruling requires refresh before opening and the current seam runs afterward.**  
    Brief: [line 30](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:30>). Owner: [ruling D4:12](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:12>). Current code authors the shift and then starts a non-fatal pull ([terminalStore:857](</Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/stores/terminalStore.ts:857>), [terminalStore:900](</Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/stores/terminalStore.ts:900>)).

    Failure scenario: checkout opens immediately against yesterday’s recall state while the background refresh is still running.

    Minimum correction: define the pre-open refresh sequence and stale/offline result explicitly. Specify the full scoped cache schema, indexes and replacement transaction; use `toSqliteUtc()` for comparisons and `sqliteUtcToDate()` for age display; include snapshot coverage/revision, cart demand, local completed demand, ACK-versus-inclusion watermark, restart dedup and refund disposition. Name the server DTO/sync endpoint and retire/gate alternate renderers so `ProductDetailDrawer` cannot create a second lot pipeline.

12. **S7b does not define a durable outbox protocol.**  
    Brief: [line 31](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:31>).

    The caller-transaction claim is correct ([FiscalEventEngine:10](</Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/FiscalEventEngine.ts:10>)), and the existing receipt transaction provides the seam ([receiptService:584](</Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/offline/receiptService.ts:584>)). But “own outbox item” leaves the table, status lifecycle, retry/recovery, stable evidence ID/content fingerprint, ordering and retention undecided.

    Failure scenario: the receipt commits with evidence locally, but a crash leaves its outbox in `syncing` forever; or evidence arrives before the fiscal event and is rejected because ingress cannot yet validate the receipt hash.

    Minimum correction: specify evidence and outbox schemas, the atomic write position after `append()` and before commit, pending/syncing/synced/failed recovery, terminal/company/location/event/hash/line/product/variant/quantity/cache-revision fields, content-conflict key, either-arrival-order server states, and crash/restart tests. It must remain outside `fiscal_events` and outside the sealed bytes.

13. **The L8/L9 completion accounting is inconsistent.**  
    Brief: [lines 34 and 52](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:34>). Owner D3/D7 explicitly bring display → capture → consumption into this lane ([owner rulings:109](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:109>)); the accepted spec calls captured evidence L8 and requires handback coverage through L9 ([spec:404](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:404>), [spec:408](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:408>)).

    Failure scenario: the handback maps only L1–L7 and can mark the package complete without evidence for captured-lot L8 or identification L9.

    Minimum correction: state that S7b/S7c implement the owner-ruled core of spec L8; defer only override/no-oversell policy with an explicit ticket; require the handback to map L1–L9.

## MINOR findings

14. **Citation hygiene needs correction.**  
    Brief: [lines 9–16 and 24–29](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md:9>).

    Current-HEAD verification:

    - Correct/supporting: `UpdateBatchRequest:20-24`; `ProductOpeningStockPhase:74-77` within the cited range; `InventoryCountingItem:79-105`; organism `Sidebar:222-223`; `BatchStock:44-84`; `Batch:143-151`; FEFO `940-957`, `264-272`, `930-934`; `BatchStockService:471,515-516`; `StockAdjustmentService:1446-1447`; counting test `130-131`; WAC lock text `92-95`; traceability `60-88`; census `81`; fiscal engine `10-11`.
    - Incomplete/ambiguous: `BatchController:176-210` does not prove absent permission; `FEFOInventoryService` and the POS-current-state cell lack lines; `Sidebar.tsx` is ambiguous because two files exist; `WeightedAverageCostService:87-95` only reaches the lock contract at 92; the Odoo hash source needs its actual source link/line 727.
    - The benchmark identifies `b9a5565aa` as “today,” while actual reviewed HEAD is `cd8188d82d11`.

    Minimum correction: use repository-relative full paths and current lines everywhere, and record the exact dispatch revision.

15. **S5 and S6 are oversized review units.**  
    Each combines migrations, write orchestration, legacy handling, UI/DTO work, concurrency, GL behavior and operational enablement.

    Failure scenario: a single “green” reviewer gate hides an unverified sub-leg or makes red-first sequencing impossible to demonstrate.

    Minimum correction: retain the owner’s one-lane/package boundary, but add separately gated substeps for schema, service/API, UI/contracts, concurrency/GL, backfill and operational activation.

## Rejected false positives

- **L9-before-L4 is present.** S4 precedes S5 and matches the owner/spec. The defect is the missing pre-S4 lock census and S2 shipping before the paired replacement path.
- **The flat-movement direction is correct.** One zero-delta aggregate justification plus offsetting lot legs will reach the existing `directionForRow() === flat` GL skip. It needs explicit wiring/tests, not a different accounting design.
- **D7 sidecar evidence is valid.** Odoo’s French hash field list omits lots, the owner confirmed separate evidence, and the caller-owned SQLite transaction can make receipt plus evidence atomic. No SALE_RECEIPT version bump is required.
- **The BatchExpiry route group already has rule-12 base middleware.** The remaining work is per-action permissions, location scope and matching web/device/worker gates.
- **FEFO is correct, not FIFO.** Q7 confirmed it.
- **`product_batches` does not need another uniqueness migration.** Its live keys already include `company_id`; it needs the convention-09 manifest classification.
- **S7’s top-level order is sensible.** Display → capture → projection consumption matches D3. The missing server-first capability/cutover detail prevents dispatch.
- **The brief recognizes the right fixture dimensions and PG need.** Those should be preserved while being converted into named red-first tests.

## What to preserve

- Authority precedence: owner rulings, then accepted spec.
- One W-LOT package with gated slices and no implementer merge/push.
- Private PostgreSQL lane and real two-company/two-location/two-lot/two-terminal fixtures.
- L9 before L4.
- Exact decimal strings, BCMath, unit precision and device `QuantityInput`.
- One flat justification with zero GL and unchanged WAC.
- Three-producer provenance vocabulary.
- Additive device migration and `toSqliteUtc`.
- Display → editable capture → projection sequence.
- Evidence outside sealed SALE_RECEIPT bytes but inside the receipt’s local transaction.
- Module-off aggregate behavior and durable blocked lot work.
- No invented expiry and no FEFO estimate promoted to captured evidence.

## Owner decisions still required

The ruling sheet says the program register is closed, but RD2 introduced one unresolved user-visible lifecycle choice that the brief cannot safely invent: whether a general manager may reject/close a recall request and lift the branch hold, who may perform that release, and what append-only reason/evidence is required. If the intended policy is “a branch hold never lifts in this lane; it remains until company recall,” state that explicitly and mark rejection/release out of scope. All other findings above are plan-definition work, not new owner decisions.

VERDICT: CHANGES-REQUIRED