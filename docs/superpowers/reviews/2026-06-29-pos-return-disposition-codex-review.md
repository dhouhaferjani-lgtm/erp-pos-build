# Spec Summary

The spec proposes adding per-return-line physical disposition to POS returns, with Phase 0 supporting `RESTOCK`, `SCRAP`, and `NOT_RECEIVED`, later expanding to quarantine/destruction/RTV and then migrating refunds/voids onto device-authored fiscal events. It tries to keep Phase 0 additive by defaulting missing disposition to current `RESTOCK` behavior, while building a `restock_policy` resolver before enforcement. The design also claims two-movement scrap is traceable and WAC-correct, and that fiscal invariants remain intact until the Phase 3 migration. The current code does not yet contain the disposition enum, restock policy schema, fiscal refund payload handlers, or disposition-bound hash inputs.

# Attacks by Axis

## a. Disposition Model Holes

**Quoted spec claim:** "Each **return line** records two raw facts plus the resulting disposition:" followed by "`physical_receipt: bool`", "`resalable: bool | null`", and "`ReturnLineDisposition` (enum)" in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:43-47`.

**Observed code reality:** UNVERIFIABLE -- no matching code found for `ReturnLineDisposition`, `physical_receipt`, or `resalable` in the searched `apps/api`, `apps/pos/src`, or `packages` tree. The live service still documents return input as `array<int, array{line_id: string, quantity: string}>` in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:93`, and `validateReturnQuantities()` only reads `$returnLine['line_id']` and `$returnLine['quantity']` at lines `1026-1029`. The stock loop then restores every product line unconditionally through `restoreStock()` at lines `394-423` and restores batch allocations at lines `425-430`.

**Severity:** BLOCKER.

**Specific fix required:** Add persisted per-line disposition fields and validation before changing stock behavior. The implementation must define how `physical_receipt`, `resalable`, and `disposition` are stored on return lines or an immutable side table, and it must reject invalid combinations such as `physical_receipt=false` with `resalable=true` or `NOT_RECEIVED` with batch restitution.

**Quoted spec claim:** "Storing the two raw facts alongside the derived disposition keeps it **auditable as a function of facts + policy** and lets back-office perform legal state transitions later (`QUARANTINE → RESTOCK | SCRAP | RETURN_TO_VENDOR`)." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:58`.

**Observed code reality:** UNVERIFIABLE -- no state-transition code or persistence model exists for quarantine or back-office reclassification. Search found no `QUARANTINE`, `DESTROY`, `RETURN_TO_VENDOR`, `SCRAP`, or `NOT_RECEIVED` implementation relevant to return disposition; only unrelated "disposition" comments exist. The future transition `QUARANTINE -> RESTOCK | SCRAP | RETURN_TO_VENDOR` is not constrained by any table, event, or service in the current code.

**Severity:** IMPORTANT.

**Specific fix required:** Specify and implement a transition table or event-sourced audit trail with allowed previous/next states, actor, timestamp, reason, inventory location, and idempotency key. Do not let Phase 2 be a free-form update of a return-line enum.

**Quoted spec claim:** "Offline lookup -- on local sale authoring, also write a local lookup-index row so the device's own receipts are findable offline (fixes the root cause)." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:109`.

**Observed code reality:** The device fiscal registry lists `REFUND_RECEIPT`, `SALE_VOID`, and `PARTIAL_REFUND` in `FISCAL_EVENT_TYPES` at `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts:65-68`, but `IMPLEMENTED_EVENT_TYPES` ends at the existing implemented set and does not include them at lines `84-107`. `eventVersionFor()` throws `FiscalEventTypeNotImplementedError` for unimplemented types at lines `162-165`. `FiscalEventEngine.append()` resolves the event version before mutation at `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/pos/src/lib/fiscal/FiscalEventEngine.ts:561-565`, so an offline return event cannot be authored today.

**Severity:** IMPORTANT.

**Specific fix required:** Treat offline returns as Phase 3 only and add explicit Phase 0/1 guards so UI/server behavior never implies offline return support. The Phase 3 plan needs local receipt-index write/read tests for same-device sales before sync and for receipts authored on another offline device.

## b. Two-Movement Scrap Inventory Approach

