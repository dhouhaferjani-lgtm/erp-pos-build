<!-- W-LOT-A rev 5, authored by Codex CLI (gpt-5.6-sol, high, read-only) on 2026-09-06 from rev 4 + gate r1; filed verbatim. Status: awaiting gate r2 (A). Rev 4 = e5242a4d7. -->
<!-- W-LOT-A rev 5; read-only plan revision prepared at local dev HEAD 4878c3e6add3f2815522e6f1cb553ebcd81b3e66 on 2026-09-06. Intended path: docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md. No repository or Git state was changed. -->

# W-LOT-A execution plan — server-side lot core, rev 5

All `P5-*` identifiers below are stable normative plan lines. Every repository-relative `path:line` citation was read at local `dev` SHA `4878c3e6add3f2815522e6f1cb553ebcd81b3e66`.

## Change log and gate disposition

### Gate r1 findings

| Finding | Disposition | Closure / rejection |
|---|---|---|
| BLOCKER-1 — Q10 encoded | **CLOSED** | `P5-Q10`, `P5-S2`, `P5-WITHHELD`: the dormant hold table/enum contains only the branch-independent facts `requested` and `recalled`; it has no lifecycle edge, release/reject field, route, permission, feature flag or dispatched writer. Q10-dependent lifecycle activation is withheld until an owner ruling names every terminal disposition and authority. |
| BLOCKER-2 — incomplete task packets | **CLOSED** | `P5-T1`–`P5-T10`: every dispatched task has exact production paths, full new/changed signatures, schema ownership, red file/class/method/assertion/command/lane, Convention-09 cases, reviewer files, gate prompt and rollback. |
| BLOCKER-3 — staging manifest not executable | **CLOSED** | `P5-DEPLOY`: five acyclic pushes, remote execution through SSH plus container `docker compose exec`, persistent evidence manifests, exact deployment-title correlation, per-tenant migration/permission proof, immutable API/web SHA fingerprints and rollback per push. |
| BLOCKER-4 — scale-3 allocation and shadow types | **CLOSED** | `P5-S3`, `P5-T3`, `P5-T6`: the historical POS allocation column created as `DECIMAL(10,3)` at `apps/api/database/migrations/tenant/2026_02_19_000001_create_pos_receipt_line_batch_allocations_table.php:13-20` is reasserted as `DECIMAL(15,4)` despite the earlier widening migration at `apps/api/database/migrations/tenant/2026_05_29_100002_widen_quantity_columns_to_scale_4.php:72-100`; a live schema/value census gates cutover. Batch, transfer and counting local interfaces become generated-DTO re-export shims. |
| BLOCKER-5 — unowned writers | **CLOSED** | `P5-LOCK`: every discovered inventory/lot writer is assigned to T3, T7 or an explicit non-lot classification. `StockTransferService`, POS FEFO, reservations and receipt/refund paths consume the shared eligibility service. Inventory GL is owned only by `InventoryGlPostingBuffer`, whose present boundary is `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:17-92`. |
| BLOCKER-6 — Convention 10 format | **CLOSED** | `P5-BENCH`: the required matrix immediately follows the summary and uses exactly `ID \| Guarantee \| Odoo \| ERPNext \| Dolibarr/NV \| AutoERP today path:line \| Gap \| Decision`, with only MATCH/DEFER/DIVERGE/ALREADY decisions. |
| MAJOR-1 — deactivation lacks persistence | **CLOSED** | `P5-S2`, `P5-T4`: immutable deactivation transition records contain operation identity/fingerprint, actor, reason, before/after state and zero-stock/reservation snapshots. |
| MAJOR-2 — authorization matrix incomplete | **CLOSED** | `P5-T4`: viewer and operator receive `batches.view`; every list/show/create/update/deactivate/recall/trace/stock/expiring/expired/correction/count/health route has positive, forbidden, malformed-ID, fresh-tenant and existing-tenant proof. |
| MAJOR-3 — late-sync guard lost | **CLOSED** | `P5-S4`, `P5-T7`: `LateSyncResidualDetector` is assigned, reconciliation finality is enforced as `provisional→final→reopened`, and late receipts/sales plus count-versus-sale/transfer races are pinned. Existing detector authority is at `apps/api/app/Modules/Inventory/Application/Services/LateSyncResidualDetector.php:25`. |
| MAJOR-4 — provenance retention/resume inconsistent | **CLOSED** | `P5-S5`, `P5-T8`: independent immutable provenance records survive cascade-deleted operational parents; backfill runs have `resumes_run_id`; dry-run is non-persistent and execute uses a distinct UUIDv7. |
| MAJOR-5 — glossary/type ownership incomplete | **CLOSED** | `P5-VOCAB`, `P5-T3`, `P5-T6`, `P5-T8`: glossary rows include Definition and reconcile the existing Lot evidence/Lot identification terms at `docs/glossary.md:41-43`; DTOs are generated in the same task/push as consumers; local files contain re-exports only. |
| MINOR-1 — UUID version mismatch | **CLOSED** | `P5-DEPLOY`: all generated application/rollout IDs use `Str::uuid7()`, never `Str::uuid()`. |
| MINOR-2 — stale reviewed HEAD | **CLOSED** | `P5-BASE`: reviewed HEAD is `4878c3e6add3f2815522e6f1cb553ebcd81b3e66`. |

### Preserved prior-gate rows

| Prior finding | Disposition | Rev 5 plan line / cited rejection |
|---|---|---|
| R1-1 Mechanical dispatch gate | **CLOSED** | `P5-T1`–`P5-T10` |
| R1-2 Recall escalation and roles | **CLOSED** | `P5-T4`, `P5-Q10`, `P5-WITHHELD` |
| R1-3 POS core versus lot obligation | **REJECTED — W-LOT-B** | W-LOT-B owns device evidence/obligations at `docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:89-108`. |
| R1-4 Lock census | **CLOSED** | `P5-LOCK` |
| R1-5 Deployment order | **CLOSED** | `P5-DEPLOY` |
| R1-6 Freeze before replacement | **CLOSED** | `P5-T5`; identification activates before freeze. |
| R1-7 Float retirement | **CLOSED** | `P5-S3`, `P5-T3` |
| R1-8 L9 correction/identification | **CLOSED** | `P5-S3`, `P5-T5` |
| R1-9 Flat-row count model | **CLOSED** | `P5-S4`, `P5-T7` |
| R1-10 Provenance and health | **CLOSED** | `P5-S5`, `P5-S6`, `P5-T8`, `P5-T9` |
| R1-11 Refresh before POS open | **REJECTED — W-LOT-B** | `docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:89-97` |
| R1-12 Durable outbox | **REJECTED — W-LOT-B** | Same citation. |
| R1-13 L8/L9 completion | **CLOSED for A** | L9 is `P5-T5`; L8 remains B-owned. |
| R1-14 Citation hygiene | **CLOSED** | `P5-BASE`, `P5-BENCH` |
| R1-15 Task size | **CLOSED** | Ten dispatched tasks plus one non-dispatchable withheld condition. |
| R2-B1 Latest owner register | **CLOSED** | `P5-OPEN`, `P5-Q10` |
| R2-B2 Aggregate movement link | **REJECTED — W-LOT-B** | W-LOT-B owns POS effects; A uses signed `quantity_after-quantity_before`, matching `apps/api/app/Modules/Inventory/Domain/StockMovement.php:174-212`. |
| R2-B3 Canonical line key | **REJECTED — W-LOT-B** | `docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:111-124` |
| R2-B4 Writer/lock census | **CLOSED** | `P5-LOCK` |
| R2-B5 Deployment preflight | **CLOSED** | `P5-DEPLOY` |
| R2-B6 Dispatch packets | **CLOSED** | `P5-T1`–`P5-T10` |
| R2-M1 L9 permission scope | **CLOSED** | `P5-T4`, `P5-T5`; correction/identification remain admin-only by default. |
| R2-M2 Reservation consumers | **CLOSED** | `P5-T3`, `P5-LOCK` |
| R2-M3 Provenance producer seams | **CLOSED** | `P5-T8` |
| R2-M4 POS renderer census | **REJECTED — W-LOT-B** | B owns renderers at `docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:89-108`. |
| R2-M5 Health schedule | **CLOSED** | `P5-T9`: 03:20, foreground, `withoutOverlapping(180)`. |
| R2-M6 Vocabulary ambiguity | **CLOSED** | `P5-VOCAB` |
| R2-m1 Reproducible baseline | **CLOSED** | `P5-BASE` |
| Gate-r3 multi-lot cardinality | **REJECTED — W-LOT-B** | B interface at `docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:111-124`. |
| Gate-r3 canonical `SMALLINT` | **REJECTED — W-LOT-B** | Same citation. |
| Gate-r3 recall authority/bypass | **CLOSED** | Existing global recall receives explicit authorization in `P5-T4`; Q10-dependent branch lifecycle remains withheld. |
| Gate-r3 recall retry | **REJECTED pending Q10** | No recall-request lifecycle is dispatched; activation condition is `P5-WITHHELD`, owner authority `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:136-143`. |
| Gate-r3 receipt evidence atomicity | **REJECTED — W-LOT-B** | B scope cited above. |
| Gate-r3 company entitlement boundary | **CLOSED** | `P5-T2` |
| Gate-r3 five-push manifest | **CLOSED** | `P5-DEPLOY` |
| Gate-r3 dispatch and CI | **CLOSED** | `P5-T10` |
| Gate-r3 GL ownership/order inversion | **CLOSED** | `P5-LOCK` |
| Gate-r3 float retirement surfaces | **CLOSED** | `P5-S3`, `P5-T3` |
| Gate-r3 count as-of marker | **CLOSED** | `P5-S4`, `P5-T6` |
| Gate-r3 used-lot freeze | **CLOSED** | `P5-T5` |
| Gate-r3 L1 authorization census | **CLOSED** | `P5-T4` |
| Gate-r3 active cart/pre-open owners | **REJECTED — W-LOT-B** | B scope cited above. |
| Gate-r3 generated enums/types | **CLOSED** | `P5-T3`, `P5-T6`, `P5-T8` |
| Gate-r3 stale verified HEAD | **CLOSED** | `P5-BASE` |
| NEW-B1 Q10 encoded | **CLOSED** | `P5-Q10`, `P5-S2`, `P5-WITHHELD` |
| NEW-B2 Signed reconciliation | **REJECTED for A POS obligations** | `P5-S4`, `P5-T7`; B owns POS obligation effects. |
| NEW-B3 Entitlement fence non-transactional | **CLOSED** | `P5-T2`, both race directions. |
| NEW-B4 Branch hold bypass | **CLOSED structurally** | `P5-T3` makes POS FEFO, reservations and transfers consume one eligibility predicate; no hold writer/activation ships before Q10. |
| NEW-B5 Unordered watermark | **CLOSED** | `P5-S4`, `P5-T6` |
| NEW-B6 Evidence delivery order | **REJECTED — W-LOT-B** | B scope cited above. |
| NEW-B7 Staging manifest | **CLOSED** | `P5-DEPLOY` |
| NEW-B8 Convention 10 | **CLOSED** | `P5-BENCH` |
| Major: deactivation disposition | **CLOSED** | `P5-S2`, `P5-T4` |
| Major: GM role exceeded ruling | **CLOSED** | `P5-T4`: exact added authority is `batches.recall` and `treasury.manage_all_locations`; health remains admin-only. |
| Major: Convention 09 per task | **CLOSED** | Every T2–T8 packet names its three exact tests. |
| Major: Convention 11 glossary | **CLOSED** | `P5-VOCAB` |
| Major: entitlement vocabulary | **CLOSED** | `entitled`, `not_entitled`, `entitlement_unresolved` only. |
| Major: delegated schema checks | **CLOSED** | `P5-S1`–`P5-S6` |
| Major: undispatchable tasks | **CLOSED** | `P5-T1`–`P5-T10` |
| Minor: wrong oversell citation | **CLOSED** | `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:376` |
| Minor: stale HEAD label | **CLOSED** | `P5-BASE` |
| R4 false-positive group | **REJECTED as findings** | `tenants:migrate-rolling --force` is valid at `apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:46-53,73-103,158-174`; compose inheritance is real at `docker-compose.staging.yml:17-58,184-186,212-214,233-234,248-249`; 03:20/180 and additive rollback remain accepted. |

