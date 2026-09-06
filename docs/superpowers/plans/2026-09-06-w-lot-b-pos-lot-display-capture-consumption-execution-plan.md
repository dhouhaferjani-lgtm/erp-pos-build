<!-- W-LOT-B rev 4 (split from W-LOT rev 3 at 3f32ffdd8), authored by Codex CLI (gpt-5.6-sol, high, read-only) on 2026-09-06; filed verbatim by the orchestrator. Status: awaiting plan gate r1 (B). Companion: W-LOT-A. -->
# W-LOT-B execution plan — POS lot display, capture and consumption (rev 4, split from W-LOT rev 3)

## Change log and gate disposition

**Baseline:** local `dev` HEAD `a40449249cef3fd178f461e58c60c080463199e3`. All repository citations below refer to that SHA. The worktree also contained two unrelated untracked documents; they are outside this plan and must remain untouched.

| Gate finding | Disposition | Closure |
|---|---|---|
| R1-1 / R2-B6 mechanical dispatch packets | **CLOSED** | PL-T1–PL-T10 name exact files, signatures, red assertion, exact command/lane, reviewer gate and rollback. |
| R1-3 POS core versus lot arm | **CLOSED** | PL-S4, PL-T9 use one aggregate obligation with N child effects, durable blocking, signed-delta reconciliation and late-evidence convergence. |
| R1-4 / R2-B4 writer and lock census | **CLOSED** | PL-LOCK covers device writers, evidence ingress, projection/recovery, refunds, W-LOT-A mutation interfaces, `InventoryGlPostingBuffer`, downstream GL and row/advisory order. |
| R1-5 / R2-B5 deployment order | **CLOSED** | PL-DEPLOY provides five literal pushes, migration ownership, per-tenant output verification, environment recreation, Dokploy polling, fingerprint verification and rollback points. |
| R1-7 quantity/type surface omissions | **REJECTED for W-LOT-B** | Server quantity/provenance conversion is a W-LOT-A prerequisite; W-LOT-B imports its generated DTO and decimal interfaces and introduces no float surface. Gate ownership is recorded at [gate r4:30-33](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-plan-codex-gate-r4.md:30). |
| R1-8 used-lot identity/L9 correction | **REJECTED for W-LOT-B** | L9 is a W-LOT-A prerequisite, not a device-evidence task; spec separates L1–L6/L9 from L7/L8 at [spec v4:457-477](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:457). |
| R1-10 provenance completeness | **CLOSED for W-LOT-B** | PL-T1 defines generated provenance types; PL-T9 writes `operator_captured` or `system_fefo_estimate`. Existing producer census/backfill remains a W-LOT-A prerequisite. |
| R1-11 / r3 Task 17 pre-open and active-cart path | **CLOSED** | PL-T4 owns both branches of `terminalStore.openShift`; PL-T5 owns `TransactionCart` and `CartLineItem`. HEAD’s current non-blocking open hook is at [terminalStore.ts:851-930](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/stores/terminalStore.ts:851), and both active cart render paths are at [TransactionCart.tsx:270-319](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx:270). |
| R1-12 / r3 receipt evidence atomicity | **CLOSED** | PL-T6 writes fiscal event, receipt, evidence header, lines and evidence outbox in the same `withWriteTransaction('fiscal', …)` transaction. HEAD transaction ownership is [receiptService.ts:558-735](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/offline/receiptService.ts:558); `FiscalEventEngine.append` never commits at [FiscalEventEngine.ts:530-542](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/FiscalEventEngine.ts:530). |
| R1-13 W-LOT-B completion overclaim | **CLOSED** | Completion requires every PL-T1–PL-T10 check and PL-VERIFY; no W-LOT-A work is claimed. |
| R1-14 stale baseline/citations | **CLOSED** | This revision declares and cites SHA `a40449249cef3fd178f461e58c60c080463199e3`. |
| R2-B1 / NEW-B1 OPEN Q10 encoded | **REJECTED from scope** | Q10–Q13 are reproduced verbatim as OPEN. No W-LOT-B schema, state, flag, push or task encodes a branch. The gate’s withholding remedy is [gate r4:83-88](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-plan-codex-gate-r4.md:83). |
| R2-B2 aggregate movement link | **CLOSED** | PL-S4 makes `aggregate_stock_movement_id` non-null and unique per obligation; every child effect points to it indirectly and to its own batch movement. |
| R2-B3 / r3 canonical `SMALLINT` defect | **CLOSED** | PL-CANON uses `INTEGER`, `0..999999`, a six-digit index and `VARCHAR(64)` with a maximum 60-byte ASCII key. |
| R2-M4 renderer census | **CLOSED** | PL-T5 names the shipped `NearExpirySlot`, all three callers, active cart, drawer and tests. HEAD confirms the slot currently returns null at [NearExpirySlot.tsx:1-27](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/components/organisms/ProductGrid/NearExpirySlot.tsx:1). |
| R2-M6 vocabulary ambiguity | **CLOSED** | PL-VOC defines every new noun, owner, primary writer and surface before dispatch. |
| r3 multi-lot cardinality | **CLOSED** | PL-S4 models one line obligation and N initial/reversal/replacement effects, matching HEAD’s per-lot loop at [FEFOInventoryService.php:280-321](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:280). |
| r3 generated enums and DTOs | **CLOSED** | PL-T1 names every PHP enum/DTO and generated output; later tasks may not add local domain shadows. Repository generation authority is [CLAUDE.md:33-40](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:33). |
| NEW-B2 impossible signed reconciliation | **CLOSED** | PL-INVARIANT compares cumulative lot effects to `quantity_after - quantity_before`, never to unsigned `stock_movements.quantity`. HEAD stores the sale magnitude positive while reducing stock at [PosCoreReceiptProjection.php:2340-2388](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2340), while FEFO effects are negative at [FEFOInventoryService.php:309-321](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:309). |
| NEW-B3 entitlement fence | **REJECTED as a W-LOT-B task** | PL-PREREQ requires W-LOT-A’s transactional `CompanyModuleEntitlementFence`; W-LOT-B may not implement a second entitlement mechanism. The cross-database hazard is documented at [gate r4:97-102](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-plan-codex-gate-r4.md:97), consistent with database-per-tenant topology at [CLAUDE.md:144-153](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:144). |
| NEW-B4 branch hold eligibility | **REJECTED as a W-LOT-B task** | W-LOT-B consumes W-LOT-A’s one eligibility predicate, including local holds; it may not create recall state. |
| NEW-B5 count watermark | **REJECTED as a W-LOT-B task** | Movement sequence/count ownership belongs to W-LOT-A; no W-LOT-B schema orders inventory history by UUID. |
| NEW-B6 either-arrival-order evidence | **CLOSED** | PL-S3/PL-T8 permit evidence-first storage without an event FK, reconcile from both ingress directions, and return durable 202 rather than dead-lettering. |
| NEW-B7 non-executable rollout | **CLOSED** | PL-T1 and PL-DEPLOY use installed POSIX tools, create every artifact, capture identifiers before reuse, disable automatic migration ownership, force-recreate all Laravel services, poll the exact Dokploy deployment and verify the candidate fingerprint. |
| NEW-B8 Convention 10 round-zero shape | **CLOSED** | The required matrix is directly after this summary, uses the required vocabulary, versions/sources, task decisions and Convention-09 declaration. |
| Convention 09 | **CLOSED** | Every data-bearing server task carries second-company, second-location and rerun assertions; PG schema uniqueness remains company-scoped. Convention authority is [convention 09:37-50](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37). |
| Convention 11 | **CLOSED** | PL-VOC names one primary writer/surface; generated DTOs replace shadows as required by [convention 11:30-49](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:30). |

## Summary

W-LOT-B delivers only the owner-ruled sequence **display → capture → consumption**:

1. **L7a:** device branch-lot eligibility cache, acknowledged session-open refresh, server-identical FEFO suggestion, shipped product/cart/drawer rendering and module-off silence.
2. **L7b:** editable FEFO-prefilled capture, cache validation, receipt-atomic unsealed evidence and an independent durable outbox.
3. **L7c:** server ingress, captured-lot consumption, labelled FEFO fallback, durable child obligations, blocked/recovery behavior, arrival-order convergence and captured refund provenance.

The sealed `SALE_RECEIPT` payload, canonical bytes, fiscal hash, sequence and print output remain byte-for-byte unchanged under D7. The POS-core projector remains always active; only its lot child work is entitlement- and rollout-gated. This follows D3/D4/D7 at [owner rulings:9-12](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:9) and [owner rulings:104-116](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:104).

## Industry baseline (benchmark-first — convention 10)

