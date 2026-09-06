<!-- W-LOT-A rev 4 (split from W-LOT rev 3 at 3f32ffdd8), authored by Codex CLI (gpt-5.6-sol, high, read-only) on 2026-09-06; filed verbatim by the orchestrator. Status: awaiting plan gate r1 (A). Companion: W-LOT-B. -->
# W-LOT-A execution plan — server-side lot core (rev 4, split from W-LOT rev 3)

## Change log and gate disposition

All `PL-*` references below are stable normative plan-line IDs. Local `path:line` citations refer to reviewed HEAD `7e14006182a1d7e281bbdc4660af85a80a2ee9fd`.

| Gate finding | Disposition | Closure / rejection |
|---|---|---|
| R1-1 mechanical dispatch gate | CLOSED | Exact files, signatures, red assertions, commands, lanes, reviewer invocations and rollback are in `PL-T1`–`PL-T10`. |
| R1-2 recall escalation and roles | CLOSED | Policy-neutral request hold, company recall, local sale/transfer blocking and exact role deltas are in `PL-T4`. |
| R1-3 POS core versus lot obligation | REJECTED | Device evidence, server obligation/outbox and captured-lot projection are W-LOT-B interfaces, excluded here; spec separates iteration 2 at `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:384-386,404`. |
| R1-4 lock census | CLOSED | Complete writers, entitlement mutation, recall and GL ownership are in `PL-LOCK`. |
| R1-5 deployment order | CLOSED | Literal five-push manifest is in `PL-DEPLOY`. |
| R1-6 freeze before replacement | CLOSED | Identification ships before used-lot freeze activation in `PL-T5` and Push 4. |
| R1-7 float retirement | CLOSED | API, resource, reservation, transfer, document, POS-server and web consumers are enumerated in `PL-T3`. |
| R1-8 L9 correction/identification | CLOSED | Schema, services, UI and dedicated red tests are in `PL-S3` and `PL-T5`. |
| R1-9 flat-row count model | CLOSED | Parent quantity plus explicit per-lot observations and zero-versus-missing semantics are in `PL-S4`, `PL-T6`, `PL-T7`. |
| R1-10 provenance and health | CLOSED | Three producer families, readers, resumable backfill and durable health are in `PL-T8`, `PL-T9`. |
| R1-11 refresh before POS open | REJECTED | All `apps/pos` device behavior belongs to W-LOT-B. |
| R1-12 durable outbox | REJECTED | Evidence/outbox work belongs to W-LOT-B. |
| R1-13 L8/L9 completion | CLOSED for W-LOT-A | L9 is complete here; L8 is explicitly not claimed and remains W-LOT-B. |
| R1-14 citation hygiene | CLOSED | Fresh HEAD is declared in `PL-BASE`; oversell is cited at spec line 376. |
| R1-15 task size | CLOSED | Ten tasks, each independently dispatchable. |
| R2-B1 latest owner register | CLOSED | Q10–Q13 are copied verbatim at `PL-OPEN`; no release/reject branch is encoded. |
| R2-B2 aggregate movement link | REJECTED | Captured-lot obligations are W-LOT-B. W-LOT-A’s count and identification effects reconcile only against signed `quantity_after - quantity_before`, `PL-T5`/`PL-T7`. |
| R2-B3 canonical line key | REJECTED | Device evidence identity belongs to W-LOT-B. |
| R2-B4 writer/lock census | CLOSED | `PL-LOCK`. |
| R2-B5 deployment preflight | CLOSED | `PL-DEPLOY`; automatic migration ownership is reconciled with the explicit rolling migration. |
| R2-B6 dispatch packets | CLOSED | `PL-T1`–`PL-T10`. |
| R2-M1 L9 permission scope | CLOSED | Correction and identification remain admin-only in `PL-T4`/`PL-T5`. |
| R2-M2 reservation consumers | CLOSED | Exact reservation consumers and float retirement are in `PL-T3`. |
| R2-M3 provenance producer seams | CLOSED | Exact producers and readers are in `PL-T8`. |
| R2-M4 POS renderer census | REJECTED | `NearExpirySlot`, `ProductDetailDrawer` and all device/cart renderers belong to W-LOT-B. |
| R2-M5 health schedule | CLOSED | 03:20, 180-minute overlap and stale recovery are in `PL-T9`. |
| R2-M6 vocabulary ambiguity | CLOSED | Complete glossary contract is in `PL-VOCAB`. |
| R2-m1 reproducible baseline | CLOSED | `PL-BASE` records fresh HEAD `7e14006182a1d7e281bbdc4660af85a80a2ee9fd`. |
| Gate-r3 multi-lot cardinality | REJECTED | W-LOT-B obligation/effect model. |
| Gate-r3 recall authority/bypass | CLOSED | `PL-T4`. |
| Gate-r3 recall retry | CLOSED | Company-scoped operation UUID/fingerprint constraints in `PL-S2`. |
| Gate-r3 receipt evidence atomicity | REJECTED | W-LOT-B. |
| Gate-r3 company entitlement boundary | CLOSED | Real central advisory lock across tenant commit in `PL-T2`. |
| Gate-r3 five-push manifest | CLOSED | `PL-DEPLOY`. |
| Gate-r3 dispatch and CI | CLOSED | `PL-T10` adds an unconditional live PostgreSQL command to `backend-test-pgsql`. |
| Gate-r3 GL ownership/order inversion | CLOSED | `PL-LOCK`. |
| Gate-r3 float surfaces | CLOSED | `PL-T3`. |
| Gate-r3 count as-of marker | CLOSED | Monotonic numeric sequence and ordered replay in `PL-S4`, `PL-T6`. |
| Gate-r3 used-lot freeze | CLOSED | `PL-T5`. |
| Gate-r3 L1 authorization census | CLOSED | Exact API/web permission matrix in `PL-T4`. |
| Gate-r3 generated enums/types | CLOSED | Spatie DTO generation and permission-map regeneration in `PL-T10`. |
| Gate-r3 stale HEAD | CLOSED | `PL-BASE`. |
| NEW-B1 Q10 encoded | CLOSED | Only the shared positive path `requested → recalled` is implemented. Release/reject, their fields, evidence and permissions are withheld behind Q10; `PL-OPEN`, `PL-S2`, `PL-T4`. |
| NEW-B2 signed reconciliation | REJECTED for W-LOT-A obligation work | No obligation schema is created. W-LOT-A effects use `quantity_after - quantity_before`, matching HEAD’s stated authority at `apps/api/app/Modules/Inventory/Domain/StockMovement.php:174-212`. |
| NEW-B3 entitlement fence non-transactional | CLOSED | The central PostgreSQL transaction-scoped advisory lock remains held until the tenant transaction commits; both mutation race directions are pinned in `PL-T2`. |
| NEW-B4 branch hold does not block sale/transfer | CLOSED | One eligibility predicate is used by FEFO, reservations, POS server projection and `StockTransferService`; request/escalation share its product lock, `PL-T3`, `PL-T4`, `PL-LOCK`. |
| NEW-B5 unordered watermark | CLOSED | UUID ordering is retired in favor of `movement_sequence BIGINT`; clock envelope is explicit, `PL-S4`, `PL-T6`. |
| NEW-B6 evidence delivery order | REJECTED | W-LOT-B. |
| NEW-B7 staging manifest | CLOSED | No container `rg`, no nonexistent flag file, all generated IDs captured, atomic flag activation, forced recreation, Dokploy polling and deterministic fingerprint, `PL-DEPLOY`. |
| NEW-B8 Convention 10 | CLOSED | Matrix immediately follows the summary in the exact requested column format. |
| Major: deactivation disposition | CLOSED | Positive or reserved stock blocks deactivation; explicit transfer/write-off must occur first, `PL-T4`. |
| Major: GM role exceeded ruling | CLOSED | GM delta is exactly `batches.recall` and `treasury.manage_all_locations`; `batches.health` is admin-only, `PL-T4`. |
| Major: Convention 09 per task | CLOSED | Every applicable task carries exact second-company, second-location and rerun cases. |
| Major: Convention 11 glossary | CLOSED | `PL-VOCAB`. |
| Major: entitlement vocabulary | CLOSED | Exact values are `entitled`, `not_entitled`, `entitlement_unresolved`, `PL-T2`. |
| Major: delegated schema checks | CLOSED | `PL-S1`–`PL-S6` state every new column, FK action, check, unique and index. |
| Major: undispatchable tasks | CLOSED | `PL-T1`–`PL-T10`. |
| Minor: wrong oversell citation | CLOSED | `PL-BENCH` cites `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:376`. |
| Minor: stale HEAD label | CLOSED | `PL-BASE`. |
| R4 rejected false positives | REJECTED as findings | `tenants:migrate-rolling --force`, compose inheritance, 03:20/180 minutes and additive rollback are retained, consistent with `docs/superpowers/reviews/2026-09-06-w-lot-plan-codex-gate-r4.md:169-182`. |

<a id="pl-base"></a>
## Summary and reviewed base — `PL-BASE`

This plan delivers only the server-side lot core: permissions and a policy-neutral recall hold, used-lot correction, exact decimal quantities, DEFAULT identification, lot-grain counting, provenance on the three existing producer families, a durable drift census, worker-safe entitlement fencing, and the complete stock/GL lock contract.

Reviewed repository:

```text
Repository: /Users/houssamr/Projects/syneriva/apps/erp
Branch: local dev
HEAD: 7e14006182a1d7e281bbdc4660af85a80a2ee9fd
```

The working tree contained two unrelated untracked documentation files; they are not inputs or deliverables. No repository file or Git reference was changed while preparing this plan.

Authoritative inputs at this SHA:

- TDD, decimal, generated-type, module and deployment rules: `CLAUDE.md:18-49,71-105,149`.
- Convention 09: `docs/conventions/09-SECOND-OF-EVERYTHING.md:30-50,79-89`.
- Convention 10: `docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37-69,73-79`.
- Convention 11: `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:30-66`.
- W-LOT v4: `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:274-406`.
- Q4/Q5 and Q10–Q13: `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:105-114,136-145`.
- Gate r4: `docs/superpowers/reviews/2026-09-06-w-lot-plan-codex-gate-r4.md:18-214`.

<a id="pl-bench"></a>
## Industry baseline (benchmark-first — convention 10) — `PL-BENCH`