<a id="p5-base"></a>
## Summary and reviewed base — `P5-BASE`

This plan delivers the server-side lot core without selecting Q10–Q13 policy: exact lot quantities, company-safe entitlement fencing, one lot mutation/eligibility spine, explicit route authorization and guarded deactivation, DEFAULT identification, used-lot correction, ordered lot counts with late-sync finality, durable provenance, drift monitoring, generated frontend contracts and an executable staging rollout.

Reviewed repository:

```text
Repository: /Users/houssamr/Projects/syneriva/apps/erp
Branch: dev
HEAD: 4878c3e6add3f2815522e6f1cb553ebcd81b3e66
Working tree: no tracked modifications reported
Review mode: read-only; no tests or writes performed
```

Authoritative inputs:

- Repository rules: `CLAUDE.md:15-49,71-105,144-176`.
- Convention 09: `docs/conventions/09-SECOND-OF-EVERYTHING.md:28-75,79-100`.
- Convention 10: `docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37-69,73-80`.
- Convention 11: `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:30-66`.
- Spec v4 W-LOT: `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:274-408`.
- Owner register: `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:104-145`.
- Gate r1: `docs/superpowers/reviews/2026-09-06-w-lot-a-plan-codex-gate-r1.md:1-296`.

<a id="p5-bench"></a>
## Industry baseline (benchmark-first — convention 10)

Flow: server-side lot lifecycle, quantity custody, identification, counting and traceability. Reference systems: Odoo 19.0 official lot/FEFO/reassignment/count documentation; ERPNext v15 Batch/Serial and Batch Bundle/Stock Reconciliation documentation; Dolibarr current official Lot/Serial and Inventories wiki behavior checked by the spec, with `NV` used when that exact guarantee was not verified.

| ID | Guarantee | Odoo | ERPNext | Dolibarr/NV | AutoERP today path:line | Gap | Decision |
|---|---|---|---|---|---|---|---|
| G1 | Lot creation is module-, company- and permission-scoped. | Company-owned lots and inventory groups. | Batch creation is stock-role controlled. | Lot/Serial module permissions; exact company rule NV. | `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12-26` | Module middleware exists; most routes lack explicit permissions. | MATCH — T2/T4 |
| G2 | The same lot number may exist independently in a second company. | Lot carries company. | Batch is company/item scoped. | Exact multi-company key NV. | `apps/api/database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php:27-31` | Existing partial uniques already include company scope. | ALREADY — preserve cited indexes |
| G3 | A used lot cannot be silently renamed or have expiry rewritten. | Reassignment is an explicit stock procedure. | Submitted stock history is corrected through transactions. | Exact used-lot editing rule NV. | `apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:20-24` | Ordinary PATCH still accepts identity fields. | MATCH — T5 |
| G4 | Deactivation retains history and cannot hide positive or reserved stock. | Archive retains history. | Disabled Batch retains ledger evidence. | Deactivation semantics NV. | `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:138-142` | Current method only flips `is_active`. | MATCH — T4 |
| G5 | Retries use stable operation identity and conflicting reuse fails. | Stock documents are identity-keyed. | Stock transactions are voucher-keyed. | Exact retry behavior NV. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:173-210` | Deactivation/recall have no operation fingerprint. | MATCH — T4/T5/T7/T8 |
| G6 | Workers verify tenant/company ownership and current module entitlement. | Company record rules. | Company permissions. | Exact worker rule NV. | `apps/api/app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php:50-75` | Supplied company is ignored. | MATCH — T2 |
| G7 | All lot quantities remain scale-4 decimal strings. | Decimal stock quantities. | Decimal stock quantities. | Decimal quantity supported; exact scale NV. | `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchStock.php:44-84`; `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:143-150` | Public float accessors/mutators remain. | MATCH — T3 |
| G8 | Old scale-3 lot allocations cannot truncate `0.0001`. | Decimal lot quantities. | Decimal batch bundle quantities. | Exact scale NV. | Creation used `DECIMAL(10,3)` at `apps/api/database/migrations/tenant/2026_02_19_000001_create_pos_receipt_line_batch_allocations_table.php:13-20`; widening exists at `apps/api/database/migrations/tenant/2026_05_29_100002_widen_quantity_columns_to_scale_4.php:72-100`. | Existing tenant application of the widening is not proven by source alone. | MATCH — T3 migration plus live census |
| G9 | Sale, reservation and transfer evaluate the same lot eligibility before mutation. | Removal eligibility precedes stock moves. | Batch eligibility precedes stock transactions. | Lot checks exist; exact shared predicate NV. | POS FEFO filters at `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:260-273`; transfer separately checks at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:993-1035`. | Separate predicates can drift; FEFO uses `SKIP LOCKED`. | MATCH — T3 |
| G10 | Unidentified stock is split into real lots without changing aggregate stock, WAC or GL. | Explicit lot reassignment/adjustment. | Stock Reconciliation assigns batches. | Exact split workflow NV. | DEFAULT creation is in `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:133-151`. | No evidence-backed identification document. | MATCH — T5 |
| G11 | Physical counts store explicit lot observations and an ordered as-of marker. | Count lines select lots. | Reconciliation selects batches. | Lot-specific count behavior NV. | Aggregate count model at `apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php:274-347`. | No child observations; UUID ordering is not a causal sequence. | MATCH — T6/T7 |
| G12 | A late pre-count movement prevents false finality and reopens reconciliation. | Counts reconcile against stock moves. | Immutable ledger forces later adjustment. | Exact late-sync rule NV. | Existing signal at `apps/api/app/Modules/Inventory/Application/Services/LateSyncResidualDetector.php:25`. | New count design must preserve and persist the signal. | MATCH — T7 |
| G13 | FEFO estimates remain estimates across all producers and readers. | Allocation/pick evidence remains operational. | Batch bundle records source linkage. | Exact provenance vocabulary NV. | POS producer `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2016`; document and transfer producers cited at spec `:330`. | Three families have no common stored label. | MATCH — T8 |
| G14 | Provenance survives deletion of cascade-owned operational rows. | Audit/stock history retained. | Stock ledger history retained. | Exact parent-retention rule NV. | POS and transfer allocation parents cascade at `apps/api/database/migrations/tenant/2026_02_19_000001_create_pos_receipt_line_batch_allocations_table.php:23-31` and `apps/api/database/migrations/tenant/2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php:16-24`. | Annotation-only provenance can disappear with its parent. | MATCH — T8 independent evidence record |
| G15 | Drift monitoring is durable, company-aware, read-only and visibly stale. | Inventory reporting exposes mismatch. | Reconciliation exposes mismatch. | Inventory module exists; exact monitor NV. | `apps/api/app/Modules/BatchExpiry/Application/Services/LotLedgerDriftCensus.php:67-126` | Ad-hoc command and product-flag-only cohort. | MATCH — T9 |
| G16 | Offline/global oversell policy is not invented here. | Configuration-dependent. | Configuration-dependent. | Configuration-dependent/NV. | `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:376` | Separate policy decision. | DEFER — W-LOT-OVERSOLD |
| G17 | Recall release/rejection policy is not selected before Q10. | Quality release is authority/configuration dependent. | GMP release/reject is quality-authority controlled. | Exact approval chain NV. | `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:136-143` | Owner ruling open. | DEFER — OWNER-Q10 |