**Quoted spec claim:** "**`SCRAP`** → **two movements** (owner-approved, traceability): 1. `MovementType::Receipt` / `MovementReason::POSReturn`, `+qty` ... 2. `MovementType::Adjustment` / `MovementReason::WriteOff`, `−qty` ... Net sellable change = 0 relative to before the return" in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:63-65`.

**Observed code reality:** `MovementType::Receipt` and `MovementType::Adjustment` exist in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/Inventory/Domain/Enums/MovementType.php:9-13`, and `MovementReason::WriteOff` plus `MovementReason::POSReturn` exist in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:28-31`. However, the live POS return path only creates a single receipt movement with `movement_type => MovementType::Receipt`, `reason => MovementReason::POSReturn`, and positive `quantity` in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1168-1177`. There is no negative `WriteOff` leg and no disposition branch in the service.

**Severity:** BLOCKER.

**Specific fix required:** Implement scrap as one atomic service method that writes both stock movements, updates `stock_levels`, restores batch stock only for the receive leg, and records an idempotency anchor on both legs. Tests must assert exactly two movements for `SCRAP` and no movement for `NOT_RECEIVED`.

**Quoted spec claim:** "**Two-movement scrap is the standard** ... receive back at original cost (`+qty`) then write-off to scrap/shrinkage (`−qty`). Traceable, WAC-correct" in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:34`.

**Observed code reality:** The real WAC service has a dedicated return method: `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:467-475` defines `recordReturn(Product $product, Location $location, float $quantity, float $originalCost, ...)`. It blends the returned quantity into WAC at original cost at lines `522-545`, writes `unit_cost`, `total_cost`, `avg_cost_before`, and `avg_cost_after` at lines `550-563`, and updates `products.cost_price` at lines `573-576`. The POS return service bypasses this entirely: its `StockMovement::create()` does not set `unit_cost`, `total_cost`, `avg_cost_before`, or `avg_cost_after` at `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1168-1186`, even though those cost ledger columns exist and are cast at `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/Inventory/Domain/StockMovement.php:71-74` and `98-101`.

**Severity:** BLOCKER.

**Specific fix required:** The spec must say whether POS returns integrate with `WeightedAverageCostService::recordReturn()` or intentionally remain quantity-only. If it claims WAC correctness, Phase 0 must capture original unit cost from `pos_receipt_lines.unit_cost`, call the WAC path or an equivalent advisory-locked cost path, and define the second scrap leg's cost effect. "Receive at original cost then zeroing" is not WAC-correct in this code unless the write-off leg also removes value from the cost ledger without leaving `products.cost_price` inflated.

**Quoted spec claim:** "All movements carry `lot/batch` via the existing batch-allocation restore." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:69`.

**Observed code reality:** Batch restore currently updates `inventory_batch_stock` directly and does not create batch-level movement rows. `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1249-1254` locks the `inventory_batch_stock` row, then lines `1271-1276` update only `quantity` and `updated_at`. The aggregate `StockMovement::create()` at lines `1168-1186` has no batch id field.

**Severity:** IMPORTANT.

**Specific fix required:** Define the batch audit model for the scrap leg. If the movement must "carry lot/batch", create or reuse a batch movement/link table and assert the receive and write-off legs reference the same source batch allocations.

## c. `restock_policy` Hierarchy Resolution

**Quoted spec claim:** "- **Enum** `RestockPolicy`: `never | if_sealed | default_allow`." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:81`.

**Observed code reality:** UNVERIFIABLE -- no `RestockPolicy` enum was found under `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api` or `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/packages`. The only `*Restock*` file found was unrelated: `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php`.

**Severity:** IMPORTANT.

**Specific fix required:** Add the enum before any resolver work and decide whether values are snake-case strings, backed PHP enums, generated shared TS types, or all three.

**Quoted spec claim:** "- **Columns** (nullable, `null = inherit`): `products.restock_policy`, `categories.restock_policy`." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:82`.

**Observed code reality:** UNVERIFIABLE -- no `restock_policy` migration or column reference exists in the searched API/packages tree. Existing product creation has no such column in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/database/migrations/tenant/2025_11_30_052910_create_products_table.php:16-31`. Existing category creation has `parent_id` and tree fields but no `restock_policy` in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/database/migrations/tenant/2025_12_26_194624_create_categories_table.php:13-34`.

**Severity:** IMPORTANT.

**Specific fix required:** Add reversible tenant migrations for both columns with constraints/defaults, model fillable/casts, API DTOs, and generated shared types. Include a data backfill plan for existing products/categories.