Flow: server-side lot lifecycle, quantity custody, identification, counting and traceability. Reference systems: Odoo 19.0 official lot, FEFO, reassign and inventory-adjustment documentation; ERPNext v15 official Batch, Serial/Batch Bundle and Stock Reconciliation documentation; Dolibarr is marked NV where this repository contains no pinned version/source fixture. Sources: [Odoo lot management](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/product_management/product_tracking/lots.html), [Odoo FEFO](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/shipping_receiving/removal_strategies/fefo.html), [Odoo reassign lots](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/product_management/product_tracking/reassign.html), [Odoo inventory adjustments](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/warehouses_storage/inventory_management/count_products.html), [ERPNext Batch](https://docs.frappe.io/erpnext/batch), [ERPNext Serial and Batch Bundle](https://docs.frappe.io/erpnext/serial-and-batch-bundle), [ERPNext Stock Reconciliation](https://docs.frappe.io/erpnext/stock-reconciliation).

| ID | Guarantee | Odoo | ERPNext | Dolibarr/NV | AutoERP today path:line | Gap | Decision |
|---|---|---|---|---|---|---|---|
| G-CREATE | Lot creation is module-, company- and permission-scoped. | Inventory authority and company-owned lot. | Stock-role-controlled Batch. | NV — no pinned fixture. | `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12,22-24` | Module middleware exists; create lacks explicit permission and authoritative worker entitlement. | MATCH — T2/T4 |
| G-DUPLICATE | Lot identity is unique per company/product/variant while the same number remains legal in another company. | Lot carries Company. | Batch is item-specific. | NV. | `apps/api/database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php:27-31` | Existing partial uniques already express the correct scope. | ALREADY — cited migration |
| G-EDIT | A used lot cannot be silently renamed or have expiry rewritten. | Product/on-hand identity becomes constrained by stock moves. | Submitted stock history is corrected through stock transactions. | NV. | `apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:20-24` | Ordinary PATCH accepts identity fields after use. | MATCH — T5 |
| G-CANCEL | Deactivation never hides positive/reserved stock or deletes history. | Archival retains stock history. | Disabled Batch retains ledger history. | NV. | `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:138-142` | Current delete only flips `is_active`. | MATCH — T4 |
| G-RERUN | Recall, correction, identification, count application, backfill and cutover retries are exact-idempotent. | Stock documents have stable transaction identity. | Stock transactions are document-keyed. | NV. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193-210` | Direct recall lacks operation identity/fingerprint. | MATCH — T1/T4–T9 |
| G-COMPANY | HTTP and workers reject a lot belonging to another company. | Company record rules. | Company permissions. | NV. | `apps/api/app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php:50-75` | Resolver ignores the supplied company. | MATCH — T2 |
| G-LOCATION | Branch holds and custody operations apply to the selected location, never the default location. | Location/warehouse record rules. | Warehouse User Permissions. | NV. | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:215-254` | Expiring and expired scope differ; no local hold. | MATCH — T4 |
| G-PERMISSION | Read, trace, create, update, deactivate, request, recall, identify, correct, count and health surfaces have explicit permission gates. | Role/group authorization. | Role Permission Manager. | NV. | `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:14-48` | Most routes lack route-level permissions. | MATCH — T4 |
| G-AUDIT | Lifecycle and identity changes retain actor, reason, operation identity, before/after and time. | Lot/quality history. | Stock ledger and document history. | NV. | `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134-141` | Recall mutates a boolean without an append-only transition. | MATCH — T4/T5 |
| G-EXACT | Lot availability stays a scale-4 decimal string through domain, API and UI. | Decimal stock quantities. | Decimal stock quantities. | NV. | `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchStock.php:44-72`; `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:39-60` | Float accessors and writers remain. | MATCH — T3 |
| G-FEFO-HOLD | A requested branch hold blocks sale and transfer at that branch before selection or reservation. | Removal eligibility is evaluated before stock move. | Batch eligibility is evaluated before stock transaction. | NV. | `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:262-273`; `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:993-1035` | Eligibility checks only active/global recall/expiry. | MATCH — T3/T4 |
| G-IDENTIFY | Previously unidentified stock is split into real lots without changing aggregate stock, WAC or GL. | Inventory adjustment assigns lots to existing stock. | Stock Reconciliation assigns batches. | NV. | `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:81-151` | DEFAULT creation is automatic; there is no controlled identification document. | MATCH — T5 |
| G-COUNT | Physical count records explicit lot observations and an ordered as-of marker. | Counts select a lot/serial number. | Reconciliation selects specific batches. | NV. | `apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php:274-347` | Aggregate-only count uses lexicographically ordered UUIDs. | MATCH — T6/T7 |
| G-PROVENANCE | FEFO estimates remain visibly estimates and never become captured evidence. | Suggested/picked lot remains an operational allocation. | Batch bundle links actual stock transaction. | NV. | `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2052-2112`; `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteFromDocumentFactory.php:120-154`; `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:155-161` | Three producers carry no provenance label. | MATCH — T8 |
| G-HEALTH | Drift detection is durable, company-aware, read-only and visibly stale when missed. | Inventory reporting exposes count/stock mismatch. | Stock reconciliation exposes mismatch. | NV. | `apps/api/app/Modules/BatchExpiry/Application/Services/LotLedgerDriftCensus.php:67-126` | Ad-hoc command only; cohort filters only product flag. | MATCH — T9 |
| G-OVERSELL | Offline/global oversell policy is not invented in this lane. | Configuration-dependent. | Configuration-dependent. | NV. | `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:376` | Separate owner decision remains required. | DEFER — ticket W-LOT-OVERSOLD |
| G-Q10 | Release/reject is absent until the owner rules authority and evidence. | Quality release varies by configuration. | Quality disposition is authority-specific. | NV. | `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:140` | OPEN owner question. | DEFER — OWNER-Q10 |

Second-of-everything (Convention 09): T2–T8 each carry exact second-company, second-location and rerun tests. T1, T9 and T10 are infrastructure/detector/release tasks and introduce no operator-edited catalogue entity; their task packets state why Convention 09 is not applicable.

<a id="pl-vocab"></a>
## Vocabulary and one-surface contract — `PL-VOCAB`

Concepts: Lot (glossary ✅), Lot evidence (glossary ✅), Lot identification (glossary ✅), Branch lot hold (NEW), Company recall (NEW), Lot identity correction (NEW), Lot count observation (NEW), Lot count reconciliation (NEW), Company entitlement decision (NEW), Census run (NEW), Rollout revision (NEW), Company cutover (NEW).

Task 1 updates `docs/glossary.md` with these exact rows:

| Concept | Canonical table/owner | Primary writer | Canonical operator surface | Permitted synonyms |
|---|---|---|---|---|
| Branch lot hold | `batch_recall_requests` / BatchExpiry | `BatchRecallService::request()` | Batch detail Recall panel | recall request, local hold |
| Company recall | `product_batches` plus `batch_recall_transitions` / BatchExpiry | `BatchRecallService::recall()` | Same Recall panel | global recall, recalled lot |
| Lot identity correction | `batch_identity_corrections` / BatchExpiry | `BatchIdentityCorrectionService::correct()` | Batch detail Correct identity dialog | controlled correction |
| Lot count observation | `lot_count_observations` / Inventory | `LotCountObservationService::record()` | Existing inventory counting detail | batch count, lot observation |
| Lot count reconciliation | `lot_count_reconciliations` / Inventory | `LotCountReconciliationService::apply()` | Existing counting review | lot-grain count application |
| Company entitlement decision | central tenant revision plus tenant company ownership | `CompanyModuleEntitlementFence` | Admin health output; no independent company toggle | worker entitlement |
| Census run | central `lot_ledger_census_runs` and result table | `LotLedgerDriftCensusCommand` | Batch health panel | drift monitor |
| Rollout revision | central `w_lot_a_rollout_revisions` | rollout-revision command | Release evidence only | feature revision |
| Company cutover | tenant `w_lot_a_company_cutovers` | cutover command | Release evidence only | rollout activation |

There is one batch list/detail surface, one inventory-count surface and one transfer detail. No second lot dashboard, shadow TypeScript `Batch` interface or alternate writer is introduced. Generated DTOs from `packages/shared/types/generated.d.ts` replace `apps/web/src/features/batches/types.ts` and relevant hand-written portions of `apps/web/src/features/stock-transfers/types/index.ts`, per Convention 11 (`docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:37-47`).

<a id="pl-open"></a>
## OPEN owner register — Q10–Q13 verbatim — `PL-OPEN`

All four rows remain **OPEN**. The following is copied verbatim from `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:138-143`.

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

Normative policy-neutral rule:

- W-LOT-A implements only `requested → recalled`.
- A `requested` row means an immediate local sale-and-transfer hold at its location.
- A `recalled` transition means the existing company-wide `product_batches.is_recalled=true`.
- There is no `released`, `rejected`, `release_reason`, `rejected_by`, release permission, release route, release UI, rollout flag or negative-disposition task.
- Q10’s future outcome is an additive migration and separate task after a ruling. Q11–Q13 have no W-LOT-A schema, state, flag, push or task.

## Scope boundary

Included:

- L1 permissions, `general_manager`, recall request/local hold and company recall.
- L2 used-lot freeze and audited correction.
- L3 removal of floats from all server/web lot quantity surfaces.
- L9 DEFAULT identification/split.
- L4 lot-grain count observations, reattribution and ordered as-of watermark.
- L5 provenance on POS receipt allocation, `document_lines.batch_id`, and transfer allocation producers.
- L6 durable scheduled drift census.
- Worker entitlement tri-state with transactional revision fence.
- Complete inventory writer/lock/GL census.

Interfaces left open for W-LOT-B:

- No `apps/pos` device changes.
- No lot evidence record/outbox or server evidence inbox.
- No `NearExpirySlot` or `ProductDetailDrawer`.
- No projection consumption of captured lots.
- No child obligation for late or failed evidence.
- `LotProvenance::OperatorCaptured` is reserved but W-LOT-A never produces it.
- Existing server receipt allocations remain `system_fefo_estimate`; absence remains `unknown`.
- A later obligation/effect model must reconcile signed effects against `stock_movements.quantity_after - quantity_before`, never the inconsistent magnitude/sign of `stock_movements.quantity`.

## Complete migration schema contract

All UUIDs are application-generated UUIDv7. All company-owned unique keys include `tenant_id, company_id`; UUID primary keys remain exempt as surrogate identities. Audit/history FKs are `ON DELETE RESTRICT`. Every new timestamp is `TIMESTAMPTZ`. PostgreSQL and SQLite migrations expose identical logical nullability/checks; PostgreSQL additionally installs partial indexes and deferred invariant triggers.

<a id="pl-s1"></a>
### S1 — rollout, revision and cutover schema — `PL-S1`, owned by T1

Central migration: `apps/api/database/migrations/2026_09_06_100000_create_w_lot_a_rollout_revisions.php`.

Alter `tenants`:

- `module_entitlement_revision BIGINT NOT NULL DEFAULT 0`.
- Check `module_entitlement_revision >= 0`.
- Index `tenants_module_entitlement_revision_idx(module_entitlement_revision)`.

Create `w_lot_a_rollout_revisions`:

| Column | Contract |
|---|---|
| `id` | UUID PK, no database default |
| `artifact_sha` | CHAR(40) NOT NULL |
| `flags` | JSONB NOT NULL |
| `flags_fingerprint` | CHAR(64) NOT NULL |
| `created_by` | VARCHAR(255) NOT NULL |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints/indexes:

- Unique `(artifact_sha, flags_fingerprint)`.
- Check lowercase `artifact_sha ~ '^[0-9a-f]{40}$'`.
- Check lowercase `flags_fingerprint ~ '^[0-9a-f]{64}$'`.
- Check `jsonb_typeof(flags)='object'`.
- Check exact key set: `wlot_a_entitlement_fence`, `wlot_a_recall_holds`, `wlot_a_exact_quantities`, `wlot_a_identity_controls`, `wlot_a_lot_counts`, `wlot_a_provenance`, `wlot_a_drift_scheduler`.
- Check each value is JSON boolean.
- Immutable `BEFORE UPDATE OR DELETE` trigger.
- JSONB owner DTO: `apps/api/app/Modules/BatchExpiry/Application/DTOs/WLotARolloutFlagsData.php`.

Tenant migration: `apps/api/database/migrations/tenant/2026_09_06_100000_create_w_lot_a_company_cutovers.php`.

Create `w_lot_a_company_cutovers`:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `tenant_id` | UUID NOT NULL; directory identity, no cross-database FK |
| `company_id` | UUID NOT NULL, FK `companies.id` RESTRICT |
| `revision_id` | UUID NOT NULL; central revision identity, no cross-database FK |
| `artifact_sha` | CHAR(40) NOT NULL |
| `flags_fingerprint` | CHAR(64) NOT NULL |
| `entitlement_revision` | BIGINT NOT NULL |
| `state` | VARCHAR(16) NOT NULL DEFAULT `prepared` |
| `prepared_operation_uuid` | UUID NOT NULL |
| `prepared_by` | VARCHAR(255) NOT NULL |
| `prepared_at` | TIMESTAMPTZ NOT NULL |
| `activated_operation_uuid` | UUID NULL |
| `activated_by` | VARCHAR(255) NULL |
| `activated_at` | TIMESTAMPTZ NULL |
| `rolled_back_operation_uuid` | UUID NULL |
| `rolled_back_by` | VARCHAR(255) NULL |
| `rolled_back_at` | TIMESTAMPTZ NULL |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints/indexes:

- Unique `(tenant_id, company_id, revision_id)`.
- Unique `(tenant_id, company_id, prepared_operation_uuid)`.
- Partial unique indexes for non-null activation and rollback operation UUIDs, each scoped by tenant/company.
- Checks for SHA/fingerprint hex and `entitlement_revision >= 0`.
- State check: `prepared|active|rolled_back`.
- `prepared`: activation/rollback fields all null.
- `active`: all activation fields non-null; rollback fields null; `activated_at >= prepared_at`.
- `rolled_back`: activation and rollback fields non-null; `rolled_back_at >= activated_at >= prepared_at`.
- State transition trigger permits only `prepared→active→rolled_back`; exact retry is no-op, conflicting retry fails.

<a id="pl-s2"></a>
### S2 — policy-neutral recall schema — `PL-S2`, owned by T4

Migration: `apps/api/database/migrations/tenant/2026_09_06_110000_create_batch_recall_requests.php`.

Create `batch_recall_requests`:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `tenant_id` | UUID NOT NULL |
| `company_id` | UUID NOT NULL, FK `companies.id` RESTRICT |
| `batch_id` | BIGINT NOT NULL, FK `product_batches.id` RESTRICT |
| `location_id` | UUID NOT NULL, FK `locations.id` RESTRICT |
| `status` | VARCHAR(16) NOT NULL DEFAULT `requested` |
| `operation_uuid` | UUID NOT NULL |
| `operation_fingerprint` | CHAR(64) NOT NULL |
| `reason` | TEXT NOT NULL |
| `requested_by` | UUID NOT NULL, FK `users.id` RESTRICT |
| `requested_at` | TIMESTAMPTZ NOT NULL |
| `recalled_by` | UUID NULL, FK `users.id` RESTRICT |
| `recalled_at` | TIMESTAMPTZ NULL |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints/indexes:

- Unique `(tenant_id, company_id, operation_uuid)`.
- Partial unique `(tenant_id, company_id, batch_id, location_id) WHERE status='requested'`.
- Index `(tenant_id, company_id, location_id, status, requested_at)`.
- Index `(tenant_id, company_id, batch_id, status)`.
- Check status is only `requested|recalled`.
- Check fingerprint is lowercase 64-hex and trimmed reason is nonempty.
- `requested` requires recall actor/time null.
- `recalled` requires recall actor/time non-null and `recalled_at >= requested_at`.
- Trigger rejects company/product/location mismatches against the referenced batch and location.

Create `batch_recall_transitions`:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `tenant_id` | UUID NOT NULL |
| `company_id` | UUID NOT NULL, FK `companies.id` RESTRICT |
| `recall_request_id` | UUID NOT NULL, FK `batch_recall_requests.id` RESTRICT |
| `from_status` | VARCHAR(16) NOT NULL |
| `to_status` | VARCHAR(16) NOT NULL |
| `operation_uuid` | UUID NOT NULL |
| `operation_fingerprint` | CHAR(64) NOT NULL |
| `reason` | TEXT NOT NULL |
| `acted_by` | UUID NOT NULL, FK `users.id` RESTRICT |
| `acted_at` | TIMESTAMPTZ NOT NULL |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints/indexes:

- Unique `(tenant_id, company_id, operation_uuid)`.
- Unique `(tenant_id, company_id, recall_request_id, to_status)`.
- Check exact edge `from_status='requested' AND to_status='recalled'`.
- Fingerprint/reason checks as above.
- Immutable update/delete trigger.

<a id="pl-s3"></a>
### S3 — correction and identification schema — `PL-S3`, owned by T5

Migration: `apps/api/database/migrations/tenant/2026_09_06_120000_add_batch_identity_corrections.php`.

Alter `product_batches`:

- `identity_version BIGINT NOT NULL DEFAULT 0 CHECK identity_version >= 0`.
- Index `(tenant_id, company_id, id, identity_version)`.

Create `batch_identity_corrections`:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `tenant_id`, `company_id` | UUID NOT NULL; company FK RESTRICT |
| `batch_id` | BIGINT NOT NULL, batch FK RESTRICT |
| `operation_uuid` | UUID NOT NULL |
| `operation_fingerprint` | CHAR(64) NOT NULL |
| `expected_identity_version` | BIGINT NOT NULL |
| `resulting_identity_version` | BIGINT NOT NULL |
| `old_batch_number`, `new_batch_number` | VARCHAR(255) NOT NULL |
| `old_manufacturing_date`, `new_manufacturing_date` | DATE NULL |
| `old_expiry_date`, `new_expiry_date` | DATE NULL |
| `reason`, `evidence_reference` | TEXT NOT NULL |
| `corrected_by` | UUID NOT NULL, user FK RESTRICT |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints/indexes:

- Unique `(tenant_id, company_id, operation_uuid)`.
- Unique `(tenant_id, company_id, batch_id, resulting_identity_version)`.
- Check `resulting_identity_version = expected_identity_version + 1`.
- Check at least one old/new pair differs using `IS DISTINCT FROM`.
- Trimmed number/reason/evidence nonempty.
- New expiry must be on/after new manufacturing date when both exist.
- Immutable trigger.

Migration: `apps/api/database/migrations/tenant/2026_09_06_121000_create_lot_identifications.php`.

Create `lot_identifications`:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `tenant_id`, `company_id` | UUID NOT NULL; company FK RESTRICT |
| `source_batch_id` | BIGINT NOT NULL, batch FK RESTRICT |
| `product_id` | UUID NOT NULL, product FK RESTRICT |
| `variant_id` | UUID NULL, variant FK RESTRICT |
| `location_id` | UUID NOT NULL, location FK RESTRICT |
| `operation_uuid` | UUID NOT NULL |
| `operation_fingerprint` | CHAR(64) NOT NULL |
| `quantity` | DECIMAL(20,4) NOT NULL |
| `source_quantity_before`, `source_quantity_after` | DECIMAL(20,4) NOT NULL |
| `aggregate_quantity_before`, `aggregate_quantity_after` | DECIMAL(20,4) NOT NULL |
| `wac_before`, `wac_after` | DECIMAL(20,6) NOT NULL |
| `stock_movement_id` | UUID NOT NULL, stock movement FK RESTRICT |
| `reason`, `evidence_reference` | TEXT NOT NULL |
| `created_by` | UUID NOT NULL, user FK RESTRICT |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints/indexes:

- Unique `(tenant_id, company_id, operation_uuid)`.
- Check `quantity > 0`; all before/after quantities nonnegative.
- Check aggregate before equals after and WAC before equals after.
- Check source delta equals `-quantity`.
- Check nonempty reason/evidence/fingerprint.
- Deferred trigger verifies source batch is `DEFAULT`, same company/product/variant, reservation is zero, aggregate movement is flat and all effect quantities sum to zero.

Create `lot_identification_effects`:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `tenant_id`, `company_id` | UUID NOT NULL; company FK RESTRICT |
| `identification_id` | UUID NOT NULL, identification FK RESTRICT |
| `ordinal` | INTEGER NOT NULL |
| `effect_kind` | VARCHAR(16) NOT NULL |
| `batch_id` | BIGINT NOT NULL, batch FK RESTRICT |
| `signed_quantity` | DECIMAL(20,4) NOT NULL |
| `quantity_before`, `quantity_after` | DECIMAL(20,4) NOT NULL |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints/indexes:

- Unique `(tenant_id, company_id, identification_id, ordinal)`.
- Unique `(tenant_id, company_id, identification_id, batch_id)`.
- Partial unique one `source_debit` per identification.
- Check `effect_kind=source_debit|target_credit`.
- Source: ordinal zero and signed quantity negative.
- Target: ordinal positive and signed quantity positive.
- Check `quantity_after = quantity_before + signed_quantity` and both quantities nonnegative.
- Deferred sum check equals zero.
- Immutable trigger.

<a id="pl-s4"></a>
### S4 — ordered watermark and lot count schema — `PL-S4`, owned by T6/T7

Migration: `apps/api/database/migrations/tenant/2026_09_06_130000_add_stock_movement_sequence.php`.

Create `stock_movement_sequence_counters`:

| Column | Contract |
|---|---|
| `tenant_id` | UUID NOT NULL |
| `company_id` | UUID NOT NULL, company FK RESTRICT |
| `last_value` | BIGINT NOT NULL DEFAULT 0 |
| `created_at`, `updated_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints:

- PK `(tenant_id, company_id)`.
- Check `last_value >= 0`.

Alter `stock_movements`:

- Add `movement_sequence BIGINT` nullable for backfill.
- Backfill per tenant/company with `row_number()` ordered by `COALESCE(occurred_at,created_at), created_at, id`.
- Seed each counter with that company’s maximum.
- Set `movement_sequence NOT NULL CHECK movement_sequence > 0`.
- Unique `(tenant_id, company_id, movement_sequence)`.
- Index `(tenant_id, company_id, product_id, variant_id, location_id, occurred_at, movement_sequence)`.
- `StockMovement` creating hook calls `StockMovementSequenceAllocator`; raw inserts without a sequence fail.
- Sequence is immutable after insert.

Alter `inventory_counting_items`:

- Add nullable `count_1_movement_sequence`, `count_2_movement_sequence`, `count_3_movement_sequence`, `final_qty_movement_sequence`, all BIGINT with `>=0` checks.
- Retain legacy UUID marker columns for backward-readable history; new code no longer writes or orders by them.

Migration: `apps/api/database/migrations/tenant/2026_09_06_131000_create_lot_count_observations.php`.

Create `lot_count_observations`:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `tenant_id`, `company_id` | UUID NOT NULL; company FK RESTRICT |
| `counting_id` | UUID NOT NULL, counting FK RESTRICT |
| `counting_item_id` | UUID NOT NULL, counting-item FK RESTRICT |
| `count_number` | SMALLINT NOT NULL |
| `product_id` | UUID NOT NULL, product FK RESTRICT |
| `variant_id` | UUID NULL, variant FK RESTRICT |
| `location_id` | UUID NOT NULL, location FK RESTRICT |
| `batch_id` | BIGINT NOT NULL, batch FK RESTRICT |
| `observed_quantity` | DECIMAL(20,4) NOT NULL |
| `observed_at_device` | TIMESTAMPTZ NULL |
| `observed_at_estimate` | TIMESTAMPTZ NOT NULL |
| `received_at` | TIMESTAMPTZ NOT NULL |
| `movement_sequence` | BIGINT NOT NULL |
| `submitted_by` | UUID NOT NULL, user FK RESTRICT |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints/indexes:

- Unique `(tenant_id, company_id, counting_item_id, count_number, batch_id)`.
- Index `(tenant_id, company_id, counting_id, count_number)`.
- Check count number 1–3, observed quantity `>=0`, sequence `>=0`.
- Check `observed_at_estimate <= received_at + INTERVAL '5 minutes'`.
- Deferred ownership trigger validates counting, item, batch, product, variant and location share tenant/company/grain.
- Explicit zero is stored as a row. Missing batch is absence, never implicit zero.
- Immutable trigger.

Create `lot_count_reconciliations`:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `tenant_id`, `company_id` | UUID NOT NULL; company FK RESTRICT |
| `counting_id`, `counting_item_id` | UUID NOT NULL, FKs RESTRICT |
| `count_number` | SMALLINT NOT NULL |
| `operation_uuid` | UUID NOT NULL |
| `operation_fingerprint` | CHAR(64) NOT NULL |
| `mode` | VARCHAR(24) NOT NULL |
| `observed_parent_quantity` | DECIMAL(20,4) NOT NULL |
| `aggregate_quantity_before`, `aggregate_quantity_after`, `aggregate_delta` | DECIMAL(20,4) NOT NULL |
| `lot_total_before`, `lot_total_after` | DECIMAL(20,4) NOT NULL |
| `stock_movement_id` | UUID NULL, stock movement FK RESTRICT |
| `applied_by` | UUID NOT NULL, user FK RESTRICT |
| `applied_at` | TIMESTAMPTZ NOT NULL |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints/indexes:

- Unique `(tenant_id, company_id, counting_item_id, count_number)`.
- Unique `(tenant_id, company_id, operation_uuid)`.
- Mode `no_op|aggregate_adjustment|reattribution`.
- All stored quantities except delta nonnegative.
- `aggregate_after - aggregate_before = aggregate_delta`.
- `observed_parent_quantity = aggregate_quantity_after = lot_total_after`.
- `no_op`: delta zero, before/after and lot totals unchanged, movement null.
- `aggregate_adjustment`: delta nonzero, movement non-null, lot-total delta equals aggregate delta.
- `reattribution`: delta zero, aggregate before/after equal, lot totals equal, movement non-null.
- Deferred trigger checks linked stock movement’s signed delta equals `aggregate_delta`; reattribution’s movement is flat.
- Immutable trigger.

Create `lot_count_reconciliation_effects`:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `tenant_id`, `company_id` | UUID NOT NULL; company FK RESTRICT |
| `reconciliation_id` | UUID NOT NULL, reconciliation FK RESTRICT |
| `batch_id` | BIGINT NOT NULL, batch FK RESTRICT |
| `signed_quantity` | DECIMAL(20,4) NOT NULL |
| `quantity_before`, `quantity_after` | DECIMAL(20,4) NOT NULL |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints:

- Unique `(tenant_id, company_id, reconciliation_id, batch_id)`.
- Signed quantity nonzero.
- Before/after nonnegative and `after = before + signed_quantity`.
- Deferred sum equals parent `aggregate_delta`.
- Immutable trigger.

<a id="pl-s5"></a>
### S5 — provenance schema — `PL-S5`, owned by T8

Migration: `apps/api/database/migrations/tenant/2026_09_06_140000_add_lot_provenance.php`.

Add to each of `pos_receipt_line_batch_allocations`, `document_lines`, `stock_transfer_line_batch_allocations`:

- `lot_provenance VARCHAR(24) NOT NULL DEFAULT 'unknown'`.
- `provenance_recorded_at TIMESTAMPTZ NULL`.
- Check `lot_provenance IN ('operator_captured','system_fefo_estimate','unknown')`.
- Check `system_fefo_estimate|operator_captured` requires `provenance_recorded_at IS NOT NULL`.
- For `document_lines`, `batch_id IS NULL` requires `lot_provenance='unknown'` and timestamp null.
- Index `(tenant_id, company_id, lot_provenance)` where both columns exist; for child tables whose company scope comes through a parent, add denormalized non-null `tenant_id` and `company_id` first, backfill from parent, FK company RESTRICT and enforce parent ownership with a deferred trigger.
- Existing rows are not relabelled in DDL. T8’s resumable command labels rows only where the producer path is provably FEFO; otherwise leaves `unknown`.
- W-LOT-A writes no `operator_captured`.

Create `lot_provenance_backfill_runs`:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `tenant_id`, `company_id` | UUID NOT NULL; company FK RESTRICT |
| `producer` | VARCHAR(40) NOT NULL |
| `operation_uuid` | UUID NOT NULL |
| `operation_fingerprint` | CHAR(64) NOT NULL |
| `status` | VARCHAR(16) NOT NULL DEFAULT `pending` |
| `cursor_id` | UUID NULL |
| `rows_scanned`, `rows_updated` | BIGINT NOT NULL DEFAULT 0 |
| `started_at`, `heartbeat_at`, `finished_at` | TIMESTAMPTZ NULL |
| `last_error` | TEXT NULL |
| `created_at`, `updated_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints/indexes:

- Producer is `pos_receipt_allocation|document_line|transfer_allocation`.
- Status is `pending|running|complete|failed`.
- Unique `(tenant_id, company_id, producer, operation_uuid)`.
- Partial unique one `running` row per tenant/company/producer.
- Counts nonnegative and updated `<=` scanned.
- `pending`: all execution timestamps/error null.
- `running`: start/heartbeat non-null; finish/error null.
- `complete`: start/heartbeat/finish non-null; error null; ordered timestamps.
- `failed`: start/heartbeat/finish/error non-null; ordered timestamps and nonblank error.

<a id="pl-s6"></a>
### S6 — durable census schema — `PL-S6`, owned by T9

Central migration: `apps/api/database/migrations/2026_09_06_150000_create_lot_ledger_census_runs.php`.

Create `lot_ledger_census_runs`:

| Column | Contract |
|---|---|
| `id`, `operation_uuid` | UUID NOT NULL; `id` PK |
| `status` | VARCHAR(16) NOT NULL DEFAULT `running` |
| `expected_tenants`, `completed_tenants`, `failed_tenants` | BIGINT NOT NULL DEFAULT 0 |
| `started_at`, `heartbeat_at` | TIMESTAMPTZ NOT NULL |
| `finished_at` | TIMESTAMPTZ NULL |
| `last_error` | TEXT NULL |
| `created_at`, `updated_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints:

- Unique operation UUID.
- Status `running|complete|failed|stale`.
- Counts nonnegative; completed + failed `<=` expected.
- `running`: finish/error null.
- `complete`: finish non-null, error null, completed equals expected, failed zero.
- `failed|stale`: finish/error non-null and nonblank error.
- Timestamp ordering.
- Partial unique one `running` fleet census.

Create `lot_ledger_census_company_results`:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `run_id` | UUID NOT NULL, run FK RESTRICT |
| `tenant_id` | UUID NOT NULL, central tenant FK RESTRICT |
| `company_id` | UUID NULL, tenant-local identity; null only for tenant-level initialization failure |
| `status` | VARCHAR(32) NOT NULL |
| `entitlement_state` | VARCHAR(32) NOT NULL |
| `entitlement_revision` | BIGINT NULL |
| `cohort_tuples`, `compared_tuples`, `drifted_tuples` | BIGINT NOT NULL DEFAULT 0 |
| `net_drift`, `absolute_drift` | DECIMAL(20,4) NOT NULL DEFAULT 0 |
| `started_at`, `finished_at` | TIMESTAMPTZ NOT NULL |
| `last_error` | TEXT NULL |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP |

Constraints/indexes:

- Status `clean|drifted|not_entitled|entitlement_unresolved|failed`.
- Entitlement `entitled|not_entitled|entitlement_unresolved`.
- Unique `(run_id, tenant_id, company_id)` with PostgreSQL `NULLS NOT DISTINCT`; SQLite uses separate unique indexes for company-null and company-non-null.
- Metrics nonnegative; absolute drift nonnegative; finish `>=` start.
- `clean`: entitled, revision non-null, drift count/net/absolute zero, error null.
- `drifted`: entitled, revision non-null, drift count and absolute drift positive, error null.
- `not_entitled`: matching entitlement, revision non-null, every metric zero, error null.
- `entitlement_unresolved`: matching entitlement, revision null, metrics zero, nonblank error.
- `failed`: nonblank error.
- Index `(tenant_id, company_id, finished_at DESC)` and `(status, finished_at DESC)`.

## State machines

| Machine | Allowed edges | Enforcement |
|---|---|---|
| Company cutover | `prepared→active→rolled_back` | S1 implication checks and transition trigger |
| Recall request | `requested→recalled` only | S2 checks, row lock, immutable transition |
| Used-lot identity | version `n→n+1` only through correction service | S3 unique/check plus batch row lock |
| Provenance backfill | `pending→running→complete|failed`; failed resumes as a new operation linked by `--resume-run` | S5 checks and partial running unique |
| Census | `running→complete|failed|stale` | S6 checks and one-running partial unique |
| Count reconciliation | one immutable result per item/count phase | S4 unique keys and deferred effect-sum checks |

<a id="pl-lock"></a>
## Complete writer, eligibility and lock census — `PL-LOCK`

Canonical order:

1. Central entitlement transaction and `pg_advisory_xact_lock(hashtextextended('module-entitlement:' || tenant || ':BatchExpiry',0))`.
2. Tenant workflow header: counting header, recall request or transfer/document header.
3. Sorted `ProductCostLock` advisory locks for every affected product.
4. `stock_levels` rows ordered by product, variant-nullness, variant, location.
5. `inventory_batch_stock` ordered by batch and location.
6. `stock_reservations` ordered by UUID.
7. `product_batches` ordered by ID; product rows stay product-last where WAC requires them.
8. `stock_movement_sequence_counters`.
9. Stock movement, batch movement and immutable audit/effect/provenance rows.
10. `InventoryGlPostingBuffer::flushIfOutermost()`.
11. GL numbering lock.
12. GL company hash/chain lock.
13. Journal entry/header/lines.

Rules:

- No tenant lock is acquired before the central entitlement lock on an entitled lot arm.
- Configuration mutation takes the same central locks, sorted by tenant, before changing entitlement/revision.
- FEFO replaces `SKIP LOCKED` with ordinary `FOR UPDATE` after product serialization; a hold or competing count may wait, never silently divert or shortfall because a candidate was skipped.
- Recall request, recall escalation, sale, issue, reservation and transfer use the same `BatchEligibilityService`.
- Eligibility is checked after locks: company, product/variant, active, not globally recalled, not expired, and no `requested` hold at the operation location. Transfer checks both source and destination.
- `InventoryGlPostingBuffer` is the exclusive owner of inventory-triggered GL dispatch. New identification and reattribution movements are flat, so its existing flat-row guard emits zero journal entries. Direct calls to `InventoryGlPostingService` or `GeneralLedgerService` from W-LOT code are forbidden.
- The buffer remains transaction-scoped: enqueue after inventory rows, flush before the outer transaction commits, GL numbering/company locks after all inventory locks. Current ownership is visible at `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:17-81` and the stock row direction authority at `StockMovement.php:174-212`.

Writer census:

| Writer / existing production path | Operations | Required disposition |
|---|---|---|
| `WeightedAverageCostService.php` | purchase, sale, return, cost adjustment | Add missing product advisory to sale; preserve stock→product-last→buffer order |
| `StockAdjustmentService.php` | receive, issue, transfer, reserve, release, adjust, count result | Route batch quantities through `BatchStockMutationService`; acquire entitlement/product locks |
| `BatchStockService.php` | DEFAULT, receive, issue, transfer, movement | Retain DEFAULT creation only for legacy/unidentified inbound; exact strings; no direct identity split |
| `BatchStock.php` | reserve/release/adjust | Remove public float mutators; model becomes persistence-only |
| `FEFOInventoryService.php` | suggest, consume, restore, total availability | One eligibility predicate, exact strings, no `SKIP LOCKED` after advisory serialization |
| `StockReservationService.php` | reserve, release, releaseBySource, expire, recalculate, FEFO/work-order adapters | Exact quantities, eligibility before batch reservation, shared lock order |
| `GoodsReceiptService.php` | inbound aggregate/lot | Entitlement fence and canonical mutation |
| `OpeningBalancePostingService.php`, `ResetOpeningBalanceService.php` | opening/reset | Canonical mutation; unidentified stock remains DEFAULT until L9 |
| `StockAdjustmentDocumentService.php` | posted adjustments | Canonical mutation and GL buffer |
| `SupplierGoodsReturnNoteService.php` | supplier return | Eligibility/fence and buffer |
| `StockTransferService.php` | initiate/complete/cancel allocation effects | Fence; source/destination hold; product advisory before lot rows |
| `StockLevelMigrationService.php`, `StockThresholdService.php`, `FixOrphanedProducts.php` | maintenance | Declare non-lot writes or route batch effects through canonical service |
| `GroupedWriteOffService.php`, `BatchWriteOffService.php`, `ReverseWriteOffService.php` | write-off/reversal | Same fence/order; buffer owns GL |
| `RepairPhantomDefaultBatchesCommand.php` | repair/repoint | Maintenance mode, same product lock, no concurrent live writer |
| `DeliveryNoteService.php`, `ReturnNoteService.php` | issue/return | Fence, provenance and canonical mutation |
| `ReceiptCreationService.php` | legacy server receipt allocation | Keep compilable and provenance-correct; no captured claim |
| `ReceiptReturnService.php` | server return | Use original allocation only as an estimate and preserve provenance |
| `ReturnScrapWriteOffService.php` | returned-stock scrap | Canonical mutation and buffer |
| `PosCoreReceiptProjection.php` | live POS aggregate plus FEFO lot arm | Aggregate stays always active; only lot arm is fenced; local requested hold blocks FEFO consumption |
| `BatchRecallService` | request and global recall | Same product lock as sale/transfer; no GL |
| `BatchIdentityCorrectionService` | identity correction | Same product/batch lock; no quantity or GL |
| `LotIdentificationService` | DEFAULT reattribution | Flat aggregate movement, offsetting lot effects, zero WAC/GL |
| `LotCountObservationService` | observations/watermark | Counting header then product lock; no stock write |
| `LotCountReconciliationService` | aggregate adjustment or reattribution | Product lock, lot rows, movement, then buffer |
| Entitlement mutators | vertical/extras/provision/reconcile | Central advisory locks and revision bump before mutation |
| `InventoryGlPostingBuffer.php` | inventory→GL owner | Only buffer calls `InventoryGlPostingService`; flush remains inside outer transaction |
| `InventoryGlPostingService.php` | count correction/write-off/movement JE | Called only by buffer; flat movements skipped |
| `GeneralLedgerService.php` | journal creation/posting/numbering | Remains downstream of buffer and inventory locks |

Architecture enforcement:

- `apps/api/tests/Architecture/InventoryWriterLockManifestTest.php` contains the exact allowlist above and fails on an unclassified writer.
- `apps/api/tests/Architecture/InventoryGlPostingViaBufferOnlyTest.php` forbids direct inventory-to-GL calls.
- `apps/api/tests/Architecture/LotQuantityFloatBanTest.php` rejects `float`, `(float)`, `floatval`, scientific notation and direct batch-stock mutation in production lot paths.
- `apps/web/eslint-rules/no-lot-quantity-number.ts` rejects `parseFloat`, `Number`, arithmetic operators and `number` declarations for generated lot quantity fields.

## Dispatch tasks

Reviewer invocation syntax used below:

```text
Run <reviewer-file> against merge-base(origin/dev)..HEAD.
Prompt: "Review only Task <ID> against W-LOT-A rev 4. Verify every listed invariant by reading code and running the task commands. Cite path:line for every finding. Return BLOCKED unless every red-first proof, Convention-09 case, schema/lock rule and rollback rule is present. Do not merge."
Acceptance: explicit APPROVED with zero BLOCKER/MAJOR findings.
```

<a id="pl-t1"></a>
### Task 1 — rollout scaffold, glossary, build fingerprint and migration ownership — `PL-T1`

Production/config files:

- `docs/glossary.md`
- `apps/api/config/w_lot_a.php` new
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/WLotARolloutFlagsData.php` new
- `apps/api/app/Console/Commands/CreateWLotARolloutRevisionCommand.php` new
- `apps/api/app/Console/Commands/WLotACutoverCommand.php` new
- `apps/api/app/Console/Commands/WLotAPreflightCommand.php` new
- `docker-compose.staging.yml`
- `apps/api/docker/entrypoint.sh`
- `apps/api/.env.example`
- `apps/web/vite.config.ts`
- `apps/web/tools/wLotABuildFingerprintPlugin.ts` new
- `apps/web/Dockerfile`

Public signatures:

```php
final readonly class WLotARolloutFlagsData extends Data
{
    public function __construct(
        public bool $wlotAEntitlementFence,
        public bool $wlotARecallHolds,
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

Full CLI signatures:

```text
inventory:w-lot-a-rollout-revision
  {--artifact-sha= : Required lowercase 40-character Git SHA}
  {--flags-json= : Required inline JSON object containing exactly the seven rollout booleans}
  {--created-by= : Required deployment actor}
  {--format=json : Output format; only json is accepted}

inventory:w-lot-a-cutover
  {action : prepare|activate|rollback}
  {revision : Rollout revision UUID}
  {--tenant= : Optional one tenant UUID}
  {--all-tenants : Select every reachable tenant}
  {--company= : Optional company UUID within the selected tenant}
  {--operation= : Required operation UUID}
  {--expected-active-revision= : Required for rollback}
  {--actor= : Required deployment actor}
  {--manifest= : Required captured manifest path for activate/rollback}
  {--format=json : Output format}

inventory:w-lot-a-preflight
  {--tenant= : Optional one tenant UUID}
  {--all-tenants : Select every reachable tenant}
  {--company= : Optional company UUID}
  {--artifact-sha= : Expected artifact SHA}
  {--expected-revision= : Expected rollout revision UUID}
  {--expected-flags-fingerprint= : Expected 64-character fingerprint}
  {--expected-cutover-manifest= : Captured manifest path}
  {--fail-on-drift : Fail if latest census is drifted}
  {--fail-on-unresolved : Fail on entitlement_unresolved}
  {--fail-on-stale : Fail when no successful census exists inside 26 hours}
  {--format=json : Output format}
```

Entrypoint/compose contract:

- Seven rollout variables plus `AUTO_MIGRATE` are declared in `x-api-env`, inherited by API, worker, scheduler and websocket (`docker-compose.staging.yml:17-58,184-186,212-214,233-234,248-249`).
- Entrypoint accepts only literal `true|false`, prints all eight values and their SHA-256 fingerprint, and exits nonzero on invalid/missing values.
- `AUTO_MIGRATE=false` skips central and tenant auto-migration. `true` runs central migration then `tenants:migrate-rolling --force` and exits nonzero on either failure; current swallowed failures at `apps/api/docker/entrypoint.sh:131-154` are removed.
- Web emits deterministic `/build-fingerprint.json` containing `contract_rev:4`, `feature_fingerprint:"w-lot-a-rev4-server-core"` and the seven compile-time feature names. No time/random field.

Red first:

- Test: `apps/api/tests/Feature/Console/WLotARolloutRevisionCommandTest.php`.
- Case: `WLotARolloutRevisionCommandTest::test_revision_rejects_a_missing_or_non_boolean_flag`.
- First failing assertion: `self::assertSame(Command::INVALID, $exitCode)`.
- Command/lane: `cd apps/api && php artisan test tests/Feature/Console/WLotARolloutRevisionCommandTest.php --filter='WLotARolloutRevisionCommandTest::test_revision_rejects_a_missing_or_non_boolean_flag'` — PHPUnit SQLite.
- Convention 09: not applicable; rollout metadata is release infrastructure, not an operator-edited catalogue entity.
- Reviewer gate: `.claude/agents/tenancy-authz-reviewer.md` and `.claude/agents/frontend-conventions-reviewer.md` with the invocation above.
- Rollback: redeploy prior image; keep no schema because S1 lands in Push 2. Restore prior asset hash. Never leave `AUTO_MIGRATE=true` with fail-open behavior.

<a id="pl-t2"></a>
### Task 2 — transactional worker entitlement fence — `PL-T2`

Production files:

- `apps/api/app/Modules/BatchExpiry/Domain/Enums/CompanyModuleEntitlementState.php` new
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/CompanyModuleEntitlementDecisionData.php` new
- `apps/api/app/Modules/BatchExpiry/Application/Services/CompanyModuleEntitlementFence.php` new
- `apps/api/app/Modules/BatchExpiry/Providers/BatchExpiryServiceProvider.php`
- `apps/api/app/Services/CompanyConfigService.php`
- `apps/api/app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php`
- `apps/api/app/Modules/Tenant/Presentation/Controllers/VerticalConfigController.php`
- `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php`
- `apps/api/app/Modules/Tenant/Application/Commands/ReconcileModulesCommand.php`
- `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php`
- `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php`
- `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php`
- `apps/api/app/Modules/Product/Presentation/Requests/UpdateProductRequest.php`
- `apps/web/src/features/inventory/ProductForm.tsx`

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
    public function resolve(string $tenantId, string $companyId, ModuleName $module): CompanyModuleEntitlementDecisionData;

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

    /** @param list<string> $tenantIds
      * @return array<string,int>
      */
    public function executeVerticalFanoutMutation(
        array $tenantIds,
        ModuleName $module,
        Closure $centralMutation,
    ): array;
}
```

Fence algorithm:

1. Begin a central transaction.
2. Acquire the transaction-scoped advisory lock for tenant/BatchExpiry.
3. Read the tenant revision and effective default/extra/vertical configuration directly under the lock; the cache key becomes `tenant_config:{tenant}:{revision}`.
4. Initialize that tenant, verify `companies.id` ownership, and begin the tenant transaction.
5. Invoke the void callback only for `entitled`.
6. Commit the tenant transaction while the central advisory lock remains held.
7. Tear down tenancy, then commit central and release the lock.
8. On failure, roll back tenant then central; lookup/config failures return `entitlement_unresolved`, never boolean false.
9. Configuration mutations acquire the same locks in lexical tenant order and increment `module_entitlement_revision` in the same central transaction only when effective entitlement changes.
10. No independent company licence/toggle is introduced.

Consumers: correction, identification, counts, receipt sale/refund lot arms, transfer, reservation, batch create/update/write-off and census cohort. POS aggregate/fiscal work remains outside the callback.

Red first:

- Test: `apps/api/tests/Feature/BatchExpiry/WLotAEntitlementFencePostgresTest.php`.
- Case: `WLotAEntitlementFencePostgresTest::test_revocation_waits_for_the_tenant_commit_and_blocks_the_next_lot_write`.
- First failing assertion: `self::assertSame(0, DB::table('inventory_batch_movements')->where('reference_id', $blockedOperation)->count())`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry/WLotAEntitlementFencePostgresTest.php --filter='WLotAEntitlementFencePostgresTest::test_revocation_waits_for_the_tenant_commit_and_blocks_the_next_lot_write'` — live PHPUnit PostgreSQL.