Sources: [Odoo lots](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/product_management/product_tracking/lots.html), [Odoo FEFO](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/shipping_receiving/removal_strategies/fefo.html), [Odoo reassignment](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/product_management/product_tracking/reassign.html), [Odoo inventory adjustments](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/warehouses_storage/inventory_management/count_products.html), [ERPNext Batch](https://docs.frappe.io/erpnext/batch), [ERPNext Stock Reconciliation](https://docs.frappe.io/erpnext/stock-reconciliation), [Dolibarr Lot/Serial](https://wiki.dolibarr.org/index.php/Module_Lot_/_Serial), [Dolibarr Inventories](https://wiki.dolibarr.org/index.php/Inventories).

Second-of-everything (convention 09): T2–T8 each add the exact second-company, second-location and rerun tests named in their packet. T1, T9 and T10 are infrastructure/read-only/release work and state that exclusion explicitly.

<a id="p5-vocab"></a>
## Vocabulary and one-surface contract — `P5-VOCAB`

Task 1 updates `docs/glossary.md` using its required five-column schema:

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| **Lot eligibility** | The single server predicate deciding whether one lot may participate in a named sale, reservation, transfer, return, identification or count operation at one company/location. | No table; `BatchEligibilityService` / BatchExpiry | Existing batch/transfer/count surfaces; no independent editor | batch eligibility |
| **Lot identity correction** | An append-only, version-checked change to number/manufacturing/expiry metadata of a used lot, preserving before/after evidence. | `batch_identity_corrections` / BatchExpiry | Existing batch detail → Correct identity | controlled correction |
| **Lot count observation** | One explicit observed quantity, including explicit zero, for a lot and count phase at an ordered movement marker. | `lot_count_observations` / Inventory | Existing inventory counting detail | batch count |
| **Lot count reconciliation** | The immutable applied result linking accepted observations to aggregate and lot effects, with provisional/final/reopened late-sync status. | `lot_count_reconciliations`, `lot_count_reconciliation_effects` / Inventory | Existing counting review | lot-grain count application |
| **Company entitlement decision** | The tri-state worker decision `entitled`, `not_entitled` or `entitlement_unresolved`, tied to a monotonic tenant revision. | Central `tenants.module_entitlement_revision`; tenant company ownership / BatchExpiry | Existing admin health output; no company licence editor | worker entitlement |
| **Lot provenance record** | Independent retained evidence that one existing POS, document or transfer producer recorded a lot as operator-captured, system-FEFO-estimated or unknown. | `lot_provenance_records` / BatchExpiry | Existing trace/detail/export surfaces | lot evidence record |
| **Lot census run** | One durable, read-only fleet execution and its per-company entitlement/drift results. | `lot_ledger_census_runs`, `lot_ledger_census_company_results` / BatchExpiry | Existing Batches health panel | drift run |
| **Rollout revision** | Immutable artifact SHA plus exact feature-flag fingerprint used for staged activation. | `w_lot_a_rollout_revisions` / BatchExpiry | Release evidence only | rollout manifest |
| **Company cutover** | Per-company preparation/activation/rollback record for one rollout revision. | `w_lot_a_company_cutovers` / BatchExpiry | Release evidence only | activation record |

The existing Lot evidence and Lot identification rows at `docs/glossary.md:41-43` remain canonical and are amended, not duplicated. There remains one batch list/detail, one inventory-count surface and one transfer surface.

`apps/web/src/features/batches/types.ts`, `apps/web/src/features/stock-transfers/types/index.ts`, and `apps/web/src/features/inventory-counting/types.ts` become generated-type re-export shims only. They may contain imports/exports and UI-only view-state types, but no domain interface or enum duplication.

<a id="p5-open"></a>
## Owner decisions still required — VERBATIM OPEN register — `P5-OPEN`

All four remain **OPEN**.

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

<a id="p5-q10"></a>
## Q10–Q13 neutrality rule — `P5-Q10`

- No Q10–Q13 branch appears in a dispatched state machine, runtime feature flag, route, permission, push activation or behavioral writer.
- The dormant hold schema contains only `requested` and `recalled`, facts common to every Q10 outcome. It defines no permitted edge or terminality.
- No `released`, `rejected`, `release_reason`, rejection reason, disposition signature, release/reject actor, route, permission, UI or test exists.
- The existing global recall route remains protected by `batches.recall`; it does not create or transition a branch request.
- Q11–Q13 have no W-LOT-A schema, enum, state, flag, command, task or push.
- The shared eligibility service can read an existing `requested` row, making all consumers structurally consistent, but no production path creates such a row before `P5-WITHHELD` is activated.

<a id="p5-scope"></a>
## Scope boundary

Included:

- Company-aware entitlement fencing.
- Exact quantity storage/emission and retirement of float writers.
- One mutation, eligibility and lock spine for all server lot writers.
- Explicit authorization for existing batch routes.
- Policy-neutral dormant hold schema; no lifecycle activation.
- Guarded, audited deactivation.
- DEFAULT identification before used-lot freeze/correction.
- Numeric movement sequence, lot observations, reconciliation and late-sync reopening.
- Provenance across POS estimate, document and transfer families.
- Durable 03:20 drift census.
- Generated backend-owned frontend types.
- Five-push staging rollout.

Excluded:

- Q10 release/reject or branch-request lifecycle activation.
- Q11–Q13.
- `apps/pos` device display/capture/outbox/evidence.
- POS captured-lot obligations/effect recovery.
- `NearExpirySlot`, `ProductDetailDrawer`, receipt schema/version/hash/print changes.
- Global oversell policy.
- Automatic repair of drift, DEFAULT reconstruction or reversal of accepted counts.

<a id="p5-schema"></a>
## Complete migration schema contract

General rules:

- Application IDs are UUIDv7 with no database default.
- Company-owned unique keys include `tenant_id, company_id`.
- New audit/history FKs use `ON DELETE RESTRICT`.
- Cross-database tenant/revision identities have no FK.
- Timestamps are `TIMESTAMPTZ`.
- Quantities are `DECIMAL(20,4)` unless altering an existing `DECIMAL(15,4)` contract.
- New JSONB has a named Spatie Data DTO.
- PostgreSQL installs partial indexes/deferred triggers; SQLite receives equivalent application checks and logical nullability.

<a id="p5-s1"></a>
### S1 — rollout and cutover — `P5-S1`, T1

Central migration: `apps/api/database/migrations/2026_09_06_100000_create_w_lot_a_rollout_revisions.php`.

Alter `tenants`:

| Column | Type | Null | Default | Constraints/index |
|---|---|---:|---|---|
| `module_entitlement_revision` | BIGINT | no | `0` | CHECK `>=0`; index |

Create `w_lot_a_rollout_revisions`:

| Column | Type | Null | Default / FK |
|---|---|---:|---|
| `id` | UUID PK | no | none |
| `artifact_sha` | CHAR(40) | no | none |
| `flags` | JSONB | no | none |
| `flags_fingerprint` | CHAR(64) | no | none |
| `created_by` | VARCHAR(255) | no | none |
| `created_at` | TIMESTAMPTZ | no | CURRENT_TIMESTAMP |

Constraints:

- UNIQUE `(artifact_sha, flags_fingerprint)`.
- Lowercase 40-hex SHA and 64-hex fingerprint.
- JSON object with exactly six boolean keys: `wlot_a_entitlement_fence`, `wlot_a_exact_quantities`, `wlot_a_identity_controls`, `wlot_a_lot_counts`, `wlot_a_provenance`, `wlot_a_drift_scheduler`.
- Immutable update/delete trigger.
- JSONB DTO: `WLotARolloutFlagsData`.

Tenant migration: `apps/api/database/migrations/tenant/2026_09_06_100100_create_w_lot_a_company_cutovers.php`.

| Column | Type | Null | Default / FK |
|---|---|---:|---|
| `id` | UUID PK | no | none |
| `tenant_id` | UUID | no | cross-DB identity |
| `company_id` | UUID | no | `companies.id` RESTRICT |
| `revision_id` | UUID | no | central identity |
| `artifact_sha` | CHAR(40) | no | none |
| `flags_fingerprint` | CHAR(64) | no | none |
| `entitlement_revision` | BIGINT | no | none |
| `state` | VARCHAR(16) | no | `prepared` |
| `prepared_operation_uuid` | UUID | no | none |
| `prepared_by` | VARCHAR(255) | no | none |
| `prepared_at` | TIMESTAMPTZ | no | none |
| `activated_operation_uuid` | UUID | yes | none |
| `activated_by` | VARCHAR(255) | yes | none |
| `activated_at` | TIMESTAMPTZ | yes | none |
| `rolled_back_operation_uuid` | UUID | yes | none |
| `rolled_back_by` | VARCHAR(255) | yes | none |
| `rolled_back_at` | TIMESTAMPTZ | yes | none |
| `created_at` | TIMESTAMPTZ | no | CURRENT_TIMESTAMP |
| `updated_at` | TIMESTAMPTZ | no | CURRENT_TIMESTAMP |

Constraints:

- UNIQUE `(tenant_id,company_id,revision_id)` and `(tenant_id,company_id,prepared_operation_uuid)`.
- Partial company-scoped uniques for non-null activation/rollback UUIDs.
- State `prepared|active|rolled_back`.
- State implication checks and transition trigger `prepared→active→rolled_back`.
- Exact retry returns prior row; conflicting fingerprint/state reuse fails.

<a id="p5-s2"></a>
### S2 — policy-neutral hold storage and deactivation evidence — `P5-S2`, T4

Migration: `apps/api/database/migrations/tenant/2026_09_06_110000_create_policy_neutral_batch_holds_and_deactivations.php`.

`BatchHoldStatus` contains only:

```php
enum BatchHoldStatus: string
{
    case Requested = 'requested';
    case Recalled = 'recalled';
}
```

Create dormant `batch_recall_requests`:

| Column | Type | Null | Default / FK |
|---|---|---:|---|
| `id` | UUID PK | no | none |
| `tenant_id` | UUID | no | cross-DB identity |
| `company_id` | UUID | no | `companies.id` RESTRICT |
| `batch_id` | BIGINT | no | `product_batches.id` RESTRICT |
| `location_id` | UUID | no | `locations.id` RESTRICT |
| `status` | VARCHAR(16) | no | `requested` |
| `operation_uuid` | UUID | no | none |
| `operation_fingerprint` | CHAR(64) | no | none |
| `reason` | TEXT | no | none |
| `requested_by` | UUID | no | `users.id` RESTRICT |
| `requested_at` | TIMESTAMPTZ | no | none |
| `created_at` | TIMESTAMPTZ | no | CURRENT_TIMESTAMP |
| `updated_at` | TIMESTAMPTZ | no | CURRENT_TIMESTAMP |

Constraints:

- UNIQUE `(tenant_id,company_id,operation_uuid)`.
- Partial UNIQUE `(tenant_id,company_id,batch_id,location_id)` where status=`requested`.
- Indexes `(tenant_id,company_id,location_id,status,requested_at)` and `(tenant_id,company_id,batch_id,status)`.
- Status CHECK only `requested|recalled`.
- Nonblank reason and lowercase 64-hex fingerprint.
- Deferred ownership trigger validates company/batch/location.
- No transition trigger, transition table, recalled actor/time, release/reject field or runtime writer.

Create `batch_deactivation_transitions`:

| Column | Type | Null | Default / FK |
|---|---|---:|---|
| `id` | UUID PK | no | none |
| `tenant_id` | UUID | no | cross-DB identity |
| `company_id` | UUID | no | `companies.id` RESTRICT |
| `batch_id` | BIGINT | no | `product_batches.id` RESTRICT |
| `operation_uuid` | UUID | no | none |
| `operation_fingerprint` | CHAR(64) | no | none |
| `reason` | TEXT | no | none |
| `before_is_active` | BOOLEAN | no | none |
| `after_is_active` | BOOLEAN | no | false |
| `quantity_snapshot` | DECIMAL(20,4) | no | `0.0000` |
| `reserved_quantity_snapshot` | DECIMAL(20,4) | no | `0.0000` |
| `acted_by` | UUID | no | `users.id` RESTRICT |
| `acted_at` | TIMESTAMPTZ | no | none |
| `created_at` | TIMESTAMPTZ | no | CURRENT_TIMESTAMP |

Constraints:

- UNIQUE `(tenant_id,company_id,operation_uuid)`.
- UNIQUE `(tenant_id,company_id,batch_id)` where `after_is_active=false`.
- CHECK `before_is_active=true AND after_is_active=false`.
- CHECK both snapshots equal zero.
- Nonblank reason/fingerprint.
- Immutable trigger and deferred batch/company ownership trigger.

<a id="p5-s3"></a>
### S3 — scale-four, identity correction and identification — `P5-S3`, T3/T5

Migration `apps/api/database/migrations/tenant/2026_09_06_115000_enforce_lot_quantity_scale_four.php`:

- PostgreSQL preflight rejects nonnumeric values or absolute values requiring more than 11 integer digits.
- `pos_receipt_line_batch_allocations.quantity` becomes `DECIMAL(15,4) NOT NULL USING quantity::numeric(15,4)`.
- It verifies `inventory_batch_stock.quantity`, `reserved_quantity`, generated `available_quantity`, `inventory_batch_movements.quantity`, `stock_reservations.quantity` and `stock_transfer_line_batch_allocations.quantity` are scale 4.
- Postflight query asserts numeric scale 4 for every target.
- SQLite is schema-no-op but tests model casts and canonical-string ingress.
- `down()` is intentionally no-op; narrowing would destroy valid data.

Migration `apps/api/database/migrations/tenant/2026_09_06_120000_add_batch_identity_corrections.php`:

Alter `product_batches` with `identity_version BIGINT NOT NULL DEFAULT 0 CHECK >=0`; index `(tenant_id,company_id,id,identity_version)`.

Create `batch_identity_corrections`:

| Column | Type | Null | Default / FK |
|---|---|---:|---|
| `id` | UUID PK | no | none |
| `tenant_id` | UUID | no | cross-DB identity |
| `company_id` | UUID | no | `companies.id` RESTRICT |
| `batch_id` | BIGINT | no | `product_batches.id` RESTRICT |
| `operation_uuid` | UUID | no | none |
| `operation_fingerprint` | CHAR(64) | no | none |
| `expected_identity_version` | BIGINT | no | none |
| `resulting_identity_version` | BIGINT | no | none |
| `old_batch_number` | VARCHAR(255) | no | none |
| `new_batch_number` | VARCHAR(255) | no | none |
| `old_manufacturing_date` | DATE | yes | none |
| `new_manufacturing_date` | DATE | yes | none |
| `old_expiry_date` | DATE | yes | none |
| `new_expiry_date` | DATE | yes | none |
| `reason` | TEXT | no | none |
| `evidence_reference` | TEXT | no | none |
| `corrected_by` | UUID | no | `users.id` RESTRICT |
| `created_at` | TIMESTAMPTZ | no | CURRENT_TIMESTAMP |

Constraints: company-scoped operation/version uniques; resulting version = expected+1; at least one pair differs via `IS DISTINCT FROM`; dates ordered; text nonblank; immutable trigger.

Migration `apps/api/database/migrations/tenant/2026_09_06_121000_create_lot_identifications.php`.

`lot_identifications`:

| Column | Type | Null | Default / FK |
|---|---|---:|---|
| `id` | UUID PK | no | none |
| `tenant_id` | UUID | no | cross-DB identity |
| `company_id` | UUID | no | `companies.id` RESTRICT |
| `source_batch_id` | BIGINT | no | `product_batches.id` RESTRICT |
| `product_id` | UUID | no | `products.id` RESTRICT |
| `variant_id` | UUID | yes | `product_variants.id` RESTRICT |
| `location_id` | UUID | no | `locations.id` RESTRICT |
| `operation_uuid` | UUID | no | none |
| `operation_fingerprint` | CHAR(64) | no | none |
| `quantity` | DECIMAL(20,4) | no | none |
| `source_quantity_before` | DECIMAL(20,4) | no | none |
| `source_quantity_after` | DECIMAL(20,4) | no | none |
| `aggregate_quantity_before` | DECIMAL(20,4) | no | none |
| `aggregate_quantity_after` | DECIMAL(20,4) | no | none |
| `wac_before` | DECIMAL(20,6) | no | none |
| `wac_after` | DECIMAL(20,6) | no | none |
| `stock_movement_id` | UUID | no | `stock_movements.id` RESTRICT |
| `reason` | TEXT | no | none |
| `evidence_reference` | TEXT | no | none |
| `created_by` | UUID | no | `users.id` RESTRICT |
| `created_at` | TIMESTAMPTZ | no | CURRENT_TIMESTAMP |

Constraints: company-scoped operation unique; quantity positive; nonnegative snapshots; source delta=`-quantity`; aggregate/WAC unchanged; source must be DEFAULT/unknown, unreserved and same grain; movement flat; effects sum zero.

`lot_identification_effects`:

| Column | Type | Null | Default / FK |
|---|---|---:|---|
| `id` | UUID PK | no | none |
| `tenant_id` | UUID | no | cross-DB identity |
| `company_id` | UUID | no | `companies.id` RESTRICT |
| `identification_id` | UUID | no | `lot_identifications.id` RESTRICT |
| `ordinal` | INTEGER | no | none |
| `effect_kind` | VARCHAR(16) | no | none |
| `batch_id` | BIGINT | no | `product_batches.id` RESTRICT |
| `signed_quantity` | DECIMAL(20,4) | no | none |
| `quantity_before` | DECIMAL(20,4) | no | none |
| `quantity_after` | DECIMAL(20,4) | no | none |
| `created_at` | TIMESTAMPTZ | no | CURRENT_TIMESTAMP |

Constraints: company/identification ordinal and batch uniques; one source debit; `source_debit|target_credit`; ordinal/sign checks; `after=before+signed`; deferred zero sum; immutable.

<a id="p5-s4"></a>
### S4 — movement sequence, observations and reconciliation — `P5-S4`, T6/T7

Migration `apps/api/database/migrations/tenant/2026_09_06_130000_add_stock_movement_sequence.php` creates `stock_movement_sequence_counters(tenant_id UUID, company_id UUID FK companies RESTRICT, last_value BIGINT DEFAULT 0, created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ)`, PK `(tenant_id,company_id)`, CHECK last value ≥0.

Alter `stock_movements`:

- Add nullable `movement_sequence BIGINT`.
- Backfill per company by `row_number()` ordered by `COALESCE(occurred_at,created_at),created_at,id`.
- Seed counters to maximum, set NOT NULL, CHECK >0.
- UNIQUE `(tenant_id,company_id,movement_sequence)`.
- Index `(tenant_id,company_id,product_id,variant_id,location_id,occurred_at,movement_sequence)`.
- Immutable after insert.

Alter `inventory_counting_items` with nullable BIGINT `count_1_movement_sequence`, `count_2_movement_sequence`, `count_3_movement_sequence`, `final_qty_movement_sequence`, each CHECK ≥0. Legacy UUID markers remain read-only.

Migration `apps/api/database/migrations/tenant/2026_09_06_131000_create_lot_count_observations.php`.

`lot_count_observations` columns: `id UUID PK`; non-null `tenant_id UUID`; `company_id UUID FK companies RESTRICT`; `counting_id UUID FK inventory_countings RESTRICT`; `counting_item_id UUID FK inventory_counting_items RESTRICT`; `count_number SMALLINT`; `product_id UUID FK products RESTRICT`; nullable `variant_id UUID FK product_variants RESTRICT`; `location_id UUID FK locations RESTRICT`; `batch_id BIGINT FK product_batches RESTRICT`; `observed_quantity DECIMAL(20,4)`; nullable `observed_at_device TIMESTAMPTZ`; non-null `observed_at_estimate`, `received_at TIMESTAMPTZ`; `movement_sequence BIGINT`; `submitted_by UUID FK users RESTRICT`; `created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP`.

Constraints: company-scoped item/phase/batch unique; phase 1–3; quantity/sequence nonnegative; estimate ≤ received+5 minutes; ownership trigger; explicit zero stored; immutable.

`lot_count_reconciliations` columns: `id UUID PK`; non-null tenant/company with company FK RESTRICT; counting/counting-item FKs RESTRICT; `count_number SMALLINT`; `operation_uuid UUID`; `operation_fingerprint CHAR(64)`; `mode VARCHAR(24)`; `finality_state VARCHAR(16) DEFAULT provisional`; quantities `observed_parent_quantity`, `aggregate_quantity_before`, `aggregate_quantity_after`, `aggregate_delta`, `lot_total_before`, `lot_total_after` as `DECIMAL(20,4)`; nullable `stock_movement_id UUID FK stock_movements RESTRICT`; `applied_by UUID FK users RESTRICT`; `applied_at TIMESTAMPTZ`; nullable `finalized_at`, `reopened_at TIMESTAMPTZ`; nullable `reopened_by UUID FK users RESTRICT`; nullable `late_movement_id UUID FK stock_movements RESTRICT`; nullable `reopen_reason TEXT`; `created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP`.

Constraints:

- UNIQUE company/item/phase and company/operation.
- Mode `no_op|aggregate_adjustment|reattribution`.
- Finality `provisional|final|reopened`.
- Quantity equations from rev 4.
- `provisional`: final/reopen fields null.
- `final`: `finalized_at` non-null, reopen fields null.
- `reopened`: finalized/reopened/late movement/reason non-null and ordered.
- State trigger only `provisional→final→reopened`.
- Linked movement signed delta equals aggregate delta; reattribution movement is flat.

`lot_count_reconciliation_effects`: UUID PK; tenant/company; reconciliation UUID FK RESTRICT; batch BIGINT FK RESTRICT; signed/before/after `DECIMAL(20,4)`; created_at. Company/reconciliation/batch unique; signed nonzero; nonnegative before/after; equation; deferred sum equals parent aggregate delta; immutable.

<a id="p5-s5"></a>
### S5 — provenance and resumable backfill — `P5-S5`, T8

Migration `apps/api/database/migrations/tenant/2026_09_06_140000_add_lot_provenance.php` adds to the three existing producer tables:

- `lot_provenance VARCHAR(24) NOT NULL DEFAULT 'unknown'`.
- `provenance_recorded_at TIMESTAMPTZ NULL`.
- CHECK values `operator_captured|system_fefo_estimate|unknown`.
- Captured/estimate require timestamp; unknown requires null.
- `document_lines.batch_id IS NULL` requires unknown.
- Denormalize tenant/company where absent, backfill through parent, company FK RESTRICT and deferred parent-ownership trigger.
- Existing cascade FKs remain; retained evidence lives independently below.
- W-LOT-A never writes `operator_captured`.

Create `lot_provenance_records`:

| Column | Type | Null | Default / FK |
|---|---|---:|---|
| `id` | UUID PK | no | none |
| `tenant_id` | UUID | no | cross-DB identity |
| `company_id` | UUID | no | `companies.id` RESTRICT |
| `producer` | VARCHAR(40) | no | none |
| `producer_row_id` | UUID | no | intentionally no FK |
| `parent_id` | UUID | no | intentionally no FK |
| `batch_id` | BIGINT | no | `product_batches.id` RESTRICT |
| `quantity` | DECIMAL(20,4) | no | none |
| `provenance` | VARCHAR(24) | no | `unknown` |
| `record_fingerprint` | CHAR(64) | no | none |
| `recorded_at` | TIMESTAMPTZ | no | none |
| `created_at` | TIMESTAMPTZ | no | CURRENT_TIMESTAMP |

Constraints: producer enum `pos_receipt_allocation|document_line|transfer_allocation`; provenance enum; quantity >0; company-scoped producer-row unique; fingerprint check; immutable. Parent IDs are scalar snapshots so cascade deletion cannot erase the audit record.

Create `lot_provenance_backfill_runs`:

| Column | Type | Null | Default / FK |
|---|---|---:|---|
| `id` | UUID PK | no | none |
| `tenant_id` | UUID | no | cross-DB identity |
| `company_id` | UUID | no | `companies.id` RESTRICT |
| `producer` | VARCHAR(40) | no | none |
| `operation_uuid` | UUID | no | none |
| `operation_fingerprint` | CHAR(64) | no | none |
| `resumes_run_id` | UUID | yes | self FK RESTRICT |
| `status` | VARCHAR(16) | no | `pending` |
| `cursor_id` | UUID | yes | none |
| `rows_scanned` | BIGINT | no | `0` |
| `rows_updated` | BIGINT | no | `0` |
| `started_at` | TIMESTAMPTZ | yes | none |
| `heartbeat_at` | TIMESTAMPTZ | yes | none |
| `finished_at` | TIMESTAMPTZ | yes | none |
| `last_error` | TEXT | yes | none |
| `created_at` | TIMESTAMPTZ | no | CURRENT_TIMESTAMP |
| `updated_at` | TIMESTAMPTZ | no | CURRENT_TIMESTAMP |

Constraints: producer/status enums; company/producer/operation unique; one running row per company/producer; counts valid; lifecycle implication checks; resumed run must reference a failed run of the same company/producer, never itself. Dry-run persists no run.

<a id="p5-s6"></a>
### S6 — durable census — `P5-S6`, T9

Central migration `apps/api/database/migrations/2026_09_06_150000_create_lot_ledger_census_runs.php`.

`lot_ledger_census_runs`: `id UUID PK`; `operation_uuid UUID UNIQUE`; `status VARCHAR(16) DEFAULT running`; expected/completed/failed tenant counts BIGINT DEFAULT 0; non-null started/heartbeat timestamps; nullable finished/error; created/updated timestamps. Status `running|complete|failed|stale`; count/timestamp implication checks; one running fleet census.

`lot_ledger_census_company_results`: `id UUID PK`; `run_id UUID FK runs RESTRICT`; `tenant_id UUID FK central tenants RESTRICT`; nullable tenant-local `company_id UUID`; `status VARCHAR(32)`; `entitlement_state VARCHAR(32)`; nullable `entitlement_revision BIGINT`; cohort/compared/drifted BIGINT DEFAULT 0; net/absolute drift `DECIMAL(20,4) DEFAULT 0`; started/finished timestamps; nullable error; created timestamp.

Constraints:

- Status `clean|drifted|not_entitled|entitlement_unresolved|failed`.
- Entitlement `entitled|not_entitled|entitlement_unresolved`.
- UNIQUE `(run_id,tenant_id,company_id)` with PG `NULLS NOT DISTINCT`; equivalent SQLite split indexes.
- Status-specific revision, metrics and error checks.
- Indexes by tenant/company/time and status/time.

<a id="p5-state"></a>
## State machines

| Machine | Allowed edges | Enforcing fields |
|---|---|---|
| Company cutover | `prepared→active→rolled_back` | `state`, operation/actor/timestamp implication checks and transition trigger |
| Dormant hold row | **No dispatched state machine** | `status` may store only requested/recalled; no edge is encoded |
| Deactivation | one immutable `active→inactive` record | `before_is_active`, `after_is_active`, zero snapshots and immutable trigger |
| Used-lot identity | version `n→n+1` only through correction | expected/resulting version, unique keys and batch lock |
| Provenance backfill | `pending→running→complete|failed`; failed may spawn a linked new run | `status`, timestamps, `resumes_run_id`, one-running partial unique |
| Count reconciliation finality | `provisional→final→reopened` | `finality_state`, finalized/reopen fields and transition trigger |
| Census | `running→complete|failed|stale` | status implication checks and one-running partial unique |

<a id="p5-lock"></a>
## Complete writer, eligibility and lock census — `P5-LOCK`

Canonical order:

1. Central module-entitlement transaction and tenant/module advisory lock.
2. Tenant workflow header.
3. Sorted `ProductCostLock` advisory locks.
4. `stock_levels` ordered by product, variant-nullness, variant, location.
5. `inventory_batch_stock` ordered by batch/location.
6. `stock_reservations` ordered by UUID.
7. `product_batches` ordered by ID; product row remains last where WAC requires it.
8. `stock_movement_sequence_counters`.
9. Stock/batch movement and immutable evidence/effect rows.
10. `InventoryGlPostingBuffer::flushIfOutermost()`.
11. GL numbering lock.
12. GL company-chain lock.
13. Journal header/lines.

`FOR UPDATE SKIP LOCKED` at `FEFOInventoryService.php:260-273` becomes ordinary ordered `FOR UPDATE` after product serialization. Contention waits; it cannot silently divert to a later lot.

| Writer / consumer at HEAD | Task | Required remediation | Discriminating proof |
|---|---:|---|---|
| `BatchStockService.php` | T3 | Only implementation behind `BatchStockMutationService`; exact strings and shared locks. | `WLotAWriterMatrixPostgresTest::test_batch_stock_service_routes_all_mutations_through_canonical_service` |
| `BatchStock.php` | T3 | Delete public float accessors/mutators; persistence-only exact casts. | `LotQuantityFloatBanTest::test_batch_stock_has_no_float_mutator` |
| `FEFOInventoryService.php` | T3 | Shared eligibility, no `SKIP LOCKED`, exact return type. | `WLotAEligibilityConsumersPostgresTest::test_pos_fefo_honours_shared_eligibility` |
| `StockReservationService.php` | T3 | Replace float decrements at current `:507,631`; shared eligibility and mutation service. | `WLotAWriterMatrixPostgresTest::test_reserve_release_expire_are_exact_and_locked` |
| `StockAdjustmentService.php` | T3/T7 | Replace direct lot movement/write at `:2128-2152`; canonical mutation; T7 owns count branch. | `WLotAWriterMatrixPostgresTest::test_adjustment_receive_issue_and_count_use_canonical_mutation` |
| `WeightedAverageCostService.php` | T3 | Preserve advisory→stock rows→product-last order documented at `:92-96`; add missing sale lock. | `WLotALockOrderPostgresTest::test_wac_and_lot_sale_have_one_order` |
| `GoodsReceiptService.php` | T3 | Canonical lot receive and GL buffer; retain GR-IR semantics. | `WLotAWriterMatrixPostgresTest::test_goods_receipt_uses_canonical_lot_and_buffer` |
| `OpeningBalancePostingService.php`, `ResetOpeningBalanceService.php` | T3 | Canonical lot receive/reverse; DEFAULT remains explicit. | `WLotAWriterMatrixPostgresTest::test_opening_and_reset_are_exact_inverse_operations` |
| `StockAdjustmentDocumentService.php` | T3 | Canonical mutation and GL buffer. | `WLotAWriterMatrixPostgresTest::test_adjustment_document_uses_canonical_lot_and_buffer` |
| `SupplierGoodsReturnNoteService.php` | T3 | Eligibility, canonical issue and buffer. | `WLotAWriterMatrixPostgresTest::test_supplier_return_honours_lot_eligibility` |
| `StockTransferService.php` | T3 | Shared eligibility at source/destination, product lock before FEFO/lot rows. | `WLotAEligibilityConsumersPostgresTest::test_stock_transfer_honours_shared_eligibility_at_both_locations` |
| `GroupedWriteOffService.php`, `BatchWriteOffService.php`, `ReverseWriteOffService.php` | T3 | Canonical mutation; replace direct `GeneralLedgerService` use visible at `BatchWriteOffService.php:7,29-35,103-121` and `ReverseWriteOffService.php:7,55-60,218-228` with buffer contexts. | `WLotAWriterMatrixPostgresTest::test_writeoff_and_reverse_are_buffered_exact_inverses` |
| `RepairPhantomDefaultBatchesCommand.php` | T3 | Maintenance lock, canonical mutation, explicit non-concurrent mode. | `WLotAWriterMatrixPostgresTest::test_repair_command_refuses_without_maintenance_lock` |
| `DeliveryNoteService.php`, `ReturnNoteService.php` | T3/T8 | Canonical issue/return; retain producer provenance. | `WLotAWriterMatrixPostgresTest::test_delivery_and_return_preserve_lot_and_buffer` |
| `ReceiptCreationService.php`, `ReceiptReturnService.php` | T3/T8 | Legacy server writer remains exact and estimate-labelled. | `WLotAEligibilityConsumersPostgresTest::test_legacy_receipt_and_return_use_shared_eligibility` |
| `ReturnScrapWriteOffService.php` | T3 | Canonical issue and buffer; never restores scrapped lot. | `WLotAWriterMatrixPostgresTest::test_return_scrap_uses_canonical_issue_once` |
| `PosCoreReceiptProjection.php` | T3/T8 | Aggregate remains always active; lot arm fenced, exact and estimate-labelled. | `WLotAEligibilityConsumersPostgresTest::test_pos_projection_lot_arm_is_fenced_and_exact` |
| `ProductOpeningStockPhase.php` | T3 | Indirect writer only through opening/canonical services; retain DEFAULT. | Architecture manifest assertion |
| `MarketplaceOrderService.php`, `CartService.php`, `InventoryReservationAdapter.php` | T3 | Reservation consumers only; no direct lot writes. | Architecture manifest plus reservation test |
| `StockLevelMigrationService.php`, `StockThresholdService.php`, `FixOrphanedProducts.php` | T3 | Classified aggregate-only; architecture guard fails if any gains lot mutation. | `InventoryWriterLockManifestTest::test_aggregate_only_allowlist_has_no_lot_write` |
| `LotIdentificationService` | T5 | Flat aggregate movement, offsetting effects, no GL. | T5 identification PG test |
| `LotCountObservationService` | T6 | Evidence/watermark only; no stock write. | T6 observation test |
| `LotCountReconciliationService` | T7 | Aggregate adjustment/reattribution only through canonical mutation/buffer. | T7 reconciliation PG test |
| `LateSyncResidualDetector.php` | T7 | Read-only detector calls reopening orchestration, never rewrites observation. | T7 late-sync test |
| Entitlement mutators | T2 | Same central advisory lock and monotonic revision. | Both T2 race directions |
| `InventoryGlPostingBuffer.php` | T3/T7 | Exclusive inventory→GL owner; transaction-scoped enqueue/flush preserved. | `InventoryGlPostingViaBufferOnlyTest` |
| `InventoryGlPostingService.php` | T3/T7 | Callable only by buffer; flat movements skipped. | Same architecture test |
| `GeneralLedgerService.php` | downstream | No direct W-LOT inventory caller after T3. | Same architecture test |

Architecture guards:

- `apps/api/tests/Architecture/InventoryWriterLockManifestTest.php`.
- `apps/api/tests/PHPStan/InventoryGlPostingViaBufferOnlyTest.php` plus its rule/fixture.
- `apps/api/tests/Architecture/LotQuantityFloatBanTest.php`.
- `apps/web/eslint-rules/no-lot-quantity-number.ts`.
- `apps/web/eslint-rules/no-shadowed-generated-dto.ts`.

<a id="p5-review"></a>
## Reviewer gate contract

For each task, the dispatcher runs every named `.claude/agents/*-reviewer.md` against `$(git merge-base origin/dev HEAD)..HEAD` with:

```text
Review only W-LOT-A rev 5 Task <ID>. Verify every listed production path, signature,
schema constraint, red-first assertion, Convention-09 case, writer/lock rule,
generated-type check and rollback command. Run the literal task commands.
Cite path:line for every finding. Return BLOCKED for any BLOCKER or MAJOR.
Do not edit, commit, merge or push.
```

Acceptance is a recorded `APPROVED` from every named reviewer, zero BLOCKER/MAJOR, on the identical candidate SHA.

<a id="p5-tasks"></a>
## Dispatch tasks

<a id="p5-t1"></a>
### Task 1 — rollout, entrypoints, glossary and artifact fingerprint — `P5-T1`

Production files:

- `docs/glossary.md`
- `apps/api/config/w_lot_a.php` (new)
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/WLotARolloutFlagsData.php` (new)
- `apps/api/app/Console/Commands/CreateWLotARolloutRevisionCommand.php` (new)
- `apps/api/app/Console/Commands/WLotACutoverCommand.php` (new)
- `apps/api/app/Console/Commands/WLotAPreflightCommand.php` (new)
- `apps/api/docker/verify-w-lot-a-env.sh` (new)
- `apps/api/docker/entrypoint.sh`
- `apps/api/docker/entrypoint-worker.sh`
- `apps/api/docker/entrypoint-scheduler.sh`
- `apps/api/docker/entrypoint-websocket.sh`
- `apps/api/.env.example`
- `docker-compose.staging.yml`
- `apps/web/vite.config.ts`
- `apps/web/tools/wLotABuildFingerprintPlugin.ts` (new)
- `apps/web/Dockerfile`
- `scripts/release/w-lot-a-staging.sh` (new)

Signatures:

```php
final readonly class WLotARolloutFlagsData extends Data
{
    public function __construct(
        public bool $wlotAEntitlementFence,
        public bool $wlotAExactQuantities,
        public bool $wlotAIdentityControls,
        public bool $wlotALotCounts,
        public bool $wlotAProvenance,
        public bool $wlotADriftScheduler,
    );

    public static function fromArray(array $flags): self;
    public function toCanonicalArray(): array;
    public function fingerprint(string $artifactSha): string;
}
```

```text
inventory:w-lot-a-rollout-revision
  {--artifact-sha= : Required lowercase 40-character SHA}
  {--flags-json= : Required object containing exactly six booleans}
  {--created-by= : Required actor}
  {--format=json : json only}

inventory:w-lot-a-cutover
  {action : prepare|activate|rollback}
  {revision : Revision UUID}
  {--tenant= : One tenant UUID}
  {--all-tenants : All reachable tenants}
  {--company= : Optional company UUID}
  {--operation= : Required UUIDv7}
  {--expected-active-revision= : Required for rollback}
  {--actor= : Required actor}
  {--manifest= : Required for activate/rollback}
  {--format=json : json only}

inventory:w-lot-a-preflight
  {--tenant=}
  {--all-tenants}
  {--company=}
  {--artifact-sha=}
  {--expected-revision=}
  {--expected-flags-fingerprint=}
  {--expected-cutover-manifest=}
  {--fail-on-drift}
  {--fail-on-unresolved}
  {--fail-on-stale}
  {--format=json}
```

```text
scripts/release/w-lot-a-staging.sh
  verify-services --sha=<40hex>
  migrate --sha=<40hex>
  seed-permissions --sha=<40hex>
  deploy-web --sha=<40hex> --push=P1|P3|P4|P5
  smoke --sha=<40hex>
  rollback --to-sha=<40hex> --revision=<uuid>
```

Environment contract:

- Six rollout variables and `AUTO_MIGRATE` are forwarded through `x-api-env`, inherited by all four Laravel services; current inheritance anchors are `docker-compose.staging.yml:17-58,184-186,212-214,233-234,248-249`.
- All four entrypoints source one validator immediately after their banners. Current distinct entrypoints begin at `apps/api/docker/entrypoint.sh:13`, `entrypoint-worker.sh:13`, `entrypoint-scheduler.sh:13`, `entrypoint-websocket.sh:13`.
- Validator accepts literal `true|false`, prints values, build SHA and canonical SHA-256 fingerprint, and fails closed.
- `entrypoint.sh` makes central/rolling migration failure fatal; current swallowed failures at `apps/api/docker/entrypoint.sh:131-154` are removed.
- `/build-fingerprint.json` contains `build_sha`, `contract_rev:5`, `feature_fingerprint:"w-lot-a-rev5-server-core"`, entry asset path/hash and the six names.

Red first:

- File/class/method: `apps/api/tests/Feature/Console/WLotARolloutRevisionCommandTest.php::test_revision_rejects_a_missing_or_non_boolean_flag`.
- First failure: `self::assertSame(Command::INVALID, $exitCode)`.
- Command/lane: `cd apps/api && php artisan test tests/Feature/Console/WLotARolloutRevisionCommandTest.php --filter='test_revision_rejects_a_missing_or_non_boolean_flag'` — PHPUnit SQLite.
- Convention 09: not applicable; release metadata is not an operator catalogue.
- Reviewers: `.claude/agents/tenancy-authz-reviewer.md`, `.claude/agents/frontend-conventions-reviewer.md`.
- Rollback: redeploy predecessor API/web artifacts; no schema exists in P1.

<a id="p5-t2"></a>
### Task 2 — transactional company entitlement fence — `P5-T2`

Production files:

- `apps/api/app/Modules/BatchExpiry/Domain/Enums/CompanyModuleEntitlementState.php` (new)
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/CompanyModuleEntitlementDecisionData.php` (new)
- `apps/api/app/Modules/BatchExpiry/Application/Services/CompanyModuleEntitlementFence.php` (new)
- `apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php`
- `apps/api/app/Services/CompanyConfigService.php`
- `apps/api/app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php`
- `apps/api/app/Http/Controllers/Api/Admin/VerticalConfigController.php`
- `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php`
- `apps/api/app/Modules/Tenant/Application/Commands/ReconcileModulesCommand.php`
- `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php`
- `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php`
- `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php`
- `apps/api/app/Modules/Product/Presentation/Requests/UpdateProductRequest.php`
- `apps/web/src/features/inventory/ProductForm.tsx`
- `packages/shared/types/generated.d.ts`

Signatures:

```php
enum CompanyModuleEntitlementState: string
{
    case Entitled = 'entitled';
    case NotEntitled = 'not_entitled';
    case EntitlementUnresolved = 'entitlement_unresolved';
}

final readonly class CompanyModuleEntitlementDecisionData extends Data
{
    public function __construct(
        public CompanyModuleEntitlementState $state,
        public string $tenantId,
        public string $companyId,
        public string $module,
        public ?int $effectiveRevision,
        public ?string $reason,
    );
}

final class CompanyModuleEntitlementFence
{
    public function resolve(
        string $tenantId,
        string $companyId,
        ModuleName $module,
    ): CompanyModuleEntitlementDecisionData;

    public function executeEntitledWrite(
        string $tenantId,
        string $companyId,
        ModuleName $module,
        Closure $tenantWrite,
    ): CompanyModuleEntitlementDecisionData;

    public function executeTenantConfigMutation(
        string $tenantId,
        ModuleName $module,
        Closure $centralMutation,
    ): int;

    /** @param list<string> $tenantIds @return array<string,int> */
    public function executeVerticalFanoutMutation(
        array $tenantIds,
        ModuleName $module,
        Closure $centralMutation,
    ): array;
}
```

The central transaction takes the advisory lock, reads revision/config, initializes tenant ownership, runs/commits the tenant callback while the central lock remains held, tears down tenancy, then commits central. Mutation takes the same lock and bumps revision atomically.

Red first:

- `apps/api/tests/Feature/BatchExpiry/WLotAEntitlementFencePostgresTest.php::test_revocation_waits_for_tenant_commit_and_blocks_next_lot_write`.
- First failure: `self::assertSame(0, $blockedMovementCount)`.
- Companion reverse race: `::test_grant_waits_for_inflight_denial_and_only_next_write_is_entitled`; first failure `self::assertSame(1, $allowedMovementCount)`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry/WLotAEntitlementFencePostgresTest.php` — PHPUnit PostgreSQL.
- C09 file: `apps/api/tests/Feature/BatchExpiry/WLotAEntitlementSecondOfEverythingTest.php`; methods `test_second_company_uses_company_b`, `test_second_location_remains_selected`, `test_identical_product_update_does_not_bump_revision`; first assertions respectively compare company B ID, assert location B row, assert unchanged revision. Same PG command by file.
- Generate in-task: `cd apps/api && php artisan typescript:transform`.
- Reviewer: `.claude/agents/tenancy-authz-reviewer.md`.
- Rollback: flags/cutovers off, redeploy P1; retain revision/cutover data.

<a id="p5-t3"></a>
### Task 3 — scale-four, canonical mutation, eligibility and every existing writer — `P5-T3`

Production files are every existing path assigned to T3 in `P5-LOCK`, plus:

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchQuantityData.php` (new)
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchStockMutationResultData.php` (new)
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchData.php` (new)
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchStockData.php` (new)
- `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferData.php` (new)
- `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferLineData.php` (new)
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockMutationService.php` (new)
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchEligibilityService.php` (new)
- `apps/api/app/Console/Commands/WLotAQuantityCensusCommand.php` (new)
- S3 scale migration
- architecture/PHPStan/ESLint guards named in `P5-LOCK`
- `apps/web/src/features/batches/types.ts`
- `apps/web/src/features/stock-transfers/types/index.ts`
- `apps/web/src/features/batches/api/batches.ts`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/web/src/features/batches/pages/BatchListPage.tsx`
- `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx`
- `apps/web/src/features/stock-transfers/api/stockTransferApi.ts`
- `apps/web/src/features/stock-transfers/api/queries.ts`
- `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx`
- `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx`
- `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx`
- `packages/shared/types/generated.d.ts`

Core signatures:

```php
final readonly class BatchQuantityData extends Data
{
    public function __construct(
        public string $quantity,
        public string $reservedQuantity,
        public string $availableQuantity,
    );
}

final class BatchStockMutationService
{
    public function receive(string $tenantId, string $companyId, int $batchId, string $locationId, string $quantity, MovementContextData $context): BatchStockMutationResultData;
    public function issue(string $tenantId, string $companyId, int $batchId, string $locationId, string $quantity, MovementContextData $context): BatchStockMutationResultData;
    public function transfer(string $tenantId, string $companyId, int $batchId, string $sourceLocationId, string $destinationLocationId, string $quantity, MovementContextData $context): BatchStockMutationResultData;
    public function reserve(string $tenantId, string $companyId, int $batchId, string $locationId, string $quantity): BatchQuantityData;
    public function release(string $tenantId, string $companyId, int $batchId, string $locationId, string $quantity): BatchQuantityData;
    public function adjust(string $tenantId, string $companyId, int $batchId, string $locationId, string $signedQuantity, MovementContextData $context): BatchStockMutationResultData;
}

final class BatchEligibilityService
{
    public function assertEligible(Batch $batch, string $locationId, BatchEligibilityPurpose $purpose): void;
    public function applyToQuery(Builder $query, string $locationId, BatchEligibilityPurpose $purpose): Builder;
}
```

```text
inventory:w-lot-a-quantity-census
  {--tenant=}
  {--all-tenants}
  {--company=}
  {--fail-on-schema-drift}
  {--fail-on-noncanonical-values}
  {--format=json}
```

Red first:

- `apps/api/tests/Feature/BatchExpiry/WLotAExactQuantityBoundaryTest.php::test_fractional_transfer_accepts_exact_boundary_and_rejects_one_ten_thousandth_more`.
- First failure: `self::assertSame('0.0000', (string) $source->refresh()->available_quantity)`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry/WLotAExactQuantityBoundaryTest.php --filter='test_fractional_transfer_accepts_exact_boundary_and_rejects_one_ten_thousandth_more'` — PHPUnit PostgreSQL.
- Writer matrix: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry/WLotAWriterMatrixPostgresTest.php tests/Feature/BatchExpiry/WLotAEligibilityConsumersPostgresTest.php tests/Feature/BatchExpiry/WLotALockOrderPostgresTest.php`.
- Web red: `apps/web/src/features/batches/pages/BatchDetailPage.exactQuantity.test.tsx`, test `renders exact quantities without JavaScript number coercion`, first failure `expect(screen.getByText('9007199254740991.1234')).toBeInTheDocument()`, command `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchDetailPage.exactQuantity.test.tsx`.
- C09: `WLotAExactQuantitySecondOfEverythingTest::test_exact_quantity_is_company_scoped`, `::test_transfer_uses_selected_second_location`, `::test_duplicate_reservation_retry_does_not_double`; first assertions are `'1.2345'`, location-B-only change and original reserved string. PG by file.
- Run `php artisan typescript:transform` before typecheck/commit; local type files must contain no domain interface.
- Reviewers: inventory-costing, stock-GL-interaction, tenancy-authz, frontend-conventions.
- Rollback: deactivate exact-quantity cutover and deploy P2; never narrow columns.

<a id="p5-t4"></a>
### Task 4 — route authorization and audited deactivation; no recall lifecycle — `P5-T4`

Production files:

- S2 migration
- `BatchHoldStatus.php` and dormant model
- `BatchDeactivationTransition.php` (new)
- `BatchDeactivationTransitionData.php` (new)
- `BatchDeactivationService.php` (new)
- `DeactivateBatchRequest.php` (new)
- `BatchController.php`, `BatchTraceabilityController.php`, `Presentation/routes.php`
- `BatchRepository.php`
- `RolesAndPermissionsSeeder.php`
- web routes/sidebar/batch pages/API/locales
- generated permission map and shared types

Signatures:

```php
final readonly class BatchDeactivationTransitionData extends Data
{
    public function __construct(
        public string $batchUuid,
        public string $operationUuid,
        public string $reason,
    );
}

final class BatchDeactivationService
{
    public function deactivate(
        Batch $batch,
        BatchDeactivationTransitionData $data,
        User $actor,
    ): BatchDeactivationTransition;
}

final class BatchController
{
    public function destroy(DeactivateBatchRequest $request, string $uuid): JsonResponse;
    public function recall(RecallBatchRequest $request, string $uuid): JsonResponse;
}
```

Permissions:

- list/show/expiring/expired/stock/product-stock/POS batch data: `batches.view`.
- trace/history/export: `batches.traceability`.
- create/update/delete/global recall/transfer/write-off: existing specific permission.
- correction: `batches.correct-identity`; identification: `batches.identify`; lot count: `batches.count-lots`; health: `batches.health`.
- `batches.recall.request` may be seeded as dormant catalogue preparation but is granted to no role and attached to no route until Q10 activation.
- manager loses `batches.recall`.
- `general_manager` is manager with no location restriction plus exactly `batches.recall` and `treasury.manage_all_locations`.
- cashier retains view; viewer and operator gain view; health/correction/identification admin-only.

Red first:

- `apps/api/tests/Feature/BatchExpiry/WLotABatchDeactivationPostgresTest.php::test_positive_or_reserved_stock_blocks_deactivation_and_exact_retry_preserves_one_transition`.
- First failure: `self::assertDatabaseCount('batch_deactivation_transitions', 1)`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry/WLotABatchDeactivationPostgresTest.php` — PHPUnit PostgreSQL.
- Authorization: `apps/api/tests/Feature/Security/WLotABatchRouteAuthorizationTest.php`; one data provider covers list/show/create/update/delete/recall/trace/stock/expiring/expired/correction/identify/count/health, fresh/existing tenants, allowed/forbidden roles and malformed identifiers. First failure: `self::assertSame($expectedStatus, $response->status())`. SQLite plus PG by identical path.
- Web: `apps/web/src/features/batches/pages/BatchAuthorization.test.tsx`, first failure asserts hidden forbidden action; Vitest by path.
- C09: second-company deactivation leaves A active; second-location stock blocks only the targeted batch; retry leaves one transition. File `WLotADeactivationSecondOfEverythingTest.php`, PG by file.
- Generate DTO and permission map in-task.
- Reviewers: tenancy-authz, inventory-costing, frontend-conventions.
- Rollback: disable cutover/deploy T3; retain transition evidence. No recall-request row is created.

<a id="p5-t5"></a>
### Task 5 — identification first, then used-lot freeze/correction — `P5-T5`

Production paths are S3 migrations plus rev-4 T5 paths, unchanged in name.

Public signatures are the rev-4 `BatchIdentityCorrectionData`, `LotIdentificationLineData`, `LotIdentificationData`, `BatchIdentityCorrectionService::correct(...)` and `LotIdentificationService::identify(...)`, with every quantity a string and UUID an explicit string.

Red first:

- `WLotAUsedLotIdentityCorrectionTest::test_ordinary_patch_fails_after_first_movement_but_versioned_correction_preserves_before_and_after`; first failure `self::assertSame('BATCH_IDENTITY_LOCKED', $response->json('error.code'))`; PHPUnit SQLite command by exact file/filter.
- `WLotADefaultIdentificationPostgresTest::test_default_is_split_into_two_lots_with_zero_aggregate_wac_and_gl_change`; first failure `self::assertSame('0.0000', $aggregateDelta)`; PG command by exact file/filter.
- C09 file `WLotAIdentitySecondOfEverythingTest.php`: same UUID independently in company B; selected second location only; retry adds no movement/effect/GL. PG by file.
- Reviewers: inventory-costing, stock-GL-interaction, tenancy-authz, frontend-conventions.
- Rollback: disable identity cutover; retain corrections/identifications; never reconstruct DEFAULT.

<a id="p5-t6"></a>
### Task 6 — movement sequence, observations and generated counting DTOs — `P5-T6`

Exact paths: S4 sequence/observation migration; new allocator, observation service and DTO; `StockMovement.php`, `InventoryCountingItem.php`, `MovementReplayService.php`, `InventoryCountingService.php`, request/controller/routes; counting API/pages/types and `packages/shared/types/generated.d.ts`.

Signatures are rev-4 `StockMovementSequenceAllocator::next/currentForGrain`, `SubmitLotObservationData`, `LotCountObservationService::record` and the expanded `InventoryCountingService::submitCount`, unchanged except generated DTOs own all response shapes.

Red first:

- `WLotAMovementSequencePostgresTest::test_uuid4_history_and_same_second_insertions_replay_by_numeric_sequence`; first failure `self::assertSame('7.0000', $replayedQuantity)`; PG by file/filter.
- `WLotALotObservationRequestTest::test_count_claim_after_device_now_plus_five_minutes_is_rejected`; first failure status 422; SQLite by file/filter.
- C09 file `WLotAObservationSecondOfEverythingTest.php`: company-B marker isolation, location-B isolation, identical rerun single observation/conflicting 409; PG by file.
- Run `typescript:transform` in this task and reduce `inventory-counting/types.ts` to generated re-exports/UI-only state.
- Reviewers: inventory-costing, tenancy-authz, frontend-conventions.
- Rollback: lot-count flag off; preserve sequences/observations and legacy UUID columns.

<a id="p5-t7"></a>
### Task 7 — reconciliation, reattribution and late-sync finality — `P5-T7`

Exact paths: S4 reconciliation tables; new result DTO/service; `CountingReconciliationService.php`, `InventoryCountingService.php`, `LateSyncResidualDetector.php`, counting-completed listener, GL buffer/service, counting controllers and review/detail components.

Signatures:

```php
final class LotCountReconciliationService
{
    public function apply(
        InventoryCountingItem $item,
        int $countNumber,
        string $operationUuid,
        User $actor,
    ): LotCountReconciliationResultData;

    public function finalize(
        LotCountReconciliation $reconciliation,
        User $actor,
    ): LotCountReconciliationResultData;

    public function reopenForLateSync(
        LotCountReconciliation $reconciliation,
        StockMovement $lateMovement,
        string $reason,
    ): LotCountReconciliationResultData;
}
```

Red first:

- `WLotALotCountReconciliationPostgresTest::test_fifteen_plus_five_reattributes_five_between_lots_with_one_flat_row_and_zero_journals`; first failure zero journal count.
- `WLotALateSyncFinalityPostgresTest::test_pre_count_event_arriving_after_marker_reopens_final_reconciliation_without_reapplying_effect`; first failure `self::assertSame('reopened', $reconciliation->refresh()->finality_state)`.
- One PG command naming both files.
- Required scenarios in the same classes: 10+10→10+5, +5 identified surplus, explicit zero versus missing, legacy active count, late receipt, late sale, sale/transfer both race directions, repeat finalize.
- C09 `WLotACountSecondOfEverythingTest.php`: company, second location, rerun no extra movement/WAC/GL. PG by file.
- Reviewers: inventory-costing, stock-GL-interaction, tenancy-authz, frontend-conventions.
- Rollback: count flag off; retain accepted evidence/movements; never auto-reverse a count.

<a id="p5-t8"></a>
### Task 8 — retained provenance, all producers/readers and resumable backfill — `P5-T8`

Exact paths: S5 migration; `LotProvenance.php`, DTO/model, backfill command; three producer models/services; trace controller/routes; batch/transfer details; generated shared types.

Signatures:

```php
enum LotProvenance: string
{
    case OperatorCaptured = 'operator_captured';
    case SystemFefoEstimate = 'system_fefo_estimate';
    case Unknown = 'unknown';
}

final readonly class LotProvenanceData extends Data
{
    public function __construct(
        public string $producer,
        public string $producerRowId,
        public string $parentId,
        public int $batchId,
        public string $quantity,
        public LotProvenance $provenance,
        public string $recordFingerprint,
    );
}
```

```text
inventory:backfill-lot-provenance
  {--tenant=}
  {--all-tenants}
  {--company=}
  {--chunk=500 : 1..5000}
  {--dry-run : Non-persistent preview; incompatible with --operation/--resume-run}
  {--execute : Persist; requires --operation}
  {--operation= : Fresh UUIDv7 for execute}
  {--resume-run= : Failed run UUID; execute only and requires a fresh operation UUID}
  {--format=text : text|json}
```

Red first:

- `WLotAProvenanceProducerTest::test_pos_document_and_transfer_producers_persist_estimate_and_retained_record`; first failure compares three `system_fefo_estimate` values.
- `WLotAProvenanceRetentionPostgresTest::test_parent_deletion_cannot_delete_retained_provenance`; first failure asserts retained row exists.
- `WLotAProvenanceResumePostgresTest::test_failed_run_resumes_as_linked_new_operation_and_dry_run_persists_nothing`; first failure asserts new `resumes_run_id`.
- PG command naming all three files.
- C09 `WLotAProvenanceSecondOfEverythingTest.php`: company B isolation, second-location transfer provenance, second execution updates zero. PG by file.
- Run `typescript:transform` before web tests/commit.
- Reviewers: inventory-costing, fiscal-pos, tenancy-authz, frontend-conventions.
- Rollback: provenance flag off; retain annotations, independent records and run history.

<a id="p5-t9"></a>
### Task 9 — durable entitlement-aware 03:20 census — `P5-T9`

Exact files: S6 migration, current census service/command, new health controller/panel, BatchExpiry routes, `apps/api/routes/console.php`, batch API/list page.

CLI signature remains the rev-4 signature with `--tenant|--all-tenants`, optional company/operation, `--stale-after=180`, `--persist`, three failure flags and text/json output.

Red first:

- `LotLedgerDriftCensusPostgresTest::test_entitled_clean_drifted_not_entitled_unresolved_and_missed_run_are_durable_and_read_only`.
- First failure: `self::assertSame('entitlement_unresolved', $unresolved->status)`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry/LotLedgerDriftCensusPostgresTest.php` — PostgreSQL.
- C09 exclusion: read-only detector; class still proves company/location isolation and rerun/stale recovery.
- Reviewers: inventory-costing, tenancy-authz.
- Rollback: scheduler flag off; retain history and run one manual read-only census.

<a id="p5-t10"></a>
### Task 10 — CI, generated-contract ratchets, staging smoke and release gate — `P5-T10`

Exact files:

- `.github/workflows/ci.yml`
- all architecture guards named in `P5-LOCK`
- `apps/web/e2e/smoke/w-lot-a.smoke.ts` (new)
- `apps/web/playwright.smoke.config.ts`
- `apps/web/tools/__tests__/wLotABuildFingerprintPlugin.test.ts` (new)
- `apps/api/tests/Feature/Console/WLotAPreflightCommandTest.php`
- `apps/api/tests/Feature/BatchExpiry/WLotAEndToEndPostgresTest.php`
- generated shared types and permission map

Red first:

- `WLotAEndToEndPostgresTest::test_identify_count_transfer_trace_and_census_converge_without_float_or_gl_drift`.
- First failure: `self::assertSame('0.0000', $finalLotMinusAggregate)`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry/WLotAEndToEndPostgresTest.php --filter='test_identify_count_transfer_trace_and_census_converge_without_float_or_gl_drift'` — PostgreSQL.
- CI adds an unconditional path-based W-LOT-A step to the live PG job rooted at `.github/workflows/ci.yml:578-667`.
- Convention 09 exclusion: release gate, not a substitute for T2–T8.
- Reviewers: all five domain reviewers used above on the identical SHA.
- Rollback: block promotion; if staged, cutovers off before predecessor deployment.

<a id="p5-withheld"></a>
## WITHHELD-Q10 — branch recall lifecycle activation — `P5-WITHHELD`

This is not a dispatchable task and is not included in the ten-task count.

Activation condition: the owner must rule Q10 in the ruling-of-record, explicitly naming every allowed terminal disposition, who may perform it, required evidence/signature, retry behavior, whether the requester may participate and how historical open requests are migrated.

Only after that ruling may a new plan define lifecycle fields, transition evidence, writer service, routes, permissions, UI, tests, feature flag and rollout. Until then:

- `batch_recall_requests` is dormant.
- No production request writer exists.
- No request lifecycle flag is present.
- Existing global recall remains a separately authorized boolean action.
- No release/reject behavior is inferred.

<a id="p5-deploy"></a>
## Executable five-push staging manifest — `P5-DEPLOY`

Staging facts:

- API auto-deploys; web does not: `docs/factory/WORKFLOW.md:206-229`.
- Web application ID: `mY6P_PHb4pw-2LdG1Y7Ml`.
- Every remote command runs through SSH in the configured compose directory and then `docker compose ... exec -T api`; no local macOS compose command targets staging.
- Every block reloads IDs from an evidence JSON file; shell variables are never assumed to survive.
- All UUIDs are printed by a `Str::uuid7()` command and captured before use.

Required environment:

```bash
: "${STAGING_SSH:?}"
: "${STAGING_COMPOSE_DIR:?}"
: "${DOKPLOY_URL:?}"
: "${DOKPLOY_API_KEY:?}"
: "${DEPLOY_ACTOR:?}"
: "${SMOKE_TEST_EMAIL:?}"
: "${SMOKE_TEST_PASSWORD:?}"

WEB_APPLICATION_ID=mY6P_PHb4pw-2LdG1Y7Ml
WEB_URL=https://erp.otospex.dev
API_URL=https://api.erp.otospex.dev
EVIDENCE_DIR="$(pwd)/docs/sessions/w-lot-a-rev5"
mkdir -p "$EVIDENCE_DIR"
```

Remote Artisan pattern:

```bash
ssh "$STAGING_SSH" \
  "cd '$STAGING_COMPOSE_DIR' &&
   docker compose -f docker-compose.staging.yml exec -T api \
   env DB_HOST=\${DB_DIRECT_HOST:-postgres} php artisan <command>"
```

Web-deploy contract after P1/P3/P4/P5:

1. Set `DEPLOY_TITLE="W-LOT-A-${PUSH}-${CANDIDATE_SHA}-$(date +%s)"`.
2. POST `application.redeploy` with exact title and description=`CANDIDATE_SHA`.
3. Poll `deployment.all` for exact title+description; capture its ID before polling status.
4. Require `done|success`; persist ID/status.
5. Require `/build-fingerprint.json.build_sha == CANDIDATE_SHA`, contract 5 and expected feature fingerprint.
6. Fetch the declared entry asset and require its SHA-256 equals the fingerprint.
7. Run `w-lot-a.smoke.ts` with `EXPECTED_BUILD_SHA`.
8. Persist old/new deployment IDs, asset hashes, fingerprint and transcript.

### Push 1 — rollout/environment/fingerprint scaffold; six flags false

```bash
git add -- \
  docs/glossary.md \
  apps/api/config/w_lot_a.php \
  apps/api/app/Modules/BatchExpiry/Application/DTOs/WLotARolloutFlagsData.php \
  apps/api/app/Console/Commands/CreateWLotARolloutRevisionCommand.php \
  apps/api/app/Console/Commands/WLotACutoverCommand.php \
  apps/api/app/Console/Commands/WLotAPreflightCommand.php \
  apps/api/docker/verify-w-lot-a-env.sh \
  apps/api/docker/entrypoint.sh \
  apps/api/docker/entrypoint-worker.sh \
  apps/api/docker/entrypoint-scheduler.sh \
  apps/api/docker/entrypoint-websocket.sh \
  apps/api/.env.example \
  docker-compose.staging.yml \
  apps/web/vite.config.ts \
  apps/web/tools/wLotABuildFingerprintPlugin.ts \
  apps/web/tools/__tests__/wLotABuildFingerprintPlugin.test.ts \
  apps/web/Dockerfile \
  scripts/release/w-lot-a-staging.sh

git commit -m "W-LOT-A P1: add rollout and artifact controls"
P1_SHA="$(git rev-parse HEAD)"
printf '{"p1_sha":"%s"}\n' "$P1_SHA" > "$EVIDENCE_DIR/p1.json"
git push origin "$P1_SHA:refs/heads/dev"
```

Run unit/static checks, wait for API fingerprint SHA, force-recreate all four Laravel services remotely, verify six false flags plus `AUTO_MIGRATE=false`, then explicit web deploy/fingerprint/Playwright.

Rollback P1: redeploy predecessor API SHA and captured prior web deployment; no database action.

### Push 2 — all additive schemas and scale assertion; no live callers

```bash
git add -- \
  apps/api/database/migrations/2026_09_06_100000_create_w_lot_a_rollout_revisions.php \
  apps/api/database/migrations/tenant/2026_09_06_100100_create_w_lot_a_company_cutovers.php \
  apps/api/database/migrations/tenant/2026_09_06_110000_create_policy_neutral_batch_holds_and_deactivations.php \
  apps/api/database/migrations/tenant/2026_09_06_115000_enforce_lot_quantity_scale_four.php \
  apps/api/database/migrations/tenant/2026_09_06_120000_add_batch_identity_corrections.php \
  apps/api/database/migrations/tenant/2026_09_06_121000_create_lot_identifications.php \
  apps/api/database/migrations/tenant/2026_09_06_130000_add_stock_movement_sequence.php \
  apps/api/database/migrations/tenant/2026_09_06_131000_create_lot_count_observations.php \
  apps/api/database/migrations/tenant/2026_09_06_140000_add_lot_provenance.php \
  apps/api/database/migrations/2026_09_06_150000_create_lot_ledger_census_runs.php \
  apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchHoldStatus.php \
  apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchRecallRequest.php \
  apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchDeactivationTransition.php

git commit -m "W-LOT-A P2: add policy-neutral additive schemas"
P2_SHA="$(git rev-parse HEAD)"
jq -n --arg p2 "$P2_SHA" '{p2_sha:$p2}' > "$EVIDENCE_DIR/p2.json"
git push origin "$P2_SHA:refs/heads/dev"
```

After API deploy, run remotely:

```bash
php artisan migrate --force
php artisan tenants:migrate-rolling --force
php artisan tenants:migrate-rolling --force
```

Capture the first rolling output. Parse every `→ ... (<uuid>)`/UUID row into `p2-tenants.tsv`; require declared count equals visited unique IDs and final text `Done. N tenant(s) migrated, 0 failed.`. The rerun must also report zero failures. Run quantity census and persist its JSON. No later command may use a tenant ID not in this file.

Rollback P2: deploy P1; retain all additive/widened schema; never run down migrations.

### Push 3 — entitlement, exact quantities, shared eligibility and writer remediation

Commit exact T2/T3 paths and generated types after:

```bash
cd apps/api
php artisan typescript:transform
cd ../..
pnpm --filter @autoerp/web lint
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/web test
cd apps/api
php artisan test -c phpunit-pgsql.xml \
  tests/Feature/BatchExpiry/WLotAEntitlementFencePostgresTest.php \
  tests/Feature/BatchExpiry/WLotAExactQuantityBoundaryTest.php \
  tests/Feature/BatchExpiry/WLotAWriterMatrixPostgresTest.php \
  tests/Feature/BatchExpiry/WLotAEligibilityConsumersPostgresTest.php \
  tests/Feature/BatchExpiry/WLotALockOrderPostgresTest.php
cd ../..
git add -- apps/api apps/web packages/shared/types/generated.d.ts
git commit -m "W-LOT-A P3: enforce entitlement and exact lot mutation"
P3_SHA="$(git rev-parse HEAD)"
jq -n --arg p3 "$P3_SHA" '{p3_sha:$p3}' > "$EVIDENCE_DIR/p3.json"
git push origin "$P3_SHA:refs/heads/dev"
```

Flags remain false. Wait for API SHA, remotely recreate/verify services, run preflight and quantity census, then explicit web deploy/hash/fingerprint/Playwright.

Rollback P3: cutovers remain inactive; deploy P2; retain scale-4 data.

### Push 4 — authorization, deactivation, identification and lot counting; flags false

Generate DTOs/permission map before commit. Commit exact T4–T7 files. After deploy, seed each captured tenant individually:

```bash
while IFS=$'\t' read -r tenant_id; do
  ssh "$STAGING_SSH" \
    "cd '$STAGING_COMPOSE_DIR' &&
     docker compose -f docker-compose.staging.yml exec -T api \
     env DB_HOST=\${DB_DIRECT_HOST:-postgres} php artisan tenants:seed \
       --force --tenants='$tenant_id' \
       --class='Database\\Seeders\\RolesAndPermissionsSeeder'" \
    | tee "$EVIDENCE_DIR/permission-$tenant_id.log"
  grep -F "Tenant: $tenant_id" "$EVIDENCE_DIR/permission-$tenant_id.log"
done < <(cut -f1 "$EVIDENCE_DIR/p2-tenants.tsv")
```

Capture and verify one success per tenant, reset permission cache, run route-role matrix and PG count/identity suite.

Create an all-false immutable revision for P4; capture revision/fingerprint JSON. Generate prepare UUIDv7 with:

```bash
ssh "$STAGING_SSH" "... docker compose ... exec -T api php -r \
'require \"vendor/autoload.php\"; echo json_encode([\"operation_id\"=>(string)Illuminate\\Support\\Str::uuid7()]);'"
```

Capture the ID before calling cutover prepare. Persist all tenant/company/cutover IDs and require they match the captured tenant manifest. Do not activate.

Explicit web deploy/hash/fingerprint/Playwright.

Rollback P4: deploy P3; retain prepared cutovers and all audit/evidence schema.

### Push 5 — provenance, census, CI and six-flag activation

Run full repository gates, regenerate types/map, then:

```bash
git add -- \
  apps/api \
  apps/web \
  packages/shared/types/generated.d.ts \
  apps/web/src/hooks/permissionsMap.generated.ts \
  .github/workflows/ci.yml \
  docker-compose.staging.yml

git commit -m "W-LOT-A P5: activate server lot core"
P5_SHA="$(git rev-parse HEAD)"
jq -n --arg p5 "$P5_SHA" '{p5_sha:$p5}' > "$EVIDENCE_DIR/p5.json"
git push origin "$P5_SHA:refs/heads/dev"
```

Compose changes all six variables to true; there is no recall-hold variable. Remotely force-recreate API/worker/scheduler/websocket and verify identical SHA/flag fingerprint.

Provenance:

1. Run non-persistent `--dry-run`; assert no run ID and no row-count change.
2. Capture a fresh UUIDv7 from command output.
3. Run `--execute --operation=<captured>`.
4. Persist run/result IDs; require zero captured rows and zero failed runs.

Census:

1. Capture a fresh UUIDv7.
2. Run persistent fleet census with all three fail flags.
3. Persist run and every company result ID.

Activation:

1. Create all-true immutable revision for P5 and capture revision/fingerprint.
2. Capture fresh prepare UUIDv7; prepare all company cutovers using only tenant IDs from P2 evidence.
3. Run preflight against P5 SHA, revision, fingerprint, migration/permission/census manifests.
4. Capture fresh activation UUIDv7.
5. Activate the exact prepared manifest.
6. Require every cutover active, zero failures, no unresolved entitlement and no drift.
7. Explicit web deploy, exact title/description deployment correlation, build SHA, asset SHA, feature fingerprint and Playwright smoke.
8. Query health and require active revision/census/build SHA match captured IDs.

Rollback P5:

1. Capture rollback UUIDv7.
2. Roll back active cutovers before old code.
3. Require all cutovers report rolled_back.
4. Deploy P4 SHA with six false variables.
5. Force-recreate all Laravel services.
6. Explicitly redeploy P4 web artifact and verify SHA/asset/fingerprint/Playwright.
7. Retain migrations, deactivation/correction/identification/count/provenance/census history.
8. Never auto-repair stock, rebuild DEFAULT or delete evidence.

## Dispatch order

1. T1 — rollout/environment/glossary/fingerprint.
2. P2 schema migration after T1 is live and fail-hard.
3. T2 — entitlement fence.
4. T3 — exact quantity, canonical mutation, every writer and eligibility.
5. T4 — authorization/deactivation; no recall lifecycle.
6. T5 — identification, then used-lot freeze/correction.
7. T6 — numeric movement sequence and observations.
8. T7 — reconciliation and late-sync finality.
9. T8 — retained provenance and backfill.
10. T9 — durable census.
11. T10 — CI/release gate.
12. Five-push staging promotion.
13. WITHHELD-Q10 remains undispatchable until its named activation condition is satisfied.

## Final verification checklist

- [ ] Reviewed/source SHA is `4878c3e6add3f2815522e6f1cb553ebcd81b3e66`.
- [ ] Ten dispatched tasks; every packet has exact files/signatures/tests/commands/lanes/reviewer/rollback.
- [ ] Q10–Q13 rows remain verbatim and OPEN.
- [ ] No release/reject field, state, route, permission, UI, test, flag or push exists.
- [ ] Dormant hold enum/table contains only requested/recalled and defines no lifecycle edge.
- [ ] WITHHELD-Q10 has not been dispatched.
- [ ] Q11–Q13 appear nowhere in schema/state/push/task code.
- [ ] Viewer and operator receive `batches.view`; route-role matrix is green on fresh/existing tenants.
- [ ] Manager lacks global recall; general manager’s exact delta is recall plus all-location treasury.
- [ ] Positive/reserved stock blocks deactivation; retry/audit evidence is durable.
- [ ] Historical POS allocation column is proven `DECIMAL(15,4)` on every tenant.
- [ ] No lot quantity float accessor, mutator, cast or JS-number arithmetic remains.
- [ ] Batch, transfer and count domain frontend types come from generated DTOs.
- [ ] `typescript:transform` runs in the same task/push as each DTO consumer change.
- [ ] Entitlement values are exactly entitled/not_entitled/entitlement_unresolved.
- [ ] Central entitlement lock remains held through tenant commit; both race directions pass.
- [ ] Every writer in `P5-LOCK` is classified and assigned.
- [ ] POS FEFO, reservations and StockTransferService use the shared eligibility predicate.
- [ ] FEFO no longer uses `SKIP LOCKED` after product serialization.
- [ ] Inventory GL reaches `InventoryGlPostingService` only through `InventoryGlPostingBuffer`.
- [ ] Identification precedes used-lot freeze and conserves aggregate/WAC/GL.
- [ ] No UUID ordering remains in count replay.
- [ ] Every stock movement has a positive company sequence.
- [ ] Explicit zero differs from missing observation.
- [ ] Reattribution emits one flat aggregate movement, offsetting lot effects and zero journals.
- [ ] Late pre-count arrivals reopen final reconciliation without reapplying effects.
- [ ] Provenance covers all three producer families and survives parent deletion.
- [ ] Dry-run persists no backfill run; resume links a fresh execution to a failed run.
- [ ] W-LOT-A creates zero `operator_captured` rows.
- [ ] Census cohort is entitled company × tracked product.
- [ ] `not_entitled` is non-alerting; unresolved/drift/stale are alerting.
- [ ] Scheduler is 03:20, foreground and `withoutOverlapping(180)`.
- [ ] Every applicable task’s second-company, second-location and rerun tests pass.
- [ ] W-LOT-A PG classes run unconditionally by path in `backend-test-pgsql`.
- [ ] `pnpm build`, lint, typecheck, Vitest, composer test, PHPStan, Pint and preflight pass.
- [ ] P2 runs `tenants:migrate-rolling --force` twice and captures every tenant result.
- [ ] Permission seeding is individually verified for every captured tenant.
- [ ] All rollout variables are forwarded by compose and validated in every entrypoint.
- [ ] Every revision, operation, run, cutover, tenant and deployment ID is captured from output before use.
- [ ] Every web-changing push has explicit Dokploy deployment, exact request correlation, build SHA, asset SHA, feature fingerprint and Playwright smoke.
- [ ] Every push has a tested rollback point retaining additive history and performing no automatic stock repair.