**Quoted spec claim:** "tenant default in `Company.reservation_settings.default_restock_policy` (seeded, vertical-aware: parapharmacy → `if_sealed`, generic retail → `default_allow`)." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:83`.

**Observed code reality:** UNVERIFIABLE -- `ReservationSettings` has many refund-policy fields but no `defaultRestockPolicy` constructor property and no `default_restock_policy` key. The constructor fields are visible in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/Company/Domain/ValueObjects/ReservationSettings.php:20-72`; `fromArray()` reads known keys at lines `99-159`; `toArray()` writes known keys at lines `169-217`. The migration that added `reservation_settings` seeds `(new ReservationSettings)->toArray()` for existing companies at `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/database/migrations/tenant/2025_12_24_133802_add_reservation_settings_to_companies.php:19-24`, so today it cannot seed a vertical-aware restock default.

**Severity:** BLOCKER for Phase 1 enforcement; IMPORTANT for Phase 0 resolver-only work.

**Specific fix required:** Add `default_restock_policy` to `ReservationSettings` with read/write tests, migration backfill, and vertical-specific seeding. Define conflict resolution explicitly: product wins, nearest category ancestor wins over farther ancestor, tenant default last, and unresolved/null must fail closed to `default_allow` or a configured tenant default, not silently restock regulated goods.

## d. NF525 Compliance Claims

**Quoted spec claim:** "The reserved fiscal-event types `REFUND_RECEIPT`, `SALE_VOID`, `PARTIAL_REFUND` exist in the enum but are **not implemented** on either side." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:20`.

**Observed code reality:** Correct. `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php:29-32` defines `SALE_VOID`, `REFUND_RECEIPT`, and `PARTIAL_REFUND`, but `isImplemented()` only includes the implemented list at lines `45-71` and omits those three. Server coverage also marks them `RESERVED_UNREACHABLE` in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/Fiscal/Application/Services/FiscalEventCoveragePolicy.php:48-51`. Device registry likewise lists them but omits them from `IMPLEMENTED_EVENT_TYPES` in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts:65-68` and `84-107`.

**Severity:** MINOR as a factual claim; IMPORTANT as a migration dependency.

**Specific fix required:** Keep the spec explicit that no Phase 0/1 code can rely on these event types. Add tests that attempting to author them fails until Phase 3.

**Quoted spec claim:** "Refunds, voids (annulations), and closures stay in the signed chain, append-only, never hard-deleted." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:135`.

**Observed code reality:** Legacy returns are chained by `ReceiptFinalizationService::finalize()`, which locks the terminal at `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:83-86`, sets `previous_hash` and `chain_sequence` at lines `90-95`, computes the hash at lines `97-103`, then saves the receipt and advances the terminal at lines `105-111`. However, the legacy hash input only includes `receipt_number`, `posted_at`, `total`, `currency`, `vat_breakdown_hash`, and `payment_methods_hash` in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:84-93`. It does not bind original receipt id, return reason, returned line ids/quantities, disposition, physical facts, or stock outcome.

**Severity:** BLOCKER if Phase 0 claims fiscal integrity for disposition; IMPORTANT if Phase 0 is explicitly inventory-only.

**Specific fix required:** Bind return linkage and per-line disposition into the signed artifact before relying on it for compliance. For legacy v3, extend `V3ReceiptHashComputer` input or store disposition in an already-bound canonical payload; for fiscal events, define the canonical refund payload keys and validator.

**Quoted spec claim:** "`REFUND_RECEIPT` references the original, carries refunded line subset (full or partial — `PARTIAL_REFUND` stays reserved), refund payments, reason, **and per-line disposition**." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:107`.

**Observed code reality:** UNVERIFIABLE -- no `REFUND_RECEIPT` DTO, payload builder, validator key set, or projector exists. The server payload registry maps implemented DTOs but has no `REFUND_RECEIPT`, `SALE_VOID`, or `PARTIAL_REFUND` entries in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php:53-81`; missing types throw `FiscalEventTypeNotImplemented` at lines `88-93` and `114-119`. The existing device `SaleReceiptPayloadInput` can encode `invoice_type_code: 'REFUND' | 'VOID'` and `original_receipt_reference` at `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/pos/src/lib/fiscal/FiscalEventEngine.ts:391-412`, but that interface has no disposition fields.

**Severity:** BLOCKER for Phase 3.

**Specific fix required:** Before Phase 3, define whether refunds are separate `REFUND_RECEIPT` events or `SALE_RECEIPT` payloads with `invoice_type_code='REFUND'`. Do not keep both models ambiguous. Add canonical bytes golden tests proving per-line disposition changes the hash.

**Quoted spec claim:** "The disposition model adds inventory semantics only; it does not weaken any fiscal invariant." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:135`.