Convention 09 in `apps/api/tests/Feature/BatchExpiry/WLotAEntitlementSecondOfEverythingTest.php`:

- `test_second_company_uses_its_owned_company_row_and_never_company_a`: first assertion `self::assertSame($companyB->id, $decision->companyId)`.
- `test_second_location_remains_selected_when_entitlement_is_resolved`: first assertion `self::assertDatabaseHas('inventory_batch_stock',['location_id'=>$locationB->id])`.
- `test_repeating_an_identical_product_update_does_not_bump_revision_or_duplicate_lots`: first assertion `self::assertSame($beforeRevision, $tenant->refresh()->module_entitlement_revision)`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry/WLotAEntitlementSecondOfEverythingTest.php` — live PHPUnit PostgreSQL.

Reviewer gate: `.claude/agents/tenancy-authz-reviewer.md`. Rollback: set cutovers inactive/roll back, deploy T1 image, retain S1 revision data; old resolver remains only as a compatibility reader, never a worker authority.

<a id="pl-t3"></a>
### Task 3 — exact quantities, canonical mutation and shared eligibility — `PL-T3`

Production files:

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchQuantityData.php` new
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchStockMutationResultData.php` new
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockMutationService.php` new
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchEligibilityService.php` new
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchStock.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`
- `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php`
- `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php`
- `apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php`
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php`
- `apps/api/app/Modules/Marketplace/Application/Services/MarketplaceOrderService.php`
- `apps/api/app/Modules/Cart/Application/Services/CartService.php`
- `apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/InventoryReservationAdapter.php`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/web/src/features/batches/pages/BatchListPage.tsx`
- `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx`
- `apps/web/src/features/batches/api/batches.ts`
- `apps/web/src/features/batches/types.ts`
- `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx`
- `apps/web/src/features/stock-transfers/types/index.ts`
- `packages/shared/types/generated.d.ts`