Flow: POS lot display, capture and downstream consumption. Reference systems: Odoo 18, ERPNext v15/v16, Dolibarr current where verifiable. Sources: [Odoo 18 POS serial/lot numbers](https://www.odoo.com/documentation/18.0/applications/sales/point_of_sale/shop/serial_numbers.html), [Odoo 18 FEFO](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/shipping_receiving/removal_strategies/fefo.html), [ERPNext Serial and Batch Bundle](https://docs.frappe.io/erpnext/serial-and-batch-bundle), [ERPNext inline serial/batch editor](https://docs.frappe.io/erpnext/use-inline-serial-batch-editor).

| ID | Guarantee | Odoo | ERPNext | Dolibarr/NV | AutoERP today path:line | Gap | Decision with MATCH/DEFER/DIVERGE/ALREADY |
|---|---|---|---|---|---|---|---|
| G-SESSION | A POS session begins with current location-bound lot data. | Lot/serial operation is tied to the loaded POS session and stock location. | POS Profile/Warehouse scopes stock and batch selection. | NV. | `apps/pos/src/stores/terminalStore.ts:851-930` | Shift open only launches a non-fatal aggregate-stock refresh after opening. | **MATCH — T4** |
| G-ELIGIBILITY | Suggestions contain only sale-eligible lots and exact available quantities. | Lot eligibility follows product, location and removal rules. | Batch bundle selection respects item, warehouse and available quantity. | NV. | `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:81-112` | No device branch-lot snapshot; no acknowledged entitlement revision. | **MATCH — T3/T4** |
| G-FEFO | Client and server choose the same earliest-expiry order, deterministically. | FEFO uses earliest removal/expiration date. | Batch selection can be FIFO/expiry-aware and is recorded in the bundle. | NV. | `FEFOInventoryService.php:93-95,260-273` | Suggestion/consume lack a final batch-id tie-break; no shared POS comparator. | **MATCH — T3/T5** |
| G-DISPLAY | The cashier sees the suggested lot and expiry before tender. | POS exposes lot/serial selection during sale. | Batch editor displays and selects batch rows. | NV. | `apps/pos/src/components/organisms/ProductGrid/NearExpirySlot.tsx:1-27` | Shipped slot renders nothing; cart and drawer expose no lot. | **MATCH — T5** |
| G-CAPTURE | The suggested lot is editable when the physical shelf item differs. | Cashier enters/selects lot or serial data on the order line. | Inline batch editor supports explicit batch allocation. | NV. | `apps/pos/src/types/cart.ts:11-50`; `receiptService.ts:607-685` | Cart and receipt mirror contain no lot capture. | **MATCH — T6** |
| G-SIDECAR | Operational lot evidence can be retained without changing fiscal receipt truth. | Lot allocation is operational stock data adjacent to the POS order. | Serial/Batch Bundle links operational stock allocation to the transaction. | NV. | `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:530-542`; `apps/api/app/Modules/Fiscal/routes.php:18-53` | Only the sealed fiscal event has a device outbox/ingress contract. | **DIVERGE — T6–T8**, because D7 expressly keeps lot evidence outside the fiscal hash. |
| G-CONSUME | One receipt line may consume N lots, with durable links to the aggregate stock movement. | A move line can represent lot-specific quantities under one stock move. | One bundle can hold multiple batch entries for one transaction line. | NV. | `FEFOInventoryService.php:280-321`; `PosCoreReceiptProjection.php:1938-1974` | N batch movements exist, but no durable child obligation/evidence link or blocked recovery. | **MATCH — T2/T9** |
| G-REFUND | A refund credits the lot actually attributed to the original sale. | Returns preserve stock-move/lot traceability. | Return documents derive serial/batch detail from the original transaction. | NV. | `PosCoreReceiptProjection.php:2431-2551,2684-2701`; `ReceiptReturnService.php:1900-1937` | Readers see only estimated allocation snapshots, never captured evidence. | **MATCH — T9** |
| G-MODULE-OFF | A tenant without BatchExpiry sees no lot chrome and incurs no lot write. | Lot UI appears only for tracked products/configuration. | Batch fields appear only for batch-managed items. | NV. | `ProductDetailDrawer.tsx:81-117,297-302` | `stock_lots` is currently unconditional. | **MATCH — T5** |
| G-SEALED | Introducing lot capture does not rewrite an already-certified receipt payload. | Operational lots are distinct from fiscal receipt signing. | Batch bundle is linked transaction data, not a rewrite of posted accounting identity. | NV. | `receiptService.ts:585-685`; `FiscalEventEngine.ts:539-542` | No sidecar exists. | **DIVERGE — T6/T8** under ruled D7; sealed payload change is forbidden. |

Decision vocabulary: **MATCH** reproduces the benchmark guarantee; **ALREADY** means HEAD already supplies it; **DIVERGE** is an explicit ruled difference; **DEFER** requires a named later lane/ticket.

Second-of-everything declaration: W-LOT-B touches company-, location-, terminal- and product-scoped operational data. T3, T4, T8 and T9 therefore must prove a second company, a second location and exact rerun/idempotency on live PostgreSQL or device SQLite as applicable. Primary-key uniques are exempt; every business unique must include `company_id` directly or be a child key whose company-owned parent and deferred ownership trigger are documented. The live schema ratchet remains mandatory at [convention 09:52-72](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:52).

## PL-OPEN — owner questions that remain OPEN

Status for every row below: **OPEN**. The rows are copied verbatim from [OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:138-143](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:138).

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

Binding neutrality rule:

- No W-LOT-B migration, enum, state transition, command, feature flag, device behavior, push or test may encode a Q10–Q13 branch.
- W-LOT-B creates no recall-release, drawer-sharing, typed cash-operation or historical-alignment field.
- If implementation discovers that a task requires one of those decisions, withhold that task behind the ruling. Do not add a temporary state or “default for now.”
- The generic `ProjectionStatus` addition is policy-neutral: `pending`, `running`, `blocked`, `applied`, `dead_lettered` describe technical child-work execution and are true under every Q10–Q13 outcome.

## PL-SCOPE — boundaries and prerequisites

Included:

- Device eligibility cache and session-open acknowledgement.
- FEFO calculation and product/cart/drawer presentation.
- Editable receipt-line capture.
- Receipt-atomic evidence and independent evidence outbox.
- Authenticated evidence ingress and arrival-order reconciliation.
- Lot obligations/effects, recovery and refund provenance.
- Rollout plumbing and verification needed to ship those behaviors.

Excluded:

- Recall permissions/lifecycle/hold implementation.
- Used-lot identity freeze or correction.
- L9 DEFAULT identification.
- Lot-grain physical count implementation.
- General lot provenance backfill.
- Entitlement mutation/fencing implementation.
- General inventory writer replacement or global lock-census remediation.
- Q10–Q13 decisions.
- Any sealed `SALE_RECEIPT` payload/version/hash/print change.

### W-LOT-A prerequisite interfaces

Before T3 begins, the candidate must contain and pass W-LOT-A’s implementation of:

- `App\Modules\BatchExpiry\Application\Services\CompanyModuleEntitlementFence`
- `CompanyModuleEntitlementState::{Entitled,NotEntitled,EntitlementUnresolved}`
- `CompanyModuleEntitlementDecisionData`, including acknowledged monotonic revision
- `BatchStockMutationService`
- one canonical eligibility predicate covering company, product/variant, location, active, global recall, expiry, reservations and requested local hold
- product-first canonical advisory/row lock order
- exact decimal lot quantities
- `lot_provenance` with `operator_captured|system_fefo_estimate|unknown`
- hold, freeze, L9, lot-count and provenance writer coverage
- `InventoryWriterLockManifestTest`, `InventoryGlPostingViaBufferOnlyTest` and the W-LOT-A PG concurrency suite

These are interfaces, not W-LOT-B tasks. If any is absent or red, T3–T10 remain undispatchable. W-LOT-B must not build a competing adapter, eligibility predicate, mutation service or lock order.

## PL-VOC — vocabulary and single ownership

Add or reconcile these rows in `docs/glossary.md` before production code:

| Concept | Canonical storage / module | Primary writer | Operator surface | Permitted synonym |
|---|---|---|---|---|
| Branch lot eligibility snapshot | SQLite `branch_lot_snapshots` + `branch_lot_eligibility`; POS | `BranchLotEligibilityRepository::replaceSnapshot` | session age indicator, product lot views | lot cache |
| Lot suggestion | derived, no table; POS | `selectFefoSuggestion` | `NearExpirySlot`, cart and stock-lots tab | FEFO suggestion |
| Lot evidence submission | `pos_receipt_lot_evidence_submissions`; Fiscal | `LotEvidenceIngressService::ingest` | receipt projection detail/recovery | captured lot evidence |
| Lot evidence line | `pos_receipt_line_lot_evidence`; Fiscal | same ingress service | child of evidence submission | captured allocation |
| Lot evidence outbox item | SQLite `lot_evidence_outbox`; POS | `ReceiptLotEvidenceRepository::insertInsideReceipt` | sync diagnostics only | evidence delivery |
| POS lot obligation | `pos_receipt_lot_obligations`; POS | `PosReceiptLotObligationService` | fiscal projection detail/recovery | lot child work |
| POS lot effect | `pos_receipt_lot_obligation_effects`; POS | same obligation service | child of obligation | batch movement leg |
| Obligation evidence link | `pos_receipt_lot_obligation_evidence_links`; POS | same obligation service | projection detail | reconciliation link |

No second operator dashboard is introduced. UI remains in the shipped product tile/list/table, cart line and product drawer. Persistence row interfaces may exist inside repository files, but domain DTOs must come from `packages/shared/types/generated.d.ts`.

## PL-CANON — canonical line key and evidence fingerprint

Canonical line key v1:

```text
sr:v1:<lowercase-hyphenated-fiscal-event-uuid>:<payload-version>:<zero-padded-six-digit-line-index>
```

Constraints:

- `payload-version`: PostgreSQL/TypeScript integer `1..2147483647`.
- `line-index`: PostgreSQL/TypeScript integer `0..999999`.
- UUID: lowercase RFC-4122 textual form.
- Regex: `^sr:v1:[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}:[1-9][0-9]{0,9}:[0-9]{6}$`.
- Storage: ASCII `VARCHAR(64)`. Maximum accepted length is 60 bytes, so every accepted key fits.
- Line order is the zero-based order in the already-sealed `line_items` array. The key is derived after `FiscalEventEngine.append` returns the fiscal event ID/hash; it is not inserted into canonical payload bytes.

Shared signatures:

```php
final class CanonicalSaleReceiptLineKey
{
    public static function make(
        string $fiscalEventId,
        int $payloadVersion,
        int $zeroBasedLineIndex,
    ): string;

    public static function parse(string $key): CanonicalSaleReceiptLineKeyData;
}
```

```ts
export function makeCanonicalSaleReceiptLineKey(
  fiscalEventId: string,
  payloadVersion: number,
  zeroBasedLineIndex: number,
): string;

export function parseCanonicalSaleReceiptLineKey(
  key: string,
): CanonicalSaleReceiptLineKeyData;
```

Evidence fingerprint v1 is SHA-256 of UTF-8 canonical JSON with fixed key order and no insignificant whitespace:

```text
version, submission_id, tenant_id, company_id, location_id, terminal_id,
fiscal_event_id, fiscal_event_hash, payload_version, client_operation_uuid,
entitlement_revision, snapshot_revision, snapshot_watermark,
captured_by, captured_at_device,
lines sorted by (canonical_line_key, allocation_index), each containing:
canonical_line_key, line_index, allocation_index, product_id, variant_id,
batch_id, quantity="scale-4", provenance="operator_captured"
```

The shared vector file must cover index `0`, `999999`, rejected `1000000`, payload version `2147483647`, rejected overflow, non-lowercase UUID, Unicode rejection, reordered input lines producing the same fingerprint, and one-byte content changes producing different fingerprints.

## PL-SCHEMA — complete migration contracts

### S1 — device SQLite migration v68

`apps/pos/src/lib/db/migrations.ts` currently ends at v67 at [migrations.ts:2163-2183](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/db/migrations.ts:2163). Reserve **v68** exactly for eligibility.

`branch_lot_snapshots`:

| Column | Contract |
|---|---|
| `id` | `TEXT PRIMARY KEY NOT NULL`; deterministic `company_id:location_id:terminal_id`. |
| `tenant_id` | `TEXT NOT NULL`. |
| `company_id` | `TEXT NOT NULL`. |
| `location_id` | `TEXT NOT NULL`. |
| `terminal_id` | `TEXT NOT NULL`. |
| `entitlement_state` | `TEXT NOT NULL CHECK IN ('entitled','not_entitled','entitlement_unresolved')`. |
| `entitlement_revision` | `INTEGER NOT NULL CHECK >= 1`. |
| `snapshot_revision` | `INTEGER NOT NULL CHECK >= 1`. |
| `snapshot_watermark` | `TEXT NOT NULL CHECK(length BETWEEN 1 AND 128)`. |
| `server_captured_at` | `TEXT NOT NULL`; exact SQLite UTC `YYYY-MM-DD HH:MM:SS`. |
| `device_acknowledged_at` | same type/format, written through `toSqliteUtc()`. |
| `created_at`, `updated_at` | `TEXT NOT NULL DEFAULT (datetime('now'))`. |

Constraints/indexes:

- `UNIQUE(company_id,location_id,terminal_id)`.
- `CHECK(device_acknowledged_at >= server_captured_at)` as lexicographically comparable SQLite UTC.
- Index `(company_id,terminal_id,entitlement_revision)`.

`branch_lot_eligibility`:

| Column | Contract |
|---|---|
| `snapshot_id` | `TEXT NOT NULL REFERENCES branch_lot_snapshots(id) ON DELETE CASCADE`. |
| `tenant_id`, `company_id`, `location_id` | `TEXT NOT NULL`. |
| `product_id` | `TEXT NOT NULL`. |
| `variant_id` | `TEXT NULL`; normalized repository comparison uses `''` only in query bindings, never storage. |
| `batch_id` | `INTEGER NOT NULL CHECK > 0`. |
| `batch_uuid` | `TEXT NOT NULL`. |
| `batch_number` | `TEXT NOT NULL CHECK(length BETWEEN 1 AND 100)`. |
| `expiry_date` | `TEXT NULL CHECK NULL OR YYYY-MM-DD`. |
| `batch_created_at` | SQLite UTC `TEXT NOT NULL`. |
| `server_available_quantity` | fixed scale-4 decimal `TEXT NOT NULL`; integer component 1–11 digits, exactly four fractional digits, no sign, no exponent, no leading zero except `0`, numeric value `>=0`. |
| `requires_batch_tracking` | `INTEGER NOT NULL CHECK IN (0,1)`. |
| `created_at`, `updated_at` | SQLite UTC text defaults. |

Keys/indexes:

- `PRIMARY KEY(snapshot_id,batch_id)`.
- `UNIQUE(snapshot_id,product_id,variant_id,batch_id)`.
- `(snapshot_id,product_id,variant_id,expiry_date,batch_created_at,batch_id)`.
- `(company_id,location_id,batch_id)`.
- Repository rejects company/location mismatch with parent; a trigger enforces copied `tenant_id/company_id/location_id` equality on insert/update.

`branch_lot_snapshot_coverage`:

- `snapshot_id TEXT NOT NULL REFERENCES branch_lot_snapshots(id) ON DELETE CASCADE`
- `fiscal_event_id TEXT NOT NULL`
- `created_at TEXT NOT NULL DEFAULT(datetime('now'))`
- `PRIMARY KEY(snapshot_id,fiscal_event_id)`

All API timestamps pass through `toSqliteUtc()` per the cross-layer rule at [CLAUDE.md:81-86](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:81).

### S2 — device SQLite migration v69

Reserve **v69** exactly for capture and delivery.

`receipt_lot_evidence_submissions`:

- `id TEXT PRIMARY KEY NOT NULL`
- `tenant_id`, `company_id`, `location_id`, `terminal_id`, `fiscal_event_id`, `fiscal_event_hash`, `client_operation_uuid`, `snapshot_id`, `snapshot_watermark`, `captured_by`, `operation_fingerprint`: `TEXT NOT NULL`
- `payload_version INTEGER NOT NULL CHECK BETWEEN 1 AND 2147483647`
- `entitlement_revision INTEGER NOT NULL CHECK >=1`
- `snapshot_revision INTEGER NOT NULL CHECK >=1`
- `captured_at_device TEXT NOT NULL` in SQLite UTC
- `canonical_key_version INTEGER NOT NULL DEFAULT 1 CHECK =1`
- `created_at`, `updated_at TEXT NOT NULL DEFAULT(datetime('now'))`
- `UNIQUE(company_id,terminal_id,client_operation_uuid)`
- `UNIQUE(company_id,fiscal_event_id)`
- checks: hashes/fingerprint are lowercase 64-hex; UUID text is canonical lowercase; snapshot/company/location/terminal match is verified by repository before insert
- indexes `(company_id,fiscal_event_id,fiscal_event_hash)` and `(company_id,created_at)`

`receipt_line_lot_evidence`:

- `id TEXT PRIMARY KEY NOT NULL`
- `submission_id TEXT NOT NULL REFERENCES receipt_lot_evidence_submissions(id) ON DELETE RESTRICT`
- `canonical_line_key TEXT NOT NULL CHECK length<=64`
- `line_index INTEGER NOT NULL CHECK BETWEEN 0 AND 999999`
- `allocation_index INTEGER NOT NULL CHECK BETWEEN 0 AND 999999`
- `product_id TEXT NOT NULL`
- `variant_id TEXT NULL`
- `batch_id INTEGER NOT NULL CHECK >0`
- `quantity TEXT NOT NULL` under the same fixed-scale-4 decimal check as S1 and value `>0.0000`
- `provenance TEXT NOT NULL DEFAULT 'operator_captured' CHECK = 'operator_captured'`
- `created_at TEXT NOT NULL DEFAULT(datetime('now'))`
- `UNIQUE(submission_id,canonical_line_key,allocation_index)`
- index `(submission_id,batch_id)`

`lot_evidence_outbox`:

- `id TEXT PRIMARY KEY NOT NULL`
- `submission_id TEXT NOT NULL UNIQUE REFERENCES receipt_lot_evidence_submissions(id) ON DELETE RESTRICT`
- `fiscal_event_id TEXT NOT NULL`
- `fiscal_event_hash TEXT NOT NULL`
- `operation_fingerprint TEXT NOT NULL`
- `payload_version INTEGER NOT NULL DEFAULT 1 CHECK =1`
- `state TEXT NOT NULL DEFAULT 'pending' CHECK IN ('pending','sending','acknowledged','retry','dead_lettered')`
- `attempts INTEGER NOT NULL DEFAULT 0 CHECK >=0`
- `lease_token TEXT NULL`, `lease_owner TEXT NULL`, `lease_expires_at TEXT NULL`
- `next_attempt_at TEXT NULL`, `acknowledged_at TEXT NULL`, `last_error_code TEXT NULL`, `last_error_detail TEXT NULL`
- `created_at`, `updated_at TEXT NOT NULL DEFAULT(datetime('now'))`
- state checks:
  - `pending`: no lease/ack/error
  - `sending`: all three lease fields non-null and no acknowledgement
  - `retry`: no lease, `next_attempt_at` and error code non-null
  - `acknowledged`: acknowledgement non-null, no lease/error
  - `dead_lettered`: error code non-null and no lease
- indexes `(state,next_attempt_at,created_at)` and `(fiscal_event_id,fiscal_event_hash)`

### S3 — tenant migration `2026_09_06_200000_create_pos_lot_evidence_tables.php`

No JSONB column is allowed; normalized header/line storage means no JSONB DTO exception exists.

`pos_receipt_lot_evidence_submissions`:

- `id UUID PRIMARY KEY`
- `tenant_id UUID NOT NULL`
- `company_id UUID NOT NULL REFERENCES companies(id) ON DELETE RESTRICT`
- `location_id UUID NOT NULL REFERENCES locations(id) ON DELETE RESTRICT`
- `terminal_id UUID NOT NULL REFERENCES pos_terminals(id) ON DELETE RESTRICT`
- `fiscal_event_id UUID NOT NULL`, deliberately **no FK** so evidence may arrive first
- `fiscal_event_hash CHAR(64) NOT NULL`
- `payload_version INTEGER NOT NULL CHECK BETWEEN 1 AND 2147483647`
- `client_operation_uuid UUID NOT NULL`
- `operation_fingerprint CHAR(64) NOT NULL`
- `canonical_key_version SMALLINT NOT NULL DEFAULT 1 CHECK =1`; this is a version, not a line index
- `entitlement_revision BIGINT NOT NULL CHECK >=1`
- `snapshot_revision BIGINT NOT NULL CHECK >=1`
- `snapshot_watermark VARCHAR(128) NOT NULL`
- `captured_by UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT`
- `captured_at_device TIMESTAMPTZ NOT NULL`
- `validation_status VARCHAR(24) NOT NULL DEFAULT 'pending_event' CHECK IN ('pending_event','validated','blocked')`
- `validation_reason VARCHAR(64) NULL`
- `validated_at TIMESTAMPTZ NULL`
- `created_at`, `updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `UNIQUE(company_id,terminal_id,client_operation_uuid)`
- `UNIQUE(company_id,fiscal_event_id)`
- checks:
  - lowercase hex hashes/fingerprint
  - `pending_event` has null reason/time
  - `validated` has non-null `validated_at` and null reason
  - `blocked` has non-null `validated_at` and reason
- indexes `(company_id,validation_status,created_at)`, `(company_id,fiscal_event_id,fiscal_event_hash)`, `(tenant_id,company_id,terminal_id)`
- deferred ownership trigger verifies location, terminal and user belong to the same tenant/company

`pos_receipt_line_lot_evidence`:

- `id UUID PRIMARY KEY`
- `submission_id UUID NOT NULL REFERENCES pos_receipt_lot_evidence_submissions(id) ON DELETE RESTRICT`
- `tenant_id UUID NOT NULL`
- `company_id UUID NOT NULL REFERENCES companies(id) ON DELETE RESTRICT`
- `fiscal_event_id UUID NOT NULL`, no FK
- `canonical_line_key VARCHAR(64) NOT NULL`
- `line_index INTEGER NOT NULL CHECK BETWEEN 0 AND 999999`
- `allocation_index INTEGER NOT NULL CHECK BETWEEN 0 AND 999999`
- `product_id UUID NOT NULL REFERENCES products(id) ON DELETE RESTRICT`
- `variant_id UUID NULL REFERENCES product_variants(id) ON DELETE RESTRICT`
- `batch_id BIGINT NOT NULL REFERENCES product_batches(id) ON DELETE RESTRICT`
- `quantity DECIMAL(15,4) NOT NULL CHECK >0`
- `provenance VARCHAR(24) NOT NULL DEFAULT 'operator_captured' CHECK = 'operator_captured'`
- `created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `UNIQUE(submission_id,canonical_line_key,allocation_index)`
- indexes `(company_id,fiscal_event_id,canonical_line_key)`, `(submission_id,batch_id)`
- checks canonical key regex and line index equality with parsed final six digits
- deferred trigger enforces copied tenant/company/event and product/variant/batch ownership against the parent submission

### S4 — tenant migration `2026_09_06_200100_create_pos_lot_projection_obligations.php`

Extend `ProjectionStatus` with `Blocked='blocked'`; HEAD currently has only four values at [ProjectionStatus.php:7-12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php:7).

`pos_receipt_lot_obligations`:

- `id UUID PRIMARY KEY`
- `tenant_id UUID NOT NULL`
- `company_id UUID NOT NULL REFERENCES companies(id) ON DELETE RESTRICT`
- `fiscal_event_id UUID NOT NULL REFERENCES fiscal_events(id) ON DELETE RESTRICT`
- `receipt_id UUID NOT NULL REFERENCES pos_receipts(id) ON DELETE RESTRICT`
- `receipt_line_id UUID NOT NULL REFERENCES pos_receipt_lines(id) ON DELETE RESTRICT`
- `canonical_line_key VARCHAR(64) NOT NULL`
- `operation VARCHAR(16) NOT NULL CHECK IN ('consume','restore')`
- `aggregate_stock_movement_id UUID NOT NULL REFERENCES stock_movements(id) ON DELETE RESTRICT`
- `expected_quantity DECIMAL(15,4) NOT NULL CHECK >0`
- `expected_signed_delta DECIMAL(15,4) NOT NULL CHECK <>0`
- `initial_provenance VARCHAR(24) NOT NULL CHECK IN ('operator_captured','system_fefo_estimate')`
- `projection_status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK IN ('pending','running','blocked','applied','dead_lettered')`
- `generation INTEGER NOT NULL DEFAULT 0 CHECK >=0`
- `applied_generation INTEGER NULL CHECK >=0`
- `attempts INTEGER NOT NULL DEFAULT 0 CHECK >=0`
- `lease_token UUID NULL`, `lease_owner VARCHAR(128) NULL`, `lease_expires_at TIMESTAMPTZ NULL`
- `next_attempt_at TIMESTAMPTZ NULL`
- `blocked_reason VARCHAR(64) NULL`
- `last_error TEXT NULL`
- `last_attempted_at`, `applied_at`, `dead_lettered_at TIMESTAMPTZ NULL`
- `created_at`, `updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `UNIQUE(company_id,fiscal_event_id,canonical_line_key,operation)`
- `UNIQUE(company_id,aggregate_stock_movement_id,canonical_line_key,operation)`
- indexes `(company_id,projection_status,next_attempt_at)`, `(fiscal_event_id)`, `(receipt_line_id)`
- state checks:
  - `pending`: no lease; `applied_at/dead_lettered_at` null
  - `running`: complete lease tuple and `last_attempted_at` non-null
  - `blocked`: no lease, `blocked_reason` non-null
  - `applied`: no lease/block, `applied_generation=generation`, `applied_at` non-null
  - `dead_lettered`: no lease, `dead_lettered_at` and `last_error` non-null
  - `consume => expected_signed_delta<0`; `restore => >0`
- deferred trigger verifies expected signed delta equals its aggregate movement’s `quantity_after - quantity_before`

`pos_receipt_lot_obligation_effects`:

- `id UUID PRIMARY KEY`
- `obligation_id UUID NOT NULL REFERENCES pos_receipt_lot_obligations(id) ON DELETE RESTRICT`
- `generation INTEGER NOT NULL CHECK >=0`
- `effect_index INTEGER NOT NULL CHECK BETWEEN 0 AND 999999`
- `effect_kind VARCHAR(16) NOT NULL CHECK IN ('initial','reversal','replacement')`
- `batch_id BIGINT NOT NULL REFERENCES product_batches(id) ON DELETE RESTRICT`
- `evidence_line_id UUID NULL REFERENCES pos_receipt_line_lot_evidence(id) ON DELETE RESTRICT`
- `signed_quantity DECIMAL(15,4) NOT NULL CHECK <>0`
- `inventory_batch_movement_id BIGINT NOT NULL REFERENCES inventory_batch_movements(id) ON DELETE RESTRICT`
- `created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `UNIQUE(obligation_id,generation,effect_index)`
- `UNIQUE(inventory_batch_movement_id)`
- indexes `(obligation_id,batch_id)`, `(evidence_line_id)`
- deferred trigger verifies the batch movement has the obligation’s aggregate movement ID, same batch and identical signed quantity

`pos_receipt_lot_obligation_evidence_links`:

- `id UUID PRIMARY KEY`
- `obligation_id UUID NOT NULL REFERENCES pos_receipt_lot_obligations(id) ON DELETE RESTRICT`
- `submission_id UUID NOT NULL REFERENCES pos_receipt_lot_evidence_submissions(id) ON DELETE RESTRICT`
- `generation INTEGER NOT NULL CHECK >=0`
- `outcome VARCHAR(16) NOT NULL CHECK IN ('exact_match','corrected')`
- `linked_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `UNIQUE(obligation_id,submission_id)`
- `UNIQUE(obligation_id,generation)`
- deferred trigger enforces the same company/event and requires submission status `validated`

Deferred applied invariant:

```text
SUM(all effect.signed_quantity for obligation)
  = stock_movements.quantity_after - stock_movements.quantity_before
  = obligation.expected_signed_delta
```

It also checks each child batch movement and prevents `applied` while any mismatch exists. For an initial `-Q` estimate later corrected to captured lots, append `+Q` reversal effects and `-Q` replacement effects; cumulative sum remains `-Q`. No aggregate movement, stock level, WAC or GL row is replayed.

## PL-STATE — state machines

### Server evidence

```text
missing
  ├─ evidence first ─> pending_event
  │                     ├─ matching fiscal event ─> validated
  │                     └─ mismatched hash/scope/line ─> blocked
  └─ event first + valid evidence ─> validated
```

- Exact same submission ID and fingerprint returns its existing 200/202 result.
- Same submission ID or `(company,terminal,client_operation_uuid)` with different fingerprint returns 409 `LOT_EVIDENCE_CONTENT_CONFLICT`; existing rows do not mutate.
- Malformed or unauthorized content returns 422/403 and writes zero rows.
- Missing fiscal event is a durable 202 `pending_event`, never a dead letter.
- A pending submission is reconciled inside event ingress before projectors are dispatched.

### Lot obligation

```text
pending -> running -> applied
                   -> blocked
                   -> pending          retryable infrastructure failure
                   -> dead_lettered    exhausted infrastructure failure

blocked -> pending                     dependency/stock/entitlement recovery
applied -> pending                     only when a newly validated evidence
                                        submission increments generation
```

Expected invalid evidence may be marked blocked while a no-evidence projection uses FEFO fallback. A batch shortfall blocks only the child obligation. Receipt, VAT, payment, aggregate stock movement and inventory GL remain projected.

## PL-INVARIANT — non-negotiable behavior

- `PosCoreReceiptProjection::requiresModule()` stays `null`; HEAD’s always-active contract is [PosCoreReceiptProjection.php:211-224](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:211) and registry behavior is [FiscalEventProjectionRegistry.php:233-275](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php:233).
- BatchExpiry entitlement gates the lot child only.
- Server availability already nets reservations; the device additionally subtracts uncovered local pending sales and current-cart allocations. HEAD’s aggregate formula is [availability.ts:195-244](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/stock/availability.ts:195) and cart entry passes through [stockGate.ts:52-114](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/stock/stockGate.ts:52).
- FEFO order is exactly:
  1. dated before undated;
  2. `expiry_date ASC`;
  3. `batch_created_at ASC`;
  4. `batch_id ASC`.
- The server suggestion query and locked consume query both add the missing final `batch_id` tie-break to HEAD’s current order at [FEFOInventoryService.php:81-114](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:81) and [FEFOInventoryService.php:260-273](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:260).
- Module off renders no chip, cart allocation, edit affordance or `stock_lots` tab and writes no lot evidence.
- Captured allocations must sum exactly to the cart line quantity at scale 4.
- Training receipts write neither lot evidence nor obligations.
- Evidence never modifies sealed payload/canonical bytes/hash/print.
- Every estimate is labelled `system_fefo_estimate`; it must never silently become captured.
- Refund lookup uses validated evidence when present; otherwise it uses the explicit estimate provenance.
- One receipt line may generate N batch effects.
- Cumulative signed batch effects reconcile to aggregate `quantity_after - quantity_before`, not unsigned movement magnitude.
- Lot-only late reconciliation creates no `StockMovement`, WAC or GL entry.

## PL-LOCK — complete writer and lock census

Canonical order inherited from W-LOT-A:

1. Central entitlement transaction and tenant/module advisory lock.
2. Bind tenant DB while retaining the entitlement lock.
3. Sorted product advisory lock.
4. Aggregate `stock_levels` row lock.
5. Eligible `product_batches` / `inventory_batch_stock` row locks in FEFO order.
6. Reservation rows where applicable.
7. Aggregate `stock_movements` insert/update.
8. Lot obligation row and child batch effects/movements.
9. Receipt allocation/provenance/evidence links.
10. Inventory GL buffer flush.
11. GL numbering/company-chain locks.
12. Commit tenant transaction, tear down tenancy, commit central entitlement transaction.

| Writer/read owner | Writes | Locks and rule |
|---|---|---|
| `apps/pos/src/lib/sync/syncService.ts` + `BranchLotEligibilityRepository` | v68 snapshots/coverage/cache | One `withWriteTransaction('sync')`; complete response before replace; never interleave partial pages. HEAD aggregate pull already follows all-pages-before-write at [syncService.ts:1015-1163](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/sync/syncService.ts:1015). |
| `apps/pos/src/lib/offline/receiptService.ts` | fiscal event, receipt, evidence, evidence lines, evidence outbox | One `withWriteTransaction('fiscal')`; no nested gated writer. |
| `apps/pos/src/lib/sync/lotEvidenceSync.ts` | evidence outbox lease/retry/ack | Short SQLite lease transaction; no fiscal-chain row mutation. |
| `LotEvidenceIngressService` | server evidence header/lines/status | Locks only competing submission/event identity rows; acquires no inventory lock. |
| `OutboxIngestor` | fiscal event and evidence reconciliation | Reconcile pending evidence before `dispatchProjections`; HEAD dispatch occurs from [OutboxIngestor.php:157-268](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:157). |
| `PosCoreReceiptProjection` | receipt, aggregate stock, aggregate movement, lot obligation/effects, allocation | Always-active aggregate path; lot work goes through W-LOT-A entitlement/product lock and canonical mutation service. |
| `PosReceiptLotObligationService` | obligation state/effects/evidence link | Live projection already owns entitlement/product lock. Recovery first claims a lease in a short transaction and commits it; it then acquires entitlement/product locks and re-locks the obligation by lease token. No obligation row lock survives while acquiring an advisory/product lock. |
| `FEFOInventoryService` / W-LOT-A `BatchStockMutationService` | batch stock/movement | No competing direct mutation; batch rows lock after product serialization. Existing per-lot emission is [FEFOInventoryService.php:280-321](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:280). |
| `ReceiptReturnService` | aggregate return movement and lot restore | Product/stock/lot order above; captured evidence is read after original sale identity is fixed. HEAD stock-level lock is [ReceiptReturnService.php:1536-1644](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1536). |
| `InventoryGlPostingBuffer` | queues inventory GL contexts and flushes them | Sole POS inventory→GL owner. `enqueue` is pure; `flushIfOutermost` requires root transaction and invokes `InventoryGlPostingService` at [InventoryGlPostingBuffer.php:17-92](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:17). Lot-only evidence correction queues nothing. |
| `InventoryGlPostingService` | inventory JEs | Called only by the buffer after inventory locks. |
| `GeneralLedgerService.php` | journal numbering, entries and fiscal chain | Remains downstream of the buffer; it never calls back into inventory. |
| W-LOT-A recall, count, identity, transfer, reservation and entitlement writers | prerequisite inventory state | Remain in the W-LOT-A manifest and canonical order; W-LOT-B introduces no alternate path. |

Architecture enforcement:

- Extend `apps/api/tests/Architecture/InventoryWriterLockManifestTest.php` with the obligation service/recovery job and no new direct batch-stock writer.
- Keep `InventoryGlPostingViaBufferOnlyTest` green.
- Add `apps/api/tests/Architecture/WLotBWriterOwnershipTest.php` to prove:
  - one device receipt-evidence writer;
  - one server evidence writer;
  - one obligation/effect writer;
  - no direct `inventory_batch_stock` mutation outside the W-LOT-A service;
  - no GL call in late evidence reconciliation.
- PG concurrency test runs live projection versus recovery in both directions and fails on deadlock, double effect or lease inversion.

## Dispatch tasks

Reviewer invocation for every task:

```text
Run each named reviewer file against merge-base(origin/dev)..HEAD.
Prompt: "Review only W-LOT-B Task <ID> against rev 4. Verify every listed
file, signature, schema/state/lock invariant, first-red proof,
Convention-09 case, exact command and rollback. Cite path:line for every
finding. Return BLOCKED unless the task is complete. Do not merge."
Acceptance: explicit APPROVED from every named reviewer with zero
BLOCKER or MAJOR findings.
```

### PL-T1 — contract, generated types, rollout plumbing and build fingerprint

Production/config files:

- `docs/glossary.md`
- `packages/shared/contracts/w-lot-canonical-line-key-v1.json`
- `apps/api/config/w_lot_b.php`
- `apps/api/app/Modules/Fiscal/Domain/Enums/LotEvidenceValidationStatus.php`
- `apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php`
- `apps/api/app/Modules/POS/Domain/Enums/LotOperation.php`
- `apps/api/app/Modules/POS/Domain/Enums/LotObligationEffectKind.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/PosLotEligibilityItemData.php`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/PosLotEligibilitySnapshotData.php`
- `apps/api/app/Modules/Fiscal/Application/DTOs/CanonicalSaleReceiptLineKeyData.php`
- `apps/api/app/Modules/Fiscal/Application/DTOs/LotEvidenceLineData.php`
- `apps/api/app/Modules/Fiscal/Application/DTOs/LotEvidenceSubmissionData.php`
- `apps/api/app/Modules/Fiscal/Application/DTOs/LotEvidenceIngressResultData.php`
- `apps/api/app/Modules/POS/Application/DTOs/PosLotObligationData.php`
- `apps/api/app/Modules/Fiscal/Application/Services/CanonicalSaleReceiptLineKey.php`
- `apps/api/app/Modules/Fiscal/Application/Services/LotEvidenceCanonicalizerV1.php`
- `apps/pos/src/lib/lot/canonicalSaleReceiptLineKey.ts`
- `apps/pos/src/lib/lot/lotEvidenceCanonicalizerV1.ts`
- `packages/shared/types/generated.d.ts`
- `docker-compose.staging.yml`
- `apps/api/docker/entrypoint.sh`
- `apps/api/.env.example`
- `apps/api/app/Modules/Tenant/Application/Commands/BackupTenantCommand.php`
- `apps/web/vite.config.ts`
- `apps/web/tools/wLotBBuildFingerprintPlugin.ts`
- `apps/web/Dockerfile`
- `apps/web/e2e/w-lot-b-staging-smoke.spec.ts`

DTO signatures:

```php
final readonly class PosLotEligibilityItemData extends Data
{
    public function __construct(
        public string $productId,
        public ?string $variantId,
        public int $batchId,
        public string $batchUuid,
        public string $batchNumber,
        public ?CarbonImmutable $expiryDate,
        public CarbonImmutable $batchCreatedAt,
        public string $serverAvailableQuantity,
        public bool $requiresBatchTracking,
    );
}

final readonly class PosLotEligibilitySnapshotData extends Data
{
    /** @param list<PosLotEligibilityItemData> $lots
      * @param list<string> $coveredFiscalEventIds */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $locationId,
        public string $terminalId,
        public CompanyModuleEntitlementState $entitlementState,
        public int $entitlementRevision,
        public int $snapshotRevision,
        public string $snapshotWatermark,
        public CarbonImmutable $serverCapturedAt,
        public WLotBRolloutFlagsData $rollout,
        public array $coveredFiscalEventIds,
        public array $lots,
    );
}

final readonly class LotEvidenceLineData extends Data
{
    public function __construct(
        public string $canonicalLineKey,
        public int $lineIndex,
        public int $allocationIndex,
        public string $productId,
        public ?string $variantId,
        public int $batchId,
        public string $quantity,
        public LotProvenance $provenance,
    );
}

final readonly class LotEvidenceSubmissionData extends Data
{
    /** @param list<LotEvidenceLineData> $lines */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $companyId,
        public string $locationId,
        public string $terminalId,
        public string $fiscalEventId,
        public string $fiscalEventHash,
        public int $payloadVersion,
        public string $clientOperationUuid,
        public int $entitlementRevision,
        public int $snapshotRevision,
        public string $snapshotWatermark,
        public string $capturedBy,
        public CarbonImmutable $capturedAtDevice,
        public string $operationFingerprint,
        public array $lines,
    );
}
```

Rollout variables:

```text
WLOT_B_DISPLAY_ENABLED
WLOT_B_CAPTURE_ENABLED
WLOT_B_EVIDENCE_INGRESS_ENABLED
WLOT_B_CONSUMPTION_ENABLED
AUTO_MIGRATE
```

All are literal `true|false`. Entrypoint rejects invalid/missing values and rejects dependency inversions:

```text
capture => display
evidence_ingress => capture
consumption => evidence_ingress
```

All five are declared in `x-api-env`, inherited by API, worker, scheduler and websocket. Current inheritance points are [docker-compose.staging.yml:17-57](/Users/houssamr/Projects/syneriva/apps/erp/docker-compose.staging.yml:17), [docker-compose.staging.yml:175-214](/Users/houssamr/Projects/syneriva/apps/erp/docker-compose.staging.yml:175) and [docker-compose.staging.yml:225-250](/Users/houssamr/Projects/syneriva/apps/erp/docker-compose.staging.yml:225).

`AUTO_MIGRATE=false` skips automatic central/tenant migrations. `true` runs both and exits nonzero on failure; replace HEAD’s log-and-continue branches at [entrypoint.sh:127-154](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:127).

Modified backup CLI:

```text
tenant:backup
  {slug? : Tenant slug; omit with --all}
  {--all : Back up every tenant}
  {--format=text : text|json}
```

JSON emits full `tenant_id`, `backup_id`, path, size, full SHA-256 and status. Existing command only supports slug/`--all` and truncates hashes at [BackupTenantCommand.php:18-74](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/BackupTenantCommand.php:18).

Web fingerprint:

- `VITE_APP_BUILD_SHA` is an exact lowercase 40-character candidate SHA.
- `/build-fingerprint.json` contains `build_sha`, `contract_rev:4`, `feature_fingerprint:"w-lot-b-rev4-display-capture-consumption"`, `entry_asset`, `entry_asset_sha256`.
- No clock/random value.
- Dockerfile declares and exports the build arg before `pnpm build`; current args are at [apps/web/Dockerfile:47-55](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/Dockerfile:47).

Red first:

- Server file/case: `apps/api/tests/Feature/Fiscal/WLotBCanonicalContractPostgresTest.php::test_maximum_line_index_round_trips_and_overflow_is_rejected`.
- First failing assertion: `self::assertSame($vector['key'], CanonicalSaleReceiptLineKey::make(...))`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/WLotBCanonicalContractPostgresTest.php --filter='WLotBCanonicalContractPostgresTest::test_maximum_line_index_round_trips_and_overflow_is_rejected'` — PHPUnit PostgreSQL.
- Device file/case: `apps/pos/src/lib/lot/__tests__/canonicalSaleReceiptLineKey.test.ts::shared vectors remain byte-identical to PHP`.
- First failing assertion: `expect(makeCanonicalSaleReceiptLineKey(...)).toBe(vector.key)`.
- Command/lane: `pnpm --filter @autoerp/pos test -- src/lib/lot/__tests__/canonicalSaleReceiptLineKey.test.ts -t 'shared vectors remain byte-identical to PHP'` — Vitest.
- Generation: `cd apps/api && php artisan typescript:transform && git diff --exit-code -- ../../packages/shared/types/generated.d.ts`.
- Convention 09: not applicable to contracts/release metadata; no operator-edited catalogue row.
- Reviewers: fiscal-pos, tenancy-authz, frontend-conventions.
- Rollback: redeploy predecessor, restore predecessor asset hash; no schema exists yet.

### PL-T2 — additive device/server schemas and persistence adapters

Production files:

- `apps/pos/src/lib/db/migrations.ts`
- `apps/pos/src/lib/db/repositories/branchLotEligibilityRepository.ts`
- `apps/pos/src/lib/db/repositories/receiptLotEvidenceRepository.ts`
- `apps/pos/src/lib/db/repositories/lotEvidenceOutboxRepository.ts`
- `apps/api/database/migrations/tenant/2026_09_06_200000_create_pos_lot_evidence_tables.php`
- `apps/api/database/migrations/tenant/2026_09_06_200100_create_pos_lot_projection_obligations.php`
- corresponding Eloquent models under:
  - `apps/api/app/Modules/Fiscal/Domain/Models/PosReceiptLotEvidenceSubmission.php`
  - `apps/api/app/Modules/Fiscal/Domain/Models/PosReceiptLineLotEvidence.php`
  - `apps/api/app/Modules/POS/Domain/PosReceiptLotObligation.php`
  - `apps/api/app/Modules/POS/Domain/PosReceiptLotObligationEffect.php`
  - `apps/api/app/Modules/POS/Domain/PosReceiptLotObligationEvidenceLink.php`

Implement S1–S4 exactly. No caller or feature activation lands here.

Repository signatures:

```ts
export interface ReplaceBranchLotSnapshotInput {
  snapshot: PosLotEligibilitySnapshotData;
  acknowledgedAt: string;
}

export class BranchLotEligibilityRepository {
  static replaceSnapshot(tx: SqlSurface, input: ReplaceBranchLotSnapshotInput): Promise<void>;
  static getAcknowledgedSnapshot(db: SqlSurface, companyId: string, locationId: string, terminalId: string): Promise<PosLotEligibilitySnapshotData | null>;
  static listEligibleLots(db: SqlSurface, snapshotId: string, productId: string, variantId: string | null): Promise<readonly PosLotEligibilityItemData[]>;
  static isBatchEligible(db: SqlSurface, snapshotId: string, productId: string, variantId: string | null, batchId: number): Promise<boolean>;
}

export class ReceiptLotEvidenceRepository {
  static insertInsideReceipt(tx: SqlSurface, submission: LotEvidenceSubmissionData): Promise<void>;
  static findByOperation(db: SqlSurface, companyId: string, terminalId: string, operationUuid: string): Promise<LotEvidenceSubmissionData | null>;
}
```

Red first:

- Server: `apps/api/tests/Feature/Fiscal/PosLotEvidenceSchemaPostgresTest.php::test_company_scoped_uniques_checks_and_deferred_ownership_are_enforced`.
- First failing assertion: `self::assertSame('23505', $duplicate->getSqlState())`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/PosLotEvidenceSchemaPostgresTest.php --filter='PosLotEvidenceSchemaPostgresTest::test_company_scoped_uniques_checks_and_deferred_ownership_are_enforced'` — PHPUnit PostgreSQL.
- Device: `apps/pos/src/lib/db/__tests__/wLotBMigrations.test.ts::v68 and v69 migrate once and preserve scale-4 decimals`.
- First failing assertion: `expect(await currentVersion(db)).toBe(69)`.
- Command/lane: `pnpm --filter @autoerp/pos test -- src/lib/db/__tests__/wLotBMigrations.test.ts -t 'v68 and v69 migrate once and preserve scale-4 decimals'` — Vitest.
- Convention 09: company A/B may reuse operation UUID; location B cache never replaces A; rerunning migrations/repository insert is exact no-op or explicit conflict.
- Reviewers: fiscal-pos, inventory-costing, tenancy-authz.
- Rollback: disable all W-LOT-B flags and redeploy T1; retain additive tables and v68/v69. Never run destructive `down()` after device/server rows exist.

### PL-T3 — server branch-lot eligibility snapshot

Production files:

- `apps/api/app/Modules/POS/routes.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Requests/PosLotEligibilityRequest.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/PosLotEligibilityController.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/PosLotEligibilitySnapshotService.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`
- `apps/api/app/Modules/BatchExpiry/Providers/BatchExpiryServiceProvider.php`

Route:

```text
GET /api/v1/pos/lot-eligibility
  ?terminal_id=<uuid>
  &known_snapshot_revision=<positive-int,optional>
  &local_fiscal_event_ids[]=<uuid,max-500>
```

Middleware: `api`, `auth:sanctum`, `SetPermissionsTeam`, `EnforceTokenTenantClaim`, `can:pos.operate_terminal`. Do not add `module:BatchExpiry`, because a known `not_entitled` response is required to clear stale device data.

Signatures:

```php
final class PosLotEligibilitySnapshotService
{
    /** @param list<string> $localFiscalEventIds */
    public function snapshot(
        string $tenantId,
        string $companyId,
        Terminal $terminal,
        array $localFiscalEventIds,
    ): PosLotEligibilitySnapshotData;
}

final class PosLotEligibilityController
{
    public function show(PosLotEligibilityRequest $request): JsonResponse;
}
```

Rules:

- Resolve entitlement only through W-LOT-A’s fence/decision interface.
- `not_entitled` returns no lots and a revision; `entitlement_unresolved` returns no lots and cannot be acknowledged for opening.
- Verify terminal belongs to authenticated tenant/company and its selected `pos_enabled` location.
- Query W-LOT-A’s canonical eligibility predicate.
- Add deterministic `batch_id ASC` tie-break to suggestion and consumption.
- `server_available_quantity` remains decimal string at scale 4.
- `coveredFiscalEventIds` contains only requested events already reflected in server stock projection.
- Snapshot watermark is opaque and monotonic within `(company,location,terminal)`; it must not use unordered movement UUIDs.
- Rollout flags are returned so the POS has one runtime policy source.

Red first:

- `apps/api/tests/Feature/BatchExpiry/PosLotEligibilityEndpointPostgresTest.php::test_snapshot_matches_locked_server_fefo_order_and_nets_reservations`.
- First failing assertion: `self::assertSame(['EARLY-TIE-1','EARLY-TIE-2','LATE','UNDATED'], $response->json('data.lots.*.batch_number'))`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/BatchExpiry/PosLotEligibilityEndpointPostgresTest.php --filter='PosLotEligibilityEndpointPostgresTest::test_snapshot_matches_locked_server_fefo_order_and_nets_reservations'` — PHPUnit PostgreSQL.
- Convention 09 in the same class:
  - same batch number in company B never leaks;
  - terminal at second location receives only that location;
  - repeated snapshot has stable order/revision semantics and no database write duplication.
- Reviewers: fiscal-pos, inventory-costing, tenancy-authz.
- Rollback: set display false; endpoint may remain deployed and return a false rollout contract. No data reversal.

### PL-T4 — device cache refresh and acknowledged session-open gate

Production files:

- `apps/pos/src/api/lotEligibilityApi.ts`
- `apps/pos/src/lib/sync/lotEligibilitySync.ts`
- `apps/pos/src/lib/sync/syncService.ts`
- `apps/pos/src/stores/terminalStore.ts`
- `apps/pos/src/stores/syncStore.ts`

Signatures:

```ts
export async function fetchPosLotEligibility(
  terminalId: string,
  localFiscalEventIds: readonly string[],
  options?: { signal?: AbortSignal; timeoutMs?: number },
): Promise<PosLotEligibilitySnapshotData>;

export async function refreshAndAcknowledgeLotEligibility(
  db: SqlSurface,
  terminalId: string,
  reason: 'session_open' | 'periodic',
): Promise<PosLotEligibilitySnapshotData>;

export async function assertAcknowledgedLotEligibilityForOpen(
  db: SqlSurface,
  terminalId: string,
): Promise<PosLotEligibilitySnapshotData | null>;
```

Session-open contract:

- Determine module/rollout before either v3 device-authoritative or legacy server-authoritative shift mutation.
- Module off or `not_entitled`: delete stale cache, render nothing, open normally.
- `entitlement_unresolved`: refuse with translated `LOT_ENTITLEMENT_UNRESOLVED`.
- Entitled + display enabled: fetch complete snapshot, write it in one sync transaction, read it back and verify exact entitlement/snapshot revision plus acknowledgement timestamp; only then invoke either shift-open branch.
- Timeout/network/cache failure refuses opening; a prior-session snapshot is not sufficient for D4.
- Periodic refresh during an open session is non-fatal and shows age/error.
- API timestamps use `toSqliteUtc()`.
- This replaces HEAD’s post-open fire-and-forget behavior at [terminalStore.ts:857-873](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/stores/terminalStore.ts:857).

Red first:

- `apps/pos/src/stores/__tests__/terminalStore.lotEligibilityOpen.test.ts::refuses both open paths until the returned entitlement revision is durably acknowledged`.
- First failing assertion: `await expect(openShift('100.000')).rejects.toMatchObject({code:'LOT_SNAPSHOT_NOT_ACKNOWLEDGED'})`.
- Command/lane: `pnpm --filter @autoerp/pos test -- src/stores/__tests__/terminalStore.lotEligibilityOpen.test.ts -t 'refuses both open paths until the returned entitlement revision is durably acknowledged'` — Vitest.
- Convention 09:
  - separate company DB/cache;
  - second location snapshot cannot satisfy first location;
  - retry after the same acknowledged revision opens once and writes one shift.
- Reviewers: fiscal-pos, frontend-conventions, tenancy-authz.
- Rollback: set display false before reverting the app; existing cache is harmless and removed on module-off/open.

### PL-T5 — FEFO selector and shipped display surfaces

Production files:

- `apps/pos/src/lib/lot/selectFefoSuggestion.ts`
- `apps/pos/src/lib/stock/availability.ts`
- `apps/pos/src/lib/stock/stockGate.ts`
- `apps/pos/src/lib/stock/cartIngress.ts`
- `apps/pos/src/types/cart.ts`
- `apps/pos/src/components/organisms/ProductGrid/NearExpirySlot.tsx`
- `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx`
- `apps/pos/src/components/organisms/ProductGrid/ProductListRow.tsx`
- `apps/pos/src/components/organisms/ProductGrid/ProductTable.tsx`
- `apps/pos/src/components/molecules/CartLineItem/CartLineItem.tsx`
- `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx`
- `apps/pos/src/components/pos/ProductDetailDrawer.tsx`
- `apps/pos/src/locales/en/pos.json`
- `apps/pos/src/locales/fr/pos.json`
- `apps/pos/src/locales/ar/pos.json`

Signature:

```ts
export interface EffectiveLotAllocation {
  batchId: number;
  batchUuid: string;
  batchNumber: string;
  expiryDate: string | null;
  quantity: string;
  provenance: App.Modules.BatchExpiry.Domain.Enums.LotProvenance;
}

export async function selectFefoSuggestion(
  db: SqlSurface,
  product: POSProduct,
  variantId: string | null,
  requestedQuantity: string,
  cartLines: readonly AvailabilityCartLine[],
): Promise<readonly EffectiveLotAllocation[]>;
```

Rules:

- Start with server quantities already net of reservations.
- Subtract pending local sale allocations only when their fiscal event is absent from snapshot coverage.
- For pending pre-capture receipts, derive a local `system_fefo_estimate` from the acknowledged snapshot.
- Subtract current sale-cart allocations; return lines never subtract.
- Use decimal helpers only.
- `stockGate` remains cart ingress authority; the selector consumes the exact effective availability computed through it rather than creating another availability path.
- `NearExpirySlot` renders suggested batch, expiry/“undated” and snapshot age in all three existing callers.
- `CartLineItem` renders allocation summary and, only when capture is enabled, an edit action.
- `ProductDetailDrawer` includes `stock_lots` only when BatchExpiry is active and display rollout is true; if a disappearing module invalidates the active tab, select `details`.
- Module off renders no lot DOM/test IDs.
- No product/card caller performs its own FEFO query.

Red first:

- `apps/pos/src/components/organisms/ProductGrid/__tests__/WLotBDisplay.test.tsx::renders one shared suggestion across card row table cart and drawer and nothing when module is off`.
- First failing assertion: `expect(screen.getAllByTestId('suggested-lot')).toHaveLength(4)`.
- Command/lane: `pnpm --filter @autoerp/pos test -- src/components/organisms/ProductGrid/__tests__/WLotBDisplay.test.tsx -t 'renders one shared suggestion across card row table cart and drawer and nothing when module is off'` — Vitest.
- Additional exact selector test: `apps/pos/src/lib/lot/__tests__/selectFefoSuggestion.test.ts::nets reservation pending sales current cart and coverage without floats`.
- Command: `pnpm --filter @autoerp/pos test -- src/lib/lot/__tests__/selectFefoSuggestion.test.ts`.
- Convention 09: switch company/location snapshots and prove no stale render; rerender/recompute produces no cache or cart mutation.
- Reviewers: fiscal-pos, frontend-conventions.
- Rollback: set display false; then revert renderer app. No server/device data reversal.

### PL-T6 — editable capture and receipt-atomic evidence/outbox

Production files:

- `apps/pos/src/types/cart.ts`
- `apps/pos/src/stores/cartStore.ts`
- `apps/pos/src/components/molecules/CartLineItem/CartLineItem.tsx`
- `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx`
- `apps/pos/src/components/organisms/LotCaptureEditor/LotCaptureEditor.tsx`
- `apps/pos/src/lib/lot/validateCapturedLots.ts`
- `apps/pos/src/lib/offline/receiptService.ts`
- `apps/pos/src/lib/db/repositories/receiptLotEvidenceRepository.ts`

Signatures:

```ts
export interface CapturedLotAllocation {
  batchId: number;
  quantity: string;
}

export async function validateCapturedLots(
  db: SqlSurface,
  snapshot: PosLotEligibilitySnapshotData,
  productId: string,
  variantId: string | null,
  lineQuantity: string,
  allocations: readonly CapturedLotAllocation[],
): Promise<readonly EffectiveLotAllocation[]>;

export interface OfflineReceiptInput {
  // existing fields unchanged
  lotEvidenceOperationUuid: string;
}
```

Capture contract:

- When an item enters the cart, prefill from the current FEFO suggestion.
- Editor permits one or N eligible batches.
- Each quantity is a positive scale-4 string; sum equals line quantity exactly.
- Batch, company, location, product and variant must match the acknowledged cache.
- Quantity edits rerun FEFO while retaining explicitly edited allocations when still valid; invalid retained allocations require operator correction before tender.
- The checkout operation UUID is allocated once and retained across exact retries.
- After `FiscalEventEngine.append` returns event ID/hash, derive canonical line keys and evidence fingerprint.
- Inside the same existing `withWriteTransaction('fiscal')`:
  1. append fiscal event;
  2. insert offline receipt;
  3. insert evidence header;
  4. insert every evidence line;
  5. insert its own evidence outbox item linked to event ID/hash;
  6. finish existing voucher mutations;
  7. commit.
- Inject failures after each numbered boundary; every failure leaves all six effects absent and chain head unchanged.
- Exact retry yields one receipt/event/evidence/outbox. Different content under the same operation UUID fails locally before mutation.
- Training or module-off receipts omit evidence entirely.
- No field is added to `canonicalPayload`, `FiscalEventAppendRequest` or printed receipt.

Red first:

- `apps/pos/src/lib/offline/__tests__/receiptLotEvidenceAtomicity.test.ts::rolls back receipt event evidence and outbox when every evidence boundary fails`.
- First failing assertion: `expect(await countRows(db,'fiscal_events')).toBe(0)`.
- Command/lane: `pnpm --filter @autoerp/pos test -- src/lib/offline/__tests__/receiptLotEvidenceAtomicity.test.ts -t 'rolls back receipt event evidence and outbox when every evidence boundary fails'` — Vitest.
- Sealed regression: `apps/pos/src/lib/offline/__tests__/receiptLotEvidenceSealedPayload.test.ts::capture leaves canonical bytes hash and printable receipt unchanged`.
- First failing assertion: `expect(withEvidence.canonical_bytes).toBe(withoutEvidence.canonical_bytes)`.
- Convention 09: company/location cache mismatch refuses; exact operation retry is one row set.
- Reviewers: fiscal-pos, frontend-conventions.
- Rollback: set capture false while leaving display true; queued evidence remains durable. Revert app only after draining or exporting pending rows.

### PL-T7 — evidence outbox delivery and retry

Production files:

- `apps/pos/src/lib/sync/lotEvidenceSync.ts`
- `apps/pos/src/lib/sync/syncService.ts`
- `apps/pos/src/stores/syncStore.ts`
- `apps/pos/src/lib/db/repositories/lotEvidenceOutboxRepository.ts`

Signatures:

```ts
export async function pushLotEvidence(
  db: SqlSurface,
  options?: { limit?: number; signal?: AbortSignal },
): Promise<{ acknowledged: number; retrying: number; deadLettered: number }>;

export async function recoverStrandedLotEvidence(
  db: SqlSurface,
  now?: string,
): Promise<number>;
```

Delivery:

- `runFullSync` continues to push fiscal events in chain order at [syncService.ts:2268-2291](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/sync/syncService.ts:2268).
- Evidence has its own drain and may be deliberately exercised before or after fiscal event delivery.
- 200 `validated` and 202 `pending_event` both acknowledge the local outbox because the server has durable custody.
- 409 content conflict is terminal and visible.
- 401/403/422 are terminal only after parsing a typed server error; network/408/429/5xx retry with capped exponential backoff and deterministic jitter from outbox ID.
- Startup and each sync recover expired `sending` leases.
- One outbox item never changes fiscal-event sync status.

Red first:

- `apps/pos/src/lib/sync/__tests__/lotEvidenceSync.test.ts::acknowledges durable pending_event when evidence reaches the server first`.
- First failing assertion: `expect(await getOutboxState(db,outboxId)).toBe('acknowledged')`.
- Command/lane: `pnpm --filter @autoerp/pos test -- src/lib/sync/__tests__/lotEvidenceSync.test.ts -t 'acknowledges durable pending_event when evidence reaches the server first'` — Vitest.
- Convention 09: company-scoped outbox selection; second-location item retains location; exact resend produces one acknowledgement.
- Reviewers: fiscal-pos, frontend-conventions.
- Rollback: turn capture off; continue running delivery until pending/retry is zero. Never delete unacknowledged evidence.

### PL-T8 — authenticated server ingress and either-order reconciliation

Production files:

- `apps/api/app/Modules/Fiscal/routes.php`
- `apps/api/app/Modules/Fiscal/Presentation/Requests/IngestLotEvidenceRequest.php`
- `apps/api/app/Modules/Fiscal/Presentation/Controllers/LotEvidenceIngestionController.php`
- `apps/api/app/Modules/Fiscal/Application/Services/LotEvidenceIngressService.php`
- `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
- `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php`

Route:

```text
POST /api/v1/pos/sync/lot-evidence
```

Middleware matches fiscal ingress at [Fiscal routes:29-53](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/routes.php:29), including `can:pos.operate_terminal`.

Signatures:

```php
final class LotEvidenceIngressService
{
    public function ingest(
        LotEvidenceSubmissionData $submission,
        Terminal $authenticatedTerminal,
        User $actor,
    ): LotEvidenceIngressResultData;

    public function reconcilePendingForEvent(FiscalEvent $event): int;
}

final class LotEvidenceIngestionController
{
    public function store(IngestLotEvidenceRequest $request): JsonResponse;
}
```

Ingress rules:

- Feature flag false returns typed 409 `LOT_EVIDENCE_INGRESS_DISABLED` and writes nothing.
- Validate DTO, canonical key, fingerprint, tenant/company/terminal/location/operator and W-LOT-A entitlement revision.
- Evidence-first: insert header/lines atomically as `pending_event`, return 202.
- Event-first: validate ID/hash, sealed line count/key/product/variant/quantity and cache revision, store `validated`, return 200.
- Fiscal event ingress calls `reconcilePendingForEvent` after event insert and before `dispatchProjections`.
- Expected mismatch transitions evidence to `blocked`; it does not roll back or quarantine the fiscal event.
- Exact retry returns the existing status.
- Same ID/operation identity with different content returns 409.
- When a newly validated submission belongs to an already-applied estimate obligation, increment only that obligation’s generation and set it pending; do not replay the parent fiscal projection.

Red first:

- `apps/api/tests/Feature/Fiscal/LotEvidenceArrivalOrderPostgresTest.php::test_evidence_first_and_event_first_converge_to_one_validated_submission`.
- First failing assertion: `self::assertSame('validated', $evidenceFirst->refresh()->validation_status->value)`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/LotEvidenceArrivalOrderPostgresTest.php --filter='LotEvidenceArrivalOrderPostgresTest::test_evidence_first_and_event_first_converge_to_one_validated_submission'` — PHPUnit PostgreSQL.
- Conflict case in same file: first assertion `self::assertSame(409,$response->status())`.
- Convention 09:
  - cross-company replay is 403 and sees zero rows;
  - terminal at location B cannot submit location A evidence;
  - exact replay has one submission and N unchanged lines.
- Reviewers: fiscal-pos, tenancy-authz.
- Rollback: set capture false, drain existing device evidence, then set ingress false. Retain all accepted/pending/blocked evidence.

### PL-T9 — captured consumption, fallback, blocked recovery and refunds

Production files:

- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`
- `apps/api/app/Modules/POS/Application/Services/PosReceiptLotObligationService.php`
- `apps/api/app/Modules/POS/Application/Jobs/ApplyPosReceiptLotObligationJob.php`
- `apps/api/app/Modules/POS/Application/Services/PosReceiptLotRecoveryService.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`
- `apps/api/app/Modules/Fiscal/Infrastructure/Commands/RetryPosLotObligationsCommand.php`
- `apps/api/routes/console.php`
- `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`

Signatures:

```php
final class PosReceiptLotObligationService
{
    public function createAndAttemptForSale(
        FiscalEvent $event,
        Receipt $receipt,
        ReceiptLine $line,
        StockMovement $aggregateMovement,
        Terminal $terminal,
    ): PosReceiptLotObligation;

    public function createAndAttemptForRefund(
        FiscalEvent $event,
        Receipt $receipt,
        ReceiptLine $line,
        ReceiptLine $originalLine,
        StockMovement $aggregateMovement,
        Terminal $terminal,
    ): PosReceiptLotObligation;

    public function apply(PosReceiptLotObligation $obligation): void;
}

final class PosReceiptLotRecoveryService
{
    public function recover(
        ?string $tenantId,
        ?string $companyId,
        ?string $fiscalEventId,
        array $statuses,
        int $limit,
        int $minAgeMinutes,
        bool $dryRun,
        bool $sync,
    ): PosLotRecoveryResultData;
}
```

New CLI:

```text
fiscal:retry-pos-lot-obligations
  {--tenant= : Restrict to one tenant UUID}
  {--all-tenants : Iterate every reachable tenant}
  {--company= : Optional company UUID}
  {--fiscal-event-id= : Optional fiscal event UUID}
  {--status=pending,blocked : Comma-separated pending|blocked|dead_lettered}
  {--limit=100 : Maximum obligations}
  {--min-age-minutes=0 : Minimum stable-state age}
  {--dry-run : Report only}
  {--sync : Apply inline instead of dispatching jobs}
  {--format=text : text|json}
```

Exactly one of `--tenant`/`--all-tenants` is required. Empty filters are invalid. Schedule:

```php
Schedule::command(
    'fiscal:retry-pos-lot-obligations --all-tenants --status=pending,blocked --limit=100'
)->everyFiveMinutes()->withoutOverlapping()->runInBackground();
```

Behavior:

- The aggregate stock movement remains created exactly once by `PosCoreReceiptProjection`.
- Replace the immediate contained FEFO call with creation/application of one child obligation.
- Valid evidence selects its explicit N batches and writes `operator_captured`.
- No valid evidence uses the exact server FEFO order and writes `system_fefo_estimate`.
- Insufficient or temporarily ineligible stock records partial durable effects if committed and leaves the obligation `blocked`; it never rejects the signed receipt.
- Receipt projection status may be applied while its child obligation is blocked. Projection detail exposes both.
- Recovery lease transaction commits before acquiring entitlement/product locks.
- Worker death after any effect is idempotent through unique child movement/effect keys.
- Late valid evidence:
  - exact lot match: append evidence link with `exact_match`, no inventory movement;
  - differing lot match: append reversal effects for the current net estimate and replacement effects for captured lots, all under the original aggregate movement ID;
  - update existing effective allocation materialization only through the obligation service;
  - cumulative signed effect remains the aggregate delta.
- Refund provenance:
  1. validated evidence link and its captured lines;
  2. otherwise cumulative effective obligation effects labelled estimate;
  3. otherwise legacy `pos_receipt_line_batch_allocations`.
- Partial refunds prorate against outstanding captured/effective lots.
- Scrap/not-received keep HEAD’s no-credit behavior.
- Never mint a DEFAULT lot for unattributed historical refunds.
- Lot-only recovery never calls `InventoryGlPostingBuffer`; aggregate/refund GL remains buffer-owned. HEAD currently flushes POS inventory GL inside the root transaction at [PosCoreReceiptProjection.php:500-528](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:500).

Red first:

- `apps/api/tests/Feature/Fiscal/PosCoreReceiptLotEvidenceProjectionPostgresTest.php::test_two_captured_lots_reconcile_to_the_signed_aggregate_delta`.
- First failing assertion:

```php
self::assertSame(
    (string) $movement->quantity_after - (string) $movement->quantity_before,
    $signedEffectSum,
);
```

Implement with `bcsub`, never PHP subtraction.

- Exact command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/PosCoreReceiptLotEvidenceProjectionPostgresTest.php --filter='PosCoreReceiptLotEvidenceProjectionPostgresTest::test_two_captured_lots_reconcile_to_the_signed_aggregate_delta'` — PHPUnit PostgreSQL.
- Late order/concurrency: `apps/api/tests/Feature/Fiscal/PosLotObligationRecoveryPostgresTest.php::test_late_different_evidence_appends_compensating_effects_without_replaying_aggregate_or_gl`.
- First failing assertion: `self::assertSame(1, StockMovement::whereKey($aggregateId)->count())`.
- Refund: `apps/api/tests/Feature/Fiscal/PosLotCapturedRefundProvenancePostgresTest.php::test_partial_refund_restores_the_operator_captured_lots`.
- First failing assertion: `self::assertSame($capturedBatchIds,$restoredBatchIds)`.
- Commands: exact file/filter with `php artisan test -c phpunit-pgsql.xml`; no SQLite substitute.
- Convention 09:
  - same event/operation identifiers in company B cannot affect company A;
  - second location consumes/restores only that location;
  - event replay, evidence replay and recovery replay add zero aggregate movements, batch movements, effects and GL rows.
- Reviewers: fiscal-pos, inventory-costing, stock-gl-interaction, tenancy-authz.
- Rollback: set consumption false first. POS-core aggregate projection remains active; retain obligations/effects/evidence. Redeploy T8 and use the preflight report to enumerate blocked/unapplied child rows. Never reverse aggregate stock automatically.

### PL-T10 — architecture/CI, preflight, end-to-end and release proof

Production/config files:

- `apps/api/app/Console/Commands/WLotBPreflightCommand.php`
- `apps/api/tests/Architecture/WLotBWriterOwnershipTest.php`
- `apps/api/tests/Architecture/InventoryWriterLockManifestTest.php`
- `.github/workflows/ci.yml`
- `apps/web/e2e/w-lot-b-staging-smoke.spec.ts`
- `docs/qa/W-LOT-B-STAGING-EVIDENCE.md`

New CLI:

```text
inventory:w-lot-b-preflight
  {--tenant= : Restrict to one tenant UUID}
  {--all-tenants : Iterate every reachable tenant}
  {--company= : Optional company UUID}
  {--expected-build-sha= : Required lowercase 40-character artifact SHA}
  {--expect-stage=off : off|display|capture|consumption}
  {--fail-on-blocked : Nonzero if any blocked evidence/obligation exists}
  {--fail-on-pending-older-than=15 : Age in minutes}
  {--format=text : text|json}
```

JSON includes tenant/company IDs, rollout flags/fingerprint, migration presence, evidence status counts, obligation status counts, signed-invariant violations, orphan movement/effect counts, oldest pending age, POS-core registered/always-active result and W-LOT-A prerequisite health.

CI:

- Add all new PG classes to the live `backend-test-pgsql` selection; current filter includes the existing POS lot projection class at [.github/workflows/ci.yml:1116-1120](/Users/houssamr/Projects/syneriva/apps/erp/.github/workflows/ci.yml:1116).
- Device tests run under the existing POS Vitest job.
- Types drift runs `typescript:transform` and rejects diffs.
- Architecture job runs writer/GL/quantity/shadow-type checks.
- Full preflight remains required by [CLAUDE.md:42-46](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:42).

Red first:

- `apps/api/tests/Feature/Fiscal/WLotBPreflightPostgresTest.php::test_preflight_fails_on_signed_effect_or_old_blocked_obligation`.
- First failing assertion: `self::assertSame(Command::FAILURE,$exitCode)`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/WLotBPreflightPostgresTest.php --filter='WLotBPreflightPostgresTest::test_preflight_fails_on_signed_effect_or_old_blocked_obligation'` — PHPUnit PostgreSQL.
- Device acceptance: `apps/pos/src/__tests__/wLotBDeviceJourney.test.tsx::display capture offline receipt and exact retry`.
- First failing assertion: `expect(await countRows(db,'lot_evidence_outbox')).toBe(1)`.
- Command/lane: `pnpm --filter @autoerp/pos test -- src/__tests__/wLotBDeviceJourney.test.tsx -t 'display capture offline receipt and exact retry'` — Vitest.
- Convention 09: preflight enumerates both companies/locations; repeated run is read-only and byte-identical except measured clock fields, which are excluded from fingerprint comparison.
- Reviewers: all five relevant reviewers: fiscal-pos, inventory-costing, stock-gl-interaction, tenancy-authz, frontend-conventions.
- Rollback: block promotion; if staged, execute the PL-DEPLOY reverse flag sequence and retain all audit/evidence/obligation rows.

## PL-DEPLOY — executable five-push staging manifest

Staging facts:

- API auto-deploys from `dev`; web does not. Explicit web deploy is required after every promotion at [WORKFLOW.md:196-231](/Users/houssamr/Projects/syneriva/apps/erp/docs/factory/WORKFLOW.md:196).
- Web application ID is `mY6P_PHb4pw-2LdG1Y7Ml`.
- Central and tenant boot migrations currently run automatically and swallow failure at [entrypoint.sh:131-154](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:131); Push 1 replaces this before schema lands.
- `tenants:migrate-rolling` prints each tenant, continues through failures and returns nonzero when any fails at [RollingTenantMigrationCommand.php:73-103](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:73) and [RollingTenantMigrationCommand.php:158-174](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:158).

### Common pre-push

Run from repository root in a fresh shell:

```bash
set -euo pipefail

test "$(git branch --show-current)" = "dev"
test -z "$(git status --porcelain --untracked-files=no)"

: "${DOKPLOY_URL:?}"
: "${DOKPLOY_API_KEY:?}"
: "${STAGING_SSH:?}"
: "${SMOKE_TEST_EMAIL:?}"
: "${SMOKE_TEST_PASSWORD:?}"

WEB_APPLICATION_ID="mY6P_PHb4pw-2LdG1Y7Ml"
WEB_URL="https://erp.otospex.dev"
API_URL="https://api.erp.otospex.dev"

git fetch origin dev
git merge --ff-only origin/dev

pnpm lint
pnpm typecheck
pnpm test
pnpm build

(
  cd apps/api
  ./vendor/bin/pint --test
  ./vendor/bin/phpstan analyse
  composer test
  php artisan test -c phpunit-pgsql.xml \
    tests/Feature/Fiscal/WLotBCanonicalContractPostgresTest.php \
    tests/Feature/Fiscal/PosLotEvidenceSchemaPostgresTest.php \
    tests/Feature/BatchExpiry/PosLotEligibilityEndpointPostgresTest.php \
    tests/Feature/Fiscal/LotEvidenceArrivalOrderPostgresTest.php \
    tests/Feature/Fiscal/PosCoreReceiptLotEvidenceProjectionPostgresTest.php \
    tests/Feature/Fiscal/PosLotObligationRecoveryPostgresTest.php \
    tests/Feature/Fiscal/PosLotCapturedRefundProvenancePostgresTest.php \
    tests/Feature/Fiscal/WLotBPreflightPostgresTest.php
  php artisan typescript:transform
)

git diff --exit-code -- packages/shared/types/generated.d.ts
./scripts/preflight.sh
```

### Common push and ID capture

```bash
CANDIDATE_SHA="$(git rev-parse HEAD)"
test "${#CANDIDATE_SHA}" -eq 40

git push origin "${CANDIDATE_SHA}:refs/heads/dev"

REMOTE_SHA="$(git ls-remote origin refs/heads/dev | awk '{print $1}')"
test "$REMOTE_SHA" = "$CANDIDATE_SHA"
```

### Common explicit Dokploy web deploy

The redeploy acknowledgement is not assumed to contain an ID. Use a unique title, then obtain the deployment ID from Dokploy’s deployment list before polling it.

```bash
DEPLOY_TITLE="W-LOT-B-${CANDIDATE_SHA}-$(date +%s)"

curl --fail-with-body --silent --show-error \
  -X POST "${DOKPLOY_URL}/api/application.redeploy" \
  -H "x-api-key: ${DOKPLOY_API_KEY}" \
  -H "content-type: application/json" \
  --data "$(jq -nc \
    --arg applicationId "$WEB_APPLICATION_ID" \
    --arg title "$DEPLOY_TITLE" \
    --arg description "$CANDIDATE_SHA" \
    '{applicationId:$applicationId,title:$title,description:$description}')"

DEPLOYMENT_ID=""
for attempt in $(seq 1 30); do
  DEPLOYMENTS_JSON="$(
    curl --fail-with-body --silent --show-error \
      -H "x-api-key: ${DOKPLOY_API_KEY}" \
      "${DOKPLOY_URL}/api/deployment.all?applicationId=${WEB_APPLICATION_ID}"
  )"
  DEPLOYMENT_ID="$(
    printf '%s\n' "$DEPLOYMENTS_JSON" |
      jq -er --arg title "$DEPLOY_TITLE" --arg sha "$CANDIDATE_SHA" \
        '.[] | select(.title == $title and .description == $sha) | .deploymentId' |
      head -n 1
  )" || true
  test -n "$DEPLOYMENT_ID" && break
  sleep 2
done
test -n "$DEPLOYMENT_ID"

for attempt in $(seq 1 90); do
  DEPLOYMENTS_JSON="$(
    curl --fail-with-body --silent --show-error \
      -H "x-api-key: ${DOKPLOY_API_KEY}" \
      "${DOKPLOY_URL}/api/deployment.all?applicationId=${WEB_APPLICATION_ID}"
  )"
  DEPLOY_STATUS="$(
    printf '%s\n' "$DEPLOYMENTS_JSON" |
      jq -er --arg id "$DEPLOYMENT_ID" \
        '.[] | select(.deploymentId == $id) | .status'
  )"
  case "$DEPLOY_STATUS" in
    done|success) break ;;
    error|failed|cancelled) exit 1 ;;
  esac
  sleep 5
done
test "$DEPLOY_STATUS" = "done" || test "$DEPLOY_STATUS" = "success"
```

### Common asset hash, feature fingerprint and Playwright smoke

```bash
WEB_META="$(
  curl --fail-with-body --silent --show-error \
    "${WEB_URL}/build-fingerprint.json"
)"

test "$(printf '%s\n' "$WEB_META" | jq -r '.build_sha')" = "$CANDIDATE_SHA"
test "$(printf '%s\n' "$WEB_META" | jq -r '.contract_rev')" = "4"
test "$(printf '%s\n' "$WEB_META" | jq -r '.feature_fingerprint')" \
  = "w-lot-b-rev4-display-capture-consumption"

ASSET_PATH="$(printf '%s\n' "$WEB_META" | jq -er '.entry_asset')"
EXPECTED_HASH="$(printf '%s\n' "$WEB_META" | jq -er '.entry_asset_sha256')"
SERVED_HASH="$(
  curl --fail-with-body --silent --show-error "${WEB_URL}${ASSET_PATH}" |
    openssl dgst -sha256 |
    awk '{print $NF}'
)"
test "$SERVED_HASH" = "$EXPECTED_HASH"

STAGING_URL="$WEB_URL" \
EXPECTED_BUILD_SHA="$CANDIDATE_SHA" \
SMOKE_TEST_EMAIL="$SMOKE_TEST_EMAIL" \
SMOKE_TEST_PASSWORD="$SMOKE_TEST_PASSWORD" \
pnpm --filter @autoerp/web exec playwright test \
  e2e/w-lot-b-staging-smoke.spec.ts \
  --config=playwright.smoke.config.ts
```

Record `CANDIDATE_SHA`, `DEPLOYMENT_ID`, prior/new asset hashes, fingerprint JSON and Playwright transcript before continuing.

### Common Laravel environment recreation and verification

On every rollout-variable change, recreate—do not merely restart—all four Laravel services:

```bash
ssh "$STAGING_SSH" \
  'cd /var/www/html &&
   docker compose -f docker-compose.staging.yml up -d --force-recreate --no-deps api worker scheduler websocket'
```

Verify each service receives identical values:

```bash
for service in api worker scheduler websocket; do
  ssh "$STAGING_SSH" \
    "cd /var/www/html &&
     docker compose -f docker-compose.staging.yml exec -T ${service} sh -lc '
       printf \"%s\\n\" \
         \"WLOT_B_DISPLAY_ENABLED=\$WLOT_B_DISPLAY_ENABLED\" \
         \"WLOT_B_CAPTURE_ENABLED=\$WLOT_B_CAPTURE_ENABLED\" \
         \"WLOT_B_EVIDENCE_INGRESS_ENABLED=\$WLOT_B_EVIDENCE_INGRESS_ENABLED\" \
         \"WLOT_B_CONSUMPTION_ENABLED=\$WLOT_B_CONSUMPTION_ENABLED\" \
         \"AUTO_MIGRATE=\$AUTO_MIGRATE\"'"
done
```

No flag file under `/run` is used.

### Push 1 — T1, contracts and migration ownership

Contents: PL-T1 only. Flags:

```text
WLOT_B_DISPLAY_ENABLED=false
WLOT_B_CAPTURE_ENABLED=false
WLOT_B_EVIDENCE_INGRESS_ENABLED=false
WLOT_B_CONSUMPTION_ENABLED=false
AUTO_MIGRATE=false
```

Commands:

```bash
git add -- \
  docs/glossary.md \
  packages/shared/contracts/w-lot-canonical-line-key-v1.json \
  packages/shared/types/generated.d.ts \
  apps/api/config/w_lot_b.php \
  apps/api/app/Modules/Fiscal/Domain/Enums \
  apps/api/app/Modules/POS/Domain/Enums \
  apps/api/app/Modules/BatchExpiry/Application/DTOs \
  apps/api/app/Modules/Fiscal/Application/DTOs \
  apps/api/app/Modules/POS/Application/DTOs \
  apps/api/app/Modules/Fiscal/Application/Services/CanonicalSaleReceiptLineKey.php \
  apps/api/app/Modules/Fiscal/Application/Services/LotEvidenceCanonicalizerV1.php \
  apps/api/app/Modules/Tenant/Application/Commands/BackupTenantCommand.php \
  apps/pos/src/lib/lot \
  docker-compose.staging.yml \
  apps/api/docker/entrypoint.sh \
  apps/api/.env.example \
  apps/web/vite.config.ts \
  apps/web/tools/wLotBBuildFingerprintPlugin.ts \
  apps/web/Dockerfile \
  apps/web/e2e/w-lot-b-staging-smoke.spec.ts \
  apps/api/tests/Feature/Fiscal/WLotBCanonicalContractPostgresTest.php \
  apps/pos/src/lib/lot/__tests__/canonicalSaleReceiptLineKey.test.ts

git commit -m "W-LOT-B P1: add contracts and rollout controls"
```

Run common pre-push, push, explicit web deploy, environment recreation and smoke.

Rollback point P1: captured predecessor web deployment ID and asset hash. Redeploy predecessor; no database action.

### Push 2 — T2 additive schemas

Before promotion, back up every tenant and capture IDs from command output:

```bash
BACKUP_JSON="$(
  ssh "$STAGING_SSH" \
    'cd /var/www/html &&
     DB_HOST=${DB_DIRECT_HOST:-postgres} php artisan tenant:backup --all --format=json'
)"

printf '%s\n' "$BACKUP_JSON" |
  jq -e '.tenants | length > 0'

printf '%s\n' "$BACKUP_JSON" |
  jq -e '.tenants[] | select(
    .backup_id == null or
    .status != "completed" or
    .sha256 == null or
    (.sha256 | length) != 64
  )' |
  (! read -r)

ACTIVE_TENANTS="$(printf '%s\n' "$BACKUP_JSON" | jq '.tenants | length')"
printf '%s\n' "$BACKUP_JSON" |
  jq -r '.tenants[] | [.tenant_id,.backup_id,.sha256] | @tsv' \
  > "/tmp/w-lot-b-backups-${CANDIDATE_SHA}.tsv"
```

Contents: PL-T2 only; all flags remain false and `AUTO_MIGRATE=false`.

```bash
git add -- \
  apps/pos/src/lib/db/migrations.ts \
  apps/pos/src/lib/db/repositories/branchLotEligibilityRepository.ts \
  apps/pos/src/lib/db/repositories/receiptLotEvidenceRepository.ts \
  apps/pos/src/lib/db/repositories/lotEvidenceOutboxRepository.ts \
  apps/api/database/migrations/tenant/2026_09_06_200000_create_pos_lot_evidence_tables.php \
  apps/api/database/migrations/tenant/2026_09_06_200100_create_pos_lot_projection_obligations.php \
  apps/api/app/Modules/Fiscal/Domain/Models \
  apps/api/app/Modules/POS/Domain \
  apps/api/tests/Feature/Fiscal/PosLotEvidenceSchemaPostgresTest.php \
  apps/pos/src/lib/db/__tests__/wLotBMigrations.test.ts

git commit -m "W-LOT-B P2: add evidence and obligation schemas"
```

After push/API deploy, run migrations explicitly:

```bash
MIGRATION_LOG="/tmp/w-lot-b-migrate-${CANDIDATE_SHA}.log"

ssh "$STAGING_SSH" \
  'cd /var/www/html &&
   DB_HOST=${DB_DIRECT_HOST:-postgres} php artisan migrate --force &&
   DB_HOST=${DB_DIRECT_HOST:-postgres} php artisan tenants:migrate-rolling --force' \
  | tee "$MIGRATION_LOG"

grep -F "0 failed" "$MIGRATION_LOG"
DECLARED_TENANTS="$(
  sed -n 's/^Rolling tenant migrations across \([0-9][0-9]*\) tenant(s).$/\1/p' \
    "$MIGRATION_LOG"
)"
MIGRATED_TENANTS="$(grep -c '^→ ' "$MIGRATION_LOG")"
test "$DECLARED_TENANTS" -eq "$ACTIVE_TENANTS"
test "$MIGRATED_TENANTS" -eq "$ACTIVE_TENANTS"
! grep -E 'FAILED:|Done with errors' "$MIGRATION_LOG"

ssh "$STAGING_SSH" \
  'cd /var/www/html &&
   DB_HOST=${DB_DIRECT_HOST:-postgres} php artisan tenants:migrate-rolling --force' \
  > "/tmp/w-lot-b-migrate-rerun-${CANDIDATE_SHA}.log"

grep -F "0 failed" "/tmp/w-lot-b-migrate-rerun-${CANDIDATE_SHA}.log"
```

Then recreate all Laravel services and run common web deployment/smoke.

Rollback point P2: P1 deployment/image. Leave additive server tables and device migrations installed; backup IDs remain recorded.

### Push 3 — T3–T5 display

Flags:

```text
WLOT_B_DISPLAY_ENABLED=true
WLOT_B_CAPTURE_ENABLED=false
WLOT_B_EVIDENCE_INGRESS_ENABLED=false
WLOT_B_CONSUMPTION_ENABLED=false
AUTO_MIGRATE=false
```

Contents: PL-T3, PL-T4, PL-T5.

```bash
git add -- \
  apps/api/app/Modules/POS/routes.php \
  apps/api/app/Modules/BatchExpiry/Presentation/Requests/PosLotEligibilityRequest.php \
  apps/api/app/Modules/BatchExpiry/Presentation/Controllers/PosLotEligibilityController.php \
  apps/api/app/Modules/BatchExpiry/Application/Services/PosLotEligibilitySnapshotService.php \
  apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php \
  apps/api/app/Modules/BatchExpiry/Providers/BatchExpiryServiceProvider.php \
  apps/pos/src/api/lotEligibilityApi.ts \
  apps/pos/src/lib/sync/lotEligibilitySync.ts \
  apps/pos/src/lib/sync/syncService.ts \
  apps/pos/src/stores/terminalStore.ts \
  apps/pos/src/stores/syncStore.ts \
  apps/pos/src/lib/lot/selectFefoSuggestion.ts \
  apps/pos/src/lib/stock \
  apps/pos/src/types/cart.ts \
  apps/pos/src/components/organisms/ProductGrid \
  apps/pos/src/components/molecules/ProductCard/ProductCard.tsx \
  apps/pos/src/components/molecules/CartLineItem/CartLineItem.tsx \
  apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx \
  apps/pos/src/components/pos/ProductDetailDrawer.tsx \
  apps/pos/src/locales \
  apps/api/tests/Feature/BatchExpiry/PosLotEligibilityEndpointPostgresTest.php \
  apps/pos/src/stores/__tests__/terminalStore.lotEligibilityOpen.test.ts \
  apps/pos/src/lib/lot/__tests__/selectFefoSuggestion.test.ts

git commit -m "W-LOT-B P3: ship POS lot display"
```

Post-deploy:

```bash
ssh "$STAGING_SSH" \
  "cd /var/www/html &&
   php artisan inventory:w-lot-b-preflight \
     --all-tenants \
     --expected-build-sha=${CANDIDATE_SHA} \
     --expect-stage=display \
     --format=json"
```

Verify:

- module-off company has no lot DOM;
- entitled company refuses session opening until snapshot acknowledgement;
- second location receives only its lots;
- card/list/table/cart/drawer show identical FEFO suggestion;
- reservations, pending local receipts and active cart quantities are netted.

Perform common web deployment/fingerprint/Playwright and recreate all Laravel services.

Rollback point P3: P2 deployment ID. Emergency flags set display false, force-recreate services, then redeploy P2. Cache remains.

### Push 4 — T6–T8 capture and ingress

Flags:

```text
WLOT_B_DISPLAY_ENABLED=true
WLOT_B_CAPTURE_ENABLED=true
WLOT_B_EVIDENCE_INGRESS_ENABLED=true
WLOT_B_CONSUMPTION_ENABLED=false
AUTO_MIGRATE=false
```

Contents: PL-T6, PL-T7, PL-T8.

```bash
git add -- \
  apps/pos/src/types/cart.ts \
  apps/pos/src/stores/cartStore.ts \
  apps/pos/src/components/molecules/CartLineItem/CartLineItem.tsx \
  apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx \
  apps/pos/src/components/organisms/LotCaptureEditor \
  apps/pos/src/lib/lot/validateCapturedLots.ts \
  apps/pos/src/lib/offline/receiptService.ts \
  apps/pos/src/lib/sync/lotEvidenceSync.ts \
  apps/pos/src/lib/sync/syncService.ts \
  apps/pos/src/stores/syncStore.ts \
  apps/api/app/Modules/Fiscal/routes.php \
  apps/api/app/Modules/Fiscal/Presentation/Requests/IngestLotEvidenceRequest.php \
  apps/api/app/Modules/Fiscal/Presentation/Controllers/LotEvidenceIngestionController.php \
  apps/api/app/Modules/Fiscal/Application/Services/LotEvidenceIngressService.php \
  apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php \
  apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php \
  apps/pos/src/lib/offline/__tests__/receiptLotEvidenceAtomicity.test.ts \
  apps/pos/src/lib/offline/__tests__/receiptLotEvidenceSealedPayload.test.ts \
  apps/pos/src/lib/sync/__tests__/lotEvidenceSync.test.ts \
  apps/api/tests/Feature/Fiscal/LotEvidenceArrivalOrderPostgresTest.php

git commit -m "W-LOT-B P4: capture and deliver lot evidence"
```

Staging sequence:

1. Deploy/recreate server first.
2. Install/build the candidate POS.
3. Submit evidence before its event; capture submission/outbox/event IDs from device logs.
4. Verify server 202 and local acknowledgement.
5. Push event; verify one validated submission.
6. Submit event first, then evidence; verify the same terminal state.
7. Exact resend produces same IDs.
8. Conflicting resend returns 409 and preserves original fingerprint.
9. Fault injection proves receipt/event/evidence/outbox atomicity.
10. Compare canonical bytes/hash/print with capture disabled.

Run preflight with `--expect-stage=capture`; consumption must report zero active lot obligations.

Rollback point P4: P3 deployment ID. Set capture false first, allow evidence drain to zero, then set ingress false and force-recreate services. Retain evidence.

### Push 5 — T9–T10 consumption and completion

Flags:

```text
WLOT_B_DISPLAY_ENABLED=true
WLOT_B_CAPTURE_ENABLED=true
WLOT_B_EVIDENCE_INGRESS_ENABLED=true
WLOT_B_CONSUMPTION_ENABLED=true
AUTO_MIGRATE=false
```

Contents: PL-T9, PL-T10.

```bash
git add -- \
  apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php \
  apps/api/app/Modules/POS/Application/Services/PosReceiptLotObligationService.php \
  apps/api/app/Modules/POS/Application/Jobs/ApplyPosReceiptLotObligationJob.php \
  apps/api/app/Modules/POS/Application/Services/PosReceiptLotRecoveryService.php \
  apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php \
  apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php \
  apps/api/app/Modules/Fiscal/Infrastructure/Commands/RetryPosLotObligationsCommand.php \
  apps/api/app/Console/Commands/WLotBPreflightCommand.php \
  apps/api/routes/console.php \
  apps/api/app/Modules/POS/Providers/POSServiceProvider.php \
  apps/api/tests/Architecture/WLotBWriterOwnershipTest.php \
  apps/api/tests/Architecture/InventoryWriterLockManifestTest.php \
  apps/api/tests/Feature/Fiscal/PosCoreReceiptLotEvidenceProjectionPostgresTest.php \
  apps/api/tests/Feature/Fiscal/PosLotObligationRecoveryPostgresTest.php \
  apps/api/tests/Feature/Fiscal/PosLotCapturedRefundProvenancePostgresTest.php \
  apps/api/tests/Feature/Fiscal/WLotBPreflightPostgresTest.php \
  apps/pos/src/__tests__/wLotBDeviceJourney.test.tsx \
  .github/workflows/ci.yml \
  docs/qa/W-LOT-B-STAGING-EVIDENCE.md

git commit -m "W-LOT-B P5: consume captured lots with recovery"
```

Post-deploy:

```bash
PREFLIGHT_JSON="$(
  ssh "$STAGING_SSH" \
    "cd /var/www/html &&
     php artisan inventory:w-lot-b-preflight \
       --all-tenants \
       --expected-build-sha=${CANDIDATE_SHA} \
       --expect-stage=consumption \
       --fail-on-blocked \
       --fail-on-pending-older-than=15 \
       --format=json"
)"

printf '%s\n' "$PREFLIGHT_JSON" |
  jq -e '
    .outcome == "applied" and
    .signed_invariant_violations == 0 and
    .orphan_effects == 0 and
    .orphan_batch_movements == 0 and
    .blocked_obligations == 0 and
    .pos_core.always_active == true
  '
```

Then exercise and capture IDs for:

- captured sale spanning two lots;
- sale without evidence using labelled estimate;
- evidence-first;
- event-first;
- late exact evidence;
- late different evidence with compensating legs;
- worker death between effect and status;
- concurrent live projection/recovery;
- partial captured refund;
- module-off sale;
- replay of each operation.

For every journey verify:

```text
one fiscal event
one receipt
one aggregate stock movement per physical line
one obligation per physical line
N batch effects as required
sum effects = aggregate quantity_after - quantity_before
no duplicate inventory batch movement
no duplicate GL
correct provenance
```

Run common web deploy, asset/fingerprint check, Playwright and all Laravel environment verification.

Rollback point P5: P4 deployment ID. Emergency sequence:

```bash
ssh "$STAGING_SSH" \
  'cd /var/www/html &&
   WLOT_B_CONSUMPTION_ENABLED=false \
   docker compose -f docker-compose.staging.yml up -d --force-recreate --no-deps api worker scheduler websocket'
```

Confirm POS-core receipt projection remains active and aggregate sale/refund posting continues. Drain/report child obligations, then redeploy P4. Never reverse aggregate movements or delete evidence/effects.

## Dispatch order

1. Verify immutable base SHA and W-LOT-A prerequisite interfaces/tests.
2. PL-T1 — canonical contracts, types, rollout/migration ownership.
3. PL-T2 — additive SQLite/server schemas.
4. PL-T3 — server eligibility snapshot.
5. PL-T4 — acknowledged pre-open refresh.
6. PL-T5 — FEFO selector and display.
7. Push 3/display staging gate.
8. PL-T6 — editable receipt-atomic capture.
9. PL-T7 — durable device delivery.
10. PL-T8 — server ingress and either-order reconciliation.
11. Push 4/capture staging gate.
12. PL-T9 — obligations, consumption, recovery and refunds.
13. PL-T10 — architecture, CI, preflight and end-to-end evidence.
14. Push 5/consumption staging gate.
15. Final reviewer approvals and verification checklist.

No T6–T8 dispatch before display is green. No T9 dispatch before both evidence arrival orders are green. No consumption activation before the W-LOT-A entitlement/hold/lock/provenance prerequisites and all live-PG tests are green.

## Final verification checklist

- [ ] Implementation base and each push SHA recorded; no stale citation assumption.
- [ ] Q10–Q13 remain copied verbatim as OPEN.
- [ ] No schema/state/flag/push/task encodes a Q10–Q13 branch.
- [ ] W-LOT-A prerequisite interfaces exist and their PG concurrency/lock tests pass.
- [ ] SQLite migration max was 67 at planning time; W-LOT-B alone owns v68/v69.
- [ ] All quantities remain scale-4 decimal strings on device and `DECIMAL(15,4)` on server.
- [ ] Every JS timestamp written to SQLite passes through `toSqliteUtc()`.
- [ ] Canonical line keys pass shared PHP/TS vectors, including `999999` and overflow rejection.
- [ ] Generated DTOs are regenerated; no local domain shadow type exists.
- [ ] Server and device FEFO orders are identical, including `batch_id` tie-break.
- [ ] Server availability nets reservations.
- [ ] Device suggestion additionally nets uncovered pending sales and active cart allocations through `stockGate`.
- [ ] Both shift-open branches refuse until the returned entitlement revision is durably acknowledged.
- [ ] Module off opens normally and renders/writes nothing lot-related.
- [ ] `NearExpirySlot` renders in ProductCard, ProductListRow and ProductTable.
- [ ] Active `TransactionCart`/`CartLineItem` renders and edits the allocation.
- [ ] `ProductDetailDrawer.stock_lots` exists only under BatchExpiry/display gating.
- [ ] Editable captured allocations are eligible and sum exactly to line quantity.
- [ ] Receipt, fiscal event, evidence, evidence lines and evidence outbox commit/rollback together.
- [ ] Evidence outbox is independently durable and linked to event ID/hash.
- [ ] Captured provenance is `operator_captured`; fallback is `system_fefo_estimate`.
- [ ] Sealed payload, canonical bytes, hash chain and print output are byte-identical.
- [ ] Evidence-first and event-first converge to one validated submission.
- [ ] Exact redelivery is idempotent; differing content is a 409 without mutation.
- [ ] POS-core projector remains registered and always active.
- [ ] One aggregate movement supports N batch effects.
- [ ] Signed reconciliation uses `quantity_after - quantity_before`.
- [ ] Child shortfall/dependency is `blocked` and visible without rejecting the receipt.
- [ ] Lost enqueue, worker death and stale running/blocked recovery are green.
- [ ] Late exact evidence adds no stock movement.
- [ ] Late differing evidence adds only compensating lot effects under the original aggregate movement.
- [ ] Refunds restore captured provenance first and never mint phantom DEFAULT lots.
- [ ] Lot-only reconciliation writes no WAC or GL.
- [ ] `InventoryGlPostingBuffer` remains sole inventory→GL boundary and flushes after inventory locks.
- [ ] Live/recovery concurrency passes both directions without deadlock or duplicate effects.
- [ ] Second company, second location and rerun/idempotency assertions pass.
- [ ] Every new company-scoped unique passes the live PG Convention-09 ratchet.
- [ ] All server tests run in the PHPUnit PostgreSQL lane; all device tests run in Vitest.
- [ ] PHPUnit SQLite is not cited as evidence for PG locks, constraints or decimal behavior.
- [ ] `php artisan typescript:transform` leaves no generated diff.
- [ ] Pint, PHPStan, ESLint, typecheck, Vitest, PHPUnit, builds and `./scripts/preflight.sh` pass.
- [ ] `AUTO_MIGRATE=false` owns rollout migration timing; boot cannot migrate ahead of capture.
- [ ] `tenants:migrate-rolling --force` output names every active tenant and reports zero failures.
- [ ] All rollout variables are identical in API, worker, scheduler and websocket.
- [ ] Changed rollout values recreate containers; no `docker compose restart`.
- [ ] Every backup/deployment/submission/event/obligation ID is captured from command output before reuse.
- [ ] Every promotion triggers explicit Dokploy web deployment.
- [ ] Captured Dokploy deployment ID reaches success for the candidate SHA.
- [ ] Served build SHA, feature fingerprint and entry-asset SHA-256 match.
- [ ] Playwright staging smoke passes after every web promotion.
- [ ] Push-specific rollback point and artifact hashes are recorded.
- [ ] Final preflight reports zero signed violations, orphans, blocked obligations and stale pending work.
- [ ] Every named reviewer returns explicit APPROVED with zero BLOCKER/MAJOR findings.