**Observed code reality:** Current v3 hash input built from a `Receipt` includes receipt number, posted time, previous hash, total, currency, VAT breakdown, payments, voucher ledger entries, exchange group id, and an audit block in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/POS/Application/Services/Fiscal/V3/V3ReceiptHashComputer.php:156-167`. The audit block only includes `authorized_by_user_id`, `override_reason`, `out_of_window`, `policy_trigger`, and `refund_request_id` at lines `221-239`. No disposition, physical receipt, resalable flag, batch, or stock movement ids are bound.

**Severity:** IMPORTANT.

**Specific fix required:** Either downgrade the claim to "inventory-only, not fiscally signed until Phase 3" or bind disposition fields in the Phase 0/legacy hash path. Without that, a database update can change disposition/stock semantics without changing the fiscal hash.

## e. Phasing Safety

**Quoted spec claim:** "**Phase 0 — disposition core (FIRST SLICE, this effort):** ... `RestockPolicy` enum + nullable `products.restock_policy` / `categories.restock_policy` + `Company.reservation_settings.default_restock_policy` (seeded) + `RestockPolicyResolver` (hierarchy + provenance), **built and tested but not yet enforcing** (additive)." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:120-124`.

**Observed code reality:** The "additive" claim is only true if callers remain trusted. Today `ReceiptReturnService::processReturn()` is the server-side return path and performs money side effects at lines `357-391`, then stock at lines `393-431`, then final sealing at lines `433-436`. Once Phase 0 accepts caller-supplied disposition without policy enforcement, a caller can choose `RESTOCK` for medicines or opened goods even though the spec says `never` should reject restock in Phase 1. The policy schema and resolver are also not present today, as shown above.

**Severity:** BLOCKER for regulated vertical rollout; IMPORTANT for generic retail.

**Specific fix required:** Do not ship Phase 0 in parapharmacy/pharmacy tenants without the backend `never => no RESTOCK` guard. If the team insists on Phase 0 additive behavior, gate it behind a feature flag restricted to existing generic behavior and default to `RESTOCK` only when no disposition fields are supplied by legacy callers.

**Quoted spec claim:** "**Phase 1 — refund-modal disposition selector** ... wires `RestockPolicyResolver` into the cashier's pre-filled default and the `never ⇒ no RESTOCK` backend guard." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:125`.

**Observed code reality:** This is the first phase that prevents regulated restock abuse, but it depends on a resolver and columns that do not exist. It also still leaves refunds on the legacy server-authored chain until Phase 3; `ReceiptFinalizationService` explicitly documents `ReceiptReturnService` as a knowingly retained legacy caller in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:45-50`.

**Severity:** IMPORTANT.

**Specific fix required:** Move the backend guard into Phase 0 for any tenant that can sell regulated goods, or make Phase 0 impossible to enable for those tenants. Add a migration/test proving existing receipts without disposition continue to return as `RESTOCK`, but new requests for products resolved as `never` cannot restock.

**Quoted spec claim:** "**Phase 3 — fiscal-event migration** (`REFUND_RECEIPT` / `SALE_VOID` device authoring + offline lookup + projection with disposition) — the umbrella." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:127`.

**Observed code reality:** `PosCoreReceiptProjection` handles only `FiscalEventType::SALE_RECEIPT` in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:138-141`. It writes the receipt, payments, vouchers, loyalty, and then always calls `decrementStockForLines()` at line `324`. That is appropriate for sales but dangerous if refund/void semantics are pushed through existing `SALE_RECEIPT` `invoice_type_code='REFUND'` before a sibling reversal projector exists.

**Severity:** BLOCKER for Phase 3 cutover.

**Specific fix required:** Add a dedicated reversal projector before any device-authored refund events are accepted. It must idempotently restore/scrap/no-op stock according to disposition and must not call sale stock decrement for refund/void events.

## f. Fiscal-Event Migration Plan