Signatures:

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
    public function assertEligible(
        Batch $batch,
        string $locationId,
        BatchEligibilityPurpose $purpose,
    ): void;

    public function applyToQuery(
        Builder $query,
        string $locationId,
        BatchEligibilityPurpose $purpose,
    ): Builder;
}
```

All quantities are canonical non-scientific numeric strings at scale 4. `FEFOInventoryService::getTotalAvailableQuantity()` returns `numeric-string`; `BatchResource` always serializes quantity/reserved/available as strings. `BatchStock` float accessors and public mutators are deleted. Web sums/comparisons use the existing decimal helper, never JS number arithmetic.

Red first:

- Test: `apps/api/tests/Feature/BatchExpiry/WLotAExactQuantityBoundaryTest.php`.
- Case: `WLotAExactQuantityBoundaryTest::test_fractional_transfer_accepts_exact_boundary_and_rejects_one_ten_thousandth_more`.
- First failing assertion: `self::assertSame('0.0000', (string) $source->refresh()->available_quantity)`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry/WLotAExactQuantityBoundaryTest.php --filter='WLotAExactQuantityBoundaryTest::test_fractional_transfer_accepts_exact_boundary_and_rejects_one_ten_thousandth_more'` — live PHPUnit PostgreSQL.

Web red:

- Test: `apps/web/src/features/batches/pages/BatchDetailPage.exactQuantity.test.tsx`.
- Case: `renders exact quantities without parseFloat`.
- First failing assertion: `expect(screen.getByText('9007199254740991.1234')).toBeInTheDocument()`.
- Command/lane: `pnpm --filter @autoerp/web exec vitest run src/features/batches/pages/BatchDetailPage.exactQuantity.test.tsx -t 'renders exact quantities without parseFloat'` — Vitest.

Convention 09:

- `test_exact_quantity_is_company_scoped` → first assertion compares B’s exact quantity with `'1.2345'`.
- `test_exact_transfer_uses_second_selected_location` → first assertion checks only location B changed.
- `test_duplicate_reservation_retry_does_not_double_reserved_quantity` → first assertion equals the original reserved string.
- File/command: `apps/api/tests/Feature/BatchExpiry/WLotAExactQuantitySecondOfEverythingTest.php`; live PostgreSQL command by path.

Reviewer gate: inventory-costing, stock-GL-interaction and frontend-conventions reviewers. Rollback: deactivate exact-quantity cutover and redeploy prior code; stored DECIMAL values and additive schema remain unchanged.

<a id="pl-t4"></a>
### Task 4 — L1 authorization, policy-neutral recall hold and guarded deactivation — `PL-T4`

Production files:

- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallService.php` new
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchDeactivationService.php` new
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchRecallRequest.php` new
- `apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchRecallRequestStatus.php` new
- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/RequestBatchRecallRequest.php` new
- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/RecallBatchRequest.php` new
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchRecallRequestResource.php` new
- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`
- `apps/web/src/routes/index.tsx`
- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- `apps/web/src/features/batches/api/batches.ts`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/web/src/features/batches/pages/BatchListPage.tsx`
- `apps/web/src/features/batches/pages/EditBatchPage.tsx`
- `apps/web/src/locales/en/batches.json`
- `apps/web/src/locales/fr/batches.json`
- `apps/web/src/hooks/permissionsMap.generated.ts`

Signatures:

```php
final class BatchRecallService
{
    public function request(
        Batch $batch,
        Location $location,
        string $reason,
        string $operationUuid,
        User $actor,
    ): BatchRecallRequest;

    public function recall(
        BatchRecallRequest $request,
        string $reason,
        string $operationUuid,
        User $actor,
    ): Batch;
}

final class BatchDeactivationService
{
    public function deactivate(Batch $batch, string $operationUuid, User $actor): void;
}

final class BatchController
{
    public function requestRecall(RequestBatchRecallRequest $request, string $uuid): JsonResponse;
    public function recall(RecallBatchRequest $request, string $uuid): JsonResponse;
    public function destroy(Request $request, string $uuid): JsonResponse;
}
```

Route/permission matrix:

| Route | Permission |
|---|---|
| GET list/show/expiring/expired/stock/product stock/POS batch data | `batches.view` |
| GET forward/backward trace and export | `batches.traceability` |
| POST create | `batches.create` |
| PATCH update | `batches.update` |
| DELETE deactivate | `batches.delete` |
| POST `/batches/{uuid}/recall-requests` | `batches.recall.request` |
| POST `/batches/{uuid}/recall` | `batches.recall` |
| transfer | `inventory.transfer` plus existing module gate |
| write-off/reversal | `batches.write-off` |
| identity correction | `batches.correct-identity` |
| identification | `batches.identify` |
| lot count | `batches.count-lots` plus existing assignment/`inventory.adjust` rules |
| health | `batches.health` |

Role contract:

- Manager: existing manager set, remove `batches.recall`, add `batches.recall.request` and `batches.count-lots`.
- `general_manager`: exactly manager set with no location restriction, plus only `batches.recall` and `treasury.manage_all_locations`.
- Admin: all permissions, including identity and health.
- Cashier/viewer: `batches.view` only where existing read role applies.
- `batches.correct-identity`, `batches.identify`, `batches.health` remain admin-only.
- Regenerate via `php artisan permissions:export-frontend-map`, whose existing command is at `apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:14-40`.

Deactivation locks product/aggregate/lot rows and requires every batch-stock quantity and reservation to be zero. The operator must complete existing transfer/write-off disposition first. Exact retry is 204; conflicting operation UUID is 409.

Red first:

- Test: `apps/api/tests/Feature/BatchExpiry/WLotARecallHoldAuthorizationPostgresTest.php`.
- Case: `test_requested_hold_serializes_against_pos_fefo_and_stock_transfer_in_both_directions`.
- First failing assertion: `self::assertDatabaseMissing('inventory_batch_movements',['batch_id'=>$heldBatch->id,'reference_id'=>$saleOperation])`.
- Command/lane: live PostgreSQL by exact file/filter.

Convention 09:

- Second company: company B request never holds company A’s same-number lot.
- Second location: a request at B blocks B sale and source/destination transfer while A remains eligible.
- Rerun: same operation returns the original request; conflicting fingerprint returns 409 and creates no transition.
- File: `apps/api/tests/Feature/BatchExpiry/WLotARecallSecondOfEverythingTest.php`.
- First data assertions: company ID equals B; B eligibility throws `BatchHeldException`; request count remains one.
- Command/lane: live PostgreSQL by path.

Reviewer gate: tenancy-authz, inventory-costing and frontend-conventions. Rollback: roll back active cutovers and deploy T3; retain request/transition evidence. Never delete requests or invent a release transition.

<a id="pl-t5"></a>
### Task 5 — L9 identification first, then L2 used-lot freeze/correction — `PL-T5`

Production files:

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchIdentityCorrectionData.php` new
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/LotIdentificationData.php` new
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/LotIdentificationLineData.php` new
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchIdentityCorrectionService.php` new
- `apps/api/app/Modules/BatchExpiry/Application/Services/LotIdentificationService.php` new
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php`
- `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/CorrectBatchIdentityRequest.php` new
- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/IdentifyLotRequest.php` new
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`
- `apps/web/src/features/batches/components/BatchIdentityCorrectionDialog.tsx` new
- `apps/web/src/features/batches/components/LotIdentificationDialog.tsx` new
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/web/src/features/batches/pages/EditBatchPage.tsx`
- `apps/web/src/features/batches/api/batches.ts`

Signatures:

```php
final readonly class BatchIdentityCorrectionData extends Data
{
    public function __construct(
        public string $batchUuid,
        public int $expectedIdentityVersion,
        public string $batchNumber,
        public ?CarbonImmutable $manufacturingDate,
        public ?CarbonImmutable $expiryDate,
        public string $reason,
        public string $evidenceReference,
        public string $operationUuid,
    );
}

final class BatchIdentityCorrectionService
{
    public function correct(BatchIdentityCorrectionData $data, User $actor): Batch;
}

final readonly class LotIdentificationLineData extends Data
{
    public function __construct(
        public string $batchNumber,
        public ?CarbonImmutable $manufacturingDate,
        public ?CarbonImmutable $expiryDate,
        public string $quantity,
    );
}

final readonly class LotIdentificationData extends Data
{
    /** @param list<LotIdentificationLineData> $lines */
    public function __construct(
        public string $sourceBatchUuid,
        public string $locationId,
        public string $quantity,
        public array $lines,
        public string $reason,
        public string $evidenceReference,
        public string $operationUuid,
    );
}

final class LotIdentificationService
{
    public function identify(LotIdentificationData $data, User $actor): LotIdentification;
}
```

Rules:

- Identification is enabled and verified before ordinary used-lot freezing is activated.
- “Used” means any `inventory_batch_movements` history, active/historical reservation, correction, identification or count effect.
- Ordinary PATCH may still change non-identity metadata but returns 409 `BATCH_IDENTITY_LOCKED` for number/manufacturing/expiry once used.
- Correction takes expected version, product lock and batch row lock; it never merges history.
- Identification requires source `DEFAULT`, zero reservations, same company/product/variant/location targets and exact destination sum.
- It writes one flat aggregate stock movement, source negative and N target positive lot effects. Aggregate, WAC and GL remain byte-for-byte unchanged.
- Signed effect reconciliation uses `quantity_after - quantity_before`.

Red first:

- Correction: `WLotAUsedLotIdentityCorrectionTest::test_ordinary_patch_fails_after_first_movement_but_a_versioned_correction_preserves_before_and_after`; first failing assertion `self::assertSame('BATCH_IDENTITY_LOCKED',$response->json('error.code'))`.
- Identification: `WLotADefaultIdentificationPostgresTest::test_default_is_split_into_two_lots_with_zero_aggregate_wac_and_gl_change`; first failing assertion `self::assertSame('0.0000',$aggregateDelta)`.
- Files: `apps/api/tests/Feature/BatchExpiry/WLotAUsedLotIdentityCorrectionTest.php`, `apps/api/tests/Feature/BatchExpiry/WLotADefaultIdentificationPostgresTest.php`.
- Commands: correction on PHPUnit SQLite; identification on live PostgreSQL, exact file/filter.

Convention 09:

- Same operation UUID may be used independently in company A and B; each produces only its own effects.
- Second location changes only that location’s DEFAULT and targets.
- Repeating the identification/correction produces zero extra movements, effects or GL rows.
- File: `apps/api/tests/Feature/BatchExpiry/WLotAIdentitySecondOfEverythingTest.php`; live PostgreSQL.

Reviewer gate: inventory-costing, stock-GL-interaction, tenancy-authz and frontend-conventions. Rollback: disable identity cutover, deploy T4 predecessor, retain corrections/identifications/movements; never recombine identified lots automatically.

<a id="pl-t6"></a>
### Task 6 — monotonic movement sequence and lot observations — `PL-T6`

Production files:

- `apps/api/app/Modules/Inventory/Application/Services/StockMovementSequenceAllocator.php` new
- `apps/api/app/Modules/Inventory/Application/Services/LotCountObservationService.php` new
- `apps/api/app/Modules/Inventory/Application/DTOs/SubmitLotObservationData.php` new
- `apps/api/app/Modules/Inventory/Domain/StockMovement.php`
- `apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php`
- `apps/api/app/Modules/Inventory/Domain/Services/MovementReplayService.php`
- `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php`
- `apps/api/app/Modules/Inventory/Presentation/Requests/SubmitCountRequest.php`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php`
- `apps/api/app/Modules/Inventory/Presentation/routes.php`
- `apps/web/src/features/inventory-counting/api/countingApi.ts`
- `apps/web/src/features/inventory-counting/pages/CountingDetailPage.tsx`
- `apps/web/src/features/inventory-counting/types.ts`

Signatures:

```php
final class StockMovementSequenceAllocator
{
    public function next(string $tenantId, string $companyId): int;

