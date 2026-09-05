<!-- Authored by Codex CLI (gpt-5.6-sol, high effort, read-only) on 2026-09-06 from the W-LOT brief + plan gate r1; filed verbatim by the orchestrator. Status: rev 1, awaiting plan gate r2. -->
# W-LOT Batch Management A-to-Z — Execution Plan

**Date:** 2026-09-06  
**Dispatch base:** local `dev` at `dbfd640e7289d606af4a658b2fcaa67c5d1c6584`  
**Package:** one W-LOT package, divided into independently reviewed tasks  
**Implementation branch:** `lane/w-lot-batch-a-to-z`  
**PostgreSQL lane:** private PostgreSQL 16 instance on a free port `>=5453`, database `autoerp_test_wlot`  
**Device migration baseline:** SQLite v67; this plan reserves v68 and v69  
**Promotion model:** every push auto-deploys to staging; all schemas therefore land additively and all new behavior remains disabled until its explicit activation gate passes  
**Authority:** owner rulings in `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md`, then accepted spec v4 in `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md`. This plan keeps recall rejection/release open as explicitly required by the dispatch.

---

## 1. Non-negotiable outcome

This package closes W-LOT L1–L9 without changing the sealed `SALE_RECEIPT` bytes:

- POS-core receipt, aggregate stock, payment, and accounting projection always remain active.
- The lot projection arm uses an explicit company-entitlement result: `enabled`, `not_entitled`, or `unresolved`.
- Unknown entitlement never becomes `false`; it creates durable blocked lot work.
- Batch tracking remains per product through `products.requires_batch_tracking`.
- A branch manager may request a recall, which immediately holds that lot at the requesting location.
- A `general_manager` or administrator may execute the request into a company-wide hold.
- Rejecting a request or releasing a hold remains conditional on the open owner decision in §4.
- Used lot identity is frozen only when the correction and identification replacements are ready in the same activation.
- Lot identification and lot-grain counting conserve aggregate quantity and WAC. Pure reattribution creates one flat aggregate justification, offsetting lot legs, and no journal entry.
- Lot quantities are decimal strings throughout PHP, JSON, generated TypeScript, web, and POS code.
- POS lot guidance is refreshed before shift opening. Failure disables the lot-specific arm without disabling ordinary POS-core selling.
- Operator-captured lot evidence is written atomically with the local receipt but outside the fiscal payload/hash.
- Evidence and fiscal events may reach the server in either order.
- Late or missing evidence is represented by `fiscal_projection_lot_obligations`; it never causes a second aggregate decrement.
- Existing provenance is backfilled only as `unknown`; no migration invents operator capture, expiry, or FEFO certainty.
- The existing lot-drift census gains scheduled, durable health reporting only after live-PostgreSQL validation.

---

## 2. Gate-r1 closure map

| Gate finding | Closure in this plan |
|---|---|
| BLOCKER 1 — mechanical plan gate | §§3, 5, 8, 10–24 provide the benchmark matrix, exact Vocabulary line, glossary changes, named red tests, exact files, schemas, contracts, and gates. |
| BLOCKER 2 — recall escalation and false role assumption | §§4, 6, 12–14 define membership invariants, schema, transitions, routes, permissions, location custody, both transfer paths, and targeted role migration. |
| BLOCKER 3 — POS core versus lot arm and late evidence | §§7, 18–22 keep `requiresModule()` null, introduce the tri-state adapter, child obligations, and one lot-only recovery service. |
| BLOCKER 4 — lock census too late | §9 and Task 1 precede every new lot writer. Tasks 8 and 12 cannot start until the census gate is accepted. |
| BLOCKER 5 — unsafe automatic deployment | §25 gives migrations, capability flags, preflights, ordering, rollback, and restore rehearsal. |
| MAJOR 6 — freeze before replacement | Tasks 7–9 implement L9 and correction before one shared activation enables the freeze. |
| MAJOR 7 — cross-layer floats | Task 6 covers models, services, resources, generated DTOs, every batch web consumer, and device contracts. |
| MAJOR 8 — incomplete L9 contract | §§8.3, 16–17 and Tasks 7–9 define full storage, permissions, optimistic version, validation, flat movement, UI, and PG tests. |
| MAJOR 9 — unsafe counting reattribution | §§8.4 and 19–20 define the one-flat-row path, two lot legs, legacy disposition, provisional data, zero GL, and unchanged WAC. |
| MAJOR 10 — provenance and health underspecified | §§8.5–8.6 and Tasks 13–15 define three-store provenance, conservative backfill, durable health runs, retention, alerts, scheduler, and UI. |
| MAJOR 11 — refresh runs after shift opening | Task 17 moves the refresh ahead of both v3 and legacy opening paths and defines failure behavior. |
| MAJOR 12 — evidence outbox underspecified | §§8.8, 21 and Tasks 19–21 define records, leases, retries, fingerprints, atomic placement, retention, and either-arrival-order handling. |
| MAJOR 13 — L8/L9 completion mismatch | §27 maps every L1–L9 requirement; L8 core is implemented, with only override/no-oversell policy deferred as `WLOT-FOLLOWUP-01`. |
| MINOR 14 — citation hygiene | §3 cites exact HEAD paths and linked benchmark sources. |
| MINOR 15 — oversized S5/S6 | Counting and provenance are split into schema, writer, API/UI, concurrency, and operational activation tasks, each with its own reviewer gate. |

---

## 3. Industry baseline (benchmark-first — convention 10)

**Flow:** lot identity, custody, counting, recall, traceability, and offline POS capture.  
**Reference systems:** Odoo 19, Odoo 18 French POS certification source, ERPNext v15/current documentation checked 2026-09-06, and the current Dolibarr Lot/Serial module documentation.

Sources:

- [Odoo 19 — Lot numbers](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/product_management/product_tracking/lots.html)
- [Odoo 19 — FEFO removal](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/shipping_receiving/removal_strategies/fefo.html)
- [Odoo 19 — Inventory adjustments/counting](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/warehouses_storage/inventory_management/count_products.html)
- [Odoo 18 — POS lot/serial capture](https://www.odoo.com/documentation/18.0/applications/sales/point_of_sale/shop/serial_numbers.html)
- [Odoo 18 — French POS hash field source, line 727](https://github.com/odoo/odoo/blob/18.0/addons/l10n_fr_pos_cert/models/pos.py#L727)
- [ERPNext — Batch](https://docs.frappe.io/erpnext/batch)
- [ERPNext — Stock Reconciliation](https://docs.frappe.io/erpnext/stock-reconciliation)
- [ERPNext — Inline Serial and Batch Bundle](https://docs.frappe.io/erpnext/use-inline-serial-batch-editor)
- [Dolibarr — Lot / Serial module](https://wiki.dolibarr.org/index.php/Module_Lot_/_Serial)
- [Dolibarr — Inventories](https://wiki.dolibarr.org/index.php/Inventories)

“NV” means the exact guarantee was not verified in the cited official documentation and is not used as affirmative evidence.

| ID | Guarantee the baseline gives the user | Odoo | ERPNext | Dolibarr or NV | AutoERP at dispatch HEAD (`dbfd640e7289`) | Gap | Decision |
|---|---|---|---|---|---|---|---|
| B1 | A lot is an identifiable stock cohort attached to a product; the same human lot number does not collapse unrelated company/product stock. | Lot belongs to a product; lot adjustment is product-scoped. | Batch is attached to an item. | First sighting creates a product-lot record; later movements reuse it. | `product_batches` carries `company_id`, `product_id`, and `batch_number`; current unique includes company/product (`apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:18-46`). | Existing keys are adequate; convention-09 classification is missing. | **ALREADY** — retain keys; add manifest classification and second-company proof. |
| B2 | Existing stock can be assigned or split into identified lots without changing total stock. | Inventory adjustment assigns lot numbers to existing stock. | Stock Reconciliation supports serial/batch-grain quantities. | Exact split workflow NV. | Openings can create `DEFAULT`, but there is no identification operation; ordinary identity remains editable (`apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:19-24`). | No conserved, audited split/correction workflow. | **MATCH** — L9 `LotIdentificationService`, flat justification, offsetting lot legs. |
| B3 | Identity fields already used in movements cannot be silently rewritten or merged. | Lot identity is tracked through stock moves; reassignment is an inventory operation. | Batch references survive stock transactions; exact edit prohibition NV. | A conflicting sell-by/eat-by date for an existing lot is refused. | `UpdateBatchRequest` permits number and dates after use (`…/UpdateBatchRequest.php:19-24`). | History can be rewritten. | **MATCH** — freeze used identity; allow only permissioned, versioned corrections with history. |
| B4 | Removing/deactivating a lot cannot silently erase stock or reservations. | Stock is corrected/moved through inventory operations. | Reconciliation or stock entries account for remaining quantity. | Stock movement requires a lot when lot tracking is active. | DELETE is exposed without route permission (`apps/api/app/Modules/BatchExpiry/Presentation/routes.php:21-31`); controller deactivates without a formal disposition contract. | No verified empty/written-off/transferred disposition. | **MATCH** — require zero stock and reservations plus a verified disposition. |
| B5 | Repeating the same correction/reconciliation does not double stock. | Validated inventory adjustments create one accounted operation. | Reconciliation submission is document-based. | Exact API idempotency NV. | No L9 operation or company-scoped operation UUID exists. | Re-run behavior is undefined. | **MATCH** — company-scoped operation UUID, content replay, and conflict response. |
| B6 | Counts can distinguish separate physical lots, including an explicitly counted zero. | Inventory count lines include lot/serial grain. | Stock Reconciliation can reconcile all batches and add/consume individual batches. | Inventory feature documented; exact lot-grain behavior NV. | `inventory_counting_items` stores product/variant/location totals only (`apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php:79-105`). | Missing child observations; zero and missing cannot be distinguished by lot. | **MATCH** — L4 child lot observations and derived parent totals. |
| B7 | Expiry-aware outbound selection uses FEFO and does not present an estimate as operator evidence. | FEFO is the documented removal strategy for expiry-tracked products. | Batch expiry exists; exact POS FEFO guarantee NV. | Dates are attached to lots; exact FEFO selection NV. | FEFO orders by dated-before-undated, expiry, creation and uses `FOR UPDATE … SKIP LOCKED` (`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:260-272`), but allocations have no provenance. | Selection is sound, evidence label is not. | **ALREADY** for FEFO; **MATCH** for explicit `system_fefo_estimate` provenance. |
| B8 | Lot screens and actions are permissioned and respect the operator’s company/location custody. | Inventory permissions govern lot operations. | Warehouse restrictions constrain stock transactions. | Module activation and stock permissions exist; exact location model NV. | Batch group has module/auth middleware, but most routes lack action permission (`apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12-48`); several reads return all company locations. | Per-action and location scope are incomplete. | **MATCH** — L1 route matrix, location-scoped readers, and atomic role/membership policy. |
| B9 | A recall or hold prevents the affected lot from being sold or transferred, with central visibility and an audit trail. | Lots can be located through traceability; exact two-step approval NV. | Disabled batches block new transactions; exact branch escalation NV. | Lot tracking exists; recall workflow NV. | One global `is_recalled` flag is toggled directly; the route is unpermissioned (`…/routes.php:29`), and no request/hold document exists. | No branch request, central execution, idempotency, or transition history. | **DIVERGE — owner-ruled RD2:** branch request/local hold, then general-manager company-wide hold. |
| B10 | Forward/backward trace identifies which movement allocated a lot and whether the allocation was captured or estimated. | Lot traceability follows stock operations. | Batch-wise transaction history is available. | Lot numbers are retained across stock movements. | Trace combines document and POS rows (`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:60-88`) but emits no provenance. | Three producers have no common provenance contract. | **MATCH** — annotate all three producers and show provenance on the existing trace surface. |
| B11 | POS can display and capture lot/serial information without silently changing certified fiscal bytes. | POS supports scanning/typing lot/serial values. | POS invoice items can carry batch information. | TakePOS exact behavior NV. | Reserved `NearExpirySlot` returns `null` (`apps/pos/src/components/organisms/ProductGrid/NearExpirySlot.tsx:1-26`); receipt payload has no lot field. Odoo’s French hash list likewise omits lots. | No guidance, editable capture, or durable sidecar evidence. | **MATCH** — L7/L8 sidecar evidence outside `SALE_RECEIPT`; no fiscal version bump. |
| B12 | Offline capture survives retries, crashes, and either server arrival order. | Exact offline sidecar protocol NV. | Exact offline sidecar protocol NV. | NV. | Fiscal append is caller-transactional, but there is no evidence/outbox table. | Receipt can survive while evidence is lost or stuck. | **MATCH** — atomic local evidence/outbox, lease recovery, stable ID/fingerprint, idempotent ingress. |
| B13 | Disabling the optional lot capability does not suppress ordinary receipt and aggregate stock projection. | Lot tracking is optional around the core inventory/POS flow. | Batch tracking is item-specific. | Lot module extends stock; exact projection behavior NV. | `PosCoreReceiptProjection::requiresModule()` correctly returns `null` (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:221-224`), but its lot arm checks only the product flag (`…/PosCoreReceiptProjection.php:2016-2027`). | Lot writes can occur without explicit entitlement; changing the parent module requirement would break POS core. | **DIVERGE deliberately:** keep the parent always active and tri-state only the child lot arm. |
| B14 | Drift between aggregate and lot stock is discoverable and stale health is visible. | Inventory adjustments expose discrepancies. | Stock Reconciliation compares physical and book stock. | Inventories reconcile physical and recorded stock. | `LotLedgerDriftCensus` exists, but only manual output is durable; no scheduled health surface exists. | Missed/failed runs and stale “clean” state are invisible. | **MATCH** — retain the read-only command and add a separate durable monitor. |

**Second-of-everything (convention 09):** named tests are in §5. They cover a second company created through `POST /api/v1/companies`, a second `pos_enabled` location, and idempotent re-runs with no duplicated rows or doubled balances.

---

## 4. Owner decisions

| ID | Status | Decision required | Recommendation | Alternative and consequence | Dependent work |
|---|---|---|---|---|---|
| O-LOT-1 | **OPEN — activation blocker only for reject/release** | May a `general_manager` reject a branch recall request? May an already company-held lot be released? Who may do so, and what reason/evidence is mandatory? | Permit `requested → rejected` to `general_manager` and administrator with a non-empty reason and optional evidence reference. Permit `held → released` only to administrator, with non-empty reason and evidence reference. Both transitions remain append-only and never delete the request. | If branch holds must never lift in this lane, omit both routes and leave `requested`/`held` active until a later compensating workflow. If general managers may release company holds, operational recovery is faster but the role gains a high-risk safety action. | Tasks 3–5 implement only `requested → held` unconditionally. Reject/release routes, buttons, enum transitions, and permission grants are conditional and remain disabled until this row is ruled. |
| O-LOT-2 / `WLOT-FOLLOWUP-01` | **OPEN, deferred outside this package’s core L8 delivery** | May a cashier or manager override an unavailable/expired/held lot or oversell a lot while offline? | No override in W-LOT. Captured but invalid/unavailable evidence blocks only the lot obligation; POS core remains accepted. | An override requires a separately designed reservation/no-oversell policy, approval evidence, and offline conflict compensation. Adding it now would silently invent inventory policy. | Tasks 17–22 implement display, capture, durable evidence, and consumption without an override path. |

No implementation task may resolve either row by inference.

---

## 5. Vocabulary (convention 11)

**Vocabulary — Concepts: Lot (batch) (glossary ✅); Lot evidence (glossary ✅ — row expanded in this lane); Lot identification (glossary ✅ — row expanded in this lane); Projection lot obligation (glossary ✅ — row expanded in this lane); Recall request (NEW — glossary row added in this lane); Recall hold (NEW — glossary row added in this lane); Lot count observation (NEW — glossary row added in this lane); Lot eligibility snapshot (NEW — glossary row added in this lane); Lot-ledger census run (NEW — glossary row added in this lane).**

Task 1 modifies `docs/glossary.md` with these exact deltas:

| Canonical term | Definition | Table/module | Canonical operator surface | Permitted synonyms | Primary writer |
|---|---|---|---|---|---|
| Recall request | An append-only branch request that immediately prevents the named lot from being issued at one requesting location until centrally executed or conditionally rejected. | `batch_recall_requests`, `batch_recall_transitions` / BatchExpiry | Existing batch detail → Recall panel | branch recall, branch lot hold request | `BatchRecallWorkflowService::request()` |
| Recall hold | The company-wide held state created when a general manager executes a recall request; compatibility fields on `product_batches` mirror this state but are not a second writer. | Same recall tables; `product_batches.is_recalled` is a compatibility projection | Existing batch detail and recall worklist | company recall, company hold | `BatchRecallWorkflowService::hold()` |
| Lot count observation | A physical quantity observation for one known lot during one count phase; a present `0.0000` is different from no observation. | `inventory_counting_item_lots` / Inventory | Existing inventory-counting detail/reconciliation | batch count line | `InventoryCountingService::submitCount()` |
| Lot eligibility snapshot | A terminal/location-scoped, replace-all cache of server-known lots and the event-inclusion watermark used for POS guidance. It is a read projection, not stock authority. | Server DTO; SQLite `branch_lot_snapshots`, `branch_lot_eligibility` / POS | Existing product tiles/cart/detail drawer | branch lot cache | Server `PosLotEligibilityService`; device `replaceLotEligibilitySnapshot()` |
| Lot-ledger census run | A durable record of one monitored comparison of aggregate stock against summed lots. It records clean, drifted, failed, or stale health without repairing stock. | `lot_ledger_census_runs` / BatchExpiry | Existing Batches page health banner/detail | lot health run | `LotLedgerMonitorService` |
| Lot evidence | Expand the existing row to name server `pos_receipt_line_lot_evidence`, SQLite evidence/outbox, `LotEvidenceIngressService`, and device `recordReceiptLotEvidence()`. | Existing three allocation stores plus server/device evidence tables | Existing trace, receipt, transfer, and POS lot controls | existing synonyms only | Each declared producer writes its own allocation; captured sidecar has the named device/server writer |
| Lot identification | Expand the existing row to add `lot_identification_lines`, `batch_identity_corrections`, optimistic identity version, and `/batches/{uuid}/identify`. | BatchExpiry/Inventory | Existing batch detail | existing synonyms only | `LotIdentificationService`; identity-only history via `BatchIdentityCorrectionService` |
| Projection lot obligation | Expand the existing row with exact statuses, unique key, reason codes, and `PosReceiptLotProjectionService`. | `fiscal_projection_lot_obligations` / POS | Existing projection detail plus batch health | existing synonyms only | `PosReceiptLotProjectionService` |

No new list, tile, import type, or standalone catalogue is introduced. Batch detail remains the identity/recall/identification surface; inventory counting remains the count surface; batch trace remains the provenance surface.

---

## 6. Permissions, routes, and custody

### 6.1 Permission matrix

| Route/action | Module gate | Permission | Location rule |
|---|---|---|---|
| `GET /batches`, `/batches/{uuid}`, `/batches/expiring`, `/batches/expired`, `/batches/{uuid}/stock`, `/products/{productId}/batch-stock` | `BatchExpiry` | `batches.view` | Restrict lot rows and stock aggregates to `LocationContext::allowedLocationIds()`; `null` means explicitly unrestricted. |
| `GET /pos/products/{productId}/batches` | `BatchExpiry` | `batches.view` plus `pos.operate_terminal` | Derive location from the persisted terminal for device callers; do not trust an arbitrary location. |
| `POST /batches` | `BatchExpiry` | `batches.create` | Product/company validation; initial location, when supplied, must be accessible. |
| `PATCH /batches/{uuid}` | `BatchExpiry` | `batches.update` | Used identity is frozen after paired activation; notes remain editable. |
| `DELETE /batches/{uuid}` | `BatchExpiry` | `batches.delete` | Reject unless all visible and company-wide stock/reservation checks prove zero and disposition is verified. |
| `POST /batches/{uuid}/transfer` | `BatchExpiry` | `batches.update` | Both locations must be accessible; recall policy enforced again in the service. |
| Write-off and reversal routes | `BatchExpiry` | `batches.write-off` | Source location must be accessible. |
| Forward/backward trace routes | `BatchExpiry` | `batches.traceability` | Filter document, receipt, transfer, and location rows by custody. |
| `POST /batches/{uuid}/recall-requests` | `BatchExpiry` | `batches.recall.request` | `request_location_id` must be a non-null allowed location. |
| `GET /batch-recall-requests` | `BatchExpiry` | `batches.recall` | General managers/admins see company-wide worklist; branch managers see only requests in their allowed locations. |
| `POST /batch-recall-requests/{uuid}/hold` | `BatchExpiry` | `batches.recall` | Explicit unrestricted membership required. |
| Conditional reject/release routes | `BatchExpiry` | New permission only after O-LOT-1 | Conditional per §4. |
| `POST /batches/{uuid}/corrections` | `BatchExpiry` | `batches.correct-identity` | Batch company and any evidence reference must be in current company; optimistic version required. |
| `POST /batches/{uuid}/identify` | `BatchExpiry` | `batches.identify` | Source and targets must share company/product/variant/location. |
| `GET /lot-ledger-health` | `BatchExpiry` | `batches.health.view` | Company-wide only for general manager/admin. |
| `GET /pos/lot-eligibility` | `BatchExpiry` | `pos.operate_terminal` | Location is derived from the current-company terminal and checked against membership. |
| `POST /pos/lot-evidence/batch` | Durability exception: no live module middleware | `pos.operate_terminal` | Historical evidence is accepted after entitlement revocation only when terminal/company/location and previously advertised capability revision validate. It does not itself apply stock. |

The evidence ingress is not an operator lot action. Keeping it available is necessary to drain already-authored evidence after a module or rollout flag is disabled. Entitlement is checked later by the lot obligation worker.

### 6.2 Canonical role grants

- `admin`: all W-LOT permissions.
- `general_manager`: canonical manager grants plus `batches.recall`, `batches.correct-identity`, `batches.identify`, `batches.health.view`, and the owner-ruled cross-location grants.
- `manager`: `batches.view`, create/update/delete/write-off/traceability, and `batches.recall.request`; no company-wide recall execution.
- `cashier`: retain `batches.view`; POS authoring is additionally capability-gated.
- `operator` and `viewer`: add `batches.view` as required by the accepted spec; no mutation grants.
- Custom roles are untouched.

`RoleMembershipScopeService::assign()` is the only role/membership writer for the affected APIs:

```php
public function assign(
    User $target,
    Company $company,
    string $roleName,
    ?array $allowedLocationIds,
    User $actor,
    bool $scopeWasExplicitlySupplied,
): void;
```

Invariants:

- `manager` requires an explicitly supplied, non-empty location list.
- `general_manager` requires an explicitly supplied `null` list.
- An omitted scope is refused for either role.
- Locations must belong to the target company.
- Role and membership update occur in one transaction.
- Existing unrestricted managers are reported, never assigned guessed branches.
- Activation is blocked until the readiness command reports zero invalid manager/general-manager memberships and at least one eligible central actor.

---

## 7. Entitlement and rollout contracts

### 7.1 Tri-state entitlement

Create:

```php
enum CompanyEntitlementState: string
{
    case Enabled = 'enabled';
    case NotEntitled = 'not_entitled';
    case Unresolved = 'unresolved';
}
```

```php
final readonly class CompanyEntitlementDecision
{
    public function __construct(
        public CompanyEntitlementState $state,
        public string $revision,
        public string $reasonCode,
    ) {}
}
```

```php
interface CompanyEntitlementResolver
{
    public function resolve(
        string $module,
        string $tenantId,
        string $companyId,
    ): CompanyEntitlementDecision;
}
```

Rules:

- Validate that tenancy is initialized for `tenantId`.
- Validate that `companyId` exists in that tenant database.
- Read effective modules from the central tenant/vertical configuration.
- Return `not_entitled` only after an authoritative, successful read.
- Missing tenant, wrong initialized tenant, missing company, malformed data, database/cache failure, or revision failure returns `unresolved`.
- The lot arm never uses `DefaultModuleActivationResolver::isActive()` because that method currently discards `companyId` and coerces lookup failures to false (`apps/api/app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php:50-74`).
- `PosCoreReceiptProjection::requiresModule()` remains `null`.
- Every queued test clears `CompanyContext`; event fields and initialized tenancy are the worker authority.

### 7.2 Revision fencing

Add central `tenants.module_config_revision BIGINT NOT NULL DEFAULT 1`.

Effective revision is:

```text
<module_config_revision>:<sha256(canonical default_modules + enabled_extras + vertical override)>
```

- `TenantObserver` increments the revision when `vertical` or `enabled_extras` changes.
- `VerticalConfigController` increments revisions for affected tenants before invalidating caches.
- `CompanyConfigService` keys cached effective configuration by the full revision.
- A stale in-flight read can repopulate only an obsolete revision key.
- Config-file deployment changes alter the hash without inventing a database entitlement.
- The current bool resolver remains for unrelated projectors; only W-LOT uses the tri-state contract.

### 7.3 Kill switches and advertised capabilities

Add `apps/api/config/lot_rollout.php`, all defaults `false`:

```text
recall_workflow
identity_correction
lot_counting
provenance
census_scheduler
pos_guidance
pos_evidence_authoring
pos_evidence_projection
```

Effective behavior requires both the corresponding global kill switch and explicit entitlement. Device authoring additionally requires persisted terminal flags:

```text
pos_terminals.lot_guidance_enabled
pos_terminals.lot_evidence_authoring_enabled
pos_terminals.lot_capability_revision
pos_terminals.lot_evidence_authoring_acknowledged_at
```

The global flags are emergency rollout boundaries, not an editable business configuration surface. Terminal capability state is the single advertised device activation record.

---

## 8. Schema contract

All enum-like columns use PHP backed enums plus database `CHECK` constraints, following repository convention. Quantities are `DECIMAL(15,4)` and API/device values are numeric strings.

### 8.1 Recall

`batch_recall_requests`:

- `id UUID PRIMARY KEY`
- `tenant_id UUID NOT NULL`
- `company_id UUID NOT NULL`
- `batch_id BIGINT NOT NULL`
- `request_location_id UUID NOT NULL`
- `operation_uuid UUID NOT NULL`
- `status VARCHAR(16) NOT NULL CHECK IN ('requested','held','rejected','released')`
- `request_reason TEXT NOT NULL`
- `requested_by_user_id UUID NOT NULL`
- `requested_at TIMESTAMP NOT NULL`
- `held_by_user_id UUID NULL`, `held_at TIMESTAMP NULL`, `hold_reason TEXT NULL`
- `rejected_by_user_id UUID NULL`, `rejected_at TIMESTAMP NULL`, `rejection_reason TEXT NULL`
- `released_by_user_id UUID NULL`, `released_at TIMESTAMP NULL`, `release_reason TEXT NULL`
- `evidence_reference VARCHAR(255) NULL`
- timestamps
- unique `(tenant_id, company_id, operation_uuid)`
- PostgreSQL partial unique `(tenant_id, company_id, batch_id, request_location_id) WHERE status = 'requested'`
- PostgreSQL partial unique `(tenant_id, company_id, batch_id) WHERE status = 'held'`

`batch_recall_transitions`:

- UUID PK; tenant/company/request FKs
- `operation_uuid UUID`
- `from_status VARCHAR(16) NULL`
- `to_status VARCHAR(16) NOT NULL`
- actor, reason, evidence reference, timestamp
- unique `(tenant_id, company_id, operation_uuid)`

### 8.2 Identity correction

Add to `product_batches`:

- `identity_version BIGINT NOT NULL DEFAULT 1`

`batch_identity_corrections`:

- UUID PK; tenant/company/batch
- company-scoped `operation_uuid`
- source/target version
- old/new batch number, manufacturing date, expiry date
- reason, evidence reference, actor, timestamp
- unique `(tenant_id, company_id, operation_uuid)`

### 8.3 Identification

`lot_identifications`:

- UUID PK; tenant/company/product/variant/location/source batch
- `operation_uuid UUID`
- `source_identity_version BIGINT`
- `total_quantity DECIMAL(15,4)`
- reason, evidence reference, actor
- `stock_movement_id UUID NOT NULL`
- `status VARCHAR(16) CHECK IN ('applied')`
- created timestamp; no mutable business columns
- unique `(tenant_id, company_id, operation_uuid)`

`lot_identification_lines`:

- UUID PK; identification FK
- target batch FK
- captured target batch number, optional manufacturing/expiry dates
- `quantity DECIMAL(15,4)`
- unique `(identification_id, target_batch_id)`

### 8.4 Lot-grain counting

Add to `inventory_countings`:

- `lot_grain_version SMALLINT NOT NULL DEFAULT 1 CHECK IN (1,2)`

`inventory_counting_item_lots`:

- UUID PK; tenant/company/counting item/batch
- `count_1_qty`, `count_2_qty`, `count_3_qty`, `final_qty` nullable decimal strings
- corresponding server/device/estimated timestamps and movement markers
- notes and resolution metadata
- `version BIGINT NOT NULL DEFAULT 1`
- unique `(tenant_id, company_id, counting_item_id, batch_id)`

Existing rows and active counts remain version 1. No child rows are fabricated. Counts created after activation use version 2 for batch-tracked products.

### 8.5 Provenance

Add `lot_provenance VARCHAR(32) NOT NULL DEFAULT 'unknown'` with check:

```text
operator_captured | system_fefo_estimate | unknown
```

to:

- `pos_receipt_line_batch_allocations`
- `document_lines`
- `stock_transfer_line_batch_allocations`

Add nullable `lot_evidence_id UUID` to POS allocations.

Migration behavior:

- Every legacy row remains `unknown`.
- No legacy document row is labelled captured.
- No legacy allocation is labelled FEFO merely because it resembles current FEFO output.
- New writers must pass provenance explicitly; an architecture test rejects omitted production writes.

### 8.6 Census health

`lot_ledger_census_runs`:

- UUID PK; tenant/company
- `trigger VARCHAR(16) CHECK IN ('manual','schedule')`
- `status VARCHAR(16) CHECK IN ('running','clean','drifted','failed')`
- started/completed timestamps
- tuple count, drifted tuple count
- net and absolute drift `DECIMAL(15,4)`
- error code/message nullable
- entitlement revision
- created timestamp
- indexes `(company_id, completed_at)` and `(status, completed_at)`

Retention: keep the newest run per company, all failed/drifted runs for 365 days, and clean runs for 90 days. Staleness is derived when no successful completion exists within 26 hours; it is not stored as a mutable status.

### 8.7 Terminal capabilities

Add to `pos_terminals`:

- `lot_guidance_enabled BOOLEAN NOT NULL DEFAULT FALSE`
- `lot_evidence_authoring_enabled BOOLEAN NOT NULL DEFAULT FALSE`
- `lot_capability_revision BIGINT NOT NULL DEFAULT 0`
- `lot_evidence_authoring_acknowledged_at TIMESTAMP NULL`

The capability command increments the revision whenever either flag changes.

### 8.8 Server evidence

Add to `fiscal_events`:

- `lot_evidence_expected BOOLEAN NOT NULL DEFAULT FALSE`
- `lot_capability_revision BIGINT NULL`

These are unsealed ingress-envelope facts. Legacy events default to false.

`pos_receipt_line_lot_evidence`:

- `id UUID PRIMARY KEY` — device-generated stable evidence ID
- tenant/company/terminal/location/event IDs
- `fiscal_event_hash CHAR(64)`
- `canonical_line_key VARCHAR(191)`
- product and nullable variant
- `lot_operation VARCHAR(16) CHECK IN ('sale','refund')`
- batch UUID, nullable resolved batch ID, captured batch number
- `quantity DECIMAL(15,4)`
- provenance constrained to `operator_captured`
- cache revision, capability revision, selected-at-device
- `content_sha256 CHAR(64)`
- `match_status VARCHAR(24) CHECK IN ('awaiting_event','matched','rejected')`
- nullable receipt line, rejection code, timestamps
- unique `(tenant_id, company_id, fiscal_event_id, canonical_line_key, lot_operation, batch_uuid)`
- indexes for event/status and terminal/status

Same ID plus the same server-recomputed fingerprint returns `already_exists`; same ID plus different content returns `409 LOT_EVIDENCE_CONTENT_CONFLICT`.

### 8.9 Child lot obligation

`fiscal_projection_lot_obligations`:

- UUID PK
- tenant/company/fiscal event
- `canonical_line_key VARCHAR(191)`
- `lot_operation VARCHAR(16) CHECK IN ('sale','refund')`
- receipt and nullable receipt-line IDs
- product, nullable variant, location
- `required_quantity DECIMAL(15,4)`
- `status VARCHAR(16) CHECK IN ('pending','blocked','applied')`
- reason code, entitlement revision, capability revision
- attempt count, next-attempt timestamp, last-attempt timestamp, applied timestamp
- timestamps
- exact unique `(tenant_id, company_id, fiscal_event_id, canonical_line_key, lot_operation)`

The parent `ProjectionStatus` enum remains unchanged. A child blocked lot arm must not dead-letter or revert an applied POS-core receipt.

### 8.10 Device SQLite v68 and v69

**v68 — `add_branch_lot_eligibility`:**

`branch_lot_snapshots`:

- tenant/company/terminal/location composite primary key
- entitlement state/revision
- capability revision
- snapshot revision and server `as_of`
- `fiscal_acknowledged_through_sequence`
- `lot_effects_included_through_sequence`
- `refreshed_at` stored with `toSqliteUtc()`

`branch_lot_eligibility`:

- tenant/company/terminal/location/product
- `variant_key TEXT NOT NULL` using `''` only as the SQLite key encoding for null
- batch ID/UUID/number; nullable expiry
- quantity, reserved, available as decimal text
- active, recalled, and hold scope
- snapshot revision and source update timestamp
- composite primary key through batch UUID
- indexes on product/variant, expiry, and batch UUID

One transaction replaces the snapshot and all rows for its exact scope. No delete occurs until all pages are fetched.

**v69 — `add_pos_receipt_lot_evidence_outbox`:**

Local `pos_receipt_line_lot_evidence` mirrors stable evidence fields and content hash.

`lot_evidence_outbox`:

- `evidence_id TEXT PRIMARY KEY`
- `status TEXT CHECK IN ('pending','syncing','synced','failed')`
- attempt count, next attempt, lease timestamp, last error, synced timestamp
- created/updated timestamps

On startup, `syncing` rows whose lease is older than five minutes return to `pending`. `failed` is retryable after its backoff. Synced rows are retained for 90 days and deleted only after server acknowledgement.

---

## 9. Pre-writer lock and writer census

Task 1 must commit `docs/architecture/w-lot-lock-and-writer-census.md` and a corresponding architecture ratchet before Tasks 8 or 12 introduce a writer.

### 9.1 Canonical lock order

1. Lifecycle/document header where applicable: recall request, counting, transfer, or identification document.
2. `ProductCostLock::acquire(tenant, company, sorted product IDs)`; its name is retained for compatibility, but its contract expands to all aggregate-plus-lot writers.
3. `stock_levels` rows ordered by product, variant-null ordering, and location.
4. `inventory_batch_stock` rows ordered by batch ID and location.
5. Active reservations ordered by ID.
6. Product row last, only where WAC/cost changes.
7. Enqueue GL work while locks are held; flush at the root transaction tail. No GL path may acquire a product inventory lock after taking a company GL lock.
8. External calls and event publication occur only after commit.

FEFO retains `FOR UPDATE OF ibs SKIP LOCKED`, but every in-repository lot writer must first hold the same product advisory lock. Consequently, identification/count/transfer cannot cause FEFO to skip the earliest lot. A skipped row from an unclassified external writer becomes an explicit shortfall/blocked obligation, never an unrecorded success.

### 9.2 Writer inventory

| Writer/entry point | Aggregate writer | Lot writer | Transaction owner | Required order/action |
|---|---|---|---|---|
| `WeightedAverageCostService` purchase/return/cost adjustment | Direct `stock_levels` | None | Service | Existing advisory → all stock rows → product-last contract retained. |
| `StockAdjustmentService` receive/issue/adjust/transfer/count replay | Direct | Delegates or writes lot legs | Service | Change pure `issue()` from stock-row-first to advisory-first; preserve re-entrant calls. |
| `GoodsReceiptService`, `OpeningBalancePostingService`, `ResetOpeningBalanceService`, `StockAdjustmentDocumentService`, `SupplierGoodsReturnNoteService` | Delegate | Delegate | Outer application service | Acquire sorted product locks before any row locks; no new direct lot writer. |
| `StockTransferService` initiate/complete/cancel | Delegate | Allocation validation and delegated lot writes | Service | Transfer header → sorted product advisory locks → source/destination rows → lots. |
| `BatchStockService` receive/issue/transfer/default remainder | None | Direct | Service/nested | Resolve batch ownership, acquire product advisory lock, re-read scoped batch, then lock lot rows. `movement_id` is mandatory for quantity changes. |
| `FEFOInventoryService::consumeBatchesAtomically()` and return restore | None | Direct SQL | Service/nested | Acquire product advisory lock before FEFO query; all lot changes write linked batch movements. |
| `GroupedWriteOffService`, `BatchWriteOffService`, `ReverseWriteOffService` | Delegate | Delegate | Outer service | Product advisory → stock row → sorted lot rows. |
| `RepairPhantomDefaultBatchesCommand` | Flat aggregate justification | Direct repair | Command transaction | Maintenance-window only; add advisory lock ahead of its current lot locks. |
| `ReceiptCreationService`, `ReceiptReturnService`, `ReturnScrapWriteOffService` | Delegate | FEFO/delegate/direct legacy seams | Service | Route through the same advisory and lot writer contract. |
| `PosCoreReceiptProjection` | Existing aggregate receipt path | Replaced by `PosReceiptLotProjectionService` | Projection transaction/savepoint | POS core first records aggregate and child obligation; child service applies/retries lot-only work. |
| Product controller, imports, seeders, reservation default-lot paths | Delegate/default lot | Delegate | Caller | No direct quantity writes; creation-only zero rows are distinguished from stock quantity changes. |
| New `LotIdentificationService` | One flat row | Source negative plus targets positive | Service | Identification header → advisory → stock row → sorted source/targets → reservation check. |
| New lot-count finalize | One aggregate correction or flat row | Per-lot deltas | Counting listener/service | Counting header → all product advisory locks sorted → aggregate/lot rows. |

The architecture ratchet scans production PHP for `stock_levels` and `inventory_batch_stock` writes and fails on a writer absent from the manifest. It also fails if a quantity-changing batch movement can omit `movement_id`.

---

## 10. Named convention-09 tests

| Dimension | File / class / exact case | Lane | Red assertion |
|---|---|---|---|
| Second company through the real creation path | `apps/api/tests/Feature/BatchExpiry/WLotSecondOfEverythingPostgresTest.php` / `WLotSecondOfEverythingPostgresTest::test_second_company_created_through_post_companies_can_reuse_lot_number_and_lists_only_its_own_lots` | PostgreSQL | Create company B with authenticated `POST /api/v1/companies`, create equivalent product/lot identities in A and B, then fail until B’s list contains only B and no company-A row. No raw company insert is permitted in the fixture. |
| Second location | Same file / `test_second_pos_location_receives_identification_count_and_terminal_snapshot_without_falling_back_to_default_location` | PostgreSQL | Create a second location through `POST /api/v1/locations` with `pos_enabled=true`; fail until identification, lot count, and terminal snapshot all reference location B and leave the default location unchanged. |
| Re-run/idempotency | Same file / `test_replaying_operation_and_evidence_ids_returns_already_exists_without_duplicate_rows_or_quantity_change` | PostgreSQL | Execute identification, recall request, count submission, and evidence ingress twice; fail until row counts remain one, stock remains unchanged on replay, and responses say `already_exists`/`already_applied`. |

The class also creates two real lots and two terminals and runs an explicit module-off company/tenant fixture. Cross-tenant negatives remain separate tests so a successful company isolation assertion cannot conceal a tenancy failure.

---

## 11. State machines

### 11.1 Recall

```text
none
  └─ request(location, reason) ──> requested
                                  local hold active immediately
requested
  ├─ general-manager hold ──────> held
  │                               company-wide hold; product_batches mirror updated
  └─ reject ────────────────────> rejected       [O-LOT-1 OPEN]
held
  └─ release ───────────────────> released       [O-LOT-1 OPEN]
```

Duplicate operation UUID with identical content is `already_exists`; different content is `409`. There is no destructive delete.

### 11.2 Server evidence

```text
ingress before event: awaiting_event ── event match ──> matched
ingress after event:  matched directly
invalid immutable mapping: rejected
same id + different content: HTTP 409, existing row unchanged
```

### 11.3 Device evidence outbox

```text
pending ── lease ──> syncing ── acknowledged ──> synced
   ▲                    ├─ transient error ─────> failed ── backoff ──> pending
   └──── stale lease after restart ─────────────┘
```

### 11.4 Lot obligation

```text
pending
  ├─ valid entitled evidence/fallback applied ──> applied
  ├─ entitlement unresolved ────────────────────> blocked(reason)
  ├─ expected evidence missing after grace ─────> blocked(reason)
  └─ invalid/insufficient captured lot ─────────> blocked(reason)

blocked ── entitlement/evidence/data repaired ──> pending ──> applied
```

An explicit `not_entitled` decision produces `applied` with disposition `not_entitled_noop`; it writes no lot leg. It is not the same as unresolved.

### 11.5 Census run

```text
running ── no drift ──> clean
        ├─ drift ─────> drifted
        └─ error ─────> failed
```

“Stale” is derived from the last completed run and cannot overwrite historical status.

---

# Implementation tasks

## Task 1 — Freeze the writer/lock and vocabulary contracts

**Files to create**

- `docs/architecture/w-lot-lock-and-writer-census.md`
- `apps/api/app/Modules/Inventory/Domain/Services/InventoryWriterLockManifest.php`
- `apps/api/tests/Architecture/InventoryWriterLockManifestTest.php`
- `apps/api/tests/Architecture/LotVocabularyContractTest.php`

**Files to modify**

- `docs/glossary.md`
- `apps/api/tests/Architecture/DocumentPerActionWriteGuardTest.php`
- `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php`
- `apps/api/tests/Architecture/baselines/tenant-only-unique-baseline.json`
- `apps/api/tests/feature-lane-manifest.json`
- `.github/workflows/ci.yml`

**Schema delta:** none.

**Red first**

- `InventoryWriterLockManifestTest::test_every_aggregate_or_lot_quantity_writer_is_classified` fails listing the current direct FEFO, repair, batch-stock, POS return, and count writers absent from the new manifest.
- `InventoryWriterLockManifestTest::test_quantity_changing_batch_writers_require_a_stock_movement_link` fails on the conditional `movement_id` path in `BatchStockService::recordBatchMovement()`.
- `LotVocabularyContractTest::test_w_lot_terms_name_one_surface_and_primary_writer` fails until every §5 row exists verbatim.
- Extend `TenantOnlyUniqueOnCatalogueTablesRatchetTest` to classify `product_batches` as catalogue and every new transaction/evidence child as an explicit non-catalogue relationship or immutable evidence table.

**Implementation**

1. Write the complete census from §9 with current path/line evidence.
2. Make the manifest the machine-readable authority used by the test.
3. Add the exact glossary changes from §5.
4. Add `product_batches` to `CATALOGUE_TABLES`.
5. Predeclare classifications for planned unique-bearing tables; do not add a waiver without its parent/company rationale.
6. Add all new test classes to live PostgreSQL CI selection or deliberately update the parked-lane ceiling with a merge-time union note. No new PG test may execute nowhere.

**Reviewer gate:** inventory-costing plus architecture. Tasks 8 and 12 are blocked until this gate accepts both the table and ratchet.

---

## Task 2 — Add tri-state entitlement, revision fencing, and rollout flags

**Files to create**

- `apps/api/database/migrations/2026_09_06_100000_add_module_config_revision_to_tenants_table.php`
- `apps/api/config/lot_rollout.php`
- `apps/api/app/Modules/Company/Domain/Enums/CompanyEntitlementState.php`
- `apps/api/app/Modules/Company/Application/DTOs/CompanyEntitlementDecision.php`
- `apps/api/app/Shared/Contracts/CompanyEntitlementResolver.php`
- `apps/api/app/Modules/Company/Application/Services/EffectiveCompanyEntitlementResolver.php`
- `apps/api/tests/Feature/Company/CompanyEntitlementResolverTest.php`
- `apps/api/tests/Feature/Company/ModuleConfigurationRevisionRacePostgresTest.php`

**Files to modify**

- `apps/api/.env.example`
- `apps/api/app/Modules/Company/Services/CompanyConfigService.php`
- `apps/api/app/Modules/Company/Services/VerticalConfigService.php`
- `apps/api/app/Modules/Company/Presentation/Controllers/VerticalConfigController.php`
- `apps/api/app/Modules/Tenant/Observers/TenantObserver.php`
- `apps/api/app/Modules/Tenant/Domain/Tenant.php`
- `apps/api/app/Providers/AppServiceProvider.php`

**Schema delta:** `tenants.module_config_revision BIGINT NOT NULL DEFAULT 1`.

**Red first**

- `CompanyEntitlementResolverTest::test_lookup_failure_is_unresolved_not_not_entitled` expects `unresolved`; current bool resolver returns false.
- `CompanyEntitlementResolverTest::test_company_mismatch_is_unresolved_and_enabled_company_returns_revision` fails until company identity is checked.
- `ModuleConfigurationRevisionRacePostgresTest::test_stale_inflight_cache_fill_cannot_overwrite_new_revision` fails until cache keys are revisioned.
- `test_parapharmacy_default_is_enabled_but_product_flag_still_controls_tracking` pins `apps/api/config/verticals.php:344-355` without making every product tracked.

**Implementation**

1. Add the contracts in §7.
2. Keep the existing bool resolver unchanged for unrelated projections.
3. Version `CompanyConfigService` cache keys and revision changes.
4. Bind the interface using constructor injection.
5. Add all kill switches default false.
6. Never read `CompanyContext` from a fiscal worker.

**Reviewer gate:** tenancy-authz plus fiscal-pos. Must demonstrate enabled, explicitly disabled, malformed, missing, wrong-company, stale-cache, and database-failure outcomes.

---

## Task 3 — Make role and membership changes atomic

**Files to create**

- `apps/api/app/Modules/Identity/Application/Services/RoleMembershipScopeService.php`
- `apps/api/app/Modules/Identity/Domain/Exceptions/InvalidRoleMembershipScope.php`
- `apps/api/app/Console/Commands/ApplyWLotRoleDeltaCommand.php`
- `apps/api/app/Console/Commands/WLotRoleReadinessCommand.php`
- `apps/api/tests/Feature/Identity/WLotRoleMembershipPolicyTest.php`
- `apps/api/tests/Feature/Permissions/WLotRoleDeltaCommandTest.php`

**Files to modify**

- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- `apps/api/app/Modules/Identity/Presentation/Requests/AssignRoleRequest.php`
- `apps/api/app/Modules/Identity/Presentation/Requests/CreateUserRequest.php`
- `apps/api/app/Modules/Identity/Presentation/Requests/UpdateUserRequest.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php`
- `apps/web/src/hooks/permissionsMap.generated.ts`
- `apps/web/tools/__tests__/permission-map-drift-guard.test.mjs`

**Schema delta:** none.

**Red first**

- `WLotRoleMembershipPolicyTest::test_manager_assignment_requires_explicit_nonempty_branch_scope`.
- `…::test_general_manager_assignment_requires_explicit_unrestricted_scope`.
- `…::test_role_and_membership_roll_back_together_when_location_is_foreign`.
- `WLotRoleDeltaCommandTest::test_execute_changes_only_canonical_roles_and_preserves_custom_roles`.
- `…::test_dry_run_reports_existing_unrestricted_managers_without_writing`.

**Implementation**

1. Add the permission grants from §6.
2. Route create/update/assign APIs through `RoleMembershipScopeService`.
3. Make `AssignRoleRequest` accept `allowed_location_ids`; refuse affected roles when omitted.
4. Add `permissions:apply-w-lot-delta` with mutually exclusive `--dry-run` and `--execute`.
5. Create/update only named canonical roles and permissions; never call a fleet-wide blind `syncPermissions()` from the deployment command.
6. Run `php artisan permissions:export-frontend` and verify generated-map drift.
7. Require `permission:cache-reset` after each successful tenant delta.

**Reviewer gate:** tenancy-authz. Activation remains blocked on any readiness finding.

---

## Task 4 — Add recall workflow schema and domain service

**Files to create**

- `apps/api/database/migrations/tenant/2026_09_06_100100_create_batch_recall_workflow_tables.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Enums/BatchRecallStatus.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchRecallRequest.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchRecallTransition.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/CreateBatchRecallRequestData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/HoldBatchRecallData.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallWorkflowService.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchRecallPolicy.php`
- `apps/api/tests/Feature/BatchExpiry/BatchRecallWorkflowTest.php`
- `apps/api/tests/Feature/BatchExpiry/BatchRecallConcurrencyPostgresTest.php`

**Files to modify**

- `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`

**Schema delta:** §8.1.

**Contract signatures**

```php
public function request(
    Batch $batch,
    CreateBatchRecallRequestData $data,
    User $actor,
): BatchRecallRequest;

public function hold(
    BatchRecallRequest $request,
    HoldBatchRecallData $data,
    User $actor,
): BatchRecallRequest;

public function assertIssuable(
    Batch $batch,
    string $locationId,
): void;
```

**Red first**

- `BatchRecallWorkflowTest::test_branch_request_blocks_fefo_direct_issue_and_stock_transfer_service_at_requesting_location_only`.
- `…::test_general_manager_hold_blocks_every_location_and_projects_is_recalled`.
- `…::test_manager_cannot_execute_company_hold`.
- `…::test_same_operation_replays_and_changed_content_conflicts`.
- `BatchRecallConcurrencyPostgresTest::test_two_simultaneous_requests_create_one_active_location_request`.

**Implementation**

1. Create append-only request and transition records.
2. Apply `BatchRecallPolicy` in FEFO, direct `BatchStockService` issue/transfer, explicit write-off exception rules, and `StockTransferService` validation/completion.
3. A requested row blocks its location immediately.
4. Holding sets the company-wide state and compatibility `product_batches.is_recalled` fields inside the same transaction.
5. Do not add reject/release code until O-LOT-1 is ruled.
6. No transition publishes before commit.

**Reviewer gate:** inventory-costing and tenancy-authz.

---

## Task 5 — Expose recall API and web surface; close all BatchExpiry permission gaps

**Files to create**

- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRecallRequest.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/HoldBatchRecallRequest.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchRecallController.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchRecallData.php`
- `apps/api/tests/Feature/Security/BatchExpiryActionAuthorizationTest.php`
- `apps/api/tests/Feature/BatchExpiry/BatchLocationScopeTest.php`
- `apps/web/src/features/batches/components/BatchRecallPanel.tsx`
- `apps/web/src/features/batches/components/BatchRecallPanel.test.tsx`

**Files to modify**

- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php`
- `apps/web/src/routes/index.tsx`
- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- `apps/web/src/features/inventory/pages/InventoryHubPage.tsx`
- `apps/web/src/features/batches/api/batches.ts`
- `apps/web/src/features/batches/hooks/useBatches.ts`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/web/src/locales/en/batches.json`
- `apps/web/src/locales/fr/batches.json`
- `packages/shared/types/generated.d.ts`

**Schema delta:** none beyond Task 4.

**Red first**

- `BatchExpiryActionAuthorizationTest` provides one case for every route in §6 and initially fails on the current body-less routes.
- `BatchLocationScopeTest::test_restricted_manager_never_receives_foreign_location_stock_or_trace_rows`.
- `BatchRecallPanel.test.tsx::shows_request_for_branch_manager_and_hold_only_for_general_manager`.
- Route test asserts UUID public routes; no `{id}` batch route is accepted.

**Implementation**

1. Add every route permission from §6.
2. Make `BatchController::expiring()` use UUID validation plus `LocationScopeResolver`, matching the safe `expired()` pattern.
3. Scope list/detail/stock/product-stock/trace queries by allowed locations.
4. Replace web `moduleKey="inventory"` guards with explicit batch permissions.
5. Gate Sidebar, Inventory Hub card, page routes, and individual actions.
6. Keep recall inside batch detail; do not create a second batch catalogue.

**Reviewer gate:** tenancy-authz plus frontend-conventions. Required evidence includes `php artisan route:list --path=batches --columns=method,uri,middleware`.

---

## Task 6 — Retire the complete float contract

**Files to create**

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchStockData.php`
- `apps/api/tests/Feature/BatchExpiry/BatchQuantityStringContractTest.php`
- `apps/api/tests/Unit/BatchExpiry/BatchStockExactArithmeticTest.php`
- `apps/web/src/features/batches/__tests__/BatchQuantityPrecision.test.tsx`

**Files to modify**

- `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchStock.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`
- `apps/api/app/PHPStan/Rules/ForbidFloatCastOnDecimalProperty.php`
- `apps/web/src/features/batches/types.ts`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/web/src/features/batches/pages/BatchListPage.tsx`
- `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx`
- `apps/web/src/features/batches/components/BatchForm.tsx`
- `apps/web/src/features/batches/api/batches.ts`
- `packages/shared/types/generated.d.ts`

**Schema delta:** none.

**Contract changes**

```php
public function availableQuantity(): string;
public function reserve(string $quantity): void;
public function releaseReservation(string $quantity): void;
public function adjustQuantity(string $delta): void;
public function getTotalAvailableQuantity(
    string $productId,
    ?string $locationId = null,
): string;
```

**Red first**

- `BatchStockExactArithmeticTest::test_fractional_reserve_release_and_adjust_never_use_float`.
- `BatchQuantityStringContractTest::test_all_batch_quantity_fields_are_json_strings_with_zero_fallbacks`.
- Web test uses quantities beyond JavaScript’s safe integer precision and fails while `parseFloat`/native reduce remain.
- Extend the PHPStan rule/liveness test so `(float)$batch->quantity`, float return types, and arithmetic on decimal properties fail analysis.

**Implementation**

1. Replace every float accessor, comparison, sum, and mutator with BCMath/`QuantityScale`.
2. Emit `'0.0000'`, never numeric `0`, from fallbacks.
3. Generate DTO types with `php artisan typescript:transform`.
4. Replace hand-written batch DTO shapes with imports/aliases from `@autoerp/shared/types/generated`.
5. Use `bcadd`/`bcsub` helpers and `formatQuantity(value, unit.decimal_places)` in web consumers.
6. Apply quantity regex ceilings to every new request: `^-?\d+(\.\d{1,4})?$`, plus positive/non-negative domain rules.

**Reviewer gate:** inventory-costing and frontend-conventions.

---

## Task 7 — Add identity-correction and identification schemas

**Files to create**

- `apps/api/database/migrations/tenant/2026_09_06_100200_add_batch_identity_version_and_corrections.php`
- `apps/api/database/migrations/tenant/2026_09_06_100300_create_lot_identification_tables.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Enums/LotDeactivationDisposition.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchIdentityCorrection.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/LotIdentification.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/LotIdentificationLine.php`
- `apps/api/tests/Feature/BatchExpiry/LotIdentificationSchemaPostgresTest.php`

**Files to modify**

- `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php`
- `apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php`
- `apps/api/app/Shared/Domain/Enums/StockMovementReferenceType.php`
- `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php`

**Schema delta:** §§8.2–8.3.

**Red first**

- Schema test asserts every column, FK, check, public UUID, company-scoped operation unique, and absence of a tenant-only operation unique.
- Test runs the migration twice through the test migrator and asserts no duplicate indexes.
- Test proves the same operation UUID is legal in company B.

**Implementation**

1. Create additive tables and indexes.
2. Add `MovementReason::LotIdentification` and `StockMovementReferenceType::LotIdentification`.
3. Set legacy `identity_version=1`; do not reinterpret identity history.
4. Mark identification and correction rows immutable through model guards and PostgreSQL update/delete triggers where the repository’s immutable-document pattern applies.

**Reviewer gate:** database/inventory-costing. No behavior flag is enabled.

---

## Task 8 — Implement identity correction, L9 identification, and canonical locking

**Files to create**

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/CorrectBatchIdentityData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/IdentifyLotData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/IdentifyLotLineData.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchIdentityCorrectionService.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/LotIdentificationService.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/LotQuantityDeltaData.php`
- `apps/api/tests/Feature/BatchExpiry/BatchIdentityCorrectionTest.php`
- `apps/api/tests/Feature/BatchExpiry/LotIdentificationTest.php`
- `apps/api/tests/Feature/BatchExpiry/LotIdentificationConcurrencyPostgresTest.php`

**Files to modify**

- `apps/api/app/Modules/Inventory/Domain/Services/ProductCostLock.php`
- `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`
- `apps/api/app/Console/Commands/RepairPhantomDefaultBatchesCommand.php`
- every writer identified by Task 1 that lacks the common advisory lock

**Schema delta:** uses Tasks 7 schemas; none additional.

**Contract signatures**

```php
public function correct(
    Batch $batch,
    CorrectBatchIdentityData $data,
    User $actor,
): Batch;

public function identify(
    Batch $source,
    IdentifyLotData $data,
    User $actor,
): LotIdentification;

public function reattributeLots(
    StockLevel $lockedStock,
    array $lotDeltas,
    StockMovementReferenceType $referenceType,
    string $referenceId,
    ?string $userId,
): StockMovement;
```

**Red first**

- Used ordinary update expects `422 BATCH_IDENTITY_FROZEN`.
- Stale `source_identity_version` expects `409 BATCH_IDENTITY_VERSION_CONFLICT`.
- Identifying a reserved source expects `409 LOT_IDENTIFICATION_RESERVED`.
- Target mismatch in company/product/variant/location expects 422 with zero writes.
- Replay expects the original result, one identification, one flat movement, and unchanged quantities.
- PG concurrency: identification versus POS FEFO and versus transfer must neither deadlock nor skip to a later lot.
- GL assertion expects zero `journal_entries`; WAC before equals WAC after exactly.

**Implementation**

1. `correct()` refuses `DEFAULT` stock-bearing identity changes; those use `identify()`.
2. Used non-DEFAULT correction writes old/new values and increments `identity_version` under optimistic lock.
3. Never merge two used histories. A target conflict is a typed refusal.
4. `identify()` requires explicit target batch numbers; expiry may remain null but is never synthesized.
5. Refuse any active source reservation.
6. Lock per §9, verify source quantity, create/reuse target batches through canonical batch creation, and write one aggregate movement with quantity `0.0000` and equal before/after.
7. Write source-negative and target-positive batch movements linked to that movement.
8. Dispatch stock/channel events after commit.
9. Change `StockAdjustmentService::issue()` to advisory-first and apply the common lock to all Task 1 writers.

**Reviewer gate:** inventory-costing plus stock/GL interaction. This gate must pass before Task 12.

---

## Task 9 — Expose correction/identification and activate freeze as one unit

**Files to create**

- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/CorrectBatchIdentityRequest.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/IdentifyLotRequest.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchIdentityController.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/LotIdentificationData.php`
- `apps/web/src/features/batches/components/BatchIdentityCorrectionDialog.tsx`
- `apps/web/src/features/batches/components/LotIdentificationDialog.tsx`
- corresponding `.test.tsx` files

**Files to modify**

- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php`
- `apps/web/src/features/batches/api/batches.ts`
- `apps/web/src/features/batches/hooks/useBatches.ts`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/web/src/locales/en/batches.json`
- `apps/web/src/locales/fr/batches.json`
- `packages/shared/types/generated.d.ts`

**Schema delta:** none.

**Red first**

- API test asserts `/batches/{uuid}/corrections` and `/batches/{uuid}/identify`, permissions, company scope, evidence requirement, quantity precision, and replay.
- UI tests assert correction/identify controls appear only with their exact permissions.
- Deactivation tests require one of `empty`, `written_off`, or `transferred`, and independently verify zero company-wide quantity/reservations.

**Implementation**

1. Add typed requests and generated response DTOs.
2. Keep both actions in existing batch detail.
3. Link count discrepancy rows to the same identify action.
4. Gate ordinary used-identity freeze, correction, and identification behind the single effective `identity_correction` flag.
5. Do not turn the flag on until Tasks 7–9, role delta, generated types, and reviewer gates are green.

**Reviewer gate:** inventory-costing plus frontend-conventions.

---

## Task 10 — Add lot-count schema and typed submission contract

**Files to create**

- `apps/api/database/migrations/tenant/2026_09_06_100400_add_lot_grain_counting.php`
- `apps/api/app/Modules/Inventory/Domain/InventoryCountingItemLot.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/CountSubmissionData.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/LotCountObservationData.php`
- `apps/api/tests/Feature/Inventory/LotCountingSchemaPostgresTest.php`
- `apps/api/tests/Feature/Inventory/LotCountSubmissionContractTest.php`

**Files to modify**

- `apps/api/app/Modules/Inventory/Domain/InventoryCounting.php`
- `apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php`
- `apps/api/app/Modules/Inventory/Presentation/Requests/SubmitCountRequest.php`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php`
- `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php`

**Schema delta:** §8.4.

**Revised signature**

```php
public function submitCount(
    InventoryCountingItem $item,
    int $countNumber,
    CountSubmissionData $submission,
    User $user,
): void;
```

**Red first**

- Explicit zero lot row is persisted and differs from omitted lot.
- Duplicate batch UUID in one submission is rejected.
- Parent quantity unequal to BCMath sum of child quantities is rejected.
- Foreign company/product/variant/location lot is rejected with zero writes.
- A legacy version-1 active count continues using the parent path and gains no fabricated children.

**Implementation**

1. Extend the request with a typed `lots[]` list and scale-4 regexes.
2. For version-2 batch-tracked items, require complete lot observations and derive the parent total.
3. For untracked or legacy version-1 items, retain the existing contract.
4. Persist parent and children under the existing counting-header lock.
5. Carry server/device timestamps and movement markers to child observations.

**Reviewer gate:** inventory-costing.

---

## Task 11 — Apply lot-count reconciliation safely

**Files to create**

- `apps/api/app/Modules/Inventory/Application/Services/LotCountingReconciliationService.php`
- `apps/api/tests/Feature/Inventory/LotCountingReconciliationTest.php`
- `apps/api/tests/Feature/Inventory/LotCountingConcurrencyPostgresTest.php`
- `apps/api/tests/Feature/Inventory/LotCountingLateSyncTest.php`

**Files to modify**

- `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php`
- `apps/api/app/Modules/Inventory/Application/Services/CountingReconciliationService.php`
- `apps/api/app/Modules/Inventory/Application/Services/CountingReconciliationPayloadBuilder.php`
- `apps/api/app/Modules/Inventory/Application/Services/CountingDiscrepancyReportService.php`
- `apps/api/app/Modules/Inventory/Application/Services/LateSyncResidualDetector.php`
- `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php`
- `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php`
- `apps/api/tests/Feature/Inventory/InventoryCountingDefaultBatchTest.php`

**Schema delta:** none.

**Red first**

- Book `10+10`, observed `15+5`: expect one flat movement, two lot legs `+5/-5`, unchanged aggregate/WAC, zero journals.
- Aggregate gain with identified observations: expect gain assigned to named lots and no `DEFAULT` increase.
- Lot final quantity below reserved quantity: expect unresolved/refused finalize and no writes.
- Replaying finalize: expect no duplicate movement or journal.
- Late terminal observation after finalize: expect a residual, not stock mutation.
- PG races: lot count versus POS sale, direct transfer, and `StockTransferService` completion.

**Implementation**

1. Derive final per-lot quantities through the same count-resolution rules as the parent.
2. Compute aggregate delta and lot deltas with BCMath.
3. If aggregate delta is zero but lot deltas exist, call `reattributeLots()`.
4. If aggregate changes, write one aggregate count correction and exact lot legs.
5. Do not auto-credit `DEFAULT` when version-2 identified observations exist.
6. Preserve the existing no-op return when both aggregate and every lot delta are zero.
7. Extend late-sync detection to child movement markers.
8. Replace `_pins_limitation_` tests only after real behavior exists.

**Reviewer gate:** inventory-costing plus stock/GL interaction.

---

## Task 12 — Build lot-count web contracts and UI

**Files to modify**

- `apps/web/src/features/inventory-counting/types.ts`
- `apps/web/src/features/inventory-counting/api/countingApi.ts`
- `apps/web/src/features/inventory-counting/api/queries.ts`
- `apps/web/src/features/inventory-counting/pages/CountingDetailPage.tsx`
- `apps/web/src/features/inventory-counting/pages/CountingReviewPage.tsx`
- `apps/web/src/features/inventory-counting/pages/DiscrepancyReportPage.tsx`
- `apps/web/src/features/inventory-counting/components/ReconciliationTable.tsx`
- `apps/web/src/locales/en/inventory.json`
- `apps/web/src/locales/fr/inventory.json`
- `apps/web/src/locales/ar/inventory.json`
- `packages/shared/types/generated.d.ts`

**Files to create**

- `apps/web/src/features/inventory-counting/__tests__/LotCountSubmission.test.tsx`
- `apps/web/src/features/inventory-counting/__tests__/LotCountReconciliation.test.tsx`

**Schema delta:** none.

**Red first**

- UI distinguishes “not counted” from `0`.
- UI totals decimal strings without JavaScript number coercion.
- Identification link targets existing batch detail and appears only with `batches.identify`.
- Legacy version-1 counts show an explicit product-grain badge rather than invented lot rows.

**Implementation**

1. Import generated DTOs.
2. Add expandable lot rows inside the existing count item.
3. Use quantity precision from the product unit.
4. Show applied/blocked reasons and late residuals.
5. Do not create a second lot-count page.

**Reviewer gate:** frontend-conventions.

---

## Task 13 — Add provenance columns and explicit producer writes

**Files to create**

- `apps/api/database/migrations/tenant/2026_09_06_100500_add_lot_provenance.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Enums/LotProvenance.php`
- `apps/api/tests/Feature/BatchExpiry/LotProvenanceMigrationTest.php`
- `apps/api/tests/Architecture/LotProvenanceWriterContractTest.php`

**Files to modify**

- `apps/api/app/Modules/POS/Domain/ReceiptLineBatchAllocation.php`
- `apps/api/app/Modules/Document/Domain/DocumentLine.php`
- `apps/api/app/Modules/Inventory/Domain/StockTransferLineBatchAllocation.php`
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php`
- `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php`
- `apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`

**Schema delta:** §8.5.

**Red first**

- Migration test expects all legacy producers to read `unknown`.
- Writer contract test fails each production create/update that omits provenance.
- FEFO-created allocation expects `system_fefo_estimate`.
- Operator evidence-linked allocation expects `operator_captured`.
- Automatic transfer allocation expects `system_fefo_estimate`; explicit transfer selection is captured only when the request actually records operator selection, otherwise `unknown`.

**Implementation**

1. Add enum casts and checks.
2. Keep all legacy rows unknown.
3. Require every new writer to select the correct enum from actual source facts.
4. Returns copy the original allocation provenance; they do not promote it.
5. Do not infer provenance from batch number, expiry, timestamp, or current service shape.

**Reviewer gate:** inventory-costing plus fiscal-pos.

---

## Task 14 — Show provenance on the existing trace and document surfaces

**Files to create**

- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchTraceRowData.php`
- `apps/api/tests/Feature/BatchExpiry/BatchTraceabilityProvenanceTest.php`
- `apps/web/src/features/batches/components/LotProvenanceBadge.tsx`
- `apps/web/src/features/batches/components/LotProvenanceBadge.test.tsx`

**Files to modify**

- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php`
- applicable document/receipt/transfer resources and export builders
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- applicable receipt, document, and transfer detail pages
- `apps/web/src/locales/en/batches.json`
- `apps/web/src/locales/fr/batches.json`
- `packages/shared/types/generated.d.ts`

**Schema delta:** none.

**Red first**

- Forward and backward trace both return provenance.
- Restricted users cannot see foreign-location trace rows.
- CSV/export contract includes the same stable enum.
- UI labels estimate as estimate and unknown as unknown; neither appears as captured.

**Implementation**

1. Extend the existing trace DTO and exports.
2. Render one shared provenance badge.
3. Reuse the existing batch trace surface.
4. Preserve return lineage.

**Reviewer gate:** frontend-conventions plus tenancy-authz.

---

## Task 15 — Add durable drift monitoring and health surface

**Files to create**

- `apps/api/database/migrations/tenant/2026_09_06_100600_create_lot_ledger_census_runs.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/LotLedgerCensusRun.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Enums/LotLedgerCensusStatus.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/LotLedgerMonitorService.php`
- `apps/api/app/Console/Commands/MonitorLotLedgerDriftCommand.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/LotLedgerHealthController.php`
- `apps/api/tests/Feature/BatchExpiry/LotLedgerDriftCensusPostgresTest.php`
- `apps/api/tests/Feature/BatchExpiry/LotLedgerMonitorScheduleTest.php`
- `apps/web/src/features/batches/components/LotLedgerHealthBanner.tsx`
- `apps/web/src/features/batches/components/LotLedgerHealthBanner.test.tsx`

**Files to modify**

- `apps/api/app/Modules/BatchExpiry/Application/Services/LotLedgerDriftCensus.php`
- `apps/api/app/Console/Commands/LotLedgerDriftCensusCommand.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`
- `apps/api/routes/console.php`
- `apps/web/src/features/batches/pages/BatchListPage.tsx`
- `apps/web/src/features/batches/api/batches.ts`
- `apps/web/src/features/batches/hooks/useBatches.ts`

**Schema delta:** §8.6.

**Contracts**

```php
public function monitorCompany(
    string $tenantId,
    string $companyId,
    string $trigger,
): LotLedgerCensusRun;
```

Command:

```text
inventory:lot-drift-monitor
  {--tenant=}
  {--all-tenants}
  {--company=}
```

**Red first**

- `LotLedgerDriftCensusPostgresTest` proves positive, negative, variant, missing-lot, exact-decimal, and wrong-company cases.
- Module-off is excluded only after an explicit `not_entitled`; unresolved produces a failed run.
- Scheduler test asserts daily `02:15`, `onOneServer`, and `withoutOverlapping`.
- Failure test persists `failed`, never `clean`.
- Wrong-tenant test proves no cross-tenant run.
- Missed-run test makes the UI/API report stale after 26 hours.

**Implementation**

1. Keep `inventory:lot-drift-census` read-only.
2. Add the separate monitor command as the only health writer.
3. Persist one run per company.
4. Notify admins/general managers on drift, failure, and stale reads.
5. Schedule only when `census_scheduler` is enabled.
6. Apply retention rules without deleting the newest or unresolved run.

**Reviewer gate:** live PostgreSQL inventory-costing. Scheduler flag stays false until accepted.

---

## Task 16 — Add server lot-eligibility DTO, endpoint, and terminal capabilities

**Files to create**

- `apps/api/database/migrations/tenant/2026_09_06_100000_add_w_lot_terminal_capabilities.php`
- `apps/api/app/Modules/POS/Application/DTOs/LotEligibilityRowData.php`
- `apps/api/app/Modules/POS/Application/DTOs/LotEligibilitySnapshotData.php`
- `apps/api/app/Modules/POS/Application/Services/PosLotEligibilityService.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/PosLotEligibilityController.php`
- `apps/api/app/Console/Commands/ConfigurePosLotCapabilitiesCommand.php`
- `apps/api/tests/Feature/POS/PosLotEligibilityEndpointTest.php`
- `apps/api/tests/Feature/POS/PosLotCapabilityCommandTest.php`

**Files to modify**

- `apps/api/app/Modules/POS/Domain/Terminal.php`
- `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php`
- `apps/api/app/Modules/POS/routes.php`
- `packages/shared/types/generated.d.ts`

**Schema delta:** §8.7.

**Endpoint**

```text
GET /api/v1/pos/lot-eligibility?terminal_id=<uuid>&page=<n>
```

Response includes snapshot revision, entitlement state/revision, capability revision, server `as_of`, fiscal acknowledged-through sequence, lot-effects-included-through sequence, and paginated rows.

**Red first**

- Terminal location is server-derived.
- Foreign terminal/company or inaccessible terminal location is refused.
- Requested local hold excludes only that location; held excludes every location.
- Quantities are strings and ordering matches server FEFO.
- Old clients remain compatible because no existing response field becomes required.
- Capability command dry-run writes nothing and execute increments revision.

**Implementation**

1. Build the read projection from product batches, batch stock, recall workflow, and projection inclusion state.
2. Paginate on a stable snapshot boundary.
3. Advertise guidance/authoring only when terminal flag, global kill switch, and entitlement are enabled.
4. Return unresolved explicitly; never an empty “module off” response for an error.
5. Generate shared types.

**Reviewer gate:** fiscal-pos plus tenancy-authz.

---

## Task 17 — Add SQLite v68, pre-open refresh, and one POS guidance pipeline

**Files to create**

- `apps/pos/src/lib/db/repositories/lotEligibilityRepository.ts`
- `apps/pos/src/lib/lot/lotEligibilityService.ts`
- `apps/pos/src/lib/lot/__tests__/lotEligibilityService.test.ts`
- `apps/pos/src/lib/db/__tests__/migrations.v68.test.ts`
- `apps/pos/src/lib/sync/__tests__/syncService.lotEligibility.test.ts`
- `apps/pos/src/stores/__tests__/terminalStore.lotPreOpenRefresh.test.ts`
- `apps/pos/src/components/organisms/ProductGrid/__tests__/NearExpirySlot.test.tsx`

**Files to modify**

- `apps/pos/src/lib/db/migrations.ts` — reserve and add v68 only
- `apps/pos/src/lib/sync/syncService.ts`
- `apps/pos/src/lib/db/repositories/terminalStateRepository.ts`
- `apps/pos/src/stores/terminalStore.ts`
- `apps/pos/src/stores/productStore.ts`
- `apps/pos/src/pages/HomePage.tsx`
- `apps/pos/src/components/organisms/ProductGrid/NearExpirySlot.tsx`
- `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx`
- `apps/pos/src/components/organisms/ProductGrid/ProductListRow.tsx`
- `apps/pos/src/components/organisms/ProductGrid/ProductTable.tsx`
- `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx`
- `apps/pos/src/components/molecules/CartLineItem/CartLineItem.tsx`
- `apps/pos/src/components/pos/ProductDetailDrawer.tsx`
- `apps/pos/src/components/pos/ProductGrid.tsx`
- `apps/pos/src/locales/en/pos.json`
- `apps/pos/src/locales/fr/pos.json`

**Schema delta:** SQLite v68 in §8.10.

**Pre-open sequence**

1. Set opening state to loading.
2. Open the company SQLite database.
3. Pull terminal state/capability.
4. If guidance is explicitly disabled, clear/hide lot cache state and continue.
5. If enabled, complete a full lot snapshot replacement before authoring either local v3 `SESSION_OPEN` or legacy server shift.
6. On refresh success, mark the lot arm ready and open.
7. On network/unresolved failure, permit ordinary POS-core shift opening but disable batch-tracked lot guidance/capture for that attempt. Module-off products continue through ordinary aggregate behavior.
8. Manual successful refresh during the shift may enable the lot arm.

**Red first**

- Current `terminalStore.ts:857-907` fails the ordering assertion because pull happens after authoring.
- v68 migration is additive/idempotent from v67 and from a fresh database.
- Full replacement rolls back completely on page failure.
- Date comparisons use `toSqliteUtc()` and age display uses `sqliteUtcToDate()`.
- Snapshot netting subtracts cart allocations and local completed sales only when their event sequence is above `lot_effects_included_through_sequence`.
- Acknowledged-but-not-included events remain subtracted.
- Pending refunds are not added to saleable availability until the server snapshot includes them.
- Restart deduplicates local demand.
- ProductCard, list, table, cart, and detail drawer all render from the same selector.
- Legacy `apps/pos/src/components/pos/ProductGrid.tsx` is either deleted if unused or explicitly delegates to the organism; it may not implement lot selection.

**Implementation**

1. Add v68 only; do not take v69 in this task.
2. Implement a replace-all scoped cache transaction.
3. Compute FEFO with decimal strings and stable dated-before-undated ordering.
4. Render snapshot age and estimated provenance.
5. Gate the `stock_lots` detail tab and every slot on effective capability.
6. Keep selection state in one HomePage/cart pipeline.
7. No lot affordance renders when capability is off or unresolved.

**Reviewer gate:** fiscal-pos plus frontend-conventions.

---

## Task 18 — Add server evidence storage and either-order ingress

**Files to create**

- `apps/api/database/migrations/tenant/2026_09_06_100700_create_pos_lot_evidence.php`
- `apps/api/app/Modules/POS/Domain/Enums/LotEvidenceMatchStatus.php`
- `apps/api/app/Modules/POS/Domain/PosReceiptLineLotEvidence.php`
- `apps/api/app/Modules/POS/Application/DTOs/IngestLotEvidenceData.php`
- `apps/api/app/Modules/POS/Application/Services/LotEvidenceIngressService.php`
- `apps/api/app/Modules/POS/Presentation/Requests/IngestLotEvidenceBatchRequest.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/LotEvidenceIngressController.php`
- `apps/api/tests/Feature/POS/LotEvidenceIngressTest.php`
- `apps/api/tests/Feature/POS/LotEvidenceArrivalOrderPostgresTest.php`

**Files to modify**

- `apps/api/app/Modules/Fiscal/Domain/FiscalEvent.php`
- fiscal event ingestion DTO/request/service that handles the unsealed envelope
- `apps/api/app/Modules/POS/routes.php`

**Schema delta:** §8.8.

**Contract**

```php
public function ingestBatch(
    string $tenantId,
    string $companyId,
    array $evidence,
    User $actor,
): LotEvidenceIngressResult;
```

**Red first**

- Evidence-before-event persists `awaiting_event`; event arrival matches it.
- Event-before-evidence matches immediately.
- Terminal, company, location, event hash, line key, product, variant, quantity, and capability revision mismatches are refused.
- Same ID/same fingerprint is idempotent; changed content is 409.
- Evidence accepted after current module revocation remains stored but does not bypass entitlement.
- No test sees evidence fields in canonical fiscal payload bytes.

**Implementation**

1. Add unsealed event-envelope expectation fields.
2. Recompute fingerprints server-side.
3. Store historical evidence even when the current rollout is disabled, provided its terminal capability revision is valid.
4. Dispatch lot recovery after commit when a matching event/obligation exists.
5. Keep ingress free of stock writes.

**Reviewer gate:** fiscal-pos plus tenancy-authz.

---

## Task 19 — Add SQLite v69 and atomically author captured evidence

**Files to create**

- `apps/pos/src/lib/db/repositories/lotEvidenceRepository.ts`
- `apps/pos/src/lib/lot/lotEvidenceAuthoringService.ts`
- `apps/pos/src/lib/db/__tests__/migrations.v69.test.ts`
- `apps/pos/src/lib/lot/__tests__/lotEvidenceAuthoringService.test.ts`
- `apps/pos/src/lib/offline/__tests__/receiptService.lotEvidenceAtomicity.test.ts`
- `apps/pos/src/components/pos/LotSelectionEditor.tsx`
- `apps/pos/src/components/pos/__tests__/LotSelectionEditor.test.tsx`

**Files to modify**

- `apps/pos/src/lib/db/migrations.ts` — add reserved v69
- `apps/pos/src/lib/offline/receiptService.ts`
- `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` only if its returned append result lacks the already-computed ID/hash; do not alter canonical serialization
- `apps/pos/src/components/molecules/CartLineItem/CartLineItem.tsx`
- `apps/pos/src/pages/HomePage.tsx`
- `apps/pos/src/types/cart.ts` or the actual canonical cart type file
- `apps/pos/src/locales/en/pos.json`
- `apps/pos/src/locales/fr/pos.json`

**Schema delta:** SQLite v69 in §8.10.

**Atomic position**

Inside the existing receipt transaction:

1. Validate editable selections against the current local snapshot.
2. Call `FiscalEventEngine.append()`.
3. Receive the stable event ID and hash.
4. Insert receipt rows.
5. Insert evidence rows and outbox rows.
6. Commit once.

Any evidence/outbox insert failure rolls back the fiscal event and receipt.

**Red first**

- Fault injection after fiscal append but before evidence insert leaves no fiscal event, receipt, evidence, or outbox row.
- Captured selection differing from FEFO is preserved exactly.
- Quantity totals use decimal strings and `QuantityInput`.
- Authoring is impossible until the terminal has locally stored the server capability acknowledgement.
- Stale/unavailable/expired/held selections are refused with no O-LOT-2 override.
- Fiscal V1–V5 byte-stability snapshots remain unchanged.

**Implementation**

1. Add v69 idempotently.
2. Store selection on the canonical cart line.
3. Prefill from guidance, but require the operator’s retained/edited selection for `operator_captured`.
4. Write one evidence row per selected lot.
5. Mark the fiscal sync envelope `lot_evidence_expected=true` and include the capability revision outside canonical bytes.
6. Refund authoring references/copies original evidence where available; it never creates saleable positive cache stock.

**Reviewer gate:** fiscal-pos.

---

## Task 20 — Implement evidence outbox delivery and crash recovery

**Files to create**

- `apps/pos/src/lib/sync/lotEvidenceSync.ts`
- `apps/pos/src/lib/sync/__tests__/lotEvidenceSync.test.ts`
- `apps/pos/src/lib/sync/__tests__/lotEvidenceSync.restart.integration.test.ts`

**Files to modify**

- `apps/pos/src/lib/sync/syncService.ts`
- `apps/pos/src/lib/db/repositories/lotEvidenceRepository.ts`

**Schema delta:** none.

**Red first**

- Stale `syncing` lease returns to pending after restart.
- 250 rows drain in bounded batches without starving fiscal event sync.
- HTTP retry marks failed with backoff and later succeeds.
- Content conflict becomes a durable terminal error and is not overwritten.
- Normal order pushes fiscal events before evidence, while evidence-before-event remains accepted.
- Synced retention removes only rows older than 90 days with server acknowledgement.

**Implementation**

1. Reuse the existing fiscal outbox lifecycle semantics.
2. Process bounded batches after fiscal event push.
3. Lease rows in a write transaction.
4. Send stable IDs and fingerprints.
5. Record acknowledgement before marking synced.
6. Keep authoring disablement independent from draining already-authored rows.

**Reviewer gate:** fiscal-pos.

---

## Task 21 — Add child obligations and the sole lot-projection writer

**Files to create**

- `apps/api/database/migrations/tenant/2026_09_06_100800_create_fiscal_projection_lot_obligations.php`
- `apps/api/app/Modules/POS/Domain/Enums/LotObligationStatus.php`
- `apps/api/app/Modules/POS/Domain/FiscalProjectionLotObligation.php`
- `apps/api/app/Modules/POS/Application/DTOs/LotProjectionResult.php`
- `apps/api/app/Modules/POS/Application/Services/PosReceiptLotProjectionService.php`
- `apps/api/app/Modules/POS/Application/Jobs/ApplyReceiptLotObligationJob.php`
- `apps/api/tests/Feature/Fiscal/PosReceiptLotObligationTest.php`
- `apps/api/tests/Feature/Fiscal/PosReceiptLotRecoveryPostgresTest.php`

**Files to modify**

- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php`
- `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php`
- `apps/api/app/Modules/POS/Domain/ReceiptLineBatchAllocation.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`

**Schema delta:** §8.9.

**Contracts**

```php
public function ensureObligation(
    FiscalEvent $event,
    string $canonicalLineKey,
    string $receiptId,
    ?string $receiptLineId,
    string $productId,
    ?string $variantId,
    string $locationId,
    string $quantity,
    string $operation,
): FiscalProjectionLotObligation;

public function apply(
    FiscalProjectionLotObligation $obligation,
): LotProjectionResult;

public function recoverForEvent(FiscalEvent $event): void;
```

**Red first**

- Module-off sale still creates receipt, aggregate stock, payment, and GL effects, with zero lot legs.
- Unresolved entitlement creates blocked child work and leaves parent applied.
- Existing receipt fast path still invokes `recoverForEvent()`.
- Expected evidence missing does not fall back to FEFO.
- Old client with `lot_evidence_expected=false` uses FEFO and stores `system_fefo_estimate`.
- Captured selection different from FEFO consumes captured lots.
- Invalid captured evidence blocks instead of silently switching to FEFO.
- Evidence arriving later applies exactly one lot effect and never repeats aggregate stock.
- Refund restores the original allocated lot and provenance.
- Every worker test clears `CompanyContext`.

**Implementation**

1. Leave `requiresModule()` returning `null`.
2. During initial core projection, persist each tracked line’s obligation alongside the aggregate effect.
3. Explicit `not_entitled` becomes applied/no-op.
4. Unresolved becomes blocked.
5. New expected-evidence events wait for matched evidence.
6. Legacy/non-expected events use FEFO with estimate provenance.
7. Apply captured lots through strict, product-locked, lot-only consumption.
8. Replace `containLotWork()` log-only loss with child status/reason persistence.
9. On existing receipt fast path, recover children before returning.
10. Use this same service from initial projection, evidence arrival, and manual W4 recovery.
11. Do not add `blocked` to parent `ProjectionStatus`.

**Reviewer gate:** fiscal-pos plus inventory-costing.

---

## Task 22 — Add obligation recovery/health UI and complete L8 core

**Files to create**

- `apps/api/app/Modules/POS/Presentation/Controllers/LotObligationController.php`
- `apps/api/tests/Feature/POS/LotObligationAuthorizationTest.php`
- `apps/web/src/features/pos/components/LotObligationStatus.tsx`
- `apps/web/src/features/pos/components/LotObligationStatus.test.tsx`

**Files to modify**

- `apps/api/app/Modules/POS/routes.php`
- existing fiscal projection detail resource/controller
- existing receipt detail resource/controller
- `apps/web/src/features/pos/pages/ReceiptDetailPage/ReceiptDetailPage.tsx`
- `apps/web/src/features/batches/pages/BatchListPage.tsx`
- relevant web locale files
- `packages/shared/types/generated.d.ts`

**Schema delta:** none.

**Red first**

- Blocked reason and age are visible only to authorized company/location actors.
- Manual retry invokes the same service and is idempotent.
- No UI offers an O-LOT-2 override.
- Applied obligation links to allocations and provenance.

**Implementation**

1. Add read/retry actions to existing receipt/projection detail.
2. Surface unresolved/missing/invalid reasons.
3. Link drift-health rows to affected receipts where known.
4. Do not create a second recovery workflow or stock writer.

**Reviewer gate:** fiscal-pos plus frontend-conventions.

---

## Task 23 — Run second-of-everything, cross-tenant, and end-to-end acceptance

**Files to create**

- `apps/api/tests/Feature/BatchExpiry/WLotSecondOfEverythingPostgresTest.php`
- `apps/api/tests/Feature/BatchExpiry/WLotEndToEndPostgresTest.php`
- `apps/pos/src/__tests__/wLotCapturedSale.integration.test.tsx`
- `apps/web/e2e/w-lot-batch-management.spec.ts`

**Files to modify**

- `apps/api/tests/feature-lane-manifest.json`
- `.github/workflows/ci.yml`
- `docs/qa/MANUAL-TESTING-LOOP.md` if the existing loop lacks the W-LOT journey

**Schema delta:** none.

**Red first**

Implement the three exact convention-09 cases from §10, then:

- Two tenants with colliding company/product/location/operation IDs never see or mutate one another.
- Two terminals at different locations receive different snapshots.
- Branch request blocks one location; general-manager hold blocks both.
- Identify `DEFAULT`, then count the resulting lots, preserving aggregate/WAC and zero GL.
- Captured lot differing from FEFO reaches the allocation as captured.
- Evidence loss preserves fiscal/money/core receipt and leaves a blocked child plus drift.
- Module-off fixture has no lot UI/legs but unchanged aggregate behavior.
- Event-before-evidence and evidence-before-event converge.
- Replays create no duplicate movement, allocation, evidence, request, or obligation.

**Implementation**

1. Use the real company API and real location API.
2. Use two actual product batches, not factory-only placeholders.
3. Run PostgreSQL cases in the private lane and in reachable CI.
4. Run POS and web journeys with real generated DTO shapes.
5. Capture screenshots for recall, identification, count, guidance, captured evidence, and blocked obligation states.

**Reviewer gate:** joint tenancy-authz, inventory-costing, stock/GL, fiscal-pos, and frontend-conventions.

---

## Task 24 — Handback and activation evidence

**File to create**

- `docs/handoff/HANDBACK-W-LOT-2026-09-06.md`

**Files to modify only if evidence requires current references**

- `docs/handoff/LEDGER.md`
- `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md` only after the owner actually rules O-LOT-1/O-LOT-2
- `docs/glossary.md` to change “proposed” to shipped only after merge

**Schema delta:** none.

The handback must include:

- dispatch and final commit hashes;
- exact migration list;
- actual SQLite maximum and confirmation that v68/v69 were not reused;
- red and green transcripts per task;
- reviewer verdict per gate;
- route/middleware diff;
- generated-type and permission-map diff;
- role readiness and role-delta results per tenant;
- pre/post drift census;
- backup and restore rehearsal;
- capability states per terminal;
- L1–L9 evidence map from §27;
- all unresolved owner rows and deferred ticket IDs;
- staging smoke evidence;
- rollback rehearsal result.

**Reviewer gate:** orchestrator promotion gate. No implementer merge or push.

---

## 25. Automatic staging deployment and rollback plan

### 25.1 Preflight before the first migration-bearing push

1. Confirm dispatch HEAD and rebase/merge policy with the orchestrator; never use `git stash`.
2. Verify current tenant migration maximum and SQLite maximum again. If another lane has consumed any reserved filename/version, renumber before code is written.
3. Run the current read-only command for every staging tenant:

   ```bash
   php artisan inventory:lot-drift-census --all-tenants
   ```

4. Run:

   ```bash
   php artisan permissions:w-lot-readiness --all-tenants --json
   ```

   Block activation on unrestricted managers, restricted general managers, missing central actors, unreachable tenants, or incomplete output.

5. Record counts/checksums for:

   - products requiring batch tracking;
   - `product_batches`;
   - `inventory_batch_stock`;
   - `inventory_batch_movements`;
   - active reservations;
   - POS receipt allocations;
   - document batch assignments;
   - transfer allocations;
   - active inventory counts;
   - terminals and app versions.

6. Take central and tenant backups.
7. Restore one central plus representative tenant backup into scratch databases and verify the recorded counts and lot-drift result. A backup without a successful restore rehearsal is not promotion evidence.

### 25.2 Deployment order

1. **Schema-only push:** deploy central revision column and all additive tenant tables/columns/checks/indexes. Every rollout flag and terminal capability remains false.
2. **Compatible server push:** deploy tri-state readers, ingress, recovery, permissions, new API readers, and dormant services. Existing clients continue to operate.
3. **Role readiness:** run role command in dry-run mode; remediate memberships explicitly; execute the targeted delta; reset permission caches. Any failed tenant blocks all behavioral activation.
4. **Lock/writer gate:** land and pass Task 1 plus live PG concurrency before enabling identity/count/projection writers.
5. **Identity replacement:** deploy Tasks 7–9; enable `identity_correction` only after correction and identify endpoints/UI are both ready. The used-lot freeze activates in the same effective flag.
6. **Recall:** enable `recall_workflow` only after role assignment, route authorization, and both transfer enforcement tests pass. Existing held state remains enforced independently of the flag.
7. **Counting/provenance:** enable `lot_counting`, then `provenance`, after their respective gates. Legacy active counts remain version 1.
8. **Census scheduler:** enable only after live-PG drift, failure, wrong-tenant, missed-run, and overlap tests pass.
9. **Server POS readiness:** deploy evidence ingress, evidence storage, lot obligations, and recovery before any device advertises authoring.
10. **Device v68 release:** ship cache/guidance with all terminal flags false. Confirm migration from v67 and fresh install.
11. **Guidance canary:** enable guidance for one reviewed terminal, refresh before shift open, then expand.
12. **Device v69 release:** ship capture/outbox. Keep authoring false until the device has v69 and successful capability acknowledgement.
13. **Evidence authoring canary:** enable one terminal; confirm receipt/evidence atomicity, event/evidence sync, server match, and obligation application.
14. **Projection expansion:** enable `pos_evidence_projection` only after canary evidence is visible server-side and recovery is green.
15. Expand terminal/company scope incrementally with census and obligation health checked after each step.

### 25.3 Backfills

Permitted automatic values:

- technical `identity_version=1`;
- legacy `lot_grain_version=1`;
- legacy `lot_evidence_expected=false`;
- terminal flags false and capability revision zero;
- provenance `unknown`.

Forbidden automatic inference:

- expiry dates;
- operator-captured provenance;
- FEFO provenance for legacy allocations;
- manager location grants;
- general-manager assignments;
- recall requests or releases;
- count child rows;
- lot identification history;
- repair movements.

Any data correction requires an explicit document/service or maintenance command with dry-run, execute, run ID, and audit trail.

### 25.4 Rollback

Normal rollback is capability-based, not destructive:

1. Disable `pos_evidence_authoring` first.
2. Keep evidence ingress, evidence readers, and outbox acknowledgements available so already-authored evidence drains.
3. Disable `pos_guidance` if its cache/display is faulty.
4. Disable `pos_evidence_projection`; leave obligations and evidence intact for later retry.
5. Disable `lot_counting`, `identity_correction`, `recall_workflow`, or `census_scheduler` as needed.
6. Existing recall requests/holds remain enforced. Disabling creation must never release safety state.
7. Existing identification/count/provenance/evidence/history rows remain readable.
8. Do not run production `down()` migrations during ordinary rollback.
9. Old device builds ignore additive terminal fields and never see authoring enabled.
10. If server code must be reverted, retain a compatibility ingress/store-only deployment until every v69 outbox is drained or authoring is proven disabled on every terminal.
11. Restore backups only for disaster recovery after a failed forward repair rehearsal; record any post-backup receipts/evidence that would need replay.

---

## 26. Verification commands

Run focused tests red first and green after each task. Before handback, run the complete set:

```bash
cd apps/api
composer test
./vendor/bin/phpstan
./vendor/bin/pint --test
php artisan route:list --path=batches
php artisan route:list --path=lot-evidence
php artisan typescript:transform
php artisan permissions:export-frontend
```

Private PostgreSQL examples:

```bash
DB_CONNECTION=pgsql \
DB_HOST=127.0.0.1 \
DB_PORT=<private-port>=5453 \
DB_DATABASE=autoerp_test_wlot \
php artisan test tests/Feature/BatchExpiry tests/Feature/Inventory tests/Feature/Fiscal tests/Feature/POS tests/Feature/Security
```

```bash
DB_CONNECTION=pgsql \
DB_HOST=127.0.0.1 \
DB_PORT=<private-port>=5453 \
DB_DATABASE=autoerp_test_wlot \
php artisan test tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php \
  tests/Architecture/InventoryWriterLockManifestTest.php \
  tests/Architecture/LotProvenanceWriterContractTest.php
```

Repository root:

```bash
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm --filter @autoerp/web test:e2e
pnpm --filter @autoerp/pos test
```

Operational staging checks:

```bash
php artisan inventory:lot-drift-census --all-tenants --fail-on-drift
php artisan inventory:lot-drift-monitor --all-tenants
php artisan permissions:w-lot-readiness --all-tenants --json
php artisan permissions:apply-w-lot-delta --all-tenants --dry-run
php artisan permission:cache-reset
```

The role execute command, capability changes, and scheduler enablement run only in the ordered deployment steps, not as part of an ungated test command.

---

## 27. L1–L9 completion map

| Spec item | Implemented by | Required evidence |
|---|---|---|
| L1 — permissions, location scope, escalation | Tasks 3–5 | Route matrix, role/membership tests, branch request/company hold journey |
| L2 — freeze after use and correction history | Tasks 7–9 | Used-update refusal, optimistic correction, deactivation disposition |
| L3 — exact availability | Task 6 | PHPStan liveness, API string contract, unsafe-number web test |
| L4 — lot-grain counts | Tasks 10–12 | Zero-versus-missing, reattribution, PG races, zero GL/WAC proof |
| L5 — provenance | Tasks 13–14 | Three-producer writer contract, conservative migration, trace/export UI |
| L6 — drift and monitoring | Task 15 | Live-PG census, scheduled health, stale/failure/wrong-tenant tests |
| L7 — POS guidance | Tasks 16–17 | Pre-open ordering, scoped cache, watermark netting, all renderer tests |
| L8 — editable captured evidence and consumption | Tasks 18–22 | Atomic local evidence, crash recovery, either arrival order, captured-not-FEFO application, refund lineage. Only override/no-oversell policy remains `WLOT-FOLLOWUP-01`. |
| L9 — DEFAULT identification/split | Tasks 7–9 | Company-scoped idempotency, flat movement, offsetting lot legs, zero GL, unchanged WAC |

The package cannot be marked complete unless the handback maps all nine rows to passing evidence or to an explicitly open/deferred owner row.

---

## 28. Dispatch order and reviewer stop points

1. Task 1 — vocabulary and writer/lock census.
2. Task 2 — tri-state entitlement and rollout infrastructure.
3. Task 3 — role/membership policy and targeted role delta.
4. Tasks 4–5 — recall domain, API, permissions, and web.
5. Task 6 — exact decimal contract.
6. Task 7 — correction/identification schemas.
7. Task 8 — identification writer and common locking.
8. Task 9 — correction/identification API/UI and paired freeze activation.
9. Task 10 — count schema/submission.
10. Task 11 — count reconciliation/concurrency/GL.
11. Task 12 — count UI.
12. Task 13 — provenance schema/writers.
13. Task 14 — provenance readers/UI/exports.
14. Task 15 — durable census monitoring.
15. Task 16 — server lot snapshot and terminal capabilities.
16. Task 17 — SQLite v68 and pre-open guidance.
17. Task 18 — server evidence ingress/storage.
18. Task 19 — SQLite v69 and atomic capture.
19. Task 20 — outbox delivery/recovery.
20. Task 21 — child obligation and sole lot writer.
21. Task 22 — recovery/health UI and L8 completion.
22. Task 23 — second-of-everything and full acceptance.
23. Task 24 — handback, deployment rehearsal, and promotion evidence.

A reviewer rejection stops dispatch of dependent tasks. In particular:

- Task 8 waits for Task 1.
- Task 9 cannot activate before Tasks 7 and 8 pass.
- Task 11 waits for Task 8’s lock gate.
- Task 17 waits for Task 16.
- Device authoring waits for Tasks 18 and 21 server-side.
- `pos_evidence_projection` activation waits for Tasks 19–21 and a canary.
- Reject/release work waits for O-LOT-1.

---

## 29. Final verification checklist

- [ ] Exact dispatch HEAD recorded.
- [ ] Convention-10 matrix has an ID, guarantee, linked competitor evidence or NV, current AutoERP path:line, gap, and decision in every row.
- [ ] Exact Vocabulary line and glossary rows exist.
- [ ] `product_batches` and every new unique-bearing table are classified by convention 09.
- [ ] Second company is created through authenticated `POST /api/v1/companies`, not a raw insert.
- [ ] Second `pos_enabled` location is created through the real API.
- [ ] Re-run tests assert data meaning, explicit replay outcome, and no doubled balances.
- [ ] POS core remains module-independent.
- [ ] Lot entitlement is tri-state and revisioned.
- [ ] Worker tests clear `CompanyContext`.
- [ ] Every BatchExpiry route has module, action permission, and custody rules, except the documented durability ingress.
- [ ] Manager/general-manager membership invariants are atomic.
- [ ] Custom roles remain untouched.
- [ ] Recall request immediately blocks its location.
- [ ] Company hold blocks FEFO, direct batch transfer, and `StockTransferService`.
- [ ] Reject/release remains absent until O-LOT-1 is ruled.
- [ ] Used identity freeze and replacement workflows activate together.
- [ ] L9 operation UUID is company-scoped.
- [ ] L9 refuses active reservations and cross-grain targets.
- [ ] Pure reattribution writes one flat aggregate row, offsetting lot legs, zero GL, unchanged WAC.
- [ ] Complete writer/lock census precedes new writers.
- [ ] Identification/count versus sale/transfer concurrency is exercised on PostgreSQL.
- [ ] No batch quantity crosses a boundary as float/number.
- [ ] Generated DTOs replace hand-written shadows.
- [ ] All new quantity requests enforce scale-4 regex ceilings.
- [ ] Lot count distinguishes explicit zero from no observation.
- [ ] Legacy active counts remain version 1 without invented children.
- [ ] Positive lot-grain count does not inflate `DEFAULT`.
- [ ] Legacy provenance is `unknown`.
- [ ] All three new producer paths write explicit provenance.
- [ ] Trace, exports, returns, and UI preserve provenance.
- [ ] Census health records clean/drifted/failed and derives stale.
- [ ] Scheduler is enabled only after live-PG proof.
- [ ] SQLite v68 and v69 are still the next free versions at implementation time.
- [ ] Lot refresh completes before shift authoring.
- [ ] Failed refresh disables the lot arm without disabling POS core.
- [ ] Snapshot netting uses inclusion watermark, not acknowledgement alone.
- [ ] Pending refunds are not treated as saleable local stock.
- [ ] Every product renderer uses one lot selector/pipeline.
- [ ] Evidence is outside canonical `SALE_RECEIPT` bytes.
- [ ] Receipt, evidence, and outbox commit atomically.
- [ ] Stale outbox leases recover after restart.
- [ ] Evidence/event either arrival order converges.
- [ ] Same evidence ID with changed content conflicts.
- [ ] Child obligation unique key exactly matches `(tenant_id, company_id, fiscal_event_id, canonical_line_key, lot_operation)`.
- [ ] Parent projection status is separate and unchanged.
- [ ] Existing-receipt fast path still runs lot recovery.
- [ ] Captured evidence wins over a different FEFO suggestion.
- [ ] Invalid captured evidence blocks; it is not silently relabelled or replaced.
- [ ] Refund restores original lot/provenance.
- [ ] O-LOT-2 override/no-oversell remains deferred as `WLOT-FOLLOWUP-01`.
- [ ] All L1–L9 rows have handback evidence.
- [ ] Role, permission-cache, capability, backup, restore, canary, and rollback rehearsals are documented.
- [ ] No production rollback depends on destructive down migrations.