**Quoted spec claim:** "**Server projection** for the reversal events (sibling of `PosCoreReceiptProjection`) — writes the reversal `pos_receipts` row (append-only, never hard-delete), money via the existing refund machinery, and **disposition-gated stock** (the §2.2 model). `SALE_VOID` → full restock of all lines (goods never left), money nets zero." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:110`.

**Observed code reality:** UNVERIFIABLE -- no sibling reversal projection exists. `PosCoreReceiptProjection::handlesEventType()` only returns true for `SALE_RECEIPT` at `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:138-141`. The projection registry can only activate projectors that exist; the fiscal coverage policy marks `SALE_VOID`, `REFUND_RECEIPT`, and `PARTIAL_REFUND` as `RESERVED_UNREACHABLE` at `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/Fiscal/Application/Services/FiscalEventCoveragePolicy.php:48-51`.

**Severity:** BLOCKER.

**Specific fix required:** Add the reversal projector, DTOs, validators, coverage policy changes, registry entries, and projection idempotency constraints in the same migration phase. The projector must be replay-safe and must not duplicate stock/money side effects on retries.

**Quoted spec claim:** "Retire the legacy server-side return/void seal path once the projection path is authoritative." in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:112`.

**Observed code reality:** The spec does not state how existing legacy return receipts are migrated or coexisted. Current chain verification has two arms: fiscal-events rows use `canonical_bytes`, while legacy rows where `fiscal_event_id IS NULL` are verified through legacy receipt hashing, documented in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:31-37` and implemented by `verifyTerminalChain()` at lines `180-187`. Existing return receipts created by `ReceiptReturnService` will remain legacy rows unless backfilled or deliberately left as legacy.

**Severity:** IMPORTANT.

**Specific fix required:** Add an explicit coexistence/migration plan for existing receipts: no backfill and permanent dual verification, or append-only migration events that reference legacy receipt ids without rewriting old chain rows. Include rollback behavior for partially deployed clients.

**Quoted spec claim:** "`REFUND_RECEIPT` references the original, carries refunded line subset (full or partial — `PARTIAL_REFUND` stays reserved)" in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md:107`.

**Observed code reality:** The current canonical `OriginalReceiptReferenceInput` has `fiscal_event_id`, `original_business_date`, `original_receipt_uuid`, and `refund_reason` in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/pos/src/lib/fiscal/FiscalEventEngine.ts:365-374`, but no line subset, no original line ids, and no disposition. The PHP `SaleReceiptPayload` DTO accepts `original_receipt_reference` but not a refund-specific disposition payload in `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund/apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php:89-97` and `153-157` from the search output.

**Severity:** BLOCKER.

**Specific fix required:** Define the canonical schema for partial returns: original line id, returned quantity, tax allocations, refund amount allocation, disposition, batch references, and idempotency key. Add migration tests for existing full/partial legacy returns so totals and "already returned" logic survive cutover.

# Required Before Merge

1. Add and persist `ReturnLineDisposition`, `physical_receipt`, and `resalable` with validation for all legal/illegal combinations.
2. Integrate POS returns with WAC-correct cost handling, either by using `WeightedAverageCostService::recordReturn()` or by implementing an equivalent advisory-locked path that writes cost ledger fields and updates `products.cost_price`.
3. Specify and test the scrap write-off leg's cost effect; a two-movement quantity plan without cost semantics is not WAC-correct in this codebase.
4. Add batch-level audit/linkage for both receive and scrap legs, not just direct `inventory_batch_stock` quantity updates.
5. Add `RestockPolicy` enum, `products.restock_policy`, `categories.restock_policy`, `ReservationSettings.default_restock_policy`, reversible migrations, backfills, casts, generated shared types, and resolver provenance tests.
6. Move the `never => no RESTOCK` backend guard into Phase 0 for regulated tenants, or feature-flag Phase 0 away from parapharmacy/pharmacy tenants.
7. Bind disposition fields into the signed artifact, or explicitly document Phase 0 disposition as not fiscally signed and prohibit compliance claims until Phase 3.
8. Decide one canonical migration model: separate `REFUND_RECEIPT`/`SALE_VOID` event types or `SALE_RECEIPT` with `invoice_type_code='REFUND'|'VOID'`; do not leave both in play.
9. Add refund/void DTOs, validators, registry entries, device payload builders, golden canonical-byte/hash tests, and projector coverage before enabling device-authored returns.
10. Add a dedicated reversal projector that is idempotent, replay-safe, and disposition-gates stock without calling sale stock decrement.
11. Write a coexistence/migration plan for existing legacy return receipts, including chain verification, rollback, and partial-return history.
12. Add Phase 0/1 tests for concurrent duplicate return requests proving idempotency covers both receipt creation and the new stock movement legs.

# Can Ship As-Is?

NO. The spec's current Phase 0/1 cuts would add caller-selected inventory semantics without the WAC integration, policy enforcement, signed disposition binding, and migration guarantees needed to protect inventory and fiscal integrity.