    public function currentForGrain(
        string $tenantId,
        string $companyId,
        string $productId,
        ?string $variantId,
        string $locationId,
    ): int;
}

final readonly class SubmitLotObservationData extends Data
{
    public function __construct(public string $batchUuid, public string $quantity);
}

final class LotCountObservationService
{
    /** @param list<SubmitLotObservationData> $observations */
    public function record(
        InventoryCountingItem $item,
        int $countNumber,
        string $parentQuantity,
        array $observations,
        ?CarbonInterface $countedAtDevice,
        ?CarbonInterface $deviceNow,
        User $actor,
    ): void;
}

final class InventoryCountingService
{
    /** @param list<SubmitLotObservationData> $lotObservations */
    public function submitCount(
        InventoryCountingItem $item,
        int $countNumber,
        string $quantity,
        ?string $notes,
        User $user,
        ?CarbonInterface $countedAtDevice = null,
        ?CarbonInterface $deviceNow = null,
        array $lotObservations = [],
    ): void;
}
```

Clock/as-of contract:

- `device_now` without `counted_at_device` is 422.
- When both exist, require `counted_at_device <= device_now + 300 seconds`.
- Device/server skew over 300 seconds remains accepted evidence but flags `clock_skew`, matching `InventoryCountingItem.php:69-75,305-317`.
- Missing `device_now` with a device count falls back to received time and flags.
- Under counting-header then product lock, capture the maximum numeric movement sequence for the exact grain.
- Replay predicate is `occurred_at > estimate OR (occurred_at = estimate AND movement_sequence > marker)`.
- No UUID comparison remains in `latestMovementMarker`, final resolver or replay.
- Parent must equal sum of submitted lot observations. Missing rows do not become zero; explicit zero must be present.

Red first:

- `WLotAMovementSequencePostgresTest::test_uuid4_history_and_same_second_insertions_replay_by_numeric_sequence`; first failing assertion `self::assertSame('7.0000',$replayedQuantity)`.
- Command: live PostgreSQL exact file/filter.
- Request envelope: `WLotALotObservationRequestTest::test_count_claim_after_device_now_plus_five_minutes_is_rejected`; first failing assertion `self::assertSame(422,$response->status())`; PHPUnit SQLite.

Convention 09:

- Company B marker excludes company A’s higher sequence.
- Second location marker excludes the first location.
- Identical resubmission retains one observation per batch/phase and returns the original result; conflicting quantity is 409.
- File: `apps/api/tests/Feature/Inventory/WLotAObservationSecondOfEverythingTest.php`; live PostgreSQL.

Reviewer gate: inventory-costing and tenancy-authz. Rollback: disable lot-count cutover; retain sequences and observations. Legacy UUID marker columns remain readable.

<a id="pl-t7"></a>
### Task 7 — lot reconciliation, reattribution and flat GL behavior — `PL-T7`

Production files:

- `apps/api/app/Modules/Inventory/Application/DTOs/LotCountReconciliationResultData.php` new
- `apps/api/app/Modules/Inventory/Application/Services/LotCountReconciliationService.php` new
- `apps/api/app/Modules/Inventory/Application/Services/CountingReconciliationService.php`
- `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php`
- `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php`
- `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php`
- `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php`
- `apps/web/src/features/inventory-counting/components/ReconciliationTable.tsx`
- `apps/web/src/features/inventory-counting/pages/CountingReviewPage.tsx`
- `apps/web/src/features/inventory-counting/pages/CountingDetailPage.tsx`

Signature:

```php
final class LotCountReconciliationService
{
    public function apply(
        InventoryCountingItem $item,
        int $countNumber,
        string $operationUuid,
        User $actor,
    ): LotCountReconciliationResultData;
}
```

Rules:

- Replay every observation forward from its ordered marker before applying.
- Finalization blocks if observations are missing, parent/child sums differ, a requested hold exists, entitlement is unresolved/not entitled, or unidentified physical quantity requires L9.
- Aggregate difference changes aggregate and affected lots equally.
- Pure reattribution writes one flat stock movement and offsetting lot effects; no DEFAULT top-up, WAC or GL.
- True no-op writes reconciliation evidence but no stock/batch movement.
- Product advisory serialization makes count versus sale/transfer/identification deterministic in both directions.

Red first:

- Test: `apps/api/tests/Feature/Inventory/WLotALotCountReconciliationPostgresTest.php`.
- Case: `test_fifteen_plus_five_reattributes_five_between_lots_with_one_flat_row_and_zero_journals`.
- First failing assertion: `self::assertSame(0, JournalEntry::where('source_id',$reconciliation->id)->count())`.
- Command/lane: live PostgreSQL exact file/filter.

Convention 09:

- Company A and B may reconcile identical batch numbers independently.
- Second-location reconciliation changes no first-location quantity.
- Repeated finalize adds no movement, effect, WAC or journal.
- File: `apps/api/tests/Feature/Inventory/WLotACountSecondOfEverythingTest.php`; live PostgreSQL.

Reviewer gate: inventory-costing, stock-GL-interaction and frontend-conventions. Rollback: disable lot-count cutover and deploy T6; never reverse an accepted count automatically. Existing applied movements remain audit truth.

<a id="pl-t8"></a>
### Task 8 — provenance on all three producers and all readers — `PL-T8`

Production files:

- `apps/api/app/Modules/BatchExpiry/Domain/Enums/LotProvenance.php` new
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/LotProvenanceData.php` new
- `apps/api/app/Console/Commands/BackfillLotProvenanceCommand.php` new
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php`
- `apps/api/app/Modules/POS/Domain/ReceiptLineBatchAllocation.php`
- `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToDeliveryNoteConverter.php`
- `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteFromDocumentFactory.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`
- `apps/api/app/Modules/Inventory/Domain/StockTransferLineBatchAllocation.php`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx`

Signatures:

```php
enum LotProvenance: string
{
    case OperatorCaptured = 'operator_captured';
    case SystemFefoEstimate = 'system_fefo_estimate';
    case Unknown = 'unknown';
}

final class BatchTraceabilityController
{
    public function forwardTrace(string $uuid): JsonResponse;
    public function backwardTrace(Request $request, string $partnerId): JsonResponse;
    public function export(Request $request, string $uuid): StreamedResponse;
}
```

Full new CLI signature:

```text
inventory:backfill-lot-provenance
  {--tenant= : Optional tenant UUID}
  {--all-tenants : Select every reachable tenant}
  {--company= : Optional company UUID}
  {--chunk=500 : Positive chunk size, maximum 5000}
  {--dry-run : Scan and report without mutation}
  {--execute : Persist the proved labels}
  {--operation= : Required operation UUID}
  {--resume-run= : Optional failed run UUID}
  {--format=text : text|json}
```

Exactly one of `--dry-run|--execute` is required. The three producers write `system_fefo_estimate`. Rows that cannot be proved stay `unknown`. Trace, receipt return, POS server refund, transfer detail/export and batch export surface the stored value. No inference produces `operator_captured`.

Red first:

- Test: `apps/api/tests/Feature/BatchExpiry/WLotAProvenanceProducerTest.php`.
- Case: `test_pos_document_and_transfer_producers_persist_system_fefo_estimate_and_trace_exports_it`.
- First failing assertion: `self::assertSame(['system_fefo_estimate','system_fefo_estimate','system_fefo_estimate'],$provenances)`.
- Command/lane: live PostgreSQL exact file/filter.

Convention 09:

- Same lot number in company B exposes only B provenance.
- Second-location transfer detail/export retains the selected location and provenance.
- Backfill rerun resumes at the captured cursor and updates zero rows on the second execution.
- File: `apps/api/tests/Feature/BatchExpiry/WLotAProvenanceSecondOfEverythingTest.php`; live PostgreSQL.

Reviewer gate: inventory-costing, fiscal-pos, tenancy-authz and frontend-conventions. Rollback: disable provenance cutover and deploy T7; retain annotations/backfill history. Never relabel an estimate as captured.

<a id="pl-t9"></a>
### Task 9 — entitlement-aware durable drift census and 03:20 schedule — `PL-T9`

Production files:

- `apps/api/app/Modules/BatchExpiry/Application/Services/LotLedgerDriftCensus.php`
- `apps/api/app/Console/Commands/LotLedgerDriftCensusCommand.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/WLotAHealthController.php` new
- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`
- `apps/api/routes/console.php`
- `apps/web/src/features/batches/components/LotLedgerHealthPanel.tsx` new
- `apps/web/src/features/batches/api/batches.ts`
- `apps/web/src/features/batches/pages/BatchListPage.tsx`

Modified full CLI signature:

```text
inventory:lot-drift-census
  {--tenant= : Tenant UUID, required unless --all-tenants}
  {--all-tenants : Deliberate fleet-wide census}
  {--company= : Optional company UUID}
  {--operation= : Optional operation UUID; generated and printed when omitted}
  {--stale-after=180 : Minutes before an unfinished prior run becomes stale}
  {--persist : Persist central run and company results}
  {--fail-on-drift : Exit nonzero on drift}
  {--fail-on-unresolved : Exit nonzero on entitlement_unresolved}
  {--fail-on-stale : Exit nonzero when stale recovery occurred}
  {--format=text : text|json}
```

Health signature:

```php
final class WLotAHealthController
{
    public function show(Request $request): JsonResponse;
}
```

Rules:

- Cohort is exactly effective `entitled` company × `requires_batch_tracking=true`.
- `not_entitled` produces a non-alerting zero-metric result.
- Resolution failure is `entitlement_unresolved`, alerting even if quantitative query finds no rows.
- Query is read-only and performs no repair.
- Before a run, mark older `running` rows stale when heartbeat exceeds 180 minutes.
- Schedule at 03:20, avoiding current 01:30 batch expiry, 02:15 treasury and 03:00 certification jobs (`apps/api/routes/console.php:95-123,193-213,256-259`).
- Use `withoutOverlapping(180)`, foreground execution and `onFailure()` logging/notification.
- Health reports latest success, latest result, age, revision, runtime flags and build fingerprint.

Red first:

- Test: `apps/api/tests/Feature/BatchExpiry/LotLedgerDriftCensusPostgresTest.php`.
- Case: `test_entitled_clean_drifted_not_entitled_unresolved_and_missed_run_are_durable_and_read_only`.
- First failing assertion: `self::assertSame('entitlement_unresolved',$unresolved->status)`.
- Command/lane: live PostgreSQL exact file/filter.
- Convention 09: not applicable; census is read-only infrastructure. The test itself still proves company/location isolation and rerun/stale recovery.
- Reviewer gate: inventory-costing and tenancy-authz.
- Rollback: disable scheduler cutover, leave durable run history, run one manual census after rollback and confirm no automatic repair occurred.

<a id="pl-t10"></a>
### Task 10 — generated contracts, CI reachability, smoke and release gate — `PL-T10`

Exact files:

- `packages/shared/types/generated.d.ts`
- `apps/web/src/hooks/permissionsMap.generated.ts`
- `.github/workflows/ci.yml`
- `apps/api/tests/Architecture/InventoryWriterLockManifestTest.php`
- `apps/api/tests/Architecture/InventoryGlPostingViaBufferOnlyTest.php`
- `apps/api/tests/Architecture/LotQuantityFloatBanTest.php`
- `apps/web/e2e/smoke/w-lot-a.smoke.ts` new
- `apps/web/playwright.smoke.config.ts`
- `apps/web/tools/__tests__/wLotABuildFingerprintPlugin.test.ts` new
- `apps/api/tests/Feature/Console/WLotAPreflightCommandTest.php`
- `apps/api/tests/Feature/BatchExpiry/WLotAEndToEndPostgresTest.php`

No new production service is introduced. Regenerate exactly:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
php artisan typescript:transform
php artisan permissions:export-frontend-map
```

Add an unconditional command to the live `backend-test-pgsql` job (`.github/workflows/ci.yml:578-646,1116-1117`):

```bash
php artisan test -c phpunit-pgsql.xml \
  tests/Feature/BatchExpiry/WLotAEntitlementFencePostgresTest.php \
  tests/Feature/BatchExpiry/WLotARecallHoldAuthorizationPostgresTest.php \
  tests/Feature/BatchExpiry/WLotAExactQuantityBoundaryTest.php \
  tests/Feature/BatchExpiry/WLotADefaultIdentificationPostgresTest.php \
  tests/Feature/Inventory/WLotAMovementSequencePostgresTest.php \
  tests/Feature/Inventory/WLotALotCountReconciliationPostgresTest.php \
  tests/Feature/BatchExpiry/WLotAProvenanceProducerTest.php \
  tests/Feature/BatchExpiry/LotLedgerDriftCensusPostgresTest.php \
  tests/Feature/BatchExpiry/WLotAEndToEndPostgresTest.php
```

Red first:

- Test: `WLotAEndToEndPostgresTest::test_request_identify_count_transfer_trace_and_census_converge_without_float_or_gl_drift`.
- First failing assertion: `self::assertSame('0.0000',$finalLotMinusAggregate)`.
- Command: live PostgreSQL exact file/filter.
- Convention 09: not separately applicable; this is a release gate and does not substitute for T2–T8’s same-lane tests.
- Reviewer gate: all five reviewers used above; every reviewer must approve the identical candidate SHA.
- Rollback: block promotion. If already staged, roll back cutovers before code and retain all additive audit/history rows.

<a id="pl-deploy"></a>
## Executable five-push staging manifest — `PL-DEPLOY`

The staging API compose auto-deploys while web does not (`docs/factory/WORKFLOW.md:206-220`). Every web-changing push therefore explicitly redeploys application `mY6P_PHb4pw-2LdG1Y7Ml`, captures the new deployment ID, waits for `done`, compares asset hashes, verifies `/build-fingerprint.json`, then runs Playwright. Dokploy’s official APIs document `application.redeploy` and deployment status endpoints: [Application API](https://docs.dokploy.com/docs/api/application), [Deployment API](https://docs.dokploy.com/docs/api/deployment).

### Common shell preamble

Run each block from a fresh shell:

```bash
set -euo pipefail

ERP_ROOT=/Users/houssamr/Projects/syneriva/apps/erp
STAGING_URL=https://erp.otospex.dev
STAGING_API_URL=https://api.erp.otospex.dev
DOKPLOY_WEB_APP_ID=mY6P_PHb4pw-2LdG1Y7Ml

cd "$ERP_ROOT"
git fetch origin dev
git merge-base --is-ancestor origin/dev HEAD
test "$(git branch --show-current)" = "dev"
```

### Common explicit web deployment

```bash
BEFORE_DEPLOYMENTS="$(
  curl --fail-with-body --silent --show-error \
    "${DOKPLOY_URL:?}/api/deployment.all?applicationId=${DOKPLOY_WEB_APP_ID}" \
    -H "x-api-key: ${DOKPLOY_API_KEY:?}"
)"
BEFORE_DEPLOYMENT_ID="$(
  printf '%s' "$BEFORE_DEPLOYMENTS" |
    jq -r 'sort_by(.createdAt) | reverse | .[0].deploymentId // empty'
)"
BEFORE_INDEX="$(curl -fsS "$STAGING_URL/")"
BEFORE_ASSET_PATH="$(
  printf '%s' "$BEFORE_INDEX" |
    grep -oE 'assets/[^"]+\.js' |
    head -n 1
)"
BEFORE_ASSET_HASH="$(
  curl -fsS "$STAGING_URL/$BEFORE_ASSET_PATH" |
    shasum -a 256 |
    awk '{print $1}'
)"

curl --fail-with-body --silent --show-error \
  -X POST "${DOKPLOY_URL:?}/api/application.redeploy" \
  -H "x-api-key: ${DOKPLOY_API_KEY:?}" \
  -H "Content-Type: application/json" \
  --data "$(jq -nc \
    --arg applicationId "$DOKPLOY_WEB_APP_ID" \
    --arg title "W-LOT-A rev 4 explicit web deployment" \
    '{applicationId:$applicationId,title:$title}')"

DEPLOYMENT_ID=
for attempt in {1..120}; do
  DEPLOYMENTS="$(
    curl --fail-with-body --silent --show-error \
      "${DOKPLOY_URL:?}/api/deployment.all?applicationId=${DOKPLOY_WEB_APP_ID}" \
      -H "x-api-key: ${DOKPLOY_API_KEY:?}"
  )"
  DEPLOYMENT_ID="$(
    printf '%s' "$DEPLOYMENTS" |
      jq -r --arg before "$BEFORE_DEPLOYMENT_ID" \
        '[.[] | select(.deploymentId != $before)] | sort_by(.createdAt) | reverse | .[0].deploymentId // empty'
  )"
  test -n "$DEPLOYMENT_ID" && break
  sleep 5
done
test -n "$DEPLOYMENT_ID"

for attempt in {1..120}; do
  DEPLOYMENTS="$(
    curl --fail-with-body --silent --show-error \
      "${DOKPLOY_URL:?}/api/deployment.all?applicationId=${DOKPLOY_WEB_APP_ID}" \
      -H "x-api-key: ${DOKPLOY_API_KEY:?}"
  )"
  DEPLOYMENT_STATUS="$(
    printf '%s' "$DEPLOYMENTS" |
      jq -r --arg id "$DEPLOYMENT_ID" \
        '.[] | select(.deploymentId == $id) | .status'
  )"
  test "$DEPLOYMENT_STATUS" = "done" && break
  test "$DEPLOYMENT_STATUS" = "error" && exit 1
  sleep 5
done
test "$DEPLOYMENT_STATUS" = "done"

NEW_INDEX="$(curl -fsS "$STAGING_URL/")"
NEW_ASSET_PATH="$(
  printf '%s' "$NEW_INDEX" |
    grep -oE 'assets/[^"]+\.js' |
    head -n 1
)"
NEW_ASSET_HASH="$(
  curl -fsS "$STAGING_URL/$NEW_ASSET_PATH" |
    shasum -a 256 |
    awk '{print $1}'
)"
test "$NEW_ASSET_HASH" != "$BEFORE_ASSET_HASH"

curl -fsS "$STAGING_URL/build-fingerprint.json" |
  jq -e '
    .contract_rev == 4 and
    .feature_fingerprint == "w-lot-a-rev4-server-core" and
    (.features | sort) == (
      [
        "wlot_a_drift_scheduler",
        "wlot_a_entitlement_fence",
        "wlot_a_exact_quantities",
        "wlot_a_identity_controls",
        "wlot_a_lot_counts",
        "wlot_a_provenance",
        "wlot_a_recall_holds"
      ] | sort
    )
  '

STAGING_URL="$STAGING_URL" \
SMOKE_TEST_EMAIL="${SMOKE_TEST_EMAIL:?}" \
SMOKE_TEST_PASSWORD="${SMOKE_TEST_PASSWORD:?}" \
pnpm --filter @autoerp/web exec playwright test \
  e2e/smoke/w-lot-a.smoke.ts \
  --config=playwright.smoke.config.ts
```

Save `BEFORE_DEPLOYMENT_ID`, `DEPLOYMENT_ID`, status, both asset hashes, fingerprint JSON and Playwright transcript as release evidence.

### Push 1 — rollout/config/fingerprint scaffold, all flags false

Contents: T1 code excluding S1 migration, migration-safe config, fail-hard entrypoint, deterministic web fingerprint. In `docker-compose.staging.yml`, all seven flags default false and `AUTO_MIGRATE` defaults false.

```bash
cd "$ERP_ROOT"
pnpm lint
pnpm typecheck
pnpm --filter @autoerp/web exec vitest run tools/__tests__/wLotABuildFingerprintPlugin.test.ts
cd apps/api
php artisan test tests/Feature/Console/WLotARolloutRevisionCommandTest.php
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
cd "$ERP_ROOT"

git add \
  docs/glossary.md \
  apps/api/config/w_lot_a.php \
  apps/api/app/Modules/BatchExpiry/Application/DTOs/WLotARolloutFlagsData.php \
  apps/api/app/Console/Commands/CreateWLotARolloutRevisionCommand.php \
  apps/api/app/Console/Commands/WLotACutoverCommand.php \
  apps/api/app/Console/Commands/WLotAPreflightCommand.php \
  docker-compose.staging.yml \
  apps/api/docker/entrypoint.sh \
  apps/api/.env.example \
  apps/web/vite.config.ts \
  apps/web/tools/wLotABuildFingerprintPlugin.ts \
  apps/web/Dockerfile
git commit -m "Phase 4.1.0: Add W-LOT-A rollout scaffold"
P1_SHA="$(git rev-parse HEAD)"
git push origin HEAD:dev
printf '%s\n' "$P1_SHA"
```

After API compose deploy, verify API/worker/scheduler/websocket logs each print seven false flags and `AUTO_MIGRATE=false`. Run the common explicit web deployment.

Rollback point P1: redeploy the previously recorded API image/SHA and previous Dokploy web deployment ID. No DB action.

### Push 2 — all additive schema, no live callers

Contents: S1–S6, models/enums/casts only. Flags and `AUTO_MIGRATE` remain false, so auto-deploy cannot pre-empt explicit migration evidence.

```bash
cd "$ERP_ROOT"
cd apps/api
php artisan test tests/Feature/Migrations/WLotARolloutSchemaTest.php
php artisan test tests/Feature/Migrations/WLotATenantSchemaTest.php
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
cd "$ERP_ROOT"

git add apps/api/database/migrations apps/api/app/Modules/BatchExpiry apps/api/app/Modules/Inventory
git commit -m "Phase 4.1.1: Add W-LOT-A additive schema"
P2_SHA="$(git rev-parse HEAD)"
git push origin HEAD:dev
printf '%s\n' "$P2_SHA"
```

After the auto-deployed API container is healthy and confirms `AUTO_MIGRATE=false`, run central then tenant migrations explicitly:

```bash
cd "$ERP_ROOT"
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan migrate --force

TENANT_MIGRATION_LOG="$(mktemp)"
if ! docker compose -f docker-compose.staging.yml exec -T api \
  php artisan tenants:migrate-rolling --force \
  >"$TENANT_MIGRATION_LOG" 2>&1
then
  cat "$TENANT_MIGRATION_LOG"
  exit 1
fi
cat "$TENANT_MIGRATION_LOG"

EXPECTED_TENANTS="$(
  sed -n 's/^Rolling tenant migrations across \([0-9][0-9]*\) tenant(s)\.$/\1/p' \
    "$TENANT_MIGRATION_LOG"
)"
VISITED_TENANTS="$(grep -c '^→ ' "$TENANT_MIGRATION_LOG")"
test -n "$EXPECTED_TENANTS"
test "$VISITED_TENANTS" = "$EXPECTED_TENANTS"
grep -E '^→ [0-9a-f-]{36}' "$TENANT_MIGRATION_LOG"
grep -Eq '^Done\. [0-9]+ tenant\(s\) migrated, 0 failed\.$' "$TENANT_MIGRATION_LOG"
if grep -Eq 'FAILED:|Done with errors|failed\.$' "$TENANT_MIGRATION_LOG"; then
  exit 1
fi
```

This captures every tenant ID from command output before it is used for later cutovers. The command and per-tenant output are grounded at `apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48-51,85-93,163`.

Rollback point P2: redeploy P1. Leave additive schema installed; do not run `migrate:rollback`.

### Push 3 — entitlement fence, exact quantity and lock spine

Contents: T2, T3 and architecture guards. All seven flags remain false. Change `AUTO_MIGRATE` default back to true; entrypoint now finds no pending W-LOT schema and fails deployment on future migration errors.

```bash
cd "$ERP_ROOT"
pnpm --filter @autoerp/web lint
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/web test
cd apps/api
php artisan test tests/Architecture/InventoryWriterLockManifestTest.php
php artisan test tests/Architecture/InventoryGlPostingViaBufferOnlyTest.php
php artisan test tests/Architecture/LotQuantityFloatBanTest.php
php artisan test -c phpunit-pgsql.xml \
  tests/Feature/BatchExpiry/WLotAEntitlementFencePostgresTest.php \
  tests/Feature/BatchExpiry/WLotAExactQuantityBoundaryTest.php
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
cd "$ERP_ROOT"

git add apps/api apps/web packages/shared docker-compose.staging.yml
git commit -m "Phase 4.1.2: Enforce lot entitlement and exact quantities"
P3_SHA="$(git rev-parse HEAD)"
git push origin HEAD:dev
printf '%s\n' "$P3_SHA"
```

Post-deploy:

```bash
docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:w-lot-a-preflight \
  --all-tenants \
  --artifact-sha="$P3_SHA" \
  --fail-on-unresolved \
  --format=json
```

The preflight must report all seven behavior flags false on API, worker, scheduler and websocket. Run the common web deployment.

Rollback point P3: redeploy P2; exact stored decimals require no data conversion.

### Push 4 — recall, correction, identification and lot counting, flags false

Contents: T4–T7 and their web surfaces. Flags stay false; code paths additionally require an active company cutover.

```bash
cd "$ERP_ROOT"
pnpm --filter @autoerp/web lint
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/web test
cd apps/api
php artisan typescript:transform
php artisan permissions:export-frontend-map
git diff --exit-code -- ../../packages/shared/types/generated.d.ts ../web/src/hooks/permissionsMap.generated.ts
php artisan test -c phpunit-pgsql.xml \
  tests/Feature/BatchExpiry/WLotARecallHoldAuthorizationPostgresTest.php \
  tests/Feature/BatchExpiry/WLotADefaultIdentificationPostgresTest.php \
  tests/Feature/Inventory/WLotAMovementSequencePostgresTest.php \
  tests/Feature/Inventory/WLotALotCountReconciliationPostgresTest.php
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
cd "$ERP_ROOT"

git add apps/api apps/web packages/shared
git commit -m "Phase 4.1.3: Add lot lifecycle and counting controls"
P4_SHA="$(git rev-parse HEAD)"
git push origin HEAD:dev
printf '%s\n' "$P4_SHA"
```

Sync permissions explicitly and verify every tenant result:

```bash
PERMISSION_LOG="$(mktemp)"
if ! docker compose -f docker-compose.staging.yml exec -T api \
  php artisan tenants:seed \
  --force \
  --class='Database\Seeders\RolesAndPermissionsSeeder' \
  >"$PERMISSION_LOG" 2>&1
then
  cat "$PERMISSION_LOG"
  exit 1
fi
cat "$PERMISSION_LOG"
if grep -Eq 'FAILED|ERROR|exception' "$PERMISSION_LOG"; then
  exit 1
fi

docker compose -f docker-compose.staging.yml exec -T api \
  php artisan permission:cache-reset
```

Create a false revision from inline JSON and capture its generated ID:

```bash
FALSE_FLAGS='{"wlot_a_entitlement_fence":false,"wlot_a_recall_holds":false,"wlot_a_exact_quantities":false,"wlot_a_identity_controls":false,"wlot_a_lot_counts":false,"wlot_a_provenance":false,"wlot_a_drift_scheduler":false}'

FALSE_REVISION_JSON="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php artisan inventory:w-lot-a-rollout-revision \
    --artifact-sha="$P4_SHA" \
    --flags-json="$FALSE_FLAGS" \
    --created-by="${DEPLOY_ACTOR:?}" \
    --format=json
)"
FALSE_REVISION_ID="$(printf '%s' "$FALSE_REVISION_JSON" | jq -er '.revision_id')"
FALSE_FLAGS_FINGERPRINT="$(printf '%s' "$FALSE_REVISION_JSON" | jq -er '.flags_fingerprint')"
printf '%s\n' "$FALSE_REVISION_JSON" > w-lot-a-p4-false-revision.json

PREPARE_OPERATION_JSON="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php -r 'require "vendor/autoload.php"; echo json_encode(["operation_id"=>(string)Illuminate\Support\Str::uuid()]);'
)"
PREPARE_OPERATION="$(printf '%s' "$PREPARE_OPERATION_JSON" | jq -er '.operation_id')"

FALSE_CUTOVERS_JSON="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php artisan inventory:w-lot-a-cutover prepare "$FALSE_REVISION_ID" \
    --all-tenants \
    --operation="$PREPARE_OPERATION" \
    --actor="${DEPLOY_ACTOR:?}" \
    --format=json
)"
printf '%s\n' "$FALSE_CUTOVERS_JSON" > w-lot-a-p4-false-cutovers.json
jq -e --arg revision "$FALSE_REVISION_ID" '
  .revision_id == $revision and
  (.cutovers | length > 0) and
  all(.cutovers[]; .cutover_id and .tenant_id and .company_id)
' w-lot-a-p4-false-cutovers.json

docker compose -f docker-compose.staging.yml cp \
  w-lot-a-p4-false-cutovers.json \
  api:/tmp/w-lot-a-p4-false-cutovers.json

docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:w-lot-a-preflight \
  --all-tenants \
  --artifact-sha="$P4_SHA" \
  --expected-revision="$FALSE_REVISION_ID" \
  --expected-flags-fingerprint="$FALSE_FLAGS_FINGERPRINT" \
  --expected-cutover-manifest=/tmp/w-lot-a-p4-false-cutovers.json \
  --fail-on-unresolved \
  --format=json
```

Run the common web deployment.

Rollback point P4: redeploy P3. Keep prepared cutovers and audit schema. No lot operation was enabled.

### Push 5 — provenance, census, CI and atomic activation

Contents: T8–T10. `docker-compose.staging.yml` changes all seven defaults from false to true in one commit. Behavior remains blocked until active company cutovers match the all-true revision.

```bash
cd "$ERP_ROOT"
pnpm build
pnpm lint
pnpm typecheck
pnpm test
cd apps/api
composer test
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
php artisan typescript:transform
php artisan permissions:export-frontend-map
git diff --exit-code -- ../../packages/shared/types/generated.d.ts ../web/src/hooks/permissionsMap.generated.ts
php artisan test -c phpunit-pgsql.xml \
  tests/Feature/BatchExpiry/WLotAEntitlementFencePostgresTest.php \
  tests/Feature/BatchExpiry/WLotARecallHoldAuthorizationPostgresTest.php \
  tests/Feature/BatchExpiry/WLotAExactQuantityBoundaryTest.php \
  tests/Feature/BatchExpiry/WLotADefaultIdentificationPostgresTest.php \
  tests/Feature/Inventory/WLotAMovementSequencePostgresTest.php \
  tests/Feature/Inventory/WLotALotCountReconciliationPostgresTest.php \
  tests/Feature/BatchExpiry/WLotAProvenanceProducerTest.php \
  tests/Feature/BatchExpiry/LotLedgerDriftCensusPostgresTest.php \
  tests/Feature/BatchExpiry/WLotAEndToEndPostgresTest.php
cd "$ERP_ROOT"

git add apps/api apps/web packages/shared .github/workflows/ci.yml docker-compose.staging.yml
git commit -m "Phase 4.1.4: Activate server-side lot core"
P5_SHA="$(git rev-parse HEAD)"
git push origin HEAD:dev
printf '%s\n' "$P5_SHA"
```

Force recreation of all four Laravel services so changed environment defaults are loaded:

```bash
docker compose -f docker-compose.staging.yml up -d \
  --force-recreate \
  api worker scheduler websocket
```

Verify all seven values and their fingerprint are identical in every service’s entrypoint log. Then dry-run and execute provenance with an ID captured from command output:

```bash
PROVENANCE_OPERATION_JSON="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php -r 'require "vendor/autoload.php"; echo json_encode(["operation_id"=>(string)Illuminate\Support\Str::uuid()]);'
)"
PROVENANCE_OPERATION="$(printf '%s' "$PROVENANCE_OPERATION_JSON" | jq -er '.operation_id')"

docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:backfill-lot-provenance \
  --all-tenants \
  --operation="$PROVENANCE_OPERATION" \
  --dry-run \
  --format=json

PROVENANCE_RESULT="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php artisan inventory:backfill-lot-provenance \
    --all-tenants \
    --operation="$PROVENANCE_OPERATION" \
    --execute \
    --format=json
)"
printf '%s\n' "$PROVENANCE_RESULT" > w-lot-a-p5-provenance.json
printf '%s' "$PROVENANCE_RESULT" |
  jq -e '.operator_captured_rows == 0 and .failed_runs == 0'
```

Run the durable census before activation:

```bash
CENSUS_RESULT="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php artisan inventory:lot-drift-census \
    --all-tenants \
    --persist \
    --stale-after=180 \
    --fail-on-drift \
    --fail-on-unresolved \
    --fail-on-stale \
    --format=json
)"
CENSUS_RUN_ID="$(printf '%s' "$CENSUS_RESULT" | jq -er '.run_id')"
printf '%s\n' "$CENSUS_RESULT" > w-lot-a-p5-census.json
```

Create the all-true revision and prepare fresh cutovers:

```bash
TRUE_FLAGS='{"wlot_a_entitlement_fence":true,"wlot_a_recall_holds":true,"wlot_a_exact_quantities":true,"wlot_a_identity_controls":true,"wlot_a_lot_counts":true,"wlot_a_provenance":true,"wlot_a_drift_scheduler":true}'

ACTIVE_REVISION_JSON="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php artisan inventory:w-lot-a-rollout-revision \
    --artifact-sha="$P5_SHA" \
    --flags-json="$TRUE_FLAGS" \
    --created-by="${DEPLOY_ACTOR:?}" \
    --format=json
)"
ACTIVE_REVISION_ID="$(printf '%s' "$ACTIVE_REVISION_JSON" | jq -er '.revision_id')"
ACTIVE_FLAGS_FINGERPRINT="$(printf '%s' "$ACTIVE_REVISION_JSON" | jq -er '.flags_fingerprint')"
printf '%s\n' "$ACTIVE_REVISION_JSON" > w-lot-a-p5-active-revision.json

ACTIVE_PREPARE_OPERATION_JSON="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php -r 'require "vendor/autoload.php"; echo json_encode(["operation_id"=>(string)Illuminate\Support\Str::uuid()]);'
)"
ACTIVE_PREPARE_OPERATION="$(
  printf '%s' "$ACTIVE_PREPARE_OPERATION_JSON" |
    jq -er '.operation_id'
)"

ACTIVE_CUTOVERS_JSON="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php artisan inventory:w-lot-a-cutover prepare "$ACTIVE_REVISION_ID" \
    --all-tenants \
    --operation="$ACTIVE_PREPARE_OPERATION" \
    --actor="${DEPLOY_ACTOR:?}" \
    --format=json
)"
printf '%s\n' "$ACTIVE_CUTOVERS_JSON" > w-lot-a-p5-active-cutovers.json
jq -e --arg revision "$ACTIVE_REVISION_ID" '
  .revision_id == $revision and
  (.cutovers | length > 0) and
  all(.cutovers[]; .cutover_id and .tenant_id and .company_id)
' w-lot-a-p5-active-cutovers.json

docker compose -f docker-compose.staging.yml cp \
  w-lot-a-p5-active-cutovers.json \
  api:/tmp/w-lot-a-p5-active-cutovers.json

docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:w-lot-a-preflight \
  --all-tenants \
  --artifact-sha="$P5_SHA" \
  --expected-revision="$ACTIVE_REVISION_ID" \
  --expected-flags-fingerprint="$ACTIVE_FLAGS_FINGERPRINT" \
  --expected-cutover-manifest=/tmp/w-lot-a-p5-active-cutovers.json \
  --fail-on-drift \
  --fail-on-unresolved \
  --fail-on-stale \
  --format=json
```

Only after that succeeds, generate/capture the activation operation and activate the captured manifest:

```bash
ACTIVATE_OPERATION_JSON="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php -r 'require "vendor/autoload.php"; echo json_encode(["operation_id"=>(string)Illuminate\Support\Str::uuid()]);'
)"
ACTIVATE_OPERATION="$(printf '%s' "$ACTIVATE_OPERATION_JSON" | jq -er '.operation_id')"

ACTIVATION_RESULT="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php artisan inventory:w-lot-a-cutover activate "$ACTIVE_REVISION_ID" \
    --all-tenants \
    --operation="$ACTIVATE_OPERATION" \
    --actor="${DEPLOY_ACTOR:?}" \
    --manifest=/tmp/w-lot-a-p5-active-cutovers.json \
    --format=json
)"
printf '%s\n' "$ACTIVATION_RESULT" > w-lot-a-p5-activation.json
printf '%s' "$ACTIVATION_RESULT" |
  jq -e --arg revision "$ACTIVE_REVISION_ID" '
    .revision_id == $revision and
    .failed == 0 and
    all(.cutovers[]; .state == "active")
  '
```

Run the common explicit web deployment, then final smoke:

```bash
curl -fsS "$STAGING_API_URL/api/v1/batches/health" \
  -H "Authorization: Bearer ${STAGING_ADMIN_TOKEN:?}" |
  jq -e --arg revision "$ACTIVE_REVISION_ID" --arg census "$CENSUS_RUN_ID" '
    .data.active_revision_id == $revision and
    .data.latest_census_run_id == $census and
    .data.entitlement_unresolved == 0 and
    .data.drifted_companies == 0 and
    .data.flags.wlot_a_recall_holds == true and
    .data.flags.wlot_a_lot_counts == true
  '
```

Rollback point P5:

1. Capture a rollback operation ID from command output.
2. Roll back active cutovers before deploying old code:

```bash
ROLLBACK_OPERATION_JSON="$(
  docker compose -f docker-compose.staging.yml exec -T api \
    php -r 'require "vendor/autoload.php"; echo json_encode(["operation_id"=>(string)Illuminate\Support\Str::uuid()]);'
)"
ROLLBACK_OPERATION="$(printf '%s' "$ROLLBACK_OPERATION_JSON" | jq -er '.operation_id')"

docker compose -f docker-compose.staging.yml exec -T api \
  php artisan inventory:w-lot-a-cutover rollback "$ACTIVE_REVISION_ID" \
  --all-tenants \
  --operation="$ROLLBACK_OPERATION" \
  --expected-active-revision="$ACTIVE_REVISION_ID" \
  --actor="${DEPLOY_ACTOR:?}" \
  --manifest=/tmp/w-lot-a-p5-active-cutovers.json \
  --format=json
```

3. Revert or redeploy P4 so all seven compose defaults return to false.
4. Force-recreate API, worker, scheduler and websocket.
5. Explicitly deploy the P4 web image and repeat deployment-ID, asset-hash, fingerprint and Playwright verification.
6. Retain migrations, requests, transitions, corrections, identifications, observations, reconciliations, provenance and census history.
7. Never automatically reverse stock movements, reconstruct DEFAULT or delete audit evidence.

## Dispatch order

1. T1 — rollout/config/glossary/fingerprint.
2. T2 — entitlement fence.
3. T3 — exact quantity, mutation and eligibility spine.
4. T4 — recall/permissions/deactivation.
5. T5 — identification first, then identity freeze/correction.
6. T6 — numeric sequence and observations.
7. T7 — reconciliation and reattribution.
8. T8 — provenance.
9. T9 — durable census.
10. T10 — generated contracts, live CI and release evidence.

Schema work is committed together in Push 2, but no task may use its tables until its own red-first packet is green. T5 identification must be green before identity freezing is activated. T9 scheduling must not activate before its live PostgreSQL class passes in `backend-test-pgsql`.

## Final verification checklist

- [ ] Candidate SHA is a clean descendant of `origin/dev`; no force push.
- [ ] Ten tasks or fewer; every task has exact files, signature, red assertion, command/lane, reviewer and rollback.
- [ ] Q10–Q13 remain verbatim OPEN.
- [ ] Recall schema contains only `requested|recalled`; no release/reject branch exists anywhere.
- [ ] Manager lacks `batches.recall`; general manager’s only delta is `batches.recall` plus `treasury.manage_all_locations`; health remains admin-only.
- [ ] Requested hold blocks sale and transfer, including POS server FEFO and both ends of `StockTransferService`, under the shared product lock.
- [ ] Positive/reserved stock blocks batch deactivation.
- [ ] No float accessor/writer or JS-number lot calculation remains.
- [ ] `BatchResource` and every reservation/transfer consumer expose scale-4 strings.
- [ ] Entitlement state names exactly match spec v4.
- [ ] Central advisory lock remains held until tenant write commit.
- [ ] Both entitlement race directions pass on PostgreSQL.
- [ ] DEFAULT identification precedes used-lot freeze activation.
- [ ] Identification conserves aggregate, WAC and GL and is exact-retry idempotent.
- [ ] No UUID ordering remains in count replay.
- [ ] Every new stock movement has a positive monotonic company sequence.
- [ ] Count request enforces the explicit five-minute device ordering envelope and retains the existing 300-second skew flag.
- [ ] Explicit-zero observation is distinct from missing observation.
- [ ] Reattribution emits one flat aggregate movement, offsetting lot effects and zero journal entries.
- [ ] All three producer families store provenance.
- [ ] W-LOT-A creates zero `operator_captured` rows.
- [ ] Traceability, returns, transfer detail/export and batch export display stored provenance.
- [ ] Census cohort is entitled company × batch-tracked product.
- [ ] `not_entitled` is non-alerting; `entitlement_unresolved`, drift and stale runs alert.
- [ ] Scheduler is 03:20, foreground, `withoutOverlapping(180)`, with stale recovery.
- [ ] Inventory writer manifest and GL-only-through-buffer guards pass.
- [ ] Every applicable task’s second-company, second-location and rerun assertions pass.
- [ ] Generated DTOs and permission map have no diff after regeneration.
- [ ] W-LOT-A PostgreSQL classes run unconditionally in live `backend-test-pgsql`.
- [ ] `pnpm build`, `pnpm lint`, `pnpm typecheck`, `pnpm test`, `composer test`, PHPStan and Pint pass.
- [ ] Push 2 central and every tenant migration are captured and verified with zero failures.
- [ ] Every rollout variable is forwarded through compose and printed/validated by entrypoint in API, worker, scheduler and websocket.
- [ ] Every generated revision, operation, cutover, census and Dokploy deployment ID is captured from command output before use.
- [ ] All seven rollout flags switch atomically and match the active immutable revision before cutover activation.
- [ ] Every web-changing push has a completed explicit Dokploy deployment, changed asset hash, valid feature fingerprint and green Playwright smoke.
- [ ] Each push has a tested rollback point that retains additive history and performs no automatic stock repair.
